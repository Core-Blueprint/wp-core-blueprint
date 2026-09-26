<?php
declare(strict_types=1);

namespace CB\Core\Ajax\Handlers;

use CB\Core\Ajax\Guards;
use CB\Core\Ajax\Request;
use CB\Core\Permissions\PrivilegedAccessGuard;
use CB\Core\Security\TwoFactor\Policy;
use CB\Core\Security\TwoFactor\PolicyMutation;
use Throwable;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX boundary for the site-wide Base two-factor policy.
 *
 * Browser policy changes require the normal admin nonce, a signed/approved
 * CB Operator identity and a fresh WordPress password confirmation. The
 * domain-level PolicyMutation remains the final authority.
 */
final class TwoFactorPolicy {
	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_set_two_factor_policy', [ __CLASS__, 'set_mode' ] );
	}

	public static function set_mode(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_trusted_operator();
		self::require_password_reconfirm();

		$mode = Request::sanitize_key( 'mode', [
			Policy::MODE_OPTIONAL,
			Policy::MODE_ENFORCE,
		] );

		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) || $user->ID <= 0 ) {
			wp_send_json_error( [
				'message' => __( 'No authenticated user.', 'core-blueprint' ),
			], 401 );
		}

		try {
			PolicyMutation::set_mode( $mode, $user, 'safeguards' );
		} catch ( Throwable $error ) {
			wp_send_json_error( [
				'message' => $error->getMessage(),
				'mode'    => Policy::mode(),
			], 409 );
		}

		wp_send_json_success( [
			'mode' => Policy::mode(),
		] );
	}

	private static function require_trusted_operator(): void {
		$user = wp_get_current_user();
		if (
			! current_user_can( 'cb_manage_permissions' )
			|| ! ( $user instanceof WP_User )
			|| ! PrivilegedAccessGuard::is_trusted_operator( $user )
		) {
			wp_send_json_error( [
				'message' => __( 'Only a signed and approved CB Operator can change the site-wide two-factor policy.', 'core-blueprint' ),
			], 403 );
		}
	}
}
