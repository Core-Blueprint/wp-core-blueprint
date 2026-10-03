<?php
declare(strict_types=1);
/**
 * Storage - per-user HUD state persistence.
 *
 * State strategy: user_meta is the source of truth (cross-device,
 * authoritative); localStorage in the browser is an immediate-write
 * cache so position/ghost changes feel instant without waiting for the
 * REST roundtrip. The JS layer writes to localStorage on every change
 * and POSTs the same value to the REST endpoint; if the REST call
 * fails (offline, capability lost), the browser keeps showing the
 * latest local state until next page load.
 *
 * Storage keys:
 *
 *   user_meta cb_core_hud_position  - string, one of the 8 dock anchors
 *                                     ('top-left', 'top-center', 'top-right',
 *                                     'middle-left', 'middle-right',
 *                                     'bottom-left', 'bottom-center',
 *                                     'bottom-right'). Falls back to the
 *                                     site default ('bottom-right' out of
 *                                     the box) when not set.
 *   user_meta cb_core_hud_ghost     - '1' | '0', whether ghost-mode is on
 *                                     (low opacity until hover). Falls back
 *                                     to the site default (false) when not
 *                                     set.
 *   user_meta cb_core_active_brand  - brand id (e.g. 'core-blueprint',
 *                                     'achterhood'). Resolves through
 *                                     BrandRegistry::current() with
 *                                     fallback to site default and final
 *                                     fallback to 'core-blueprint'.
 *
 *   localStorage cb_core_hud_*      - JS mirror of the above, keyed
 *                                     identically with 'cb_core_hud_'
 *                                     prefix. Source of truth for the
 *                                     UI between server roundtrips.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\HUD;

defined( 'ABSPATH' ) || exit;

final class Storage {

	public const META_POSITION     = 'cb_core_hud_position';
	public const META_GHOST        = 'cb_core_hud_ghost';
	public const META_ACTIVE_BRAND = 'cb_core_active_brand';

	/** Allowed position values - anything else falls back to the default. */
	public const POSITIONS = [
		'top-left',
		'top-center',
		'top-right',
		'middle-left',
		'middle-right',
		'bottom-left',
		'bottom-center',
		'bottom-right',
	];

	// ─── Position ──────────────────────────────────────────────────────────

	/**
	 * Resolve the active dock position for the current user. Falls back
	 * through user_meta → site default → bottom-right.
	 */
	public static function get_position( int $user_id = 0 ): string {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return Settings::default_position();
		}
		$stored = (string) get_user_meta( $user_id, self::META_POSITION, true );
		if ( '' !== $stored && in_array( $stored, self::POSITIONS, true ) ) {
			return $stored;
		}
		return Settings::default_position();
	}

	/**
	 * Persist a new dock position. Validates against the allowed-list;
	 * unknown values are rejected (returns false) so a malformed REST
	 * payload can't corrupt the stored state.
	 */
	public static function set_position( int $user_id, string $position ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			return false;
		}
		return (bool) update_user_meta( $user_id, self::META_POSITION, $position );
	}

	// ─── Ghost mode ────────────────────────────────────────────────────────

	/**
	 * Whether ghost-mode is on for the current user. Falls back through
	 * user_meta → site default → false.
	 */
	public static function get_ghost( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return Settings::default_ghost();
		}
		$stored = get_user_meta( $user_id, self::META_GHOST, true );
		if ( '1' === $stored || 1 === $stored ) {
			return true;
		}
		if ( '0' === $stored || 0 === $stored ) {
			return false;
		}
		return Settings::default_ghost();
	}

	/** Persist ghost-mode for a user. Always succeeds for valid user ids. */
	public static function set_ghost( int $user_id, bool $ghost ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) update_user_meta( $user_id, self::META_GHOST, $ghost ? '1' : '0' );
	}

	// ─── Active brand ──────────────────────────────────────────────────────

	/**
	 * Active brand id for the current user. Returns the raw stored
	 * value (or the site default) without checking whether that brand
	 * is currently registered - that lookup happens in
	 * {@see Brand\BrandRegistry::current()}, which falls back gracefully.
	 */
	public static function get_active_brand_id( int $user_id = 0 ): string {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return Settings::default_brand();
		}
		$stored = (string) get_user_meta( $user_id, self::META_ACTIVE_BRAND, true );
		if ( '' !== $stored ) {
			return $stored;
		}
		return Settings::default_brand();
	}

	/**
	 * Persist a new active brand for a user. The id is sanitised to the
	 * brand-id charset (lowercase, dashes, alphanumerics) to defend
	 * against malformed REST payloads. Empty result after sanitisation
	 * is rejected.
	 */
	public static function set_active_brand_id( int $user_id, string $brand_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$brand_id = sanitize_key( $brand_id );
		if ( '' === $brand_id ) {
			return false;
		}
		return (bool) update_user_meta( $user_id, self::META_ACTIVE_BRAND, $brand_id );
	}
}
