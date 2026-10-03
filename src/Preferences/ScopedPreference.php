<?php
declare(strict_types=1);
/**
 * ScopedPreference - shared persistence behaviour for preferences that
 * resolve user override → site default → fallback.
 *
 * Three places in Core Blueprint follow this pattern: Themes, Locale, and
 * the UI description-mode preference. This trait consolidates the
 * identical set_user() / set_site_default() / raw accessors across them,
 * so a new preference only needs to declare its constants + validator.
 *
 * Consumers (see {@see Themes}, {@see Locale}) must:
 *   1. Declare three class constants:
 *        - USER_META_KEY     (string)  wp_usermeta key
 *        - SITE_OPTION_KEY   (string)  wp_options key
 *        - AUDIT_EVENT       (string)  event slug for AuditLog entries
 *   2. Implement `is_valid( string $value ): bool` that decides whether
 *      a value can be stored. Kept abstract because validators vary:
 *      Themes checks against a dynamic registry, Locale checks against
 *      a filterable allowlist, future preferences may want a regex.
 *   3. Implement `site_changed_action(): string` returning the action
 *      hook fired on site-default change. Class-level method instead of
 *      a fourth constant to preserve the established public preference contract. Core Blueprint now
 *      requires PHP 8.4+, so this is an API-stability choice rather than a
 *      runtime-compatibility workaround.
 *
 * Current-value resolution stays in the consumer class - each preference
 * has its own fall-through semantics (Locale defers to WordPress's own
 * locale, Themes has a client-side prefers-color-scheme step) that don't
 * generalise cleanly. The trait only owns the persistence + validation.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\Preferences;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

trait ScopedPreference {

	/**
	 * Validate a candidate value. Implementation varies per preference.
	 */
	abstract public static function is_valid( string $value ): bool;

	/**
	 * Return the action-hook slug to fire on site-default change.
	 * Receives ($new_value, $previous_value).
	 */
	abstract public static function site_changed_action(): string;

	// ─── User-scope persistence ───────────────────────────────────────────────

	/**
	 * Persist a user-level override. Empty string clears the override so
	 * the user falls back to the site default on the next read.
	 *
	 * Returns false for unknown values or when no user id can be determined.
	 */
	public static function set_user( int $user_id, string $value ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( '' === $value ) {
			return (bool) delete_user_meta( $user_id, static::USER_META_KEY );
		}
		if ( ! static::is_valid( $value ) ) {
			return false;
		}
		return (bool) update_user_meta( $user_id, static::USER_META_KEY, $value );
	}

	/**
	 * Raw user-level preference. Returns '' when no override is stored -
	 * distinct from a deliberately-empty override.
	 *
	 * @param int $user_id User ID; 0 means current user.
	 */
	public static function raw_user_preference( int $user_id = 0 ): string {
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}
		if ( $user_id <= 0 ) {
			return '';
		}
		$val = get_user_meta( $user_id, static::USER_META_KEY, true );
		return is_string( $val ) ? $val : '';
	}

	// ─── Site-scope persistence ───────────────────────────────────────────────

	/**
	 * Persist the site-wide default. Fires the consumer's changed-action
	 * when the value actually changes, and emits an audit-log entry.
	 *
	 * Returns false for invalid values; true on successful write (regardless
	 * of whether the value actually differed from the prior one).
	 */
	public static function set_site_default( string $value ): bool {
		if ( ! static::is_valid( $value ) ) {
			return false;
		}

		$before = static::raw_site_default();
		$ok     = update_option( static::SITE_OPTION_KEY, $value, false );

		if ( $ok && $before !== $value ) {
			do_action( static::site_changed_action(), $value, $before );
			if ( class_exists( AuditLog::class ) ) {
				AuditLog::log( static::AUDIT_EVENT, 'info', [
					'from' => $before,
					'to'   => $value,
				] );
			}
		}
		return $ok;
	}

	/**
	 * Raw site-level default. Returns the stored string exactly as written,
	 * without validation - callers that need a guaranteed-valid value
	 * should route through the consumer's own current() resolver.
	 */
	public static function raw_site_default(): string {
		$val = get_option( static::SITE_OPTION_KEY, '' );
		return is_string( $val ) ? $val : '';
	}
}
