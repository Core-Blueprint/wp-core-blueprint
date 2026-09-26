<?php
declare(strict_types=1);

use CB\Core\Security\TwoFactor\AccountManager;
use CB\Core\Security\TwoFactor\CredentialStore;
use CB\Core\Security\TwoFactor\EnrollmentStore;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\ProfileController;
use CB\Core\Security\TwoFactor\RecoveryCodes;
use CB\Core\Settings;

final class CB_Base_Two_Factor_Profile_Controller_Contract_Test extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private array $original_policy = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_policy = is_array( Settings::get()[ Policy::SETTINGS_KEY ] ?? null )
			? Settings::get()[ Policy::SETTINGS_KEY ]
			: Policy::default_config();
		Settings::set_key( Policy::SETTINGS_KEY, Policy::default_config(), 'two-factor-profile-ui-test' );
		wp_set_current_user( 0 );
		ProfileController::boot();
	}

	public function tear_down(): void {
		Settings::set_key( Policy::SETTINGS_KEY, $this->original_policy, 'two-factor-profile-ui-test-restore' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_tu1_controller_registers_only_self_profile_and_admin_post_surfaces(): void {
		self::assertNotFalse( has_action( 'show_user_profile', [ ProfileController::class, 'render' ] ) );
		self::assertFalse( has_action( 'edit_user_profile', [ ProfileController::class, 'render' ] ) );
		self::assertNotFalse( has_action( 'admin_post_' . ProfileController::START_ACTION, [ ProfileController::class, 'start' ] ) );
		self::assertNotFalse( has_action( 'admin_post_' . ProfileController::CONFIRM_ACTION, [ ProfileController::class, 'confirm' ] ) );
		self::assertNotFalse( has_action( 'admin_post_' . ProfileController::CANCEL_ACTION, [ ProfileController::class, 'cancel' ] ) );
		self::assertNotFalse( has_action( 'admin_post_' . ProfileController::REMOVE_ACTION, [ ProfileController::class, 'remove' ] ) );
	}

	public function test_tu2_unenrolled_privileged_profile_requires_password_to_start_and_renders_only_for_self(): void {
		$user_id = self::factory()->user->create( [
			'role'      => 'administrator',
			'user_pass' => 'correct-password',
		] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user_id );

		ob_start();
		ProfileController::render( $user );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( ProfileController::START_ACTION, $html );
		self::assertStringContainsString( '_wpnonce', $html );
		self::assertStringContainsString( 'autocomplete="current-password"', $html );
		self::assertStringNotContainsString( ProfileController::REMOVE_ACTION, $html );

		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = get_userdata( $other_id );
		self::assertInstanceOf( WP_User::class, $other );

		ob_start();
		ProfileController::render( $other );
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function test_tu3_pending_profile_never_reveals_setup_secret_and_requires_password_to_confirm_or_cancel(): void {
		$user_id = self::factory()->user->create( [
			'role'      => 'administrator',
			'user_pass' => 'correct-password',
		] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user_id );

		$secret = AccountManager::start_enrollment( $user, 'correct-password' );
		self::assertTrue( EnrollmentStore::has_pending( $user_id ) );

		ob_start();
		ProfileController::render( $user );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( $secret, $html );
		self::assertStringContainsString( ProfileController::CONFIRM_ACTION, $html );
		self::assertStringContainsString( ProfileController::CANCEL_ACTION, $html );
		self::assertGreaterThanOrEqual( 2, substr_count( $html, 'autocomplete="current-password"' ) );
		self::assertSame( [], CredentialStore::recovery_hashes( $user_id ) );
	}

	public function test_tu4_enrolled_optional_profile_exposes_secure_removal_form_and_recovery_count(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user_id );

		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		RecoveryCodes::generate_for_user( $user_id );

		ob_start();
		ProfileController::render( $user );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( ProfileController::REMOVE_ACTION, $html );
		self::assertStringContainsString( 'autocomplete="current-password"', $html );
		self::assertStringContainsString( (string) RecoveryCodes::CODE_COUNT, $html );
		self::assertStringNotContainsString( 'JBSWY3DPEHPK3PXP', $html );
	}

	public function test_tu5_enforce_policy_hides_removal_form_without_external_provider(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user_id );

		CredentialStore::store_totp_secret( $user_id, 'JBSWY3DPEHPK3PXP' );
		Settings::set_key( Policy::SETTINGS_KEY, [
			'mode'  => Policy::MODE_ENFORCE,
			'scope' => Policy::SCOPE_PRIVILEGED,
		], 'two-factor-profile-ui-test' );

		ob_start();
		ProfileController::render( $user );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( ProfileController::REMOVE_ACTION, $html );
		self::assertStringContainsString( 'cannot be removed', $html );
	}
}
