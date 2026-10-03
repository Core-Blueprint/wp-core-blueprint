<?php
declare(strict_types=1);
/**
 * ModuleRegistry
 *
 * Central registry for Core Blueprint feature modules. Modules
 * are collected via the `core_blueprint_modules` filter on `plugins_loaded` priority
 * 20, giving sibling CB plugins a chance to register their own modules.
 *
 * In Milestone 1 the registry ships empty - no built-in modules are bundled
 * yet. Milestone 2 adds Fingerprint and Headers. Sibling plugins (Access
 * Control, Protected Content) may already register their own modules now.
 *
 * Feature-enablement helpers honour settings, delegation detection, and the
 * failsafe bypass. When those subsystems are not yet loaded (M1), the helpers
 * gracefully fall back to a permissive default so the plugin keeps booting.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Security;

use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Detector;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class ModuleRegistry {

	/** @var array<string, Module> Registered modules keyed by slug. */
	private static array $modules = [];

	/** Has the registry been booted? */
	private static bool $booted = false;

	// ─── Boot ─────────────────────────────────────────────────────────────────

	/**
	 * Collect all modules via filter and call boot() on each. Called once
	 * on plugins_loaded priority 20.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		/**
		 * Filter: core_blueprint_modules
		 *
		 * Register additional modules by appending to the array. Each entry
		 * must implement Module.
		 *
		 * Example:
		 *   add_filter( 'core_blueprint_modules', function( $modules ) {
		 *     $modules[] = new My_Custom_Module();
		 *     return $modules;
		 *   });
		 */
		$modules = apply_filters( 'core_blueprint_modules', [] );

		foreach ( $modules as $module ) {
			if ( ! ( $module instanceof Module ) ) {
				continue;
			}

			$slug = $module->slug();
			if ( empty( $slug ) || isset( self::$modules[ $slug ] ) ) {
				continue; // Ignore empties and duplicates.
			}

			self::$modules[ $slug ] = $module;
		}

		// Boot each module after registration is complete.
		foreach ( self::$modules as $module ) {
			try {
				$module->boot();
			} catch ( \Throwable $e ) {
				// Module boot failure must NEVER crash the plugin. Log and continue.
				if ( class_exists( AuditLog::class ) ) {
					AuditLog::log( 'module.boot_failed', 'critical', [
						'module'  => $module->slug(),
						'message' => $e->getMessage(),
					] );
				}
			}
		}
	}

	// ─── Accessors ────────────────────────────────────────────────────────────

	/** Get all registered modules. */
	public static function all(): array {
		return self::$modules;
	}

	/** Get a single module by slug, or null. */
	public static function get( string $slug ): ?Module {
		return self::$modules[ $slug ] ?? null;
	}

	/** How many modules are currently registered. */
	public static function count(): int {
		return count( self::$modules );
	}

	// ─── Feature enablement helpers ──────────────────────────────────────────

	/**
	 * Is a specific feature enabled in the settings?
	 *
	 * Respects (when those subsystems are loaded):
	 *   - The individual toggle in settings
	 *   - Delegation to another plugin (via Detector)
	 *   - Failsafe bypass (for restrictive features)
	 *
	 * In M1, where settings/detector/failsafe may not yet be loaded, this
	 * helper returns false (fail-closed) - the safer default. Modules
	 * themselves only reach this code path in M2+ when they exist.
	 *
	 * @param string $module_slug
	 * @param string $feature_id
	 * @return bool
	 */
	public static function is_feature_enabled( string $module_slug, string $feature_id ): bool {
		if ( ! class_exists( Settings::class ) ) {
			return false;
		}

		$settings = get_option( CB_CORE_SETTINGS, [] );
		$modules  = is_array( $settings['modules'] ?? null ) ? $settings['modules'] : [];

		// If the whole module is disabled, the feature is disabled.
		if ( empty( $modules[ $module_slug ]['enabled'] ) ) {
			return false;
		}

		// Individual feature toggle.
		$feature_enabled = (bool) ( $modules[ $module_slug ]['features'][ $feature_id ] ?? false );
		if ( ! $feature_enabled ) {
			return false;
		}

		// Delegation / restrictive checks.
		$module = self::get( $module_slug );
		if ( $module ) {
			foreach ( $module->features() as $feature ) {
				if ( ( $feature['id'] ?? '' ) === $feature_id ) {
					if ( ! empty( $feature['conflict'] ) && class_exists( Detector::class ) ) {
						if ( Detector::delegated_to( $feature['conflict'] ) !== null ) {
							return false; // Another plugin handles it.
						}
					}
					if ( ! empty( $feature['restrictive'] ) && class_exists( Failsafe::class ) ) {
						if ( Failsafe::is_bypassed() ) {
							return false;
						}
					}
					break;
				}
			}
		}

		return true;
	}
}
