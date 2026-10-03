<?php
declare(strict_types=1);
/**
 * Audience contract for Admin Notices presentation policy.
 *
 * Reuses Admin Navigation's bounded reference normalization, but owns its
 * matching semantics. Admin Notices selected audiences are intentionally OR:
 * any selected role or any selected capability is sufficient. Neither domain
 * grants or revokes WordPress authorization.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\AdminNotices;

use CoreBlueprint\Core\AdminNavigation\Audience as NavigationAudience;

defined( 'ABSPATH' ) || exit;

final class Audience {

	/** @return array{roles:list<string>,capabilities:list<string>} */
	public static function normalize( array $audience ): array {
		return NavigationAudience::normalize( $audience );
	}

	public static function matches( array $audience ): bool {
		$audience = self::normalize( $audience );
		$user     = wp_get_current_user();
		$roles    = $user instanceof \WP_User
			? array_values( array_map( 'strval', (array) $user->roles ) )
			: [];

		if ( [] !== array_intersect( $audience['roles'], $roles ) ) {
			return true;
		}

		foreach ( $audience['capabilities'] as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}

		return false;
	}

	private function __construct() {}
}
