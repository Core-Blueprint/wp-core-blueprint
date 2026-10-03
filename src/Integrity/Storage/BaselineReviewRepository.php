<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Integrity\Storage;

use function array_fill_keys;
use function array_intersect_key;
use function array_keys;
use function count;
use function current_time;
use function get_current_user_id;
use function get_user_meta;
use function in_array;
use function is_array;
use function sanitize_key;
use function update_user_meta;

defined( 'ABSPATH' ) || exit;

/** User-scoped review progress for local-baseline candidates in the latest scan. */
final class BaselineReviewRepository {
	private const META_KEY = 'cb_core_integrity_baseline_review';

	public static function scanId( array $result ): string {
		return (string) ( $result['completed_at'] ?? $result['timestamp'] ?? '' );
	}

	/** @return list<string> */
	public static function reviewedIds( array $result ): array {
		$user_id = get_current_user_id();
		$scan_id = self::scanId( $result );
		if ( $user_id <= 0 || '' === $scan_id ) {
			return [];
		}

		$state = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $state ) || ! hash_equals( $scan_id, (string) ( $state['scan_id'] ?? '' ) ) ) {
			return [];
		}

		$candidate_map = array_fill_keys( ResultRepository::baselineCandidateIds( $result ), true );
		$reviewed = is_array( $state['reviewed'] ?? null ) ? $state['reviewed'] : [];
		return array_keys( array_intersect_key( $reviewed, $candidate_map ) );
	}

	public static function isReviewed( array $result, string $candidate_id ): bool {
		$candidate_id = sanitize_key( $candidate_id );
		return '' !== $candidate_id && in_array( $candidate_id, self::reviewedIds( $result ), true );
	}

	public static function markReviewed( array $result, string $candidate_id ): bool {
		$user_id      = get_current_user_id();
		$scan_id      = self::scanId( $result );
		$candidate_id = sanitize_key( $candidate_id );
		$candidates   = ResultRepository::baselineCandidateIds( $result );
		if ( $user_id <= 0 || '' === $scan_id || '' === $candidate_id || ! in_array( $candidate_id, $candidates, true ) ) {
			return false;
		}

		$state = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $state ) || ! hash_equals( $scan_id, (string) ( $state['scan_id'] ?? '' ) ) ) {
			$state = [ 'scan_id' => $scan_id, 'reviewed' => [] ];
		}
		$state['reviewed'] = is_array( $state['reviewed'] ?? null ) ? $state['reviewed'] : [];
		if ( isset( $state['reviewed'][ $candidate_id ] ) ) {
			return true;
		}
		$state['reviewed'][ $candidate_id ] = current_time( 'mysql' );

		return false !== update_user_meta( $user_id, self::META_KEY, $state );
	}

	/** @return array{reviewed:int,total:int,complete:bool} */
	public static function progress( array $result ): array {
		$total    = count( ResultRepository::baselineCandidateIds( $result ) );
		$reviewed = count( self::reviewedIds( $result ) );
		return [
			'reviewed' => $reviewed,
			'total'    => $total,
			'complete' => $total > 0 && $reviewed === $total,
		];
	}
}
