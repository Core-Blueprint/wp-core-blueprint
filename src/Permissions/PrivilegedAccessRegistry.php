<?php
declare(strict_types=1);
/**
 * PrivilegedAccessRegistry
 *
 * Stores signed approvals and pending-review state for administrator/admin-like
 * identities. Approval records are HMAC-bound to the site's auth salt, user ID,
 * privilege fingerprint, approver, and approval time so a database-only write
 * cannot manufacture a valid approval without also knowing the site secret.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Permissions;

use CB\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class PrivilegedAccessRegistry {

	private const META_APPROVAL   = '_cb_core_privileged_approval';
	private const META_REVIEW = '_cb_core_privileged_review';

	/**
	 * Approve the user's *current* privilege fingerprint.
	 */
	public static function approve( \WP_User $user, int $approved_by, string $source = 'operator' ): bool {
		if ( ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
			self::clear( $user );
			return false;
		}

		$fingerprint = PrivilegedAccessPolicy::fingerprint( $user );
		$approved_at = time();
		$source      = sanitize_key( $source );

		$record = [
			'fingerprint' => $fingerprint,
			'approved_at' => $approved_at,
			'approved_by' => $approved_by,
			'source'      => $source,
		];
		$record['signature'] = self::signature( (int) $user->ID, $record );

		update_user_meta( (int) $user->ID, self::META_APPROVAL, $record );
		delete_user_meta( (int) $user->ID, self::META_REVIEW );

		AuditLog::log( 'permissions.privileged_user_approved', 'notice', [
			'user_id'      => (int) $user->ID,
			'user_login'   => (string) $user->user_login,
			'roles'        => array_values( (array) $user->roles ),
			'critical_caps'=> PrivilegedAccessPolicy::critical_capabilities_for_user( $user ),
			'approved_by'  => $approved_by,
			'source'       => $source,
		] );

		return true;
	}

	/**
	 * Return the valid signed approval for the user's exact current privilege
	 * state, or an empty array when no such approval exists.
	 *
	 * @return array<string,mixed>
	 */
	public static function valid_approval_record( \WP_User $user ): array {
		$record = get_user_meta( (int) $user->ID, self::META_APPROVAL, true );
		if ( ! is_array( $record ) || ! self::signed_record_is_valid( (int) $user->ID, $record ) ) {
			return [];
		}

		$fingerprint = (string) ( $record['fingerprint'] ?? '' );
		if ( '' === $fingerprint || ! hash_equals( PrivilegedAccessPolicy::fingerprint( $user ), $fingerprint ) ) {
			return [];
		}

		return $record;
	}

	/** Whether the stored approval is valid for the user's exact current state. */
	public static function is_approved( \WP_User $user ): bool {
		return [] !== self::valid_approval_record( $user );
	}

	/**
	 * Persist pending-review state once per unique privilege fingerprint.
	 * Returns true when this call created/updated the review record and emitted
	 * the security event; false for an already-known identical review.
	 */
	public static function flag_for_review( \WP_User $user, string $reason, string $source = 'runtime' ): bool {
		if ( ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
			self::clear( $user );
			return false;
		}

		$fingerprint = PrivilegedAccessPolicy::fingerprint( $user );
		$existing    = get_user_meta( (int) $user->ID, self::META_REVIEW, true );
		if ( is_array( $existing ) && hash_equals( (string) ( $existing['fingerprint'] ?? '' ), $fingerprint ) ) {
			return false;
		}

		delete_user_meta( (int) $user->ID, self::META_APPROVAL );

		$state = [
			'fingerprint'   => $fingerprint,
			'detected_at'   => time(),
			'reason'        => sanitize_key( $reason ),
			'source'        => sanitize_key( $source ),
			'detected_by'   => get_current_user_id(),
			'roles'         => array_values( (array) $user->roles ),
			'critical_caps' => PrivilegedAccessPolicy::critical_capabilities_for_user( $user ),
		];
		update_user_meta( (int) $user->ID, self::META_REVIEW, $state );

		AuditLog::log( 'permissions.privileged_user_review_required', 'warning', [
			'user_id'       => (int) $user->ID,
			'user_login'    => (string) $user->user_login,
			'user_email'    => (string) $user->user_email,
			'roles'         => $state['roles'],
			'critical_caps' => $state['critical_caps'],
			'reason'        => $state['reason'],
			'source'        => $state['source'],
			'detected_by'   => $state['detected_by'],
			'fingerprint'   => $fingerprint,
			'enforcement_mode' => PrivilegedAccessPolicy::enforcement_mode(),
			'restricted'       => PrivilegedAccessPolicy::enforces_approval(),
			'review_url'       => admin_url( 'admin.php?page=core-blueprint-safeguards&tab=core-shield#cb-core-privileged-access' ),
		] );

		return true;
	}

	/**
	 * Force an exact privileged identity into review.
	 *
	 * Used when crossing a trust-domain boundary such as a site migration:
	 * even a cryptographically valid imported approval must not become
	 * destination authority merely because source and destination secrets
	 * happen to match.
	 */
	public static function require_review( \WP_User $user, string $reason, string $source = 'runtime' ): bool {
		if ( ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
			self::clear( $user );
			return false;
		}

		$fingerprint = PrivilegedAccessPolicy::fingerprint( $user );
		$existing    = get_user_meta( (int) $user->ID, self::META_REVIEW, true );
		$approval    = get_user_meta( (int) $user->ID, self::META_APPROVAL, true );
		$reason      = sanitize_key( $reason );
		$source      = sanitize_key( $source );
		$same_review = is_array( $existing )
			&& hash_equals( (string) ( $existing['fingerprint'] ?? '' ), $fingerprint )
			&& $reason === (string) ( $existing['reason'] ?? '' )
			&& $source === (string) ( $existing['source'] ?? '' );

		delete_user_meta( (int) $user->ID, self::META_APPROVAL );
		if ( $same_review && empty( $approval ) ) {
			return false;
		}

		$state = [
			'fingerprint'   => $fingerprint,
			'detected_at'   => time(),
			'reason'        => $reason,
			'source'        => $source,
			'detected_by'   => get_current_user_id(),
			'roles'         => array_values( (array) $user->roles ),
			'critical_caps' => PrivilegedAccessPolicy::critical_capabilities_for_user( $user ),
		];
		update_user_meta( (int) $user->ID, self::META_REVIEW, $state );

		AuditLog::log( 'permissions.privileged_user_review_required', 'warning', [
			'user_id'          => (int) $user->ID,
			'user_login'       => (string) $user->user_login,
			'user_email'       => (string) $user->user_email,
			'roles'            => $state['roles'],
			'critical_caps'    => $state['critical_caps'],
			'reason'           => $reason,
			'source'           => $source,
			'detected_by'      => $state['detected_by'],
			'fingerprint'      => $fingerprint,
			'enforcement_mode' => PrivilegedAccessPolicy::enforcement_mode(),
			'restricted'       => PrivilegedAccessPolicy::enforces_approval(),
			'review_url'       => admin_url( 'admin.php?page=core-blueprint-safeguards&tab=core-shield#cb-core-privileged-access' ),
		] );

		return true;
	}

	/** Clear all guard state when an identity is no longer privileged. */
	public static function clear( \WP_User $user ): void {
		delete_user_meta( (int) $user->ID, self::META_APPROVAL );
		delete_user_meta( (int) $user->ID, self::META_REVIEW );
	}

	/** @return array<string,mixed> */
	public static function review_state( \WP_User $user ): array {
		$state = get_user_meta( (int) $user->ID, self::META_REVIEW, true );
		return is_array( $state ) ? $state : [];
	}

	/**
	 * Privileged users waiting for operator review.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function review_snapshot(): array {
		$users = get_users( [
			'meta_key' => self::META_REVIEW, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'  => 'registered',
			'order'    => 'DESC',
		] );

		$rows = [];
		foreach ( $users as $user ) {
			if ( ! ( $user instanceof \WP_User ) ) {
				continue;
			}
			if ( ! PrivilegedAccessPolicy::is_privileged( $user ) ) {
				self::clear( $user );
				continue;
			}
			if ( self::is_approved( $user ) ) {
				delete_user_meta( (int) $user->ID, self::META_REVIEW );
				continue;
			}
			$state = self::review_state( $user );
			$rows[] = [
				'user'          => $user,
				'detected_at'   => (int) ( $state['detected_at'] ?? 0 ),
				'reason'        => (string) ( $state['reason'] ?? '' ),
				'source'        => (string) ( $state['source'] ?? '' ),
				'detected_by'   => (int) ( $state['detected_by'] ?? 0 ),
				'roles'         => array_values( (array) ( $state['roles'] ?? $user->roles ) ),
				'critical_caps' => array_values( (array) ( $state['critical_caps'] ?? PrivilegedAccessPolicy::critical_capabilities_for_user( $user ) ) ),
			];
		}
		return $rows;
	}

	/** Number of currently valid approved privileged users. */
	public static function approved_count(): int {
		$count = 0;
		foreach ( get_users() as $user ) {
			if ( $user instanceof \WP_User && PrivilegedAccessPolicy::is_privileged( $user ) && self::is_approved( $user ) ) {
				$count++;
			}
		}
		return $count;
	}

	/** @return int[] IDs of CB Operators with a valid approval for their exact current state. */
	public static function approved_operator_ids(): array {
		$approved = [];
		foreach ( Roles::operator_ids() as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User && self::is_approved( $user ) ) {
				$approved[] = (int) $user->ID;
			}
		}
		sort( $approved, SORT_NUMERIC );
		return $approved;
	}

	/** Number of CB Operators currently able to act as browser trust authority. */
	public static function approved_operator_count(): int {
		return count( self::approved_operator_ids() );
	}

	/** @param array<string,mixed> $record */
	private static function signed_record_is_valid( int $user_id, array $record ): bool {
		$fingerprint = (string) ( $record['fingerprint'] ?? '' );
		$signature   = (string) ( $record['signature'] ?? '' );
		if ( '' === $fingerprint || '' === $signature ) {
			return false;
		}
		$expected = self::signature( $user_id, $record );
		return '' !== $expected && hash_equals( $expected, $signature );
	}

	/** @param array<string,mixed> $record */
	private static function signature( int $user_id, array $record ): string {
		$key = wp_salt( 'auth' );
		if ( '' === $key ) {
			return '';
		}
		$message = implode( '|', [
			(string) $user_id,
			(string) ( $record['fingerprint'] ?? '' ),
			(string) (int) ( $record['approved_at'] ?? 0 ),
			(string) (int) ( $record['approved_by'] ?? 0 ),
			(string) ( $record['source'] ?? '' ),
		] );
		return hash_hmac( 'sha256', $message, $key );
	}
}
