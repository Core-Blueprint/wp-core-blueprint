<?php
declare(strict_types=1);
/** Bootstrap for native WordPress Admin Navigation presentation governance. */

namespace CB\Core\AdminNavigation;

use CB\Core\Governance\EventRegistry;
use CB\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	private static bool $events_booted = false;
	private static bool $request_handlers_booted = false;
	private static bool $sidebar_booted = false;
	private static bool $toolbar_booted = false;

	public static function boot(): void {
		if ( ! self::$events_booted ) {
			self::$events_booted = true;
			add_action( 'init', [ self::class, 'register_events' ], 1 );
		}

		// Request handlers may be discovered after an earlier non-endpoint boot in
		// long-lived test/runtime processes. Guard this boundary independently so
		// admin-post.php and admin-ajax.php can always register their handlers.
		if (
			! self::$request_handlers_booted
			&& ( RequestContext::is_admin_post() || RequestContext::is_ajax() )
		) {
			self::$request_handlers_booted = true;
			Admin::boot();
		}

		// Sidebar governance exists only on normal site wp-admin screens. Network
		// Admin and User Admin are explicitly outside the v1 contract.
		if (
			! self::$sidebar_booted
			&& RequestContext::is_admin_screen()
			&& ! is_network_admin()
			&& ! is_user_admin()
		) {
			self::$sidebar_booted = true;
			add_filter( 'custom_menu_order', [ AdminMenuRuntime::class, 'filter_custom_menu_order' ], PHP_INT_MAX );
			add_filter( 'menu_order', [ AdminMenuRuntime::class, 'filter_menu_order' ], PHP_INT_MAX );
			add_action( 'admin_menu', [ AdminMenuRuntime::class, 'apply_visibility' ], PHP_INT_MAX );
		}

		// The same stored toolbar rule may apply in wp-admin and on the frontend
		// when WordPress exposes the same node ID. Discovery itself remains admin-only.
		if ( ! self::$toolbar_booted ) {
			self::$toolbar_booted = true;
			add_action( 'admin_bar_menu', [ ToolbarRuntime::class, 'apply' ], PHP_INT_MAX );
		}
	}

	public static function register_events(): void {
		EventRegistry::register_core_many( [
			'ui.admin.navigation.changed' => __( 'Admin Navigation policy changed', 'core-blueprint' ),
			'ui.admin.navigation.reset'   => __( 'Admin Navigation policy reset', 'core-blueprint' ),
		] );
	}

	private function __construct() {}
}
