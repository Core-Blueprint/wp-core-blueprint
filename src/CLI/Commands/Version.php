<?php
declare(strict_types=1);
/**
 * Version - `wp cb version` and Console "cb version".
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;

defined( 'ABSPATH' ) || exit;

final class Version implements CommandInterface {

	public function execute( array $args ): Result {
		$components = self::components();
		$lines      = [ '', 'Core Blueprint - Versions', str_repeat( '─', 40 ) ];
		foreach ( $components as $name => $version ) {
			$lines[] = sprintf( '  %-22s %s', $name . ':', (string) $version );
		}
		$lines[] = '';

		return Result::success(
			__( 'Core Blueprint version snapshot', 'core-blueprint' ),
			$lines,
			$components
		);
	}

	public function args_schema(): array {
		return [
			'format' => [
				'type'    => 'select',
				'label'   => __( 'Output format', 'core-blueprint' ),
				'default' => 'text',
				'options' => [ 'text' => 'text', 'json' => 'json', 'yaml' => 'yaml' ],
				'help'    => __( 'CLI display format. Console renders the structured view; CLI honours this flag.', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string {
		return 'none';
	}

	/**
	 * Print Core Blueprint version information.
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
	 *     wp cb version
	 *     wp cb version --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>     $args
	 * @param array<string, string>  $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result     = $this->execute( $assoc_args );
		$components = $result->data ?? [];
		$format     = (string) ( $assoc_args['format'] ?? 'text' );

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $components, JSON_PRETTY_PRINT ) );
			return;
		}
		if ( 'yaml' === $format ) {
			\WP_CLI\Utils\format_items( 'yaml', [ $components ], array_keys( $components ) );
			return;
		}

		foreach ( $result->lines as $line ) {
			\WP_CLI::line( $line );
		}
	}

	/** @return array<string, string> */
	private static function components(): array {
		global $wp_version;

		$components = [
			'cb_base'      => CB_CORE_VERSION,
			'cb_db_schema' => (string) get_option( 'cb_core_db_version', '-' ),
			'wordpress'    => is_string( $wp_version ?? null ) ? $wp_version : '-',
			'php'          => PHP_VERSION,
		];

		/**
		 * Filter: core_blueprint_cli_version_components
		 *
		 * Lets sibling plugins surface their own version under
		 * `wp cb version`.
		 *
		 * @param array<string, string> $components
		 */
		return (array) apply_filters( 'core_blueprint_cli_version_components', $components );
	}
}
