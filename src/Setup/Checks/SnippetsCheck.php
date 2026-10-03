<?php
declare(strict_types=1);
/**
 * Core Setup evidence for the managed Snippets runtime.
 *
 * Snippet source code and human-authored titles/descriptions never enter Setup
 * evidence. Only bounded runtime metadata and existing code hashes are used.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Modules\ActivationRegistry;
use CoreBlueprint\Core\Modules\Status as ModuleStatus;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;
use CoreBlueprint\Core\Setup\Fingerprint;
use CoreBlueprint\Core\Snippets\Admin\Page;
use CoreBlueprint\Core\Snippets\Repository;

defined( 'ABSPATH' ) || exit;

final class SnippetsCheck implements CheckInterface {

	public function id(): string { return 'snippets'; }
	public function section(): string { return 'cms-tools'; }
	public function label(): string { return 'Snippets'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'cb_manage_snippets'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Page::SLUG ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$enabled = ActivationRegistry::is_enabled( 'snippets' );
			if ( ! $enabled ) {
				return new Evidence(
					Evidence::HEALTH_OK,
					'snippets.disabled',
					[ 'enabled' => false ],
					[ 'enabled' => false, 'count' => 0, 'enabled_count' => 0 ]
				);
			}

			$status = ModuleStatus::get( 'snippets' );
			if ( ! is_array( $status ) ) {
				return Evidence::unavailable( 'snippets.status-unavailable' );
			}
			$status_state = (string) ( $status['state'] ?? '' );
			$attention = in_array( $status_state, [ 'warn', 'err', 'off', '' ], true );

			$safe_meta = [];
			$enabled_count = 0;
			foreach ( Repository::all() as $id => $meta ) {
				if ( ! is_array( $meta ) ) {
					continue;
				}
				$is_enabled = ! empty( $meta['enabled'] );
				$enabled_count += $is_enabled ? 1 : 0;
				$safe_meta[ (string) $id ] = [
					'enabled'    => $is_enabled,
					'type'       => (string) ( $meta['type'] ?? '' ),
					'location'   => (string) ( $meta['location'] ?? '' ),
					'priority'   => (int) ( $meta['priority'] ?? 0 ),
					'code_hash'  => (string) ( $meta['code_hash'] ?? '' ),
					'last_error' => is_array( $meta['last_error'] ?? null ),
				];
			}
			ksort( $safe_meta, SORT_STRING );

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'snippets.attention' : 'snippets.ready',
				[
					'enabled'        => true,
					'status_state'   => $status_state,
					'inventory_hash' => Fingerprint::hash( [ 'snippets' => $safe_meta ] ),
					'count'          => count( $safe_meta ),
					'enabled_count'  => $enabled_count,
				],
				[
					'enabled'       => true,
					'status_state'  => $status_state,
					'count'         => count( $safe_meta ),
					'enabled_count' => $enabled_count,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'snippets.unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['enabled'] );
	}
}
