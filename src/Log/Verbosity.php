<?php
declare(strict_types=1);
/**
 * Verbosity - per-category event filter.
 *
 * Determines whether a candidate audit event should actually be written,
 * based on the event's category and the site's verbosity configuration.
 *
 * Verbosity levels (per category):
 *   - 'always'         - log every event in this category
 *   - 'admins_only'    - only when the current user has manage_options
 *   - 'critical_only'  - only if severity >= warning
 *   - 'disabled'       - never log
 *
 * Categories are inferred from the event slug prefix:
 *   'system_plugin_*'  → 'plugins'
 *   'system_theme_*'   → 'themes'
 *   'system_core_*'    → 'core'
 *   'system_user_*'    → 'users'
 *   'system_login'     → 'logins'
 *   'system_option_*'  → 'settings'
 *   'system_foundation_*' → 'core'
 *   (anything else)    → 'other'  (always logged - safety net)
 *
 * Storage: wp_options key 'cb_core_verbosity', keyed by category.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Log;

defined( 'ABSPATH' ) || exit;

final class Verbosity {

	const OPTION_KEY = 'cb_core_verbosity';

	const LEVEL_ALWAYS        = 'always';
	const LEVEL_ADMINS_ONLY   = 'admins_only';
	const LEVEL_CRITICAL_ONLY = 'critical_only';
	const LEVEL_DISABLED      = 'disabled';

	const LEVELS = [
		self::LEVEL_ALWAYS,
		self::LEVEL_ADMINS_ONLY,
		self::LEVEL_CRITICAL_ONLY,
		self::LEVEL_DISABLED,
	];

	/**
	 * Default verbosity per category. Baked in - presets in
	 * Presets can override these.
	 */
	const DEFAULTS = [
		'logins'   => self::LEVEL_ADMINS_ONLY,
		'plugins'  => self::LEVEL_ALWAYS,
		'themes'   => self::LEVEL_ALWAYS,
		'core'     => self::LEVEL_ALWAYS,
		'users'    => self::LEVEL_ALWAYS,
		'settings' => self::LEVEL_ALWAYS,
		'other'    => self::LEVEL_ALWAYS,
	];

	/**
	 * Decide whether an event should be logged.
	 *
	 * @param string $event_type Event slug (e.g. 'system_login').
	 * @param string $severity   info|notice|warning|critical.
	 * @return bool True = allowed, false = suppress.
	 */
	public static function should_log( string $event_type, string $severity ): bool {
		$category = self::category_for( $event_type );
		$level    = self::level_for_category( $category );

		switch ( $level ) {
			case self::LEVEL_DISABLED:
				return false;

			case self::LEVEL_CRITICAL_ONLY:
				return in_array( $severity, [ 'warning', 'critical' ], true );

			case self::LEVEL_ADMINS_ONLY:
				// Only log if the acting user has manage_options. Falls back
				// to 'always' when there is no current user (e.g. WP-CLI,
				// cron) so we don't silently drop system-initiated events.
				if ( ! function_exists( 'current_user_can' ) ) {
					return true;
				}
				if ( 0 === get_current_user_id() ) {
					return true;
				}
				return current_user_can( 'manage_options' );

			case self::LEVEL_ALWAYS:
			default:
				return true;
		}
	}

	/**
	 * Current verbosity level for a category. Returns the saved value,
	 * falling back to DEFAULTS when the category hasn't been configured.
	 */
	public static function level_for_category( string $category ): string {
		$settings = get_option( self::OPTION_KEY, [] );
		$settings = is_array( $settings ) ? $settings : [];
		$level    = $settings[ $category ] ?? ( self::DEFAULTS[ $category ] ?? self::LEVEL_ALWAYS );
		return in_array( $level, self::LEVELS, true ) ? $level : self::LEVEL_ALWAYS;
	}

	/**
	 * Save a category's verbosity level. Returns false on invalid input.
	 */
	public static function set_level( string $category, string $level ): bool {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			return false;
		}
		$settings              = get_option( self::OPTION_KEY, [] );
		$settings              = is_array( $settings ) ? $settings : [];
		$settings[ $category ] = $level;
		return (bool) update_option( self::OPTION_KEY, $settings, false );
	}

	/**
	 * Map an event slug to a category. The mapping is public so admin
	 * UIs can group events by category for the verbosity table.
	 *
	 * Event slugs come in normalised form (dot → underscore), so the
	 * prefixes here use 'system_' rather than 'system.'.
	 */
	public static function category_for( string $event_type ): string {
		if ( 0 === strpos( $event_type, 'system_login' ) ) {
			return 'logins';
		}
		if ( 0 === strpos( $event_type, 'system_plugin' ) ) {
			return 'plugins';
		}
		if ( 0 === strpos( $event_type, 'system_theme' ) ) {
			return 'themes';
		}
		if ( 0 === strpos( $event_type, 'system_core' ) ) {
			return 'core';
		}
		if ( 0 === strpos( $event_type, 'system_foundation' ) ) {
			return 'core';
		}
		if ( 0 === strpos( $event_type, 'system_user' ) ) {
			return 'users';
		}
		if ( 0 === strpos( $event_type, 'system_option' ) ) {
			return 'settings';
		}
		return 'other';
	}

	/**
	 * All configured levels, merged with defaults for any missing keys.
	 *
	 * @return array<string, string>
	 */
	public static function all_levels(): array {
		$settings = get_option( self::OPTION_KEY, [] );
		$settings = is_array( $settings ) ? $settings : [];
		return array_merge( self::DEFAULTS, $settings );
	}
}
