<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Storage;

use RuntimeException;

use function apply_filters;
use function count;
use function get_option;
use function hash;
use function is_array;
use function is_string;
use function maybe_serialize;
use function random_bytes;
use function sanitize_option;
use function serialize;
use function str_split;
use function unserialize;
use function wp_cache_delete;

/**
 * Packet-safe storage for large Scanner state in wp_options.
 *
 * WordPress serialises an option into one SQL statement. A large integrity
 * result, active job, or per-file baseline can therefore exceed MySQL's
 * max_allowed_packet even though the option column itself is LONGTEXT.
 *
 * Immutable generations are published only after every chunk is verified.
 * A MySQL/MariaDB named mutex serializes readers, writers and complete
 * repository read-modify-write transactions across PHP workers. A durable
 * pending-generation journal makes interrupted writes recoverable on the next
 * mutation without mistaking damaged evidence for an empty snapshot.
 */
final class ChunkedOptionStore {
	private const FORMAT = 'cb_integrity_chunked_v1';
	private const CHUNK_BYTES = 196608; // 192 KiB before WP serialisation overhead.
	private const LOCK_WAIT_SECONDS = 2;
	private const PENDING_SUFFIX = '__pending';

	/** @var array<string,array{connection:object,session_id:int}> Session-fenced named locks. */
	private static array $held_locks = [];

	/**
	 * Serialize the complete read-modify-write transaction.
	 *
	 * MySQL named locks belong to a connection, not a PHP request. WordPress
	 * may reconnect after a server disconnect; the old lock would be released
	 * although this worker still has a PHP stack frame in progress. Record the
	 * actual connection handle and lock-owning session, and fail closed on any
	 * identity change (including during an ownership query).
	 */
	public static function with_lock( string $key, callable $operation ): mixed {
		global $wpdb;
		$name = self::lock_name( $key );

		if ( isset( self::$held_locks[ $name ] ) ) {
			self::assert_lock_owned( $name );
			try {
				return $operation();
			} finally {
				self::assert_lock_owned( $name );
			}
		}

		$claimed = $wpdb->get_var( $wpdb->prepare(
			'SELECT GET_LOCK(%s, %d)',
			$name,
			self::LOCK_WAIT_SECONDS
		) );
		if ( '1' !== (string) $claimed ) {
			throw new RuntimeException( 'Core Scanner storage is busy or its database mutex is unavailable.' );
		}

		$connection = $wpdb->dbh ?? null;
		if ( ! is_object( $connection ) ) {
			throw new RuntimeException( 'Core Scanner storage mutex connection is unavailable.' );
		}
		$session_id = self::owned_session_id( $name );
		if ( $session_id < 1 || $connection !== ( $wpdb->dbh ?? null ) ) {
			throw new RuntimeException( 'Core Scanner storage mutex ownership could not be verified.' );
		}

		self::$held_locks[ $name ] = [
			'connection' => $connection,
			'session_id' => $session_id,
		];
		try {
			$result = $operation();
			self::assert_lock_owned( $name );
			return $result;
		} finally {
			try {
				// A mysqli handle may survive a server-session replacement. Never
				// RELEASE_LOCK unless both the original handle and session still
				// own the named mutex.
				self::assert_lock_owned( $name );
				$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
				if ( '1' !== (string) $released || $connection !== ( $wpdb->dbh ?? null ) ) {
					throw new RuntimeException( 'Core Scanner could not release the storage mutex safely.' );
				}
			} finally {
				unset( self::$held_locks[ $name ] );
			}
		}
	}

