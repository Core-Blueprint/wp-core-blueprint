<?php
declare(strict_types=1);
/**
 * Core Setup evidence for the optional Notes and Reports operational tools.
 *
 * The check records only activation choices. Module-specific configuration
 * remains owned by Notes and Reports.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Modules\ActivationRegistry;
use CoreBlueprint\Core\Modules\Status as ModuleStatus;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;

defined( 'ABSPATH' ) || exit;

final class OperationalToolsCheck implements CheckInterface {

	public function id(): string { return 'operational-tools'; }
	public function section(): string { return 'operations'; }
	public function label(): string { return 'Notes and Reports'; }
	public function kind(): string { return self::KIND_OPTIONAL; }
	public function capability(): string { return 'manage_options'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=core-blueprint' ); }
	public function allows_later(): bool { return true; }

	public function evidence(): Evidence {
		try {
			$states = [];
			$attention = false;

			foreach ( [ 'notes', 'reports' ] as $id ) {
				if ( null === ActivationRegistry::definition( $id ) ) {
					return Evidence::unavailable( 'operations.tools-unavailable' );
				}
				$enabled = ActivationRegistry::is_enabled( $id );
				$status = ModuleStatus::get( $id );
				$status_state = is_array( $status ) ? (string) ( $status['state'] ?? '' ) : '';

				if ( $enabled && in_array( $status_state, [ 'warn', 'err', 'off', '' ], true ) ) {
					$attention = true;
				}

				$states[ $id ] = [
					'enabled'      => $enabled,
					'status_state' => $status_state,
				];
			}

			return new Evidence(
				$attention ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$attention ? 'operations.tools-attention' : 'operations.tools-reviewed-choice',
				[ 'modules' => $states ],
				[ 'modules' => $states ]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'operations.tools-unavailable' );
		}
	}

	public function allows_not_applicable( Evidence $evidence ): bool {
		$modules = $evidence->context()['modules'] ?? [];
		return is_array( $modules )
			&& empty( $modules['notes']['enabled'] )
			&& empty( $modules['reports']['enabled'] );
	}
}
