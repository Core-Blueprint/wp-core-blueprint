<?php
declare(strict_types=1);
/**
 * LoginShield - AJAX handlers.
 *
 * Two endpoints:
 *
 *   - `cb_core_login_shield_save` - persist the full Login Shield config
 *     after server-side validation + normalisation. Returns the resolved
 *     config so the client can update the live preview without having to
 *     reload the page. The `enabled` field is intentionally optional in
 *     this endpoint: the master switch persists separately (see below),
 *     so a form-batch save that omits `enabled` preserves the current
 *     Dashboard-owned activation state rather than silently toggling it off.
 *
 *   - `cb_core_login_shield_test` - issue a server-side GET against the
 *     configured custom login URL and report back whether it responds
 *     200. Gives the admin a quick sanity check after saving, before they
 *     trust the new URL for real.
 *
 * All endpoints run behind the standard nonce + capability preamble and
 * use the shared {@see Request} helper, matching the pattern established
 * by the other handlers in this directory.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Ajax\Handlers;

use CB\Core\Ajax\Guards;
use CB\Core\Ajax\Request;
use CB\Core\Security\LoginShield as LoginShieldCore;

defined( 'ABSPATH' ) || exit;

final class LoginShield {
	use Guards;

	public static function init(): void {
		add_action( 'wp_ajax_cb_core_login_shield_save', [ __CLASS__, 'save' ] );
		add_action( 'wp_ajax_cb_core_login_shield_test', [ __CLASS__, 'test' ] );
	}

	/**
	 * Persist the Login Shield configuration. Expects the config fields
	 * in $_POST; missing fields fall back to conservative defaults via
	 * {@see LoginShieldCore::save()}'s internal normalisation.
	 *
	 * The `enabled` field is treated specially: if absent from $_POST,
	 * the current master state is preserved. This decouples the form-
	 * batch save (URL/mode/redirect/response code) from the master
	 * switch, which has its own atomic endpoint.
	 *
	 * Responds with the resolved config (as returned by save()) plus the
	 * public custom login URL that the new slug generates - the UI uses
	 * both to update the live preview and the status banner atomically.
	 */
	public static function save(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$user    = wp_get_current_user();
		$current = LoginShieldCore::config();

		$enabled = isset( $_POST['enabled'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Request::nonce() above.
			? Request::bool( 'enabled' )
			: ! empty( $current['enabled'] );

		$incoming = [
			'enabled'              => $enabled,
			'slug'                 => Request::text( 'slug' ),
			'mode'                 => Request::text( 'mode', LoginShieldCore::MODE_STANDARD ),
			'redirect_after_login' => Request::text( 'redirect_after_login', LoginShieldCore::REDIRECT_DASHBOARD ),
			'redirect_custom_url'  => Request::text( 'redirect_custom_url' ),
			'block_response_code'  => Request::int( 'block_response_code', LoginShieldCore::RESPONSE_CODE_404 ),
		];

		// Extra validation: if Login Shield is enabled, a non-empty slug is
		// required. Enforcing this here (on top of the silent short-circuit
		// in is_enforcing()) keeps the UI honest - users see an error
		// rather than a success message for a config that won't actually
		// do anything. Applies whether the master came from $_POST or from
		// the preserved current state.
		if ( $incoming['enabled'] && '' === LoginShieldCore::sanitize_slug( $incoming['slug'] ) ) {
			wp_send_json_error(
				[ 'message' => __( 'A custom login URL is required before Login Shield can be enabled.', 'core-blueprint' ) ],
				400
			);
		}

		$resolved = LoginShieldCore::save( $incoming, 'admin:' . $user->user_login );

		wp_send_json_success( [
			'message' => __( 'Login Shield settings saved.', 'core-blueprint' ),
			'config'  => $resolved,
			'url'     => LoginShieldCore::custom_login_url(),
		] );
	}


	/**
	 * GET the configured custom login URL from the server's own perspective.
	 * A 200 response indicates the alias resolves; anything else is surfaced
	 * to the admin as a diagnostic hint.
	 *
	 * Done server-side (not client-side) so cookies - including the admin's
	 * own login cookie - don't mask what a guest would actually see.
	 */
	public static function test(): void {
		Request::nonce( 'cb_core_admin' );
		self::require_admin();

		$url = LoginShieldCore::custom_login_url();
		if ( '' === $url ) {
			wp_send_json_error( [
				'message' => __( 'No custom URL is configured.', 'core-blueprint' ),
			], 400 );
		}

		// Server-side request without cookies - approximates what a blind
		// visitor would get. 5-second timeout is enough for any healthy
		// site and caps worst-case UI latency.
		$response = wp_safe_remote_get( $url, [
			'timeout'     => 5,
			'redirection' => 2,
			'sslverify'   => (bool) apply_filters( 'cb_core_login_shield_test_sslverify', true ),
			'cookies'     => [],
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: WP_Error message from wp_safe_remote_get */
					__( 'Request failed: %s', 'core-blueprint' ),
					$response->get_error_message()
				),
				'url'     => $url,
			] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = 200 === $code;

		wp_send_json_success( [
			'ok'      => $ok,
			'code'    => $code,
			'url'     => $url,
			'message' => $ok
				? __( 'Custom login URL responds 200 OK.', 'core-blueprint' )
				: sprintf(
					/* translators: %d: HTTP status code returned by the request */
					__( 'Custom login URL responded with HTTP %d - expected 200.', 'core-blueprint' ),
					$code
				),
		] );
	}
}
