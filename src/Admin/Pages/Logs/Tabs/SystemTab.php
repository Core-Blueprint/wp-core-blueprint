<?php
declare(strict_types=1);
/**
 * SystemTab - built-in System Log tab for the Logs page.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Admin\Pages\Logs\Tabs;

use CoreBlueprint\Core\Admin\Admin;
use CoreBlueprint\Core\Admin\TabNav;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Log\Chart;
use CoreBlueprint\Core\Log\SystemLog;
use CoreBlueprint\Core\Log\TimeFilter;

defined( 'ABSPATH' ) || exit;

final class SystemTab {

	public const SLUG = 'system';

	public static function render( string $tab, array $tab_labels ): void {
		if ( ! class_exists( SystemLog::class ) ) {
			TabNav::render_subsystem_missing( __( 'System log subsystem not loaded.', 'core-blueprint' ) );
			return;
		}

		$current_period = isset( $_GET['period'] ) ? TimeFilter::sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : TimeFilter::DEFAULT_PRESET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$sys_args = [
			'event_like'   => isset( $_GET['event'] )    ? sanitize_text_field( wp_unslash( $_GET['event'] ) )    : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'severity'     => isset( $_GET['severity'] ) ? sanitize_text_field( wp_unslash( $_GET['severity'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'user_id'      => isset( $_GET['user'] )     ? (int) $_GET['user']                                   : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'page'         => isset( $_GET['paged'] )    ? max( 1, (int) $_GET['paged'] )                        : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page'     => 50,
			'since'        => TimeFilter::since_mysql( $current_period ),
			'event_prefix' => 'system',
		];
		$sys_result = AuditLog::query( $sys_args );

		$sys_chart_rows = AuditLog::query( [
			'event_like'   => $sys_args['event_like'],
			'severity'     => $sys_args['severity'],
			'user_id'      => $sys_args['user_id'],
			'event_prefix' => 'system',
			'since'        => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
			'per_page'     => 500,
			'page'         => 1,
		] )['rows'] ?? [];
		$sys_chart_daily = Chart::daily_counts_from_rows( $sys_chart_rows, 30 );

		ob_start();
		include CB_CORE_DIR . 'templates/system-log.php';
		$html = ob_get_clean();
		echo TabNav::inject( $html, Admin::LOGS_SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
