<?php
declare(strict_types=1);

use CB\Core\Security\TwoFactor\CredentialCipher;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\RecoveryCodes;
use CB\Core\Settings;

final class CB_Base_Two_Factor_Policy_Storage_Contract_Test extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private array $original_policy = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_policy = is_array( Settings::get()[ Policy::SETTINGS_KEY ] ?? null )
			? Settings::get()[ Policy::SETTINGS_KEY ]
			: Policy::default_config();
		Settings::set_key( Policy::SETTINGS_KEY, Policy::default_config(), 'two-factor-test' );
	}

	public function tear_down(): void {
		Settings::set_key( Policy::SETTINGS_KEY, $this->original_policy, 'two-factor-test-restore' );
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

	public function test_tf3_totp_secret_is_authenticated_encrypted_and_user_bound(): void {
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

	public function test_tf4_recovery_codes_are_one_time_and_never_persist_plaintext(): void {
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

	public function test_tf5_authentication_material_never_enters_base_settings(): void {
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

	public function test_tf6_clear_removes_all_user_bound_authentication_material(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		RecoveryCodes::generate_for_user( $user_id );
		CredentialStore::set_last_timestep( $user_id, 123 );

		CredentialStore::clear( $user_id );

		self::assertNull( CredentialStore::totp_secret( $user_id ) );
		self::assertFalse( CredentialStore::is_enrolled( $user_id ) );
		self::assertSame( [], CredentialStore::recovery_hashes( $user_id ) );
		self::assertSame( -1, CredentialStore::last_timestep( $user_id ) );
	}
}
