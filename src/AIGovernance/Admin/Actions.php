<?php
declare(strict_types=1);
/**
 * Privileged AI Governance admin-post handlers.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\AIGovernance\Admin;

use CB\Core\Admin\Admin;
use CB\Core\Admin\Pages\Logs\Tabs\AIActivityTab;
use CB\Core\AIGovernance\Exporter;
use CB\Core\AIGovernance\Settings;
use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Actions {
	public const EXPORT_ACTION = 'cb_core_ai_activity_export';
	public const RETENTION_ACTION = 'cb_core_ai_retention_update';

	public static function boot(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, [ __CLASS__, 'export' ] );
		add_action( 'admin_post_' . self::RETENTION_ACTION, [ __CLASS__, 'update_retention' ] );
	}

	public static function export(): void {
		self::guard( self::EXPORT_ACTION );
		$format = isset( $_POST['format'] ) ? sanitize_key( (string) wp_unslash( $_POST['format'] ) ) : 'csv';
		if ( ! in_array( $format, [ 'csv', 'json' ], true ) ) {
			wp_die( esc_html__( 'Unsupported AI activity export format.', 'core-blueprint' ), '', [ 'response' => 400 ] );
		}
		$filters = self::filters_from_post();
		$extension = 'json' === $format ? 'json' : 'csv';
		$filename = 'core-blueprint-ai-activity-' . gmdate( 'Ymd-His' ) . '.' . $extension;

		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json; charset=UTF-8' : 'text/csv; charset=UTF-8' ) );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$handle = fopen( 'php://output', 'wb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'Unable to open the AI activity export stream.', 'core-blueprint' ), '', [ 'response' => 500 ] );
		}
		$written = Exporter::write( $format, $handle, $filters );
		fclose( $handle );
		AuditLog::log( 'ai.activity.exported', 'notice', [
			'format' => $format,
			'rows'   => $written,
			'from'   => $filters['since'] ?? null,
			'until'  => $filters['until'] ?? null,
		] );
		exit;
	}

	public static function update_retention(): void {
		self::guard( self::RETENTION_ACTION );
		$days = isset( $_POST['retention_days'] ) ? (int) $_POST['retention_days'] : Settings::DEFAULT_RETENTION_DAYS;
		$days = min( Settings::MAX_RETENTION_DAYS, max( 0, $days ) );
		$previous = Settings::retention_days();
		Settings::update_retention_days( $days );
		AuditLog::log( 'ai.retention.updated', 'notice', [ 'from' => $previous, 'to' => $days ] );
		wp_safe_redirect( add_query_arg(
			[
				'page'      => Admin::LOGS_SLUG,
				'tab'       => AIActivityTab::SLUG,
				'retention' => 'updated',
			],
			admin_url( 'admin.php' )
		) . '#retention' );
		exit;
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage AI Governance.', 'core-blueprint' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	/** @return array<string,mixed> */
	private static function filters_from_post(): array {
		$filters = [];
		$from = isset( $_POST['from'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['from'] ) ) : '';
		$to = isset( $_POST['to'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['to'] ) ) : '';
		if ( self::valid_date( $from ) ) {
			$filters['since'] = $from . ' 00:00:00';
		}
		if ( self::valid_date( $to ) ) {
			$filters['until'] = $to . ' 23:59:59';
		}
		if ( ! empty( $_POST['actor'] ) ) {
			$filters['actor'] = max( 0, (int) $_POST['actor'] );
		}
		if ( isset( $_POST['correlation'] ) && is_string( $_POST['correlation'] ) ) {
			$correlation = sanitize_text_field( wp_unslash( $_POST['correlation'] ) );
			if ( wp_is_uuid( $correlation, 4 ) ) {
				$filters['correlation'] = $correlation;
			}
		}
		foreach ( [ 'source', 'type', 'transport', 'provider', 'model', 'operation', 'outcome' ] as $key ) {
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				if ( '' !== $value ) {
					$filters[ $key ] = $value;
				}
			}
		}
		return $filters;
	}

	private static function valid_date( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d' ) === $value;
	}
}
