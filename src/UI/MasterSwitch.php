<?php
declare(strict_types=1);
namespace CB\Core\UI;

defined( 'ABSPATH' ) || exit;

/**
 * MasterSwitch - CB UI primitive for binary master switches.
 *
 * Renders a compact consequence-pair flanking a central binary toggle, optionally
 * preceded by a caption (title + description) that frames the switch.
 * Used wherever a subsystem has a single binary on/off gate that
 * operators flip rarely but consequentially - security shields, login
 * hardening, monitoring layers. The pair makes both consequences
 * visible side-by-side so the choice is informed, not blind.
 *
 *   echo MasterSwitch::render( [
 *       'name'       => 'core-shield',
 *       'aria_label' => __( 'Toggle Core Shield', 'core-blueprint' ),
 *       'active'     => $shield_on ? 'on' : 'off',
 *       'states'     => [
 *           'on'  => [
 *               'tone'        => 'success',
 *               'label'       => __( 'On - shield active', 'core-blueprint' ),
 *               'description' => __( '…', 'core-blueprint' ),
 *           ],
 *           'off' => [
 *               'tone'        => 'warning',
 *               'label'       => __( 'Off - features off', 'core-blueprint' ),
 *               'description' => __( '…', 'core-blueprint' ),
 *           ],
 *       ],
 *   ] );
 *
 * **Suite convention (since 1.3.28): omit `caption`.** Every page or tab
 * hosting a master switch already carries a page-level `<h1 class="cb-core-title">`
 * plus a `<p class="cb-core-intro">` paragraph that frames what the
 * feature is. A caption inside the switch block would duplicate that
 * framing and put two competing headings on one page. Compose the
 * mode-aware (plain↔technical) explanation in the page-intro instead;
 * the switch itself carries only its own state-descriptions.
 *
 * The `caption` prop remains supported for cases that legitimately lack
 * a page-level heading (e.g. a switch embedded in a wider dashboard
 * surface), but no current CB Suite page falls in that category. New
 * pages should follow the page-h1 + intro pattern by default.
 *
 * Caption text - when used - follows the suite-wide plain↔technical
 * convention so non-technical readers (council members, healthcare
 * admins) get a usable description without an IT specialist looking
 * over their shoulder. Resolve the variant in the caller via
 * $_pick(...) before passing the string in - the component stays dumb
 * about modes.
 *
 * Three tones for the state dot - the variant API stays semantic
 * ("what state is this?") rather than colour-named:
 *
 *   - 'success'  → green dot   (the desirable / safe state)
 *   - 'warning'  → amber dot   (operator should know they're here)
 *   - 'idle'     → grey dot    (inactive but neutral; e.g. shield off
 *                              while not the desired state)
 *
 * The component is **purely visual** - markup only. It does not wire up
 * AJAX, form submissions, confirmation modals, or any persistence
 * concern. Callers are responsible for:
 *
 *   1. Hooking a click handler on `[data-cb-core-master-switch-toggle]`
 *      AND on `[data-cb-core-master-switch-state]` (cards commit to a
 *      specific state, the toggle inverts).
 *   2. Persisting the new state server-side (via AJAX, admin-post, or
 *      whatever fits the page's existing pattern).
 *   3. Reverting the visual state if the server rejects (optimistic UI).
 *
 * **Usage convention for secondary settings:** when a master switch
 * gates a feature with secondary configuration fields (custom URL,
 * mode picker, etc.), those fields should remain editable when the
 * master is OFF. Treat them as *config* (describe what the feature
 * does) rather than *state* (only meaningful while master is on).
 * Signal "saved-but-not-active" via a status banner above the form,
 * not via disabling the inputs. This preserves the natural setup
 * flow: configure first, flip master last.
 *
 * **Suite-wide philosophy:** every CB subsystem must be deactivatable
 * - operators may already cover a given concern with another tool
 * (their own integrity scanner, login hardening plugin, monitoring
 * stack) and CB Suite should never force itself on them. Wherever
 * a subsystem can be disabled, this component is the canonical UI.
 *
 * Two deliberate exceptions where MasterSwitch does NOT fit:
 *
 *   - **Failsafe** is the lockout-recovery - a literal "if everything
 *     else fails, you can still get in" path. Adding a master toggle
 *     to it is the difference between having a safety net and not
 *     having one. Failsafe gets its own multi-step confirmation flow
 *     when an operator really wants to turn it off; not a one-tap
 *     card-pair like the rest.
 *
 *   - **Access Mode** is a four-state policy picker (Public, Coming Soon,
 *     Maintenance, Admin-Only), not a binary subsystem enable/disable switch.
 *     It therefore owns a tile-grid selector and explicit apply action rather
 *     than reusing MasterSwitch's two-card/toggle contract.
 *
 * Markup contract (BEM):
 *
 *   <div class="cb-core-master-switch-block">              <!-- only with caption -->
 *     <header class="cb-core-master-switch-block__caption">
 *       <h2 class="cb-core-master-switch-block__title">{TITLE}</h2>
 *       <p class="cb-core-master-switch-block__description">{DESC}</p>
 *     </header>
 *     <div class="cb-core-master-switch" data-cb-core-master-switch="{NAME}">
 *       <div class="cb-core-master-switch__option …" data-cb-core-master-switch-state="on">…</div>
 *       <button class="cb-core-master-switch__toggle" data-cb-core-master-switch-toggle>…</button>
 *       <div class="cb-core-master-switch__option …" data-cb-core-master-switch-state="off">…</div>
 *     </div>
 *   </div>
 *
 * Without a caption, the block-wrapper is omitted entirely and the
 * `.cb-core-master-switch` element is the root. Core Admin pages should not
 * add a `cb-core-panel` around that root: the consequence cards already form
 * the visual group. Use `.cb-core-master-switch-shell` when a transparent
 * layout/rhythm hook is needed.
 *
 * The state dot has aria-hidden="true" because the label carries the same
 * info in text. The toggle's aria-pressed mirrors the active state so
 * assistive tech announces "Toggle Core Shield, pressed" or "not
 * pressed" cleanly.
 *
 * CSS for the component lives in components/master-switch.css.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */


