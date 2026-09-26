<?php
declare(strict_types=1);

use CB\Core\Security\TwoFactor\AccountManager;
use CB\Core\Security\TwoFactor\ChallengeStore;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\EnrollmentStore;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\RecoveryCodes;
use CB\Core\Security\TwoFactor\Totp;
use CB\Core\Settings;

final class CB_Base_Two_Factor_Self_Service_Contract_Test extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private array $original_policy = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_policy = is_array( Settings::get()[ Policy::SETTINGS_KEY ] ?? null )
			? Settings::get()[ Policy::SETTINGS_KEY ]
			: Policy::default_config();
		Settings::set_key( Policy::SETTINGS_KEY, Policy::default_config(), 'two-factor-self-service-test' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		Settings::set_key( Policy::SETTINGS_KEY, $this->original_policy, 'two-factor-self-service-test-restore' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_ts1_self_service_enrollment_is_limited_to_own_privileged_identity(): void {
		$admin_id = self::factory()->user->create( [
			'role'      => 'administrator',
			'user_pass' => 'correct-password',
		] );
		$admin = get_userdata( $admin_id );
		self::assertInstanceOf( WP_User::class, $admin );

		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = get_userdata( $other_id );
		self::assertInstanceOf( WP_User::class, $other );

		wp_set_current_user( $admin_id );
		$secret = AccountManager::start_enrollment( $admin, 'correct-password' );
		self::assertMatchesRegularExpression( '/^[A-Z2-7]{32}$/', $secret );

		try {
			AccountManager::start_enrollment( $other, 'irrelevant' );
			self::fail( 'User was allowed to start enrollment for another privileged identity.' );
		} catch ( RuntimeException ) {
			self::assertNull( EnrollmentStore::pending_secret( $other_id ) );
		}

		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$subscriber = get_userdata( $subscriber_id );
		self::assertInstanceOf( WP_User::class, $subscriber );
		wp_set_current_user( $subscriber_id );

		$this->expectException( RuntimeException::class );
		AccountManager::start_enrollment( $subscriber, 'irrelevant' );
	}

	public function test_ts2_self_service_confirm_returns_recovery_codes_once(): void {
		$user_id = self::factory()->user->create( [
			'role'      => 'administrator',
			'user_pass' => 'correct-password',
		] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user_id );

		try {
			AccountManager::start_enrollment( $user, 'wrong-password' );
			self::fail( 'Wrong password started Base 2FA enrollment.' );
		} catch ( RuntimeException ) {
			self::assertNull( EnrollmentStore::pending_secret( $user_id ) );
		}

		$secret = AccountManager::start_enrollment( $user, 'correct-password' );

		try {
			AccountManager::confirm_enrollment(
				$user,
				'wrong-password',
				Totp::code( $secret, time() )
			);
			self::fail( 'Wrong password confirmed Base 2FA enrollment.' );
		} catch ( RuntimeException ) {
			self::assertFalse( CredentialStore::is_enrolled( $user_id ) );
			self::assertNotNull( EnrollmentStore::pending_secret( $user_id ) );
		}

		$codes = AccountManager::confirm_enrollment(
			$user,
			'correct-password',
			Totp::code( $secret, time() )
		);

		self::assertCount( RecoveryCodes::CODE_COUNT, $codes );
		self::assertTrue( CredentialStore::is_enrolled( $user_id ) );
		self::assertNull( EnrollmentStore::pending_secret( $user_id ) );
	}

	public function test_ts3_removal_requires_password_and_current_factor_and_revokes_challenges(): void {
		$user_id = self::factory()->user->create( [
			'role'      => 'administrator',
			'user_pass' => 'correct-password',
		] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user_id );

		$secret = 'JBSWY3DPEHPK3PXP';
		CredentialStore::store_totp_secret( $user_id, $secret );
		RecoveryCodes::generate_for_user( $user_id );
		$token = ChallengeStore::create( $user_id, false, admin_url(), ChallengeStore::FLOW_VERIFY );

		try {
			AccountManager::remove( $user, 'wrong-password', Totp::code( $secret, time() ) );
			self::fail( 'Wrong password removed Base 2FA.' );
		} catch ( RuntimeException ) {
			self::assertTrue( CredentialStore::is_enrolled( $user_id ) );
		}

		try {
			AccountManager::remove( $user, 'correct-password', 'invalid-factor' );
			self::fail( 'Wrong factor removed Base 2FA.' );
		} catch ( RuntimeException ) {
			self::assertTrue( CredentialStore::is_enrolled( $user_id ) );
		}

		$stats = AccountManager::remove(
			$user,
			'correct-password',
			Totp::code( $secret, time() )
		);

		self::assertTrue( $stats['changed'] );
		self::assertFalse( CredentialStore::is_enrolled( $user_id ) );
		self::assertSame( [], CredentialStore::recovery_hashes( $user_id ) );
		self::assertNull( ChallengeStore::inspect( $token ) );
	}

	public function test_ts4_enforce_policy_blocks_self_service_removal_without_external_owner(): void {
		$user_id = self::factory()->user->create( [
			'role'      => 'administrator',
			'user_pass' => 'correct-password',
		] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user_id );

		$secret = 'JBSWY3DPEHPK3PXP';
		CredentialStore::store_totp_secret( $user_id, $secret );
		Settings::set_key( Policy::SETTINGS_KEY, [
			'mode'  => Policy::MODE_ENFORCE,
			'scope' => Policy::SCOPE_PRIVILEGED,
		], 'two-factor-self-service-test' );

		$this->expectException( RuntimeException::class );
		AccountManager::remove(
			$user,
			'correct-password',
			Totp::code( $secret, time() )
		);
	}
	public function test_ts5_cancel_pending_enrollment_requires_current_password(): void {
		$user_id = self::factory()->user->create( [
			'role'      => 'administrator',
			'user_pass' => 'correct-password',
		] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user_id );

		AccountManager::start_enrollment( $user, 'correct-password' );
		self::assertTrue( EnrollmentStore::has_pending( $user_id ) );

		try {
			AccountManager::cancel_enrollment( $user, 'wrong-password' );
			self::fail( 'Wrong password cancelled pending Base 2FA enrollment.' );
		} catch ( RuntimeException ) {
			self::assertTrue( EnrollmentStore::has_pending( $user_id ) );
		}

		AccountManager::cancel_enrollment( $user, 'correct-password' );
		self::assertFalse( EnrollmentStore::has_pending( $user_id ) );
	}

}
