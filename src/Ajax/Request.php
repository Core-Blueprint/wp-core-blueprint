<?php
declare(strict_types=1);
/**
 * Request - typed input + nonce + capability helpers for AJAX handlers.
 *
 * Every handler in CoreBlueprint\Core\Ajax opens with the same four moves: verify
 * the nonce, check the capability, read + sanitize a $_POST field, and
 * reject the request with a 400 if the input is missing or invalid. Four
 * moves × 24 handlers × ~5 lines each = a lot of repeated boilerplate.
 *
 * This class folds all four into helpers that terminate the request on
 * failure via wp_send_json_error(), so handlers read as business logic:
 *
 *     Request::nonce( 'cb_core_theme' );
 *     Request::cap( 'manage_options' );
 *     $mode = Request::sanitize_key( 'mode', Settings::SITE_MODES );
 *     // ... business logic with $mode guaranteed valid ...
 *
 * Design notes:
 *   - Every helper terminates the request on failure. No mixed return
 *     types - a returned value is always the (sanitized, validated)
 *     input, never false/null/error-array.
 *   - Error messages are generic by design ("%s is required", "Invalid
 *     %s") to avoid leaking internals. Handlers can still craft custom
 *     errors when a domain-specific message matters by reading $_POST
 *     directly and sending their own error.
 *   - The class is a companion to {@see Guards}, not a replacement.
 *     Guards covers auth/session concerns (require_admin,
 *     require_password_reconfirm), Request covers request-shape.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Ajax;

defined( 'ABSPATH' ) || exit;

final class Request {

	// ─── Preamble guards ──────────────────────────────────────────────────────

	/**
	 * Verify an AJAX nonce. Terminates with wp_send_json_error() on failure.
	 * Thin wrapper around check_ajax_referer() - exists so handlers can
	 * adopt a single, consistent opening line.
	 *
	 * @param string $action The nonce action name (e.g. 'cb_core_admin').
	 * @param string $field  Request field holding the nonce (default 'nonce').
	 */
	public static function nonce( string $action, string $field = 'nonce' ): void {
		check_ajax_referer( $action, $field );
	}

	/**
	 * Capability gate. Terminates with 403 when the current user lacks
	 * the requested capability.
	 */
	public static function cap( string $capability = 'manage_options' ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'core-blueprint' ) ], 403 );
		}
	}

	// ─── Typed input ──────────────────────────────────────────────────────────

	/**
	 * Read a slug-like $_POST field. Applies sanitize_key(), requires a
	 * non-empty value, and optionally validates against an allowlist.
	 *
	 * Terminates with 400 when the field is missing/empty, or when a value
	 * is supplied that isn't in $allowed.
	 *
	 * @param string        $field    Form field name.
	 * @param string[]|null $allowed  Optional allowlist. When provided, the
	 *                                 value must be one of these strings.
	 */
	public static function sanitize_key( string $field, ?array $allowed = null ): string {
		$val = isset( $_POST[ $field ] )
			? sanitize_key( wp_unslash( $_POST[ $field ] ) )
			: ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $val ) {
			wp_send_json_error( [
				/* translators: %s: form field name that was missing */
				'message' => sprintf( __( 'Missing required parameter: %s.', 'core-blueprint' ), $field ),
			], 400 );
		}

		if ( null !== $allowed && ! in_array( $val, $allowed, true ) ) {
			wp_send_json_error( [
				/* translators: %s: form field name that received an invalid value */
				'message' => sprintf( __( 'Invalid value for %s.', 'core-blueprint' ), $field ),
			], 400 );
		}

		return $val;
	}

	/**
	 * Read a free-form text $_POST field. Applies sanitize_text_field().
	 * Returns '' when missing - does NOT terminate; the caller decides
	 * whether empty is acceptable.
	 *
	 * Use sanitize_key() when the value must be slug-shaped, or this when
	 * the value is a label, search query, or locale code that may contain
	 * hyphens/underscores.
	 */
	public static function text( string $field, string $default = '' ): string {
		if ( ! isset( $_POST[ $field ] ) ) {
			return $default;
		}
		return sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Read a boolean $_POST field. Accepts 'true'/'false', '1'/'0',
	 * 'yes'/'no', on/off - anything filter_var() with FILTER_VALIDATE_BOOLEAN
	 * understands.
	 *
	 * Terminates with 400 when the field is missing or unparseable - so
	 * handlers don't have to distinguish "missing" from "explicit false".
	 */
	public static function bool( string $field ): bool {
		if ( ! isset( $_POST[ $field ] ) ) {
			wp_send_json_error( [
				/* translators: %s: form field name that was missing */
				'message' => sprintf( __( 'Missing required parameter: %s.', 'core-blueprint' ), $field ),
			], 400 );
		}
		if ( ! is_scalar( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ownership belongs to the public handler.
			wp_send_json_error( [
				/* translators: %s: request field name. */
				'message' => sprintf( __( 'Invalid boolean value for %s.', 'core-blueprint' ), $field ),
			], 400 );
		}
		$parsed = filter_var( wp_unslash( (string) $_POST[ $field ] ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ownership belongs to the public handler.
		if ( null === $parsed ) {
			wp_send_json_error( [
				/* translators: %s: request field name. */
				'message' => sprintf( __( 'Invalid boolean value for %s.', 'core-blueprint' ), $field ),
			], 400 );
		}
		return (bool) $parsed;
	}

	/**
	 * Read an integer $_POST field. Always returns an int - no termination
	 * on missing values; returns $default instead so handlers can tolerate
	 * optional numeric inputs without extra isset() dance.
	 */
	public static function int( string $field, int $default = 0 ): int {
		if ( ! isset( $_POST[ $field ] ) || ! is_scalar( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ownership belongs to the public handler.
			return $default;
		}
		return (int) sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ownership belongs to the public handler.
	}
}
