<?php
declare(strict_types=1);
/**
 * Preferences - user-preference + diagnostic AJAX handlers.
 *
 * Covers: Plain/Technical description mode, security header scan.
 *
 * @package Core_Blueprint
 */

namespace CoreBlueprint\Core\Ajax\Handlers;

use CoreBlueprint\Core\Ajax\Guards;
use CoreBlueprint\Core\Ajax\Request;
use CoreBlueprint\Core\Log\AuditLog;
use CoreBlueprint\Core\Security\HeaderTest;
use CoreBlueprint\Core\UI;

defined( 'ABSPATH' ) || exit;

final class Preferences {
	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_header_test',          [ __CLASS__, 'header_test' ] );
		add_action( 'wp_ajax_cb_core_set_description_mode', [ __CLASS__, 'set_description_mode' ] );
	}

	public static function header_test(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$result = HeaderTest::run();

		AuditLog::log( 'diagnostic.header_test', 'info', [
			'score' => $result['score'],
			'total' => $result['total'],
			'grade' => $result['grade'],
		] );

		wp_send_json_success( $result );
	}

	/**
	 * Set the description mode. Accepts:
	 *   - scope=site  : update the site-wide default (admin only)
	 *   - scope=user  : update the current user's override (any user with manage_options)
	 *   - scope=user & mode=inherit : clear the user's override so site default applies
	 */
	public static function set_description_mode(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$scope = Request::sanitize_key( 'scope', [ 'site', 'user' ] );
		// Mode is validated against different allowlists per scope - too
		// context-dependent for a single Request::sanitize_key call, so we
		// read raw then check below.
		$allowed_modes = 'site' === $scope ? UI::MODES : UI::USER_MODES;
		$mode          = Request::sanitize_key( 'mode', $allowed_modes );

		$user = wp_get_current_user();

		if ( 'site' === $scope ) {
			$ok = UI::set_site_default_mode( $mode, 'admin:' . $user->user_login );
			if ( ! $ok ) {
				wp_send_json_error( [ 'message' => __( 'Could not save site default.', 'core-blueprint' ) ] );
			}
		} else {
			UI::set_user_mode( (int) $user->ID, $mode );
		}

		wp_send_json_success( [
			'scope'     => $scope,
			'mode'      => $mode,
			'effective' => UI::current_mode(),
		] );
	}
}
