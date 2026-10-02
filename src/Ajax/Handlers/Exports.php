<?php
declare(strict_types=1);
/**
 * Exports - format-aware export AJAX handlers.
 *
 * Three endpoints: audit log, system log, maintenance report. Each reads
 * a `format` query param (default 'csv') and dispatches to LogExporter.
 * Adding a new format (e.g. 'pdf' via a CB Report plugin) requires no
 * changes here - the dispatcher fires `do_action( 'cb_core_export_{format}' )`
 * for any format CB Base doesn't handle natively.
 *
 * Endpoints kept separate per log type because their filter-arg shapes
 * differ; consolidating to one generic endpoint is a later refactor.
 *
 * @package Core_Blueprint
 */

namespace CB\Core\Ajax\Handlers;

use CB\Core\Ajax\Guards;
use CB\Core\Ajax\Request;
use CB\Core\Log\AuditLog;
use CB\Core\Log\LogExporter;
use CB\Core\Log\MaintenanceReport;
use CB\Core\Log\TimeFilter;

defined( 'ABSPATH' ) || exit;

final class Exports {
	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_export_audit',              [ __CLASS__, 'export_audit' ] );
		add_action( 'wp_ajax_cb_core_export_system_log',         [ __CLASS__, 'export_system_log' ] );
		add_action( 'wp_ajax_cb_core_export_maintenance_report', [ __CLASS__, 'export_maintenance_report' ] );
	}

	public static function export_audit(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$format = LogExporter::sanitize_format(
			isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv'
		);

		$period = isset( $_GET['period'] ) ? TimeFilter::sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : 'all';
		$args = [
			'event_like'       => isset( $_GET['event'] )    ? sanitize_text_field( wp_unslash( $_GET['event'] ) )    : '',
			'severity'         => isset( $_GET['severity'] ) ? sanitize_text_field( wp_unslash( $_GET['severity'] ) ) : '',
			'since'            => TimeFilter::since_mysql( $period ),
			'event_not_prefix' => 'system',
		];

		$handle = self::begin_stream( 'core-blueprint-audit', $format );
		if ( $handle ) {
			$meta = AuditLog::export_meta( 'audit', [
				'event'    => $args['event_like'],
				'severity' => $args['severity'],
				'period'   => $period,
			] );
			LogExporter::dispatch( $format, $handle, AuditLog::rows_iterator( $args ), AuditLog::columns(), $meta );
			fclose( $handle );
		}

		AuditLog::log( 'audit.exported', 'notice', [ 'format' => $format ] );
		exit;
	}

	public static function export_system_log(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$format = LogExporter::sanitize_format(
			isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv'
		);

		$period = isset( $_GET['period'] ) ? TimeFilter::sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : 'all';
		$args = [
			'event_like'   => isset( $_GET['event'] )    ? sanitize_text_field( wp_unslash( $_GET['event'] ) )    : '',
			'severity'     => isset( $_GET['severity'] ) ? sanitize_text_field( wp_unslash( $_GET['severity'] ) ) : '',
			'since'        => TimeFilter::since_mysql( $period ),
			'event_prefix' => 'system',
		];

		$handle = self::begin_stream( 'core-blueprint-system-log', $format );
		if ( $handle ) {
			$meta = AuditLog::export_meta( 'system_log', [
				'event'    => $args['event_like'],
				'severity' => $args['severity'],
				'period'   => $period,
			] );
			LogExporter::dispatch( $format, $handle, AuditLog::rows_iterator( $args ), AuditLog::columns(), $meta );
			fclose( $handle );
		}

		AuditLog::log( 'system_log.exported', 'notice', [ 'format' => $format ] );
		exit;
	}

	public static function export_maintenance_report(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		if ( ! class_exists( MaintenanceReport::class ) ) {
			wp_die( esc_html__( 'Maintenance Report subsystem not loaded.', 'core-blueprint' ), 500 );
		}

		$format = LogExporter::sanitize_format(
			isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv'
		);

		$period = isset( $_GET['period'] ) ? TimeFilter::sanitize( sanitize_text_field( wp_unslash( $_GET['period'] ) ) ) : 'all';
		$args = [
			'actor'    => isset( $_GET['actor'] )    ? sanitize_text_field( wp_unslash( $_GET['actor'] ) )    : '',
			'category' => isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) )         : '',
			'source'   => isset( $_GET['source'] )   ? sanitize_key( wp_unslash( $_GET['source'] ) )           : '',
			'since'    => TimeFilter::since_timestamp( $period ),
		];

		$handle = self::begin_stream( 'core-blueprint-logs-maintenance', $format );
		if ( $handle ) {
			$meta = MaintenanceReport::export_meta( [
				'actor'    => $args['actor'],
				'category' => $args['category'],
				'source'   => $args['source'],
				'period'   => $period,
			] );
			LogExporter::dispatch( $format, $handle, MaintenanceReport::rows_iterator( $args ), MaintenanceReport::columns(), $meta );
			fclose( $handle );
		}

		AuditLog::log( 'maintenance_report.exported', 'notice', [ 'format' => $format ] );
		exit;
	}

	/**
	 * Format-agnostic stream setup - clears PHP output buffers, emits
	 * the correct Content-Type + Content-Disposition for the requested
	 * format, and returns an open stdout handle for the exporter to
	 * write into.
	 *
	 * Filename pattern: `{basename}-YYYY-MM-DD-HHMMSS.{ext}` - consistent
	 * across formats so downstream tooling can glob by basename.
	 *
	 * @param string $basename Filename stem (no extension, no timestamp).
	 * @param string $format   Format slug (already sanitised by caller).
	 * @return resource|false  Open file handle, or false on failure.
	 */
	private static function begin_stream( string $basename, string $format ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$extension = LogExporter::extension( $format );
		$filename  = $basename . '-' . gmdate( 'Y-m-d-His' ) . '.' . $extension;
		$mime      = LogExporter::mime_type( $format );

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		return fopen( 'php://output', 'w' );
	}
}
