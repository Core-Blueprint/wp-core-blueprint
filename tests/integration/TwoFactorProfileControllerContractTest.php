<?php
declare(strict_types=1);

use CoreBlueprint\Core\Admin\ProfileActionForms;
use CoreBlueprint\Core\Admin\UserProfileSectionRegistry;
use CoreBlueprint\Core\Security\TwoFactor\AccountManager;
use CoreBlueprint\Core\Security\TwoFactor\CredentialStore;
use CoreBlueprint\Core\Security\TwoFactor\EnrollmentStore;
use CoreBlueprint\Core\Security\TwoFactor\Policy;
use CoreBlueprint\Core\Security\TwoFactor\ProfileController;
use CoreBlueprint\Core\Security\TwoFactor\RecoveryCodes;
use CoreBlueprint\Core\Settings;

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
		ProfileActionForms::_reset_for_testing();
		UserProfileSectionRegistry::_reset_for_testing();
		ProfileController::boot();
	}

	public function tear_down(): void {
		Settings::set_key( Policy::SETTINGS_KEY, $this->original_policy, 'two-factor-profile-ui-test-restore' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_tu1_controller_registers_only_profile_registry_and_admin_post_surfaces(): void {
		self::assertNotFalse( has_action( 'core_blueprint_register_user_profile_sections', [ ProfileController::class, 'register_profile_section' ] ) );
		self::assertNotFalse( has_action( 'admin_enqueue_scripts', [ ProfileController::class, 'enqueue_profile_assets' ] ) );
		self::assertFalse( has_action( 'show_user_profile', [ ProfileController::class, 'render' ] ) );
		self::assertFalse( has_action( 'edit_user_profile', [ ProfileController::class, 'render' ] ) );
		self::assertNotFalse( has_action( 'admin_post_' . ProfileController::START_ACTION, [ ProfileController::class, 'start' ] ) );
		self::assertNotFalse( has_action( 'admin_post_' . ProfileController::CONFIRM_ACTION, [ ProfileController::class, 'confirm' ] ) );
		self::assertNotFalse( has_action( 'admin_post_' . ProfileController::CANCEL_ACTION, [ ProfileController::class, 'cancel' ] ) );
		self::assertNotFalse( has_action( 'admin_post_' . ProfileController::REMOVE_ACTION, [ ProfileController::class, 'remove' ] ) );
		self::assertNotFalse( has_action( 'admin_post_' . ProfileController::REGENERATE_ACTION, [ ProfileController::class, 'regenerate_recovery_codes' ] ) );
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
		ob_start();
		ProfileActionForms::render();
		$action_forms = (string) ob_get_clean();

		self::assertStringContainsString( 'form="cb-core-two-factor-start-form"', $html );
		self::assertStringContainsString( 'button button-primary', $html );
		self::assertStringContainsString( 'cb-core-form-actions', $html );
		self::assertStringContainsString( 'cb-core-stack cb-core-stack--form', $html );
		self::assertStringContainsString( 'autocomplete="current-password"', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringContainsString( ProfileController::START_ACTION, $action_forms );
		self::assertStringContainsString( '_wpnonce', $action_forms );
		self::assertStringNotContainsString( ProfileController::REMOVE_ACTION, $html );

		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = get_userdata( $other_id );
		self::assertInstanceOf( WP_User::class, $other );

		ob_start();
		ProfileController::render( $other );
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function test_tu3_pending_profile_never_reveals_setup_secret_and_cancel_needs_no_password(): void {
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
		ob_start();
		ProfileActionForms::render();
		$action_forms = (string) ob_get_clean();

		self::assertStringNotContainsString( $secret, $html );
		self::assertStringContainsString( 'form="cb-core-two-factor-confirm-form"', $html );
		self::assertSame( 1, substr_count( $html, 'cb-core-form-actions' ) );
		self::assertStringContainsString( 'data-cb-two-factor-cancel', $html );
		self::assertStringContainsString( 'data-cb-two-factor-cancel-form="cb-core-two-factor-cancel-form"', $html );
		self::assertStringNotContainsString( 'cb-core-two-factor-cancel-password', $html );
		self::assertStringContainsString( ProfileController::CONFIRM_ACTION, $action_forms );
		self::assertStringContainsString( ProfileController::CANCEL_ACTION, $action_forms );
		self::assertStringNotContainsString( '<form', $html );
		self::assertSame( 1, substr_count( $html, 'autocomplete="current-password"' ) );
		self::assertLessThan(
			strpos( $html, 'cb-core-two-factor-confirm-password' ),
			strpos( $html, 'cb-core-two-factor-profile-code' )
		);
		self::assertStringContainsString( 'Enable two-factor authentication', $html );
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
		ob_start();
		ProfileActionForms::render();
		$action_forms = (string) ob_get_clean();

		self::assertStringContainsString( ProfileController::REMOVE_ACTION, $action_forms );
		self::assertStringContainsString( ProfileController::REGENERATE_ACTION, $action_forms );
		self::assertStringContainsString( 'form="cb-core-two-factor-regenerate-form"', $html );
		self::assertGreaterThanOrEqual( 2, substr_count( $html, 'cb-core-form-actions' ) );
		self::assertStringContainsString( 'form="cb-core-two-factor-remove-form"', $html );
		self::assertStringNotContainsString( '<form', $html );
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
		ob_start();
		ProfileActionForms::render();
		$action_forms = (string) ob_get_clean();

		self::assertStringNotContainsString( ProfileController::REMOVE_ACTION, $action_forms );
		self::assertStringContainsString( ProfileController::REGENERATE_ACTION, $action_forms );
		self::assertStringContainsString( 'cannot be removed', $html );
	}
	public function test_tu6_profile_redirect_uses_canonical_registry_anchor(): void {
		$method = new ReflectionMethod( ProfileController::class, 'profile_url' );
		$method->setAccessible( true );
		$url = (string) $method->invoke( null );

		self::assertStringEndsWith(
			'profile.php#cb-core-user-profile-core-blueprint-two-factor',
			$url
		);
	}


	public function test_tu7_secure_action_shell_keeps_admin_context_without_rendering_the_navigation_rail(): void {
		$screen = file_get_contents( CB_CORE_DIR . 'src/Admin/SecureActionScreen.php' );
		$css = file_get_contents( CB_CORE_DIR . 'assets/css/pages/secure-action.css' );

		self::assertIsString( $screen );
		self::assertIsString( $css );
		self::assertStringContainsString( "admin-header.php", $screen );
		self::assertStringContainsString( "admin-footer.php", $screen );
		self::assertStringNotContainsString( 'add_menu_page', $screen );
		self::assertStringNotContainsString( 'PageRegistry::', $screen );
		self::assertStringContainsString( 'body.wp-admin #adminmenumain', $css );
		self::assertStringContainsString( 'display: none;', $css );
		self::assertStringContainsString( 'body.wp-admin #wpcontent', $css );
		self::assertStringContainsString( 'margin-left: 0;', $css );
		self::assertStringContainsString( 'margin-right: 0;', $css );
	}

}
