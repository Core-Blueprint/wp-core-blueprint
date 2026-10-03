<?php
declare(strict_types=1);
/**
 * Preferences Bootstrap
 *
 * Lightweight subsystem bootstrap for the Preferences admin surface. The
 * Preferences page itself is owned by {@see \CoreBlueprint\Core\Admin\Pages\Preferences}
 * and registered via {@see \CoreBlueprint\Core\Admin\Admin::register_foundation_pages()};
 * this Bootstrap registers the HUD-item that links into it.
 *
 * Kept as a separate file so that future Preferences-wide work (a master
 * preferences API, scoped preference resolvers, etc.) has a natural home
 * matching every other subsystem's pattern.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\Preferences;

use CoreBlueprint\Core\Admin\Admin;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	/**
	 * Register all Preferences-subsystem hooks. Idempotent.
	 */
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'core_blueprint_hud_register_items', [ __CLASS__, 'register_hud_item' ] );
	}

	/**
	 * Add the Preferences entry to the HUD's cb-core section. Always
	 * available - the page itself shows different tabs based on the
	 * viewer's capabilities, so a viewer-with-only-cb_view_permissions
	 * still sees a useful subset.
	 *
	 * @param string $registry HUD Registry class name (passed by the action).
	 */
	public static function register_hud_item( string $registry ): void {
		if ( ! class_exists( $registry ) ) {
			return;
		}
		if ( ! current_user_can( 'cb_view_permissions' ) ) {
			return;
		}

		$registry::add_item( [
			'id'         => 'cb-hud-cb-preferences',
			'label'      => __( 'Preferences', 'core-blueprint' ),
			'section'    => 'cb-core',
			'url'        => admin_url( 'admin.php?page=' . Admin::PREFERENCES_SLUG ),
			'order'      => 50,
			'capability' => 'cb_view_permissions',
			'icon'       => 'admin-generic',
		] );
	}
}
