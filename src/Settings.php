<?php
declare(strict_types=1);
/**
 * Central persistence and mutation facade for Base settings.
 *
 * The complete Base configuration is stored in one serialized option so
 * related mutations can be committed as one document. Canonical Base-owned
 * defaults live in SettingsDefaults; extensions own their own persistent state.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Security\AccessMode;
use CoreBlueprint\Core\Security\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/** Request-local merged settings cache. */
	private static ?array $cached = null;

	/** Current public settings schema version. */
	const SCHEMA_VERSION = 1;

	/** Valid site modes. */
	const SITE_MODES = [ 'hub', 'production', 'development' ];

	/**
	 * Return the canonical Base-owned defaults document.
	 *
	 * Extensions must keep their persistent configuration in extension-owned
	 * storage rather than injecting state into Base's settings option.
	 */
	public static function defaults(): array {
		return SettingsDefaults::all();
	}

	/** Get the full settings array with nested defaults merged in. */
	public static function get(): array {
		if ( null !== self::$cached ) {
			return self::$cached;
		}

		$stored = get_option( CB_CORE_SETTINGS, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		self::$cached = self::deep_merge( self::defaults(), $stored );
		return self::$cached;
	}

	public static function site_mode(): string {
		$mode = self::get()['site_mode'] ?? 'production';
		return in_array( $mode, self::SITE_MODES, true ) ? $mode : 'production';
	}

	public static function shield_enabled(): bool {
		$val = self::get()['shield_enabled'] ?? true;
		return (bool) $val;
	}

	public static function set_shield_enabled( bool $enabled, string $actor = 'unknown' ): bool {
		return self::set_key( 'shield_enabled', $enabled, $actor );
	}

	/**
	 * Update one top-level Base settings key and emit an audit-safe change hint.
	 */
	public static function set_key( string $key, $value, string $actor = 'unknown' ): bool {
		$settings = self::get();
		$before   = $settings[ $key ] ?? null;

		$before_hint      = self::hint( $before );
		$settings[ $key ] = $value;
		$after_hint       = self::hint( $value );

		$result = self::persist( $settings );

		if ( $result && class_exists( AuditLog::class ) ) {
			$entry = [
				'key'    => $key,
				'before' => $before_hint,
				'after'  => $after_hint,
				'actor'  => $actor,
			];

			if ( is_array( $before ) && is_array( $value ) ) {
				$paths = self::diff_paths( $before, $value );
				if ( ! empty( $paths ) ) {
					$entry['changed'] = implode( ', ', $paths );
				}
			}

			AuditLog::log( 'settings.changed', 'notice', $entry );
		}

		return $result;
	}

	public static function set_module_enabled( string $module_slug, bool $enabled, string $actor = 'unknown' ): bool {
		$settings    = self::get();
		$module_slug = sanitize_key( $module_slug );

		if ( empty( $module_slug ) ) {
			return false;
		}

		if ( ! isset( $settings['modules'][ $module_slug ] ) ) {
			$settings['modules'][ $module_slug ] = [ 'enabled' => false, 'features' => [] ];
		}

		$before = (bool) ( $settings['modules'][ $module_slug ]['enabled'] ?? false );
		$settings['modules'][ $module_slug ]['enabled'] = $enabled;

		$result = self::persist( $settings );

		if ( $result && $before !== $enabled && class_exists( AuditLog::class ) ) {
			AuditLog::log( 'settings.module_toggled', 'notice', [
				'module'  => $module_slug,
				'enabled' => $enabled,
				'actor'   => $actor,
			] );
		}

		return $result;
	}

	/**
	 * Enable or disable every registered module in one full-document write.
	 *
	 * @return array<string,bool> Changed module states keyed by slug.
	 */
	public static function set_all_modules_enabled( bool $enabled, string $actor = 'unknown' ): array {
		$settings = self::get();
		$changed  = [];
		$slugs    = [];

		if ( class_exists( ModuleRegistry::class ) ) {
			foreach ( ModuleRegistry::all() as $module ) {
				$slugs[] = $module->slug();
			}
		}
		foreach ( array_keys( $settings['modules'] ?? [] ) as $slug ) {
			if ( ! in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}

		if ( empty( $slugs ) ) {
			return [];
		}

		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( $slug );
			if ( empty( $slug ) ) {
				continue;
			}
			if ( ! isset( $settings['modules'][ $slug ] ) ) {
				$settings['modules'][ $slug ] = [ 'enabled' => false, 'features' => [] ];
			}
			$before = (bool) ( $settings['modules'][ $slug ]['enabled'] ?? false );
			if ( $before === $enabled ) {
				continue;
			}
			$settings['modules'][ $slug ]['enabled'] = $enabled;
			$changed[ $slug ] = $enabled;
		}

		if ( empty( $changed ) ) {
			return [];
		}

		$result = self::persist( $settings );

		if ( $result && class_exists( AuditLog::class ) ) {
			AuditLog::log( 'settings.modules_bulk_toggled', 'notice', [
				'enabled' => $enabled,
				'count'   => count( $changed ),
				'slugs'   => array_keys( $changed ),
				'actor'   => $actor,
			] );
		}

		return $result ? $changed : [];
	}

	public static function set_feature_enabled( string $module_slug, string $feature_id, bool $enabled, string $actor = 'unknown' ): bool {
		$settings    = self::get();
		$module_slug = sanitize_key( $module_slug );
		$feature_id  = sanitize_key( $feature_id );

		if ( empty( $module_slug ) || empty( $feature_id ) ) {
			return false;
		}

		if ( ! isset( $settings['modules'][ $module_slug ] ) ) {
			$settings['modules'][ $module_slug ] = [ 'enabled' => true, 'features' => [] ];
		}

		$before = (bool) ( $settings['modules'][ $module_slug ]['features'][ $feature_id ] ?? false );
		$settings['modules'][ $module_slug ]['features'][ $feature_id ] = $enabled;

		$result = self::persist( $settings );

		if ( $result && $before !== $enabled && class_exists( AuditLog::class ) ) {
			AuditLog::log( 'settings.feature_toggled', 'notice', [
				'module'  => $module_slug,
				'feature' => $feature_id,
				'enabled' => $enabled,
				'actor'   => $actor,
			] );
		}

		return $result;
	}

	public static function apply_recommended_defaults( string $actor = 'unknown' ): void {
		$settings = self::get();
		$shield   = self::shield_enabled();
		$mode     = self::effective_hardening_mode();

		foreach ( ModuleRegistry::all() as $module ) {
			$slug = $module->slug();
			if ( ! isset( $settings['modules'][ $slug ] ) ) {
				$settings['modules'][ $slug ] = [ 'enabled' => true, 'features' => [] ];
			}
			$settings['modules'][ $slug ]['enabled'] = $shield;

			foreach ( $module->features() as $feature ) {
				$feature_id = $feature['id'] ?? '';
				if ( empty( $feature_id ) ) {
					continue;
				}

				$should_enable = $shield ? self::should_enable_for_mode( $feature, $mode ) : false;
				$settings['modules'][ $slug ]['features'][ $feature_id ] = $should_enable;
			}
		}

		self::persist( $settings );

		if ( class_exists( AuditLog::class ) ) {
			AuditLog::log( 'settings.defaults_applied', 'notice', [
				'shield' => $shield ? 'on' : 'off',
				'mode'   => $mode,
				'actor'  => $actor,
			] );
		}
	}

	/** @return string 'hub' | 'production' */
	public static function effective_hardening_mode(): string {
		if ( class_exists( AccessMode::class ) && AccessMode::is_admin_only() ) {
			return 'hub';
		}

		return 'production';
	}

	private static function should_enable_for_mode( array $feature, string $mode ): bool {
		$default = (bool) ( $feature['default'] ?? false );
		$risk    = $feature['risk'] ?? 'low';

		if ( 'high' === $risk ) {
			return false;
		}

		switch ( $mode ) {
			case 'hub':
				if ( ! empty( $feature['restrictive'] ) ) {
					return true;
				}
				return $default;

			case 'production':
				if ( 'medium' === $risk && ! empty( $feature['restrictive'] ) ) {
					return false;
				}
				return $default;

			case 'development':
				if ( 'none' !== $risk ) {
					return false;
				}
				return $default;

			default:
				return $default;
		}
	}

	/** Persist the full Base settings document and synchronize the local cache. */
	private static function persist( array $settings ): bool {
		$result = update_option( CB_CORE_SETTINGS, $settings, true );
		if ( $result ) {
			self::$cached = self::deep_merge( self::defaults(), $settings );
			return true;
		}

		$stored = get_option( CB_CORE_SETTINGS, null );
		if ( is_array( $stored ) && $stored === $settings ) {
			self::$cached = self::deep_merge( self::defaults(), $settings );
		}
		return false;
	}

	private static function deep_merge( array $defaults, array $override ): array {
		$merged = $defaults;
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) {
				$merged[ $key ] = self::deep_merge( $merged[ $key ], $value );
			} else {
				$merged[ $key ] = $value;
			}
		}
		return $merged;
	}

	private static function hint( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			return substr( $value, 0, 80 );
		}
		if ( is_array( $value ) ) {
			return sprintf( 'array(%d keys)', count( $value ) );
		}
		if ( is_null( $value ) ) {
			return 'null';
		}
		return gettype( $value );
	}

	/**
	 * @param array<mixed,mixed> $old
	 * @param array<mixed,mixed> $new
	 * @return string[]
	 */
	private static function diff_paths( array $old, array $new, string $prefix = '' ): array {
		$paths    = [];
		$all_keys = array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) );

		foreach ( $all_keys as $k ) {
			$path  = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			$o_has = array_key_exists( $k, $old );
			$n_has = array_key_exists( $k, $new );
			$o_val = $old[ $k ] ?? null;
			$n_val = $new[ $k ] ?? null;

			if ( $o_has && $n_has && is_array( $o_val ) && is_array( $n_val ) ) {
				$paths = array_merge( $paths, self::diff_paths( $o_val, $n_val, $path ) );
				continue;
			}

			if ( ! $o_has || ! $n_has || $o_val !== $n_val ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}
}
