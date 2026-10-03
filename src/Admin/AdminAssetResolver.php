<?php
declare(strict_types=1);
/**
 * Core Admin screen asset resolver.
 *
 * Base-owned screens resolve through ScreenContext and private manifests.
 * Registered open Logs/Reports addon tabs without semantic requirements use
 * the dedicated extension-tab full-set provider.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminAssetResolver {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ], 10 );
	}

	/** Resolve the current screen and enqueue its private Base requirements. */
	public static function enqueue( string $hook ): void {
		$context = ScreenContext::from_request( $hook );
		if ( ! ScreenAssetRegistry::owns( $context ) ) {
			return;
		}

		if ( ScreenAssetRegistry::requires_full_set( $context ) ) {
			AdminAssetCatalog::enqueue( AdminAssetCatalog::EXTENSION_TAB_FULL_SET, $context );
			return;
		}

		$asset_ids = array_values( array_unique( array_merge(
			AdminAssetCatalog::minimal_shell(),
			ScreenAssetRegistry::requirements( $context )
		) ) );

		$weighted = [];
		foreach ( $asset_ids as $index => $asset_id ) {
			$weighted[] = [
				'id'       => $asset_id,
				'priority' => AdminAssetCatalog::priority( $asset_id ),
				'index'    => $index,
			];
		}
		usort( $weighted, static function ( array $a, array $b ): int {
			return $a['priority'] === $b['priority']
				? $a['index'] <=> $b['index']
				: $a['priority'] <=> $b['priority'];
		} );

		$public_requirements_enqueued = false;
		foreach ( $weighted as $asset ) {
			if ( ! $public_requirements_enqueued && $asset['priority'] > 15 ) {
				PageRegistry::enqueue_requirements_for_hook( $hook );
				$public_requirements_enqueued = true;
			}
			AdminAssetCatalog::enqueue( $asset['id'], $context );
		}
		if ( ! $public_requirements_enqueued ) {
			PageRegistry::enqueue_requirements_for_hook( $hook );
		}
	}
}
