<?php
declare(strict_types=1);

use CB\Core\Core;
use CB\Core\HUD\Settings as HudSettings;
use CB\Core\Integrity\State as IntegrityState;
use CB\Core\MediaReplace\State as MediaReplaceState;
use CB\Core\Notes\State as NotesState;
use CB\Core\PackageDownload\State as PackageDownloadState;
use CB\Core\Permissions\UserRolesState;
use CB\Core\Reports\State as ReportsState;
use CB\Core\Settings;

final class CB_Base_First_Install_Presentation_Defaults_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		$this->clear_state();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->clear_state();
		parent::tear_down();
	}

	public function test_first_activation_seeds_optional_workflows_off_and_hud_off(): void {
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

	public function test_established_activation_preserves_legacy_enabled_fallbacks(): void {
		update_option( 'cb_core_first_activated_at', '2026-01-01 00:00:00', false );
		update_option( CB_CORE_SETTINGS, Settings::defaults(), true );

		Core::activate();

		self::assertTrue( IntegrityState::is_enabled() );
		self::assertTrue( NotesState::is_enabled() );
		self::assertTrue( ReportsState::is_enabled() );
		self::assertTrue( MediaReplaceState::is_enabled() );
		self::assertTrue( PackageDownloadState::is_enabled() );
		self::assertTrue( UserRolesState::is_enabled() );
		self::assertTrue( HudSettings::site_enabled() );
	}

	public function test_hud_site_preference_can_be_explicitly_toggled(): void {
		self::assertTrue( HudSettings::site_enabled() );
		self::assertTrue( HudSettings::set_site_enabled( false, 'test' ) );
		self::assertFalse( HudSettings::site_enabled() );
		self::assertTrue( HudSettings::set_site_enabled( true, 'test' ) );
		self::assertTrue( HudSettings::site_enabled() );
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
		] as $option ) {
			delete_option( $option );
		}
	}
}
