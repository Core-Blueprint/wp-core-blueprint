<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Quarantine;

use RuntimeException;

use function add_option;
use function bin2hex;
use function delete_option;
use function get_option;
use function is_array;
use function preg_match;
use function random_bytes;
use function time;

/**
 * Short per-item lease for mutating Quarantine Workspace actions.
 *
 * Restore, permanent delete, notes and review-state changes all mutate the
 * same persisted item. The lease prevents two HTTP workers from racing those
 * transitions. A crashed request leaves a stale lease that can be reclaimed.
 */
final class MutationLock {
	private const PREFIX    = 'cb_core_quarantine_mutation_lock_';
	private const STALE_TTL = 300;

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

		$current = get_option( $key, [] );
		$current = is_array( $current ) ? $current : [];
		$age = time() - (int) ( $current['acquired_at'] ?? 0 );
		if ( $age > self::STALE_TTL ) {
			delete_option( $key );
			if ( add_option( $key, $data, '', false ) ) {
				return $token;
			}
		}

		throw new RuntimeException( __( 'Another quarantine action is already running for this item. Try again after it finishes.', 'core-blueprint' ) );
	}

	public static function release( string $id, string $token ): void {
		$id = self::validate_id( $id );
		$key = self::PREFIX . $id;
		$current = get_option( $key, [] );
		if ( is_array( $current ) && '' !== $token && $token === (string) ( $current['token'] ?? '' ) ) {
			delete_option( $key );
		}
	}

	private static function validate_id( string $id ): string {
		if ( 1 !== preg_match( '/^q_[a-f0-9]{16}$/', $id ) ) {
			throw new RuntimeException( __( 'Invalid quarantine item identifier.', 'core-blueprint' ) );
		}
		return $id;
	}
}
