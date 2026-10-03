<?php
declare(strict_types=1);
/**
 * AuditTab - built-in Audit Log tab for the Logs page.
 *
 * Registered automatically on Logs page render. Kept as a standalone class
 * (rather than an inline closure) so it can double as the canonical example
 * for third-party CB plugins registering their own tabs.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin\Pages\Logs\Tabs;

use CoreBlueprint\Core\Admin\Admin;
use CoreBlueprint\Core\Admin\TabNav;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Log\Chart;
use CoreBlueprint\Core\Log\TimeFilter;

defined( 'ABSPATH' ) || exit;

final class AuditTab {

	public const SLUG = 'audit';

	/**
	 * Tab renderer. Called by the Logs page dispatch after tab resolution.
	 *
	 * @param string $tab        Resolved active-tab slug ('audit' here).
	 * @param array  $tab_labels Map of tab-slug → translated label, used by
	 *                            TabNav::inject().
	 */
	public static function render( string $tab, array $tab_labels ): void {
		$current_period = isset( $_GET['period'] ) ? TimeFilter::sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : TimeFilter::DEFAULT_PRESET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = [
			'event_like'       => isset( $_GET['event'] )    ? sanitize_text_field( wp_unslash( $_GET['event'] ) )    : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'severity'         => isset( $_GET['severity'] ) ? sanitize_text_field( wp_unslash( $_GET['severity'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'user_id'          => isset( $_GET['user'] )     ? (int) $_GET['user']                                   : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'page'             => isset( $_GET['paged'] )    ? max( 1, (int) $_GET['paged'] )                        : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page'         => 50,
			'since'            => TimeFilter::since_mysql( $current_period ),
			'event_not_prefix' => 'system',
		];
		$result = AuditLog::query( $args );

		$chart_rows = AuditLog::query( [
			'event_like'       => $args['event_like'],
			'severity'         => $args['severity'],
			'user_id'          => $args['user_id'],
			'event_not_prefix' => 'system',
			'since'            => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
			'per_page'         => 500,
			'page'             => 1,
		] )['rows'] ?? [];
		$chart_daily = Chart::daily_counts_from_rows( $chart_rows, 30 );

		ob_start();
		include CB_CORE_DIR . 'templates/audit-log.php';
		$html = ob_get_clean();
		echo TabNav::inject( $html, Admin::LOGS_SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
