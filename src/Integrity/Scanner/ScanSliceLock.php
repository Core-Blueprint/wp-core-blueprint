<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Scanner;

use function add_option;
use function bin2hex;
use function delete_option;
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
 * Short execution lease for one Scanner slice.
 *
 * ScannerLock protects the whole logical job from other jobs. This lease solves
 * a different problem: duplicate/overlapping cron workers for the same job must
 * never process the same persisted cursor concurrently. A crashed PHP request
 * leaves the lease behind; it becomes reclaimable after a conservative TTL.
 *
 * Replacement and release use compare-and-swap against the exact option value
 * that was inspected. An older worker can therefore never clear a newer slice
 * lease after ownership changed between its read and write.
 */
final class ScanSliceLock {
	private const OPTION    = 'cb_core_integrity_scan_slice_lock';
	private const STALE_TTL = 300;

	/** Return a lease token, or null when another slice is still executing. */
	public static function acquire( string $job_id ): ?string {
		$job_id = sanitize_key( $job_id );
		if ( '' === $job_id ) {
			return null;
		}

		$token = bin2hex( random_bytes( 16 ) );
		$data  = [
			'token'       => $token,
			'job_id'      => $job_id,
			'acquired_at' => time(),
		];

		if ( add_option( self::OPTION, $data, '', false ) ) {
			return $token;
		}

		$current_raw = self::read_raw();
		if ( null === $current_raw ) {
			return add_option( self::OPTION, $data, '', false ) ? $token : null;
		}
		$current = maybe_unserialize( $current_raw );
		$current = is_array( $current ) ? $current : [];

		// A previous job may have been cancelled/recovered while its PHP worker
		// was still unwinding. If the global long-lived lock now belongs to this
		// requested job, a slice lease from another job can no longer publish
		// state and is safe to replace immediately.
		$global = ScannerLock::current();
		if (
			$job_id === (string) ( $global['job_id'] ?? '' )
			&& $job_id !== (string) ( $current['job_id'] ?? '' )
			&& self::replace_raw( $current_raw, maybe_serialize( $data ) )
		) {
			return $token;
		}

		$age = time() - (int) ( $current['acquired_at'] ?? 0 );
		if ( $age > self::STALE_TTL && self::replace_raw( $current_raw, maybe_serialize( $data ) ) ) {
			return $token;
		}

		return null;
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

	/** Explicit lifecycle cleanup; deactivation intentionally invalidates any lease. */
	public static function clear(): void {
		delete_option( self::OPTION );
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
