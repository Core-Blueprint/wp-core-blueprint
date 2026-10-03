<?php
declare(strict_types=1);
/**
 * Privacy - privacy & logging configuration.
 *
 * No longer registers as a standalone Page - it renders as a tab inside
 * Preferences. This class remains the single source of truth for the
 * rendering logic + the storage-estimate helpers.
 *
 * Central control panel for:
 *   - IP anonymization mode
 *   - Per-category event verbosity
 *   - Per-category retention
 *   - Governance presets (Minimal / Standard / Enhanced / Strict)
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Admin\Pages;

use CoreBlueprint\Core\Privacy\Anonymizer;
use CoreBlueprint\Core\Privacy\Presets;
use CoreBlueprint\Core\Log\Verbosity;
use CoreBlueprint\Core\Governance\RetentionPolicy;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	/**
	 * Render the Privacy tab body (captured in an output-buffer by the
	 * caller if it needs to wrap tab navigation around the output).
	 *
	 * Caller is responsible for capability-guarding before invoking.
	 */
	public static function render_body(): void {
		if ( ! class_exists( Anonymizer::class ) || ! class_exists( Verbosity::class ) ) {
			echo '<div class="wrap"><p>';
			esc_html_e( 'Privacy subsystem not loaded.', 'core-blueprint' );
			echo '</p></div>';
			return;
		}

		// Resolve current state for the template.
		$current_ip_mode        = Anonymizer::ip_mode();
		$current_verbosity      = Verbosity::all_levels();
		$current_retention      = RetentionPolicy::all();
		$active_preset          = Presets::active();
		$preset_definitions     = Presets::definitions();
		$preset_actually_matches = Presets::PRESET_CUSTOM !== $active_preset
			&& Presets::matches( $active_preset );

		// Verbosity categories to render in the table - order matters.
		$verbosity_categories = [
			'logins'   => __( 'Login events',        'core-blueprint' ),
			'users'    => __( 'User management',     'core-blueprint' ),
			'plugins'  => __( 'Plugin lifecycle',    'core-blueprint' ),
			'themes'   => __( 'Theme lifecycle',     'core-blueprint' ),
			'core'     => __( 'WordPress core',      'core-blueprint' ),
			'settings' => __( 'Settings changes',    'core-blueprint' ),
		];

		// Retention categories.
		$retention_categories = [
			'security'    => __( 'Security events',    'core-blueprint' ),
			'maintenance' => __( 'Maintenance events', 'core-blueprint' ),
			'logins'      => __( 'Login events',       'core-blueprint' ),
			'settings'    => __( 'Settings changes',   'core-blueprint' ),
			'general'     => __( 'General events',     'core-blueprint' ),
		];

		// Retention options (days → human label).
		$retention_options = [
			30   => __( '30 days',  'core-blueprint' ),
			60   => __( '60 days',  'core-blueprint' ),
			90   => __( '90 days',  'core-blueprint' ),
			180  => __( '6 months', 'core-blueprint' ),
			365  => __( '1 year',   'core-blueprint' ),
			730  => __( '2 years',  'core-blueprint' ),
			1095 => __( '3 years',  'core-blueprint' ),
		];

		// Storage estimate for the current configuration.
		$estimate_kb_per_year = self::estimate_storage_kb_per_year(
			$current_verbosity,
			$current_retention
		);

		include CB_CORE_DIR . 'templates/privacy.php';
	}

	/**
	 * Rough storage-estimate in KB/year based on typical event volume
	 * and current verbosity + retention. This is a coarse projection -
	 * good enough to show the user "your config ≈ X MB/yr" in the UI.
	 *
	 * Baseline numbers come from medium-site observations. Sites with
	 * heavier traffic will see proportionally larger storage; we prefer
	 * under-estimating slightly to over-estimating (users dislike being
	 * told their config is bigger than it turns out to be).
	 */
	private static function estimate_storage_kb_per_year( array $verbosity, array $retention ): int {
		// Typical events-per-year, per category, at "always log" level.
		$events_per_year_baseline = [
			'logins'      => 1500,   // 4-5 admin logins per day
			'plugins'     => 60,
			'themes'      => 15,
			'core'        => 10,
			'users'       => 30,
			'settings'    => 50,
			'security'    => 25,
			'maintenance' => 85,     // plugins + themes + core aggregate
			'general'     => 250,
		];

		// Average bytes per row (measured on real data).
		$bytes_per_row = 500;

		// Per-verbosity multiplier: admins_only ~0.7 (skips editor logins),
		// critical_only ~0.1 (only rare events), disabled = 0.
		$verbosity_multiplier = [
			Verbosity::LEVEL_ALWAYS        => 1.0,
			Verbosity::LEVEL_ADMINS_ONLY   => 0.7,
			Verbosity::LEVEL_CRITICAL_ONLY => 0.1,
			Verbosity::LEVEL_DISABLED      => 0,
		];

		$total_bytes = 0;

		// Categories that have a user-configurable verbosity control.
		foreach ( [ 'logins', 'users', 'plugins', 'themes', 'core', 'settings' ] as $cat ) {
			$v_level      = $verbosity[ $cat ] ?? Verbosity::LEVEL_ALWAYS;
			$v_mult       = $verbosity_multiplier[ $v_level ] ?? 1.0;
			$retention_cat = self::retention_category_for_verbosity( $cat );
			$r_days       = (int) ( $retention[ $retention_cat ] ?? 365 );
			$r_mult       = $r_days / 365.0;

			$count = ( $events_per_year_baseline[ $cat ] ?? 0 ) * $v_mult * $r_mult;
			$total_bytes += $count * $bytes_per_row;
		}

		// Security + general don't have a dedicated verbosity control but
		// do have AuditLog retention.
		foreach ( [ 'security', 'general' ] as $cat ) {
			$r_days  = (int) ( $retention[ $cat ] ?? 365 );
			$r_mult  = $r_days / 365.0;
			$count   = ( $events_per_year_baseline[ $cat ] ?? 0 ) * $r_mult;
			$total_bytes += $count * $bytes_per_row;
		}

		return (int) round( $total_bytes / 1024 );
	}

	/**
	 * Map verbosity-category → retention-category. Core's retention
	 * grouping is coarser than verbosity; several verbosity categories
	 * share a retention slot.
	 */
	private static function retention_category_for_verbosity( string $cat ): string {
		$event = match ( $cat ) {
			'logins'   => 'system.login',
			'settings' => 'system.option.changed',
			'plugins'  => 'system.plugin.updated',
			'themes'   => 'system.theme.updated',
			'core'     => 'system.core.updated',
			'users'    => 'system.user.updated',
			default    => 'general.event',
		};
		return RetentionPolicy::category_for_event( $event );
	}
}
