<?php
declare(strict_types=1);
/**
 * Console Bootstrap - wires the CB Console subsystem.
 *
 * Three things to register:
 *
 *   1. Admin page         - `Core Blueprint › Console` at slug
 *                           core-blueprint-console (position 95).
 *   2. REST endpoints     - /core-blueprint/v1/console/{commands,run}.
 *   3. HUD item           - operator-only quick-launch into the page.
 *
 * Asset enqueueing happens in Admin::enqueue_admin_assets which detects
 * the current page slug and includes pages/console.css + the
 * @cb-core/console JS module when appropriate. We don't duplicate that
 * here.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\Console;

use CoreBlueprint\Core\Admin\Admin;
use CoreBlueprint\Core\Admin\PageRegistry;
use CoreBlueprint\Core\Console\Rest\RunController;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	public static function boot(): void {
		add_action( 'core_blueprint_register_pages', [ __CLASS__, 'register_page' ] );
		add_action( 'rest_api_init',          [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'core_blueprint_hud_register_items',  [ __CLASS__, 'register_hud_item' ] );
		add_action( 'init',                  [ __CLASS__, 'register_i18n_filters' ], 1 );
	}

	/**
	 * Register the Console page with the central PageRegistry. Hooked on
	 * the `core_blueprint_register_pages` action - same pattern Foundation pages
	 * use; the action fires from PageRegistry::on_admin_menu() during the
	 * admin_menu pass so submenus can be added.
	 */
	public static function register_page(): void {
		PageRegistry::register_base( new Page() );
	}

	public static function register_rest_routes(): void {
		RunController::register_routes();
	}

	/**
	 * HUD item - operator-only, deep-links to the Console page. Order 65
	 * places it directly after the CLI documentation entry (60) so the
	 * docs and the runner cluster together in the operator's mental
	 * model.
	 *
	 * @param string $registry HUD Registry class name (passed by the action).
	 */
	public static function register_hud_item( string $registry ): void {
		if ( ! current_user_can( 'cb_use_cli' ) ) {
			return;
		}
		if ( ! class_exists( $registry ) ) {
			return;
		}

		$registry::add_item( [
			'id'         => 'cb-hud-console',
			'label'      => __( 'Console', 'core-blueprint' ),
			'section'    => 'cb-core',
			'url'        => admin_url( 'admin.php?page=' . Admin::CONSOLE_SLUG ),
			'order'      => 65,
			'capability' => 'cb_use_cli',
			'icon'       => 'arrow-right-alt2',
		] );
	}

	/** Register translation-bearing log labels after the textdomain is loaded. */
	public static function register_i18n_filters(): void {
		\CoreBlueprint\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
	}

	/**
	 * Register the human-readable label for the `console.executed`
	 * audit event so the Logs page can show it nicely.
	 *
	 * @param array<string, string> $labels
	 * @return array<string, string>
	 */
	public static function register_event_labels( $labels ) {
		if ( ! is_array( $labels ) ) {
			$labels = [];
		}
		$labels['console.executed'] = __( 'Console: command executed', 'core-blueprint' );
		return $labels;
	}
}
