<?php
declare(strict_types=1);
/**
 * Permissions Caps
 *
 * Implements the "admin-toggle" mechanism: by default, only cb_operator users
 * can generate reports or run Core Scanner. When an operator flips an admin-
 * toggle on in the Permissions tab, every administrator implicitly gains the
 * relevant manage cap for the duration of that setting being enabled.
 *
 * Implementation is a single user_has_cap filter - no caps are persisted to
 * the administrator role itself, so flipping the toggle off restores the
 * default state instantly without any role-cleanup pass.
 *
 * Suite-wide: every current_user_can('cb_manage_*') call honours its toggle,
 * including ones from future addons that haven't been written yet. That's
 * the design intent - toggles are the single source of truth.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Permissions;

use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Caps {

	/**
	 * Mapping: requested-cap → settings-path that gates it. Each entry is
	 * [ cap_name, settings_path_array ]. The settings path is followed via
	 * nested array access on Settings::get().
	 *
	 * To add a new admin-toggle: register the cap in Roles::OPERATOR_CAPS,
	 * add a settings entry in Settings::defaults() under the matching path,
	 * and add the mapping here. No other code changes needed.
	 *
	 * @var array<int, array{0: string, 1: string[]}>
	 */
	private const ADMIN_TOGGLE_MAP = [
		[ 'cb_manage_reports',   [ 'reports',   'admin_can_generate', 'maintenance' ] ],
		[ 'cb_manage_integrity', [ 'integrity', 'admin_can_run' ] ],
	];

	private static bool $bootstrapped = false;

	/**
	 * Register the user_has_cap filter. Idempotent - guarded by a static flag
	 * so calling init() twice from different bootstrap paths is harmless.
	 */
	public static function init(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		add_filter( 'user_has_cap', [ __CLASS__, 'filter_user_has_cap' ], 10, 4 );
	}

	/**
	 * Return the manage capabilities controlled by Core Blueprint policy and
	 * whether each policy is currently active. Used by the User Roles editor
	 * to distinguish stored role capabilities from effective runtime grants.
	 *
	 * @return array<string,bool> Capability => policy-active.
	 */
	public static function policy_grants(): array {
		$settings = Settings::get();
		$result   = [];

		foreach ( self::ADMIN_TOGGLE_MAP as [ $cap, $path ] ) {
			$result[ $cap ] = self::resolve_path( $settings, $path );
		}

		return $result;
	}

	/**
	 * Map manage caps onto administrators when the relevant admin-toggle
	 * is on. Walks the ADMIN_TOGGLE_MAP and grants each cap whose
	 * settings-path resolves to a truthy value.
	 *
	 * @param array     $allcaps All caps the user already has, keyed by cap name.
	 * @param string[]  $caps    The capabilities being checked in this call.
	 * @param array     $args    [0] = primitive cap, [1] = user ID, [2] = object ID.
	 * @param \WP_User  $user    The user whose caps are being evaluated.
	 * @return array Filtered $allcaps.
	 */
	public static function filter_user_has_cap( array $allcaps, array $caps, array $args, $user ): array {
		// Only administrators are eligible for any toggle. Other roles never
		// gain manage caps via this filter.
		if ( empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}

		// Lazy-load settings - only read once per filter call, only if at
		// least one mapped cap is being checked.
		$settings = null;

		foreach ( self::ADMIN_TOGGLE_MAP as [ $cap, $path ] ) {
			if ( ! in_array( $cap, $caps, true ) ) {
				continue;
			}
			if ( ! empty( $allcaps[ $cap ] ) ) {
				continue;
			}

			if ( null === $settings ) {
				$settings = Settings::get();
			}

			if ( self::resolve_path( $settings, $path ) ) {
				$allcaps[ $cap ] = true;
			}
		}

		return $allcaps;
	}

	/**
	 * Walk a nested settings path. Returns false if any segment is missing
	 * or non-array along the way; otherwise returns the final value cast to
	 * bool. Defensive against partial settings shapes during bootstrap.
	 *
	 * @param array<string,mixed> $settings
	 * @param string[]            $path
	 */
	private static function resolve_path( array $settings, array $path ): bool {
		$cursor = $settings;
		foreach ( $path as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return false;
			}
			$cursor = $cursor[ $segment ];
		}
		return (bool) $cursor;
	}
}
