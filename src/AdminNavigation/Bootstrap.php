<?php
declare(strict_types=1);
/** Bootstrap for native WordPress Admin Navigation presentation governance. */

namespace CB\Core\AdminNavigation;

use CB\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Sidebar governance exists only on normal site wp-admin screens. Network
		// Admin and User Admin are explicitly outside the v1 contract.
		if ( RequestContext::is_admin_screen() && ! is_network_admin() && ! is_user_admin() ) {
			add_filter( 'custom_menu_order', [ AdminMenuRuntime::class, 'filter_custom_menu_order' ], PHP_INT_MAX );
			add_filter( 'menu_order', [ AdminMenuRuntime::class, 'filter_menu_order' ], PHP_INT_MAX );
			add_action( 'admin_menu', [ AdminMenuRuntime::class, 'apply_visibility' ], PHP_INT_MAX );
		}

		// The same stored toolbar rule may apply in wp-admin and on the frontend
		// when WordPress exposes the same node ID. Discovery itself remains admin-only.
		add_action( 'admin_bar_menu', [ ToolbarRuntime::class, 'apply' ], PHP_INT_MAX );
	}

	private function __construct() {}
}
