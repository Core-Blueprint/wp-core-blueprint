<?php
declare(strict_types=1);
/**
 * Core Setup evidence for privileged identities awaiting operator review.
 *
 * Identity details never enter Setup review metadata. The fingerprint contains
 * only per-identity hashes while presentation context exposes aggregate counts.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Setup\Checks;

use CoreBlueprint\Core\Admin\Pages\Safeguards;
use CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry;
use CoreBlueprint\Core\Setup\CheckInterface;
use CoreBlueprint\Core\Setup\Evidence;
use CoreBlueprint\Core\Setup\Fingerprint;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class PrivilegedAccessReviewCheck implements CheckInterface {

	public function id(): string { return 'privileged-access-review'; }
	public function section(): string { return 'administrator-recovery'; }
	public function label(): string { return 'Privileged identity review'; }
	public function kind(): string { return self::KIND_REQUIRED; }
	public function capability(): string { return 'cb_view_permissions'; }
	public function configuration_url(): string { return admin_url( 'admin.php?page=' . Safeguards::SLUG . '&tab=core-shield#cb-core-privileged-access' ); }
	public function allows_later(): bool { return true; }
	public function allows_not_applicable( Evidence $evidence ): bool { return false; }

	public function evidence(): Evidence {
		try {
			$rows = PrivilegedAccessRegistry::review_snapshot();
			$identity_hashes = [];

			foreach ( $rows as $row ) {
				$user = $row['user'] ?? null;
				if ( ! $user instanceof WP_User ) {
					continue;
				}
				$roles = array_values( array_map( 'strval', (array) ( $row['roles'] ?? [] ) ) );
				$caps  = array_values( array_map( 'strval', (array) ( $row['critical_caps'] ?? [] ) ) );
				sort( $roles, SORT_STRING );
				sort( $caps, SORT_STRING );

				$identity_hashes[] = Fingerprint::hash( [
					'user_id'       => (int) $user->ID,
					'roles'         => $roles,
					'critical_caps' => $caps,
					'reason'        => (string) ( $row['reason'] ?? '' ),
					'source'        => (string) ( $row['source'] ?? '' ),
				] );
			}
			sort( $identity_hashes, SORT_STRING );

			$pending = count( $identity_hashes );
			return new Evidence(
				$pending > 0 ? Evidence::HEALTH_ATTENTION : Evidence::HEALTH_OK,
				$pending > 0 ? 'privileged-access.review-required' : 'privileged-access.review-clear',
				[
					'pending_identity_hashes' => $identity_hashes,
					'approved_count'          => PrivilegedAccessRegistry::approved_count(),
					'approved_operator_count' => PrivilegedAccessRegistry::approved_operator_count(),
				],
				[
					'pending_count'           => $pending,
					'approved_count'          => PrivilegedAccessRegistry::approved_count(),
					'approved_operator_count' => PrivilegedAccessRegistry::approved_operator_count(),
				]
			);
		} catch ( \Throwable $e ) {
			return Evidence::unavailable( 'privileged-access.review-unavailable' );
		}
	}
}
