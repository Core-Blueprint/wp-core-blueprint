<?php
declare(strict_types=1);
/**
 * Failsafe\Enable - `wp cb failsafe enable` (state-change).
 *
 * Deactivates Layer 2 of the bypass and resumes enforcement. Reports
 * whether Layer 1 (CB_CORE_BYPASS constant in wp-config.php) or Layer 3
 * (60-minute window) is still keeping the bypass active - those need
 * separate action.
 *
 * Treated as `state` because it strengthens enforcement (security improves);
 * banner-warning in the Console, no confirm-modal needed.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Failsafe;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;

defined( 'ABSPATH' ) || exit;

final class Enable implements CommandInterface {

	public function execute( array $args ): Result {
		\CoreBlueprint\Core\Security\Failsafe::deactivate_emergency_bypass( 'console' );

		$layers = \CoreBlueprint\Core\Security\Failsafe::active_layers();
		$lines  = [];

		if ( $layers['constant'] ) {
			$lines[] = '⚠ Layer 2 cleared, but the CB_CORE_BYPASS constant is still defined in wp-config.php.';
			$lines[] = '  Remove that constant to fully resume enforcement.';
			return Result::warning(
				__( 'Bypass partially deactivated - wp-config.php constant still active.', 'core-blueprint' ),
				$lines,
				[ 'layers' => $layers, 'fully_resumed' => false ]
			);
		}

		if ( $layers['transient'] ) {
			$lines[] = '⚠ Layer 2 cleared, but a Layer 3 bypass window is still active.';
			$lines[] = '  Run `cb failsafe close-window` to close it immediately.';
			return Result::warning(
				__( 'Bypass partially deactivated - Layer 3 window still open.', 'core-blueprint' ),
				$lines,
				[ 'layers' => $layers, 'fully_resumed' => false ]
			);
		}

		$lines[] = 'Emergency bypass deactivated.';
		$lines[] = 'Core Blueprint is now enforcing all configured restrictions.';

		return Result::success(
			__( 'Bypass deactivated. CB enforcement resumed.', 'core-blueprint' ),
			$lines,
			[ 'layers' => $layers, 'fully_resumed' => true ]
		);
	}

	public function args_schema(): array {
		return [];
	}

	public function side_effects(): string {
		return 'state';
	}

	/**
	 * Deactivate the emergency bypass and resume enforcement.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb failsafe enable
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		\CoreBlueprint\Core\Security\Failsafe::deactivate_emergency_bypass( 'cli' );

		$layers = \CoreBlueprint\Core\Security\Failsafe::active_layers();
		if ( $layers['constant'] ) {
			\WP_CLI::warning( 'Layer 2 cleared, but the CB_CORE_BYPASS constant is still defined in wp-config.php. Remove it to fully resume enforcement.' );
			return;
		}
		if ( $layers['transient'] ) {
			\WP_CLI::warning( 'Layer 2 cleared, but a Layer 3 bypass window is still active. Run `wp cb failsafe close-window` to close it immediately.' );
			return;
		}
		\WP_CLI::success( 'Emergency bypass deactivated. Core Blueprint is now enforcing all configured restrictions.' );
	}
}
