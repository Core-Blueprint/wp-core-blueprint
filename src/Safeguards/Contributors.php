<?php
declare(strict_types=1);
/**
 * Contributors - canonical Base safeguard status providers.
 *
 * One static method per status ID translates subsystem state into the
 * canonical Modules\Status result contract:
 *
 *   [ 'state' => 'ok|warn|err|off', 'detail' => '...', 'url' => '...' ]
 *
 * All methods are pure: they read from the relevant subsystem's public API
 * (Pairing::is_active(), AccessMode::current(), etc.) and never write
 * state. They are safe to call on every dashboard pageload.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Safeguards;

use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\Security\AccessMode;
use CoreBlueprint\Core\Security\Failsafe;
use CoreBlueprint\Core\Security\LoginShield;
use CoreBlueprint\Core\Security\ModuleRegistry;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Contributors {


	// ─── Access Mode ──────────────────────────────────────────────────────
	//
	// State semantics:
	//   ok   - Public mode. The website is reachable, so the dashboard uses
	//          the normal healthy/available green state.
	//   warn - Coming Soon, Maintenance, or Admin-Only is active. These are
	//          intentional restrictions, not errors; amber makes the reduced
	//          public availability immediately visible in the dashboard cockpit.

	public static function access_mode(): array {
		$mode = class_exists( AccessMode::class ) ? AccessMode::current() : 'public';
		return [
			'state'  => 'public' === $mode ? 'ok' : 'warn',
			'detail' => class_exists( AccessMode::class ) ? AccessMode::mode_label( $mode ) : __( 'Public', 'core-blueprint' ),
			'url'    => admin_url( 'admin.php?page=core-blueprint-safeguards&tab=access-mode' ),
		];
	}

	// ─── Login Shield ─────────────────────────────────────────────────────

	public static function login_shield(): array {
		$url = admin_url( 'admin.php?page=core-blueprint-safeguards&tab=login-shield' );
		if ( ! class_exists( LoginShield::class ) ) {
			return [ 'state' => 'off', 'detail' => __( 'Disabled', 'core-blueprint' ), 'url' => $url ];
		}

		$config = LoginShield::config();
		if ( empty( $config['enabled'] ) ) {
			return [ 'state' => 'off', 'detail' => __( 'Disabled', 'core-blueprint' ), 'url' => $url ];
		}
		if ( '' === (string) ( $config['slug'] ?? '' ) ) {
			return [ 'state' => 'warn', 'detail' => __( 'Configuration required', 'core-blueprint' ), 'url' => $url ];
		}
		if ( class_exists( Settings::class ) && ! Settings::shield_enabled() ) {
			return [ 'state' => 'warn', 'detail' => __( 'Standing by - Core Shield is off', 'core-blueprint' ), 'url' => $url ];
		}
		if ( class_exists( Failsafe::class ) && Failsafe::is_bypassed() ) {
			return [ 'state' => 'warn', 'detail' => __( 'Standing by - Failsafe bypass active', 'core-blueprint' ), 'url' => $url ];
		}

		$mode_label = self::login_shield_mode_label( (string) ( $config['mode'] ?? '' ) );
		$slug       = (string) ( $config['slug'] ?? '' );
		$detail     = sprintf( '%s · /%s/', $mode_label, $slug );
		return [ 'state' => LoginShield::is_enforcing() ? 'ok' : 'warn', 'detail' => $detail, 'url' => $url ];
	}

	private static function login_shield_mode_label( string $mode ): string {
		// Built-in modes are 'standard', 'strict', 'paranoid' - mirror
		// LoginShield's MODE_* constants without hard-coupling, and fall back
		// to a generic capitalised label for any custom mode.
		switch ( $mode ) {
			case 'standard':  return __( 'Standard', 'core-blueprint' );
			case 'strict':    return __( 'Strict',   'core-blueprint' );
			case 'paranoid':  return __( 'Paranoid', 'core-blueprint' );
			default:          return ucfirst( $mode );
		}
	}

	// ─── Core Shield ──────────────────────────────────────────────────────
	//
	// Aggregate status of the master switch + registered hardening modules.
	// "Active modules" is the count where the registry exposes an enabled
	// flag in cb_core_settings.modules.{slug}.enabled - same store used by
	// is_feature_enabled() upstream.

	public static function core_shield(): array {
		$shield_on = class_exists( Settings::class ) ? Settings::shield_enabled() : false;
		$url       = admin_url( 'admin.php?page=core-blueprint-safeguards&tab=core-shield' );

		// Privileged Access Guard is independent of the Shield master switch.
		// Surface pending privileged reviews before module state so the
		// dashboard never presents a green Shield tile during an access incident.
		if ( class_exists( '\CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry' ) ) {
			$review_required = count( \CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry::review_snapshot() );
			if ( $review_required > 0 ) {
				$enforcing = \CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy::enforces_approval();
				$detail = sprintf(
					/* translators: 1: number of privileged identities requiring review, 2: current protection state */
					_n( '%1$d privileged user requires review · %2$s', '%1$d privileged users require review · %2$s', $review_required, 'core-blueprint' ),
					$review_required,
					$enforcing ? __( 'access restricted', 'core-blueprint' ) : __( 'monitor only', 'core-blueprint' )
				);
				return [ 'state' => 'warn', 'detail' => $detail, 'url' => $url ];
			}
		}

		if ( ! $shield_on ) {
			// An intentionally disabled Shield is not an error, but it does mean
			// the site's hardening layer is not currently enforcing. Surface that
			// as an amber attention state instead of the neutral/off grey state.
			return [
				'state'  => 'warn',
				'detail' => __( 'Master switch off', 'core-blueprint' ),
				'url'    => $url,
			];
		}

		$total   = 0;
		$enabled = 0;
		if ( class_exists( ModuleRegistry::class ) ) {
			$total    = ModuleRegistry::count();
			$enabled  = self::count_enabled_modules();
		}

		if ( 0 === $total ) {
			// Defensive: shield is on but no modules registered. Surfaces
			// any bootstrap-order regression to the operator without
			// crashing the dashboard.
			return [
				'state'  => 'warn',
				'detail' => __( 'No hardening modules registered', 'core-blueprint' ),
				'url'    => $url,
			];
		}

		$detail = sprintf(
			/* translators: 1: number of enabled modules, 2: total modules */
			_n(
				'%1$d of %2$d hardening module active',
				'%1$d of %2$d hardening modules active',
				$total,
				'core-blueprint'
			),
			$enabled,
			$total
		);

		return [
			'state'  => $enabled > 0 ? 'ok' : 'warn',
			'detail' => $detail,
			'url'    => $url,
		];
	}

	/**
	 * Count enabled hardening modules. Reads the same option-shape the
	 * registry uses - see ModuleRegistry::is_feature_enabled() upstream.
	 */
	private static function count_enabled_modules(): int {
		$settings = get_option( CB_CORE_SETTINGS, [] );
		$modules  = is_array( $settings['modules'] ?? null ) ? $settings['modules'] : [];

		$count = 0;
		foreach ( ModuleRegistry::all() as $module ) {
			$slug = $module->slug();
			if ( ! empty( $modules[ $slug ]['enabled'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	// ─── Core Scanner ─────────────────────────────────────────────────────

	public static function core_scanner(): array {
		$url = admin_url( 'admin.php?page=core-blueprint-safeguards&tab=core-scanner' );

		if ( class_exists( '\CoreBlueprint\Core\Integrity\State' ) && ! \CoreBlueprint\Core\Integrity\State::is_enabled() ) {
			return [ 'state' => 'off', 'detail' => __( 'Disabled', 'core-blueprint' ), 'url' => $url ];
		}

		if ( ! class_exists( ResultRepository::class ) || ! ResultRepository::hasResult() ) {
			// Scanner activation and scan readiness are separate concerns. An
			// enabled scanner with no result yet is active/healthy; `Not yet run`
			// is readiness detail, not an inactive module state.
			return [
				'state'  => 'ok',
				'detail' => __( 'Not yet run', 'core-blueprint' ),
				'url'    => $url,
			];
		}

		$summary    = ResultRepository::getSummary();
		$findings   = is_array( $summary['summary'] ?? null ) ? $summary['summary'] : [];
		$critical   = (int) ( $findings['critical'] ?? 0 );
		$warnings   = (int) ( $findings['warning']  ?? 0 );
		$last_scan  = (string) ( $summary['last_scan'] ?? '' );

		$age_label = self::relative_time_label( $last_scan );

		if ( $critical > 0 ) {
			$detail = sprintf(
				/* translators: %d: number of critical findings */
				_n( '%d critical finding', '%d critical findings', $critical, 'core-blueprint' ),
				$critical
			);
			return [ 'state' => 'err', 'detail' => $detail, 'url' => $url ];
		}

		if ( $warnings > 0 ) {
			$detail = sprintf(
				/* translators: %d: number of warning findings */
				_n( '%d warning', '%d warnings', $warnings, 'core-blueprint' ),
				$warnings
			);
			return [ 'state' => 'warn', 'detail' => $detail, 'url' => $url ];
		}

		// All clean.
		$detail = '' !== $age_label
			? sprintf(
				/* translators: %s: relative time phrase, e.g. "2 hours ago" */
				__( 'Clean · last scan %s', 'core-blueprint' ),
				$age_label
			)
			: __( 'Clean', 'core-blueprint' );

		return [ 'state' => 'ok', 'detail' => $detail, 'url' => $url ];
	}

	// ─── Failsafe ─────────────────────────────────────────────────────────

	public static function failsafe(): array {
		$url = admin_url( 'admin.php?page=core-blueprint-safeguards&tab=failsafe' );

		if ( ! class_exists( Failsafe::class ) ) {
			// Pathological - Failsafe boots first per design - but the
			// dashboard must not crash if someone has Failsafe.php missing.
			return [ 'state' => 'err', 'detail' => __( 'Failsafe missing', 'core-blueprint' ), 'url' => $url ];
		}

		// Bypass active is itself a noteworthy state - the operator should
		// see it on the dashboard without drilling in.
		if ( Failsafe::is_bypassed() ) {
			return [
				'state'  => 'warn',
				'detail' => __( 'Bypass active - restrictions paused', 'core-blueprint' ),
				'url'    => $url,
			];
		}

		// Self-test: aggregate ok/fail across the four checks.
		$results = Failsafe::self_test();
		$failed  = 0;
		foreach ( $results as $check ) {
			if ( empty( $check['ok'] ) ) {
				$failed++;
			}
		}

		if ( $failed > 0 ) {
			$detail = sprintf(
				/* translators: %d: number of failed self-test checks */
				_n( '%d self-test check failed', '%d self-test checks failed', $failed, 'core-blueprint' ),
				$failed
			);
			return [ 'state' => 'err', 'detail' => $detail, 'url' => $url ];
		}

		return [
			'state'  => 'ok',
			'detail' => __( 'Bypass-token armed', 'core-blueprint' ),
			'url'    => $url,
		];
	}

	// ─── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Convert a MySQL-format timestamp into a friendly relative-time phrase.
	 * Mirrors the human_age helper from Beacon's StatusTile so the two
	 * surfaces feel consistent. Returns '' when the timestamp is missing
	 * or unparseable.
	 */
	private static function relative_time_label( string $mysql_ts ): string {
		if ( '' === $mysql_ts ) {
			return '';
		}

		$ts = strtotime( $mysql_ts );
		if ( false === $ts || $ts <= 0 ) {
			return '';
		}

		$seconds = time() - $ts;
		if ( $seconds < 0 ) {
			return '';
		}

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'just now', 'core-blueprint' );
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			$m = max( 1, (int) round( $seconds / MINUTE_IN_SECONDS ) );
			return sprintf(
				/* translators: %d: minutes */
				_n( '%d minute ago', '%d minutes ago', $m, 'core-blueprint' ),
				$m
			);
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			$h = max( 1, (int) round( $seconds / HOUR_IN_SECONDS ) );
			return sprintf(
				/* translators: %d: hours */
				_n( '%d hour ago', '%d hours ago', $h, 'core-blueprint' ),
				$h
			);
		}
		$d = max( 1, (int) round( $seconds / DAY_IN_SECONDS ) );
		return sprintf(
			/* translators: %d: days */
			_n( '%d day ago', '%d days ago', $d, 'core-blueprint' ),
			$d
		);
	}
}
