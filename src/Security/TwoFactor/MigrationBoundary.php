<?php
declare(strict_types=1);

namespace CB\Core\Security\TwoFactor;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Destination trust-domain boundary for Base-owned two-factor material.
 *
 * Full database migrations can carry user authentication material from the
 * source site. A destination must never accept those imported Base credentials
 * as local authentication authority. Policy may remain portable; credentials,
 * recovery hashes, replay state and pending authentication state do not.
 */
final class MigrationBoundary {

	/**
	 * @return array{users:int,meta_records:int,challenge_records:int}
	 */
	public static function reset_imported_authentication_state(): array {
		global $wpdb;

		$keys = [
			CredentialStore::META_SECRET,
			CredentialStore::META_RECOVERY,
			CredentialStore::META_ENROLLED_AT,
			CredentialStore::META_LAST_TIMESTEP,
			EnrollmentStore::META_PENDING,
		];

		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})",
				...$keys
			)
		);
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})",
				...$keys
			)
		);
		if ( false === $deleted ) {
			throw new RuntimeException( 'Could not reset imported two-factor credential state.' );
		}

		foreach ( $user_ids as $user_id ) {
			wp_cache_delete( $user_id, 'user_meta' );
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ({$placeholders})",
				...$keys
			)
		);
		if ( $remaining > 0 ) {
			throw new RuntimeException( 'Imported two-factor credential state remained after reset.' );
		}

		$stats = [
			'users'             => count( $user_ids ),
			'meta_records'      => max( 0, (int) $deleted ),
			'challenge_records' => ChallengeStore::clear_all_persisted(),
		];

		Audit::migration_reset( $stats );
		return $stats;
	}
}
