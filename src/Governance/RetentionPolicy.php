<?php
declare(strict_types=1);
/**
 * Canonical AuditLog retention policy.
 *
 * Public v1 boundary for reading the five supported AuditLog retention
 * categories and resolving an event to exactly one category.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CoreBlueprint\Core\Governance;

defined( 'ABSPATH' ) || exit;

final class RetentionPolicy {
	public const OPTION_KEY = 'cb_core_retention';
	public const CATEGORIES = [ 'security', 'maintenance', 'logins', 'settings', 'general' ];
	private const DEFAULTS = [
		'security'    => 365,
		'maintenance' => 365,
		'logins'      => 90,
		'settings'    => 365,
		'general'     => 365,
	];

	/** @return array<string,int> */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$out    = self::DEFAULTS;
		foreach ( self::CATEGORIES as $category ) {
			if ( array_key_exists( $category, $stored ) ) {
				$out[ $category ] = max( 0, (int) $stored[ $category ] );
			}
		}
		return $out;
	}

	public static function days( string $category ): ?int {
		return self::is_category( $category ) ? self::all()[ $category ] : null;
	}

	public static function is_category( string $category ): bool {
		return in_array( $category, self::CATEGORIES, true );
	}

	/**
	 * Resolve any canonical public event ID or stored AuditLog identity to
	 * exactly one retention category. Unknown events are deliberately general.
	 */
	public static function category_for_event( string $event_type ): string {
		$registered = EventRegistry::retention_category( $event_type );
		if ( null !== $registered ) {
			return $registered;
		}

		$key = sanitize_key( str_replace( '.', '_', strtolower( trim( $event_type ) ) ) );
		if ( '' === $key ) {
			return 'general';
		}

		if (
			str_starts_with( $key, 'system_login' )
			|| 'login_success' === $key
			|| 'login_failed' === $key
		) {
			return 'logins';
		}

		foreach ( [ 'system_plugin_', 'system_theme_', 'system_core_', 'system_foundation_', 'plugin_', 'maintenance_', 'reports_', 'package_' ] as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return 'maintenance';
			}
		}

		foreach ( [ 'settings_', 'system_option_', 'ui_', 'hud_' ] as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return 'settings';
			}
		}

		foreach ( [ 'security_', 'permissions_', 'failsafe_', 'audit_', 'privacy_', 'module_', 'system_user_', 'user_' ] as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return 'security';
			}
		}

		return 'general';
	}

	/** @internal Base settings/preset write path. */
	public static function update( array $values ): bool {
		$current = self::all();
		foreach ( $values as $category => $days ) {
			$category = sanitize_key( (string) $category );
			if ( ! self::is_category( $category ) ) {
				continue;
			}
			$current[ $category ] = max( 0, (int) $days );
		}
		return update_option( self::OPTION_KEY, $current, false );
	}
}