	/**
	 * A single database query verifies both named-lock ownership and session
	 * identity; checking only the PHP-side held_locks map is never sufficient.
	 */
	private static function owned_session_id( string $name ): int {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare(
			'SELECT IF(IS_USED_LOCK(%s) = CONNECTION_ID(), CONNECTION_ID(), 0)',
			$name
		) );
		return is_numeric( $result ) && (int) $result > 0 ? (int) $result : 0;
	}

	private static function assert_lock_owned( string $name ): void {
		global $wpdb;
		$held = self::$held_locks[ $name ] ?? null;
		if ( null === $held || $held['connection'] !== ( $wpdb->dbh ?? null ) ) {
			throw new RuntimeException( 'Core Scanner storage mutex ownership was lost.' );
		}

		if (
			$held['session_id'] !== self::owned_session_id( $name )
			|| $held['connection'] !== ( $wpdb->dbh ?? null )
		) {
			throw new RuntimeException( 'Core Scanner storage mutex ownership was lost.' );
		}
	}

	private static function lock_name( string $key ): string {
		global $wpdb;
		return 'cb_integrity_' . substr( hash( 'sha256', $wpdb->options . ':' . $key ), 0, 40 );
	}

	/**
	 * All Scanner option DML carries the original MySQL session's named-lock
	 * authority *inside the SQL statement*. wpdb::query() can retry a failed
	 * statement on a new connection, so PHP checks alone cannot fence a retry.
	 * The replayed SQL's CONNECTION_ID() no longer matches this ownership token.
	 *
	 * This intentionally keeps the single GET_LOCK authority; it does not
	 * introduce a second lock/table. Scanner options are always non-autoloaded.
	 */
	private static function write_option( string $lock_key, string $option_key, mixed $value ): void {
		global $wpdb;
		$name = self::lock_name( $lock_key );
		self::assert_lock_owned( $name );

		// Match WordPress update_option()'s value filters: a rejected pointer
		// update must remain rejectable by pre_update_option_<option> hooks.
		$old_value = get_option( $option_key );
		$value = sanitize_option( $option_key, $value );
		$value = apply_filters( "pre_update_option_{$option_key}", $value, $old_value, $option_key );
		$value = apply_filters( 'pre_update_option', $value, $option_key, $old_value );
		if ( $value === $old_value || maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
			self::assert_lock_owned( $name );
			return;
		}

		$session_id = self::$held_locks[ $name ]['session_id'];
		$statement = $wpdb->prepare(
			"INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) "
			. 'SELECT %s, %s, %s FROM DUAL '
			. 'WHERE CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d '
			. 'ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)',
			$option_key,
			maybe_serialize( $value ),
			'off',
			$session_id,
			$name,
			$session_id
		);

		self::assert_lock_owned( $name );
		try {
			if ( false === $wpdb->query( $statement ) ) {
				throw new RuntimeException( 'Core Scanner storage option write failed.' );
			}
		} finally {
			self::invalidate_option_cache( $option_key );
		}
		self::assert_lock_owned( $name );
	}

	private static function remove_option( string $lock_key, string $option_key ): void {
		global $wpdb;
		$name = self::lock_name( $lock_key );
		self::assert_lock_owned( $name );
		$session_id = self::$held_locks[ $name ]['session_id'];

		$statement = $wpdb->prepare(
			"DELETE FROM `{$wpdb->options}` "
			. 'WHERE option_name = %s AND CONNECTION_ID() = %d AND IS_USED_LOCK(%s) = %d',
			$option_key,
			$session_id,
			$name,
			$session_id
		);

		self::assert_lock_owned( $name );
		try {
			if ( false === $wpdb->query( $statement ) ) {
				throw new RuntimeException( 'Core Scanner storage option deletion failed.' );
			}
		} finally {
			self::invalidate_option_cache( $option_key );
		}
		self::assert_lock_owned( $name );
	}

	/**
	 * Raw fenced SQL must not leave stale WP option-cache state behind. Invalidate
	 * all three native option cache paths, including persistent object caches.
	 * Do this on uncertain SQL results too: a server may have committed a write
	 * just before the connection was lost.
	 */
	private static function invalidate_option_cache( string $option_key ): void {
		wp_cache_delete( $option_key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	public static function set( string $key, mixed $value ): bool {
		return self::with_lock( $key, static fn(): bool => self::set_locked( $key, $value ) );
	}

	private static function set_locked( string $key, mixed $value ): bool {
		self::cleanup_pending( $key );
		$previous = get_option( $key, null );
		if ( null !== $previous && ! self::is_meta( $previous ) ) {
			throw new RuntimeException( 'Core Scanner storage pointer is malformed; refusing to overwrite evidence.' );
		}
		$generation = self::generation();
		$serialized = serialize( $value );
		$chunks = str_split( $serialized, self::CHUNK_BYTES );
		if ( [] === $chunks ) {
			$chunks = [ '' ];
		}

		if ( ! self::stage_pending( $key, $generation, count( $chunks ), $previous, false ) ) {
			return false;
		}

		$written = 0;
		foreach ( $chunks as $index => $chunk ) {
			$chunk_key = self::chunk_key( $key, $generation, $index );
			self::write_option( $key, $chunk_key, $chunk );
			$stored = get_option( $chunk_key, null );
			if ( ! is_string( $stored ) || $stored !== $chunk ) {
				self::cleanup_pending( $key );
				return false;
			}
			$written++;
		}

		$meta = [
			'format'     => self::FORMAT,
			'generation' => $generation,
			'chunks'     => count( $chunks ),
			'bytes'      => strlen( $serialized ),
			'sha256'     => hash( 'sha256', $serialized ),
		];

		self::write_option( $key, $key, $meta );
		$stored_meta = get_option( $key, null );
		if ( ! self::same_meta( $stored_meta, $meta ) ) {
			self::cleanup_pending( $key );
			return false;
		}

		self::cleanup_pending( $key );
		return true;
	}

	public static function get( string $key, mixed $default = null ): mixed {
		return self::with_lock( $key, static fn(): mixed => self::get_locked( $key, $default ) );
	}

	private static function get_locked( string $key, mixed $default ): mixed {
		$meta = get_option( $key, null );
		if ( null === $meta ) {
			return $default;
		}
		if ( ! self::is_meta( $meta ) ) {
			throw new RuntimeException( 'Core Scanner storage pointer is malformed; refusing to return empty evidence.' );
		}

		$generation = (string) $meta['generation'];
		$count = max( 0, (int) $meta['chunks'] );
		if ( '' === $generation || $count < 1 ) {
			throw new RuntimeException( 'Core Scanner storage generation is invalid.' );
		}

		$serialized = '';
		for ( $index = 0; $index < $count; $index++ ) {
			$chunk = get_option( self::chunk_key( $key, $generation, $index ), null );
			if ( ! is_string( $chunk ) ) {
				throw new RuntimeException( 'Core Scanner storage chunk is missing.' );
			}
			$serialized .= $chunk;
		}

		if ( (int) ( $meta['bytes'] ?? -1 ) !== strlen( $serialized ) ) {
			throw new RuntimeException( 'Core Scanner storage length verification failed.' );
		}
		if ( ! hash_equals( (string) ( $meta['sha256'] ?? '' ), hash( 'sha256', $serialized ) ) ) {
			throw new RuntimeException( 'Core Scanner storage checksum verification failed.' );
		}

		$value = @unserialize( $serialized, [ 'allowed_classes' => false ] );
		if ( false === $value && 'b:0;' !== $serialized ) {
			throw new RuntimeException( 'Core Scanner storage value is not decodable.' );
		}
		return $value;
	}

	public static function delete( string $key ): void {
		self::with_lock( $key, static function () use ( $key ): void {
			self::cleanup_pending( $key );
			$meta = get_option( $key, null );
			if ( null === $meta ) {
				return;
			}
			if ( ! self::is_meta( $meta ) ) {
				throw new RuntimeException( 'Core Scanner storage pointer is malformed; refusing to delete.' );
			}
			if ( ! self::stage_pending( $key, (string) $meta['generation'], (int) $meta['chunks'], null, true ) ) {
				throw new RuntimeException( 'Core Scanner storage deletion journal could not be persisted.' );
			}
			self::cleanup_pending( $key );
		} );
	}

	/** Number of chunks in the active generation, primarily for diagnostics/tests. */
	public static function chunk_count( string $key ): int {
		return self::with_lock( $key, static function () use ( $key ): int {
			$meta = get_option( $key, null );
			if ( null !== $meta && ! self::is_meta( $meta ) ) {
				throw new RuntimeException( 'Core Scanner storage pointer is malformed.' );
			}
			return self::is_meta( $meta ) ? (int) $meta['chunks'] : 0;
		} );
	}

	private static function generation(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $throwable ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( 'Could not create a Scanner storage generation.', 0, $throwable );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private static function chunk_key( string $key, string $generation, int $index ): string {
		return $key . '__' . $generation . '_' . $index;
	}

	private static function is_meta( mixed $value ): bool {
		return is_array( $value )
			&& self::FORMAT === (string) ( $value['format'] ?? '' )
			&& is_string( $value['generation'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{16}$/', $value['generation'] )
			&& is_int( $value['chunks'] ?? null )
			&& $value['chunks'] > 0
			&& is_int( $value['bytes'] ?? null )
			&& $value['bytes'] >= 0
			&& is_string( $value['sha256'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $value['sha256'] );
	}

	private static function same_meta( mixed $stored, array $expected ): bool {
		if ( ! self::is_meta( $stored ) ) {
			return false;
		}
		return (string) $stored['generation'] === (string) $expected['generation']
			&& (int) $stored['chunks'] === (int) $expected['chunks']
			&& (int) $stored['bytes'] === (int) $expected['bytes']
			&& (string) $stored['sha256'] === (string) $expected['sha256'];
	}

	private static function delete_previous_generation( string $key, mixed $previous, string $current_generation ): void {
		if ( ! self::is_meta( $previous ) ) {
			return;
		}
		$generation = (string) $previous['generation'];
		if ( '' === $generation || $generation === $current_generation ) {
			return;
		}
		self::delete_generation( $key, $generation, (int) $previous['chunks'] );
	}

	private static function delete_generation( string $key, string $generation, int $count ): void {
		for ( $index = 0; $index < max( 0, $count ); $index++ ) {
			$chunk_key = self::chunk_key( $key, $generation, $index );
			self::remove_option( $key, $chunk_key );
			if ( null !== get_option( $chunk_key, null ) ) {
				throw new RuntimeException( 'Core Scanner storage generation cleanup failed.' );
			}
		}
	}

	/**
	 * Write-ahead journal for interrupted publication or deletion. It is
	 * retained until both pointer and generation retirement are complete.
	 */
	private static function stage_pending( string $key, string $generation, int $chunks, mixed $prior, bool $deleting ): bool {
		$record = [
			'generation' => $generation,
			'chunks'     => $chunks,
			'prior'      => $prior,
			'deleting'   => $deleting,
		];
		$name = $key . self::PENDING_SUFFIX;
		self::write_option( $key, $name, $record );
		return get_option( $name, null ) === $record;
	}

	private static function clear_pending( string $key ): void {
		$name = $key . self::PENDING_SUFFIX;
		self::remove_option( $key, $name );
		if ( null !== get_option( $name, null ) ) {
			throw new RuntimeException( 'Core Scanner storage cleanup journal could not be cleared.' );
		}
	}

	/** Recover interrupted writes only under the exclusive writer lock. */
	private static function cleanup_pending( string $key ): void {
		$pending = get_option( $key . self::PENDING_SUFFIX, null );
		if ( null === $pending ) {
			return;
		}
		if (
			! is_array( $pending )
			|| ! is_string( $pending['generation'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{16}$/', $pending['generation'] )
			|| ! is_int( $pending['chunks'] ?? null )
			|| $pending['chunks'] < 1
			|| ! is_bool( $pending['deleting'] ?? null )
		) {
			throw new RuntimeException( 'Core Scanner storage journal is damaged.' );
		}

		$generation = $pending['generation'];
		$chunks = $pending['chunks'];
		$prior = $pending['prior'] ?? null;
		$active = get_option( $key, null );
		$is_current = self::is_meta( $active ) && $active['generation'] === $generation;

		if ( $pending['deleting'] ) {
			if ( null !== $active && ! $is_current ) {
				throw new RuntimeException( 'Core Scanner storage deletion pointer changed unexpectedly.' );
			}
			if ( $is_current ) {
				self::remove_option( $key, $key );
				if ( null !== get_option( $key, null ) ) {
					throw new RuntimeException( 'Core Scanner storage pointer deletion failed.' );
				}
			}
			self::delete_generation( $key, $generation, $chunks );
		} elseif ( $is_current ) {
			self::delete_previous_generation( $key, $prior, $generation );
		} elseif (
			( null === $active && null === $prior )
			|| ( self::is_meta( $active ) && self::is_meta( $prior ) && self::same_meta( $active, $prior ) )
		) {
			self::delete_generation( $key, $generation, $chunks );
		} else {
			throw new RuntimeException( 'Core Scanner storage publication state changed unexpectedly.' );
		}
		self::clear_pending( $key );
	}
}
