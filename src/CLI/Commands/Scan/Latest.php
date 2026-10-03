<?php
declare(strict_types=1);
/**
 * Scan\Latest - `wp cb scan latest` and Console "cb scan latest".
 *
 * Read-only - prints the most recent scan result from the integrity
 * subsystem's ResultRepository. Does not trigger a new scan.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Scan;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;

defined( 'ABSPATH' ) || exit;

final class Latest implements CommandInterface {

	public function execute( array $args ): Result {
		if ( ! class_exists( ResultRepository::class ) ) {
			return Result::error( __( 'Core Scanner subsystem not available on this site.', 'core-blueprint' ) );
		}

		if ( ! ResultRepository::hasResult() ) {
			return Result::warning(
				__( 'No scan results recorded yet. Run `wp cb scan run --user=<user>` to start one.', 'core-blueprint' ),
				[ 'No scan results recorded yet.' ]
			);
		}

		$result_data = ResultRepository::getLatest() ?? [];
		$summary     = ResultRepository::getSummary();
		$issues      = (int) ( $summary['totals']['issues'] ?? $summary['issues'] ?? 0 );

		$lines   = [];
		$lines[] = '';
		$lines[] = 'Core Blueprint - Last scan';
		$lines[] = str_repeat( '─', 40 );
		$lines[] = 'Status:        ' . (string) ( $result_data['status']       ?? 'unknown' );
		$lines[] = 'Completed at:  ' . (string) ( $result_data['completed_at'] ?? '-' );
		$lines[] = 'Source:        ' . (string) ( $result_data['source']       ?? '-' );
		$lines[] = 'Issues:        ' . $issues;
		$lines[] = '';

		$status_msg = sprintf(
			/* translators: %d: issue count */
			_n( 'Last scan completed with %d issue.', 'Last scan completed with %d issues.', $issues, 'core-blueprint' ),
			$issues
		);

		return Result::success(
			$status_msg,
			$lines,
			$result_data
		);
	}

	public function args_schema(): array {
		return [
			'format' => [
				'type'    => 'select',
				'label'   => __( 'Output format', 'core-blueprint' ),
				'default' => 'text',
				'options' => [ 'text' => 'text', 'json' => 'json', 'yaml' => 'yaml' ],
				'help'    => __( 'CLI display format. Console always renders the structured view.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string {
		return 'none';
	}

	/**
	 * Print the most recent scan result.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb scan latest
	 *     wp cb scan latest --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>     $args
	 * @param array<string, string>  $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( $assoc_args );

		if ( 'error' === $result->status ) {
			\WP_CLI::error( $result->message );
		}
		if ( 'warning' === $result->status ) {
			\WP_CLI::warning( $result->message );
			return;
		}

		$format = (string) ( $assoc_args['format'] ?? 'text' );
		$data   = $result->data ?? [];

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}
		if ( 'yaml' === $format ) {
			$summary = class_exists( ResultRepository::class ) ? ResultRepository::getSummary() : [];
			$flat    = [
				'status'       => (string) ( $data['status']       ?? 'unknown' ),
				'completed_at' => (string) ( $data['completed_at'] ?? '' ),
				'source'       => (string) ( $data['source']       ?? '' ),
				'issue_count'  => (int)    ( $summary['totals']['issues'] ?? $summary['issues'] ?? 0 ),
			];
			\WP_CLI\Utils\format_items( 'yaml', [ $flat ], array_keys( $flat ) );
			return;
		}

		foreach ( $result->lines as $line ) {
			\WP_CLI::line( $line );
		}
		\WP_CLI::line( 'Run `wp cb scan latest --format=json` for the full payload.' );
		\WP_CLI::line( '' );
	}
}
