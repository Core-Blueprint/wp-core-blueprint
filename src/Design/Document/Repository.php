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
		if ( ! Schema::healthy() ) {
			return [ 'status' => 'unavailable' ];
		}
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
		if ( 1 !== $affected ) {
			$after = self::read( $type, $key );
			return [ 'status' => 'found' === $after['status'] ? 'conflict' : 'error' ];
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
			'UPDATE %i SET document_json = %s, revision = revision + 1, updated_at = %s WHERE identity_hash = %s AND document_type = %s AND document_key = %s AND revision = %s',
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

	/** Reset is a versioned overwrite, never a delete/recreate ABA cycle. */
	public static function reset( string $type, string $key, string $expected, array $canonical_default ): array {
		return self::save( $type, $key, $expected, $canonical_default );
	}

	/** @return array{status:string,revision?:string,document?:array<string,mixed>} */
	private static function read( string $type, string $key ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT identity_hash, document_type, document_key, payload_schema, revision, document_json FROM %i WHERE identity_hash = %s LIMIT 1',
			Schema::table_name(), self::identity_hash( $type, $key )
		), ARRAY_A );
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
			return [ 'status' => 'corrupt' ];
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

	/** Foundation checks shape/size; consumers will additionally run domain validators. */
	public static function valid_document( string $type, array $document ): bool {
		if ( self::MAIL === $type ) {
			if ( ! isset( $document['subject'], $document['project'] )
				|| ! is_string( $document['subject'] ) || '' === trim( $document['subject'] )
				|| ! is_array( $document['project'] ) || array_is_list( $document['project'] ) ) {
				return false;
			}
		} elseif ( self::REPORTS === $type ) {
			if ( ! isset( $document['branding'], $document['composer'] )
				|| ! is_array( $document['branding'] ) || ! is_array( $document['composer'] )
				|| array_is_list( $document['branding'] ) || array_is_list( $document['composer'] ) ) {
				return false;
			}
		} else {
			return false;
		}
		$encoded = self::encode( $document );
		return null !== $encoded && strlen( $encoded ) <= ( self::MAIL === $type ? self::MAX_MAIL_BYTES : self::MAX_REPORT_BYTES );
	}

	private static function encode( array $document ): ?string {
		$json = wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) && '' !== $json ? $json : null;
	}

	private static function identity_hash( string $type, string $key ): string {
		return hash( 'sha256', $type . "\0" . $key );
	}

	private function __construct() {}
}
