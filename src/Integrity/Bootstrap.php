<?php
declare(strict_types=1);
/**
 * Bootstrap - wires the Core Scanner subsystem into Core Blueprint.
 *
 * Called from {@see \CB\Core\Core::init()} alongside Reports, Notes,
 * and Permissions bootstraps. Registers:
 *
 *   - REST routes and generic external-scan controller methods; authenticated
 *     remote mirrors are extension-owned and register their own routes
 *   - Cron handler for the optional scheduled scan
 *   - Audit-log event metadata via the canonical Governance EventRegistry
 *
 * Asset enqueue (script module + stylesheet) is registered in the central
 * {@see \CB\Core\Admin\Admin::enqueue_assets()} alongside every other CB
 * Base module - keeps a single enqueue surface, single place to read
 * which modules ship.
 *
 * The scanner UI itself renders as a tab inside the Safeguards page -
 * see {@see \CB\Core\Admin\Pages\Safeguards::render_core_scanner_tab()}.
 * No separate top-level page is registered.
 *
 * Lifecycle (cron schedule sync, install seed) is owned by
 * {@see \CB\Core\Core::activate()} and {@see \CB\Core\Core::deactivate()}.
 *
 * Scanner policy is surfaced through
 * {@see \CB\Core\Core::register_builtin_modules()} alongside Fingerprint
 * and Headers - directly, not via the cb_core_modules filter, since
 * Core owns its own built-ins.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Integrity;

use CB\Core\Integrity\Rest\ScanController;
use CB\Core\Integrity\Scheduler\Cron;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {

	/**
	 * Cron hook fired by resumable Scanner continuations. Manual, Hub/API and
	 * scheduled jobs all arrive here after a persisted job has already been
	 * created; the callback never creates jobs on its own.
	 *
	 * Defined as a constant so REST and Bootstrap reference the same
	 * action name.
	 */
	public const ASYNC_SCAN_HOOK = 'cb_core_integrity_run_scan_async';

	/**
	 * Register all Core Scanner hooks. Called once from
	 * {@see \CB\Core\Core::init()} during plugin load.
	 */
	public static function boot(): void {
		add_action( 'rest_api_init',           [ ScanController::class, 'register_routes' ] );
		add_action( Cron::HOOK,                 [ Cron::class,           'run_scheduled_scan' ] );
		add_action( self::ASYNC_SCAN_HOOK,      [ __CLASS__,             'run_async_scan' ], 10, 2 );
		add_action( 'init',                    [ __CLASS__,             'register_i18n_filters' ], 1 );

		// HUD quick-action. The canonical activation registry drops this item
		// when Core Scanner is disabled.
		add_action( 'cb_hud_register_items', [ __CLASS__, 'register_hud_quick_action' ] );
	}

	/**
	 * Register the "Run integrity scan" verb-action in the HUD's
	 * quick-actions section. Links to the Core Scanner tab with a
	 * query arg the page uses to trigger an async scan.
	 *
	 * Capability gates on operator-managing-integrity; module-gated so
	 * the action drops out when Core Scanner is disabled.
	 *
	 * @param string $registry HUD Registry class name (passed by the action).
	 *
	 * @since   1.0.0
	 */
	public static function register_hud_quick_action( string $registry ): void {
		if ( ! current_user_can( 'cb_manage_integrity' ) ) {
			return;
		}
		if ( ! class_exists( $registry ) ) {
			return;
		}

		$registry::add_item( [
			'id'         => 'cb-hud-quick-run-scan',
			// HUD label uses the user-facing "Core Scan" name. The
			// internal subsystem stays "Integrity" everywhere else
			// (audit events, REST routes, code) — only the menu surface
			// uses the friendlier brand-side terminology.
			'label'      => __( 'Run Core Scan', 'core-blueprint' ),
			'section'    => 'quick-actions',
			'url'        => admin_url( 'admin.php?page=core-blueprint-safeguards&tab=core-scanner&cb_action=run' ),
			'order'      => 30,
			'capability' => 'cb_manage_integrity',
			'icon'       => 'controls-play',
			'module'     => 'core-scanner',
		] );
	}

	/**
	 * Async-scan cron handler.
	 *
	 * The persisted job is authoritative; stale callbacks with no matching job
	 * are inert. The outer exception boundary handles failures that occur before
	 * ScanJobRunner's own slice-level handler can persist an error state.
	 *
	 * @param string $job_id             Persisted resumable job identifier.
	 * @param int    $started_by_user_id Original operator id from the cron args.
	 */
	public static function run_async_scan( string $job_id, int $started_by_user_id = 0 ): void {
		$job = \CB\Core\Integrity\Scanner\ScanJobRepository::get_by_id( $job_id );

		// A continuation event is only valid while its persisted resumable job
		// exists. Never recreate a job from a stale cron callback: cancelled,
		// completed or superseded events must be inert.
		if ( null === $job ) {
			\CB\Core\Integrity\Scanner\TransientProgressReporter::clear( $job_id );
			return;
		}

		if ( ! \CB\Core\Integrity\State::is_enabled() ) {
			\CB\Core\Integrity\Scanner\ScanJobRunner::cancel(
				$job_id,
				__( 'Core Scanner is disabled - scan cancelled.', 'core-blueprint' )
			);
			return;
		}

		try {
			\CB\Core\Integrity\Scanner\ScanJobRunner::run_slice( $job_id );
		} catch ( \Throwable $throwable ) {
			// run_slice() handles scanner exceptions itself, but keep this outer
			// boundary for persistence/runtime failures before that handler can run.
			\CB\Core\Integrity\Scanner\ScanJobRunner::cancel( $job_id, $throwable->getMessage() );
			\CB\Core\Integrity\Support\Audit::log(
				'integrity_scan_failed',
				'critical',
				[
					'source' => (string) ( $job['source'] ?? 'unknown' ),
					'job_id' => $job_id,
					'error'  => $throwable->getMessage(),
				]
			);
		}
	}

	/** Register translation-bearing metadata filters after the textdomain is loaded. */
	public static function register_i18n_filters(): void {
		\CB\Core\Governance\EventRegistry::register_core_many( self::register_event_labels( [] ) );
	}

	/**
	 * Contribute Core Scanner event labels to the central audit-log
	 * label registry. Labels are plain strings - {@see EmailAlerts}
	 * calls {@see AuditLog::event_label()} with a strict string return
	 * type; Plain/Technical descriptions are surfaced elsewhere.
	 *
	 * @param array<string,string> $labels Existing labels.
	 *
	 * @return array<string,string>
	 */
	public static function register_event_labels( array $labels ): array {
		$labels['integrity.subsystem.enabled']             = __( 'Core Scanner: subsystem enabled',             'core-blueprint' );
		$labels['integrity.subsystem.disabled']            = __( 'Core Scanner: subsystem disabled',            'core-blueprint' );
		$labels['integrity.scan.started']                  = __( 'Core Scanner: scan started',                  'core-blueprint' );
		$labels['integrity.scan.completed']                = __( 'Core Scanner: scan completed',                'core-blueprint' );
		$labels['integrity.scan.failed']                   = __( 'Core Scanner: scan failed',                   'core-blueprint' );
		$labels['integrity.scan.skipped.locked']            = __( 'Core Scanner: scan skipped because another scan is running', 'core-blueprint' );
		$labels['integrity.scan.critical.anomalies.detected'] = __( 'Core Scanner: new or changed critical anomalies detected', 'core-blueprint' );
		$labels['integrity.scan.warning.anomalies.detected']  = __( 'Core Scanner: new or changed anomalies detected', 'core-blueprint' );
		$labels['integrity.scan.anomalies.resolved']        = __( 'Core Scanner: anomalies resolved', 'core-blueprint' );
		$labels['integrity.scan.anomaly.resolution.unconfirmed'] = __( 'Core Scanner: anomaly resolution could not be confirmed', 'core-blueprint' );
		$labels['integrity.settings.changed']              = __( 'Core Scanner: settings changed',              'core-blueprint' );
		$labels['integrity.results.cleared']               = __( 'Core Scanner: results cleared',               'core-blueprint' );
		$labels['integrity.api.scan.requested']            = __( 'Core Scanner: API scan requested',            'core-blueprint' );
		$labels['integrity.baseline.entry.removed']        = __( 'Core Scanner: baseline entry removed',        'core-blueprint' );
		$labels['integrity.baseline.cleared']              = __( 'Core Scanner: approved baseline cleared',     'core-blueprint' );
		$labels['integrity.distribution.locale.detected']  = __( 'Core Scanner: distribution locale detected',  'core-blueprint' );
		$labels['integrity.distribution.locale.changed']   = __( 'Core Scanner: distribution locale changed',   'core-blueprint' );
		$labels['integrity.quarantine.item.quarantined']     = __( 'Core Scanner: item quarantined', 'core-blueprint' );
		$labels['integrity.quarantine.item.restored']        = __( 'Core Scanner: quarantine item restored', 'core-blueprint' );
		$labels['integrity.quarantine.restore.acknowledged']   = __( 'Core Scanner: quarantine restore backup and recovery responsibility acknowledged', 'core-blueprint' );
		$labels['integrity.quarantine.item.deleted']         = __( 'Core Scanner: quarantine payload permanently deleted', 'core-blueprint' );
		$labels['integrity.quarantine.note.added']           = __( 'Core Scanner: quarantine note added', 'core-blueprint' );
		$labels['integrity.quarantine.review.state.changed'] = __( 'Core Scanner: quarantine review state changed', 'core-blueprint' );
		$labels['integrity.quarantine.restore.failed']       = __( 'Core Scanner: quarantine restore failed', 'core-blueprint' );
		$labels['integrity.quarantine.delete.failed']        = __( 'Core Scanner: quarantine delete failed', 'core-blueprint' );
		$labels['integrity.quarantine.restore.state.failed'] = __( 'Core Scanner: quarantine restore state could not be recorded', 'core-blueprint' );
		$labels['integrity.quarantine.delete.state.failed']  = __( 'Core Scanner: quarantine delete state could not be recorded', 'core-blueprint' );
		$labels['integrity.quarantine.rollback.failed']      = __( 'Core Scanner: quarantine rollback failed', 'core-blueprint' );

		return $labels;
	}
}
