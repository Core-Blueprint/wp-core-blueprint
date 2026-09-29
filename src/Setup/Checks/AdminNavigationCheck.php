<?php
declare(strict_types=1);
/**
 * Core Setup evidence for Admin Navigation presentation governance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\Admin\Pages\Preferences;
use CB\Core\AdminNavigation\Policy;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;
use CB\Core\Setup\Fingerprint;

defined( 'ABSPATH' ) || exit;

final class AdminNavigationCheck implements CheckInterface {

	public function id(): string { return 'admin-navigation'; }
	public function section(): string { return 'cms-tools'; }
	public function label(): string { return 'Admin Navigation'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=admin-navigation' ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$policy = Policy::get();
			$active = Policy::defaults() !== $policy;
			$counts = [
				'menu_order'      => count( $policy['menu']['order'] ?? [] ),
				'menu_hidden'     => count( $policy['menu']['hidden'] ?? [] ),
				'toolbar_hidden'  => count( $policy['toolbar']['hidden'] ?? [] ),
				'toolbar_renamed' => count( $policy['toolbar']['renamed'] ?? [] ),
			];

			return new Evidence(
				Evidence::HEALTH_OK,
				$active ? 'admin-navigation.configured' : 'admin-navigation.default',
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
			return Evidence::unavailable( 'admin-navigation.unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['active'] );
	}
}
