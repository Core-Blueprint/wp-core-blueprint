<?php
declare(strict_types=1);
/**
 * TimeFilter - shared period-filter helper.
 *
 * Used by Audit Log, System Log, Connection Log, and Maintenance Report
 * to offer a consistent period dropdown across all audit views. Five
 * presets cover the common client-transparency needs: today, last week,
 * last month, last quarter, and all time.
 *
 * Provides:
 *   - PRESETS - the canonical slug → label map, translatable
 *   - sanitize()      - clamp arbitrary $_GET input to a known preset
 *   - since_timestamp()  - preset → Unix timestamp (0 when no filter)
 *   - since_mysql()      - preset → UTC 'Y-m-d H:i:s' (empty when no filter)
 *
 * Timestamps are calculated in the site's timezone for 'today' (so
 * "events from today" makes sense to the client), but output as UTC
 * MySQL strings to match the storage convention of the audit tables.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Log;

defined( 'ABSPATH' ) || exit;

class TimeFilter {

	/**
	 * Canonical preset list. Keys are URL-safe slugs; values are the
	 * English labels. Use label() to get a translated label.
	 */
	const PRESETS = [
		'all'   => 'All time',
		'today' => 'Today',
		'7d'    => 'Last 7 days',
		'30d'   => 'Last 30 days',
		'90d'   => 'Last 90 days',
	];

	/**
	 * Default preset when nothing is selected or the value is unknown.
	 * Kept at 'all' so existing deep-links without a period param keep
	 * showing everything.
	 */
	const DEFAULT_PRESET = 'all';

	/**
	 * Clamp an arbitrary input value to a known preset slug. Unknown
	 * values fall back to the default.
	 */
	public static function sanitize( string $preset ): string {
		$preset = strtolower( trim( $preset ) );
		return isset( self::PRESETS[ $preset ] ) ? $preset : self::DEFAULT_PRESET;
	}

	/**
	 * Translated label for a preset. Falls back to the raw label if
	 * the slug is unknown.
	 */
	public static function label( string $preset ): string {
		$raw = self::PRESETS[ $preset ] ?? $preset;
		// Each label needs an explicit __() call for xgettext to pick
		// it up. register_translatable_strings() below handles this.
		$translations = [
			'All time'      => __( 'All time',      'core-blueprint' ),
			'Today'         => __( 'Today',         'core-blueprint' ),
			'Last 7 days'   => __( 'Last 7 days',   'core-blueprint' ),
			'Last 30 days'  => __( 'Last 30 days',  'core-blueprint' ),
			'Last 90 days'  => __( 'Last 90 days',  'core-blueprint' ),
		];
		return $translations[ $raw ] ?? $raw;
	}

	/**
	 * Convert a preset to a Unix timestamp - the "since" boundary.
	 * Returns 0 for 'all', meaning "no lower bound".
	 *
	 * 'today' starts at midnight in the site's local timezone so the
	 * client sees "events from today" as they naturally expect it,
	 * regardless of whether the DB stores UTC.
	 */
	public static function since_timestamp( string $preset ): int {
		$preset = self::sanitize( $preset );
		switch ( $preset ) {
			case 'today':
				// Midnight in the site's local timezone.
				return (int) strtotime( 'midnight today' );
			case '7d':
				return (int) strtotime( '-7 days' );
			case '30d':
				return (int) strtotime( '-30 days' );
			case '90d':
				return (int) strtotime( '-90 days' );
			case 'all':
			default:
				return 0;
		}
	}

	/**
	 * Convert a preset to a UTC MySQL datetime string suitable for
	 * `created_at >= %s` comparisons against audit/connection tables
	 * (which store UTC via current_time('mysql', true)).
	 * Returns empty string for 'all' - caller should skip the filter.
	 */
	public static function since_mysql( string $preset ): string {
		$ts = self::since_timestamp( $preset );
		return $ts > 0 ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}
}
