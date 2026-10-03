<?php
declare(strict_types=1);
/**
 * HeaderActions — the HUD header's pluggable button slot.
 *
 * Renders between the mode-pills and the fixed ⚙ Preferences icon. The
 * theme-toggle is registered into this slot as a default entry; partner
 * plugins can add their own buttons via the `core_blueprint_hud_header_actions`
 * filter (e.g. Hub adding a "sync now" button, white-label brand-picker
 * eventually replacing the theme-toggle).
 *
 * Action contract — each entry is an array shape with these keys:
 *
 *   id          (string, required)  - kebab-case identifier
 *   label       (string, required)  - aria-label and tooltip
 *   icon_svg    (string, required)  - inline SVG markup for the icon
 *   url         (string, optional)  - when set, renders as <a href>
 *   data_attrs  (array,  optional)  - data-* attributes for JS hooks,
 *                                     keyed without the `data-` prefix
 *   capability  (string, optional)  - current_user_can gate; missing
 *                                     means "any HUD viewer"
 *   order       (int,    default 10) - lower renders first
 *
 * Layout is the order field, ascending. The default theme-toggle uses
 * order=10 to leave room before/after for partners (e.g. order=5 to
 * place a button before, order=20 to place after).
 *
 * Capability gating runs at render-time, not registration. Filter
 * callbacks see the full list including theme-toggle and may modify or
 * remove entries — partners that want to replace the theme-toggle
 * (white-label brand+theme picker) filter the list and replace the
 * `cb-theme-toggle` entry by id.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\HUD;

defined( 'ABSPATH' ) || exit;

final class HeaderActions {

	/**
	 * Build the resolved header-actions list. Defaults applied, filter
	 * fired, capability-gate enforced, sorted by `order`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function resolve(): array {
		$actions = self::defaults();

		/**
		 * Filter: core_blueprint_hud_header_actions
		 *
		 * Modify the list of buttons shown between the mode-pills and the
		 * fixed ⚙ Preferences icon in the HUD header. Each action is an
		 * array; see {@see HeaderActions} class docblock for the full
		 * shape.
		 *
		 * Partners typically:
		 *   - append a new entry (id, label, icon_svg, url|data_attrs)
		 *   - replace an existing entry by id (white-label brand+theme
		 *     picker replacing 'cb-theme-toggle')
		 *   - remove an entry (filter to drop the theme-toggle if you
		 *     want a minimalist header)
		 *
		 * Theme-toggle is registered as a default entry with id
		 * 'cb-theme-toggle' and order 10. Partner buttons should pick
		 * an order < 10 to place before, > 10 to place after.
		 *
		 * @param array<int, array<string, mixed>> $actions Default list.
		 */
		$actions = apply_filters( 'core_blueprint_hud_header_actions', $actions );

		// Defensive: filter callbacks might return non-array.
		if ( ! is_array( $actions ) ) {
			$actions = self::defaults();
		}

		// Capability-gate at render time.
		$visible = [];
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$cap = isset( $action['capability'] ) ? (string) $action['capability'] : '';
			if ( '' !== $cap && ! current_user_can( $cap ) ) {
				continue;
			}
			$visible[] = $action;
		}

		// Stable sort by order.
		usort( $visible, static function ( array $a, array $b ): int {
			return ( (int) ( $a['order'] ?? 10 ) ) <=> ( (int) ( $b['order'] ?? 10 ) );
		} );

		return $visible;
	}

	/**
	 * Render the resolved list to the page. No-op when the list is empty.
	 */
	public static function render(): void {
		$actions = self::resolve();
		if ( empty( $actions ) ) {
			return;
		}
		?>
		<div class="cb-hud__header-actions" role="group" aria-label="<?php esc_attr_e( 'HUD header actions', 'core-blueprint' ); ?>">
			<?php foreach ( $actions as $action ) {
				self::render_action( $action );
			} ?>
		</div>
		<?php
	}

	/**
	 * Default action set — currently just the theme-toggle when a
	 * dark/light pair is registered. If only one mode exists for the
	 * active brand, the toggle is omitted (no point clicking it).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function defaults(): array {
		$actions = [];

		// Theme-toggle — only when a dark/light pair is registered for
		// the active brand. Single-mode brands omit it.
		$slugs = self::theme_toggle_slug_pair();
		if ( null !== $slugs ) {
			$actions[] = [
				'id'         => 'cb-theme-toggle',
				'label'      => __( 'Toggle dark / light theme', 'core-blueprint' ),
				'icon_svg'   => self::theme_toggle_icon(),
				'data_attrs' => [
					'cb-hud-theme-toggle' => '1',
					'cb-theme-dark'       => $slugs['dark'],
					'cb-theme-light'      => $slugs['light'],
				],
				'order'      => 10,
			];
		}

		// Ghost-toggle — fade the floating HUD button to semi-transparent
		// so it stops blocking content behind it. Independent of the
		// theme-toggle so an operator who only wants this one still gets
		// it. Initial state is read from the user/site preference; the
		// JS layer flips the active class on click via the
		// data-cb-hud-ghost-action hook.
		$ghost_active = self::ghost_is_active();
		$actions[] = [
			'id'         => 'cb-ghost-toggle',
			'label'      => $ghost_active
				? __( 'Disable ghost mode', 'core-blueprint' )
				: __( 'Enable ghost mode (fade the floating button)', 'core-blueprint' ),
			'icon_svg'   => self::ghost_icon(),
			'data_attrs' => [
				'cb-hud-ghost-action' => '1',
				'cb-hud-ghost-state'  => $ghost_active ? 'on' : 'off',
			],
			'order'      => 15,
		];

		return $actions;
	}

	/**
	 * Whether ghost mode is currently active. Reads from the same
	 * source as the launcher itself: Storage::get_ghost(). localStorage may
	 * still take precedence client-side for an immediately preceding write
	 * whose REST persistence did not finish before navigation.
	 */
	private static function ghost_is_active(): bool {
		return Storage::get_ghost();
	}

	/**
	 * Ghost icon — Lucide-style ghost outline. Same SVG regardless
	 * of state; the active state is communicated via the button's
	 * `is-active` class (set by the renderer based on data-attr).
	 */
	private static function ghost_icon(): string {
		return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 10h.01"/><path d="M15 10h.01"/><path d="M12 2a8 8 0 0 0-8 8v12l3-3 2.5 2.5L12 19l2.5 2.5L17 19l3 3V10a8 8 0 0 0-8-8z"/></svg>';
	}

	/**
	 * Find the dark+light slug pair matching the active brand. Returns
	 * null when either mode has no registered theme — a single-mode
	 * brand can't toggle.
	 *
	 * @return array{dark:string, light:string}|null
	 */
	private static function theme_toggle_slug_pair(): ?array {
		if ( ! class_exists( '\\CoreBlueprint\\Core\\Themes' ) ) {
			return null;
		}

		$current_slug = (string) \CoreBlueprint\Core\Themes::current();
		$all          = \CoreBlueprint\Core\Themes::all();
		$current_fam  = $all[ $current_slug ]['family'] ?? '';

		$dark  = '';
		$light = '';

		// Same family preferred.
		foreach ( $all as $slug => $entry ) {
			if ( ( $entry['family'] ?? '' ) !== $current_fam ) {
				continue;
			}
			if ( '' === $dark && ( $entry['mode'] ?? '' ) === 'dark' ) {
				$dark = (string) $slug;
			}
			if ( '' === $light && ( $entry['mode'] ?? '' ) === 'light' ) {
				$light = (string) $slug;
			}
		}

		// Cross-family fallback so the toggle keeps working even if the
		// active brand only ships one mode.
		if ( '' === $dark || '' === $light ) {
			foreach ( $all as $slug => $entry ) {
				if ( '' === $dark && ( $entry['mode'] ?? '' ) === 'dark' ) {
					$dark = (string) $slug;
				}
				if ( '' === $light && ( $entry['mode'] ?? '' ) === 'light' ) {
					$light = (string) $slug;
				}
			}
		}

		if ( '' === $dark || '' === $light ) {
			return null;
		}

		return [ 'dark' => $dark, 'light' => $light ];
	}

	/**
	 * Render a single action. Picks <a> when a url is set, <button>
	 * otherwise. Data attributes flow through unchanged so JS handlers
	 * can hook on them.
	 *
	 * @param array<string, mixed> $action
	 */
	private static function render_action( array $action ): void {
		$id       = (string) ( $action['id']       ?? '' );
		$label    = (string) ( $action['label']    ?? '' );
		$icon_svg = (string) ( $action['icon_svg'] ?? '' );
		$url      = (string) ( $action['url']      ?? '' );

		if ( '' === $id || '' === $label ) {
			return;
		}

		$data_attrs      = is_array( $action['data_attrs'] ?? null ) ? $action['data_attrs'] : [];
		$safe_data_attrs = [];
		$active          = false;
		foreach ( $data_attrs as $key => $value ) {
			$key_str = (string) $key;
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $key_str ) ) {
				continue;
			}

			$safe_data_attrs[ $key_str ] = (string) $value;
			if ( str_ends_with( $key_str, '-state' ) && in_array( (string) $value, [ 'on', 'active', 'true' ], true ) ) {
				$active = true;
			}
		}

		$class = 'cb-hud__header-action';
		$class .= ' cb-hud__header-action--' . sanitize_html_class( $id );
		if ( $active ) {
			$class .= ' is-active';
		}

		if ( '' !== $url ) {
			printf(
				'<a class="%1$s" href="%2$s" aria-label="%3$s" title="%3$s"',
				esc_attr( $class ),
				esc_url( $url ),
				esc_attr( $label )
			);
			foreach ( $safe_data_attrs as $key => $value ) {
				printf( ' data-%1$s="%2$s"', esc_attr( $key ), esc_attr( $value ) );
			}
			echo '>';
			echo wp_kses( $icon_svg, self::allowed_icon_svg() );
			echo '</a>';
			return;
		}

		printf(
			'<button type="button" class="%1$s" aria-label="%2$s" title="%2$s"',
			esc_attr( $class ),
			esc_attr( $label )
		);
		foreach ( $safe_data_attrs as $key => $value ) {
			printf( ' data-%1$s="%2$s"', esc_attr( $key ), esc_attr( $value ) );
		}
		echo '>';
		echo wp_kses( $icon_svg, self::allowed_icon_svg() );
		echo '</button>';
	}

	/** Bounded markup contract for HUD action icons. */
	private static function allowed_icon_svg(): array {
		return [
			'svg'      => [
				'xmlns'           => true,
				'viewbox'         => true,
				'width'           => true,
				'height'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
				'focusable'       => true,
				'class'           => true,
			],
			'path'     => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true ],
			'circle'   => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true ],
			'rect'     => [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'opacity' => true ],
			'line'     => [ 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true ],
			'polyline' => [ 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
			'polygon'  => [ 'points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
			'g'        => [ 'fill' => true, 'stroke' => true, 'opacity' => true ],
		];
	}

	/**
	 * Theme-toggle icon: a moon (rendered when light theme is active —
	 * click goes to dark) or a sun (rendered when dark theme is active —
	 * click goes to light). The JS layer swaps the inner SVG when the
	 * theme changes; the server-side initial render matches the active
	 * theme so there's no flash.
	 *
	 * Lucide moon + sun outlines, sized 16×16 for parity with the
	 * existing close button.
	 */
	private static function theme_toggle_icon(): string {
		$mode = 'dark';
		if ( class_exists( '\\CoreBlueprint\\Core\\Themes' ) ) {
			$mode = (string) \CoreBlueprint\Core\Themes::current_mode();
		}

		// When dark is active, show sun (clicking goes back to light).
		// Otherwise show moon (clicking switches to dark).
		if ( 'dark' === $mode ) {
			return self::sun_svg();
		}
		return self::moon_svg();
	}

	private static function moon_svg(): string {
		return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
	}

	private static function sun_svg(): string {
		return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>';
	}
}
