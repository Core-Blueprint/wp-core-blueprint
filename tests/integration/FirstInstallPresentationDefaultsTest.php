<?php
declare(strict_types=1);

use CoreBlueprint\Core\Core;
use CoreBlueprint\Core\HUD\Settings as HudSettings;
use CoreBlueprint\Core\Integrity\State as IntegrityState;
use CoreBlueprint\Core\MediaReplace\State as MediaReplaceState;
use CoreBlueprint\Core\Notes\State as NotesState;
use CoreBlueprint\Core\PackageDownload\State as PackageDownloadState;
use CoreBlueprint\Core\Permissions\UserRolesState;
use CoreBlueprint\Core\Reports\State as ReportsState;
use CoreBlueprint\Core\Permissions\Roles;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Setup\Onboarding;
use CoreBlueprint\Core\Setup\ReviewRepository;

final class CB_Base_First_Install_Presentation_Defaults_Test extends WP_UnitTestCase {

	private array $saved_request = [];

	public function set_up(): void {
		parent::set_up();
		$this->saved_request = $_REQUEST;
		$_REQUEST = [];
		wp_set_current_user( 0 );
		$this->clear_state();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->clear_state();
		$_REQUEST = $this->saved_request;
		parent::tear_down();
	}

	public function test_first_activation_starts_optional_workflows_off_and_hud_off(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		Core::activate();

		self::assertFalse( IntegrityState::is_enabled() );
		self::assertFalse( NotesState::is_enabled() );
		self::assertFalse( ReportsState::is_enabled() );
		self::assertFalse( MediaReplaceState::is_enabled() );
		self::assertFalse( PackageDownloadState::is_enabled() );
		self::assertFalse( UserRolesState::is_enabled() );
		self::assertFalse( HudSettings::site_enabled() );
		self::assertTrue( Settings::shield_enabled(), 'Core Shield remains part of the Base safety foundation.' );
	}

	public function test_single_plugin_first_activation_guides_activator_to_core_setup_once(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		$_REQUEST = [
			'action' => 'activate',
			'plugin' => CB_CORE_BASENAME,
		];

		Core::activate();

		$pending = get_option( Onboarding::REDIRECT_OPTION, null );
		self::assertIsArray( $pending );
		self::assertSame( $user_id, (int) ( $pending['user_id'] ?? 0 ) );

		self::assertSame(
			admin_url( 'admin.php?page=core-blueprint-setup&tab=overview' ),
			Onboarding::consume_redirect_url()
		);
		self::assertNull( get_option( Onboarding::REDIRECT_OPTION, null ) );
		self::assertSame( '', Onboarding::consume_redirect_url() );
	}

	public function test_bulk_reactivation_and_network_activation_do_not_queue_first_run_redirects(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_REQUEST = [
			'action' => 'activate-selected',
			'plugin' => CB_CORE_BASENAME,
		];
		Core::activate();
		self::assertNull( get_option( Onboarding::REDIRECT_OPTION, null ) );

		update_option( 'cb_core_first_activated_at', '2026-01-01 00:00:00', false );
		$_REQUEST = [
			'action' => 'activate',
			'plugin' => CB_CORE_BASENAME,
		];
		Core::activate();
		self::assertNull( get_option( Onboarding::REDIRECT_OPTION, null ) );

		delete_option( 'cb_core_first_activated_at' );
		Core::activate( true );
		self::assertNull( get_option( Onboarding::REDIRECT_OPTION, null ) );
	}

	public function test_plugins_screen_exposes_durable_core_setup_action_link_for_trusted_activator(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		// Model the real first-activation authority path. Privileged Access Guard
		// may intentionally suppress manage_options for an unapproved administrator,
		// while the genuine first activator is promoted and approved as trust root.
		Core::activate();
		self::assertTrue( current_user_can( 'manage_options' ) );
		self::assertContains( Roles::OPERATOR_ROLE, (array) get_userdata( $user_id )->roles );

		$links = Onboarding::plugin_action_links( [ '<a href="#">Deactivate</a>' ] );
		self::assertStringContainsString( 'page=core-blueprint-setup', $links[0] );
		self::assertStringContainsString( 'tab=overview', $links[0] );
		self::assertStringContainsString( 'Core Setup', $links[0] );
	}

	public function test_public_v1_optional_workflow_defaults_are_disabled(): void {
		$defaults = Settings::defaults();

		self::assertFalse( (bool) ( $defaults['integrity']['enabled'] ?? true ) );
		self::assertFalse( (bool) ( $defaults['notes']['enabled'] ?? true ) );
		self::assertFalse( (bool) ( $defaults['reports']['enabled'] ?? true ) );
		self::assertFalse( MediaReplaceState::is_enabled() );
		self::assertFalse( PackageDownloadState::is_enabled() );
		self::assertFalse( UserRolesState::is_enabled() );
		self::assertFalse( HudSettings::site_enabled() );
	}

