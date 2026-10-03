<?php
declare(strict_types=1);
/**
 * Logs - the compliance / auditability hub.
 *
 * Acts purely as a dispatcher: tabs register themselves with
 * {@see TabRegistry}, the page iterates visible tabs and calls their
 * renderer. Built-in tabs are registered by ::ensure_builtin_tabs_registered();
 * Beacon adds its Connection tab from boot_paired_hooks(); future CB plugins
 * register their own tabs the same way.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Admin\Pages;

use CoreBlueprint\Core\Admin\PageBase;
use CoreBlueprint\Core\Admin\Pages\Logs\TabRegistry;
use CoreBlueprint\Core\Admin\Pages\Logs\Tabs\AuditTab;
use CoreBlueprint\Core\Admin\Pages\Logs\Tabs\MaintenanceTab;
use CoreBlueprint\Core\Admin\Pages\Logs\Tabs\OverviewTab;
use CoreBlueprint\Core\Admin\Pages\Logs\Tabs\RetentionTab;
use CoreBlueprint\Core\Admin\Pages\Logs\Tabs\SystemTab;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Log\MaintenanceReport as LogMaintenanceReport;

defined( 'ABSPATH' ) || exit;

final class Logs extends PageBase {

	const SLUG = 'core-blueprint-logs';

	/** Guard: built-in tab registration runs once. */
	private static bool $builtins_registered = false;

	public function slug(): string {
		return self::SLUG;
	}

	public function title(): string {
		return __( 'Logs', 'core-blueprint' );
	}

	public function menu_title(): string {
		return __( 'Logs', 'core-blueprint' );
	}

	public function position(): ?int {
		return 20;
	}

	public function render(): void {
		$this->guard();

		if ( ! class_exists( AuditLog::class ) ) {
			echo '<div class="wrap"><p>';
			esc_html_e( 'Logs subsystem not loaded.', 'core-blueprint' );
			echo '</p></div>';
			return;
		}

		self::ensure_builtin_tabs_registered();

		// Fire a filter hook so subsystems that weren't loaded at boot time
		// (hypothetically) get a last chance to register a tab. Most
		// subsystems register during their own boot - this is belt-and-
		// braces for late-arrival extension plugins.
		do_action( 'cb_core_logs_register_tabs' );

		$visible = TabRegistry::visible();
		if ( empty( $visible ) ) {
			// Should not happen - Audit tab is built-in and unconditional -
			// but guard anyway so the page degrades gracefully.
			echo '<div class="wrap"><p>';
			esc_html_e( 'No log tabs are available.', 'core-blueprint' );
			echo '</p></div>';
			return;
		}

		$tab_labels = [];
		foreach ( $visible as $slug => $spec ) {
			$tab_labels[ $slug ] = $spec['label'];
		}

		// Resolve the active tab from ?tab=…; fall back to the first visible
		// tab. The first tab is AuditTab::SLUG when unfiltered because it has
		// the lowest priority among built-ins.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active    = isset( $visible[ $requested ] ) ? $requested : array_key_first( $visible );

		call_user_func( $visible[ $active ]['renderer'], $active, $tab_labels );
	}

	/**
	 * Register Core Blueprint's built-in tabs. Idempotent - safe to call on
	 * every render; the registry deduplicates by slug.
	 *
	 * Tabs contributed by other subsystems (Beacon, future CB plugins)
	 * register themselves from their own bootstrap and are NOT touched here.
	 */
	private static function ensure_builtin_tabs_registered(): void {
		if ( self::$builtins_registered ) {
			return;
		}
		self::$builtins_registered = true;

		TabRegistry::register( OverviewTab::SLUG, [
			'label'    => __( 'Overview', 'core-blueprint' ),
			'priority' => 5,
			'renderer' => [ OverviewTab::class, 'render' ],
		] );

		TabRegistry::register( AuditTab::SLUG, [
			'label'    => __( 'Audit Log', 'core-blueprint' ),
			'priority' => 10,
			'renderer' => [ AuditTab::class, 'render' ],
		] );

		TabRegistry::register( SystemTab::SLUG, [
			'label'    => __( 'System Log', 'core-blueprint' ),
			'priority' => 20,
			'renderer' => [ SystemTab::class, 'render' ],
		] );

		TabRegistry::register( MaintenanceTab::SLUG, [
			'label'     => __( 'Maintenance Log', 'core-blueprint' ),
			'priority'  => 40,
			'condition' => static fn() => class_exists( LogMaintenanceReport::class ),
			'renderer'  => [ MaintenanceTab::class, 'render' ],
		] );

		TabRegistry::register( RetentionTab::SLUG, [
			'label'    => __( 'Retention', 'core-blueprint' ),
			'priority' => 60,
			'renderer' => [ RetentionTab::class, 'render' ],
		] );
	}
}
