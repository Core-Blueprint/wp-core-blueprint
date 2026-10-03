<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use function add_option;
use function bin2hex;
use function get_option;
use function is_array;
use function is_string;
use function maybe_serialize;
use function maybe_unserialize;
use function random_bytes;
use function sanitize_key;
use function time;
use function wp_cache_delete;

/**
 * Cross-entrypoint lock for manual, cron, Hub, API and baseline-triggered scans.
 *
 * The lock may outlive a single PHP request. Resumable scan jobs keep ownership
 * by storing the token in their persisted job state and refreshing the lock on
 * every execution slice.
 *
 * Lock mutations use compare-and-swap against the exact previously-read option
 * value. A stale worker can therefore never refresh or release a lease after a
 * newer worker has taken ownership.
 */
final class ScannerLock {
	private const OPTION    = 'cb_core_integrity_scan_lock';
	private const STALE_TTL = 7200;

	public static function acquire( string $source, string $job_id = '' ): string {
		$token = bin2hex( random_bytes( 16 ) );
		$data  = [
			'token'        => $token,
			'source'       => sanitize_key( $source ),
			'job_id'       => sanitize_key( $job_id ),
			'acquired_at'  => time(),
			'refreshed_at' => time(),
		];

		if ( add_option( self::OPTION, $data, '', false ) ) {
			return $token;
		}

		$existing_raw = self::read_raw();
		if ( null === $existing_raw ) {
			if ( add_option( self::OPTION, $data, '', false ) ) {
				return $token;
			}
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new ScanLockedException( self::current() );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$existing  = maybe_unserialize( $existing_raw );
		$existing  = is_array( $existing ) ? $existing : [];
		$reference = (int) ( $existing['refreshed_at'] ?? $existing['acquired_at'] ?? 0 );
		$age       = time() - $reference;

		if ( $age > self::STALE_TTL && self::replace_raw( $existing_raw, maybe_serialize( $data ) ) ) {
			return $token;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
		throw new ScanLockedException( self::current() );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/** Refresh a persisted job lock without changing its owner token. */
	public static function refresh( string $token ): bool {
		$current_raw = self::read_raw();
		if ( null === $current_raw ) {
			return false;
		}

		$current = maybe_unserialize( $current_raw );
		if ( ! is_array( $current ) || '' === $token || $token !== (string) ( $current['token'] ?? '' ) ) {
			return false;
		}

		$current['refreshed_at'] = time();
		$new_raw = maybe_serialize( $current );
		if ( $new_raw === $current_raw ) {
			return true;
		}

		if ( self::replace_raw( $current_raw, $new_raw ) ) {
			return true;
		}

		// Another same-owner refresh in this exact second is harmless. Any stale
		// takeover uses a different random token and therefore fails this check.
		return self::is_owned_by( $token );
	}

	public static function is_owned_by( string $token ): bool {
		$current = self::current();
		return '' !== $token && $token === (string) ( $current['token'] ?? '' );
	}

	public static function release( string $token ): void {
		global $wpdb;

		$current_raw = self::read_raw();
		if ( null === $current_raw ) {
			return;
		}
		$current = maybe_unserialize( $current_raw );
		if ( ! is_array( $current ) || '' === $token || $token !== (string) ( $current['token'] ?? '' ) ) {
			return;
		}

		$affected = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$current_raw
			)
		);
		if ( 1 === $affected ) {
			wp_cache_delete( self::OPTION, 'options' );
		}
	}

	public static function current(): array {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) ? $value : [];
	}

	private static function read_raw(): ?string {
		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION
			)
		);

		return is_string( $raw ) ? $raw : null;
	}

	private static function replace_raw( string $expected_raw, string $new_raw ): bool {
		global $wpdb;

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_raw,
				self::OPTION,
				$expected_raw
			)
		);
		if ( 1 !== $affected ) {
			return false;
		}

		wp_cache_delete( self::OPTION, 'options' );
		return true;
	}
}