	public function test_noninteractive_first_activation_does_not_mint_an_operator(): void {
		wp_set_current_user( 0 );
		$before = Roles::operator_ids();
		sort( $before, SORT_NUMERIC );

		Core::activate();

		$after = Roles::operator_ids();
		sort( $after, SORT_NUMERIC );
		self::assertSame(
			$before,
			$after,
			'A first activation without an authenticated WordPress user must not manufacture CB Operator authority.'
		);
	}

	public function test_hud_site_preference_can_be_explicitly_toggled(): void {
		self::assertFalse( HudSettings::site_enabled() );
		self::assertTrue( HudSettings::set_site_enabled( true, 'test' ) );
		self::assertTrue( HudSettings::site_enabled() );
		self::assertTrue( HudSettings::set_site_enabled( false, 'test' ) );
		self::assertFalse( HudSettings::site_enabled() );
	}

	public function test_floating_menu_save_validates_before_visibility_mutation_and_restores_on_failure(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/HUD/MenuPreferences.php' );
		self::assertIsString( $source );

		$invalid_guard = strpos( $source, 'if ( ! is_array( $decoded ) )' );
		$previous      = strpos( $source, '$previous_hud_enabled = Settings::site_enabled();' );
		$persist       = false !== $previous
			? strpos( $source, 'Settings::set_site_enabled( $hud_enabled, $actor )', $previous )
			: false;
		$menu_save     = strpos( $source, '$saved = self::save_editor_payload( $decoded );' );
		$rollback      = strpos( $source, 'Settings::set_site_enabled( $previous_hud_enabled, $actor );' );

		self::assertNotFalse( $invalid_guard );
		self::assertNotFalse( $previous );
		self::assertNotFalse( $persist );
		self::assertNotFalse( $menu_save );
		self::assertNotFalse( $rollback );
		self::assertLessThan( $previous, $invalid_guard, 'Invalid menu JSON must fail before normal-path HUD visibility mutation.' );
		self::assertLessThan( $persist, $previous );
		self::assertLessThan( $menu_save, $persist );
		self::assertLessThan( $rollback, $menu_save, 'A failed menu save must restore the prior HUD visibility state.' );
	}

	public function test_explicit_enabled_state_survives_reactivation(): void {
		update_option( 'cb_core_first_activated_at', '2026-01-01 00:00:00', false );

		IntegrityState::set_enabled( true, 'test' );
		NotesState::set_enabled( true, 'test' );
		ReportsState::set_enabled( true, 'test' );
		MediaReplaceState::set_enabled( true, 'test' );
		PackageDownloadState::set_enabled( true, 'test' );
		UserRolesState::set_enabled( true, 'test' );
		self::assertTrue( HudSettings::set_site_enabled( true, 'test' ) );

		Core::activate();

		self::assertTrue( IntegrityState::is_enabled() );
		self::assertTrue( NotesState::is_enabled() );
		self::assertTrue( ReportsState::is_enabled() );
		self::assertTrue( MediaReplaceState::is_enabled() );
		self::assertTrue( PackageDownloadState::is_enabled() );
		self::assertTrue( UserRolesState::is_enabled() );
		self::assertTrue( HudSettings::site_enabled() );
	}

	public function test_typography_scale_is_canonical_rem_based(): void {
		$source = file_get_contents( CB_CORE_DIR . 'assets/css/tokens.css' );
		self::assertIsString( $source );

		$expected = [
			'--cb-fs-2xs' => '0.625rem',
			'--cb-fs-xs'  => '0.6875rem',
			'--cb-fs-sm'  => '0.75rem',
			'--cb-fs-md'  => '0.8125rem',
			'--cb-fs-lg'  => '0.875rem',
			'--cb-fs-xl'  => '1rem',
			'--cb-fs-2xl' => '1.25rem',
			'--cb-fs-3xl' => '1.5rem',
			'--cb-fs-4xl' => '1.75rem',
		];

		foreach ( $expected as $token => $value ) {
			self::assertStringContainsString( $token . ':', $source );
			self::assertMatchesRegularExpression(
				'/'. preg_quote( $token, '/' ) . ':\\s*' . preg_quote( $value, '/' ) . '\\s*;/',
				$source
			);
		}

		self::assertDoesNotMatchRegularExpression(
			'/--cb-fs-[a-z0-9-]+:\\s*[^;]*px\\s*;/i',
			$source,
			'Canonical Core Blueprint font-size tokens must not use px units.'
		);
	}

	public function test_core_blueprint_menu_is_positioned_after_settings(): void {
		$source = file_get_contents( CB_CORE_DIR . 'src/Admin/Admin.php' );
		self::assertIsString( $source );
		self::assertMatchesRegularExpression(
			'/self::get_menu_icon\(\),\s*81\s*\)/',
			$source
		);
	}

	private function clear_state(): void {
		foreach ( [
			CB_CORE_SETTINGS,
			'cb_core_first_activated_at',
			'cb_core_media_replace_enabled',
			'cb_core_package_download_enabled',
			'cb_core_user_roles_enabled',
			HudSettings::OPTION_DISABLED,
			Onboarding::REDIRECT_OPTION,
			ReviewRepository::OPTION,
		] as $option ) {
			delete_option( $option );
		}
	}
}
