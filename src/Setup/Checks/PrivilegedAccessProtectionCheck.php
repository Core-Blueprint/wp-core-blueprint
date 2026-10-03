<?php
declare(strict_types=1);
/**
 * Core Setup evidence for the Privileged Access Protection policy.
 *
 * Enforce and Monitor are both explicit governance choices. Pending identity
 * reviews are evaluated by the separate PrivilegedAccessReviewCheck.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Preferences;
use CoreBlueprint\Core\Permissions\PrivilegedAccessPolicy;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class PrivilegedAccessProtectionCheck implements CheckInterface {

	public function id(): string { return 'privileged-access-protection'; }
	public function section(): string { return 'administrator-recovery'; }
	public function label(): string { return 'Privileged Access Protection'; }
	public function kind(): string { return self::KIND_DECISION; }
	public function capability(): string { return 'cb_view_permissions'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Preferences::SLUG . '&tab=permissions' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$mode = PrivilegedAccessPolicy::enforcement_mode();
			$operators = PrivilegedAccessRegistry::approved_operator_count();

			return new Evidence(
				Evidence::HEALTH_OK,
				PrivilegedAccessPolicy::MODE_ENFORCE === $mode
					? 'privileged-access.enforce'
					: 'privileged-access.monitor',
				[
					'mode' => $mode,
				],
				[
					'mode'                    => $mode,
					'approved_operator_count' => $operators,
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'privileged-access.protection-unavailable' );
		}
	}
}
