<?php
declare(strict_types=1);

namespace CB\Core\Integrity\Rest;

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\Integrity\Api\IntegrityApi;
use CB\Core\Integrity\Quarantine\Repository as QuarantineRepository;
use CB\Core\Integrity\Quarantine\Service as QuarantineService;
use CB\Core\Integrity\Scanner\LocaleDetector;
use CB\Core\Integrity\Scanner\ScanJobStatus;
use CB\Core\Integrity\Scheduler\Cron;
use CB\Core\Integrity\State;
use CB\Core\Integrity\Storage\BaselineReviewRepository;
use CB\Core\Integrity\Storage\ResultRepository;
use CB\Core\Integrity\Support\Audit;
use CB\Core\Integrity\Support\ResultFormatter;
use CB\Core\Integrity\Support\Summary;
use CB\Core\Settings;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function class_exists;
use function count;
use function current_time;
use function current_user_can;
use function defined;
use function function_exists;
use function get_current_user_id;
use function in_array;
use function is_array;
use function max;
use function microtime;
use function min;
use function register_rest_route;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;

defined( 'ABSPATH' ) || exit;

final class ScanController {
	public static function register_routes(): void {
		$controller = new self();

		register_rest_route( 'core-blueprint/v1', '/integrity/admin/scan', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $controller, 'scan' ],
			'permission_callback' => [ $controller, 'can_scan' ],
		] );

		// Progress polling endpoint. UI polls every 1000ms while a scan is
		// running; completion responses stay compact and findings are paginated.
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/scan-progress', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $controller, 'scan_progress' ],
			'permission_callback' => [ $controller, 'can_scan' ],
		] );

		register_rest_route( 'core-blueprint/v1', '/integrity/admin/summary', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $controller, 'summary' ],
			'permission_callback' => [ $controller, 'can_scan' ],
		] );

		register_rest_route( 'core-blueprint/v1', '/integrity/admin/findings', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $controller, 'findings' ],
			'permission_callback' => [ $controller, 'can_scan' ],
		] );

		register_rest_route( 'core-blueprint/v1', '/integrity/admin/clear', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ $controller, 'clear' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );

		register_rest_route( 'core-blueprint/v1', '/integrity/admin/baseline', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $controller, 'approve_baseline' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/baseline', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ $controller, 'clear_baseline' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/baseline/component', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $controller, 'approve_component_baseline' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/baseline/component', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ $controller, 'remove_component_baseline' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/baseline/review', [
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => [ $controller, 'mark_baseline_reviewed' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );


		register_rest_route( 'core-blueprint/v1', '/integrity/admin/settings', [
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => [ $controller, 'settings' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );

		// Distribution-locale management (1.3.12-dev). Re-detect runs
		// the LocaleDetector and persists its result. Set-mode lets
		// the operator switch between auto-detected, manual override,
		// and the get_locale() fallback.
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/locale/redetect', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $controller, 'redetect_locale' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );

		register_rest_route( 'core-blueprint/v1', '/integrity/admin/locale/mode', [
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => [ $controller, 'set_locale_mode' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );

		// Finding-driven Scanner Quarantine workspace. Read/inspect is available
		// to Scanner reviewers; every filesystem mutation remains operator-only.
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/quarantine', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $controller, 'quarantine_items' ],
			'permission_callback' => [ $controller, 'can_scan' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/quarantine', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $controller, 'quarantine_finding' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/quarantine/(?P<id>[a-z0-9_\-]+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $controller, 'inspect_quarantine' ],
			'permission_callback' => [ $controller, 'can_scan' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/quarantine/(?P<id>[a-z0-9_\-]+)/restore', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $controller, 'restore_quarantine' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/quarantine/(?P<id>[a-z0-9_\-]+)/delete', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ $controller, 'delete_quarantine' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/quarantine/(?P<id>[a-z0-9_\-]+)/note', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $controller, 'add_quarantine_note' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );
		register_rest_route( 'core-blueprint/v1', '/integrity/admin/quarantine/(?P<id>[a-z0-9_\-]+)/state', [
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => [ $controller, 'set_quarantine_state' ],
			'permission_callback' => [ $controller, 'can_manage_policy' ],
		] );

	}

	public function can_scan(): bool {
		return current_user_can( 'cb_manage_integrity' );
	}

	/**
	 * Core Scanner trust/policy authority.
	 *
	 * The optional administrator policy grant deliberately applies only to
	 * cb_manage_integrity (run/review). Baseline approval, evidence cleanup,
	 * scanner configuration, locale trust and enable/disable state remain
	 * operator-owned because each can change what future scans consider trusted
	 * or whether security evidence is retained at all.
	 */
	public function can_manage_policy(): bool {
		return current_user_can( 'cb_manage_integrity_policy' );
	}

	/**
	 * Standard "subsystem off" response shared by scan-starting REST
	 * routes. Returns HTTP 403 with a stable error code so the JS
	 * client can recognise it and surface a useful UI hint rather
	 * than a generic failure.
	 *
	 * Returned by {@see scan()}, {@see approve_baseline()},
	 * {@see approve_component_baseline()}, and {@see redetect_locale()} when
	 * Core Scanner's master switch is off. Read endpoints, settings PUT,
	 * baseline-cleanup endpoints, and locale-mode-set remain available;
	 * module activation itself is owned centrally by Modules\ActivationRegistry.
	 */
	private function subsystem_disabled_response(): WP_Error {
		return new WP_Error(
			'cb_integrity_subsystem_disabled',
			__( 'Core Scanner is disabled. Enable it via the master switch on the Core Scanner page.', 'core-blueprint' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Start an async scan.
	 *
	 * Behaviour:
	 *   - 202 Accepted + { job_id, started_at } when a scan is queued
	 *   - 409 Conflict + { existing_job_id, status, started_at } when
	 *     a scan is already running (UI uses this to resume polling
	 *     the existing job rather than starting a new one)
	 *
	 * Core Scanner no longer falls back to a potentially unbounded synchronous
	 * web request. If WordPress cannot persist the continuation event, the scan
	 * is rejected explicitly instead of risking a timeout and publishing a
	 * partial result as though it were complete. Hosts with DISABLE_WP_CRON may
	 * still use an external/system cron runner; the scheduled job remains valid.
	 *
	 * Dispatch is delegated to the public IntegrityApi service, which owns the shared
	 * lock, first continuation event and opportunistic cron kick semantics.
	 */
	public function scan( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! State::is_enabled() ) {
			return $this->subsystem_disabled_response();
		}

		// The persisted resumable job + lock are authoritative. Progress
		// transients are only a presentation cache and may expire independently.
		$active_job = ScanJobStatus::active_job();
		if ( null !== $active_job ) {
			$active = ScanJobStatus::progress_from_job( $active_job );
			return new WP_REST_Response(
				[
					'existing_job_id' => (string) ( $active['job_id'] ?? '' ),
					'status'          => (string) ( $active['status'] ?? 'running' ),
					'started_at'      => (float) ( $active['started_at'] ?? microtime( true ) ),
					'message'         => __( 'A scan is already running.', 'core-blueprint' ),
				],
				409
			);
		}

		$queued = $this->queue_scan_job( 'manual' );
		if ( $queued instanceof WP_Error ) {
			return $queued;
		}

		return new WP_REST_Response( $queued, 202 );
	}

	/**
	 * Poll endpoint: return current progress for a job.
	 *
	 * Progress transients are an optional UI cache. If one expires while the
	 * persisted job is still active, progress is reconstructed from that job.
	 * If the job already completed, the latest result's job_id provides an exact
	 * completion fallback. 404 therefore means the requested job is genuinely
	 * unknown rather than merely "the transient expired".
	 *
	 * Returns the progress state including phase counters. When status ===
	 * 'done', only a compact summary is attached. Full findings remain on the
	 * paginated findings endpoint; returning an entire 10k+ finding result from a
	 * one-second polling request would defeat the Scanner's storage scaling work.
	 */
	public function scan_progress( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scan_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$status  = IntegrityApi::scan_status( $scan_id );
		if ( $status instanceof WP_Error ) {
			return $status;
		}

		$response = $status;
		$response['job_id'] = (string) ( $response['scan_id'] ?? $scan_id );
		unset( $response['scan_id'] );

		return new WP_REST_Response( $response, 200 );
	}

	public function summary(): WP_REST_Response|WP_Error {
		$result   = ResultRepository::getLatest();
		$scan_id  = is_array( $result ) ? (string) ( $result['job_id'] ?? '' ) : '';

		if ( '' === $scan_id ) {
			return new WP_REST_Response( Summary::latest(), 200 );
		}

		$summary = IntegrityApi::summary( $scan_id );
		if ( $summary instanceof WP_Error ) {
			return $summary;
		}

		return new WP_REST_Response( [
			'status'     => $summary['status'],
			'last_scan'  => $summary['completed_at'],
			'scan_type'  => $summary['source'],
			'findings'   => $summary['findings'],
			'components' => $summary['components'],
			'completion' => $summary['completion'],
			'coverage'   => $summary['coverage'],
		], 200 );
	}

	public function findings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = ResultRepository::getLatest();
		if ( ! is_array( $result ) ) {
			return new WP_REST_Response( [
				'summary'       => ResultFormatter::summary( null ),
				'findings'      => [],
				'pagination'    => [ 'total' => 0, 'offset' => 0, 'limit' => 50, 'has_more' => false, 'next_offset' => null ],
				'groups'        => [],
				'component'     => '',
				'passed_groups' => [],
				'diff'          => ResultFormatter::diff_summary( null ),
			], 200 );
		}

		$scan_id   = (string) ( $result['job_id'] ?? '' );
		$limit     = max( 1, min( 250, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$offset    = max( 0, (int) ( $request->get_param( 'offset' ) ?: 0 ) );
		$component = sanitize_key( (string) ( $request->get_param( 'component' ) ?: '' ) );
		$public    = IntegrityApi::findings( $scan_id, $offset, $limit, $component );
		if ( $public instanceof WP_Error ) {
			return $public;
		}

		return new WP_REST_Response( [
			'summary'       => ResultFormatter::summary( $result ),
			'findings'      => $public['findings'],
			'pagination'    => $public['pagination'],
			'groups'        => ResultFormatter::grouped_findings_page( $result, $offset, $limit, $component ),
			'component'     => $component,
			'passed_groups' => ResultFormatter::grouped_passed( $result, 500 ),
			'diff'          => ResultFormatter::diff_summary( $result ),
		], 200 );
	}

	public function clear(): WP_REST_Response {
		ResultRepository::clear();
		$this->audit( 'integrity_results_cleared', 'notice', [] );

		return new WP_REST_Response( [
			'summary'  => ResultFormatter::summary( null ),
			'findings' => [],
			'groups'   => [],
			'passed_groups' => [],
			'diff' => ResultFormatter::diff_summary( null ),
			'settings' => ResultRepository::settings(),
		], 200 );
	}

	public function approve_baseline(): WP_REST_Response|WP_Error {
		if ( ! State::is_enabled() ) {
			return $this->subsystem_disabled_response();
		}

		$latest = ResultRepository::getLatest();
		if ( ! is_array( $latest ) ) {
			return new WP_Error(
				'cb_integrity_no_scan_for_baseline',
				__( 'Run an integrity scan before approving a baseline.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		$review_progress = BaselineReviewRepository::progress( $latest );
		if ( (int) ( $review_progress['total'] ?? 0 ) > 0 && empty( $review_progress['complete'] ) ) {
			return new WP_Error(
				'cb_integrity_baseline_review_required',
				__( 'Review every local-baseline candidate before approving the baseline.', 'core-blueprint' ),
				[ 'status' => 409, 'review' => $review_progress ]
			);
		}

		$eligibility = ResultRepository::baselineApprovalEligibility( $latest );
		if ( (int) ( $eligibility['candidates'] ?? 0 ) > 0 && empty( $eligibility['complete'] ) ) {
			return new WP_Error(
				'cb_integrity_baseline_incomplete',
				__( 'The local baseline was not approved because one or more components could not be snapshotted completely. Resolve unreadable files, symlinks, or filesystem errors and scan again first.', 'core-blueprint' ),
				[ 'status' => 409, 'eligibility' => $eligibility ]
			);
		}

		$baseline = ResultRepository::saveBaseline( $latest );
		if ( empty( $baseline['_baseline_saved'] ) ) {
			return new WP_Error(
				'cb_integrity_baseline_incomplete',
				__( 'The local baseline was not approved because the filesystem changed or became unreadable during approval. Run a fresh scan and review the affected component before trying again.', 'core-blueprint' ),
				[ 'status' => 409, 'baseline' => $baseline ]
			);
		}
		unset( $baseline['_baseline_saved'] );
		$this->audit( 'integrity_baseline_approved', 'notice', [ 'entry_count' => (int) ( $baseline['entry_count'] ?? 0 ) ] );

		$queued = $this->queue_scan_job( 'baseline' );
		$payload = $this->response_payload( $latest );
		$payload['baseline'] = $baseline;
		if ( $queued instanceof WP_Error ) {
			$payload['rescan_queued'] = false;
			$payload['rescan_error']  = $queued->get_error_message();
		} else {
			$payload = $queued + [ 'rescan_queued' => true ] + $payload;
		}

		return new WP_REST_Response( $payload, 200 );
	}

	public function approve_component_baseline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! State::is_enabled() ) {
			return $this->subsystem_disabled_response();
		}

		$latest = ResultRepository::getLatest();
		if ( ! is_array( $latest ) ) {
			return new WP_Error(
				'cb_integrity_no_scan_for_component_baseline',
				__( 'Run an integrity scan before approving a component baseline.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$slug = sanitize_text_field( (string) $request->get_param( 'slug' ) );

		if ( '' === $type || '' === $slug ) {
			return new WP_Error(
				'cb_integrity_invalid_component_baseline',
				__( 'Component type and slug are required before approving a component baseline.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		$eligibility = ResultRepository::baselineComponentEligibility( $latest, $type, $slug );
		if ( empty( $eligibility['found'] ) ) {
			return new WP_Error(
				'cb_integrity_component_not_in_scan',
				__( 'This component is not available as a local-baseline candidate in the latest scan.', 'core-blueprint' ),
				[ 'status' => 404 ]
			);
		}
		if ( empty( $eligibility['eligible'] ) ) {
			return new WP_Error(
				'cb_integrity_component_baseline_incomplete',
				__( 'This component cannot be approved yet because its filesystem snapshot is incomplete. Resolve unreadable files, symlinks, or filesystem errors and scan again first.', 'core-blueprint' ),
				[ 'status' => 409 ]
			);
		}

		$candidate_id = sanitize_key( (string) ( $eligibility['candidate_id'] ?? '' ) );
		if ( '' === $candidate_id || ! BaselineReviewRepository::isReviewed( $latest, $candidate_id ) ) {
			return new WP_Error(
				'cb_integrity_component_review_required',
				__( 'Review this component before approving its local baseline.', 'core-blueprint' ),
				[ 'status' => 409 ]
			);
		}

		$baseline = ResultRepository::saveBaselineComponent( $latest, $type, $slug );
		if ( empty( $baseline['_component_saved'] ) ) {
			return new WP_Error(
				'cb_integrity_component_baseline_not_saved',
				__( 'The component baseline could not be saved from the latest scan.', 'core-blueprint' ),
				[ 'status' => 409 ]
			);
		}
		unset( $baseline['_component_saved'] );
		$this->audit( 'integrity_component_baseline_approved', 'notice', [ 'type' => $type, 'slug' => $slug, 'entry_count' => (int) ( $baseline['entry_count'] ?? 0 ) ] );

		$queued = $this->queue_scan_job( 'component_baseline' );
		$payload = $this->response_payload( $latest );
		$payload['baseline'] = $baseline;
		if ( $queued instanceof WP_Error ) {
			$payload['rescan_queued'] = false;
			$payload['rescan_error']  = $queued->get_error_message();
		} else {
			$payload = $queued + [ 'rescan_queued' => true ] + $payload;
		}

		return new WP_REST_Response( $payload, 200 );
	}


	/** Mark one current-scan baseline candidate as reviewed for this operator. */
	public function mark_baseline_reviewed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$latest = ResultRepository::getLatest();
		if ( ! is_array( $latest ) ) {
			return new WP_Error( 'cb_integrity_no_scan', __( 'Run a scan before reviewing baseline candidates.', 'core-blueprint' ), [ 'status' => 409 ] );
		}

		$candidate_id = sanitize_key( (string) $request->get_param( 'candidate_id' ) );
		if ( '' === $candidate_id || ! BaselineReviewRepository::markReviewed( $latest, $candidate_id ) ) {
			return new WP_Error( 'cb_integrity_invalid_baseline_review', __( 'This baseline candidate is not part of the current scan.', 'core-blueprint' ), [ 'status' => 409 ] );
		}

		$progress = BaselineReviewRepository::progress( $latest );
		$this->audit( 'integrity_baseline_candidate_reviewed', 'notice', [
			'candidate_id' => $candidate_id,
			'scan_id'      => BaselineReviewRepository::scanId( $latest ),
			'reviewed'     => (int) $progress['reviewed'],
			'total'        => (int) $progress['total'],
		] );

		return new WP_REST_Response( [ 'review' => $progress ], 200 );
	}

	/**
	 * Remove an entry from the approved baseline by type+slug.
	 *
	 * Used when a previously-approved component has been intentionally
	 * removed from the site (merged into another plugin, retired,
	 * replaced) - the scanner would otherwise flag every subsequent
	 * scan with a `missing` finding at critical severity. Removing the
	 * baseline entry brings the baseline back in sync with reality.
	 *
	 * No latest-scan requirement: removal is independent of any current
	 * scan-result. The baseline is operator-managed state.
	 *
	 * Re-running the scan after the removal mirrors the behaviour of
	 * approve_component_baseline so the response payload reflects the
	 * cleaned-up findings list.
	 */
	public function remove_component_baseline( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$slug = sanitize_text_field( (string) $request->get_param( 'slug' ) );

		if ( '' === $type || '' === $slug ) {
			return new WP_Error(
				'cb_integrity_invalid_component_baseline',
				__( 'Component type and slug are required before removing a baseline entry.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		$baseline = ResultRepository::removeBaselineComponent( $type, $slug );

		if ( null === $baseline ) {
			return new WP_Error(
				'cb_integrity_no_baseline',
				__( 'No approved baseline exists.', 'core-blueprint' ),
				[ 'status' => 404 ]
			);
		}

		$this->audit( 'integrity_baseline_entry_removed', 'notice', [
			'type'        => $type,
			'slug'        => $slug,
			'entry_count' => (int) ( $baseline['entry_count'] ?? 0 ),
		] );

		$latest = ResultRepository::getLatest();
		$queued = State::is_enabled()
			? $this->queue_scan_job( 'baseline_entry_removed' )
			: null;
		$payload = $this->response_payload( $latest );
		$payload['baseline'] = $baseline;
		if ( $queued instanceof WP_Error ) {
			$payload['rescan_queued'] = false;
			$payload['rescan_error']  = $queued->get_error_message();
		} elseif ( is_array( $queued ) ) {
			$payload = $queued + [ 'rescan_queued' => true ] + $payload;
		} else {
			$payload['rescan_queued'] = false;
		}

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Clear the entire approved baseline.
	 *
	 * Operator-driven reset: every approved entry is dropped from
	 * storage. The stored scan-result is left untouched - the operator
	 * runs a fresh scan after this call (the modal copy spells that
	 * out). Re-running the scan automatically here would defeat the
	 * "start with a clean slate" intent: the immediate auto-scan would
	 * re-flag every component that was previously approved as
	 * `baseline_required`, mixing the reset with implicit work.
	 *
	 * Returns 404 when there is nothing to clear (no baseline exists)
	 * so the UI can distinguish "cleared" from "already empty".
	 */
	public function clear_baseline(): WP_REST_Response|WP_Error {
		$existing = ResultRepository::getBaseline();

		if ( ! is_array( $existing ) ) {
			return new WP_Error(
				'cb_integrity_no_baseline',
				__( 'No approved baseline exists.', 'core-blueprint' ),
				[ 'status' => 404 ]
			);
		}

		$entry_count = (int) ( $existing['entry_count'] ?? 0 );

		ResultRepository::clearBaseline();

		$this->audit( 'integrity_baseline_cleared', 'notice', [
			'entry_count_before' => $entry_count,
		] );

		// Return the current findings payload unchanged - no re-scan,
		// the operator's next "Run Scan" click is the next step. Use
		// the existing latest result so the page stays populated;
		// `response_payload` handles the null-result case if there's
		// nothing on file.
		return new WP_REST_Response( $this->response_payload( ResultRepository::getLatest() ), 200 );
	}

	/**
	 * Run distribution-locale detection and persist the result.
	 *
	 * Operator-triggered alternative to the lazy scan-time detection:
	 * useful after a manual core re-install, after a custom build was
	 * deployed, or when the auto-detection produced an unexpected
	 * result and the operator wants to retry from a known-good state.
	 */
	public function redetect_locale(): WP_REST_Response|WP_Error {
		if ( ! State::is_enabled() ) {
			return $this->subsystem_disabled_response();
		}

		global $wp_version;

		$detection = ( new LocaleDetector() )->detect( (string) $wp_version );

		$integrity = (array) ( Settings::get()['integrity'] ?? [] );
		$now       = current_time( 'mysql' );

		$integrity['distribution_locale_meta'] = [
			'last_detected_at' => $now,
			'tried'            => $detection['tried'],
			'matched_file'     => $detection['matched_file'],
			'cross_check'      => $detection['cross_check'],
		];

		if ( null !== $detection['detected'] && 'failed' !== $detection['cross_check'] ) {
			$integrity['distribution_locale_detected'] = $detection['detected'];
			$integrity['distribution_locale_mode']     = 'auto';
		}

		Settings::set_key( 'integrity', $integrity, 'integrity_redetect' );

		$this->audit( 'integrity_distribution_locale_detected', 'notice', [
			'detected'    => $detection['detected'],
			'reason'      => $detection['reason'],
			'cross_check' => $detection['cross_check'],
			'tried_count' => count( $detection['tried'] ),
		] );

		return new WP_REST_Response( [
			'detection' => $detection,
			'mode'      => $integrity['distribution_locale_mode'] ?? 'fallback',
			'detected'  => $integrity['distribution_locale_detected'] ?? '',
			'meta'      => $integrity['distribution_locale_meta'] ?? [],
		], 200 );
	}

	/**
	 * Update the distribution-locale mode + override.
	 *
	 * Accepts:
	 *   - mode:     'auto' | 'override' | 'fallback'
	 *   - override: locale string (only used when mode === 'override')
	 *
	 * 'auto' requires that detection has previously run successfully
	 * (a non-empty `distribution_locale_detected` value). If detection
	 * has not run, the request is rejected with 400 - the operator
	 * should call `/locale/redetect` first.
	 */
	public function set_locale_mode( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$mode     = sanitize_key( (string) $request->get_param( 'mode' ) );
		$override = sanitize_text_field( (string) $request->get_param( 'override' ) );

		if ( ! in_array( $mode, [ 'auto', 'override', 'fallback' ], true ) ) {
			return new WP_Error(
				'cb_integrity_invalid_locale_mode',
				__( 'Invalid distribution-locale mode.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		$integrity = (array) ( Settings::get()['integrity'] ?? [] );

		if ( 'auto' === $mode && '' === (string) ( $integrity['distribution_locale_detected'] ?? '' ) ) {
			return new WP_Error(
				'cb_integrity_no_detection',
				__( 'Auto mode requires a successful detection first. Run Re-detect before switching to auto.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'override' === $mode && '' === $override ) {
			return new WP_Error(
				'cb_integrity_override_required',
				__( 'Override mode requires a non-empty locale value.', 'core-blueprint' ),
				[ 'status' => 400 ]
			);
		}

		$integrity['distribution_locale_mode']     = $mode;
		$integrity['distribution_locale_override'] = 'override' === $mode ? $override : '';

		Settings::set_key( 'integrity', $integrity, 'integrity_locale_mode' );

		$this->audit( 'integrity_distribution_locale_changed', 'notice', [
			'mode'     => $mode,
			'override' => 'override' === $mode ? $override : '',
		] );

		return new WP_REST_Response( [
			'mode'      => $mode,
			'override'  => $integrity['distribution_locale_override'],
			'detected'  => $integrity['distribution_locale_detected'] ?? '',
			'meta'      => $integrity['distribution_locale_meta']     ?? [],
		], 200 );
	}


	public function quarantine_items(): WP_REST_Response {
		$items = array_map( static fn( array $item ): array => QuarantineService::public_item( $item ), QuarantineRepository::items() );
		return new WP_REST_Response( [ 'items' => $items, 'open_count' => QuarantineRepository::open_count() ], 200 );
	}

	public function quarantine_finding( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$item = QuarantineService::quarantine(
				sanitize_key( (string) $request->get_param( 'finding_id' ) ),
				sanitize_key( (string) $request->get_param( 'scope' ) )
			);
			return new WP_REST_Response( [ 'item' => QuarantineService::public_item( $item ), 'open_count' => QuarantineRepository::open_count() ], 201 );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'cb_integrity_quarantine_failed', $throwable->getMessage(), [ 'status' => 409 ] );
		}
	}

	public function inspect_quarantine( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( QuarantineService::inspect(
				sanitize_key( (string) $request['id'] ),
				sanitize_text_field( (string) $request->get_param( 'file' ) )
			), 200 );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'cb_integrity_quarantine_inspect_failed', $throwable->getMessage(), [ 'status' => 409 ] );
		}
	}

	public function restore_quarantine( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			MutationAcknowledgement::require_confirmed(
				$request->get_param( 'quarantine_restore_acknowledgement' ),
				__( 'Confirm your responsibility for backup and recovery before restoring the quarantined item.', 'core-blueprint' )
			);
		} catch ( \InvalidArgumentException $error ) {
			return new WP_Error( 'cb_integrity_quarantine_restore_confirmation', $error->getMessage(), [ 'status' => 400 ] );
		}
		$id = sanitize_key( (string) $request['id'] );
		$this->audit( 'integrity_quarantine_restore_acknowledged', 'notice', [
			'quarantine_id'          => $id,
			'recovery_responsibility' => true,
		] );
		try {
			$item = QuarantineService::restore( $id );
			return new WP_REST_Response( [ 'item' => QuarantineService::public_item( $item ), 'open_count' => QuarantineRepository::open_count() ], 200 );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'cb_integrity_quarantine_restore_failed', $throwable->getMessage(), [ 'status' => 409 ] );
		}
	}

	public function delete_quarantine( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( 'DELETE' !== (string) $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'cb_integrity_quarantine_delete_confirmation', __( 'Permanent deletion requires the exact DELETE confirmation.', 'core-blueprint' ), [ 'status' => 400 ] );
		}
		try {
			$item = QuarantineService::delete_permanently( sanitize_key( (string) $request['id'] ) );
			return new WP_REST_Response( [ 'item' => QuarantineService::public_item( $item ), 'open_count' => QuarantineRepository::open_count() ], 200 );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'cb_integrity_quarantine_delete_failed', $throwable->getMessage(), [ 'status' => 409 ] );
		}
	}

	public function add_quarantine_note( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$item = QuarantineService::add_note( sanitize_key( (string) $request['id'] ), (string) $request->get_param( 'note' ) );
			return new WP_REST_Response( [ 'item' => QuarantineService::public_item( $item ) ], 200 );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'cb_integrity_quarantine_note_failed', $throwable->getMessage(), [ 'status' => 400 ] );
		}
	}

	public function set_quarantine_state( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$item = QuarantineService::set_review_state( sanitize_key( (string) $request['id'] ), sanitize_key( (string) $request->get_param( 'state' ) ) );
			return new WP_REST_Response( [ 'item' => QuarantineService::public_item( $item ), 'open_count' => QuarantineRepository::open_count() ], 200 );
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'cb_integrity_quarantine_state_failed', $throwable->getMessage(), [ 'status' => 400 ] );
		}
	}

	public function settings( WP_REST_Request $request ): WP_REST_Response {
		$settings = [
			'schedule'         => sanitize_text_field( (string) $request->get_param( 'schedule' ) ),
			'plugin_checksums' => (bool) $request->get_param( 'plugin_checksums' ),
			'theme_checksums'  => (bool) $request->get_param( 'theme_checksums' ),
			'uploads_scan'     => (bool) $request->get_param( 'uploads_scan' ),
		];

		ResultRepository::saveSettings( $settings );
		Cron::sync_schedule();
		$this->audit_settings_changed( ResultRepository::settings() );

		return new WP_REST_Response( [ 'settings' => ResultRepository::settings() ], 200 );
	}

	/**
	 * Create and dispatch one resumable scan job.
	 *
	 * No synchronous web fallback exists by design. A scanner that can inspect
	 * arbitrarily large trees must not quietly collapse back into one unbounded
	 * REST request when cron dispatch is unavailable. Failure to persist the
	 * first continuation is surfaced explicitly and the acquired lock/job state
	 * is cleaned up before returning.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function queue_scan_job( string $source ): array|WP_Error {
		$queued = IntegrityApi::request_scan( $source );
		if ( $queued instanceof WP_Error ) {
			if ( 'cb_integrity_scan_locked' === $queued->get_error_code() ) {
				$data = (array) $queued->get_error_data();
				$data['existing_job_id'] = (string) ( $data['scan_id'] ?? '' );
				unset( $data['scan_id'] );
				$queued->add_data( $data );
			}
			return $queued;
		}

		return [
			'async'      => true,
			'job_id'     => (string) ( $queued['scan_id'] ?? '' ),
			'started_at' => (float) ( $queued['started_at'] ?? 0.0 ),
		];
	}

	/**
	 * Build the standard response payload for any endpoint that
	 * returns scan-result-derived data (scan, clear, baseline ops,
	 * progress completion).
	 *
	 * Accepts null because several call paths legitimately have no
	 * stored latest - for example: `clear_baseline()` runs against
	 * the option without requiring a recent scan; `scan_progress()`
	 * may be called immediately after a clear-results action; first
	 * scan flows have no previous result. Callers used to be required
	 * to defend against null themselves; centralising the fallback
	 * here means any new endpoint that uses this helper inherits the
	 * same robust behaviour.
	 *
	 * When result is null, the formatters all fall back to their
	 * empty-state defaults (idle status, zero counts, no findings).
	 */
	private function response_payload( ?array $result ): array {
		$result = is_array( $result ) ? $result : [];
		$max_visible = (int) ( ResultRepository::settings()['max_visible_findings'] ?? 50 );

		return [
			// Keep mutation responses compact. Full checks are stored in the
			// repository and exposed through the paginated findings endpoint; sending
			// them again here can turn a harmless baseline action into a multi-MB REST
			// response on a heavily modified site.
			'result'        => $this->compact_result( $result ),
			'summary'       => ResultFormatter::summary( $result ),
			'findings'      => ResultFormatter::limited_findings( $result, $max_visible ),
			'groups'        => ResultFormatter::grouped_findings( $result, $max_visible ),
			'passed_groups' => ResultFormatter::grouped_passed( $result, 500 ),
			'diff'          => ResultFormatter::diff_summary( $result ),
			'settings'      => ResultRepository::settings(),
		];
	}


	/** @return array<string,mixed> */
	private function compact_result( array $result ): array {
		unset(
			$result['checks'],
			$result['diff'],
			$result['incident_lifecycle']
		);

		return $result;
	}

	private function audit_failure( string $message ): void {
		$this->audit( 'integrity_scan_failed', 'critical', [ 'message' => $message ] );
	}

	private function audit_settings_changed( array $settings ): void {
		$this->audit( 'integrity_settings_changed', 'notice', [ 'settings' => $settings ] );
	}

	private function audit( string $event_type, string $severity, array $context = [] ): void {
		Audit::log( $event_type, $severity, $context );
	}
}
