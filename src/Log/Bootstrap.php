<?php
declare(strict_types=1);
/**
 * Log Bootstrap
 *
 * Lightweight subsystem bootstrap for the audit/system-log surface.
 * Currently only registers the HUD item that links to the Logs admin
 * page - the audit-log writer/reader/retention machinery already wires
 * itself in {@see \CoreBlueprint\Core\Core::init_hooks()} alongside DB migrations.
 *
 * Kept as a separate Bootstrap class so that future Logs-side work
 * (per-event filters, exported-log notifications, etc.) has a natural
 * home that mirrors every other subsystem's pattern.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\Log;

use CoreBlueprint\Core\Admin\Admin;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	/**
	 * Register all Log-subsystem hooks. Idempotent - guarded by a static
	 * flag so calling boot() twice from different bootstrap paths is
	 * harmless.
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
	 * Add the Logs entry to the HUD's cb-core section. Always available -
	 * the Logs page itself is already capability-gated on whoever can
	 * reach the admin parent menu, and there is no master switch for
	 * audit logging (#TamperProof — Logs is the audit basis and must
	 * always be visible). Deliberately NO `module` field here so the
	 * ActivationRegistry gate never drops this item, regardless of any future
	 * module-toggle wiring.
	 *
	 * @param string $registry HUD Registry class name (passed by the action).
	 */
	public static function register_hud_item( string $registry ): void {
		if ( ! class_exists( $registry ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$registry::add_item( [
			'id'            => 'cb-hud-cb-logs',
			'label'         => __( 'Logs', 'core-blueprint' ),
			'section'       => 'cb-core',
			'url'           => admin_url( 'admin.php?page=' . Admin::LOGS_SLUG ),
			'order'         => 20,
			'capability'    => 'manage_options',
			'icon'          => 'list-view',
			// No 'module' field by design — Logs cannot be disabled.
			'status'        => 'logs',
		] );
	}
}
