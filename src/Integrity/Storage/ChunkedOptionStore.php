<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Storage;

use RuntimeException;

use function count;
use function delete_option;
use function get_option;
use function hash;
use function is_array;
use function is_string;
use function random_bytes;
use function serialize;
use function str_split;
use function unserialize;
use function update_option;

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

	/** @var array<string,true> Named locks already owned by this PHP request. */
	private static array $held_locks = [];

	/**
	 * Serialize the complete read-modify-write transaction, not just set().
	 * The database drops these locks on connection termination: no stale lease
	 * can be reclaimed while a worker is still active.
	 */
	public static function with_lock( string $key, callable $operation ): mixed {
		global $wpdb;
		$name = self::lock_name( $key );
		if ( isset( self::$held_locks[ $name ] ) ) {
			return $operation();
		}

		$claimed = $wpdb->get_var( $wpdb->prepare(
			'SELECT GET_LOCK(%s, %d)',
			$name,
			self::LOCK_WAIT_SECONDS
		) );
		if ( '1' !== (string) $claimed ) {
			throw new RuntimeException( 'Core Scanner storage is busy or its database mutex is unavailable.' );
		}

		self::$held_locks[ $name ] = true;
		try {
			return $operation();
		} finally {
			unset( self::$held_locks[ $name ] );
			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			if ( '1' !== (string) $released ) {
				throw new RuntimeException( 'Core Scanner could not release the storage mutex safely.' );
			}
		}
	}

	private static function lock_name( string $key ): string {
		global $wpdb;
		return 'cb_integrity_' . substr( hash( 'sha256', $wpdb->options . ':' . $key ), 0, 40 );
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
			update_option( $chunk_key, $chunk, false );
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

		update_option( $key, $meta, false );
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
			delete_option( $chunk_key );
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
		update_option( $name, $record, false );
		return get_option( $name, null ) === $record;
	}

	private static function clear_pending( string $key ): void {
		$name = $key . self::PENDING_SUFFIX;
		delete_option( $name );
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
				delete_option( $key );
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
