<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Explicit, internal, PREPARE-ONLY R-C migration operation.
 *
 * Never hooked to boot, activation, cron, REST, AJAX or CLI. No active cutover
 * marker exists in this class. Legacy consumers remain sole live authority.
 * Invocation even on a test database requires a separate operator DB GO.
 *
 * @internal
 */
final class Migration {
	public const PREPARED_OPTION = 'cb_core_design_docs_prepared_v1';

	/** @return array{status:string} */
	public static function prepare(): array {
		if ( ! Schema::healthy() ) {
			return [ 'status' => 'unavailable' ];
		}
		$before = MigrationInventory::inspect();
		if ( 'ok' !== $before['status'] ) {
			return [ 'status' => $before['status'] ];
		}
		$marker = self::read_marker();
		if ( 'prepared' === $marker['status'] ) {
			return self::freshness();
		}
		if ( 'missing' !== $marker['status'] ) {
			return [ 'status' => $marker['status'] ];
		}
		$backup = MigrationBackup::ensure( $before );
		if ( 'ready' !== $backup['status'] ) {
			return [ 'status' => $backup['status'] ];
		}

		global $wpdb;
		// Never join/commit another component's transaction.
		$wpdb->last_error = '';
		$in_transaction = $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( null === $in_transaction || '' !== (string) $wpdb->last_error
			|| '0' !== (string) $in_transaction ) {
			return [ 'status' => 'unavailable' ];
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return [ 'status' => 'error' ];
		}
		$started = true;
		try {
			// Option row locks are acquired in a stable order, including backup
			// and marker. Absent source rows have no lock: later A2.3 MUST
			// drain old writers and re-read source under its own barrier.
			$locked = MigrationInventory::inspect( true );
			if ( 'ok' !== $locked['status'] ) {
				return [ 'status' => 'invalid_source' ];
			}
			if ( $locked['fingerprint'] !== $before['fingerprint']
				|| $locked['target_hash'] !== $before['target_hash'] ) {
				return [ 'status' => 'source_changed' ];
			}
			$locked_backup = MigrationInventory::read_option( MigrationBackup::OPTION, true );
			$locked_marker = MigrationInventory::read_option( self::PREPARED_OPTION, true );
			if ( 'ok' !== $locked_backup['status'] || null === $locked_backup['option']
				|| 'ok' !== $locked_marker['status'] || null !== $locked_marker['option'] ) {
				return [ 'status' => 'conflict' ];
			}
			$verified_backup = MigrationBackup::read_verified();
			if ( 'found' !== $verified_backup['status']
				|| $verified_backup['fingerprint'] !== $locked['fingerprint']
				|| $verified_backup['target_hash'] !== $locked['target_hash']
				|| $verified_backup['source'] !== $locked['source'] ) {
				return [ 'status' => 'source_changed' ];
			}
			if ( ! self::target_set_empty() ) {
				return [ 'status' => 'conflict' ];
			}
			foreach ( $locked['documents'] as $item ) {
				$result = Repository::create( $item['type'], $item['key'], '0', $item['document'] );
				if ( 'saved' !== $result['status'] ) {
					return [ 'status' => $result['status'] ];
				}
			}
			if ( ! self::matches_targets( $locked['documents'], true ) ) {
				return [ 'status' => 'uncertain' ];
			}
			$receipt = [
				'version' => 1,
				'state' => 'prepared',
				'blog_id' => $locked['blog_id'],
				'options_table' => $locked['options_table'],
				'fingerprint' => $locked['fingerprint'],
				'target_hash' => $locked['target_hash'],
				'document_count' => count( $locked['documents'] ),
			];
			$json = wp_json_encode( $receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $json ) ) {
				return [ 'status' => 'invalid' ];
			}
			$affected = $wpdb->query( $wpdb->prepare(
				'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				$wpdb->options, self::PREPARED_OPTION, $json, 'no'
			) );
			if ( 1 !== $affected || ! self::matches_targets( $locked['documents'], true ) ) {
				return [ 'status' => 'uncertain' ];
			}
			$read_marker = self::read_marker();
			if ( 'prepared' !== $read_marker['status']
				|| $read_marker['fingerprint'] !== $locked['fingerprint']
				|| $read_marker['target_hash'] !== $locked['target_hash']
				|| $read_marker['document_count'] !== count( $locked['documents'] ) ) {
				return [ 'status' => 'uncertain' ];
			}
			$commit = $wpdb->query( 'COMMIT' );
			$started = false; // Even a failed COMMIT has an uncertain outcome.
			if ( false === $commit ) {
				return [ 'status' => 'uncertain' ];
			}
		} catch ( \Throwable $error ) {
			return [ 'status' => 'error' ];
		} finally {
			if ( $started ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
		// Outside the transaction, confirm readback; never acknowledge a stale
		// preparation after a later legacy write has already occurred.
		return self::freshness();
	}

	/**
	 * Read-only. A2.3 must repeat this entire check under an approved drain
	 * barrier immediately before a separately implemented ACTIVE cutover.
	 *
	 * @return array{status:string}
	 */
	public static function freshness(): array {
		$marker = self::read_marker();
		if ( 'prepared' !== $marker['status'] ) {
			return [ 'status' => $marker['status'] ];
		}
		$current = MigrationInventory::inspect();
		if ( 'ok' !== $current['status'] ) {
			return [ 'status' => $current['status'] ];
		}
		if ( $marker['fingerprint'] !== $current['fingerprint']
			|| $marker['target_hash'] !== $current['target_hash']
			|| $marker['document_count'] !== count( $current['documents'] ) ) {
			return [ 'status' => 'source_changed' ];
		}
		$backup = MigrationBackup::read_verified();
		if ( 'found' !== $backup['status']
			|| $backup['fingerprint'] !== $current['fingerprint']
			|| $backup['target_hash'] !== $current['target_hash']
			|| $backup['source'] !== $current['source'] ) {
			return [ 'status' => 'source_changed' ];
		}
		return self::matches_targets( $current['documents'], true )
			? [ 'status' => 'prepared' ] : [ 'status' => 'target_changed' ];
	}

	/** @return array<string,mixed> */
	private static function read_marker(): array {
		$row = MigrationInventory::read_option( self::PREPARED_OPTION );
		if ( 'ok' !== $row['status'] ) {
			return [ 'status' => 'error' ];
		}
		if ( null === $row['option'] ) {
			return [ 'status' => 'missing' ];
		}
		if ( ! in_array( $row['option']['autoload'], [ 'no', 'off' ], true ) ) {
			return [ 'status' => 'corrupt' ];
		}
		try {
			$marker = json_decode( $row['option']['option_value'], true, 8, JSON_THROW_ON_ERROR );
			global $wpdb;
			if ( ! is_array( $marker )
				|| 1 !== ( $marker['version'] ?? null )
				|| 'prepared' !== ( $marker['state'] ?? null )
				|| (int) get_current_blog_id() !== ( $marker['blog_id'] ?? null )
				|| $wpdb->options !== ( $marker['options_table'] ?? null )
				|| ! is_int( $marker['document_count'] ?? null )
				|| $marker['document_count'] < 1
				|| ! is_string( $marker['fingerprint'] ?? null )
				|| ! is_string( $marker['target_hash'] ?? null )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $marker['fingerprint'] )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $marker['target_hash'] ) ) {
				return [ 'status' => 'corrupt' ];
			}
			return [
				'status' => 'prepared',
				'fingerprint' => $marker['fingerprint'],
				'target_hash' => $marker['target_hash'],
				'document_count' => $marker['document_count'],
			];
		} catch ( \Throwable $error ) {
			return [ 'status' => 'corrupt' ];
		}
	}

	private static function target_set_empty(): bool {
		global $wpdb;
		$wpdb->last_error = '';
		$count = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE document_type IN (%s, %s)',
			Schema::table_name(), Repository::MAIL, Repository::REPORTS
		) );
		return '' === (string) $wpdb->last_error && null !== $count && '0' === (string) $count;
	}

	/** @param array<string,array<string,mixed>> $documents */
	private static function matches_targets( array $documents, bool $require_initial_revision ): bool {
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT identity_hash, document_type, document_key, revision FROM %i WHERE document_type IN (%s, %s)',
			Schema::table_name(), Repository::MAIL, Repository::REPORTS
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error || count( $rows ) !== count( $documents ) ) {
			return false;
		}
		$seen = [];
		foreach ( $rows as $row ) {
			$type = (string) ( $row['document_type'] ?? '' );
			$key = (string) ( $row['document_key'] ?? '' );
			$identity = $type . "\0" . $key;
			if ( ! isset( $documents[$identity] ) || isset( $seen[$identity] )
				|| ! hash_equals( hash( 'sha256', $identity ), (string) ( $row['identity_hash'] ?? '' ) )
				|| ( $require_initial_revision && '1' !== (string) ( $row['revision'] ?? '' ) ) ) {
				return false;
			}
			$seen[$identity] = true;
			$read = Repository::get( $type, $key );
			if ( 'found' !== $read['status']
				|| ( $require_initial_revision && '1' !== $read['revision'] )
				|| $read['document'] !== $documents[$identity]['document'] ) {
				return false;
			}
		}
		return count( $seen ) === count( $documents );
	}

	private function __construct() {}
}
