<?php
declare(strict_types=1);
/**
 * State - subsystem master switch for Core Scanner.
 *
 * Single source of truth for whether the Core Scanner subsystem is
 * active. Read by the cron handler, the scan-starting REST routes,
 * and the admin page; written by the master-switch toggle endpoint.
 *
 * The `enabled` flag lives inside the existing `cb_core_settings['integrity']`
 * subkey alongside the per-feature toggles (plugin_checksums,
 * theme_checksums, uploads_scan, schedule). This keeps everything
 * Core-Scanner-related in one place. {@see ResultRepository::settings()}
 * merges the canonical defaults with stored settings so callers always receive
 * the complete public settings shape.
 *
 * The helper exists to centralise three concerns that would otherwise
 * leak into every caller: (1) reading the bool with a sane default,
 * (2) emitting an audit-log event on transitions, (3) syncing or
 * clearing the cron schedule when toggling. None of those should be
 * a caller's responsibility.
 *
 * Suite philosophy reminder: every CB subsystem should be deactivatable.
 * Operators may already have their own integrity scanner (Wordfence,
 * a custom solution) and Core Blueprint must not force itself on
 * them. Disabling here means: cron stops scheduling, scan-starting
 * REST routes return 403, the "Run Core Scanner" affordance disappears
 * - but baseline, history, and settings stay intact so toggling back
 * on resumes the previous configuration without data loss.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Integrity;

use CoreBlueprint\Core\Integrity\Scheduler\Cron;
use CoreBlueprint\Core\Integrity\Scanner\ScanJobRunner;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Integrity\Support\Audit;

use CoreBlueprint\Core\Modules\ModuleStateInterface;
defined( 'ABSPATH' ) || exit;

final class State implements ModuleStateInterface {

	/**
	 * Whether the Core Scanner subsystem is currently active.
	 *
	 * Missing state is intentionally disabled for the public v1 contract.
	 */
	public static function is_enabled(): bool {
		$settings = ResultRepository::settings();
		return (bool) ( $settings['enabled'] ?? false );
	}

	/**
	 * Persist the master state and synchronise the cron schedule.
	 *
	 * Disabling clears any pending scheduled scan so cron doesn't fire
	 * and immediately no-op every day. Re-enabling re-syncs from the
	 * stored `schedule` preference, which preserves whatever cadence
	 * the operator configured before disabling.
	 *
	 * Audit-logs the transition once; idempotent calls (setting the
	 * state to its current value) return early without logging.
	 *
	 * @param bool   $enabled Target state.
	 * @param string $actor   Identifier persisted into the audit-log row
	 *                        (e.g. "admin:chris" or "rest:hub"). Defaults
	 *                        to "unknown" for callers that don't know.
	 */
	public static function set_enabled( bool $enabled, string $actor = 'unknown' ): void {
		$was = self::is_enabled();
		if ( $was === $enabled ) {
			return;
		}

		ResultRepository::saveSettings( [ 'enabled' => $enabled ] );

		// WordPress option writes are not a transaction boundary. Re-read the
		// canonical state before emitting a transition audit or touching runtime
		// side effects so a refused/failed write cannot masquerade as success.
		if ( self::is_enabled() !== $enabled ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new \RuntimeException( __( 'Core Scanner state could not be persisted.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( class_exists( Audit::class ) ) {
			Audit::log(
				$enabled ? 'integrity_subsystem_enabled' : 'integrity_subsystem_disabled',
				'notice',
				[ 'actor' => $actor ]
			);
		}

		if ( $enabled ) {
			Cron::sync_schedule();
		} else {
			Cron::clear_schedule();
			ScanJobRunner::cancel_active( __( 'Core Scanner was disabled - the active scan was cancelled.', 'core-blueprint' ) );
		}
	}
}
