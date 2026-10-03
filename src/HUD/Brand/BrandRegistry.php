<?php
declare(strict_types=1);
/**
 * BrandRegistry - central registry for HUD brand implementations.
 *
 * Storage layer is in-memory only - brands register themselves on every
 * request via {@see \CoreBlueprint\Core\HUD\Bootstrap::register_brands()} (built-ins)
 * and the `cb_core_register_brands` action (siblings + white-label). The
 * registry is rebuilt each load; no persistence beyond the active brand
 * id (which lives in {@see \CoreBlueprint\Core\HUD\Storage} as user_meta).
 *
 * Resolution flow for the active brand:
 *
 *   1. Read user_meta cb_core_active_brand
 *   2. If set and registered → return that brand
 *   3. If set but not registered (e.g. brand plugin deactivated since
 *      last setting) → fall through to step 4 with audit warning
 *   4. Read site default (Settings::default_brand())
 *   5. If site default registered → return it
 *   6. Final fallback → built-in CoreBlueprint brand (always registered)
 *
 * The fallback chain ensures the brand picker never returns null - even
 * if every site default is missing, CoreBlueprint always exists as the
 * baseline.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\HUD\Brand;

use CoreBlueprint\Core\HUD\Settings;
use CoreBlueprint\Core\HUD\Storage;

defined( 'ABSPATH' ) || exit;

final class BrandRegistry {

	/** @var array<string, BrandInterface> */
	private static array $brands = [];

	/**
	 * Register a brand implementation. Subsequent calls with the same id
	 * silently overwrite - last-write-wins, used by white-label plugins
	 * that want to replace a built-in brand wholesale.
	 */
	public static function register( BrandInterface $brand ): void {
		$id = sanitize_key( $brand->id() );
		if ( '' === $id ) {
			return;
		}
		self::$brands[ $id ] = $brand;
	}

	/**
	 * Whether a given brand id is registered.
	 */
	public static function has( string $id ): bool {
		$id = sanitize_key( $id );
		return isset( self::$brands[ $id ] );
	}

	/**
	 * Look up a brand by id. Returns null when no brand with that id is
	 * registered - callers must handle the fallback (see {@see current()}
	 * for the canonical fallback pattern).
	 */
	public static function get( string $id ): ?BrandInterface {
		$id = sanitize_key( $id );
		return self::$brands[ $id ] ?? null;
	}

	/**
	 * Every registered brand, sorted by id for deterministic ordering in
	 * the brand picker. Returned as an indexed array of BrandInterface
	 * instances - the picker UI is responsible for any further sorting
	 * logic (e.g. "available before coming-soon").
	 *
	 * @return array<int, BrandInterface>
	 */
	public static function all(): array {
		$brands = self::$brands;
		ksort( $brands );
		return array_values( $brands );
	}

	/**
	 * Resolve the brand active for the current user, walking the
	 * fallback chain. Always returns a brand - the chain ends in
	 * CoreBlueprint which is registered unconditionally by Bootstrap,
	 * so callers can rely on a non-null return.
	 *
	 * @throws \LogicException When even the CoreBlueprint fallback is
	 *                         missing - indicates Bootstrap::register_brands()
	 *                         hasn't run, which is a developer error.
	 */
	public static function current(): BrandInterface {
		// 1. Per-user override.
		$user_brand_id = Storage::get_active_brand_id();
		if ( '' !== $user_brand_id && self::has( $user_brand_id ) ) {
			return self::$brands[ $user_brand_id ];
		}

		// 2. Site default.
		$site_default = Settings::default_brand();
		if ( '' !== $site_default && self::has( $site_default ) ) {
			return self::$brands[ $site_default ];
		}

		// 3. Built-in CoreBlueprint fallback - always present after
		// Bootstrap::register_brands() has fired.
		if ( self::has( 'core-blueprint' ) ) {
			return self::$brands[ 'core-blueprint' ];
		}

		// 4. Developer error - no brand registered at all. Throw rather
		// than guess; brand-less render path is undefined behaviour.
		throw new \LogicException(
			'CB Core HUD: BrandRegistry has no brands. Bootstrap::register_brands() must run before BrandRegistry::current().'
		);
	}
}
