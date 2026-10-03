<?php
declare(strict_types=1);
/**
 * MaintenanceTab - built-in Maintenance Report tab for the Logs page.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin\Pages\Logs\Tabs;

use CoreBlueprint\Core\Admin\Admin;
use CoreBlueprint\Core\Admin\TabNav;
use CoreBlueprint\Core\Log\MaintenanceReport as LogMaintenanceReport;
use CoreBlueprint\Core\Log\TimeFilter;

defined( 'ABSPATH' ) || exit;

final class MaintenanceTab {

	public const SLUG = 'maintenance';

	public static function render( string $tab, array $tab_labels ): void {
		if ( ! class_exists( LogMaintenanceReport::class ) ) {
			TabNav::render_subsystem_missing( __( 'Maintenance Report subsystem not loaded.', 'core-blueprint' ) );
			return;
		}

		$current_period = isset( $_GET['period'] ) ? TimeFilter::sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : TimeFilter::DEFAULT_PRESET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$mr_args = [
			'actor'    => isset( $_GET['actor'] )    ? sanitize_text_field( wp_unslash( $_GET['actor'] ) )    : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'category' => isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) )         : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'source'   => isset( $_GET['source'] )   ? sanitize_key( wp_unslash( $_GET['source'] ) )           : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'since'    => TimeFilter::since_timestamp( $current_period ),
			'page'     => isset( $_GET['paged'] )    ? max( 1, (int) $_GET['paged'] )                         : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page' => 50,
		];
		$mr_result   = LogMaintenanceReport::query( $mr_args );
		$mr_summary  = LogMaintenanceReport::summary_counts( 30 );
		$mr_actors   = LogMaintenanceReport::known_actors();
		$mr_snapshot = LogMaintenanceReport::kpi_snapshot( 30 );

		ob_start();
		include CB_CORE_DIR . 'templates/maintenance-report.php';
		$html = ob_get_clean();
		echo TabNav::inject( $html, Admin::LOGS_SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
