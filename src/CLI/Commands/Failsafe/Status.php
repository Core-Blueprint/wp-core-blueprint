<?php
declare(strict_types=1);
/**
 * Failsafe\Status - `wp cb failsafe status`.
 *
 * Read-only failsafe state snapshot.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Failsafe;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Detector;
use CoreBlueprint\Core\Security\ModuleRegistry;
use CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Status implements CommandInterface {

	public function execute( array $args ): Result {
		$bypassed = \CoreBlueprint\Core\Security\Failsafe::is_bypassed();
		$layers   = \CoreBlueprint\Core\Security\Failsafe::active_layers();

		$lines   = [];
		$lines[] = '';
		$lines[] = 'Core Blueprint - Failsafe status';
		$lines[] = str_repeat( '─', 50 );
		$lines[] = 'Version:       ' . CB_CORE_VERSION;
		$lines[] = 'DB Version:    ' . get_option( 'cb_core_db_version', 'not set' );
		$lines[] = 'Site mode:     ' . Settings::site_mode();
		$lines[] = '';
		$lines[] = 'Bypass active: ' . ( $bypassed ? 'YES (restrictive features disabled)' : 'NO' );
		$lines[] = '  Layer 1 (constant):  ' . ( $layers['constant']  ? 'active' : 'inactive' );
		$lines[] = '  Layer 2 (option):    ' . ( $layers['option']    ? 'active' : 'inactive' );
		$lines[] = '  Layer 3 (transient): ' . ( $layers['transient'] ? 'active' : 'inactive' );
		$lines[] = '';
		$lines[] = 'Token present: ' . ( get_option( CB_CORE_BYPASS_TOK, '' ) ? 'yes' : 'NO - generate one!' );

		$module_count = count( ModuleRegistry::all() );
		$lines[]      = 'Modules registered: ' . $module_count;

		$detector_summary = Detector::summary();
		if ( ! empty( $detector_summary['plugins'] ) ) {
			$lines[] = '';
			$lines[] = 'Detected security plugins:';
			foreach ( $detector_summary['plugins'] as $p ) {
				$lines[] = '  - ' . $p['label'] . ' (delegates: ' . implode( ', ', $p['features'] ) . ')';
			}
		}
		$lines[] = '';

		$data = [
			'version'       => CB_CORE_VERSION,
			'site_mode'     => Settings::site_mode(),
			'bypass_active' => $bypassed,
			'layers'        => $layers,
			'token_present' => '' !== (string) get_option( CB_CORE_BYPASS_TOK, '' ),
			'module_count'  => $module_count,
			'detected'      => $detector_summary['plugins'] ?? [],
		];

		$status = $bypassed ? 'warning' : 'success';
		$msg    = $bypassed
			? __( 'Failsafe bypass is currently active.', 'core-blueprint' )
			: __( 'Failsafe is in normal enforcement state.', 'core-blueprint' );

		return new Result( $status, $msg, $lines, $data );
	}

	public function args_schema(): array {
		return [];
	}

	public function side_effects(): string {
		return 'none';
	}

	/**
	 * Print failsafe state.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb failsafe status
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( $assoc_args );
		foreach ( $result->lines as $line ) {
			\WP_CLI::line( $line );
		}
	}
}
