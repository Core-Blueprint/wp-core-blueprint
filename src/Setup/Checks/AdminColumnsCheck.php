<?php
declare(strict_types=1);
/**
 * Core Setup evidence for site-wide Admin Columns Governance.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Setup\Checks;

use CB\Core\AdminColumns\PolicyRepository;
use CB\Core\Setup\CheckInterface;
use CB\Core\Setup\Evidence;
use CB\Core\Setup\Fingerprint;

defined( 'ABSPATH' ) || exit;

final class AdminColumnsCheck implements CheckInterface {

	public function id(): string { return 'admin-columns'; }
	public function section(): string { return 'cms-tools'; }
	public function label(): string { return 'Admin Columns'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'edit.php' ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$policy = PolicyRepository::get();
			$screens = is_array( $policy['screens'] ?? null ) ? $policy['screens'] : [];
			$active = [] !== $screens;

			return new Evidence(
				Evidence::HEALTH_OK,
				$active ? 'admin-columns.configured' : 'admin-columns.default',
				[
					'active'      => $active,
					'policy_hash' => Fingerprint::hash( [ 'policy' => $policy ] ),
					'screen_count'=> count( $screens ),
				],
				[
					'active'       => $active,
					'screen_count' => count( $screens ),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'admin-columns.unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		return empty( $evidence->context()['active'] );
	}
}
