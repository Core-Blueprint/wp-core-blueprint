<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Admin Notices audience governance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Preferences;
use CoreBlueprint\Core\AdminNotices\Capabilities;
use CoreBlueprint\Core\AdminNotices\Policy;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;
use CoreBlueprint\Core\Setup\Fingerprint;

defined( 'ABSPATH' ) || exit;

final class AdminNoticesCheck implements CheckInterface {

	public function id(): string { return 'admin-notices'; }
	public function section(): string { return 'cms-tools'; }
	public function label(): string { return 'Admin Notices'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return Capabilities::MANAGE; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=admin-notices' ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$policy = Policy::get();
			$counts = [
				'rules'          => 0,
				'operators_only' => 0,
				'selected'       => 0,
			];

			foreach ( $policy['rules'] as $rule ) {
				$counts['rules']++;
				$visibility = (string) ( $rule['visibility'] ?? '' );
				if ( Policy::OPERATORS_ONLY === $visibility ) {
					$counts['operators_only']++;
				} elseif ( Policy::SELECTED === $visibility ) {
					$counts['selected']++;
				}
			}

			$active = 0 < ( $counts['operators_only'] + $counts['selected'] );

			return new Evidence(
				Evidence::HEALTH_OK,
				$active ? 'admin-notices.configured' : 'admin-notices.default',
				[
					'active'      => $active,
					'policy_hash' => Fingerprint::hash( [ 'policy' => $policy ] ),
					'counts'      => $counts,
				],
				[
					'active' => $active,
					'counts' => $counts,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'admin-notices.unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['active'] );
	}
}
