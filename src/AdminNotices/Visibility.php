<?php
declare(strict_types=1);
/**
 * Resolve notice-source visibility for the current user.
 *
 * The cb_operator population is the safety anchor. When no operator exists,
 * notice governance fails open and no source is suppressed for anyone.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\AdminNotices;

use CB\Core\Permissions\Roles;

defined( 'ABSPATH' ) || exit;

final class Visibility {

	public static function allows_current_user( string $source_id ): bool {
		if ( Roles::operator_count() < 1 ) {
			return true;
		}
		if ( current_user_can( Capabilities::MANAGE ) ) {
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
