<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Quarantine;

use CoreBlueprint\Core\Integrity\Support\Audit;
use RuntimeException;

use function add_option;
use function bin2hex;
use function hash_equals;
use function is_array;
use function is_serialized;
use function is_string;
use function preg_match;
use function random_bytes;
use function time;
use function wp_cache_delete;

/**
 * Owner-fenced lock for mutating Quarantine Workspace items.
 *
 * An elapsed timestamp does not prove that a destructive filesystem worker
 * has stopped. Occupied locks always fail closed. Recovery requires trusted
 * CLI, an exact fingerprint and independently confirmed process termination.
 */
final class MutationLock {
	private const PREFIX = 'cb_core_quarantine_mutation_lock_';

	public static function acquire( string $id ): string {
		$id = self::validate_id( $id );
		$key = self::PREFIX . $id;
		$token = bin2hex( random_bytes( 16 ) );
		$data = [
			'token'       => $token,
			'acquired_at' => time(),
		];

		if ( add_option( $key, $data, '', false ) ) {
			return $token;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
		throw new RuntimeException( __( 'Another quarantine action is already running for this item. Try again after it finishes.', 'core-blueprint' ) );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	public static function release( string $id, string $token ): void {
		$key = self::PREFIX . self::validate_id( $id );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			throw new RuntimeException( 'Invalid quarantine mutation lock ownership.' );
		}
		$raw = self::read_raw( $key );
		$owner = null === $raw ? null : self::decode( $raw );
		if ( null === $raw || null === $owner || ! hash_equals( $owner['token'], $token ) ) {
			throw new RuntimeException( 'Quarantine mutation lock ownership was lost.' );
		}
		if ( ! self::delete_exact( $key, $raw ) ) {
			throw new RuntimeException( 'Quarantine mutation lock changed before the original worker released it.' );
		}
	}

	/**
	 * Non-secret read-only fingerprint for trusted manual recovery.
	 *
	 * @return array{sha256:string,acquired_at:int}|null
	 */
	public static function lock_evidence( string $id ): ?array {
		$key = self::PREFIX . self::validate_id( $id );
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return null;
		}
		$raw = self::read_raw( $key );
		$owner = null === $raw ? null : self::decode( $raw );
		return null === $owner
			? null
			: [ 'sha256' => hash( 'sha256', $raw ), 'acquired_at' => $owner['acquired_at'] ];
	}

	/**
	 * Timestamp age alone NEVER authorizes recovery. The operator must
	 * independently verify that the original process has terminated.
	 */
	public static function recover_lock( string $id, string $expected_sha256, bool $confirmed_inactive ): bool {
		$key = self::PREFIX . self::validate_id( $id );
		if (
			! defined( 'WP_CLI' ) || ! WP_CLI
			|| ! $confirmed_inactive
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_sha256 )
		) {
			throw new RuntimeException( 'Trusted CLI, an exact fingerprint and inactive-process confirmation are required.' );
		}
		$raw = self::read_raw( $key );
		$owner = null === $raw ? null : self::decode( $raw );
		if ( null === $owner || ! hash_equals( hash( 'sha256', $raw ), $expected_sha256 ) ) {
			return false;
		}
		if ( $owner['acquired_at'] > time() - 600 ) {
			throw new RuntimeException( 'A recently acquired quarantine lock cannot be recovered.' );
		}
		if ( ! self::delete_exact( $key, $raw ) ) {
			return false;
		}
		Audit::log( 'integrity_quarantine_lock_recovered', 'warning', [
			'quarantine_id' => $id,
			'acquired_at'  => $owner['acquired_at'],
		] );
		return true;
	}

	private static function read_raw( string $key ): ?string {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$key
		) );
		return is_string( $raw ) ? $raw : null;
	}

	/** @return array{token:string,acquired_at:int}|null */
	private static function decode( string $raw ): ?array {
		$decoded = is_serialized( $raw )
			? @unserialize( $raw, [ 'allowed_classes' => false ] )
			: null;
		if (
			! is_array( $decoded )
			|| ! is_string( $decoded['token'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/', $decoded['token'] )
			|| ! is_int( $decoded['acquired_at'] ?? null )
			|| $decoded['acquired_at'] <= 0
			|| $decoded['acquired_at'] > time()
		) {
			return null;
		}
		return [ 'token' => $decoded['token'], 'acquired_at' => $decoded['acquired_at'] ];
	}

	/** Database-level compare-and-delete, even if the WP option cache is stale. */
	private static function delete_exact( string $key, string $raw ): bool {
		global $wpdb;
		try {
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s",
				$key,
				$raw
			) );
		} finally {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		if ( false === $deleted ) {
			throw new RuntimeException( 'Quarantine mutation lock database write failed.' );
		}
		return 1 === (int) $deleted;
	}

	private static function validate_id( string $id ): string {
		if ( 1 !== preg_match( '/^q_[a-f0-9]{16}$/', $id ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output; escape only at the eventual presentation boundary.
			throw new RuntimeException( __( 'Invalid quarantine item identifier.', 'core-blueprint' ) );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $id;
	}
}
