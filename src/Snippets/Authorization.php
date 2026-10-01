<?php
declare(strict_types=1);

namespace CB\Core\Snippets;

use CB\Core\Permissions\PrivilegedAccessGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Authorization policy for managed executable snippets.
 *
 * Access to the Snippets workspace and authority to mutate executable code are
 * deliberately separate. The Core Blueprint capability controls governance
 * access; executable-code mutations additionally require either a signed,
 * approved CB Operator identity or native WordPress code-management authority.
 */
final class Authorization {
	public const MANAGE_CAPABILITY = 'cb_manage_snippets';

	/**
	 * Whether the current identity may access managed snippets.
	 */
	public static function can_access(): bool {
		return current_user_can( self::MANAGE_CAPABILITY );
	}

	/**
	 * Whether the current identity carries sufficient authority to mutate code.
	 *
	 * A trusted CB Operator is an explicit Core Blueprint trust authority and
	 * does not need plugin-install capabilities. Other identities must retain
	 * WordPress' native plugin-install and unfiltered-HTML authority as an
	 * independent code-management boundary.
	 */
	public static function has_code_authority(): bool {
		if ( ! self::can_access() ) {
			return false;
		}

		$user = wp_get_current_user();
		if (
			$user instanceof \WP_User
			&& $user->ID > 0
			&& PrivilegedAccessGuard::is_trusted_operator( $user )
		) {
			return true;
		}

		return current_user_can( 'install_plugins' )
			&& current_user_can( 'unfiltered_html' );
	}

	/**
	 * Respect WordPress' canonical file-modification policy.
	 */
	public static function file_modifications_allowed(): bool {
		return ! function_exists( 'wp_is_file_mod_allowed' )
			|| wp_is_file_mod_allowed( 'capability_update_core' );
	}

	/**
	 * Full mutation boundary for managed executable code.
	 */
	public static function can_mutate(): bool {
		return self::has_code_authority()
			&& self::file_modifications_allowed();
	}
}
