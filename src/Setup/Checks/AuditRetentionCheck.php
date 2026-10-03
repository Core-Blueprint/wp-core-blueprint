<?php
declare(strict_types=1);
/**
 * Core Setup evidence for canonical Audit Log retention policy.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Preferences;
use CoreBlueprint\Core\Governance\RetentionPolicy;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class AuditRetentionCheck implements CheckInterface {

	public function id(): string { return 'audit-retention'; }
	public function section(): string { return 'privacy-governance'; }
	public function label(): string { return 'Audit retention'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=privacy' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$policy = RetentionPolicy::all();
			ksort( $policy, SORT_STRING );
			return new Evidence(
				Evidence::HEALTH_OK,
				'audit.retention',
				[ 'retention_days' => $policy ],
				[ 'retention_days' => $policy ]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'audit.retention-unavailable' );
		}
	}
}
