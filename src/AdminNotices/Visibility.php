<?php
declare(strict_types=1);
/**
 * Resolve notice-source visibility for the current user.
 *
 * The effective cb_operator manager population is the safety anchor. When no
 * operator can currently manage Admin Notices, governance fails open and no
 * source is suppressed for anyone. This includes quarantined/blocked operators
 * in Enforce mode while preserving Monitor-mode capability semantics.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\AdminNotices;

use CoreBlueprint\Core\Permissions\Roles;

defined( 'ABSPATH' ) || exit;

final class Visibility {

	public static function governance_available(): bool {
		foreach ( Roles::operator_ids() as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User && user_can( $user, Capabilities::MANAGE ) ) {
				return true;
			}
		}

		return false;
	}

	public static function current_user_is_manager(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	public static function allows_current_user( string $source_id, ?bool $governance_available = null ): bool {
		$governance_available ??= self::governance_available();

		if (
			SourceResolver::is_protected_id( $source_id )
			|| ! $governance_available
			|| self::current_user_is_manager()
		) {
			return true;
		}

		$rule = Policy::rule_for( $source_id );
		return match ( $rule['visibility'] ) {
			Policy::OPERATORS_ONLY => false,
			Policy::SELECTED       => Audience::matches( $rule['audience'] ),
			default                => true,
		};
	}

	private function __construct() {}
}
