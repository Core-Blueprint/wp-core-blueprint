<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Internal, cache-independent CAS repository for Base-owned design documents.
 *
 * Domain authorization and full Mail/Reports normalization remain the consumer
 * adapter's responsibility (G1d-A2.3). No first-party consumer is switched in
 * A2.1. Never expose arbitrary document writes through AJAX/REST/Abilities.
 *
 * @internal
 */
final class Repository {
	public const MAIL = 'base.mail-template';
	public const REPORTS = 'base.maintenance-report-design';
	private const MAX_MAIL_BYTES = 4194304;
	private const MAX_REPORT_BYTES = 262144;

	/** @return array{status:string,revision?:string,document?:array<string,mixed>} */
	public static function get( string $type, string $key ): array {
		if ( ! self::valid_identity( $type, $key ) ) {
			return [ 'status' => 'invalid' ];
		}
		// Base owns schema reconciliation. A real SELECT distinguishes a missing
		// document from an absent/unreadable table without repeating expensive
		// information_schema inspections on every Designer context request.
		// Writer paths still require Schema::healthy() before any mutation.
		return self::read( $type, $key );
	}

	/** Create once at logical version "0" through a unique identity index. */
	public static function create( string $type, string $key, string $expected, array $document ): array {
		if ( ! self::valid_identity( $type, $key ) || '0' !== $expected || ! self::valid_document( $type, $document ) ) {
			return [ 'status' => 'invalid' ];
		}
		if ( ! Schema::healthy() ) {
			return [ 'status' => 'unavailable' ];
		}
		$current = self::read( $type, $key );
		if ( 'missing' !== $current['status'] ) {
			return [ 'status' => 'found' === $current['status'] ? 'conflict' : $current['status'] ];
		}
		$encoded = self::encode( $document );
		if ( null === $encoded ) {
			return [ 'status' => 'invalid' ];
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$affected = $wpdb->query( $wpdb->prepare(
			'INSERT INTO %i (identity_hash, document_type, document_key, payload_schema, revision, document_json, created_at, updated_at) VALUES (%s, %s, %s, 1, 1, %s, %s, %s)',
			Schema::table_name(), self::identity_hash( $type, $key ), $type, $key, $encoded, $now, $now
		) );
		// Capture the numeric driver code BEFORE the follow-up SELECT can
		// overwrite mysqli's error state. Never return raw SQL, last_error,
		// document content or DB connection details to the REST consumer.
		$db_errno = 1 !== $affected ? self::safe_mysql_errno() : null;
		if ( 1 !== $affected ) {
			$after = self::read( $type, $key );
			if ( 'found' === $after['status'] ) {
				return [ 'status' => 'conflict' ];
			}
			return null !== $db_errno
				? [ 'status' => 'error', 'db_errno' => $db_errno ]
				: [ 'status' => 'error' ];
		}
		return self::verify( $type, $key, '1', $document );
	}

	/** Existing-row atomic CAS; concurrent writes may not share a winner. */
	public static function save( string $type, string $key, string $expected, array $document ): array {
		if ( ! self::valid_identity( $type, $key ) || ! self::valid_revision( $expected, false )
			|| ! self::valid_document( $type, $document ) ) {
			return [ 'status' => 'invalid' ];
		}
		if ( ! Schema::healthy() ) {
			return [ 'status' => 'unavailable' ];
		}
		$current = self::read( $type, $key );
		if ( 'found' !== $current['status'] ) {
			return [ 'status' => 'missing' === $current['status'] ? 'conflict' : $current['status'] ];
		}
		if ( $expected !== $current['revision'] ) {
			return [ 'status' => 'conflict' ];
		}
		if ( $expected === (string) PHP_INT_MAX ) {
			return [ 'status' => 'invalid' ];
		}
		$encoded = self::encode( $document );
		if ( null === $encoded ) {
			return [ 'status' => 'invalid' ];
		}
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET document_json = %s, revision = revision + 1, updated_at = %s WHERE identity_hash = %s AND document_type = %s AND document_key = %s AND revision = CAST(%s AS UNSIGNED)',
			Schema::table_name(), $encoded, gmdate( 'Y-m-d H:i:s' ), self::identity_hash( $type, $key ), $type, $key, $expected
		) );
		if ( false === $affected ) {
			return [ 'status' => 'error' ];
		}
		if ( 1 !== $affected ) {
			return [ 'status' => 'conflict' ];
		}
		$next = (string) ( (int) $expected + 1 );
		return self::verify( $type, $key, $next, $document );
	}

	/**
	 * Internal transaction-only read on the SAME wpdb connection used by the
	 * canonical Repository and the operation ledger. Never publish this as
	 * an AJAX/REST writer or call it outside TransactionalSave's transaction.
	 *
	 * @return array{status:string,revision?:string,document?:array<string,mixed>}
	 */
	public static function read_for_update( string $type, string $key ): array {
		if ( ! self::valid_identity( $type, $key ) || ! self::in_transaction() ) {
			return [ 'status' => 'unavailable' ];
		}
		return self::read( $type, $key, true );
	}

	/**
	 * Delegate the single write to the EXISTING Repository CAS/create logic.
	 * Caller must already have locked/checked the same resource and performed
	 * full consumer normalization plus authorization. Never commit here.
	 */
	public static function write_in_transaction( string $type, string $key, string $expected, array $document ): array {
		if ( ! self::in_transaction() || ! self::valid_identity( $type, $key )
			|| ! self::valid_revision( $expected, true )
			|| ! self::valid_document( $type, $document ) ) {
			return [ 'status' => 'invalid' ];
		}
		$locked = self::read( $type, $key, true );
		if ( '0' === $expected ) {
			if ( 'missing' !== ( $locked['status'] ?? null ) ) {
				return [ 'status' => 'conflict' ];
			}
			return self::create( $type, $key, $expected, $document );
		}
		if ( 'found' !== ( $locked['status'] ?? null ) || $expected !== $locked['revision'] ) {
			return [ 'status' => 'conflict' ];
		}
		return self::save( $type, $key, $expected, $document );
	}

	/** Authoritative post-CAS readback while retaining the held row lock. */
	public static function verify_in_transaction( string $type, string $key, string $revision, array $document ): bool {
		if ( ! self::in_transaction() || ! self::valid_revision( $revision, false ) ) {
			return false;
		}
		$stored = self::read( $type, $key, true );
		return 'found' === ( $stored['status'] ?? null )
			&& ( $stored['revision'] ?? null ) === $revision
			&& self::encode( $stored['document'] ) === self::encode( $document );
	}

	/**
	 * Numeric MySQL errno only, bounded for administrator-only diagnostics.
	 * Must be called immediately after the failed writer query. A missing
	 * mysqli driver returns no code; never parse raw $wpdb->last_error.
	 */
	private static function safe_mysql_errno(): ?int {
		global $wpdb;
		if ( ! isset( $wpdb->dbh ) || ! $wpdb->dbh instanceof \mysqli ) {
			return null;
		}
		try {
			$errno = mysqli_errno( $wpdb->dbh );
			return $errno >= 1 && $errno <= 65535 ? $errno : null;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	private static function in_transaction(): bool {
		global $wpdb;
		$wpdb->last_error = '';
		$flag = $wpdb->get_var( 'SELECT @@in_transaction' );
		return '' === (string) $wpdb->last_error && '1' === (string) $flag;
	}

	/** Reset is a versioned overwrite, never a delete/recreate ABA cycle. */
	public static function reset( string $type, string $key, string $expected, array $canonical_default ): array {
		return self::save( $type, $key, $expected, $canonical_default );
	}

	/** @return array{status:string,revision?:string,document?:array<string,mixed>} */
	private static function read( string $type, string $key, bool $lock = false ): array {
		global $wpdb;
		$query = $wpdb->prepare(
			'SELECT identity_hash, document_type, document_key, payload_schema, revision, document_json FROM %i WHERE identity_hash = %s LIMIT 1',
			Schema::table_name(), self::identity_hash( $type, $key )
		);
		$row = $wpdb->get_row( $query . ( $lock ? ' FOR UPDATE' : '' ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return [ 'status' => '' === (string) $wpdb->last_error ? 'missing' : 'error' ];
		}
		if ( ! hash_equals( self::identity_hash( $type, $key ), (string) ( $row['identity_hash'] ?? '' ) )
			|| $type !== (string) ( $row['document_type'] ?? '' )
			|| $key !== (string) ( $row['document_key'] ?? '' )
			|| '1' !== (string) ( $row['payload_schema'] ?? '' )
			|| ! self::valid_revision( (string) ( $row['revision'] ?? '' ), false ) ) {
			return [ 'status' => 'corrupt' ];
		}
		try {
			$decoded = json_decode( (string) ( $row['document_json'] ?? '' ), true, 128, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			return [ 'status' => 'corrupt' ];
		}
		if ( ! is_array( $decoded ) || ! self::valid_document( $type, $decoded ) ) {
			// Existing invalid/experimental data must not be mistaken for a
			// missing row: context loading and create-on-zero both refuse it.
			return [ 'status' => 'unsupported' ];
		}
		return [ 'status' => 'found', 'revision' => (string) $row['revision'], 'document' => $decoded ];
	}

	/** A verified readback is mandatory; a later writer may already have won. */
	private static function verify( string $type, string $key, string $revision, array $submitted ): array {
		$read = self::read( $type, $key );
		if ( 'found' !== $read['status'] || $revision !== $read['revision']
			|| self::encode( $submitted ) !== self::encode( $read['document'] ) ) {
			return [ 'status' => 'uncertain' ];
		}
		return [ 'status' => 'saved', 'revision' => $revision, 'document' => $read['document'] ];
	}

	public static function valid_identity( string $type, string $key ): bool {
		if ( self::MAIL === $type ) {
			// Exact existing TemplateRegistry/TemplateActions ID; never prefix provider.
			return strlen( $key ) <= 191
				&& 1 === preg_match( '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $key );
		}
		return self::REPORTS === $type && 'maintenance' === $key;
	}

	/** Decimal-string revisions remain lossless above JavaScript MAX_SAFE_INTEGER. */
	public static function valid_revision( string $revision, bool $allow_zero = true ): bool {
		if ( 1 !== preg_match( '/^(0|[1-9][0-9]*)$/D', $revision ) ) {
			return false;
		}
		if ( ! $allow_zero && '0' === $revision ) {
			return false;
		}
		$max = (string) PHP_INT_MAX;
		return strlen( $revision ) < strlen( $max )
			|| ( strlen( $revision ) === strlen( $max ) && strcmp( $revision, $max ) <= 0 );
	}

	/**
	 * Native Platform v1 is the sole Base Designer document contract.
	 *
	 * This repository NEVER upgrades an earlier saved development document,
	 * normalizes unknown keys or maps branding/subject into an older wrapper.
	 * Full project/field validation comes from the ONE trusted FrozenRegistry.
	 * Any absent/unapproved artifacts or invalid v1 schema fail closed.
	 */
	public static function valid_document( string $type, array $document ): bool {
		if ( ! in_array( $type, [ self::MAIL, self::REPORTS ], true )
			|| count( $document ) !== 2
			|| ! array_key_exists( 'project', $document ) || ! array_key_exists( 'fields', $document )
			|| ! is_array( $document['project'] ) || ! is_array( $document['fields'] ) ) {
			return false;
		}
		$encoded = self::encode( $document );
		if ( null === $encoded || strlen( $encoded ) > ( self::MAIL === $type ? self::MAX_MAIL_BYTES : self::MAX_REPORT_BYTES ) ) {
			return false;
		}
		try {
			return \CoreBlueprint\Core\Design\Editor\PlatformTransport::valid_native_document( $type, $document );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private static function encode( array $document ): ?string {
		// This table deliberately mixes ASCII integrity hashes with UTF-8 document
		// fields. WordPress wpdb::get_table_charset() treats that combination
		// as ASCII when validating a non-ASCII SQL statement, potentially
		// refusing a legitimate Mail INSERT before mysqli (errno remains 0).
		// Escape Unicode in the SQL-bound JSON; json_decode() recovers the
		// exact original UTF-8 values. Never disable wpdb's text checks.
		$json = wp_json_encode( $document, JSON_UNESCAPED_SLASHES );
		return is_string( $json ) && '' !== $json ? $json : null;
	}

	private static function identity_hash( string $type, string $key ): string {
		return hash( 'sha256', $type . "\0" . $key );
	}

	private function __construct() {}
}
