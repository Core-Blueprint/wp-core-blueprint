<?php
declare(strict_types=1);
/**
 * Safeguards Bootstrap
 *
 * Lightweight subsystem bootstrap for the Safeguards admin surface. The
 * Safeguard health providers are registered by the canonical Modules\Status
 * registry. This Bootstrap owns only Safeguards HUD integration and keeps that
 * presentation wiring separate from the subsystem health providers.
 *
 * Future Safeguards-side work (cross-cutting hardening status, suite-
 * wide policy filters) has a natural home here.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\Safeguards;

use CoreBlueprint\Core\Admin\Admin;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	/**
	 * Register all Safeguards-subsystem hooks. Idempotent - guarded so
	 * repeated boot() calls are a no-op.
	 */
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_item' ] );
	}

	/**
	 * Add the Safeguards entry to the HUD's cb-core section. Operator-
	 * level: gated on cb_view_permissions which both administrators
	 * (via ADMIN_VIEW_CAPS) and operators (via OPERATOR_CAPS) inherit.
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
			'id'         => 'cb-hud-cb-safeguards',
			'label'      => __( 'Safeguards', 'core-blueprint' ),
			'section'    => 'cb-core',
			'url'        => admin_url( 'admin.php?page=' . Admin::SAFEGUARDS_SLUG ),
			'order'      => 30,
			'capability' => 'cb_view_permissions',
			'icon'       => 'shield-alt',
		] );
	}
}
