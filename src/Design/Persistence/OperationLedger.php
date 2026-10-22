<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Authoritative primary-DB operation ledger. Every claim and receipt transition
 * MUST occur inside the same owned InnoDB transaction as the canonical document.
 * A missing row is never interpreted as proven no-write.
 *
 * @internal
 */
final class OperationLedger {
	/** Claim one operation before taking the document lock. */
	public function claim( SaveEnvelope $intent ): string {
		global $wpdb;
		$wpdb->last_error = '';
		$now = gmdate( 'Y-m-d H:i:s' );
		$affected = $wpdb->query( $wpdb->prepare(
			'INSERT INTO %i (blog_id, actor_id, operation_id_hash, resource_hash, resource_type, resource_key, profile_id, project_schema, registry_fingerprint, request_digest, submitted_project_digest, submitted_fields_digest, expected_revision, state, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
			OperationSchema::table_name(),
			$intent->blog_id, $intent->actor_id, self::operation_hash( $intent->operation_id ),
			self::resource_hash( $intent->resource_type, $intent->resource_key ),
			$intent->resource_type, $intent->resource_key, $intent->profile_id,
			$intent->project_schema, $intent->registry_fingerprint,
			$intent->request_digest, $intent->project_digest, $intent->fields_digest,
			$intent->expected_revision, 'pending', $now, $now
		) );
		if ( 1 === $affected ) {
			return 'claimed';
		}
		// Only a real duplicate-key database code authorizes a duplicate lookup.
		$errno = is_object( $wpdb->dbh ?? null ) ? (int) ( $wpdb->dbh->errno ?? 0 ) : 0;
		return 1062 === $errno ? 'duplicate' : 'unknown';
	}

	/**
	 * Immutable receipt bytes are written before COMMIT. No separate
	 * "operation succeeded" cache or mutable receipt reconstruction exists.
	 */
	public function mark_committed( SaveEnvelope $intent, string $accepted_revision, string $receipt_json ): bool {
		global $wpdb;
		if ( ! SaveEnvelope::decimal( $accepted_revision ) || '0' === $accepted_revision
			|| strlen( $receipt_json ) < 2 ) {
			return false;
		}
		try {
			$receipt = json_decode( $receipt_json, false, 128, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			return false;
		}
		if ( ! $receipt instanceof \stdClass
			|| ( $receipt->status ?? null ) !== 'committed'
			|| ( $receipt->operationId ?? null ) !== $intent->operation_id
			|| ( $receipt->acceptedStoreRevision ?? null ) !== $accepted_revision ) {
			return false;
		}
		$wpdb->last_error = '';
		$affected = $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET state = %s, accepted_revision = %s, receipt_json = %s, updated_at = %s WHERE blog_id = %d AND actor_id = %d AND operation_id_hash = %s AND state = %s AND request_digest = %s',
			OperationSchema::table_name(), 'committed', $accepted_revision, $receipt_json,
			gmdate( 'Y-m-d H:i:s' ), $intent->blog_id, $intent->actor_id,
			self::operation_hash( $intent->operation_id ), 'pending', $intent->request_digest
		) );
		return 1 === $affected && '' === (string) $wpdb->last_error;
	}

	/**
	 * Read-only, always from the primary connection after authorizing the caller.
	 * Original receipt is returned byte-for-byte, never recomputed from latest doc.
	 *
	 * @return array{status:string,receipt_json?:string}
	 */
	public function lookup( SaveEnvelope $intent ): array {
		global $wpdb;
		if ( ! OperationSchema::healthy() ) {
			return [ 'status' => 'unknown' ];
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT blog_id, actor_id, resource_hash, resource_type, resource_key, profile_id, project_schema, registry_fingerprint, request_digest, submitted_project_digest, submitted_fields_digest, expected_revision, accepted_revision, state, receipt_json FROM %i WHERE blog_id = %d AND actor_id = %d AND operation_id_hash = %s LIMIT 1',
			OperationSchema::table_name(), $intent->blog_id, $intent->actor_id,
			self::operation_hash( $intent->operation_id )
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return [ 'status' => 'unknown' ];
		}
		$compare = [
			'blog_id' => (string) $intent->blog_id,
			'actor_id' => (string) $intent->actor_id,
			'resource_hash' => self::resource_hash( $intent->resource_type, $intent->resource_key ),
			'resource_type' => $intent->resource_type,
			'resource_key' => $intent->resource_key,
			'profile_id' => $intent->profile_id,
			'project_schema' => $intent->project_schema,
			'registry_fingerprint' => $intent->registry_fingerprint,
			'request_digest' => $intent->request_digest,
			'submitted_project_digest' => $intent->project_digest,
			'submitted_fields_digest' => $intent->fields_digest,
			'expected_revision' => $intent->expected_revision,
		];
		foreach ( $compare as $key => $value ) {
			if ( ! isset( $row[$key] ) || ! hash_equals( $value, (string) $row[$key] ) ) {
				return [ 'status' => 'idempotency_reused' ];
			}
		}
		if ( 'expired' === $row['state'] ) {
			return [ 'status' => 'expired' ];
		}
		if ( 'committed' !== $row['state'] || ! is_string( $row['receipt_json'] )
			|| ! SaveEnvelope::decimal( (string) $row['accepted_revision'] ) ) {
			return [ 'status' => 'unknown' ];
		}
		try {
			$receipt = json_decode( $row['receipt_json'], false, 128, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			return [ 'status' => 'unknown' ];
		}
		if ( ! $receipt instanceof \stdClass
			|| ( $receipt->contractVersion ?? null ) !== 1
			|| ( $receipt->status ?? null ) !== 'committed'
			|| ( $receipt->operationId ?? null ) !== $intent->operation_id
			|| ( $receipt->acceptedStoreRevision ?? null ) !== $row['accepted_revision']
			|| ( $receipt->requestDigest ?? null ) !== $intent->request_digest
			|| ( $receipt->submittedFieldsDigest ?? null ) !== $intent->fields_digest ) {
			return [ 'status' => 'unknown' ];
		}
		return [ 'status' => 'committed', 'receipt_json' => $row['receipt_json'] ];
	}

	public static function operation_hash( string $id ): string {
		return hash( 'sha256', 'cb-save-operation-v1' . "\0" . $id );
	}

	public static function resource_hash( string $type, string $key ): string {
		return hash( 'sha256', $type . "\0" . $key );
	}
}
