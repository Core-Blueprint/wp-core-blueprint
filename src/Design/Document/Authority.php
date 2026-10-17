<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only, primary-DB authority for Base-owned Mail and Reports documents.
 *
 * A prepared A2.2 site remains on legacy consumers. Only a separately
 * authorized, site-bound active cutover receipt selects canonical consumers.
 * This class cannot prepare, activate, repair or migrate a site.
 *
 * @internal
 */
final class Authority {
	public const ACTIVE_OPTION = 'cb_core_design_docs_cutover';
	public const EVER_ACTIVE_OPTION = 'cb_core_design_docs_ever_active_v1';

	/** @return array{status:string,fingerprint?:string,target_hash?:string} */
	public static function inspect(): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! is_string( $wpdb->options ) || '' === $wpdb->options
			|| (int) get_current_blog_id() <= 0 ) {
			return [ 'status' => 'blocked' ];
		}

		// Never use a cached get_option() value for storage authority.
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT option_value, autoload FROM %i WHERE option_name = %s LIMIT 1',
				$wpdb->options,
				self::ACTIVE_OPTION
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return [ 'status' => 'blocked' ];
		}
		$wpdb->last_error = '';
		$witness_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT option_value, autoload FROM %i WHERE option_name = %s LIMIT 1',
				$wpdb->options,
				self::EVER_ACTIVE_OPTION
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return [ 'status' => 'blocked' ];
		}
		if ( null === $row ) {
			// Never downgrade a previously activated site if the active marker goes missing.
			if ( null !== $witness_row ) {
				return [ 'status' => 'blocked' ];
			}
			// An A2.2 prepare marker never selects canonical live authority.
			$prepared = MigrationInventory::read_option( Migration::PREPARED_OPTION );
			if ( 'ok' !== ( $prepared['status'] ?? '' ) ) {
				return [ 'status' => 'blocked' ];
			}
			if ( null === $prepared['option'] ) {
				return [ 'status' => 'legacy' ];
			}
			$receipt = self::prepared_receipt( $prepared['option'] );
			return null === $receipt ? [ 'status' => 'blocked' ] : [ 'status' => 'prepared' ];
		}
		if ( ! is_array( $row ) || ! in_array( $row['autoload'] ?? null, [ 'no', 'off' ], true )
			|| ! is_string( $row['option_value'] ?? null ) ) {
			return [ 'status' => 'blocked' ];
		}

		try {
			$active = json_decode( $row['option_value'], true, 16, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return [ 'status' => 'blocked' ];
		}
		if ( ! self::valid_receipt( $active, 'active' )
			|| ! is_array( $witness_row )
			|| ! in_array( $witness_row['autoload'] ?? null, [ 'no', 'off' ], true ) ) {
			return [ 'status' => 'blocked' ];
		}
		try {
			$witness = json_decode( (string) ( $witness_row['option_value'] ?? '' ), true, 16, JSON_THROW_ON_ERROR );
		} catch ( \\Throwable $error ) {
			return [ 'status' => 'blocked' ];
		}
		if ( ! self::valid_receipt( $witness, 'ever-active' )
			|| ! hash_equals( $active['fingerprint'], $witness['fingerprint'] )
			|| ! hash_equals( $active['target_hash'], $witness['target_hash'] ) ) {
			return [ 'status' => 'blocked' ];
		}
		$prepared = MigrationInventory::read_option( Migration::PREPARED_OPTION );
		if ( 'ok' !== ( $prepared['status'] ?? '' ) || null === $prepared['option'] ) {
			return [ 'status' => 'blocked' ];
		}
		$receipt = self::prepared_receipt( $prepared['option'] );
		if ( null === $receipt
			|| ! hash_equals( $receipt['fingerprint'], $active['fingerprint'] )
			|| ! hash_equals( $receipt['target_hash'], $active['target_hash'] ) ) {
			return [ 'status' => 'blocked' ];
		}
		// Deliberately do not call Migration::freshness() here. A legitimate
		// post-cutover save advances document revisions beyond initial "1".
		return [
			'status' => 'active',
			'fingerprint' => $active['fingerprint'],
			'target_hash' => $active['target_hash'],
		];
	}

	/**
	 * Prove a missing Mail row is a real, never-customized provider default.
	 * The A2.2 recovery snapshot is the durable inventory of all historic IDs.
	 * Missing, corrupt or mismatched evidence must NOT create a default row.
	 */
	public static function uncustomized_mail_id( string $id, array $authority ): bool {
		if ( 'active' !== ( $authority['status'] ?? '' )
			|| ! Repository::valid_identity( Repository::MAIL, $id ) ) {
			return false;
		}
		$backup = MigrationBackup::read_verified();
		if ( 'found' !== ( $backup['status'] ?? '' )
			|| ! hash_equals( (string) ( $authority['fingerprint'] ?? '' ), (string) ( $backup['fingerprint'] ?? '' ) )
			|| ! hash_equals( (string) ( $authority['target_hash'] ?? '' ), (string) ( $backup['target_hash'] ?? '' ) ) {
			return false;
		}
		$entry = $backup['source'][\CoreBlueprint\Core\Mail\Designer\TemplateRepository::OPTION] ?? null;
		if ( null === $entry ) {
			return true;
		}
		if ( ! is_array( $entry ) || ! is_string( $entry['option_value'] ?? null ) ) {
			return false;
		}
		try {
			$decoded = maybe_unserialize( $entry['option_value'] );
		} catch ( \Throwable $error ) {
			return false;
		}
		return is_array( $decoded ) && ! array_key_exists( $id, $decoded );
	}

	/** @param mixed $receipt */
	private static function valid_receipt( mixed $receipt, string $state ): bool {
		global $wpdb;
		return is_array( $receipt )
			&& 1 === ( $receipt['version'] ?? null )
			&& $state === ( $receipt['state'] ?? null )
			&& get_current_blog_id() === ( $receipt['blog_id'] ?? null )
			&& $wpdb->options === ( $receipt['options_table'] ?? null )
			&& is_string( $receipt['fingerprint'] ?? null )
			&& is_string( $receipt['target_hash'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $receipt['fingerprint'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $receipt['target_hash'] );
	}

	/** @param array<string,mixed> $row */
	private static function prepared_receipt( array $row ): ?array {
		if ( ! in_array( $row['autoload'] ?? null, [ 'no', 'off' ], true ) ) {
			return null;
		}
		try {
			$receipt = json_decode( (string) ( $row['option_value'] ?? '' ), true, 16, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! self::valid_receipt( $receipt, 'prepared' )
			|| ! is_int( $receipt['document_count'] ?? null )
			|| $receipt['document_count'] < 1 ) {
			return null;
		}
		return $receipt;
	}

	private function __construct() {}
}
