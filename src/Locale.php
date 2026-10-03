<?php
declare(strict_types=1);
/**
 * Locale
 *
 * Site-wide locale preference integrated with WordPress core's `locale` filter.
 *
 * Keys are deliberately brand-level (cb_locale, cb_locale_default) rather than
 * plugin-prefixed - sibling CB plugins read them directly, not via Core Blueprint.
 *
 * Resolution chain for ::current() :
 *   1. user_meta 'cb_locale'              (empty or 'auto' → fall through)
 *   2. option    'cb_locale_default'      (same behaviour)
 *   3. WordPress's own get_locale()       (WP user locale / site locale)
 *   4. 'en_US'
 *
 * WP integration: hooked on 'locale' filter at priority 20. Multilingual
 * plugins (Polylang, WPML) typically run at priority 10, so they remain
 * authoritative. Core Blueprint only fills in when nobody else has spoken.
 *
 * Security:
 *   - Allowlist enforced on every write
 *   - Nonce + capability checks live in Router (not here)
 *   - Runtime reads never consume $_GET / $_POST - spoofing prevention
 *   - All corrupt/unknown stored values fall through silently rather than
 *     breaking WP's locale chain
 *   - Site-default changes are audit-logged (via M2 audit log when present)
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core;

use CoreBlueprint\Core\Preferences\ScopedPreference;

defined( 'ABSPATH' ) || exit;

final class Locale {

	use ScopedPreference;

	const USER_META_KEY   = 'cb_locale';
	const SITE_OPTION_KEY = 'cb_locale_default';
	const AUDIT_EVENT     = 'locale.site_default_changed';
	const AUTO_MODE       = 'auto';
	const FALLBACK        = 'en_US';

	/** Reentrancy guard for the WP `locale` filter. */
	private static bool $in_filter = false;

	/**
	 * Validator used by {@see ScopedPreference::set_user()} and
	 * ::set_site_default(). Delegates to the filterable allowlist.
	 */
	public static function is_valid( string $value ): bool {
		return self::is_allowed( $value );
	}

	/** Action hook fired on site-default change. See trait docs. */
	public static function site_changed_action(): string {
		return 'cb_core_locale_default_changed';
	}

	// ─── Allowlist ────────────────────────────────────────────────────────────

	/** Locale codes shipped and recognised by Core Blueprint. */
	public static function allowed(): array {
		$defaults = [ self::AUTO_MODE, 'en_US', 'nl_NL', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'pt_PT' ];
		/**
		 * Filter: cb_core_locale_allowed
		 *
		 * Extend the locale allowlist. Each entry must be either 'auto' or a
		 * valid WordPress locale code (language_COUNTRY).
		 *
		 * @param string[] $allowed
		 */
		$allowed = apply_filters( 'cb_core_locale_allowed', $defaults );
		$allowed = array_values( array_filter( array_map( 'strval', (array) $allowed ), static fn( $v ) => '' !== $v ) );
		return array_unique( $allowed );
	}

	/** Is this code in the allowlist? */
	public static function is_allowed( string $code ): bool {
		return in_array( $code, self::allowed(), true );
	}

	// ─── Resolution ───────────────────────────────────────────────────────────

	/**
	 * The resolved locale for the current user. Never returns 'auto' - it
	 * always returns a concrete locale code. Cached per-request is intentionally
	 * avoided because switch_to_user_locale() etc. expect a fresh read.
	 */
	public static function current(): string {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$pref = (string) get_user_meta( $user_id, self::USER_META_KEY, true );
			if ( '' !== $pref && self::AUTO_MODE !== $pref && self::is_allowed( $pref ) ) {
				return $pref;
			}
		}

		$site = (string) get_option( self::SITE_OPTION_KEY, self::AUTO_MODE );
		if ( '' !== $site && self::AUTO_MODE !== $site && self::is_allowed( $site ) ) {
			return $site;
		}

		// Fall through to WordPress's own resolution, avoiding our own filter
		// entry to prevent infinite recursion.
		$wp = self::in_filter() ? self::FALLBACK : get_locale();
		return is_string( $wp ) && '' !== $wp ? $wp : self::FALLBACK;
	}

	/** Raw user-level preference including 'auto' and empty. */
	public static function user_preference( int $user_id = 0 ): string {
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}
		if ( $user_id <= 0 ) {
			return '';
		}
		return (string) get_user_meta( $user_id, self::USER_META_KEY, true );
	}

	/** Raw site default including 'auto'. */
	public static function site_default(): string {
		$v = (string) get_option( self::SITE_OPTION_KEY, self::AUTO_MODE );
		return '' === $v ? self::AUTO_MODE : $v;
	}

	// ─── WP integration ───────────────────────────────────────────────────────

	/**
	 * Registered on WP core's 'locale' filter at priority 20. Returns the
	 * Core Blueprint-resolved locale when it produces a value, otherwise passes
	 * the incoming $locale through unchanged.
	 *
	 * The re-entry guard prevents infinite loops when ::current() internally
	 * calls get_locale() (which fires this same filter).
	 */
	public static function filter_wp_locale( $locale ) {
		if ( self::$in_filter ) {
			return $locale;
		}
		self::$in_filter = true;
		try {
			// Check user + site preferences only. Do NOT call get_locale() from
			// here - that would recurse. If neither preference yields a value,
			// return $locale unchanged and let WP continue its chain.
			$user_id = get_current_user_id();
			if ( $user_id > 0 ) {
				$pref = (string) get_user_meta( $user_id, self::USER_META_KEY, true );
				if ( '' !== $pref && self::AUTO_MODE !== $pref && self::is_allowed( $pref ) ) {
					return $pref;
				}
			}
			$site = (string) get_option( self::SITE_OPTION_KEY, self::AUTO_MODE );
			if ( '' !== $site && self::AUTO_MODE !== $site && self::is_allowed( $site ) ) {
				return $site;
			}
			return $locale;
		} finally {
			self::$in_filter = false;
		}
	}

	/** Are we inside the WP locale filter? Used by ::current() to avoid recursion. */
	private static function in_filter(): bool {
		return self::$in_filter;
	}

	// ─── Display helpers ──────────────────────────────────────────────────────

	/**
	 * Human label for a locale code. Uses the endonym (name in the language
	 * itself) so users see their own language in their own script.
	 */
	public static function label( string $code ): string {
		$labels = [
			self::AUTO_MODE => __( 'Automatic', 'core-blueprint' ),
			'en_US'         => 'English (US)',
			'nl_NL'         => 'Nederlands',
			'de_DE'         => 'Deutsch',
			'fr_FR'         => 'Français',
			'es_ES'         => 'Español',
			'it_IT'         => 'Italiano',
			'pt_PT'         => 'Português',
		];
		/**
		 * Filter: cb_core_locale_labels
		 *
		 * Extend the code → endonym map.
		 */
		$labels = apply_filters( 'cb_core_locale_labels', $labels );
		return (string) ( $labels[ $code ] ?? $code );
	}
}
