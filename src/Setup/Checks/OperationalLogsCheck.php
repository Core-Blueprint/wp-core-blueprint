<?php
declare(strict_types=1);
/**
 * Core Setup evidence for the always-on audit/log foundation.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Modules\Status as ModuleStatus;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class OperationalLogsCheck implements CheckInterface {

	public function id(): string { return 'operational-logs'; }
	public function section(): string { return 'operations'; }
	public function label(): string { return 'Audit and logs'; }
	public function kind(): string { return self::KIND_REQUIRED; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=core-blueprint-logs' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$status = ModuleStatus::get( 'logs' );
			if ( ! is_array( $status ) ) {
				return Evidence::unavailable( 'operations.logs-unavailable' );
			}

			$state = (string) ( $status['state'] ?? '' );
			$attention = 'ok' !== $state;

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'operations.logs-attention' : 'operations.logs-ready',
				[ 'status_state' => $state ],
				[ 'status_state' => $state ]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'operations.logs-unavailable' );
		}
	}
}
