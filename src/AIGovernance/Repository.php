<?php
declare(strict_types=1);
/**
 * Internal canonical AI Activity datastore.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\AIGovernance;

use CB\Core\Admin\Admin;
use CB\Core\Admin\Pages\Logs\Tabs\AIActivityTab;
use CB\Core\Database\SchemaRegistry;
use CB\Core\Governance\RetentionStoreRegistry;

defined( 'ABSPATH' ) || exit;

final class Repository {
	public const TABLE = 'cb_core_ai_activity';
	public const DB_VERSION = '1.1';
	public const SCHEMA_OPTION = 'cb_core_ai_activity_db_version';

	public static function register_schema(): void {
		SchemaRegistry::register_base( [
			'id'         => 'ai-activity',
			'version'    => self::DB_VERSION,
			'option_key' => self::SCHEMA_OPTION,
			'tables'     => [ [ __CLASS__, 'table' ] ],
			'install'    => [ __CLASS__, 'install' ],
		] );
	}

	public static function register_retention_store(): void {
		RetentionStoreRegistry::register( [
			'id'           => 'core-ai-activity',
			'label'        => __( 'AI activity', 'core-blueprint' ),
			'days'         => Settings::retention_days(),
			'prune'        => [ __CLASS__, 'prune' ],
			'settings_url' => add_query_arg(
				[ 'page' => Admin::LOGS_SLUG, 'tab' => AIActivityTab::SLUG ],
				admin_url( 'admin.php' )
			) . '#retention',
		] );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
  id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_id      CHAR(36)            NOT NULL,
  correlation_id   CHAR(36)                     DEFAULT NULL,
  parent_activity_id CHAR(36)                   DEFAULT NULL,
  actor_user_id    BIGINT(20) UNSIGNED          DEFAULT NULL,
  actor_user_login VARCHAR(60)                   DEFAULT NULL,
  operation_type   VARCHAR(30)          NOT NULL DEFAULT 'operation',
  operation        VARCHAR(190)         NOT NULL DEFAULT '',
  transport        VARCHAR(30)          NOT NULL DEFAULT 'unknown',
  source_id        VARCHAR(190)                  DEFAULT NULL,
  source_label     VARCHAR(190)                  DEFAULT NULL,
  provider_id      VARCHAR(190)                  DEFAULT NULL,
  provider_label   VARCHAR(190)                  DEFAULT NULL,
  model_id         VARCHAR(190)                  DEFAULT NULL,
  model_label      VARCHAR(190)                  DEFAULT NULL,
  outcome          VARCHAR(30)          NOT NULL DEFAULT 'unknown',
  capture_state    VARCHAR(30)          NOT NULL DEFAULT 'reported',
  target_type      VARCHAR(60)                   DEFAULT NULL,
  target_id        VARCHAR(190)                  DEFAULT NULL,
  target_label     VARCHAR(190)                  DEFAULT NULL,
  duration_ms      INT(10) UNSIGNED              DEFAULT NULL,
  error_code       VARCHAR(100)                  DEFAULT NULL,
  evidence         LONGTEXT                      DEFAULT NULL,
  context          LONGTEXT                      DEFAULT NULL,
  created_at       DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at     DATETIME                      DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY activity_id (activity_id),
  KEY correlation_id (correlation_id),
  KEY parent_activity_id (parent_activity_id),
  KEY actor_user_id (actor_user_id),
  KEY operation_type (operation_type),
  KEY operation (operation),
  KEY transport (transport),
  KEY source_id (source_id),
  KEY provider_id (provider_id),
  KEY model_id (model_id),
  KEY outcome (outcome),
  KEY created_at (created_at)
) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** @param array<string,mixed> $row */
	public static function insert( array $row ): string|false {
		global $wpdb;
		$activity_id = isset( $row['activity_id'] ) && wp_is_uuid( (string) $row['activity_id'], 4 )
			? (string) $row['activity_id']
			: wp_generate_uuid4();

		$user_id = get_current_user_id();
		$user_login = null;
		if ( $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
			$user_login = $user instanceof \WP_User ? $user->user_login : null;
		}

		$evidence = isset( $row['evidence'] ) && is_array( $row['evidence'] ) ? Privacy::sanitize_context( $row['evidence'] ) : [];
		$context  = isset( $row['context'] ) && is_array( $row['context'] ) ? Privacy::sanitize_context( $row['context'] ) : [];
		$duration = array_key_exists( 'duration_ms', $row ) && null !== $row['duration_ms'] ? max( 0, (int) $row['duration_ms'] ) : null;
		$completed_at = self::sanitize_datetime( $row['completed_at'] ?? null );

		$data = [
			'activity_id'       => $activity_id,
			'correlation_id'    => self::nullable_uuid( $row['correlation_id'] ?? null ),
			'parent_activity_id' => self::nullable_uuid( $row['parent_activity_id'] ?? null ),
			'actor_user_id'    => $user_id > 0 ? $user_id : null,
			'actor_user_login' => $user_login,
			'operation_type'   => self::bounded_key( $row['operation_type'] ?? 'operation', 30, 'operation' ),
			'operation'        => self::bounded_text( $row['operation'] ?? '', 190 ),
			'transport'        => self::bounded_key( $row['transport'] ?? 'unknown', 30, 'unknown' ),
			'source_id'        => self::nullable_text( $row['source_id'] ?? null, 190 ),
			'source_label'     => self::nullable_text( $row['source_label'] ?? null, 190 ),
			'provider_id'      => self::nullable_text( $row['provider_id'] ?? null, 190 ),
			'provider_label'   => self::nullable_text( $row['provider_label'] ?? null, 190 ),
			'model_id'         => self::nullable_text( $row['model_id'] ?? null, 190 ),
			'model_label'      => self::nullable_text( $row['model_label'] ?? null, 190 ),
			'outcome'          => self::bounded_key( $row['outcome'] ?? 'unknown', 30, 'unknown' ),
			'capture_state'    => self::bounded_key( $row['capture_state'] ?? 'reported', 30, 'reported' ),
			'target_type'      => self::nullable_key( $row['target_type'] ?? null, 60 ),
			'target_id'        => self::nullable_text( $row['target_id'] ?? null, 190 ),
			'target_label'     => self::nullable_text( $row['target_label'] ?? null, 190 ),
			'duration_ms'      => $duration,
			'error_code'       => self::nullable_key( $row['error_code'] ?? null, 100 ),
			'evidence'         => [] !== $evidence ? wp_json_encode( $evidence ) : null,
			'context'          => [] !== $context ? wp_json_encode( $context ) : null,
			'completed_at'     => $completed_at,
		];

		if ( '' === $data['operation'] ) {
			return false;
		}

		$result = $wpdb->insert(
			self::table(),
			$data,
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
		return false === $result ? false : $activity_id;
	}

	/** @param array<string,mixed> $changes */
	public static function update( string $activity_id, array $changes ): bool {
		global $wpdb;
		if ( ! wp_is_uuid( $activity_id, 4 ) ) {
			return false;
		}
		$allowed = [];
		$formats = [];
		foreach ( [ 'outcome', 'capture_state' ] as $key ) {
			if ( array_key_exists( $key, $changes ) ) {
				$allowed[ $key ] = self::bounded_key( $changes[ $key ], 30, 'unknown' );
				$formats[] = '%s';
			}
		}
		if ( array_key_exists( 'duration_ms', $changes ) ) {
			$allowed['duration_ms'] = null === $changes['duration_ms'] ? null : max( 0, (int) $changes['duration_ms'] );
			$formats[] = '%d';
		}
		if ( array_key_exists( 'error_code', $changes ) ) {
			$allowed['error_code'] = self::nullable_key( $changes['error_code'], 100 );
			$formats[] = '%s';
		}
		if ( array_key_exists( 'evidence', $changes ) && is_array( $changes['evidence'] ) ) {
			$current = self::get( $activity_id );
			$existing = is_array( $current?->evidence_decoded ?? null ) ? $current->evidence_decoded : [];
			$merged = Privacy::sanitize_context( array_replace_recursive( $existing, $changes['evidence'] ) );
			$allowed['evidence'] = [] !== $merged ? wp_json_encode( $merged ) : null;
			$formats[] = '%s';
		}
		if ( array_key_exists( 'completed_at', $changes ) ) {
			$allowed['completed_at'] = self::sanitize_datetime( $changes['completed_at'] );
			$formats[] = '%s';
		}
		if ( [] === $allowed ) {
			return false;
		}
		$result = $wpdb->update( self::table(), $allowed, [ 'activity_id' => $activity_id ], $formats, [ '%s' ] );
		return false !== $result;
	}

	public static function get( string $activity_id ): ?object {
		global $wpdb;
		if ( ! wp_is_uuid( $activity_id, 4 ) ) {
			return null;
		}
		$table = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE activity_id = %s LIMIT 1", $activity_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decode_row( $row ) : null;
	}

	/** @return array{rows:array<int,object>,total:int,page:int,per_page:int,total_pages:int} */
	public static function query( array $args = [] ): array {
		global $wpdb;
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 200, max( 1, (int) ( $args['per_page'] ?? 50 ) ) );
		$where = [ '1=1' ];
		$params = [];

		foreach ( [ 'since' => 'created_at >= %s', 'until' => 'created_at <= %s' ] as $key => $sql ) {
			$value = self::sanitize_datetime( $args[ $key ] ?? null );
			if ( null !== $value ) {
				$where[] = $sql;
				$params[] = $value;
			}
		}
		if ( ! empty( $args['actor'] ) ) {
			$where[] = 'actor_user_id = %d';
			$params[] = max( 0, (int) $args['actor'] );
		}
		if ( ! empty( $args['source'] ) ) {
			$where[] = 'source_id = %s';
			$params[] = self::bounded_text( $args['source'], 190 );
		}
		if ( ! empty( $args['type'] ) ) {
			$type = sanitize_key( (string) $args['type'] );
			if ( in_array( $type, Activity::OPERATION_TYPES, true ) ) {
				$where[] = 'operation_type = %s';
				$params[] = $type;
			}
		}
		if ( ! empty( $args['transport'] ) ) {
			$transport = sanitize_key( (string) $args['transport'] );
			if ( in_array( $transport, Activity::TRANSPORTS, true ) ) {
				$where[] = 'transport = %s';
				$params[] = $transport;
			}
		}
		foreach ( [ 'provider' => 'provider_id', 'model' => 'model_id' ] as $key => $column ) {
			if ( ! empty( $args[ $key ] ) ) {
				$where[] = $column . ' = %s';
				$params[] = self::bounded_text( $args[ $key ], 190 );
			}
		}
		if ( ! empty( $args['correlation'] ) && wp_is_uuid( (string) $args['correlation'], 4 ) ) {
			$where[] = 'correlation_id = %s';
			$params[] = (string) $args['correlation'];
		}
		if ( ! empty( $args['operation'] ) ) {
			$where[] = 'operation LIKE %s';
			$params[] = '%' . $wpdb->esc_like( self::bounded_text( $args['operation'], 190 ) ) . '%';
		}
		if ( ! empty( $args['outcome'] ) ) {
			$outcome = sanitize_key( (string) $args['outcome'] );
			if ( in_array( $outcome, Activity::OUTCOMES, true ) ) {
				$where[] = 'outcome = %s';
				$params[] = $outcome;
			}
		}

		$where_sql = implode( ' AND ', $where );
		$table = self::table();
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$data_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$count_prepared = [] === $params ? $count_sql : $wpdb->prepare( $count_sql, ...$params );
		$total = (int) $wpdb->get_var( $count_prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$data_params = array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] );
		$data_prepared = $wpdb->prepare( $data_sql, ...$data_params );
		$rows = $wpdb->get_results( $data_prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = array_map( [ __CLASS__, 'decode_row' ], is_array( $rows ) ? $rows : [] );
		return [
			'rows' => $rows,
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		];
	}

	/** @return \Generator<int,array<string,mixed>> */
	public static function rows_iterator( array $args = [] ): \Generator {
		$page = 1;
		do {
			$result = self::query( array_merge( $args, [ 'page' => $page, 'per_page' => 200 ] ) );
			foreach ( $result['rows'] as $row ) {
				yield self::export_row( $row );
			}
			++$page;
		} while ( $page <= $result['total_pages'] );
	}

	public static function prune( int $days ): int {
		global $wpdb;
		$days = max( 0, $days );
		if ( $days < 1 ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table = self::table();
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $result ? 0 : (int) $result;
	}

	private static function decode_row( object $row ): object {
		foreach ( [ 'evidence', 'context' ] as $field ) {
			$decoded = ! empty( $row->{$field} ) ? json_decode( (string) $row->{$field}, true ) : null;
			$row->{$field . '_decoded'} = is_array( $decoded ) ? $decoded : null;
		}
		return $row;
	}

	/** @return array<string,mixed> */
	private static function export_row( object $row ): array {
		return [
			'activity_id' => (string) $row->activity_id,
			'correlation_id' => $row->correlation_id,
			'parent_activity_id' => $row->parent_activity_id,
			'created_at' => (string) $row->created_at,
			'completed_at' => $row->completed_at,
			'actor_user_id' => $row->actor_user_id,
			'actor_user_login' => $row->actor_user_login,
			'operation_type' => (string) $row->operation_type,
			'operation' => (string) $row->operation,
			'transport' => (string) $row->transport,
			'source_id' => $row->source_id,
			'source_label' => $row->source_label,
			'provider_id' => $row->provider_id,
			'provider_label' => $row->provider_label,
			'model_id' => $row->model_id,
			'model_label' => $row->model_label,
			'outcome' => (string) $row->outcome,
			'capture_state' => (string) $row->capture_state,
			'target_type' => $row->target_type,
			'target_id' => $row->target_id,
			'target_label' => $row->target_label,
			'duration_ms' => $row->duration_ms,
			'error_code' => $row->error_code,
			'evidence' => $row->evidence_decoded ?? null,
			'context' => $row->context_decoded ?? null,
		];
	}

	private static function bounded_key( mixed $value, int $max, string $fallback ): string {
		$value = substr( sanitize_key( (string) $value ), 0, $max );
		return '' !== $value ? $value : $fallback;
	}

	private static function bounded_text( mixed $value, int $max ): string {
		return substr( sanitize_text_field( (string) $value ), 0, $max );
	}

	private static function nullable_uuid( mixed $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return '' !== $value && wp_is_uuid( $value, 4 ) ? $value : null;
	}

	private static function nullable_key( mixed $value, int $max ): ?string {
		$value = self::bounded_key( $value, $max, '' );
		return '' === $value ? null : $value;
	}

	private static function nullable_text( mixed $value, int $max ): ?string {
		$value = self::bounded_text( $value, $max );
		return '' === $value ? null : $value;
	}

	private static function sanitize_datetime( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$value = trim( $value );
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d H:i:s' ) === $value ? $value : null;
	}
}
