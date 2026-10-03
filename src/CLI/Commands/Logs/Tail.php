<?php
declare(strict_types=1);
/**
 * Logs\Tail - `wp cb logs tail` and Console "cb logs".
 *
 * Read-only audit-log query. Returns the most recent N entries with
 * optional time-window, severity, and event-prefix filters.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


namespace CoreBlueprint\Core\CLI\Commands\Logs;

use CoreBlueprint\Core\Console\CommandInterface;
use CoreBlueprint\Core\Console\Result;
use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class Tail implements CommandInterface {

	public function execute( array $args ): Result {
		$limit_raw = isset( $args['limit'] ) ? (int) $args['limit'] : 20;
		$limit     = max( 1, min( $limit_raw, 200 ) );

		$since = '';
		if ( ! empty( $args['since'] ) ) {
			$since_ts = strtotime( (string) $args['since'] );
			if ( false === $since_ts ) {
				return Result::error(
					/* translators: %s: invalid since value entered on the command line. */
				sprintf( __( 'Could not parse "since" value: %s', 'core-blueprint' ), (string) $args['since'] )
				);
			}
			$since = gmdate( 'Y-m-d H:i:s', $since_ts );
		}

		$severity     = isset( $args['severity'] )     ? (string) $args['severity']     : '';
		$event_prefix = isset( $args['event-prefix'] ) ? (string) $args['event-prefix'] : '';

		$query = AuditLog::query( [
			'per_page'     => $limit,
			'page'         => 1,
			'since'        => $since,
			'severity'     => $severity,
			'event_prefix' => $event_prefix,
		] );

		$rows = self::flatten_rows( $query['rows'] ?? [] );

		if ( empty( $rows ) ) {
			return Result::warning(
				__( 'No audit log entries match the filters.', 'core-blueprint' ),
				[ 'No audit log entries match the filters.' ],
				[ 'rows' => [] ]
			);
		}

		$lines = [];
		foreach ( $rows as $row ) {
			$lines[] = sprintf(
				'%s  [%s]  %s  user=%d  %s',
				$row['created_at'],
				str_pad( $row['severity'], 8 ),
				str_pad( $row['event_type'], 36 ),
				$row['user_id'],
				$row['context']
			);
		}

		$msg = sprintf(
			/* translators: %d: number of entries */
			_n( '%d audit log entry returned.', '%d audit log entries returned.', count( $rows ), 'core-blueprint' ),
			count( $rows )
		);

		return Result::success( $msg, $lines, [ 'rows' => $rows ] );
	}

	public function args_schema(): array {
		return [
			'limit' => [
				'type'    => 'int',
				'label'   => __( 'Limit', 'core-blueprint' ),
				'default' => 20,
				'help'    => __( 'Maximum rows to return (1-200).', 'core-blueprint' ),
			],
			'since' => [
				'type'    => 'text',
				'label'   => __( 'Since', 'core-blueprint' ),
				'default' => '',
				'help'    => __( 'Earliest entry to include - accepts strtotime values like "yesterday", "2 hours ago", or "2026-04-01".', 'core-blueprint' ),
			],
			'severity' => [
				'type'    => 'select',
				'label'   => __( 'Severity', 'core-blueprint' ),
				'default' => '',
				'options' => [
					''         => __( 'Any', 'core-blueprint' ),
					'debug'    => 'debug',
					'info'     => 'info',
					'notice'   => 'notice',
					'warning'  => 'warning',
					'error'    => 'error',
					'critical' => 'critical',
				],
			],
			'event-prefix' => [
				'type'    => 'text',
				'label'   => __( 'Event prefix', 'core-blueprint' ),
				'default' => '',
				'help'    => __( 'Filter to events whose name starts with this prefix (e.g. "beacon.").', 'core-blueprint' ),
			],
		];
	}

	public function side_effects(): string {
		return 'none';
	}

	/**
	 * Tail recent audit log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Number of rows to return.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--since=<datetime>]
	 * : Earliest entry to include, in any strtotime-parseable form.
	 *
	 * [--severity=<level>]
	 * : Filter to a single severity level.
	 *
	 * [--event-prefix=<prefix>]
	 * : Filter to events whose name starts with the given prefix.
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb logs tail
	 *     wp cb logs tail --limit=50 --since=yesterday
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = $this->execute( $assoc_args );

		if ( 'error' === $result->status ) {
			\WP_CLI::error( $result->message );
		}

		$rows = is_array( $result->data['rows'] ?? null ) ? $result->data['rows'] : [];

		$format = (string) ( $assoc_args['format'] ?? 'table' );

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
			[ 'id', 'created_at', 'severity', 'event_type', 'user_id', 'context' ]
		);
	}

	/**
	 * @param array<int, object> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten_rows( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$context = isset( $row->context_decoded ) && is_array( $row->context_decoded )
				? wp_json_encode( $row->context_decoded )
				: '';
			$out[] = [
				'id'         => (int)    ( $row->id         ?? 0 ),
				'created_at' => (string) ( $row->created_at ?? '' ),
				'severity'   => (string) ( $row->severity   ?? '' ),
				'event_type' => (string) ( $row->event_type ?? '' ),
				'user_id'    => (int)    ( $row->user_id    ?? 0 ),
				'context'    => (string) $context,
			];
		}
		return $out;
	}
}
