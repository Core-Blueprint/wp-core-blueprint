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
 * This store writes a new immutable generation as small non-autoloaded chunks,
 * verifies every chunk and the full SHA-256, and only then flips the compact
 * pointer stored under the canonical option key. Readers therefore see either
 * the previous complete generation or the new complete generation - never a
 * partially-written value.
 */
final class ChunkedOptionStore {
	private const FORMAT = 'cb_integrity_chunked_v1';
	private const CHUNK_BYTES = 196608; // 192 KiB before WP serialisation overhead.

	public static function set( string $key, mixed $value ): bool {
		$previous = get_option( $key, null );
		$generation = self::generation();
		$serialized = serialize( $value );
		$chunks = str_split( $serialized, self::CHUNK_BYTES );
		if ( [] === $chunks ) {
			$chunks = [ '' ];
		}

		$written = 0;
		foreach ( $chunks as $index => $chunk ) {
			$chunk_key = self::chunk_key( $key, $generation, $index );
			update_option( $chunk_key, $chunk, false );
			$stored = get_option( $chunk_key, null );
			if ( ! is_string( $stored ) || $stored !== $chunk ) {
				self::delete_generation( $key, $generation, $written + 1 );
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
			self::delete_generation( $key, $generation, count( $chunks ) );
			return false;
		}

		self::delete_previous_generation( $key, $previous, $generation );
		return true;
	}

	public static function get( string $key, mixed $default = null ): mixed {
		$meta = get_option( $key, null );
		if ( ! self::is_meta( $meta ) ) {
			return $default;
		}

		$generation = (string) $meta['generation'];
		$count = max( 0, (int) $meta['chunks'] );
		if ( '' === $generation || $count < 1 ) {
			return $default;
		}

		$serialized = '';
		for ( $index = 0; $index < $count; $index++ ) {
			$chunk = get_option( self::chunk_key( $key, $generation, $index ), null );
			if ( ! is_string( $chunk ) ) {
				return $default;
			}
			$serialized .= $chunk;
		}

		if ( (int) ( $meta['bytes'] ?? -1 ) !== strlen( $serialized ) ) {
			return $default;
		}
		if ( ! hash_equals( (string) ( $meta['sha256'] ?? '' ), hash( 'sha256', $serialized ) ) ) {
			return $default;
		}

		$value = @unserialize( $serialized, [ 'allowed_classes' => false ] );
		if ( false === $value && 'b:0;' !== $serialized ) {
			return $default;
		}
		return $value;
	}

	public static function delete( string $key ): void {
		$meta = get_option( $key, null );
		if ( self::is_meta( $meta ) ) {
			self::delete_generation( $key, (string) $meta['generation'], (int) $meta['chunks'] );
		}
		delete_option( $key );
	}

	/** Number of chunks in the active generation, primarily for diagnostics/tests. */
	public static function chunk_count( string $key ): int {
		$meta = get_option( $key, null );
		return self::is_meta( $meta ) ? max( 0, (int) $meta['chunks'] ) : 0;
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
			&& isset( $value['generation'], $value['chunks'], $value['bytes'], $value['sha256'] );
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
			delete_option( self::chunk_key( $key, $generation, $index ) );
		}
	}
}
