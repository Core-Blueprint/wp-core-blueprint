<?php
declare(strict_types=1);
/**
 * Reports - AJAX handlers for maintenance-report generation and download.
 *
 * Two endpoints:
 *
 *   wp_ajax_cb_core_generate_maintenance_report
 *     POST. Validates the period, runs Generator::generate(), stores an
 *     immutable data snapshot, and returns the new report ID. No PDF is
 *     rendered during generation.
 *
 *   wp_ajax_cb_core_download_maintenance_report
 *     GET. Renders and streams the PDF from an existing report snapshot.
 *     Capability-checked (cb_view_reports) and nonce-checked per report.
 *
 * Capability gates:
 *   - generate  → cb_manage_reports (admins inherit via Caps filter when
 *                 the admin-toggle is enabled in settings).
 *   - download  → cb_view_reports (admins always have this; viewing is the
 *                 read-only side that the hide-toggle does NOT control).
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Ajax\Handlers;

use CB\Core\Ajax\Guards;
use CB\Core\Ajax\Request;
use CB\Core\Log\AuditLog;
use CB\Core\PDF\Api\PdfApi;
use CB\Core\PDF\RendererException;
use CB\Core\Reports\Generator;
use CB\Core\Reports\MaintenancePdf;
use CB\Core\Reports\State;
use CB\Core\Reports\Storage;

defined( 'ABSPATH' ) || exit;

final class Reports {

	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_generate_maintenance_report',     [ __CLASS__, 'generate_maintenance' ] );
		add_action( 'wp_ajax_cb_core_download_maintenance_report',     [ __CLASS__, 'download_maintenance' ] );
		add_action( 'wp_ajax_cb_core_delete_maintenance_report',       [ __CLASS__, 'delete_maintenance' ] );
		add_action( 'wp_ajax_cb_core_delete_all_maintenance_reports',  [ __CLASS__, 'delete_all_maintenance' ] );
	}

	// ─── Generate ─────────────────────────────────────────────────────────────

	/**
	 * Generate and persist a maintenance-report snapshot for the requested
	 * period. Returns { report_id } on success; PDF rendering only happens
	 * through the explicit View/Download actions on the Overview tab.
	 */
	public static function generate_maintenance(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_manage_reports();

		if ( ! State::is_enabled() ) {
			wp_send_json_error( [
				'code'    => 'cb_reports_subsystem_disabled',
				'message' => __( 'Reports is disabled. Enable it from the Core Blueprint Dashboard.', 'core-blueprint' ),
			], 403 );
		}

		$period_start = Request::text( 'period_start' );
		$period_end   = Request::text( 'period_end' );

		if ( '' === $period_start || '' === $period_end ) {
			wp_send_json_error( [
				'message' => __( 'period_start and period_end are required.', 'core-blueprint' ),
			], 400 );
		}

		try {
			$result = ( new Generator() )->generate( [
				'period_start' => $period_start,
				'period_end'   => $period_end,
			] );
		} catch ( \InvalidArgumentException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [
				'message' => __( 'Generation failed - see audit log for details.', 'core-blueprint' ),
				'detail'  => $e->getMessage(),
			], 500 );
		}

		$report_id = (int) ( $result['report_id'] ?? 0 );

		wp_send_json_success( [
			'report_id' => $report_id,
		] );
	}

	// ─── Download ─────────────────────────────────────────────────────────────

	/**
	 * Stream the PDF for an existing report row. Validates a per-report
	 * nonce so the request is tied to a valid WordPress session. The
	 * capability remains the authorization boundary; the nonce protects
	 * the download action against forged requests.
	 */
	public static function download_maintenance(): void {
		if ( ! current_user_can( 'cb_view_reports' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to download this report.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}

		$report_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		if ( $report_id <= 0 ) {
			wp_die(
				esc_html__( 'Missing or invalid report ID.', 'core-blueprint' ),
				esc_html__( 'Bad request', 'core-blueprint' ),
				[ 'response' => 400 ]
			);
		}

		$nonce = isset( $_GET['_cb_dl_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_cb_dl_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cb_core_download_report_' . $report_id ) ) {
			wp_die(
				esc_html__( 'Download link expired or invalid.', 'core-blueprint' ),
				esc_html__( 'Forbidden', 'core-blueprint' ),
				[ 'response' => 403 ]
			);
		}

		$row = Storage::find( $report_id );
		if ( null === $row || ! is_array( $row['report_data'] ?? null ) ) {
			wp_die(
				esc_html__( 'Report not found or its stored snapshot is invalid.', 'core-blueprint' ),
				esc_html__( 'Not found', 'core-blueprint' ),
				[ 'response' => 404 ]
			);
		}

		if ( ! PdfApi::is_available() ) {
			wp_die(
				esc_html__( 'PDF engine is not available.', 'core-blueprint' ),
				esc_html__( 'Service unavailable', 'core-blueprint' ),
				[ 'response' => 503 ]
			);
		}

		try {
			$pdf = ( new MaintenancePdf() )->render( $row );
		} catch ( RendererException $e ) {
			AuditLog::log( 'reports.pdf_render_failed', 'warning', [
				'report_id' => $report_id,
				'reason'    => $e->getMessage(),
			] );
			wp_die(
				esc_html__( 'The PDF could not be rendered. See the audit log for details.', 'core-blueprint' ),
				esc_html__( 'PDF rendering failed', 'core-blueprint' ),
				[ 'response' => 500 ]
			);
		} catch ( \Throwable $e ) {
			AuditLog::log( 'reports.pdf_render_failed', 'warning', [
				'report_id' => $report_id,
				'reason'    => $e->getMessage(),
			] );
			wp_die(
				esc_html__( 'The PDF could not be rendered. See the audit log for details.', 'core-blueprint' ),
				esc_html__( 'PDF rendering failed', 'core-blueprint' ),
				[ 'response' => 500 ]
			);
		}

		AuditLog::log( 'reports.maintenance_downloaded', 'info', [
			'report_id' => $report_id,
			'period'    => [ $row['period_start'] ?? '', $row['period_end'] ?? '' ],
		] );

		$filename        = self::download_filename( $row );
		$disposition_raw = isset( $_GET['disposition'] ) ? sanitize_key( wp_unslash( $_GET['disposition'] ) ) : 'attachment'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$disposition     = 'inline' === $disposition_raw ? 'inline' : 'attachment';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $filename . '"' );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF response.
		exit;
	}

	// ─── Internals ────────────────────────────────────────────────────────────

	/**
	 * Cap-check guard for the generate endpoint. The Caps filter from the
	 * Permissions subsystem decides whether administrators inherit
	 * cb_manage_reports based on the admin-toggle setting.
	 */
	private static function require_manage_reports(): void {
		if ( ! current_user_can( 'cb_manage_reports' ) ) {
			wp_send_json_error( [
				'message' => __( 'You do not have permission to generate reports.', 'core-blueprint' ),
			], 403 );
		}
	}

	/**
	 * Disabled Reports remains readable for recovery/history, but no endpoint
	 * may mutate or delete stored report snapshots until the module is enabled.
	 */
	private static function require_enabled_for_mutation(): void {
		if ( State::is_enabled() ) {
			return;
		}

		wp_send_json_error( [
			'code'    => 'cb_reports_subsystem_disabled',
			'message' => __( 'Reports is disabled. Enable it from the Core Blueprint Dashboard before changing report history.', 'core-blueprint' ),
		], 403 );
	}

	// ─── Delete ───────────────────────────────────────────────────────────────

	/**
	 * Delete a single Maintenance Report snapshot from the database.
	 *
	 * Capability: cb_manage_reports (same as generate). The view-only cap
	 * (cb_view_reports) is deliberately not enough - being able to *see*
	 * the list does not imply the right to delete entries. Operators and
	 * (when the admin-toggle is on) administrators can both delete.
	 *
	 * Uses Storage::delete() to remove the stored immutable snapshot. Audit-logged with the report's period and the actor.
	 */
	public static function delete_maintenance(): void {
		Request::nonce( 'cb_core_admin' );

		if ( ! current_user_can( 'cb_manage_reports' ) ) {
			wp_send_json_error( [
				'message' => __( 'You do not have permission to delete reports.', 'core-blueprint' ),
			], 403 );
		}

		self::require_enabled_for_mutation();

		$report_id = Request::int( 'report_id', 0 );
		if ( $report_id <= 0 ) {
			wp_send_json_error( [
				'message' => __( 'Missing or invalid report ID.', 'core-blueprint' ),
			], 400 );
		}

		// Look up first so the audit log entry has the period context - we
		// can't read it back after the row is gone.
		$row = Storage::find( $report_id );
		if ( null === $row ) {
			wp_send_json_error( [
				'message' => __( 'Report not found.', 'core-blueprint' ),
			], 404 );
		}

		$deleted = Storage::delete( $report_id );

		if ( ! $deleted ) {
			wp_send_json_error( [
				'message' => __( 'Could not delete the report.', 'core-blueprint' ),
			], 500 );
		}

		AuditLog::log( 'reports.deleted', 'notice', [
			'report_id'    => $report_id,
			'period_start' => (string) ( $row['period_start'] ?? '' ),
			'period_end'   => (string) ( $row['period_end'] ?? '' ),
			'by'           => get_current_user_id(),
		] );

		wp_send_json_success( [
			'report_id' => $report_id,
			'message'   => __( 'Report deleted.', 'core-blueprint' ),
		] );
	}

	/**
	 * Bulk-delete EVERY Maintenance Report on this site.
	 *
	 * Three gates protect this destructive endpoint:
	 *
	 *   1. Capability (cb_manage_reports) - same as single-row delete.
	 *   2. Nonce (cb_core_admin) - proves the request originates from a
	 *      legitimate admin page session.
	 *   3. Typed phrase ("DELETE ALL REPORTS", case-sensitive) - final
	 *      "are you really sure" check that mirrors the UI modal.
	 *
	 * Without all three, the endpoint refuses. The typed phrase is a
	 * deliberate ergonomic friction: the UI requires the user to copy
	 * the exact text into a confirm field before the modal's confirm
	 * button activates. Stripping the JS-side modal (DevTools, curl, …)
	 * gets you a 400, not a wipe.
	 */
	public static function delete_all_maintenance(): void {
		Request::nonce( 'cb_core_admin' );

		if ( ! current_user_can( 'cb_manage_reports' ) ) {
			wp_send_json_error( [
				'message' => __( 'You do not have permission to delete reports.', 'core-blueprint' ),
			], 403 );
		}

		self::require_enabled_for_mutation();

		$confirm_phrase = 'DELETE ALL REPORTS';
		$typed          = (string) Request::text( 'confirm', '' );

		// Strict equality - case-sensitive, no trim. Mismatch = refuse.
		// Trimming would let "DELETE ALL REPORTS  " through, which is
		// fine functionally but blurs the "type exactly this" contract.
		if ( $typed !== $confirm_phrase ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: confirmation phrase the user must type exactly */
					__( 'Confirmation phrase did not match. Type %s exactly to confirm.', 'core-blueprint' ),
					$confirm_phrase
				),
			], 400 );
		}

		$deleted = Storage::delete_all();

		AuditLog::log( 'reports.bulk_deleted', 'warning', [
			'count' => $deleted,
			'by'    => get_current_user_id(),
		] );

		wp_send_json_success( [
			'deleted' => $deleted,
			'message' => sprintf(
				/* translators: %d: number of deleted reports */
				_n( '%d report deleted.', '%d reports deleted.', $deleted, 'core-blueprint' ),
				$deleted
			),
		] );
	}

	// ─── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Compose a download filename from the report metadata. Format:
	 * onderhoudsrapport-{site-slug}-{period_start}-tot-{period_end}.pdf
	 *
	 * Sanitised via sanitize_file_name() to strip anything that would be
	 * unsafe in a Content-Disposition header on Windows clients.
	 */
	private static function download_filename( array $row ): string {
		$data       = is_array( $row['report_data'] ?? null ) ? $row['report_data'] : [];
		$site_data  = is_array( $data['site'] ?? null ) ? $data['site'] : [];
		$site       = sanitize_title( (string) ( $site_data['title'] ?? '' ) );
		$start      = (string) ( $row['period_start'] ?? 'unknown' );
		$end        = (string) ( $row['period_end'] ?? 'unknown' );

		$base = sprintf(
			'onderhoudsrapport-%s-%s-tot-%s.pdf',
			'' !== $site ? $site : 'site',
			$start,
			$end
		);

		return sanitize_file_name( $base );
	}
}
