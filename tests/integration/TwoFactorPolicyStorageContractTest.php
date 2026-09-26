<?php
declare(strict_types=1);

use CB\Core\Security\TwoFactor\CredentialCipher;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\PolicyMutation;
use CB\Core\Security\TwoFactor\RecoveryCodes;
use CB\Core\Settings;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\RolePolicySchema;
use CB\Core\Permissions\Roles;

final class CB_Base_Two_Factor_Policy_Storage_Contract_Test extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private array $original_policy = [];

	private mixed $original_bypass = false;

	public function set_up(): void {
		parent::set_up();
		$this->original_policy = is_array( Settings::get()[ Policy::SETTINGS_KEY ] ?? null )
			? Settings::get()[ Policy::SETTINGS_KEY ]
			: Policy::default_config();
		$this->original_bypass = get_option( CB_CORE_BYPASS_OPT, false );
		delete_option( CB_CORE_BYPASS_OPT );
		Settings::set_key( Policy::SETTINGS_KEY, Policy::default_config(), 'two-factor-test' );
	}

	public function tear_down(): void {
		Settings::set_key( Policy::SETTINGS_KEY, $this->original_policy, 'two-factor-test-restore' );
		if ( false === $this->original_bypass ) {
			delete_option( CB_CORE_BYPASS_OPT );
		} else {
			update_option( CB_CORE_BYPASS_OPT, $this->original_bypass, false );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_tf1_policy_has_only_optional_and_enforce_privileged_scope(): void {
		self::assertSame(
			[ 'mode' => Policy::MODE_OPTIONAL, 'scope' => Policy::SCOPE_PRIVILEGED ],
			Policy::default_config()
		);
		self::assertTrue( Policy::is_valid_mode( Policy::MODE_OPTIONAL ) );
		self::assertTrue( Policy::is_valid_mode( Policy::MODE_ENFORCE ) );
		self::assertFalse( Policy::is_valid_mode( 'off' ) );

		Settings::set_key( Policy::SETTINGS_KEY, [
			'mode'  => 'unexpected',
			'scope' => 'arbitrary-users',
		], 'two-factor-test' );

		self::assertSame( Policy::default_config(), Policy::config() );
	}

	public function test_tf2_enforcement_scope_reuses_privileged_access_policy(): void {
		$admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$admin         = get_userdata( $admin_id );
		$subscriber    = get_userdata( $subscriber_id );

		self::assertInstanceOf( WP_User::class, $admin );
		self::assertInstanceOf( WP_User::class, $subscriber );

		Settings::set_key( Policy::SETTINGS_KEY, [
			'mode'  => Policy::MODE_ENFORCE,
			'scope' => Policy::SCOPE_PRIVILEGED,
		], 'two-factor-test' );

		self::assertTrue( Policy::requires_enrollment( $admin ) );
		self::assertFalse( Policy::requires_enrollment( $subscriber ) );

		Settings::set_key( Policy::SETTINGS_KEY, Policy::default_config(), 'two-factor-test' );
		self::assertFalse( Policy::requires_enrollment( $admin ) );
	}

	public function test_tf3_enforce_policy_requires_trusted_enrolled_operator_and_closed_failsafe(): void {
		RolePolicySchema::repair();
		update_option( 'cb_core_privileged_guard_bootstrapped', time(), false );

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$admin = get_userdata( $admin_id );
		self::assertInstanceOf( WP_User::class, $admin );

		try {
			PolicyMutation::set_mode( Policy::MODE_ENFORCE, $admin, 'test' );
			self::fail( 'Untrusted administrator was allowed to enable 2FA enforcement.' );
		} catch ( RuntimeException ) {
			self::assertSame( Policy::MODE_OPTIONAL, Policy::mode() );
		}

		$operator_id = $this->create_trusted_operator();
		$operator = get_userdata( $operator_id );
		self::assertInstanceOf( WP_User::class, $operator );

		try {
			PolicyMutation::set_mode( Policy::MODE_ENFORCE, $operator, 'test' );
			self::fail( 'Unenrolled trusted Operator was allowed to enable 2FA enforcement.' );
		} catch ( RuntimeException ) {
			self::assertSame( Policy::MODE_OPTIONAL, Policy::mode() );
		}

		CredentialStore::store_totp_secret( $operator_id, 'JBSWY3DPEHPK3PXP' );
		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );
		try {
			PolicyMutation::set_mode( Policy::MODE_ENFORCE, $operator, 'test' );
			self::fail( 'Failsafe-active request was allowed to enable 2FA enforcement.' );
		} catch ( RuntimeException ) {
			self::assertSame( Policy::MODE_OPTIONAL, Policy::mode() );
		}
		delete_option( CB_CORE_BYPASS_OPT );

		self::assertTrue( PolicyMutation::set_mode( Policy::MODE_ENFORCE, $operator, 'test' ) );
		self::assertSame( Policy::MODE_ENFORCE, Policy::mode() );
	}

	public function test_tf4_optional_deescalation_requires_trusted_operator_but_not_active_base_enrollment(): void {
		RolePolicySchema::repair();
		update_option( 'cb_core_privileged_guard_bootstrapped', time(), false );

		$operator_id = $this->create_trusted_operator();
		$operator = get_userdata( $operator_id );
		self::assertInstanceOf( WP_User::class, $operator );
		CredentialStore::store_totp_secret( $operator_id, 'JBSWY3DPEHPK3PXP' );

		self::assertTrue( PolicyMutation::set_mode( Policy::MODE_ENFORCE, $operator, 'test' ) );
		CredentialStore::clear( $operator_id );
		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );

		self::assertTrue( PolicyMutation::set_mode( Policy::MODE_OPTIONAL, $operator, 'test' ) );
		self::assertSame( Policy::MODE_OPTIONAL, Policy::mode() );
	}

	public function test_tf5_totp_secret_is_authenticated_encrypted_and_user_bound(): void {
		self::assertTrue( CredentialCipher::available(), 'PHP runtime does not provide the required sodium secretbox primitive.' );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$secret = 'JBSWY3DPEHPK3PXP';

		CredentialStore::store_totp_secret( $user_id, $secret );

		$stored = get_user_meta( $user_id, CredentialStore::META_SECRET, true );
		self::assertIsArray( $stored );
		self::assertSame( 1, $stored['version'] ?? null );
		self::assertSame( 'sodium_secretbox', $stored['algorithm'] ?? null );
		self::assertStringNotContainsString( $secret, (string) wp_json_encode( $stored ) );
		self::assertSame( $secret, CredentialStore::totp_secret( $user_id ) );
		self::assertTrue( CredentialStore::is_enrolled( $user_id ) );

		$this->expectException( RuntimeException::class );
		CredentialCipher::decrypt( $stored, $other_id );
	}

	public function test_tf6_recovery_codes_are_one_time_and_never_persist_plaintext(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$codes = RecoveryCodes::generate_for_user( $user_id );

		self::assertCount( RecoveryCodes::CODE_COUNT, $codes );
		self::assertSame( RecoveryCodes::CODE_COUNT, RecoveryCodes::remaining( $user_id ) );

		$stored = CredentialStore::recovery_hashes( $user_id );
		self::assertCount( RecoveryCodes::CODE_COUNT, $stored );

		foreach ( $codes as $code ) {
			self::assertStringNotContainsString( str_replace( '-', '', $code ), (string) wp_json_encode( $stored ) );
		}

		$first = $codes[0];
		self::assertTrue( RecoveryCodes::consume( $user_id, $first ) );
		self::assertFalse( RecoveryCodes::consume( $user_id, $first ) );
		self::assertSame( RecoveryCodes::CODE_COUNT - 1, RecoveryCodes::remaining( $user_id ) );
	}

	public function test_tf7_stale_recovery_snapshot_cannot_restore_or_double_consume_a_code(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$codes = RecoveryCodes::generate_for_user( $user_id );
		$stale = CredentialStore::recovery_hashes( $user_id );

		self::assertCount( RecoveryCodes::CODE_COUNT, $stale );
		self::assertTrue( RecoveryCodes::consume( $user_id, $codes[0] ) );
		$current = CredentialStore::recovery_hashes( $user_id );
		self::assertCount( RecoveryCodes::CODE_COUNT - 1, $current );

		self::assertFalse(
			CredentialStore::claim_recovery_hashes( $user_id, $stale, array_slice( $stale, 1 ) ),
			'A stale recovery snapshot was able to overwrite newer one-time-code state.'
		);
		self::assertSame( $current, CredentialStore::recovery_hashes( $user_id ) );
		self::assertFalse( RecoveryCodes::consume( $user_id, $codes[0] ) );
	}

	public function test_tf8_authentication_material_never_enters_base_settings(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$secret = 'JBSWY3DPEHPK3PXP';

		CredentialStore::store_totp_secret( $user_id, $secret );
		$codes = RecoveryCodes::generate_for_user( $user_id );
		$settings_json = (string) wp_json_encode( Settings::get() );

		self::assertStringNotContainsString( $secret, $settings_json );
		foreach ( $codes as $code ) {
			self::assertStringNotContainsString( str_replace( '-', '', $code ), $settings_json );
		}
	}

	public function test_tf9_clear_removes_all_user_bound_authentication_material_through_canonical_replay_authority(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		RecoveryCodes::generate_for_user( $user_id );

		self::assertFalse( method_exists( CredentialStore::class, 'set_last_timestep' ) );
		self::assertTrue( CredentialStore::claim_timestep( $user_id, 123 ) );

		CredentialStore::clear( $user_id );

		self::assertNull( CredentialStore::totp_secret( $user_id ) );
		self::assertFalse( CredentialStore::is_enrolled( $user_id ) );
		self::assertSame( [], CredentialStore::recovery_hashes( $user_id ) );
		self::assertSame( -1, CredentialStore::last_timestep( $user_id ) );
	}
	private function create_trusted_operator(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );

		PrivilegedAccessGuard::trusted_mutation(
			static function () use ( $user ): void {
				$user->add_role( Roles::OPERATOR_ROLE );
			}
		);
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'two_factor_test_fixture' ) );

		return $user_id;
	}

}
