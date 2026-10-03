<?php
declare(strict_types=1);
/**
 * Operator\ListOperators - `wp cb operator list` and Console "cb operator list".
 *
 * Read-only. Lists all current cb_operator users.
 *
 * Class name avoids the PHP reserved keyword `List`.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Operator;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Permissions\Roles;

defined( 'ABSPATH' ) || exit;

final class ListOperators implements CommandInterface {

	public function execute( array $args ): Result {
		$ids = Roles::operator_ids();

		$rows = [];
		foreach ( $ids as $id ) {
			$user = get_userdata( (int) $id );
			if ( ! $user ) {
				continue;
			}
			$rows[] = [
				'ID'         => (int) $user->ID,
				'login'      => (string) $user->user_login,
				'email'      => (string) $user->user_email,
				'name'       => (string) $user->display_name,
				'registered' => (string) $user->user_registered,
			];
		}

		if ( empty( $rows ) ) {
			return Result::warning(
				__( 'No CB Operators on this site.', 'core-blueprint' ),
				[ 'No CB Operators on this site.' ],
				[ 'rows' => [], 'count' => 0 ]
			);
		}

		$lines = [
			'',
			'Core Blueprint - Operators',
			str_repeat( '─', 60 ),
		];
		foreach ( $rows as $row ) {
			$lines[] = sprintf(
				'  #%-4d  %-22s  %-30s  %s',
				$row['ID'],
				$row['login'],
				$row['email'],
				$row['name']
			);
		}
		$lines[] = '';

		$msg = sprintf(
			/* translators: %d: number of operators */
			_n( '%d Core Blueprint operator.', '%d Core Blueprint operators.', count( $rows ), 'core-blueprint' ),
			count( $rows )
		);

		return Result::success( $msg, $lines, [ 'rows' => $rows, 'count' => count( $rows ) ] );
	}

	public function args_schema(): array {
		return [
			'format' => [
				'type'    => 'select',
				'label'   => __( 'Output format', 'core-blueprint' ),
				'default' => 'table',
				'options' => [
					'table' => 'table',
					'csv'   => 'csv',
					'json'  => 'json',
					'yaml'  => 'yaml',
					'count' => 'count',
					'ids'   => 'ids',
				],
			],
		];
	}

	public function side_effects(): string {
		return 'none';
	}

	/**
	 * List all current CB Operators.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb operator list
	 *     wp cb operator list --format=ids
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( $assoc_args );

		$rows = is_array( $result->data['rows'] ?? null ) ? $result->data['rows'] : [];
		$ids  = array_map( static fn( array $r ): int => (int) $r['ID'], $rows );

		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', $ids ) );
			return;
		}
		if ( 'count' === $format ) {
			\WP_CLI::line( (string) count( $rows ) );
			return;
		}
		if ( empty( $rows ) ) {
			\WP_CLI::line( $result->message );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			[ 'ID', 'login', 'email', 'name', 'registered' ]
		);
	}
}
