<?php
declare(strict_types=1);
/**
 * UI
 *
 * Central UI helpers for description mode (Plain / Technical / Sync) and
 * badges (tech specs, security standards, CB baselines).
 *
 * Description mode resolution:
 *   1. Read per-user override from user_meta 'cb_core_description_mode'.
 *   2. Fall back to site-wide default from option 'cb_core_description_mode_default'.
 *   3. Fall back to 'plain' (Core Blueprint philosophy: user-friendly by default).
 *
 * Values:
 *   - 'plain'     - plain-language descriptions shown by default, per-feature toggle
 *                   temporarily shows technical for that one feature (not persisted).
 *   - 'technical' - technical descriptions shown by default, per-feature toggle
 *                   temporarily shows plain for that one feature (not persisted).
 *   - 'sync'      - last click wins: toggling any feature switches the whole page
 *                   and persists as the new mode.
 *   - 'inherit'   - user-level only: follow the site default.
 *
 * Badge system:
 *   Each feature may expose a 'badges' array. Each entry has a 'type' and
 *   type-specific fields. Supported types:
 *     - 'tech'        : technical spec e.g. PHP 8.4+, WP 6.0+
 *     - 'standard'    : public security standard reference (OWASP ASVS, Secure Headers)
 *     - 'cwe'         : Common Weakness Enumeration reference
 *     - 'compliance'  : certification claim (reserved, requires audit - not populated)
 *     - 'cb-baseline' : Core Blueprint internal standard (reserved, not populated)
 *
 * Description data shape accepted by render helpers:
 *   - string                                 - single plain-text description
 *   - [ 'plain' => ..., 'technical' => ... ] - dual-description (preferred)
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core;

use CoreBlueprint\Core\Log\AuditLog;

defined( 'ABSPATH' ) || exit;

final class UI {

	/** User meta key for per-user description mode override. */
	const USER_MODE_META = 'cb_core_description_mode';

	/** Site-wide default description mode option. */
	const SITE_MODE_OPTION = 'cb_core_description_mode_default';

	/** Valid description mode values (site-level). */
	const MODES = [ 'plain', 'technical', 'sync' ];

	/** Valid description mode values at the user level (plus 'inherit'). */
	const USER_MODES = [ 'plain', 'technical', 'sync', 'inherit' ];

	// ─── Mode resolution ─────────────────────────────────────────────────────

	/**
	 * Resolve the effective description mode for the current user.
	 *
	 * Always returns 'plain' or 'technical'. The 'sync' state on the
	 * user level means "follow the site default" - it's a persistence
	 * preference, not a render mode. Render code never has to handle
	 * 'sync' as a third case; it can switch on plain vs technical.
	 *
	 * For UI that needs to know which button to highlight (Plain /
	 * Technical / Sync), call current_user_preference() instead.
	 */
	public static function current_mode(): string {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$user_mode = get_user_meta( $user_id, self::USER_MODE_META, true );
			// 'sync' falls through deliberately - it means "use site
			// default", which is the same effect as no stored value.
			if ( in_array( $user_mode, [ 'plain', 'technical' ], true ) ) {
				return $user_mode;
			}
		}

		$site_mode = get_option( self::SITE_MODE_OPTION, 'plain' );
		return in_array( $site_mode, [ 'plain', 'technical' ], true ) ? $site_mode : 'plain';
	}

	/**
	 * Read the raw user preference for switcher UI rendering.
	 * Returns one of: 'plain' | 'technical' | 'sync' | 'inherit'.
	 *
	 * Use this in the mode-switcher rendering to highlight the
	 * currently-selected button. For deciding what content to show,
	 * use current_mode() instead - it does the resolution.
	 */
	public static function current_user_preference(): string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 'inherit';
		}
		$stored = get_user_meta( $user_id, self::USER_MODE_META, true );
		if ( in_array( $stored, [ 'plain', 'technical', 'sync' ], true ) ) {
			return $stored;
		}
		return 'inherit';
	}

	/** Read the site-wide default (without applying user override). */
	public static function site_default_mode(): string {
		$mode = get_option( self::SITE_MODE_OPTION, 'plain' );
		return in_array( $mode, self::MODES, true ) ? $mode : 'plain';
	}

	// ─── Mode switcher rendering ─────────────────────────────────────────

	/**
	 * Render the suite-wide reading-mode switcher (Plain / Technical /
	 * Sync). Identical markup across all CB pages and the HUD - which
	 * is exactly the point: one component, one mental model for the
	 * user, one source of truth for the markup contract.
	 *
	 * The switcher highlights whichever button matches the user's
	 * current PREFERENCE (plain / technical / sync). Sync is a
	 * persistence-state ("follow site default"); render code uses
	 * current_mode() to decide what content to actually show, but
	 * the switcher UI shows whichever the user selected.
	 *
	 * Default behaviour (no user pref stored): defaults the highlight
	 * to 'sync', because that IS the no-preference state.
	 *
	 * @param array $args {
	 *     Optional. Render options.
	 *
	 *     @type bool   $compact  When true, single-letter labels (P/T/S)
	 *                            in a narrower segment. For HUD header
	 *                            and tight toolbars. Default false.
	 *     @type string $aria_label  Accessible label for the radiogroup.
	 *                               Default: "Reading mode".
	 *     @type string $extra_class Additional classes appended to the
	 *                               container. Default empty.
	 * }
	 */
	public static function render_mode_switcher( array $args = [] ): void {
		$compact     = ! empty( $args['compact'] );
		$cycle       = ! empty( $args['cycle'] );
		$aria_label  = (string) ( $args['aria_label'] ?? __( 'Reading mode', 'core-blueprint' ) );
		$extra_class = (string) ( $args['extra_class'] ?? '' );

		// Resolve "what to highlight": map 'inherit' to 'sync' since
		// they're the same state functionally - "no explicit per-user
		// preference, follow the site". Showing 'sync' as active
		// matches what the user sees in their other settings UIs.
		$pref = self::current_user_preference();
		if ( 'inherit' === $pref ) {
			$pref = 'sync';
		}

		$modes = [
			'plain'     => [
				'short' => __( 'P', 'core-blueprint' ),
				'full'  => __( 'Plain', 'core-blueprint' ),
			],
			'technical' => [
				'short' => __( 'T', 'core-blueprint' ),
				'full'  => __( 'Technical', 'core-blueprint' ),
			],
			'sync'      => [
				'short' => __( 'S', 'core-blueprint' ),
				'full'  => __( 'Sync', 'core-blueprint' ),
			],
		];

		$container_classes = 'cb-core-mode-switcher';
		if ( $compact ) {
			$container_classes .= ' cb-core-mode-switcher--compact';
		}
		if ( $cycle ) {
			// Cycle variant: same DOM (all three buttons rendered so the
			// JS sync layer keeps working unchanged) but CSS hides every
			// button except .is-active, so visually it reads as a single
			// button. JS detects the cycle wrapper and advances to the
			// next mode on click instead of early-returning.
			$container_classes .= ' cb-core-mode-switcher--cycle';
		}
		if ( '' !== $extra_class ) {
			$container_classes .= ' ' . $extra_class;
		}

		?>
		<div
			class="<?php echo esc_attr( $container_classes ); ?>"
			role="radiogroup"
			aria-label="<?php echo esc_attr( $aria_label ); ?>"
			data-cb-mode-switcher
			<?php if ( $cycle ) : ?>data-cb-mode-cycle="1"<?php endif; ?>
		>
			<?php foreach ( $modes as $slug => $labels ) : ?>
				<button
					type="button"
					class="cb-core-mode-switcher__btn<?php echo $slug === $pref ? ' is-active' : ''; ?>"
					data-cb-mode="<?php echo esc_attr( $slug ); ?>"
					role="radio"
					aria-checked="<?php echo $slug === $pref ? 'true' : 'false'; ?>"
					title="<?php echo esc_attr( $labels['full'] ); ?>"
					aria-label="<?php echo esc_attr( $labels['full'] ); ?>"
				>
					<?php echo esc_html( $compact || $cycle ? $labels['short'] : $labels['full'] ); ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Set the site-wide default mode. Audit-logged.
	 *
	 * @return bool true on success.
	 */
	public static function set_site_default_mode( string $mode, string $actor = 'unknown' ): bool {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return false;
		}
		$before = self::site_default_mode();
		$ok     = update_option( self::SITE_MODE_OPTION, $mode, false );

		if ( $ok && $before !== $mode && class_exists( AuditLog::class ) ) {
			AuditLog::log( 'ui.site_mode_changed', 'info', [
				'from'  => $before,
				'to'    => $mode,
				'actor' => $actor,
			] );
		}
		return $ok;
	}

	/**
	 * Set the per-user override. Pass 'inherit' to clear the override.
	 */
	public static function set_user_mode( int $user_id, string $mode ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! in_array( $mode, self::USER_MODES, true ) ) {
			return false;
		}
		if ( 'inherit' === $mode ) {
			return (bool) delete_user_meta( $user_id, self::USER_MODE_META );
		}
		return (bool) update_user_meta( $user_id, self::USER_MODE_META, $mode );
	}

	// ─── Description rendering ───────────────────────────────────────────────


	/**
	 * Given a description (string or [plain, technical] array), return the
	 * text appropriate for the given mode. Mode of 'sync' treats the current
	 * active variant as whichever side of the pair is currently in force -
	 * the caller should pass 'plain' or 'technical' for 'sync', not 'sync'.
	 *
	 * @param string|array $desc
	 * @param string       $variant 'plain' or 'technical'
	 * @return string
	 */
	public static function pick_description( $desc, string $variant ): string {
		if ( is_string( $desc ) ) {
			return $desc;
		}
		if ( is_array( $desc ) ) {
			if ( 'technical' === $variant && ! empty( $desc['technical'] ) ) {
				return (string) $desc['technical'];
			}
			if ( 'plain' === $variant && ! empty( $desc['plain'] ) ) {
				return (string) $desc['plain'];
			}
			// Fall back to whichever is available.
			return (string) ( $desc['plain'] ?? $desc['technical'] ?? '' );
		}
		return '';
	}

	/**
	 * Render description text without an inline Plain/Technical toggle.
	 *
	 * When both variants exist they remain in the DOM as a cb-core-dual block,
	 * so the shared description-mode switcher can update them without a page
	 * reload. This is intended for compact rows and progressive-disclosure
	 * content where an inline TECH/PLAIN control would add visual noise.
	 *
	 * @param string|array $desc
	 * @param string       $active_variant 'plain' | 'technical'
	 * @param string       $extra_class Optional additional CSS classes.
	 */
	public static function render_description_text( $desc, string $active_variant, string $extra_class = '' ): string {
		$classes = [ 'cb-core-desc-text-block' ];
		foreach ( preg_split( '/\s+/', trim( $extra_class ) ) ?: [] as $class ) {
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}

		if ( is_string( $desc ) ) {
			return '<div class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '">' . esc_html( $desc ) . '</div>';
		}

		if ( ! is_array( $desc ) ) {
			return '';
		}

		$plain     = (string) ( $desc['plain'] ?? '' );
		$technical = (string) ( $desc['technical'] ?? '' );
		if ( '' === $plain && '' === $technical ) {
			return '';
		}
		if ( '' === $plain || '' === $technical ) {
			$text = '' !== $plain ? $plain : $technical;
			return '<div class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '">' . esc_html( $text ) . '</div>';
		}

		$active    = 'technical' === $active_variant ? 'technical' : 'plain';
		$classes[] = 'cb-core-dual';

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', array_unique( $classes ) ) ); ?>" data-active="<?php echo esc_attr( $active ); ?>">
			<span class="cb-core-desc-plain" <?php echo 'plain' === $active ? '' : 'hidden'; ?>><?php echo esc_html( $plain ); ?></span>
			<span class="cb-core-desc-technical" <?php echo 'technical' === $active ? '' : 'hidden'; ?>><?php echo esc_html( $technical ); ?></span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a dual-description block with its inline toggle. Outputs both
	 * variants in the DOM so the JS can swap without an AJAX round-trip.
	 *
	 * @param string|array $desc
	 * @param string       $active_variant 'plain' | 'technical'
	 */
	public static function render_description_block( $desc, string $active_variant ): string {
		// Single-string description - render as-is, no toggle.
		if ( is_string( $desc ) ) {
			return '<div class="cb-core-desc">' . esc_html( $desc ) . '</div>';
		}

		if ( ! is_array( $desc ) ) {
			return '';
		}

		$plain     = (string) ( $desc['plain']     ?? '' );
		$technical = (string) ( $desc['technical'] ?? '' );

		// If only one is available, render that one with no toggle.
		if ( '' === $plain && '' === $technical ) {
			return '';
		}
		if ( '' === $plain || '' === $technical ) {
			$text = '' !== $plain ? $plain : $technical;
			return '<div class="cb-core-desc">' . esc_html( $text ) . '</div>';
		}

		$active = ( 'technical' === $active_variant ) ? 'technical' : 'plain';
		$other  = ( 'technical' === $active ) ? 'plain' : 'technical';

		ob_start();
		?>
		<div class="cb-core-desc cb-core-dual" data-active="<?php echo esc_attr( $active ); ?>">
			<span class="cb-core-desc-text cb-core-desc-plain"     <?php echo 'plain'     === $active ? '' : 'hidden'; ?>><?php echo esc_html( $plain );     ?></span>
			<span class="cb-core-desc-text cb-core-desc-technical" <?php echo 'technical' === $active ? '' : 'hidden'; ?>><?php echo esc_html( $technical ); ?></span>
			<button
				type="button"
				class="cb-core-desc-toggle"
				data-current="<?php echo esc_attr( $active ); ?>"
				aria-label="<?php echo 'plain' === $active
					? esc_attr__( 'Show technical description', 'core-blueprint' )
					: esc_attr__( 'Show plain description', 'core-blueprint' ); ?>"
				title="<?php echo 'plain' === $active
					? esc_attr__( 'Show technical description', 'core-blueprint' )
					: esc_attr__( 'Show plain description', 'core-blueprint' ); ?>"
			><span class="cb-core-desc-toggle-label"><?php echo 'plain' === $active ? 'tech' : 'plain'; ?></span></button>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ─── Badge rendering ─────────────────────────────────────────────────────

	/**
	 * Render a list of badges as inline chips. Returns empty string when no
	 * badges supplied.
	 *
	 * @param array $badges  Array of badge definitions (see class docblock).
	 */
	public static function render_badges( array $badges ): string {
		if ( empty( $badges ) ) {
			return '';
		}

		$out = '<div class="cb-core-badges">';
		foreach ( $badges as $badge ) {
			$html = self::render_single_badge( $badge );
			if ( '' !== $html ) {
				$out .= $html;
			}
		}
		$out .= '</div>';
		return $out;
	}

	private static function render_single_badge( array $badge ): string {
		$type = (string) ( $badge['type'] ?? '' );
		if ( '' === $type ) {
			return '';
		}

		switch ( $type ) {
			case 'tech':
				return self::render_tech_badge( $badge );
			case 'standard':
				return self::render_standard_badge( $badge );
			case 'cwe':
				return self::render_cwe_badge( $badge );
			case 'compliance':
				return self::render_compliance_badge( $badge );
			case 'cb-baseline':
				return self::render_cb_baseline_badge( $badge );
		}
		return '';
	}

	private static function render_tech_badge( array $badge ): string {
		$label   = (string) ( $badge['label'] ?? '' );
		$tooltip = (string) ( $badge['tooltip'] ?? '' );
		if ( '' === $label ) {
			return '';
		}
		return sprintf(
			'<span class="cb-core-badge cb-core-badge-tech" title="%s">%s</span>',
			esc_attr( $tooltip ),
			esc_html( $label )
		);
	}

	private static function render_standard_badge( array $badge ): string {
		$body = (string) ( $badge['body'] ?? '' );
		$ref  = (string) ( $badge['ref']  ?? '' );
		$url  = (string) ( $badge['url']  ?? '' );
		$note = (string) ( $badge['note'] ?? '' );
		if ( '' === $body || '' === $ref ) {
			return '';
		}

		$label   = $body . ' ' . $ref;
		$tooltip = $note ? $body . ' ' . $ref . ' - ' . $note : $body . ' ' . $ref;

		if ( '' !== $url ) {
			return sprintf(
				'<a class="cb-core-badge cb-core-badge-standard" href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s <span class="cb-core-badge-ext" aria-hidden="true">↗</span></a>',
				esc_url( $url ),
				esc_attr( $tooltip ),
				esc_html( $label )
			);
		}
		return sprintf(
			'<span class="cb-core-badge cb-core-badge-standard" title="%s">%s</span>',
			esc_attr( $tooltip ),
			esc_html( $label )
		);
	}

	private static function render_cwe_badge( array $badge ): string {
		$ref  = (string) ( $badge['ref']  ?? '' );
		$url  = (string) ( $badge['url']  ?? '' );
		$note = (string) ( $badge['note'] ?? '' );
		if ( '' === $ref ) {
			return '';
		}

		$tooltip = $note ? $ref . ' - ' . $note : $ref;

		if ( '' === $url ) {
			// Auto-build CWE URL if we recognise the format CWE-NNN.
			if ( preg_match( '/^CWE-(\d+)$/i', $ref, $m ) ) {
				$url = 'https://cwe.mitre.org/data/definitions/' . $m[1] . '.html';
			}
		}

		if ( '' !== $url ) {
			return sprintf(
				'<a class="cb-core-badge cb-core-badge-cwe" href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s <span class="cb-core-badge-ext" aria-hidden="true">↗</span></a>',
				esc_url( $url ),
				esc_attr( $tooltip ),
				esc_html( $ref )
			);
		}
		return sprintf(
			'<span class="cb-core-badge cb-core-badge-cwe" title="%s">%s</span>',
			esc_attr( $tooltip ),
			esc_html( $ref )
		);
	}

	private static function render_compliance_badge( array $badge ): string {
		// Structural rendering only - no content is populated at this time.
		// Compliance badges require an actual certification audit before use.
		$body = (string) ( $badge['body'] ?? '' );
		$ref  = (string) ( $badge['ref']  ?? '' );
		if ( '' === $body || '' === $ref ) {
			return '';
		}
		return sprintf(
			'<span class="cb-core-badge cb-core-badge-compliance" title="%s">🛡 %s %s</span>',
			esc_attr( (string) ( $badge['note'] ?? '' ) ),
			esc_html( $body ),
			esc_html( $ref )
		);
	}

	private static function render_cb_baseline_badge( array $badge ): string {
		// Structural rendering only - Core Blueprint Baseline is not yet published.
		$ref = (string) ( $badge['ref'] ?? '' );
		if ( '' === $ref ) {
			return '';
		}
		$tooltip = (string) ( $badge['note'] ?? '' );
		$url     = (string) ( $badge['url']  ?? '' );

		if ( '' !== $url ) {
			return sprintf(
				'<a class="cb-core-badge cb-core-badge-cb" href="%s" target="_blank" rel="noopener noreferrer" title="%s">★ %s</a>',
				esc_url( $url ),
				esc_attr( $tooltip ),
				esc_html( $ref )
			);
		}
		return sprintf(
			'<span class="cb-core-badge cb-core-badge-cb" title="%s">★ %s</span>',
			esc_attr( $tooltip ),
			esc_html( $ref )
		);
	}
}
