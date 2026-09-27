<?php
declare(strict_types=1);

use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Permissions\PrivilegedAccessRegistry;
use CB\Core\Permissions\RolePolicySchema;
use CB\Core\Permissions\Roles;
use CB\Core\Profiles\Sections\SecuritySection;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\PolicyMutation;
use CB\Core\Security\TwoFactor\RecoveryCodes;
use CB\Core\Settings;

final class CB_Base_Two_Factor_Profiles_Contract_Test extends WP_UnitTestCase {

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
		Settings::set_key( Policy::SETTINGS_KEY, Policy::default_config(), 'two-factor-profile-test' );
		RolePolicySchema::repair();
		update_option( 'cb_core_privileged_guard_bootstrapped', time(), false );
	}

	public function tear_down(): void {
		Settings::set_key( Policy::SETTINGS_KEY, $this->original_policy, 'two-factor-profile-test-restore' );
		if ( false === $this->original_bypass ) {
			delete_option( CB_CORE_BYPASS_OPT );
		} else {
			update_option( CB_CORE_BYPASS_OPT, $this->original_bypass, false );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_tp1_security_section_v2_exports_only_portable_two_factor_policy(): void {
		$operator_id = $this->create_trusted_operator();
		$operator = get_userdata( $operator_id );
		self::assertInstanceOf( WP_User::class, $operator );
		wp_set_current_user( $operator_id );

		$secret = 'JBSWY3DPEHPK3PXP';
		CredentialStore::store_totp_secret( $operator_id, $secret );
		$codes = RecoveryCodes::generate_for_user( $operator_id );
		self::assertTrue( PolicyMutation::set_mode( Policy::MODE_ENFORCE, $operator, 'profile_test' ) );

		$section = new SecuritySection();
		self::assertSame( 2, $section->schema_version() );

		$export = $section->export();
		self::assertSame(
			[
				'mode'  => Policy::MODE_ENFORCE,
				'scope' => Policy::SCOPE_PRIVILEGED,
			],
			$export['two_factor'] ?? null
		);

		$json = (string) wp_json_encode( $export );
		self::assertStringNotContainsString( $secret, $json );
		self::assertStringNotContainsString( CredentialStore::META_SECRET, $json );
		self::assertStringNotContainsString( CredentialStore::META_RECOVERY, $json );
		foreach ( $codes as $code ) {
			self::assertStringNotContainsString( str_replace( '-', '', $code ), $json );
		}
	}

	public function test_tp2_security_section_rejects_authentication_material_in_profile_payload(): void {
		$section = new SecuritySection();
		$payload = $section->export();
		$payload['two_factor']['secret'] = 'NOT_PORTABLE';

		$this->expectException( InvalidArgumentException::class );
		$section->normalize( $payload );
	}

	public function test_tp3_profile_preflight_requires_authority_for_apply_and_rollback_targets(): void {
		$section = new SecuritySection();
		$current = $section->snapshot();
		$incoming = $current;
		$incoming['two_factor']['mode'] = Policy::MODE_ENFORCE;

		wp_set_current_user( 0 );
		try {
			$section->preflight( $incoming, $current );
			self::fail( 'Unauthenticated Profile preflight authorized a 2FA policy change.' );
		} catch ( RuntimeException ) {
			self::assertSame( Policy::MODE_OPTIONAL, Policy::mode() );
		}

		$operator_id = $this->create_trusted_operator();
		$operator = get_userdata( $operator_id );
		self::assertInstanceOf( WP_User::class, $operator );
		wp_set_current_user( $operator_id );

		try {
			$section->preflight( $incoming, $current );
			self::fail( 'Unenrolled Operator authorized Profile enforcement.' );
		} catch ( RuntimeException ) {
			self::assertSame( Policy::MODE_OPTIONAL, Policy::mode() );
		}

		CredentialStore::store_totp_secret( $operator_id, 'JBSWY3DPEHPK3PXP' );
		$section->preflight( $incoming, $current );

		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );
		try {
			$section->preflight( $incoming, $current );
			self::fail( 'Failsafe-active Profile preflight authorized new enforcement.' );
		} catch ( RuntimeException ) {
			self::assertSame( Policy::MODE_OPTIONAL, Policy::mode() );
		}
	}

	public function test_tp4_profile_rollback_restores_previous_enforce_mode_during_failsafe(): void {
		$operator_id = $this->create_trusted_operator();
		$operator = get_userdata( $operator_id );
		self::assertInstanceOf( WP_User::class, $operator );
		wp_set_current_user( $operator_id );
		CredentialStore::store_totp_secret( $operator_id, 'JBSWY3DPEHPK3PXP' );

		self::assertTrue( PolicyMutation::set_mode( Policy::MODE_ENFORCE, $operator, 'profile_test' ) );

		$section = new SecuritySection();
		$snapshot = $section->snapshot();
		$incoming = $snapshot;
		$incoming['two_factor']['mode'] = Policy::MODE_OPTIONAL;

		$section->preflight( $incoming, $snapshot );
		$section->apply( $incoming, 'profile-test' );
		self::assertSame( Policy::MODE_OPTIONAL, Policy::mode() );

		update_option( CB_CORE_BYPASS_OPT, 'emergency', false );
		$section->restore( $snapshot, $incoming, 'profile-test-rollback' );

		self::assertSame( Policy::MODE_ENFORCE, Policy::mode() );
		self::assertSame( $snapshot, $section->snapshot() );
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
		self::assertTrue( PrivilegedAccessRegistry::approve( $user, 0, 'two_factor_profile_fixture' ) );

		return $user_id;
	}
}
