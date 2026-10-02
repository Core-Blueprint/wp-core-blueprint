<?php
declare(strict_types=1);
/**
 * LoginShield
 *
 * Passive hardening against brute-force scans of the WordPress login routes.
 * Obscures /wp-login.php behind a user-chosen slug and 404s blind scanners
 * that keep hitting the default endpoint. Does NOT rate-limit, count failed
 * attempts, or otherwise defend against targeted attacks - that remains the
 * job of dedicated security plugins (Wordfence et al.).
 *
 * Honest framing matters here: Login Shield reduces the *volume* of scanned
 * login attempts by making the endpoint invisible to blind scanners. Against
 * a determined attacker who enumerates the site or monitors form submissions,
 * obscurity alone is not protection - the accompanying UI copy reflects that.
 *
 * Enforcement gating - a request is only acted on when ALL of these hold:
 *   1. Failsafe::is_bypassed() is false (lockout-safe short-circuit, same
 *      contract as AccessMode)
 *   2. Settings::shield_enabled() is true (Core Shield master switch is on)
 *   3. login_shield.enabled is true
 *   4. login_shield.slug is non-empty
 *
 * Hook timing - two entry points, chosen deliberately:
 *   - init priority 0     - blocks direct hits on /wp-login.php and, in Strict
 *                           mode, /wp-admin. Early enough that wp-login.php's
 *                           own action handler never runs, but late enough
 *                           that pluggable.php has loaded (so is_user_logged_in
 *                           works).
 *   - wp_loaded priority 10 - serves the custom-slug alias by including
 *                             wp-login.php. By this point every init-time
 *                             hook has registered (Wordfence 2FA, WP 2FA,
 *                             password-protected post filters), so wp-login.php
 *                             runs in a fully-bootstrapped environment.
 *
 * Never blocks POST requests on /wp-login.php - 2FA-plugin callbacks,
 * password-protected post submissions, and external form handlers all POST
 * back to that URL. Blocking POST would break every one of them.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Security;

use CB\Core\Log\AuditLog;
use CB\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class LoginShield {

	public const MODE_STANDARD = 'standard';
	public const MODE_STRICT   = 'strict';

	public const REDIRECT_DASHBOARD = 'dashboard';
	public const REDIRECT_HOMEPAGE  = 'homepage';
	public const REDIRECT_CUSTOM    = 'custom';

	public const RESPONSE_CODE_404 = 404;
	public const RESPONSE_CODE_403 = 403;
	public const RESPONSE_CODE_302 = 302;

	public const MODES              = [ self::MODE_STANDARD, self::MODE_STRICT ];
	public const REDIRECT_TARGETS   = [ self::REDIRECT_DASHBOARD, self::REDIRECT_HOMEPAGE, self::REDIRECT_CUSTOM ];
	public const RESPONSE_CODES     = [ self::RESPONSE_CODE_404, self::RESPONSE_CODE_403, self::RESPONSE_CODE_302 ];

	/**
	 * Settings sub-key under the CB_CORE_SETTINGS array. Centralised here
	 * so Settings + Migrator + tests all reference the same string.
	 */
	public const SETTINGS_KEY = 'login_shield';

	/**
	 * GET actions on /wp-login.php that must remain accessible to guests.
	 * These flows either don't expose the login form (postpass) or are
	 * explicit recovery paths that users reach from outside WP (lostpassword,
	 * rp, resetpass via email link). Removing any of these would break core
	 * WP features that have nothing to do with interactive login.
	 */
	private const ACTION_WHITELIST = [ 'postpass', 'lostpassword', 'rp', 'resetpass' ];

	private static bool $bootstrapped = false;

	// ─── Bootstrap ────────────────────────────────────────────────────────

	public static function boot(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		// Disabled really means no Login Shield runtime. Settings mutations are
		// owned by the separate AJAX SecurityRouter; the next request boots the
		// canonical hookset from the newly persisted configuration.
		if ( ! self::is_enforcing() ) {
			return;
		}

		// URL-generation filters are only needed while enforcement is active.
		add_filter( 'site_url',         [ __CLASS__, 'filter_site_url' ], 10, 4 );
		add_filter( 'network_site_url', [ __CLASS__, 'filter_network_site_url' ], 10, 3 );
		add_filter( 'wp_redirect',      [ __CLASS__, 'filter_wp_redirect' ], 10, 2 );
		add_filter( 'login_url',        [ __CLASS__, 'filter_login_url' ], 10, 3 );
		add_filter( 'logout_url',       [ __CLASS__, 'filter_logout_url' ], 10, 2 );
		add_filter( 'lostpassword_url', [ __CLASS__, 'filter_lostpassword_url' ], 10, 2 );
		add_filter( 'login_redirect',   [ __CLASS__, 'filter_login_redirect' ], 20, 3 );

		// Request guards.
		add_action( 'init',      [ __CLASS__, 'guard_init' ], 0 );
		add_action( 'wp_loaded', [ __CLASS__, 'maybe_serve_alias' ], 10 );
	}

	// ─── Configuration ────────────────────────────────────────────────────

	/**
	 * Read the normalised login_shield config from the settings array.
	 * Missing keys fall back to conservative defaults (disabled, no slug).
	 */
	public static function config(): array {
		$stored = [];
		if ( class_exists( Settings::class ) ) {
			$all    = Settings::get();
			$stored = is_array( $all[ self::SETTINGS_KEY ] ?? null ) ? $all[ self::SETTINGS_KEY ] : [];
		}

		return [
			'enabled'              => ! empty( $stored['enabled'] ),
			'slug'                 => self::sanitize_slug( (string) ( $stored['slug'] ?? '' ) ),
			'mode'                 => self::normalize_mode( $stored['mode'] ?? self::MODE_STANDARD ),
			'redirect_after_login' => self::normalize_redirect( $stored['redirect_after_login'] ?? self::REDIRECT_DASHBOARD ),
			'redirect_custom_url'  => (string) ( $stored['redirect_custom_url'] ?? '' ),
			'block_response_code'  => self::normalize_response_code( $stored['block_response_code'] ?? self::RESPONSE_CODE_404 ),
		];
	}

	/**
	 * Default config shape - used by Settings::defaults() and by the migrator
	 * to seed missing values on upgrade. Single source of truth so the three
	 * callers never drift.
	 */
	public static function default_config(): array {
		return [
			'enabled'              => false,
			'slug'                 => '',
			'mode'                 => self::MODE_STANDARD,
			'redirect_after_login' => self::REDIRECT_DASHBOARD,
			'redirect_custom_url'  => '',
			'block_response_code'  => self::RESPONSE_CODE_404,
		];
	}

	/**
	 * Is Login Shield actively enforcing on this request?
	 *
	 * False when any gate is down:
	 *   - Failsafe bypass is active (lockout-safe - same contract as AccessMode)
	 *   - Core Shield master switch is off (global kill-switch consistency)
	 *   - Login Shield feature toggle is off
	 *   - No custom slug configured (nothing to enforce against)
	 */
	public static function is_enforcing(): bool {
		if ( class_exists( Failsafe::class ) && Failsafe::is_bypassed() ) {
			return false;
		}
		if ( class_exists( Settings::class ) && ! Settings::shield_enabled() ) {
			return false;
		}
		$conf = self::config();
		if ( ! $conf['enabled'] ) {
			return false;
		}
		if ( '' === $conf['slug'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Sanitise a slug: lowercase, letters + digits + hyphen only, collapse
	 * consecutive hyphens, trim leading/trailing hyphens. Matches what a
	 * user would type for a vanity URL; mirrors the client-side validation.
	 */
	public static function sanitize_slug( string $raw ): string {
		$raw = strtolower( trim( $raw ) );
		$raw = preg_replace( '/[^a-z0-9-]/', '', (string) $raw );
		$raw = preg_replace( '/-+/', '-', (string) $raw );
		return trim( (string) $raw, '-' );
	}

	/** Build the public-facing custom login URL for display + redirects. */
	public static function custom_login_url(): string {
		$conf = self::config();
		if ( '' === $conf['slug'] ) {
			return '';
		}
		return home_url( '/' . $conf['slug'] . '/' );
	}

	// ─── Request guards ───────────────────────────────────────────────────

	/**
	 * init priority 0 handler. Decides whether this request should be
	 * blocked outright (direct /wp-login.php hit without whitelist, or
	 * /wp-admin in Strict mode).
	 */
	public static function guard_init(): void {
		if ( ! self::is_enforcing() ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		// Logged-in users never get blocked - they may need /wp-login.php
		// for logout or for admin access paths. This also makes the guard
		// cheap: checking is_user_logged_in() on init is O(1) after the
		// cookie has been parsed by pluggable.php.
		if ( is_user_logged_in() ) {
			return;
		}

		$script = self::script_basename();

		// Case A: direct hit on /wp-login.php (guest, not logged in).
		if ( 'wp-login.php' === $script ) {
			self::handle_wp_login_hit();
			return;
		}

		// Case B: /wp-admin hit. Standard mode relies on auth_redirect +
		// our filtered login_url to bounce the guest to the custom slug
		// automatically; Strict mode serves 404 instead so the custom
		// slug is never revealed via redirect.
		if ( self::is_wp_admin_request( $script ) ) {
			$conf = self::config();
			if ( self::MODE_STRICT === $conf['mode'] ) {
				self::log_block( 'route_blocked', self::request_path() );
				self::send_block_response();
			}
		}
	}

	/**
	 * wp_loaded handler. If the request path matches the configured custom
	 * slug, include wp-login.php inline so the login form is served under
	 * the obscured URL without a visible redirect.
	 *
	 * Runs at wp_loaded (not earlier) so every init-time hook has registered
	 * before wp-login.php starts processing - critical for 2FA plugins that
	 * hook `wp_authenticate` during init.
	 */
	public static function maybe_serve_alias(): void {
		if ( ! self::is_enforcing() ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		// Direct /wp-login.php hits are handled by guard_init; the alias
		// path never fires for them.
		if ( 'wp-login.php' === self::script_basename() ) {
			return;
		}

		$conf = self::config();
		if ( ! self::path_matches_slug( self::request_path(), $conf['slug'] ) ) {
			return;
		}

		self::serve_alias();
	}

	/**
	 * Handle a direct hit on /wp-login.php for a guest. POST always passes
	 * (2FA + password-protected posts POST credentials here). GET is
	 * blocked unless the action is on the whitelist.
	 */
	private static function handle_wp_login_hit(): void {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'POST' === $method ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, self::ACTION_WHITELIST, true ) ) {
			return;
		}

		self::log_block( 'route_blocked', self::request_path() );
		self::send_block_response();
	}

	/**
	 * Serve wp-login.php as the response to the current custom-slug request.
	 * REQUEST_URI is rewritten so WP internals (auth_redirect, action
	 * processing, $pagenow consumers) see the expected path; the slug
	 * remains visible in the browser's address bar because we never issue
	 * a redirect - the response is rendered under the slug URL.
	 *
	 * Scope notice - wp-login.php expects to run as an entry-point script
	 * (global scope). It declares `$error`, `$action`, `$user_login`, etc.
	 * at the top of the file and references them again during form
	 * rendering hundreds of lines further down. When we `require_once` it
	 * from inside this static method, those assignments land in THIS
	 * method's local scope and the later references come up empty - which
	 * surfaces as "Undefined variable $user_login" warnings in the rendered
	 * HTML, including inside the username input's `value` attribute.
	 *
	 * Fix: promote every variable wp-login.php touches to global scope
	 * before the require. The inlined code then mutates the globals
	 * consistently, matching the semantics of a direct wp-login.php hit.
	 * Keep the list exhaustive - missing one is a silent rendering bug.
	 */
	private static function serve_alias(): void {
		$_SERVER['REQUEST_URI'] = '/wp-login.php';
		$GLOBALS['pagenow']     = 'wp-login.php';

		nocache_headers();

		// phpcs:disable Squiz.PHP.GlobalKeyword.NotAllowed, WordPress.PHP.DiscouragedPHPFunctions.global_global
		global $error, $errors, $action, $user, $user_login,
			$redirect_to, $secure_cookie, $interim_login,
			$customize_login, $switched_locale, $lang, $rememberme;
		// phpcs:enable

		// wp-login.php uses `require`, not `require_once`, for wp-load.php
		// at the top - but wp-load.php's chain into wp-config.php and
		// wp-settings.php are all `require_once`-guarded, so the inner
		// bootstrap is safely idempotent. The outer re-include of wp-load.php
		// only re-runs its top-level defines, which are also idempotent.
		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/**
	 * Terminate the request with the configured block response. Three
	 * response modes:
	 *   - 302 → redirect to the site homepage (friendly - legit visitors
	 *           who bookmarked /wp-login.php get sent somewhere useful)
	 *   - 403 → static Forbidden page (explicit - good for audit trails)
	 *   - 404 → static Not Found page (default - hides the endpoint from
	 *           fingerprinting scanners)
	 *
	 * The static responses carry no Core Blueprint branding in body or
	 * headers so they look indistinguishable from server-level errors.
	 */
	private static function send_block_response(): void {
		$conf = self::config();
		$code = $conf['block_response_code'];

		if ( self::RESPONSE_CODE_302 === $code ) {
			// Low-level redirect - avoids wp_safe_redirect's URL filter
			// detour (which runs our own site_url filter and could cause
			// surprising rewrites). home_url('/') is always a safe
			// same-origin target.
			nocache_headers();
			wp_redirect( home_url( '/' ), 302 );
			exit;
		}

		status_header( $code );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( self::RESPONSE_CODE_403 === $code ) {
			echo '<!DOCTYPE html><html><head><title>Forbidden</title></head><body><h1>Forbidden</h1><p>You do not have permission to access this resource.</p></body></html>';
		} else {
			echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>';
		}
		exit;
	}

	// ─── URL filters ──────────────────────────────────────────────────────

	public static function filter_site_url( $url, $path = '', $scheme = null, $blog_id = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::maybe_rewrite_login_url( (string) $url );
	}

	public static function filter_network_site_url( $url, $path = '', $scheme = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::maybe_rewrite_login_url( (string) $url );
	}

	public static function filter_wp_redirect( $location, $status = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::maybe_rewrite_login_url( (string) $location );
	}

	public static function filter_login_url( $login_url, $redirect = '', $force_reauth = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::maybe_rewrite_login_url( (string) $login_url );
	}

	public static function filter_logout_url( $logout_url, $redirect = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::maybe_rewrite_login_url( (string) $logout_url );
	}

	public static function filter_lostpassword_url( $lostpassword_url, $redirect = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return self::maybe_rewrite_login_url( (string) $lostpassword_url );
	}

	/**
	 * Rewrite any URL containing `wp-login.php` to point at the custom
	 * slug instead. Preserves scheme, host, and query string - only the
	 * path component `wp-login.php` is swapped for the slug.
	 *
	 * Handles three URL forms WordPress can emit:
	 *
	 *   1. Absolute       - `https://site.com/wp-login.php?...`
	 *   2. Site-relative  - `/wp-login.php?...`
	 *   3. Page-relative  - `wp-login.php?...`  (no leading slash)
	 *
	 * Form 3 is what trips up logout in some configurations: WordPress
	 * core's wp-login.php logout handler hardcodes the relative string
	 * `'wp-login.php?loggedout=true&...'` as the default
	 * `logout_redirect` value. wp_safe_redirect → wp_validate_redirect
	 * absolutises this against REQUEST_URI before our wp_redirect filter
	 * sees it (so in practice form 2 covers logout) - but we handle
	 * form 3 explicitly anyway for paths that emit a relative URL
	 * without going through wp_validate_redirect first (registration-
	 * disabled emissions, certain lostpassword flows).
	 *
	 * Unconditional rewrite when enforcing
	 * ────────────────────────────────────
	 * Earlier versions of this method carried an additional guard that
	 * skipped rewriting while the alias was being served (i.e. while
	 * wp-login.php was being rendered under the custom slug). That guard
	 * was overbroad: it suppressed rewrites in three places that needed
	 * them - outgoing wp_redirect Location headers (post-logout 404 in
	 * Strict mode), form actions inside the rendered login form (slug
	 * leaked to the URL bar after a failed-credentials roundtrip), and
	 * lostpassword redirects (same leak class). Removed in 1.3.3 - the
	 * rewrite is now unconditional whenever the feature is enforcing
	 * and the URL contains 'wp-login.php'. There is no scenario where
	 * we want to *not* rewrite under those two conditions.
	 *
	 * No infinite-loop risk: this method does not call itself, and the
	 * filters that invoke it (`site_url`, `wp_redirect`, `login_url`,
	 * etc.) are pure URL transformations that do not re-trigger the
	 * containing rendering pipeline.
	 */
	private static function maybe_rewrite_login_url( string $url ): string {
		if ( ! self::is_enforcing() ) {
			return $url;
		}
		if ( false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}
		$conf = self::config();
		$slug = $conf['slug'];

		// Page-relative form (no leading slash). Detect by URL start;
		// rewrite the prefix in place so the relative-resolution at the
		// browser still lands on the correct path.
		if ( 0 === strpos( $url, 'wp-login.php' ) ) {
			return $slug . '/' . substr( $url, strlen( 'wp-login.php' ) );
		}

		return str_replace( '/wp-login.php', '/' . $slug . '/', $url );
	}

	/**
	 * Apply the configured post-login redirect, but only when nothing
	 * upstream has already claimed a more specific target.
	 *
	 * Precedence, highest-first:
	 *
	 *   1. Form-level redirect (`$requested_redirect_to` non-empty) - always
	 *      wins. Covers Bricks / BricksForge / Elementor / Gravity Forms
	 *      login forms that ship their own `redirect_to` field, and WP's
	 *      own `?redirect_to=` query parameter.
	 *
	 *   2. Plugin-level role redirect (WooCommerce sending customers to
	 *      /my-account/, membership plugins sending members to a member
	 *      area, LMS plugins sending students to a course dashboard). These
	 *      register on `login_redirect` at the default priority (10) and
	 *      mutate `$redirect_to` based on the user's role. Login Shield
	 *      runs at priority 20, so by the time this filter fires those
	 *      plugins have already spoken - we check whether `$redirect_to`
	 *      is still one of WP's defaults, and if not, stand down.
	 *
	 *   3. Login Shield's configured `redirect_after_login` setting
	 *      (Dashboard / Homepage / Custom URL) - applied only when the
	 *      request had no form-level redirect AND no plugin modified the
	 *      target to something meaningful. This is the fallback for
	 *      straightforward sites without role-based routing.
	 *
	 * Heuristic for "default target" - the values WP core sets when no
	 * filter intervenes: `admin_url()`, `admin_url('profile.php')`,
	 * `user_admin_url()` (multisite), or an empty string. Comparisons
	 * use `untrailingslashit()` because WP's own construction is
	 * inconsistent with the trailing slash.
	 */
	public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! empty( $requested_redirect_to ) ) {
			return $redirect_to;
		}
		if ( is_wp_error( $user ) || ! ( $user instanceof \WP_User ) ) {
			return $redirect_to;
		}
		if ( ! self::is_enforcing() ) {
			return $redirect_to;
		}
		if ( ! self::is_default_redirect_target( (string) $redirect_to ) ) {
			// Something upstream - WooCommerce, a membership plugin, an LMS
			// - has an opinion about where this specific user should go.
			// Defer to them; they have role context we don't.
			return $redirect_to;
		}

		$conf = self::config();
		switch ( $conf['redirect_after_login'] ) {
			case self::REDIRECT_HOMEPAGE:
				return home_url( '/' );
			case self::REDIRECT_CUSTOM:
				$custom = trim( (string) $conf['redirect_custom_url'] );
				return '' !== $custom ? $custom : $redirect_to;
			case self::REDIRECT_DASHBOARD:
			default:
				return $redirect_to;
		}
	}

	/**
	 * Is $redirect_to still one of WP's default post-login destinations?
	 * When true, nothing upstream claimed it and Login Shield can override
	 * with the configured target. When false, some other filter set a more
	 * specific URL and Login Shield stands down.
	 *
	 * The list intentionally stays short and literal - trying to pattern-
	 * match "looks like a default" produces false positives (e.g. a
	 * membership plugin that sends members to `/wp-admin/?page=members`
	 * would match an overly-loose wp-admin prefix check). Exact URL
	 * comparison keeps the contract predictable; rare defaults we miss
	 * just mean Login Shield's configured choice still applies, which is
	 * the pre-fix behaviour and no worse than shipping without this check.
	 */
	private static function is_default_redirect_target( string $redirect_to ): bool {
		if ( '' === $redirect_to ) {
			return true;
		}

		$defaults = [
			untrailingslashit( admin_url() ),
			untrailingslashit( admin_url( 'profile.php' ) ),
			untrailingslashit( home_url() ),
		];
		if ( function_exists( 'user_admin_url' ) ) {
			$defaults[] = untrailingslashit( user_admin_url() );
		}

		return in_array( untrailingslashit( $redirect_to ), $defaults, true );
	}

	// ─── Settings persistence ─────────────────────────────────────────────

	/**
	 * Persist a full config array. Returns the normalised config that was
	 * actually written. Audit-logs enable/disable and slug transitions as
	 * separate entries so the log reads chronologically.
	 */
	public static function save( array $incoming, string $actor = 'unknown' ): array {
		$settings = Settings::get();
		$previous = is_array( $settings[ self::SETTINGS_KEY ] ?? null ) ? $settings[ self::SETTINGS_KEY ] : [];

		$normalised = [
			'enabled'              => ! empty( $incoming['enabled'] ),
			'slug'                 => self::sanitize_slug( (string) ( $incoming['slug'] ?? '' ) ),
			'mode'                 => self::normalize_mode( $incoming['mode'] ?? self::MODE_STANDARD ),
			'redirect_after_login' => self::normalize_redirect( $incoming['redirect_after_login'] ?? self::REDIRECT_DASHBOARD ),
			'redirect_custom_url'  => esc_url_raw( (string) ( $incoming['redirect_custom_url'] ?? '' ) ),
			'block_response_code'  => self::normalize_response_code( $incoming['block_response_code'] ?? self::RESPONSE_CODE_404 ),
		];

		Settings::set_key( self::SETTINGS_KEY, $normalised, $actor );

		if ( class_exists( AuditLog::class ) ) {
			$was_enabled = ! empty( $previous['enabled'] );
			if ( $was_enabled !== $normalised['enabled'] ) {
				AuditLog::log(
					$normalised['enabled'] ? 'login.shield_enabled' : 'login.shield_disabled',
					'notice',
					[
						'slug'  => $normalised['slug'],
						'mode'  => $normalised['mode'],
						'actor' => $actor,
					]
				);
			}

			$prev_slug = (string) ( $previous['slug'] ?? '' );
			if ( $prev_slug !== $normalised['slug'] && '' !== $normalised['slug'] ) {
				AuditLog::log( 'login.url_changed', 'notice', [
					'from'  => $prev_slug,
					'to'    => $normalised['slug'],
					'actor' => $actor,
				] );
			}
		}

		return $normalised;
	}

	// ─── Normalisers ──────────────────────────────────────────────────────

	private static function normalize_mode( $mode ): string {
		return in_array( $mode, self::MODES, true ) ? (string) $mode : self::MODE_STANDARD;
	}

	private static function normalize_redirect( $redirect ): string {
		return in_array( $redirect, self::REDIRECT_TARGETS, true ) ? (string) $redirect : self::REDIRECT_DASHBOARD;
	}

	private static function normalize_response_code( $code ): int {
		$code = (int) $code;
		return in_array( $code, self::RESPONSE_CODES, true ) ? $code : self::RESPONSE_CODE_404;
	}

	// ─── Request helpers ──────────────────────────────────────────────────

	private static function request_path(): string {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	private static function script_basename(): string {
		if ( empty( $_SERVER['SCRIPT_NAME'] ) ) {
			return '';
		}
		return basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) );
	}

	/**
	 * Does the request path correspond to /wp-admin/*? Deliberately excludes
	 * admin-ajax.php and admin-post.php - both of those expose legitimate
	 * nopriv endpoints that must remain reachable by guests.
	 */
	private static function is_wp_admin_request( string $script ): bool {
		if ( 'admin-ajax.php' === $script || 'admin-post.php' === $script ) {
			return false;
		}
		$path      = self::request_path();
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? rtrim( $home_path, '/' ) : '';
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = '/' . ltrim( $path, '/' );
		return 0 === strpos( $path, '/wp-admin' );
	}

	/**
	 * Does the given request path match the configured slug? Accepts the
	 * slug with or without a trailing slash. Accounts for WP installs
	 * living under a subdirectory (site URL `/wp/`).
	 */
	private static function path_matches_slug( string $path, string $slug ): bool {
		if ( '' === $slug || '' === $path ) {
			return false;
		}
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? rtrim( $home_path, '/' ) : '';
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = '/' . ltrim( $path, '/' );
		return ( '/' . $slug === $path ) || ( '/' . $slug . '/' === $path );
	}

	/**
	 * Queue a block event to the audit log. Uses queue() rather than log()
	 * because these are high-volume on a public site (blind bot scans easily
	 * generate hundreds per day) - batching on shutdown avoids a per-request
	 * DB write for every dropped bot, and the dedup window in AuditLog
	 * collapses bursts to a single entry per 60 seconds when the context
	 * repeats. Context is deliberately minimal: path + method, no cookies,
	 * no request body, no nonces.
	 */
	private static function log_block( string $event_suffix, string $path ): void {
		if ( ! class_exists( AuditLog::class ) ) {
			return;
		}
		AuditLog::queue(
			'login.' . $event_suffix,
			'info',
			[
				'path'   => substr( $path, 0, 120 ),
				'method' => strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ),
			]
		);
	}
}
