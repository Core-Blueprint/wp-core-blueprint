<?php
declare(strict_types=1);
/**
 * HUD - chrome renderer.
 *
 * Emits the launcher button + slide-in panel into the document footer.
 * Called from {@see Bootstrap::render()} after the visibility gate
 * passes; this class assumes it's allowed to render and focuses purely
 * on the markup.
 *
 * Markup contract:
 *
 *   <div class="cb-hud" data-cb-hud data-state="closed" data-brand="...">
 *     <button class="cb-hud__toggle" data-cb-hud-toggle data-position="..." data-ghost="...">
 *       <!-- inline brand SVG logo -->
 *     </button>
 *     <aside class="cb-hud__panel" data-cb-hud-panel aria-hidden="true">
 *       <header>...</header>
 *       <div class="cb-hud__body">
 *         <section data-section="quick-actions">
 *           <h3>...</h3>
 *           <div class="cb-hud__section-content cb-hud__section-content--list">
 *             <a/div/>
 *           </div>
 *         </section>
 *         ...
 *       </div>
 *     </aside>
 *   </div>
 *
 * State + capability + brand-id all flow through data-* attributes so
 * the JS layer doesn't need a localised config dump - it reads from
 * the DOM. Server-rendered initial state matches what JS will compute
 * on first run, so there's no flicker.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CoreBlueprint\Core\HUD;

use CoreBlueprint\Core\HUD\Brand\BrandRegistry;

defined( 'ABSPATH' ) || exit;

final class HUD {

	public static function render(): void {
		$brand    = BrandRegistry::current();
		$position = Storage::get_position();
		$ghost    = Storage::get_ghost();

		// Emit palette-token overrides for EVERY registered brand. This
		// is critical for client-side brand-switching: when the JS layer
		// flips <html data-cb-brand="x"> to <html data-cb-brand="y">, the
		// css selector html[data-cb-brand="y"] must already match a real
		// <style> block - otherwise the cascade has nothing to work with
		// and tokens fall back to defaults until next page load.
		//
		// Only the active brand's palette block "wins" via the cascade
		// at any given moment (because the html[data-cb-brand] selector
		// only matches one value); the inactive blocks sit dormant and
		// activate instantly when the user switches brand. CoreBlueprint
		// (empty palette) emits nothing - its "win" state is the absence
		// of overrides, which is what tokens.css already provides.
		foreach ( BrandRegistry::all() as $registered_brand ) {
			self::emit_brand_palette( $registered_brand->id(), $registered_brand->palette() );
		}

		?>
		<div
			class="cb-hud"
			data-cb-hud
			data-state="closed"
			data-brand="<?php echo esc_attr( $brand->id() ); ?>"
			data-anchor="<?php echo esc_attr( $position ); ?>"
		>
			<button
				class="cb-hud__toggle"
				type="button"
				data-cb-hud-toggle
				data-position="<?php echo esc_attr( $position ); ?>"
				data-ghost="<?php echo $ghost ? 'true' : 'false'; ?>"
				aria-label="<?php
					/* translators: %s: brand label, e.g. "Core Blueprint" */
					echo esc_attr( sprintf( __( 'Open %s HUD', 'core-blueprint' ), $brand->label() ) );
				?>"
				aria-expanded="false"
				aria-controls="cb-hud-panel"
				title="<?php echo esc_attr( $brand->label() ); ?>"
			>
				<span class="cb-hud__toggle-mark" aria-hidden="true">
					<?php echo wp_kses( self::sanitize_logo_svg( $brand->logo_svg() ), self::allowed_logo_svg() ); ?>
				</span>
			</button>

			<aside
				class="cb-hud__panel"
				id="cb-hud-panel"
				data-cb-hud-panel
				aria-hidden="true"
				aria-label="<?php
					/* translators: %s: brand label */
					echo esc_attr( sprintf( __( '%s HUD', 'core-blueprint' ), $brand->label() ) );
				?>"
			>
				<header class="cb-hud__header">
					<div class="cb-hud__header-text">
						<?php
						// User link replaces the static "Operator Layer"
						// eyebrow - provides identity ("which account am I
						// logged in as?") and quick-access to the profile
						// page. Mirrors the WP admin bar pattern where
						// the user/Gravatar is always visible top-right.
						//
						// Fallback to the diegetic eyebrow if user
						// resolution fails - defensive against unusual
						// render contexts. In practice HUD only renders
						// for logged-in users (Access::can_render gates
						// on is_user_logged_in) so this branch is rare.
						$current_user = wp_get_current_user();
						if ( $current_user && $current_user->ID > 0 ) :
							$avatar_url  = get_avatar_url( $current_user->ID, [ 'size' => 32 ] );
							$profile_url = get_edit_profile_url( $current_user->ID );
							?>
							<a
								class="cb-hud__user"
								href="<?php echo esc_url( $profile_url ); ?>"
								aria-label="<?php
									/* translators: %s: user display name */
									echo esc_attr( sprintf( __( 'Edit profile of %s', 'core-blueprint' ), $current_user->display_name ) );
								?>"
							>
								<?php if ( $avatar_url ) : ?>
									<img class="cb-hud__user-avatar" src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="20" height="20">
								<?php endif; ?>
								<span class="cb-hud__user-name"><?php echo esc_html( $current_user->display_name ); ?></span>
							</a>
						<?php else : ?>
							<div class="cb-hud__eyebrow"><?php esc_html_e( 'Operator Layer', 'core-blueprint' ); ?></div>
						<?php endif; ?>
						<?php
						// Version label below the username — shows which CB
						// product+version the operator is on. "CB" prefix
						// stays compact (Hub/Invoice get their own prefix
						// in their own HUD instances). Dynamic from the
						// CB_CORE_VERSION constant so it stays accurate
						// across patch bumps without manual edits.
						$cb_version = defined( 'CB_CORE_VERSION' ) ? (string) CB_CORE_VERSION : '';
						?>
						<div class="cb-hud__version" aria-label="<?php
							/* translators: %s: CB Base version, e.g. "1.7.0-dev" */
							echo esc_attr( sprintf( __( 'Core Blueprint version %s', 'core-blueprint' ), $cb_version ) );
						?>">
							<?php
							/* translators: %s: version string, e.g. "1.7.0-dev" */
							echo esc_html( sprintf( __( 'CB v%s', 'core-blueprint' ), $cb_version ) );
							?>
						</div>
					</div>

					<?php
					// Mode segmented control - compact P/T/S in the header
					// rather than a full-width row in the body. Mode is
					// always-visible (filosofical signature of CB Suite)
					// but no longer dominates panel real-estate.
					self::render_mode_switcher();
					?>

					<?php
					// Header-actions slot (1.7.0) — pluggable button row
					// between mode-pills and the fixed Preferences/Close
					// buttons. Theme-toggle is registered as the default
					// entry; partner plugins extend or replace via the
					// `core_blueprint_hud_header_actions` filter.
					HeaderActions::render();
					?>

					<?php
					// Preferences shortcut — fixed in the header for operators
					// (CB-cap-gated). Admins without operator caps don't see
					// it; their HUD has no Preferences page to link to anyway.
					if ( current_user_can( 'cb_view_permissions' ) ) :
						$prefs_url = admin_url( 'admin.php?page=' . \CoreBlueprint\Core\Admin\Admin::PREFERENCES_SLUG );
						?>
						<a
							class="cb-hud__header-action cb-hud__header-action--preferences"
							href="<?php echo esc_url( $prefs_url ); ?>"
							aria-label="<?php esc_attr_e( 'Open Preferences', 'core-blueprint' ); ?>"
							title="<?php esc_attr_e( 'Open Preferences', 'core-blueprint' ); ?>"
						>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
								<circle cx="12" cy="12" r="3"/>
								<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
							</svg>
						</a>
					<?php endif; ?>

					<button
						class="cb-hud__close"
						type="button"
						data-cb-hud-close
						aria-label="<?php esc_attr_e( 'Close HUD', 'core-blueprint' ); ?>"
					>×</button>
				</header>

				<?php self::render_header_sections(); ?>

				<div class="cb-hud__body">
					<?php
					foreach ( Registry::sections_for_placement( 'body' ) as $section_id => $section ) :
						$items = Registry::items_for_section( (string) $section_id );
						if ( empty( $items ) ) {
							continue;
						}

						self::render_section( (string) $section_id, $section, $items );
					endforeach;
					?>
				</div>
				<footer class="cb-hud__footer">
					<?php
					/*
					 * Footer holds four step-buttons that move the HUD
					 * across the 3×3 anchor grid:
					 *
					 *   ←      ↑↓      →
					 *
					 * Left/right buttons live in the lower corners of
					 * the footer; up/down sit in a flex group at the
					 * center. CSS shows only the appropriate subset
					 * per current anchor:
					 *
					 *   - top-row    → ↓ visible (also ← / → if non-edge)
					 *   - middle-row → ↑ AND ↓ visible
					 *   - bottom-row → ↑ visible (also ← / → if non-edge)
					 *   - center col → both ← and → visible
					 *
					 * Each button has a fixed chevron direction matching
					 * its data-direction; no JS rotation needed.
					 */
					?>
					<button
						type="button"
						class="cb-hud__side-switch cb-hud__side-switch--target-left"
						data-cb-hud-side-switch
						data-direction="left"
						aria-label="<?php esc_attr_e( 'Move HUD to the left', 'core-blueprint' ); ?>"
						title="<?php esc_attr_e( 'Move HUD to the left', 'core-blueprint' ); ?>"
					>
						<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M10 4L6 8L10 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</button>

					<div class="cb-hud__vside-group">
						<button
							type="button"
							class="cb-hud__side-switch cb-hud__side-switch--target-up"
							data-cb-hud-side-switch
							data-direction="up"
							aria-label="<?php esc_attr_e( 'Move HUD up', 'core-blueprint' ); ?>"
							title="<?php esc_attr_e( 'Move HUD up', 'core-blueprint' ); ?>"
						>
							<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
								<path d="M4 10L8 6L12 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</button>
						<button
							type="button"
							class="cb-hud__side-switch cb-hud__side-switch--target-down"
							data-cb-hud-side-switch
							data-direction="down"
							aria-label="<?php esc_attr_e( 'Move HUD down', 'core-blueprint' ); ?>"
							title="<?php esc_attr_e( 'Move HUD down', 'core-blueprint' ); ?>"
						>
							<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
								<path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</button>
					</div>

					<button
						type="button"
						class="cb-hud__side-switch cb-hud__side-switch--target-right"
						data-cb-hud-side-switch
						data-direction="right"
						aria-label="<?php esc_attr_e( 'Move HUD to the right', 'core-blueprint' ); ?>"
						title="<?php esc_attr_e( 'Move HUD to the right', 'core-blueprint' ); ?>"
					>
						<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M6 4L10 8L6 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</button>
				</footer>
			</aside>
		</div>
		<?php
	}

	/** Render controlled header-placement sections. */
	private static function render_header_sections(): void {
		foreach ( Registry::sections_for_placement( 'header' ) as $section_id => $section ) {
			$items = Registry::items_for_section( (string) $section_id );
			if ( empty( $items ) ) {
				continue;
			}
			$type = SectionTypeRegistry::get( (string) ( $section['type'] ?? '' ) );
			if ( is_array( $type ) && 'status-strip' === (string) ( $type['presentation'] ?? '' ) ) {
				self::render_status_strip( $items );
			}
		}
	}

	/**
	 * Render the Base-owned compact status-strip primitive.
	 *
	 * @param array<int, array<string, mixed>> $items Validated stat items.
	 */
	private static function render_status_strip( array $items ): void {
		?>
		<div class="cb-hud__status-strip" role="group" aria-label="<?php esc_attr_e( 'System status', 'core-blueprint' ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<div class="cb-hud__status-pill" data-item-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
					<span class="cb-hud__status-label"><?php echo esc_html( (string) $item['label'] ); ?></span>
					<strong class="cb-hud__status-value"><?php echo esc_html( (string) ( $item['value'] ?? '-' ) ); ?></strong>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a controlled body-placement HUD section. Handles collapsible
	 * sections by rendering a clickable section header with caret +
	 * data attributes the JS layer reads to wire toggle behaviour.
	 *
	 * State is read from the server-rendered DOM at first paint:
	 *   - data-collapsed="true|false" - initial state
	 *
	 * The JS layer reads localStorage for an explicit user preference
	 * on mount; if present that overrides the server-rendered default.
	 *
	 * @param string                $section_id
	 * @param array<string, mixed>  $section
	 * @param array<int, array<string, mixed>> $items
	 */
	private static function render_section( string $section_id, array $section, array $items ): void {

		$is_collapsible    = (bool) ( $section['collapsible'] ?? false );
		$collapsed_default = (bool) ( $section['collapsed_default'] ?? false );
		$type              = SectionTypeRegistry::get( (string) ( $section['type'] ?? '' ) );
		$presentation      = is_array( $type ) ? (string) ( $type['presentation'] ?? 'list' ) : 'list';
		$label             = (string) ( $section['label'] ?? '' );
		$columns           = (int) ( $section['columns'] ?? 1 );

		// Defensive cap — we already validated columns at registration
		// time, but partners could pass in a hand-rolled section array
		// to render_section directly. Keep the guard.
		if ( ! in_array( $columns, [ 1, 2 ], true ) ) {
			$columns = 1;
		}

		$classes = [ 'cb-hud__section' ];
		if ( $is_collapsible ) {
			$classes[] = 'cb-hud__section--collapsible';
			if ( $collapsed_default ) {
				$classes[] = 'is-collapsed';
			}
		}

		$content_classes = [
			'cb-hud__section-content',
			'cb-hud__section-content--' . $presentation,
		];
		if ( 2 === $columns ) {
			$content_classes[] = 'cb-hud__section-content--cols-2';
		}
		?>
		<section
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-section="<?php echo esc_attr( $section_id ); ?>"
			data-columns="<?php echo esc_attr( (string) $columns ); ?>"
			<?php if ( $is_collapsible ) : ?>
				data-cb-hud-collapsible
				data-collapsed="<?php echo $collapsed_default ? 'true' : 'false'; ?>"
			<?php endif; ?>
		>
			<?php if ( $is_collapsible ) : ?>
				<button
					type="button"
					class="cb-hud__section-header"
					data-cb-hud-section-toggle
					aria-expanded="<?php echo $collapsed_default ? 'false' : 'true'; ?>"
					aria-controls="cb-hud-section-content-<?php echo esc_attr( $section_id ); ?>"
				>
					<span class="cb-hud__section-caret" aria-hidden="true">▸</span>
					<span class="cb-hud__section-title cb-hud__section-title--clickable"><?php echo esc_html( $label ); ?></span>
				</button>
			<?php else : ?>
				<h3 class="cb-hud__section-title"><?php echo esc_html( $label ); ?></h3>
			<?php endif; ?>

			<div
				class="<?php echo esc_attr( implode( ' ', $content_classes ) ); ?>"
				<?php if ( $is_collapsible ) : ?>
					id="cb-hud-section-content-<?php echo esc_attr( $section_id ); ?>"
				<?php endif; ?>
			>
				<?php foreach ( $items as $item ) : ?>
					<?php self::render_item( $item ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * full names in the tooltip. Mode is always-visible because it's
	 * CB Suite's signature filosofical control, but it no longer takes
	 * a full panel-width row.
	 *
	 * Delegates to the suite-wide UI::render_mode_switcher() with the
	 * --compact size variant. Click handling, REST persistence, and
	 * cross-switcher sync all live in core/mode-switcher.js - the
	 * HUD's switcher is just another instance of the same component
	 * the page-level Logs/Reports switchers use.
	 */
	private static function render_mode_switcher(): void {
		\CoreBlueprint\Core\UI::render_mode_switcher( [
			'compact'    => true,
			'cycle'      => true,
			'aria_label' => __( 'Description mode (click to cycle)', 'core-blueprint' ),
		] );
	}

	/**
	 * Render a single validated item by type. Two shapes are supported:
	 *   - 'link' (default): anchor with label + optional description
	 *   - 'stat': read-only label + value display (System Status section)
	 *
	 * SectionTypeRegistry validates item shapes before this renderer runs.
	 *
	 * @param array<string, mixed> $item Item from Registry.
	 */
	private static function render_item( array $item ): void {
		$type  = (string) ( $item['type'] ?? 'link' );
		$id    = (string) ( $item['id'] ?? '' );
		$label = (string) ( $item['label'] ?? '' );
		$url   = (string) ( $item['url'] ?? '' );
		$desc  = (string) ( $item['description'] ?? '' );
		$value = $item['value'] ?? null;

		if ( 'stat' === $type ) {
			?>
			<div class="cb-hud__stat" data-item-id="<?php echo esc_attr( $id ); ?>">
				<span class="cb-hud__stat-label"><?php echo esc_html( $label ); ?></span>
				<strong class="cb-hud__stat-value"><?php echo esc_html( (string) $value ); ?></strong>
			</div>
			<?php
			return;
		}

		// Default: link. Items missing a URL are skipped - silent
		// defensive handling of malformed sibling registrations.
		if ( '' === $url ) {
			return;
		}

		$icon = (string) ( $item['icon'] ?? '' );

		// Resolve the optional canonical status ID through Base's status registry.
		// Unknown or malformed status IDs render without a dot; providers cannot
		// inject an arbitrary WordPress filter into the HUD renderer.
		$status_id     = (string) ( $item['status'] ?? '' );
		$status_state  = '';
		$status_detail = '';
		if ( '' !== $status_id ) {
			$status_shape = \CoreBlueprint\Core\Modules\Status::get( $status_id );
			if ( is_array( $status_shape ) ) {
				$status_state  = (string) $status_shape['state'];
				$status_detail = (string) $status_shape['detail'];
			}
		}

		// Quick action - optional [+] secondary button rendered right
		// of the link. Two distinct interactive elements per row: the
		// row itself navigates to $url (primary action), the [+] button
		// goes to $quick_action_url. The button is a separate <button>
		// inside a wrapper, NOT nested inside the <a> link, because
		// nested interactive elements are an accessibility violation.
		$quick_action_url   = (string) ( $item['quick_action_url'] ?? '' );
		$quick_action_label = (string) ( $item['quick_action_label'] ?? '' );
		$has_quick_action   = '' !== $quick_action_url;

		if ( '' === $quick_action_label && $has_quick_action ) {
			/* translators: %s: item label, e.g. "Posts" */
			$quick_action_label = sprintf( __( 'Add new %s', 'core-blueprint' ), $label );
		}

		// Link title fallback: prefer description, then status detail,
		// then nothing. Title attribute lives the screen-reader and
		// native-tooltip role; the visible description span is hidden
		// by default in CSS (M2: descriptions don't crowd the row).
		$title_attr = '' !== $desc ? $desc : $status_detail;

		$row_classes = [ 'cb-hud__row' ];
		if ( $has_quick_action ) {
			$row_classes[] = 'cb-hud__row--has-action';
		}
		if ( '' !== $icon ) {
			$row_classes[] = 'cb-hud__row--has-icon';
		}
		if ( '' !== $status_state ) {
			$row_classes[] = 'cb-hud__row--has-status';
		}
		?>
		<div class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>" data-item-id="<?php echo esc_attr( $id ); ?>">
			<a
				class="cb-hud__link cb-hud__link--<?php echo esc_attr( $type ); ?>"
				href="<?php echo esc_url( $url ); ?>"
				<?php if ( '' !== $title_attr ) : ?>
					title="<?php echo esc_attr( $title_attr ); ?>"
				<?php endif; ?>
			>
				<?php if ( '' !== $icon ) : ?>
					<span class="cb-hud__link-icon dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<?php endif; ?>
				<span class="cb-hud__link-label"><?php echo esc_html( $label ); ?></span>
				<?php if ( '' !== $status_state ) : ?>
					<?php
					$status_value = '' !== $status_detail ? $status_detail : $status_state;
					/* translators: %s: status detail or status state. */
					$status_screen_reader = sprintf( __( 'Status: %s', 'core-blueprint' ), $status_value );
					?>
					<span class="cb-hud__link-dot cb-hud__link-dot--<?php echo esc_attr( $status_state ); ?>" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php echo esc_html( $status_screen_reader ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $desc ) : ?>
					<span class="cb-hud__link-desc"><?php echo esc_html( $desc ); ?></span>
				<?php endif; ?>
			</a>
			<?php if ( $has_quick_action ) : ?>
				<a
					class="cb-hud__row-action"
					href="<?php echo esc_url( $quick_action_url ); ?>"
					aria-label="<?php echo esc_attr( $quick_action_label ); ?>"
					title="<?php echo esc_attr( $quick_action_label ); ?>"
				>
					<span aria-hidden="true">+</span>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Derive panel side ('left' | 'right') from the toggle's dock
	 * position. Used to anchor the panel to the same edge as the
	 * toggle button - single source of truth, no separate panel-side
	 * setting to drift out of sync with the toggle.
	 *
	 * Right-side anchors (top/middle/bottom-right) → panel right.
	 * Left-side anchors (top/middle/bottom-left) → panel left.
	 * Center anchors (top/bottom-center) → default to right since
	 * the panel needs to hang from one edge or the other; right is
	 * the historical default for the WordPress admin layout.
	 */
	public static function derive_side_from_position( string $position ): string {
		if ( false !== strpos( $position, 'left' ) ) {
			return 'left';
		}
		return 'right'; // right + center anchors
	}

	/**
	 * Sanitise inline SVG for safe rendering. Strips <script>, on*
	 * handlers, and external references that could exfiltrate data.
	 * Built-in brand SVGs are trusted (we wrote them) but the same
	 * sanitiser runs against white-label brand SVGs so a misbehaving
	 * brand plugin can't inject script.
	 *
	 * Visibility note: public so brand classes (extending AbstractBrand
	 * or implementing BrandInterface directly) can reuse the sanitiser
	 * inside their own `render_trigger()` without duplicating the
	 * allowlist logic. The method is stateless - there is no risk in
	 * exposing it.
	 *
	 * Allowed tags: svg, path, circle, rect, line, polygon, polyline,
	 * g, defs, style (inline keyframes only), text, tspan, title.
	 */
	public static function sanitize_logo_svg( string $svg ): string {
		if ( '' === $svg || strlen( $svg ) > 16384 ) {
			return '';
		}

		// Remove executable content before structural KSES processing so script
		// bodies cannot survive as stray text after the tag itself is stripped.
		$svg = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $svg ) ?? '';

		// Preserve the canonical mark's alpha-mask behaviour on WordPress 7.0.
		// mask-type is a native SVG presentation attribute, so it does not need
		// to remain in an inline style attribute.
		$svg = preg_replace(
			'/\sstyle\s*=\s*(["\'])\s*mask-type\s*:\s*(alpha|luminance)\s*;?\s*\1/i',
			' mask-type="$2"',
			$svg
		) ?? '';

		// Inline SVG styles are permitted only for brand-scoped class rules and
		// brand-scoped keyframes. Remote references and document-wide selectors
		// are rejected instead of attempting to repair them.
		$svg = preg_replace_callback(
			'#<style\b[^>]*>(.*?)</style>#is',
			static function ( array $matches ): string {
				$css = self::sanitize_logo_css( (string) ( $matches[1] ?? '' ) );
				return '' === $css ? '' : '<style>' . $css . '</style>';
			},
			$svg
		) ?? '';

		// SVG paint/mask references may target definitions inside this same SVG.
		// Any external or malformed url() reference is neutralised.
		$svg = preg_replace_callback(
			'/url\(\s*([^)]+)\)/i',
			static function ( array $matches ): string {
				$target = trim( (string) ( $matches[1] ?? '' ), " \t\n\r\0\x0B\"'" );
				return 1 === preg_match( '/^#[A-Za-z][A-Za-z0-9_.:-]*$/D', $target )
					? 'url(' . $target . ')'
					: 'none';
			},
			$svg
		) ?? '';

		return wp_kses( $svg, self::allowed_logo_svg() );
	}

	/**
	 * Constrain CSS embedded inside a brand SVG to the SVG's own cb-brand-*
	 * classes and cb-brand-* keyframes.
	 */
	private static function sanitize_logo_css( string $css ): string {
		if ( '' === trim( $css ) || strlen( $css ) > 4096 ) {
			return '';
		}

		$css = preg_replace( '#/\*.*?\*/#s', '', $css ) ?? '';
		if (
			'' === trim( $css )
			|| preg_match( '/(?:@import|url\s*\(|expression\s*\(|javascript\s*:|data\s*:|behavior\s*:|-moz-binding)/i', $css )
			|| str_contains( $css, '<' )
			|| str_contains( $css, '>' )
		) {
			return '';
		}

		$depth    = 0;
		$header   = '';
		$contexts = [];
		$length   = strlen( $css );

		for ( $offset = 0; $offset < $length; ++$offset ) {
			$char = $css[ $offset ];
			if ( '{' === $char ) {
				$selector = trim( $header );
				$header   = '';
				if ( 0 === $depth ) {
					if ( 1 === preg_match( '/^@keyframes\s+cb-brand-[A-Za-z0-9_-]+$/D', $selector ) ) {
						$context = 'keyframes';
					} elseif ( self::is_safe_logo_selector( $selector ) ) {
						$context = 'rule';
					} else {
						return '';
					}
				} elseif ( 1 === $depth && 'keyframes' === ( $contexts[0] ?? '' ) ) {
					if ( 1 !== preg_match( '/^(?:(?:from|to|(?:100|[0-9]{1,2})%)(?:\s*,\s*)?)+$/iD', $selector ) ) {
						return '';
					}
					$context = 'frame';
				} else {
					return '';
				}

				$contexts[] = $context;
				++$depth;
				continue;
			}

			if ( '}' === $char ) {
				if ( 0 === $depth ) {
					return '';
				}
				array_pop( $contexts );
				--$depth;
				$header = '';
				continue;
			}

			if ( 0 === $depth || ( 1 === $depth && 'keyframes' === ( $contexts[0] ?? '' ) ) ) {
				$header .= $char;
			}
		}

		return 0 === $depth ? trim( $css ) : '';
	}

	private static function is_safe_logo_selector( string $selector ): bool {
		if ( '' === $selector ) {
			return false;
		}
		foreach ( array_map( 'trim', explode( ',', $selector ) ) as $part ) {
			if ( 1 !== preg_match( '/^\.cb-brand-[A-Za-z0-9_-]+$/D', $part ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string,array<string,bool>> */
	private static function allowed_logo_svg(): array {
		$shape = [
			'id'              => true,
			'class'           => true,
			'fill'            => true,
			'fill-opacity'    => true,
			'fill-rule'       => true,
			'stroke'          => true,
			'stroke-opacity'  => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'opacity'         => true,
			'transform'       => true,
			'mask'            => true,
			'clip-path'       => true,
			'clip-rule'       => true,
			'style'           => true,
		];

		return [
			'svg' => [
				'xmlns'               => true,
				'viewbox'             => true,
				'width'               => true,
				'height'              => true,
				'fill'                => true,
				'stroke'              => true,
				'class'               => true,
				'role'                => true,
				'aria-hidden'         => true,
				'focusable'           => true,
				'preserveaspectratio' => true,
			],
			'path' => $shape + [ 'd' => true ],
			'circle' => $shape + [ 'cx' => true, 'cy' => true, 'r' => true ],
			'ellipse' => $shape + [ 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ],
			'rect' => $shape + [ 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ],
			'line' => $shape + [ 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ],
			'polygon' => $shape + [ 'points' => true ],
			'polyline' => $shape + [ 'points' => true ],
			'g' => $shape,
			'defs' => [],
			'mask' => [
				'id'               => true,
				'x'                => true,
				'y'                => true,
				'width'            => true,
				'height'           => true,
				'maskunits'        => true,
				'maskcontentunits' => true,
				'mask-type'        => true,
			],
			'lineargradient' => [
				'id'                => true,
				'x1'                => true,
				'y1'                => true,
				'x2'                => true,
				'y2'                => true,
				'gradientunits'     => true,
				'gradienttransform' => true,
			],
			'radialgradient' => [
				'id'                => true,
				'cx'                => true,
				'cy'                => true,
				'r'                 => true,
				'fx'                => true,
				'fy'                => true,
				'fr'                => true,
				'gradientunits'     => true,
				'gradienttransform' => true,
			],
			'stop' => [
				'offset'       => true,
				'stop-color'   => true,
				'stop-opacity' => true,
			],
			'clippath' => [
				'id'            => true,
				'clippathunits' => true,
				'transform'     => true,
			],
			'text' => $shape + [
				'x'           => true,
				'y'           => true,
				'dx'          => true,
				'dy'          => true,
				'text-anchor' => true,
				'font-size'   => true,
				'font-weight' => true,
			],
			'tspan' => $shape + [ 'x' => true, 'y' => true, 'dx' => true, 'dy' => true ],
			'style' => [],
			'title' => [],
		];
	}

	/**
	 * Emit a brand palette as inline <style> rules scoped to
	 * <html data-cb-brand="..."> so the brand's token overrides cascade
	 * site-wide. Empty palettes (CoreBlueprint default) emit nothing.
	 *
	 * Called once per page load from {@see render()}. The selector
	 * scope (html data-cb-brand=) ensures palette tokens only apply
	 * when this brand is active, not when the user is previewing
	 * another brand in the picker.
	 *
	 * @param array<string, string> $palette Token overrides.
	 */
	private static function emit_brand_palette( string $brand_id, array $palette ): void {
		if ( empty( $palette ) ) {
			return;
		}
		$brand_id = sanitize_key( $brand_id );
		if ( '' === $brand_id ) {
			return;
		}

		$rules = '';
		foreach ( $palette as $token => $value ) {
			$token = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $token );
			$value = preg_replace( '/[^#a-zA-Z0-9\(\),\s%\.\-]/', '', (string) $value );
			if ( '' === $token || '' === $value ) {
				continue;
			}
			if ( 0 !== strpos( $token, '--' ) ) {
				continue; // only CSS custom properties allowed
			}
			$rules .= sprintf( '%s:%s;', $token, $value );
		}

		if ( '' === $rules ) {
			return;
		}

		printf(
			"<style id=\"cb-hud-brand-palette-%1\$s\">html[data-cb-brand=\"%1\$s\"]{%2\$s}</style>\n",
			esc_attr( $brand_id ),
			esc_html( $rules )
		);
	}
}
