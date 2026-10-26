<?php
declare(strict_types=1);

use CoreBlueprint\Core\Security\TwoFactor\ChallengeStore;
use CoreBlueprint\Core\Security\TwoFactor\CredentialStore;
use CoreBlueprint\Core\Security\TwoFactor\EnrollmentStore;
use CoreBlueprint\Core\Security\TwoFactor\LoginController;
use CoreBlueprint\Core\Security\TwoFactor\LoginFlow;
use CoreBlueprint\Core\Security\TwoFactor\Policy;
use CoreBlueprint\Core\Security\TwoFactor\RecoveryCodes;
use CoreBlueprint\Core\Security\TwoFactor\Totp;
use CoreBlueprint\Core\Settings;

final class CB_Base_Two_Factor_Security_Containment_Test extends WP_UnitTestCase {
	private array $original_policy = [];
	private mixed $original_bypass = false;
	private string $original_pagenow = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_policy = Settings::get()[ Policy::SETTINGS_KEY ] ?? Policy::default_config();
		$this->original_bypass = get_option( CB_CORE_BYPASS_OPT, false );
		$this->original_pagenow = (string) ( $GLOBALS['pagenow'] ?? '' );
		delete_option( CB_CORE_BYPASS_OPT );
		$GLOBALS['pagenow'] = 'wp-login.php';
		LoginFlow::reset_request_state();
	}

	public function tear_down(): void {
		LoginFlow::reset_request_state();
		Settings::set_key( Policy::SETTINGS_KEY, $this->original_policy, 'two-factor-h1-test-restore' );
		$GLOBALS['pagenow'] = $this->original_pagenow;
		if ( false === $this->original_bypass ) {
			delete_option( CB_CORE_BYPASS_OPT );
		} else {
			update_option( CB_CORE_BYPASS_OPT, $this->original_bypass, false );
		}
		parent::tear_down();
	}

	private function damage( int $user_id ): void {
		update_user_meta( $user_id, CredentialStore::META_ENROLLED_AT, time() );
		update_user_meta( $user_id, CredentialStore::META_SECRET, [
			'version' => 1,
			'algorithm' => 'sodium_secretbox',
			'nonce' => 'invalid',
			'ciphertext' => 'invalid',
		] );
	}

	public function test_h1_optional_and_enforce_fail_closed_on_damaged_canonical_enrollment(): void {
		foreach ( [ Policy::MODE_OPTIONAL, Policy::MODE_ENFORCE ] as $mode ) {
			Settings::set_key( Policy::SETTINGS_KEY, [ 'mode' => $mode, 'scope' => Policy::SCOPE_PRIVILEGED ], 'h1-test' );
			$user = get_userdata( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
			$this->damage( (int) $user->ID );
			self::assertSame( CredentialStore::STATUS_RECOVERY_REQUIRED, CredentialStore::enrollment_status( (int) $user->ID ) );
			self::assertTrue( CredentialStore::is_enrolled( (int) $user->ID ) );
			self::assertSame( LoginFlow::DECISION_VERIFY, LoginFlow::decision_for( $user ) );
			self::assertSame( $user, LoginFlow::filter_authenticate( $user ) );
			self::assertFalse( LoginFlow::filter_send_auth_cookies( true, 0, 0, (int) $user->ID ) );
			self::assertSame( 'verify', LoginFlow::finalize_password_stage( $user, false, admin_url() )['flow'] );
		}
	}

	public function test_h1_partial_enrollment_and_pending_setup_are_distinct(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		self::assertSame( CredentialStore::STATUS_NOT_ENROLLED, CredentialStore::enrollment_status( $user_id ) );
		$pending = EnrollmentStore::start( $user_id );
		self::assertNotSame( '', $pending );
		self::assertSame( CredentialStore::STATUS_NOT_ENROLLED, CredentialStore::enrollment_status( $user_id ) );
		update_user_meta( $user_id, CredentialStore::META_RECOVERY, [] );
		self::assertSame( CredentialStore::STATUS_RECOVERY_REQUIRED, CredentialStore::enrollment_status( $user_id ) );
		$this->expectException( RuntimeException::class );
		EnrollmentStore::start( $user_id );
	}

	public function test_h1_last_recovery_code_is_replaced_atomically_at_login_and_not_reusable(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->damage( $user_id );
		$code = 'ABCDE-12345-67890-ABCDE';
		RecoveryCodes::generate_for_user( $user_id );
		CredentialStore::store_recovery_hashes( $user_id, [ wp_hash_password( str_replace( '-', '', $code ) ) ] );
		$challenge = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );
		$result = LoginController::process( $challenge, $code );
		self::assertSame( 'success', $result['status'] ?? null );
		self::assertSame( 'recovery', $result['method'] ?? null );
		self::assertCount( RecoveryCodes::CODE_COUNT, $result['recovery_codes'] ?? [] );
		self::assertSame( RecoveryCodes::CODE_COUNT, RecoveryCodes::remaining( $user_id ) );
		self::assertFalse( RecoveryCodes::consume( $user_id, $code ) );
		self::assertTrue( RecoveryCodes::consume( $user_id, $result['recovery_codes'][0] ) );
		self::assertSame( CredentialStore::STATUS_RECOVERY_REQUIRED, CredentialStore::enrollment_status( $user_id ) );
	}

	public function test_h1_failed_recovery_hash_write_never_consumes_last_code(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$code = 'ABCDE-12345-67890-ABCDE';
		CredentialStore::store_recovery_hashes( $user_id, [ wp_hash_password( str_replace( '-', '', $code ) ) ] );
		$block = static function ( $check, $id, $key ) use ( $user_id ) {
			return (int) $id === $user_id && CredentialStore::META_RECOVERY === $key ? false : $check;
		};
		add_filter( 'update_user_metadata', $block, 10, 3 );
		try {
			self::assertNull( RecoveryCodes::consume_for_login( $user_id, $code ) );
			self::assertSame( 1, RecoveryCodes::remaining( $user_id ) );
		} finally {
			remove_filter( 'update_user_metadata', $block, 10 );
		}
		self::assertCount( RecoveryCodes::CODE_COUNT, RecoveryCodes::consume_for_login( $user_id, $code ) );
	}

	public function test_h1_enrollment_write_failure_reverts_only_its_own_records(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$material = RecoveryCodes::new_material();
		$block = static function ( $check, $id, $key ) use ( $user_id ) {
			return (int) $id === $user_id && CredentialStore::META_ENROLLED_AT === $key ? false : $check;
		};
		add_filter( 'add_user_metadata', $block, 10, 3 );
		$failed = false;
		try {
			CredentialStore::create_enrollment( $user_id, 'JBSWY3DPEHPK3PXP', 100, $material['hashes'] );
		} catch ( RuntimeException ) {
			$failed = true;
		} finally {
			remove_filter( 'add_user_metadata', $block, 10 );
		}
		self::assertTrue( $failed );
		self::assertSame( CredentialStore::STATUS_NOT_ENROLLED, CredentialStore::enrollment_status( $user_id ) );
		self::assertFalse( metadata_exists( 'user', $user_id, CredentialStore::META_SECRET ) );
	}

	public function test_h1_existing_credential_cannot_be_overwritten_or_erased_by_enrollment(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$pending = EnrollmentStore::start( $user_id );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		$material = RecoveryCodes::new_material();
		try {
			CredentialStore::create_enrollment( $user_id, $pending, 100, $material['hashes'] );
			self::fail( 'Existing canonical enrollment was overwritten.' );
		} catch ( RuntimeException ) {
			self::assertSame( 'JBSWY3DPEHPK3PXP', CredentialStore::totp_secret( $user_id ) );
		}
		self::assertNull( EnrollmentStore::confirm( $user_id, Totp::code( $pending, 1800000000 ), 1800000000 ) );
		self::assertSame( 'JBSWY3DPEHPK3PXP', CredentialStore::totp_secret( $user_id ) );
	}

	public function test_h1_concurrent_enrollment_lock_fails_closed(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$option = 'cb_core_2fa_enrollment_lock_' . $user_id;
		self::assertTrue( add_option( $option, 'another-request', '', false ) );
		try {
			$this->expectException( RuntimeException::class );
			EnrollmentStore::start( $user_id );
		} finally {
			delete_option( $option );
		}
	}
}
