<?php
declare(strict_types=1);
/** Bootstrap for native WordPress Admin Navigation presentation governance. */

namespace CB\Core\AdminNavigation;

use CB\Core\Governance\EventRegistry;
use CB\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', [ self::class, 'register_events' ], 1 );
		if ( RequestContext::is_admin_post() ) {
			Admin::boot();
		}

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

	public static function register_events(): void {
		EventRegistry::register_core_many( [
			'ui.admin.navigation.changed' => __( 'Admin Navigation policy changed', 'core-blueprint' ),
			'ui.admin.navigation.reset'   => __( 'Admin Navigation policy reset', 'core-blueprint' ),
		] );
	}

	private function __construct() {}
}
