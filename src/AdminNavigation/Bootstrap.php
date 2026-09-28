<?php
declare(strict_types=1);
/** Bootstrap for native WordPress Admin Navigation presentation governance. */

namespace CB\Core\AdminNavigation;

use CB\Core\Governance\EventRegistry;
use CB\Core\RequestContext;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	public static function boot(): void {
		// WordPress hook registration is already idempotent for the same
		// callback/priority pair. Do not shadow that lifecycle with process-local
		// static flags: long-lived processes and the WordPress test harness may
		// restore hook snapshots while preserving PHP static state.
		add_action( 'init', [ self::class, 'register_events' ], 1 );

		// admin-post.php and admin-ajax.php are request endpoints rather than
		// browser screens, but both require the canonical management handlers.
		if ( RequestContext::is_admin_post() || RequestContext::is_ajax() ) {
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
