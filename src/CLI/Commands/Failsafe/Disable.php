<?php
declare(strict_types=1);
/**
 * Failsafe\Disable - `wp cb failsafe disable` (destructive).
 *
 * Activates the emergency bypass - turns off all restrictive Core Blueprint
 * features site-wide. Used when an operator is locked out or needs to
 * temporarily stop CB enforcement (e.g. during incident response).
 *
 * Treated as `destructive` because while it's reversible (run `enable` to
 * undo), an active bypass exposes the site to the very threats CB was
 * configured to block. Modal-confirm in the Console.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Failsafe;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;

defined( 'ABSPATH' ) || exit;

final class Disable implements CommandInterface {

	public function execute( array $args ): Result {
		$reason = isset( $args['reason'] ) ? (string) $args['reason'] : '';
		$reason = '' !== trim( $reason ) ? $reason : null;

		\CoreBlueprint\Core\Security\Failsafe::activate_emergency_bypass( 'console', $reason );

		$lines = [
			'Emergency bypass activated.',
			'All restrictive Core Blueprint features are now disabled.',
			'',
			'Run `cb failsafe enable` to resume enforcement.',
		];

		return Result::success(
			__( 'Emergency bypass activated. CB enforcement disabled.', 'core-blueprint' ),
			$lines,
			[ 'bypass_active' => true, 'reason' => $reason ]
		);
	}

	public function args_schema(): array {
		return [
			'reason' => [
				'type'  => 'text',
				'label' => __( 'Reason', 'core-blueprint' ),
				'help'  => __( 'Optional - saved to the audit log. Useful for incident-response audit trails.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string {
		return 'destructive';
	}

	/**
	 * Activate the emergency bypass.
	 *
	 * ## OPTIONS
	 *
	 * [--reason=<text>]
	 * : Reason for activating the bypass (saved to audit log).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb failsafe disable
	 *     wp cb failsafe disable --reason="Locked out"
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$reason = $assoc_args['reason'] ?? null;
		\CoreBlueprint\Core\Security\Failsafe::activate_emergency_bypass( 'cli', $reason );
		\WP_CLI::success( 'Emergency bypass activated. All restrictive Core Blueprint features are now disabled.' );
		\WP_CLI::line( 'Run `wp cb failsafe enable` to resume enforcement.' );
	}
}
