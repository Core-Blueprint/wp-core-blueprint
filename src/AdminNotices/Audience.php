<?php
declare(strict_types=1);
/**
 * Audience contract for Admin Notices presentation policy.
 *
 * Reuses the proven role/capability matcher from Admin Navigation. The
 * dependency is presentation-only: neither domain grants or revokes WordPress
 * authorization.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\AdminNotices;

use CB\Core\AdminNavigation\Audience as NavigationAudience;

defined( 'ABSPATH' ) || exit;

final class Audience {

	/** @return array{roles:list<string>,capabilities:list<string>} */
	public static function normalize( array $audience ): array {
		return NavigationAudience::normalize( $audience );
	}

	public static function matches( array $audience ): bool {
		return NavigationAudience::matches( $audience );
	}

	private function __construct() {}
}