final class MasterSwitch {

	/**
	 * Map semantic tone → option modifier class. The CSS modifier names
	 * use the colour-coded states because that's what CSS describes;
	 * the helper API uses semantic tones because that's what call
	 * sites think in ("the on-state is the success tone").
	 */
	private const TONE_TO_MODIFIER = [
		'success' => 'cb-core-master-switch__option--success',
		'warning' => 'cb-core-master-switch__option--warning',
		'idle'    => 'cb-core-master-switch__option--idle',
	];

	/**
	 * Render a master switch as inline HTML.
	 *
	 * @param array{
	 *     name:       string,
	 *     aria_label: string,
	 *     active:     'on'|'off',
	 *     caption?:   array{ title?: string, description?: string },
	 *     states:     array{
	 *         on:  array{ tone: string, label: string, description: string },
	 *         off: array{ tone: string, label: string, description: string }
	 *     }
	 * } $args
	 * @return string HTML - safe to echo directly.
	 */
	public static function render( array $args ): string {
		$name       = (string) ( $args['name']       ?? '' );
		$aria_label = (string) ( $args['aria_label'] ?? '' );
		$active     = (string) ( $args['active']     ?? 'off' );
		$states     = (array)  ( $args['states']     ?? [] );
		$caption    = (array)  ( $args['caption']    ?? [] );

		// Defensive: if either state block is missing, the helper returns
		// an empty string rather than half-rendering. A missing state is
		// a programming error, not an operator scenario.
		if ( ! isset( $states['on'], $states['off'] ) ) {
			return '';
		}

		$is_on = ( 'on' === $active );

		$switch = sprintf(
			'<div class="cb-core-master-switch" data-cb-core-master-switch="%1$s">%2$s%3$s%4$s</div>',
			esc_attr( $name ),
			self::render_option( 'on', $states['on'], $is_on ),
			self::render_toggle( $aria_label, $is_on ),
			self::render_option( 'off', $states['off'], ! $is_on )
		);

		// No caption → return just the switch markup for callers that do not
		// require a caption block.
		$caption_html = self::render_caption( $caption );
		if ( '' === $caption_html ) {
			return $switch;
		}

		// With caption → wrap both in a block element so the caption can
		// share width with the switch grid below it.
		return sprintf(
			'<div class="cb-core-master-switch-block">%1$s%2$s</div>',
			$caption_html,
			$switch
		);
	}

	/**
	 * Render the optional caption header. Returns an empty string when
	 * neither title nor description is provided - the caller's
	 * "no caption" path.
	 *
	 * Title and description are rendered independently: a title-only
	 * caption is valid (rare but supported), as is a description-only
	 * caption (also rare). The default and intended shape is both.
	 *
	 * @param array{ title?: string, description?: string } $caption
	 */
	private static function render_caption( array $caption ): string {
		$title       = (string) ( $caption['title']       ?? '' );
		$description = (string) ( $caption['description'] ?? '' );

		if ( '' === $title && '' === $description ) {
			return '';
		}

		$out = '<header class="cb-core-master-switch-block__caption">';

		if ( '' !== $title ) {
			$out .= sprintf(
				'<h2 class="cb-core-master-switch-block__title">%s</h2>',
				esc_html( $title )
			);
		}

		if ( '' !== $description ) {
			$out .= sprintf(
				'<p class="cb-core-master-switch-block__description">%s</p>',
				esc_html( $description )
			);
		}

		$out .= '</header>';

		return $out;
	}

	/**
	 * Render a single option card. State key is `on` or `off`; the active
	 * flag flips the highlight ring.
	 */
	private static function render_option( string $state_key, array $state, bool $is_active ): string {
		$tone        = (string) ( $state['tone']        ?? 'idle' );
		$label       = (string) ( $state['label']       ?? '' );
		$description = (string) ( $state['description'] ?? '' );

		$tone_class = self::TONE_TO_MODIFIER[ $tone ] ?? self::TONE_TO_MODIFIER['idle'];
		$active_cls = $is_active ? ' is-active' : '';

		return sprintf(
			'<div class="cb-core-master-switch__option %1$s%2$s" data-cb-core-master-switch-state="%3$s">'
				. '<span class="cb-core-master-switch__dot" aria-hidden="true"></span>'
				. '<div class="cb-core-master-switch__label">%4$s</div>'
				. '<div class="cb-core-master-switch__desc">%5$s</div>'
			. '</div>',
			esc_attr( $tone_class ),
			esc_attr( $active_cls ),
			esc_attr( $state_key ),
			esc_html( $label ),
			esc_html( $description )
		);
	}

	/**
	 * Render the central toggle button. aria-pressed mirrors active state.
	 */
	private static function render_toggle( string $aria_label, bool $is_on ): string {
		return sprintf(
			'<button type="button" class="cb-core-master-switch__toggle" aria-label="%1$s" aria-pressed="%2$s" data-cb-core-master-switch-toggle>'
				. '<span class="cb-core-master-switch__track">'
					. '<span class="cb-core-master-switch__thumb"></span>'
				. '</span>'
			. '</button>',
			esc_attr( $aria_label ),
			$is_on ? 'true' : 'false'
		);
	}
}
