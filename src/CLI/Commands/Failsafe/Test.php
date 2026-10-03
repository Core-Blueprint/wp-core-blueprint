<?php
declare(strict_types=1);
/**
 * Failsafe\Test - `wp cb failsafe test` (state).
 *
 * Runs the failsafe self-test suite - a series of probes that verify the
 * bypass mechanism, token storage, and module registration are consistent.
 *
 * Side-effects classed as `state` rather than `none` because the self-test
 * can write to the audit log and may toggle transient cache entries during
 * its run. Banner-warning in the Console - operator should know the test
 * is doing real work, not just inspecting state.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Failsafe;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;

defined( 'ABSPATH' ) || exit;

final class Test implements CommandInterface {

	public function execute( array $args ): Result {
		$results = \CoreBlueprint\Core\Security\Failsafe::self_test();
		$all_ok  = true;

		$lines   = [];
		$lines[] = 'Running failsafe self-test...';
		$lines[] = '';

		$summary = [];
		foreach ( $results as $check => $result ) {
			$ok      = ! empty( $result['ok'] );
			$message = (string) ( $result['message'] ?? '' );
			$lines[] = sprintf( '  [%s] %s: %s', $ok ? 'PASS' : 'FAIL', $check, $message );
			$summary[ $check ] = [ 'ok' => $ok, 'message' => $message ];
			if ( ! $ok ) {
				$all_ok = false;
			}
		}

		$lines[] = '';

		if ( $all_ok ) {
			return Result::success(
				__( 'All failsafe checks passed.', 'core-blueprint' ),
				$lines,
				[ 'all_ok' => true, 'checks' => $summary ]
			);
		}

		return Result::warning(
			__( 'One or more failsafe checks failed. Review the details above.', 'core-blueprint' ),
			$lines,
			[ 'all_ok' => false, 'checks' => $summary ]
		);
	}

	public function args_schema(): array {
		return [];
	}

	public function side_effects(): string {
		return 'state';
	}

	/**
	 * Run the failsafe self-test.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb failsafe test
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		\WP_CLI::line( 'Running failsafe self-test...' );
		\WP_CLI::line( '' );

		$results = \CoreBlueprint\Core\Security\Failsafe::self_test();
		$all_ok  = true;

		foreach ( $results as $check => $result ) {
			$status = $result['ok'] ? \WP_CLI::colorize( '%gPASS%n' ) : \WP_CLI::colorize( '%rFAIL%n' );
			\WP_CLI::line( '  [' . $status . '] ' . $check . ': ' . $result['message'] );
			if ( ! $result['ok'] ) {
				$all_ok = false;
			}
		}

		\WP_CLI::line( '' );
		if ( $all_ok ) {
			\WP_CLI::success( 'All failsafe checks passed.' );
		} else {
			\WP_CLI::warning( 'One or more failsafe checks failed. Review the output above.' );
		}
	}
}
