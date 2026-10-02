<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
declare(strict_types=1);
/**
 * Preferences → Floating Menu.
 *
 * @var array<string, array<string, mixed>> $sections
 * @var bool                                $hud_enabled
 * @var array<string, mixed>                $config
 * @var array<string, bool>                 $hidden_sections
 * @var array<string, bool>                 $hidden_items
 * @var array<string, bool>                 $custom_ids
 * @var string                              $notice
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$notice_message = '';
$notice_variant = \CB\Core\UI\Notice::SUCCESS;
if ( 'saved' === $notice ) {
	$notice_message = __( 'Floating menu saved.', 'core-blueprint' );
} elseif ( 'reset' === $notice ) {
	$notice_message = __( 'Floating menu reset to registry defaults.', 'core-blueprint' );
} elseif ( 'invalid' === $notice ) {
	$notice_message = __( 'The floating menu configuration could not be saved. Reload the page and try again.', 'core-blueprint' );
	$notice_variant = \CB\Core\UI\Notice::ERROR;
}
?>
<div class="wrap cb-core-wrap cb-hud-menu-preferences" data-cb-hud-menu-editor>
	<h1 class="cb-core-title"><?php esc_html_e( 'Floating Menu', 'core-blueprint' ); ?></h1>
	<p class="cb-core-intro">
		<?php esc_html_e( 'Choose which HUD sections and shortcuts are shown across the site. Registry defaults, extension registrations, runtime capabilities, and module availability remain authoritative.', 'core-blueprint' ); ?>
	</p>

	<?php if ( '' !== $notice_message ) : ?>
		<?php
		echo \CB\Core\UI\Notice::render( [
			'variant' => $notice_variant,
			'message' => $notice_message,
		] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own output.
		?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cb-hud-menu-form>
		<input type="hidden" name="action" value="<?php echo esc_attr( \CB\Core\HUD\MenuPreferences::FORM_ACTION ); ?>" />
		<?php wp_nonce_field( \CB\Core\HUD\MenuPreferences::NONCE_ACTION, \CB\Core\HUD\MenuPreferences::NONCE_NAME ); ?>
		<input
			type="hidden"
			name="cb_hud_menu_payload"
			value="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>"
			data-cb-hud-menu-payload
		/>

		<section class="cb-core-preferences-section cb-hud-menu-editor__section" aria-labelledby="cb-hud-menu-enabled-title">
			<h2 id="cb-hud-menu-enabled-title"><?php esc_html_e( 'Floating Menu', 'core-blueprint' ); ?></h2>
			<label class="cb-core-field">
				<input type="checkbox" name="cb_hud_enabled" value="1" <?php checked( $hud_enabled ); ?> />
				<span><?php esc_html_e( 'Enabled', 'core-blueprint' ); ?></span>
			</label>
		</section>

		<section class="cb-core-preferences-section cb-hud-menu-editor__section" aria-labelledby="cb-hud-menu-structure-title">
			<h2 id="cb-hud-menu-structure-title"><?php esc_html_e( 'Menu structure', 'core-blueprint' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Drag sections or shortcuts to reorder them, or use the arrow buttons. Turning an item off only hides it from the HUD; it never changes the underlying WordPress or Core Blueprint capability.', 'core-blueprint' ); ?>
			</p>

			<div class="cb-hud-menu-editor__sections" data-cb-hud-section-list>
				<?php foreach ( $sections as $section_id => $section ) : ?>
					<?php
					$section_id    = (string) $section_id;
					$section_label = (string) ( $section['label'] ?? $section_id );
					$items         = is_array( $section['items'] ?? null ) ? $section['items'] : [];
					$section_on    = ! isset( $hidden_sections[ $section_id ] );
					?>
					<article
						class="cb-hud-menu-editor__section-card<?php echo $section_on ? '' : ' is-disabled'; ?>"
						data-cb-hud-section
						data-section-id="<?php echo esc_attr( $section_id ); ?>"
						draggable="false"
					>
						<header class="cb-hud-menu-editor__section-header">
							<button type="button" class="cb-hud-menu-editor__drag" data-cb-hud-drag-handle aria-label="<?php esc_attr_e( 'Drag section to reorder', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Drag to reorder', 'core-blueprint' ); ?>">
								<span class="dashicons dashicons-menu" aria-hidden="true"></span>
							</button>
							<div class="cb-hud-menu-editor__section-heading">
								<strong><?php echo esc_html( $section_label ); ?></strong>
								<code><?php echo esc_html( $section_id ); ?></code>
							</div>
							<div class="cb-hud-menu-editor__section-actions">
								<label class="cb-hud-menu-editor__visibility">
									<input type="checkbox" data-cb-hud-section-visible <?php checked( $section_on ); ?> />
									<span><?php esc_html_e( 'Show', 'core-blueprint' ); ?></span>
								</label>
								<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only" data-cb-hud-move="up" aria-label="<?php esc_attr_e( 'Move section up', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Move up', 'core-blueprint' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
								<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only" data-cb-hud-move="down" aria-label="<?php esc_attr_e( 'Move section down', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Move down', 'core-blueprint' ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
							</div>
						</header>

						<div class="cb-hud-menu-editor__items" data-cb-hud-item-list>
							<?php foreach ( $items as $item ) : ?>
								<?php
								$item_id     = (string) ( $item['id'] ?? '' );
								$item_label  = (string) ( $item['label'] ?? $item_id );
								$item_url    = (string) ( $item['url'] ?? '' );
								$item_on     = ! isset( $hidden_items[ $item_id ] );
								$is_custom   = isset( $custom_ids[ $item_id ] );
								?>
								<div
									class="cb-hud-menu-editor__item<?php echo $item_on ? '' : ' is-disabled'; ?><?php echo $is_custom ? ' is-custom' : ''; ?>"
									data-cb-hud-item
									data-item-id="<?php echo esc_attr( $item_id ); ?>"
									data-item-label="<?php echo esc_attr( $item_label ); ?>"
									data-item-url="<?php echo esc_attr( $item_url ); ?>"
									data-custom="<?php echo $is_custom ? '1' : '0'; ?>"
									draggable="false"
								>
									<button type="button" class="cb-hud-menu-editor__drag cb-hud-menu-editor__drag--item" data-cb-hud-drag-handle aria-label="<?php esc_attr_e( 'Drag shortcut to reorder', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Drag to reorder', 'core-blueprint' ); ?>">
										<span class="dashicons dashicons-menu" aria-hidden="true"></span>
									</button>
									<div class="cb-hud-menu-editor__item-main">
										<span class="cb-hud-menu-editor__item-label"><?php echo esc_html( $item_label ); ?></span>
										<?php if ( $is_custom ) : ?>
											<span class="cb-core-state-badge cb-core-state-badge--neutral"><?php esc_html_e( 'Custom', 'core-blueprint' ); ?></span>
										<?php endif; ?>
										<?php if ( $is_custom && '' !== $item_url ) : ?>
											<small class="cb-hud-menu-editor__item-url"><?php echo esc_html( $item_url ); ?></small>
										<?php endif; ?>
									</div>
									<div class="cb-hud-menu-editor__item-actions">
										<label class="cb-hud-menu-editor__visibility">
											<input type="checkbox" data-cb-hud-item-visible <?php checked( $item_on ); ?> />
											<span><?php esc_html_e( 'Show', 'core-blueprint' ); ?></span>
										</label>
										<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only" data-cb-hud-move="up" aria-label="<?php esc_attr_e( 'Move shortcut up', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Move up', 'core-blueprint' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
										<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only" data-cb-hud-move="down" aria-label="<?php esc_attr_e( 'Move shortcut down', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Move down', 'core-blueprint' ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
										<?php if ( $is_custom ) : ?>
											<button type="button" class="button cb-core-button cb-core-button--danger cb-core-button--compact cb-core-button--icon-only" data-cb-hud-remove-custom aria-label="<?php esc_attr_e( 'Remove custom link', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Remove custom link', 'core-blueprint' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
							<p class="cb-hud-menu-editor__empty<?php echo empty( $items ) ? '' : ' is-hidden'; ?>" data-cb-hud-empty><?php esc_html_e( 'No shortcuts are currently registered in this section.', 'core-blueprint' ); ?></p>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="cb-core-preferences-section cb-hud-menu-editor__section" aria-labelledby="cb-hud-menu-custom-title">
			<h2 id="cb-hud-menu-custom-title"><?php esc_html_e( 'Custom links', 'core-blueprint' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Add a site-specific shortcut to any managed section. Custom links are visible only to users who can use the HUD.', 'core-blueprint' ); ?></p>
			<div class="cb-hud-menu-editor__custom-form" data-cb-hud-custom-form>
				<div class="cb-core-field">
					<label for="cb-hud-custom-label"><?php esc_html_e( 'Label', 'core-blueprint' ); ?></label>
					<input type="text" id="cb-hud-custom-label" data-cb-hud-custom-label maxlength="120" placeholder="<?php esc_attr_e( 'Client portal', 'core-blueprint' ); ?>" />
				</div>
				<div class="cb-core-field">
					<label for="cb-hud-custom-url"><?php esc_html_e( 'URL', 'core-blueprint' ); ?></label>
					<input type="url" id="cb-hud-custom-url" data-cb-hud-custom-url placeholder="https://example.com/" />
				</div>
				<div class="cb-core-field">
					<label for="cb-hud-custom-section"><?php esc_html_e( 'Section', 'core-blueprint' ); ?></label>
					<select id="cb-hud-custom-section" data-cb-hud-custom-section>
						<?php foreach ( $sections as $section_id => $section ) : ?>
							<option value="<?php echo esc_attr( (string) $section_id ); ?>"><?php echo esc_html( (string) ( $section['label'] ?? $section_id ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="cb-hud-menu-editor__custom-action">
					<button type="button" class="button cb-core-button cb-core-button--secondary" data-cb-hud-add-custom>
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Add custom link', 'core-blueprint' ); ?></span>
					</button>
				</div>
			</div>
			<p class="cb-hud-menu-editor__validation" data-cb-hud-validation role="status" aria-live="polite"></p>
		</section>

		<div class="cb-hud-menu-editor__footer-actions">
			<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Save changes', 'core-blueprint' ); ?></button>
			<button type="submit" name="cb_hud_menu_reset" value="1" class="button cb-core-button cb-core-button--secondary"><?php esc_html_e( 'Reset to defaults', 'core-blueprint' ); ?></button>
		</div>
	</form>

	<template data-cb-hud-custom-item-template>
		<div class="cb-hud-menu-editor__item is-custom" data-cb-hud-item data-item-id="" data-item-label="" data-item-url="" data-custom="1" draggable="false">
			<button type="button" class="cb-hud-menu-editor__drag cb-hud-menu-editor__drag--item" data-cb-hud-drag-handle aria-label="<?php esc_attr_e( 'Drag shortcut to reorder', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Drag to reorder', 'core-blueprint' ); ?>"><span class="dashicons dashicons-menu" aria-hidden="true"></span></button>
			<div class="cb-hud-menu-editor__item-main">
				<span class="cb-hud-menu-editor__item-label"></span>
				<span class="cb-core-state-badge cb-core-state-badge--neutral"><?php esc_html_e( 'Custom', 'core-blueprint' ); ?></span>
				<small class="cb-hud-menu-editor__item-url"></small>
			</div>
			<div class="cb-hud-menu-editor__item-actions">
				<label class="cb-hud-menu-editor__visibility"><input type="checkbox" data-cb-hud-item-visible checked /><span><?php esc_html_e( 'Show', 'core-blueprint' ); ?></span></label>
				<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only" data-cb-hud-move="up" aria-label="<?php esc_attr_e( 'Move shortcut up', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Move up', 'core-blueprint' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
				<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only" data-cb-hud-move="down" aria-label="<?php esc_attr_e( 'Move shortcut down', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Move down', 'core-blueprint' ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
				<button type="button" class="button cb-core-button cb-core-button--danger cb-core-button--compact cb-core-button--icon-only" data-cb-hud-remove-custom aria-label="<?php esc_attr_e( 'Remove custom link', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Remove custom link', 'core-blueprint' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
			</div>
		</div>
	</template>
</div>
