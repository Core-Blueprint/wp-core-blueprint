<?php
declare(strict_types=1);
/**
 * Themes
 *
 * Admin theme registry for the Core Blueprint suite. Two themes ship by
 * default - Core Blueprint - Dark and Core Blueprint - Light - plus an
 * Auto mode that respects the browser's prefers-color-scheme (defaulting
 * to Core Blueprint - Dark when the user has no explicit preference).
 *
 * Partners and sibling CB plugins may register their own themes via the
 * brand-level filter `cb_admin_themes`.
 *
 * Resolution chain (first match wins):
 *   1. user_meta 'cb_core_theme'       (empty = inherit site default)
 *   2. option    'cb_core_theme_default'
 *   3. 'auto'   → client-side prefers-color-scheme, defaulting to Dark
 *   4. hardcoded fallback: 'core_blueprint_dark'
 *
 * DOM hooks applied on Core Blueprint admin pages:
 *   - body[data-cb-theme="{slug}"]
 *   - body[data-cb-mode="dark"|"light"] (only for Core Blueprint themes)
 *   - body.cb-core-theme-{slug}
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core;

use CB\Core\Preferences\ScopedPreference;

defined( 'ABSPATH' ) || exit;

final class Themes {

	use ScopedPreference;

	const USER_META_KEY     = 'cb_core_theme';
	const SITE_OPTION_KEY   = 'cb_core_theme_default';
	const AUDIT_EVENT       = 'theme.site_default_changed';
	const DEFAULT_THEME     = 'core_blueprint_dark';
	const AUTO_MODE         = 'auto';

	const SLUG_CB_DARK      = 'core_blueprint_dark';
	const SLUG_CB_LIGHT     = 'core_blueprint_light';

	/** @var array<string, array>|null Cached normalised registry. */
	private static ?array $cache = null;

	/**
	 * Validator used by {@see ScopedPreference::set_user()} and
	 * ::set_site_default(). Allows 'auto' plus any registered theme slug.
	 */
	public static function is_valid( string $value ): bool {
		return self::AUTO_MODE === $value || self::is_known( $value );
	}

	/** Action hook fired on site-default change. See trait docs. */
	public static function site_changed_action(): string {
		return 'cb_core_admin_theme_changed';
	}

	// ─── Registry ─────────────────────────────────────────────────────────────

	/**
	 * All registered themes keyed by slug.
	 *
	 * Each entry:
	 *   - label          string   Human-readable name
	 *   - description    string   One-line description
	 *   - mode           string   'dark' | 'light' | 'custom'
	 *   - family         string   'core_blueprint' | 'partner'
	 *   - css_url        string   URL to CSS file (empty for built-ins)
	 *   - author         string   Credit
	 *   - preview_svg    string   Optional inline SVG preview
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		// Built-in themes. Partner/third-party themes register via the
		// `cb_admin_themes` filter below.
		$themes = [
			self::SLUG_CB_DARK => [
				'label'       => __( 'Core Blueprint - Dark', 'core-blueprint' ),
				'description' => __( 'Futuristic dark theme. The brand-default visual mode.', 'core-blueprint' ),
				'mode'        => 'dark',
				'family'      => 'core_blueprint',
				'css_url'     => '',
				'author'      => 'Core Blueprint',
			],
			self::SLUG_CB_LIGHT => [
				'label'       => __( 'Core Blueprint - Light', 'core-blueprint' ),
				'description' => __( 'Light variant of the Core Blueprint theme. Same token set, light values.', 'core-blueprint' ),
				'mode'        => 'light',
				'family'      => 'core_blueprint',
				'css_url'     => '',
				'author'      => 'Core Blueprint',
			],
		];

		/**
		 * Filter: cb_admin_themes (brand-level)
		 *
		 * Register custom admin themes for the Core Blueprint suite.
		 *
		 * @param array<string, array> $themes Registered themes keyed by slug.
		 */
		$themes = apply_filters( 'cb_admin_themes', $themes );

		self::$cache = self::normalize( $themes );
		return self::$cache;
	}

	/**
	 * Resolve the effective theme for the current context. Returns a concrete
	 * slug - 'auto' is never returned; it is resolved to Dark server-side and
	 * flipped to Light client-side when prefers-color-scheme indicates light.
	 */
	public static function current(): string {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$pref = (string) get_user_meta( $user_id, self::USER_META_KEY, true );
			if ( '' !== $pref && self::AUTO_MODE !== $pref && self::is_known( $pref ) ) {
				return $pref;
			}
		}

		$site = (string) get_option( self::SITE_OPTION_KEY, self::AUTO_MODE );
		if ( '' !== $site && self::AUTO_MODE !== $site && self::is_known( $site ) ) {
			return $site;
		}

		// Auto: default to Dark server-side. The client script may flip to
		// Light in-page when prefers-color-scheme matches.
		return self::DEFAULT_THEME;
	}

	/** Raw user preference including 'auto'/''. Alias for trait's raw_user_preference(). */
	public static function user_preference( int $user_id = 0 ): string {
		return self::raw_user_preference( $user_id );
	}

	/** Raw site default including 'auto'. Normalises '' → 'auto'. */
	public static function site_default(): string {
		$v = self::raw_site_default();
		return '' === $v ? self::AUTO_MODE : $v;
	}

	/** Is this slug currently active for the current user? */
	public static function is_active( string $slug ): bool {
		return self::current() === $slug;
	}

	/** Does this slug refer to a registered theme? */
	public static function is_known( string $slug ): bool {
		$all = self::all();
		return isset( $all[ $slug ] );
	}

	/**
	 * Mode (`dark` | `light` | `custom`) of the active theme. Reads
	 * `current()`'s slug and looks up its mode in the registry. Falls
	 * back to `dark` when the active slug isn't registered (defensive
	 * against stale user_meta after a theme is unregistered).
	 *
	 * @since   1.0.0
	 */
	public static function current_mode(): string {
		$slug = self::current();
		$all  = self::all();
		$mode = $all[ $slug ]['mode'] ?? 'dark';
		return is_string( $mode ) && '' !== $mode ? $mode : 'dark';
	}

	/**
	 * Find the registered theme slug that pairs with the current one
	 * for a binary dark/light toggle. Looks for the first theme of the
	 * opposite mode in the same brand family; falls back to any theme
	 * of the opposite mode; falls back to the current slug as a last
	 * resort (toggle becomes a no-op rather than throwing).
	 *
	 * Used by the HUD header theme-toggle so partner brands that ship
	 * their own dark + light pair Just Work without hardcoded mappings.
	 *
	 * @since   1.0.0
	 */
	public static function opposite_slug(): string {
		$current_slug = self::current();
		$all          = self::all();
		$current_mode = $all[ $current_slug ]['mode']   ?? 'dark';
		$current_fam  = $all[ $current_slug ]['family'] ?? '';

		$target_mode = ( 'dark' === $current_mode ) ? 'light' : 'dark';

		// First pass: same family, opposite mode (matched pair).
		foreach ( $all as $slug => $entry ) {
			if ( ( $entry['mode'] ?? '' ) === $target_mode
			  && ( $entry['family'] ?? '' ) === $current_fam ) {
				return (string) $slug;
			}
		}

		// Second pass: any theme of the opposite mode.
		foreach ( $all as $slug => $entry ) {
			if ( ( $entry['mode'] ?? '' ) === $target_mode ) {
				return (string) $slug;
			}
		}

		// No counterpart registered — return the current slug; toggle
		// becomes a no-op rather than a 400 from the REST endpoint.
		return $current_slug;
	}

	/** Is the user/site configured for Auto mode? */
	public static function is_auto_mode(): bool {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$pref = (string) get_user_meta( $user_id, self::USER_META_KEY, true );
			if ( self::AUTO_MODE === $pref ) {
				return true;
			}
			if ( '' !== $pref ) {
				return false;
			}
		}
		return self::AUTO_MODE === (string) get_option( self::SITE_OPTION_KEY, self::AUTO_MODE );
	}

	// ─── DOM hooks ────────────────────────────────────────────────────────────

	/**
	 * Inject the data attributes and anti-FOUC pre-paint style into <head> so
	 * the theme is applied before first paint. Runs on `admin_head` priority 1.
	 */
	public static function emit_prepaint_hooks(): void {
		if ( ! self::on_cb_admin_screen() ) {
			return;
		}

		$slug      = self::current();
		$auto      = self::is_auto_mode() ? 'true' : 'false';
		$all       = self::all();
		$mode      = $all[ $slug ]['mode'] ?? 'dark';

		// Inline script: sets data-cb-theme on <html> IMMEDIATELY (before body
		// parse). CSS rules scoped under html[data-cb-theme="..."] (in
		// tokens.css and themes/canvas.css) then apply without FOUC and
		// without !important. Body attribute is set too once body exists,
		// so every existing body[...]-scoped rule keeps working.
		$slug_js = esc_js( $slug );
		$mode_js = esc_js( $mode );
		?>
<script id="cb-core-theme-apply">
(function(){
	var slug = <?php echo wp_json_encode( $slug_js ); ?>;
	var mode = <?php echo wp_json_encode( $mode_js ); ?>;
	var auto = <?php echo wp_json_encode( $auto ); ?> === 'true';
	function effectiveSlug() {
		if (!auto) return slug;
		if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
			return <?php echo wp_json_encode( self::SLUG_CB_LIGHT ); ?>;
		}
		return <?php echo wp_json_encode( self::SLUG_CB_DARK ); ?>;
	}
	function effectiveMode(s) {
		if (s === <?php echo wp_json_encode( self::SLUG_CB_LIGHT ); ?>) return 'light';
		if (s === <?php echo wp_json_encode( self::SLUG_CB_DARK ); ?>) return 'dark';
		return mode;
	}
	// Set on <html> immediately - it exists during <head> parse, so
	// css rules keyed on html[data-cb-theme="..."] apply before first paint.
	var s0 = effectiveSlug();
	var m0 = effectiveMode(s0);
	document.documentElement.setAttribute('data-cb-theme', s0);
	if (m0 === 'dark' || m0 === 'light') {
		document.documentElement.setAttribute('data-cb-mode', m0);
	}
	// When body exists, also mirror the attribute onto it for the many
	// body[data-cb-theme="..."]-scoped rules in the components/ and
	// pages/ stylesheets.
	function applyBody() {
		if (!document.body) return;
		var s = effectiveSlug();
		var m = effectiveMode(s);
		document.body.setAttribute('data-cb-theme', s);
		if (m === 'dark' || m === 'light') {
			document.body.setAttribute('data-cb-mode', m);
		} else {
			document.body.removeAttribute('data-cb-mode');
		}
	}
	function applyBoth() {
		var s = effectiveSlug();
		var m = effectiveMode(s);
		document.documentElement.setAttribute('data-cb-theme', s);
		if (m === 'dark' || m === 'light') {
			document.documentElement.setAttribute('data-cb-mode', m);
		} else {
			document.documentElement.removeAttribute('data-cb-mode');
		}
		applyBody();
	}
	if (document.body) { applyBody(); }
	else { document.addEventListener('DOMContentLoaded', applyBody); }
	new MutationObserver(function(_, o){ if (document.body) { applyBody(); o.disconnect(); } })
		.observe(document.documentElement, {childList:true, subtree:true});
	// Auto-mode: react to system-preference changes during the session.
	if (auto && window.matchMedia) {
		try {
			window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', applyBoth);
		} catch (e) { /* older browsers */ }
	}
})();
</script>
		<?php
	}

	/**
	 * Add theme classes to <body> as a convenience for class-based selectors.
	 */
	public static function filter_admin_body_class( string $classes ): string {
		if ( ! self::on_cb_admin_screen() ) {
			return $classes;
		}
		$slug = self::current();
		return trim( $classes . ' cb-core-theme-' . sanitize_html_class( $slug ) );
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Is the current admin request on a Core Blueprint screen? (Core Blueprint or
	 * any sibling CB plugin page - all live under the `core-blueprint` menu.)
	 */
	public static function on_cb_admin_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}
		return false !== strpos( $screen->id, CB_CORE_PARENT_MENU )
			|| false !== strpos( $screen->id, 'cb-hub' )
			|| false !== strpos( $screen->id, 'cb-invoice' );
	}

	/**
	 * Normalise a theme-definitions array - drop malformed entries and sanitise
	 * values. Built-in themes always win over partner/legacy conflicts.
	 */
	private static function normalize( array $themes ): array {
		$out = [];
		foreach ( $themes as $slug => $def ) {
			$slug_clean = sanitize_key( (string) $slug );
			if ( '' === $slug_clean || ! is_array( $def ) || empty( $def['label'] ) ) {
				continue;
			}
			$out[ $slug_clean ] = [
				'label'       => sanitize_text_field( (string) $def['label'] ),
				'description' => sanitize_text_field( (string) ( $def['description'] ?? '' ) ),
				'mode'        => (string) ( $def['mode']   ?? 'custom' ),
				'family'      => (string) ( $def['family'] ?? 'partner' ),
				'css_url'     => esc_url_raw( (string) ( $def['css_url'] ?? '' ) ),
				'author'      => sanitize_text_field( (string) ( $def['author'] ?? '' ) ),
				'preview_svg' => isset( $def['preview_svg'] ) ? self::sanitize_preview_svg( (string) $def['preview_svg'] ) : '',
			];
		}
		return $out;
	}

	/**
	 * Sanitize partner-theme preview SVG at the trust boundary.
	 *
	 * Safe to call both when a theme is registered and immediately before
	 * rendering so output remains escape-late even when data provenance changes.
	 */
	public static function sanitize_preview_svg( string $svg ): string {
		return wp_kses( $svg, self::allowed_svg() );
	}

	/** Reset the cached registry - called after filter changes during tests. */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/** Bounded SVG tagset for partner-theme preview graphics. */
	private static function allowed_svg(): array {
		return [
			'svg'            => [ 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'class' => true ],
			'circle'         => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true ],
			'rect'           => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true ],
			'path'           => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true ],
			'line'           => [ 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true ],
			'g'              => [ 'fill' => true, 'stroke' => true, 'opacity' => true ],
			'defs'           => [],
			'lineargradient' => [ 'id' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'gradienttransform' => true, 'gradientunits' => true ],
			'radialgradient' => [ 'id' => true, 'cx' => true, 'cy' => true, 'r' => true ],
			'stop'           => [ 'offset' => true, 'stop-color' => true, 'stop-opacity' => true ],
		];
	}
}
