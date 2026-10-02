<?php
declare(strict_types=1);
/**
 * Privacy-first mail delivery log repository.
 *
 * The log deliberately stores no message body, attachment content, API keys,
 * SMTP passwords, authorization headers, or other secret material.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Mail\Log;

use CB\Core\Database\SchemaRegistry;
use CB\Core\Governance\RetentionStoreRegistry;
use CB\Core\Mail\Settings;

defined( 'ABSPATH' ) || exit;

final class Repository {

	public const TABLE = 'cb_core_mail_log';
	public const DB_VERSION = '1.0';

	public static function register_schema(): void {
		SchemaRegistry::register_base( [
			'id'         => 'mail-log',
			'version'    => self::DB_VERSION,
			'option_key' => 'cb_core_mail_log_db_version',
			'tables'     => [ [ __CLASS__, 'table' ] ],
			'install'    => [ __CLASS__, 'install' ],
		] );
	}

	public static function register_retention_store(): void {
		RetentionStoreRegistry::register( [
			'id'           => 'core-mail-log',
			'label'        => __( 'Mail logs', 'core-blueprint' ),
			'days'         => Settings::retention_days(),
			'prune'        => [ __CLASS__, 'prune' ],
			'settings_url' => admin_url( 'admin.php?page=core-blueprint-mail&tab=settings' ),
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
  id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  status              VARCHAR(20)         NOT NULL DEFAULT '',
  provider            VARCHAR(30)         NOT NULL DEFAULT '',
  transport           VARCHAR(30)         NOT NULL DEFAULT '',
  from_email          VARCHAR(320)        NOT NULL DEFAULT '',
  from_name           VARCHAR(190)        NOT NULL DEFAULT '',
  recipients          LONGTEXT                     DEFAULT NULL,
  cc                  LONGTEXT                     DEFAULT NULL,
  bcc                 LONGTEXT                     DEFAULT NULL,
  reply_to            LONGTEXT                     DEFAULT NULL,
  subject             TEXT                         DEFAULT NULL,
  provider_message_id VARCHAR(190)                 DEFAULT NULL,
  error_code          VARCHAR(100)                 DEFAULT NULL,
  error_message       TEXT                         DEFAULT NULL,
  attachment_count    SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  embed_count         SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  duration_ms         INT(10) UNSIGNED      NOT NULL DEFAULT 0,
  is_test             TINYINT(1) UNSIGNED   NOT NULL DEFAULT 0,
  created_at          DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY provider (provider),
  KEY created_at (created_at)
) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function insert( array $row ): int|false {
		global $wpdb;

		$result = $wpdb->insert(
			self::table(),
			[
				'status'              => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'provider'            => sanitize_key( (string) ( $row['provider'] ?? '' ) ),
				'transport'           => sanitize_key( (string) ( $row['transport'] ?? '' ) ),
				'from_email'          => sanitize_email( (string) ( $row['from_email'] ?? '' ) ),
				'from_name'           => sanitize_text_field( (string) ( $row['from_name'] ?? '' ) ),
				'recipients'          => self::encode_addresses( $row['recipients'] ?? [] ),
				'cc'                  => self::encode_addresses( $row['cc'] ?? [] ),
				'bcc'                 => self::encode_addresses( $row['bcc'] ?? [] ),
				'reply_to'            => self::encode_addresses( $row['reply_to'] ?? [] ),
				'subject'             => sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
				'provider_message_id' => self::sanitize_message_id( $row['provider_message_id'] ?? '' ),
				'error_code'          => sanitize_key( (string) ( $row['error_code'] ?? '' ) ),
				'error_message'       => sanitize_text_field( (string) ( $row['error_message'] ?? '' ) ),
				'attachment_count'    => max( 0, (int) ( $row['attachment_count'] ?? 0 ) ),
				'embed_count'         => max( 0, (int) ( $row['embed_count'] ?? 0 ) ),
				'duration_ms'         => max( 0, (int) ( $row['duration_ms'] ?? 0 ) ),
				'is_test'             => ! empty( $row['is_test'] ) ? 1 : 0,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ]
		);

		return false === $result ? false : (int) $wpdb->insert_id;
	}

	public static function query( array $args = [] ): array {
		global $wpdb;

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 200, max( 1, (int) ( $args['per_page'] ?? 50 ) ) );
		$where    = [ '1=1' ];
		$params   = [];

		if ( ! empty( $args['status'] ) && in_array( $args['status'], [ 'sent', 'failed' ], true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['provider'] ) && in_array( $args['provider'], Settings::PROVIDERS, true ) ) {
			$where[]  = 'provider = %s';
			$params[] = $args['provider'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['since'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = '(subject LIKE %s OR recipients LIKE %s OR error_message LIKE %s OR provider_message_id LIKE %s)';
			array_push( $params, $like, $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );
		$table     = self::table();
		$count_sql = "SELECT COUNT(*) FROM %i WHERE {$where_sql}";
		$data_sql  = "SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		$count_prepared = $wpdb->prepare( $count_sql, ...array_merge( [ $table ], $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE fragments are fixed; identifier and values are bound here.
		$total = (int) $wpdb->get_var( $count_prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above.

		$data_params   = array_merge( [ $table ], $params, [ $per_page, ( $page - 1 ) * $per_page ] );
		$data_prepared = $wpdb->prepare( $data_sql, ...$data_params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE fragments are fixed; identifier and values are bound here.
		$rows          = $wpdb->get_results( $data_prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above.

		foreach ( $rows as $row ) {
			$row->recipients_decoded = self::decode_addresses( $row->recipients ?? '' );
			$row->cc_decoded         = self::decode_addresses( $row->cc ?? '' );
			$row->bcc_decoded        = self::decode_addresses( $row->bcc ?? '' );
			$row->reply_to_decoded   = self::decode_addresses( $row->reply_to ?? '' );
		}

		return [
			'rows'        => $rows,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		];
	}

	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier is bound via %i.
	}

	public static function clear(): int {
		global $wpdb;
		$table = self::table();
		$rows  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier is bound via %i.
		if ( $rows > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier is bound via %i.
		}
		return $rows;
	}

	public static function prune( int $days ): int {
		global $wpdb;
		$days = max( 0, $days );
		if ( $days < 1 ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table = self::table();
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff ) );
		return false === $result ? 0 : (int) $result;
	}

	private static function sanitize_message_id( mixed $value ): string {
		$value = trim( (string) $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?? '';
		return substr( $value, 0, 190 );
	}

	private static function encode_addresses( mixed $addresses ): ?string {
		if ( ! is_array( $addresses ) || empty( $addresses ) ) {
			return null;
		}
		$clean = [];
		foreach ( $addresses as $address ) {
			if ( ! is_array( $address ) || empty( $address['email'] ) ) {
				continue;
			}
			$email = sanitize_email( (string) $address['email'] );
			if ( ! is_email( $email ) ) {
				continue;
			}
			$clean[] = [
				'email' => $email,
				'name'  => sanitize_text_field( (string) ( $address['name'] ?? '' ) ),
			];
		}
		return empty( $clean ) ? null : wp_json_encode( $clean );
	}

	private static function decode_addresses( mixed $encoded ): array {
		$decoded = json_decode( (string) $encoded, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
