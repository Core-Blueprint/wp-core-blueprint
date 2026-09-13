<?php
declare(strict_types=1);
/**
 * Reports Bootstrap
 *
 * Wires the Reports subsystem into WordPress: schema registration, retention,
 * admin/HUD integration and module-status contributions. Report generation,
 * on-demand PDF rendering and AJAX handling are delegated to their dedicated
 * classes.
 *
 * Pattern mirrors CB\Core\Beacon\Bootstrap: a single boot() static call
 * invoked synchronously from Core::init_hooks(), then internal hook
 * registrations for everything that needs to defer.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Reports;

use CB\Core\Design\Editor\Assets as DesignEditorAssets;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	/**
	 * Register all Reports hooks. Called once from the main plugin bootstrap.
	 *
	 * Schema registration runs on plugins_loaded priority 4 - BEFORE
	 * the central schema reconciliation sweep at priority 5 - so the maintenance-reports table
	 * is migrated alongside every other Core Blueprint table.
	 */
	public static function boot(): void {
		add_action( 'plugins_loaded', [ Storage::class, 'register_schema' ], 4 );

		// Hook our cleanup callback into the unified Retention cron. Same
		// priority as the schema registration so both wirings land before
		// The central schema sweep runs at priority 5 - order does not matter
		// between the two within priority 4, but they must precede 5.
		add_action( 'init', [ Storage::class, 'register_retention_store' ], 2 );

		// Presentation metadata contains translations; expose it only after
		// the Core Blueprint textdomain has loaded on init priority 0.
		add_action( 'init', [ __CLASS__, 'register_i18n_filters' ], 1 );

		// Register the Reports admin page through the central registry.
		// PageRegistry fires this hook during admin_menu, so the page
		// shows up in the Core Blueprint sidebar between Logs (20) and
		// Safeguards (30).
		add_action( 'cb_core_register_pages', [ __CLASS__, 'register_admin_page' ] );

		// HUD items - the ActivationRegistry gate (via the `module` field) drops
		// these items if Reports is disabled. No separate State::is_enabled
		// check needed in the registration callbacks.
		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_item' ] );
		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_quick_action' ] );

		// Designer Mode is deliberately route-scoped. Reports may consume the
		// public Golden shell on its own Preferences tab without turning the
		// entire Preferences page into a Designer consumer.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_preferences_designer' ] );
	}

	/**
	 * Enqueue the canonical Designer Mode only for Preferences → Reports.
	 *
	 * Users without cb_manage_branding retain the normal read-only Preferences
	 * context and never receive an editing shell they cannot persist.
	 */
	public static function enqueue_preferences_designer(): void {
		if ( ! current_user_can( 'cb_manage_branding' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- route-only asset selection.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- route-only asset selection.

		if ( 'core-blueprint-preferences' !== $page || 'reports' !== $tab ) {
			return;
		}

		DesignEditorAssets::enqueue_designer_mode( __( 'Reports', 'core-blueprint' ) );
		wp_enqueue_media();
	}

	/**
	 * Register the Reports admin page. Hooked on cb_core_register_pages
	 * so registration is sequenced correctly relative to admin_menu.
	 *
	 * Master-switch gate (1.3.26-dev): only register the menu item when
	 * Reports is enabled. When disabled, the page disappears from the
	 * admin sidebar entirely; the Dashboard remains the
	 * activation recovery surface. Stored report
	 * snapshots are not touched - re-enabling brings the page
	 * right back with the full archive intact.
	 *
	 * Kept defensive: if PageRegistry or the Page class isn't loaded for
	 * some reason (e.g. a partial-deploy state), we skip silently rather
	 * than fataling - the Reports feature is non-critical and shouldn't
	 * crash the wider plugin.
	 */
	public static function register_admin_page(): void {
		if ( ! State::is_enabled() ) {
			return;
		}
		if ( ! class_exists( '\\CB\\Core\\Admin\\PageRegistry' ) ) {
			return;
		}
		if ( ! class_exists( '\\CB\\Core\\Admin\\Pages\\Reports' ) ) {
			return;
		}
		\CB\Core\Admin\PageRegistry::register_base( new \CB\Core\Admin\Pages\Reports() );
	}

	/**
	 * Register the Reports entry in the HUD's cb-core section. The Reports
	 * master-switch gate is delegated to ActivationRegistry via the `module`
	 * field; if Reports is disabled, this item is dropped before render.
	 *
	 * @param string $registry HUD Registry class name (passed by the action).
	 */
	public static function register_hud_item( string $registry ): void {
		if ( ! current_user_can( 'cb_view_reports' ) ) {
			return;
		}
		if ( ! class_exists( $registry ) ) {
			return;
		}

		$registry::add_item( [
			'id'            => 'cb-hud-cb-reports',
			'label'         => __( 'Reports', 'core-blueprint' ),
			'section'       => 'cb-core',
			'url'           => admin_url( 'admin.php?page=core-blueprint-reports' ),
			'order'         => 40,
			'capability'    => 'cb_view_reports',
			'icon'          => 'chart-bar',
			'module'        => 'reports',
			'status'        => 'reports',
		] );
	}

	/**
	 * Register the "Generate report" verb-action in the HUD's
	 * quick-actions section. Links to the Reports admin page with a
	 * query arg that triggers the generate flow.
	 *
	 * Capability gates on operator-managing-reports; module-gated so
	 * the action drops out when Reports is disabled.
	 *
	 * @param string $registry HUD Registry class name (passed by the action).
	 */
	public static function register_hud_quick_action( string $registry ): void {
		if ( ! current_user_can( 'cb_manage_reports' ) ) {
			return;
		}
		if ( ! class_exists( $registry ) ) {
			return;
		}

		$registry::add_item( [
			'id'         => 'cb-hud-quick-generate-report',
			'label'      => __( 'Generate report', 'core-blueprint' ),
			'section'    => 'quick-actions',
			'url'        => admin_url( 'admin.php?page=core-blueprint-reports&cb_action=generate' ),
			'order'      => 20,
			'capability' => 'cb_manage_reports',
			'icon'       => 'media-document',
			'module'     => 'reports',
		] );
	}

	/** Register translation-bearing metadata filters after the textdomain is loaded. */
	public static function register_i18n_filters(): void {
		\CB\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
	}

	/**
	 * Contribute Reports event labels to the central audit-log label
	 * registry. Currently only the subsystem master-switch transitions -
	 * the existing per-report events (generated, deleted, etc.) live
	 * elsewhere in the codebase and aren't centralised here yet.
	 *
	 * @param array<string,string> $labels Existing labels.
	 *
	 * @return array<string,string>
	 */
	public static function register_event_labels( array $labels ): array {
		$labels['reports.subsystem.enabled']  = __( 'Reports: subsystem enabled',  'core-blueprint' );
		$labels['reports.subsystem.disabled'] = __( 'Reports: subsystem disabled', 'core-blueprint' );

		return $labels;
	}
}
