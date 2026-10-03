<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Preferences > Admin Navigation.
 *
 * @var array<string,mixed> $state
 * @var string $notice
 */

defined( 'ABSPATH' ) || exit;

$policy  = is_array( $state['policy'] ?? null ) ? $state['policy'] : \CoreBlueprint\Core\AdminNavigation\Policy::defaults();
$menu    = is_array( $state['menu'] ?? null ) ? $state['menu'] : [];
$toolbar = is_array( $state['toolbar'] ?? null ) ? $state['toolbar'] : [];

$notice_message = '';
$notice_variant = \CoreBlueprint\Core\UI\Notice::SUCCESS;
if ( 'saved' === $notice ) {
	$notice_message = __( 'Admin Navigation policy saved.', 'core-blueprint' );
} elseif ( 'reset' === $notice ) {
	$notice_message = __( 'Admin Navigation policy reset to WordPress defaults.', 'core-blueprint' );
} elseif ( 'invalid' === $notice ) {
	$notice_message = __( 'The Admin Navigation policy could not be saved. Review the values and try again.', 'core-blueprint' );
	$notice_variant = \CoreBlueprint\Core\UI\Notice::ERROR;
}

$audience_references = static function ( ?array $rule, string $key ): array {
	$audience = is_array( $rule['audience'] ?? null ) ? $rule['audience'] : [];
	$values   = is_array( $audience[ $key ] ?? null ) ? $audience[ $key ] : [];
	return array_values( array_filter( array_map( 'strval', $values ), static fn( string $value ): bool => '' !== $value ) );
};

$picker_nonce = wp_create_nonce( \CoreBlueprint\Core\AdminNavigation\Admin::PICKER_NONCE_ACTION );
$render_audience_picker = static function ( ?array $rule, string $kind, string $id ) use ( $audience_references, $picker_nonce ): string {
	$references = $audience_references( $rule, $kind );
	$is_roles   = 'roles' === $kind;
	$selected   = $is_roles
		? \CoreBlueprint\Core\AdminNavigation\Admin::role_picker_items( $references )
		: \CoreBlueprint\Core\AdminNavigation\Admin::capability_picker_items( $references );

	return \CoreBlueprint\Core\UI\ObjectPicker::render( [
		'name'      => str_replace( '-', '_', $id ),
		'id'        => $id,
		'multiple'  => true,
		'action'    => $is_roles
			? \CoreBlueprint\Core\AdminNavigation\Admin::ROLE_SEARCH_ACTION
			: \CoreBlueprint\Core\AdminNavigation\Admin::CAPABILITY_SEARCH_ACTION,
		'nonce'     => $picker_nonce,
		'selected'  => $selected,
		'show_hint' => false,
	] );
};
?>
<div
	class="wrap cb-core-wrap"
	data-cb-admin-navigation-editor
	data-policy-version="<?php echo esc_attr( (string) \CoreBlueprint\Core\AdminNavigation\Policy::VERSION ); ?>"
>
	<h1 class="cb-core-title"><?php esc_html_e( 'Admin Navigation', 'core-blueprint' ); ?></h1>
	<p class="cb-core-intro">
		<?php esc_html_e( 'Control presentation of the native WordPress top-level admin menu and Toolbar. Hidden items remain directly accessible to users who still have the required WordPress capability.', 'core-blueprint' ); ?>
	</p>

	<?php if ( '' !== $notice_message ) : ?>
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
		echo \CoreBlueprint\Core\UI\Notice::render( [
			'variant' => $notice_variant,
			'message' => $notice_message,
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cb-admin-navigation-form>
		<input type="hidden" name="action" value="<?php echo esc_attr( \CoreBlueprint\Core\AdminNavigation\Admin::FORM_ACTION ); ?>" />
		<?php wp_nonce_field( \CoreBlueprint\Core\AdminNavigation\Admin::NONCE_ACTION, \CoreBlueprint\Core\AdminNavigation\Admin::NONCE_NAME ); ?>
		<input
			type="hidden"
			name="cb_admin_navigation_payload"
			value="<?php echo esc_attr( (string) wp_json_encode( $policy ) ); ?>"
			data-cb-admin-navigation-payload
		/>

		<section class="cb-core-preferences-section" aria-labelledby="cb-admin-navigation-menu-title">
			<h2 id="cb-admin-navigation-menu-title"><?php esc_html_e( 'Top-level admin menu', 'core-blueprint' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Only identities exposed by WordPress in this request, plus identities already stored in policy, are shown. Unknown plugin identities use their canonical ID as the label.', 'core-blueprint' ); ?>
			</p>
			<p>
				<label>
					<input type="checkbox" data-cb-admin-navigation-order-enabled <?php checked( ! empty( $policy['menu']['order'] ) ); ?> />
					<?php esc_html_e( 'Use custom top-level order', 'core-blueprint' ); ?>
				</label>
			</p>

			<div data-cb-core-reorder data-cb-admin-navigation-menu-reorder>
				<div data-cb-core-reorder-list="admin-menu" data-cb-core-reorder-list-label="<?php esc_attr_e( 'Top-level admin menu', 'core-blueprint' ); ?>" data-cb-admin-navigation-menu-list>
					<?php foreach ( $menu as $menu_index => $item ) : ?>
						<?php
						$id      = (string) ( $item['id'] ?? '' );
						$label   = (string) ( $item['label'] ?? $id );
						$present = ! empty( $item['present'] );
						$hidden  = is_array( $item['hidden'] ?? null ) ? $item['hidden'] : null;
						?>
						<article
							class="cb-core-admin-navigation-row cb-core-admin-navigation-row--menu"
							data-cb-core-reorder-item="<?php echo esc_attr( $id ); ?>"
							data-cb-core-reorder-label="<?php echo esc_attr( $label ); ?>"
							data-cb-admin-navigation-menu-row
							data-navigation-id="<?php echo esc_attr( $id ); ?>"
						>
							<?php
							/* translators: %s: admin navigation item label. */
							$reorder_aria_label = sprintf( __( 'Reorder %s', 'core-blueprint' ), $label );
							?>
							<button
								type="button"
								class="button-link cb-core-icon-control cb-core-reorder-handle cb-core-admin-navigation-row__drag"
								data-cb-core-reorder-handle
								aria-label="<?php echo esc_attr( $reorder_aria_label ); ?>"
							>
								<span class="dashicons dashicons-move" aria-hidden="true"></span>
							</button>

							<div class="cb-core-admin-navigation-row__identity">
								<strong><?php echo esc_html( $label ); ?></strong>
								<?php if ( $label !== $id ) : ?>
									<code><?php echo esc_html( $id ); ?></code>
								<?php endif; ?>
								<?php if ( ! $present ) : ?>
									<span class="cb-core-state-badge cb-core-state-badge--neutral"><?php esc_html_e( 'Not present in this request', 'core-blueprint' ); ?></span>
								<?php endif; ?>
							</div>

							<label class="cb-core-admin-navigation-row__toggle">
								<input type="checkbox" data-cb-admin-navigation-hide <?php checked( null !== $hidden ); ?> />
								<span><?php esc_html_e( 'Hide this menu item when the audience matches', 'core-blueprint' ); ?></span>
							</label>

							<details
								class="cb-core-disclosure cb-core-disclosure--compact cb-core-admin-navigation-row__audience"
								data-cb-admin-navigation-hide-audience
								<?php if ( null === $hidden ) : ?>hidden<?php endif; ?>
							>
								<summary class="cb-core-disclosure__summary">
									<span class="cb-core-disclosure__icon dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
									<span class="cb-core-disclosure__title"><?php esc_html_e( 'Audience', 'core-blueprint' ); ?></span>
								</summary>
								<div class="cb-core-disclosure__body">
									<div class="cb-core-field" role="group" aria-labelledby="cb-admin-navigation-menu-<?php echo esc_attr( (string) $menu_index ); ?>-hide-roles-label">
										<span class="cb-core-field__label" id="cb-admin-navigation-menu-<?php echo esc_attr( (string) $menu_index ); ?>-hide-roles-label"><?php esc_html_e( 'Roles', 'core-blueprint' ); ?></span>
										<div data-cb-admin-navigation-hide-roles-picker>
											<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output. ?>
											<?php echo $render_audience_picker( $hidden, 'roles', 'cb-admin-navigation-menu-' . (string) $menu_index . '-hide-roles' ); ?>
											<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</div>
									</div>
									<div class="cb-core-field" role="group" aria-labelledby="cb-admin-navigation-menu-<?php echo esc_attr( (string) $menu_index ); ?>-hide-capabilities-label">
										<span class="cb-core-field__label" id="cb-admin-navigation-menu-<?php echo esc_attr( (string) $menu_index ); ?>-hide-capabilities-label"><?php esc_html_e( 'Capabilities', 'core-blueprint' ); ?></span>
										<div data-cb-admin-navigation-hide-capabilities-picker>
											<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output. ?>
											<?php echo $render_audience_picker( $hidden, 'capabilities', 'cb-admin-navigation-menu-' . (string) $menu_index . '-hide-capabilities' ); ?>
											<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</div>
									</div>
								</div>
							</details>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<section class="cb-core-preferences-section" aria-labelledby="cb-admin-navigation-toolbar-title">
			<h2 id="cb-admin-navigation-toolbar-title"><?php esc_html_e( 'Toolbar', 'core-blueprint' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Toolbar nodes come from the current wp-admin request plus saved policy references. Markup-rich node titles are never renamed at runtime.', 'core-blueprint' ); ?>
			</p>

			<div class="cb-core-admin-navigation-toolbar-list">
			<?php foreach ( $toolbar as $toolbar_index => $item ) : ?>
				<?php
				$id      = (string) ( $item['id'] ?? '' );
				$label   = (string) ( $item['label'] ?? $id );
				$present = ! empty( $item['present'] );
				$hidden  = is_array( $item['hidden'] ?? null ) ? $item['hidden'] : null;
				$renamed = is_array( $item['renamed'] ?? null ) ? $item['renamed'] : null;
				$active  = null !== $hidden || null !== $renamed;
				?>
				<details
					class="cb-core-disclosure cb-core-disclosure--compact cb-core-admin-navigation-toolbar-item"
					data-cb-admin-navigation-toolbar-row
					data-navigation-id="<?php echo esc_attr( $id ); ?>"
					<?php if ( $active ) : ?>open<?php endif; ?>
				>
					<summary class="cb-core-disclosure__summary">
						<span class="cb-core-disclosure__icon dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
						<span class="cb-core-admin-navigation-toolbar-item__identity">
							<strong><?php echo esc_html( $label ); ?></strong>
							<?php if ( $label !== $id ) : ?>
								<code><?php echo esc_html( $id ); ?></code>
							<?php endif; ?>
							<?php if ( ! $present ) : ?>
								<span class="cb-core-state-badge cb-core-state-badge--neutral"><?php esc_html_e( 'Not present in this request', 'core-blueprint' ); ?></span>
							<?php endif; ?>
						</span>
					</summary>

					<div class="cb-core-disclosure__body">
						<label class="cb-core-admin-navigation-row__toggle">
							<input type="checkbox" data-cb-admin-navigation-hide <?php checked( null !== $hidden ); ?> />
							<span><?php esc_html_e( 'Hide this Toolbar node when the audience matches', 'core-blueprint' ); ?></span>
						</label>

						<div class="cb-core-admin-navigation-row__audience-fields" data-cb-admin-navigation-hide-audience <?php if ( null === $hidden ) : ?>hidden<?php endif; ?>>
							<div class="cb-core-field" role="group" aria-labelledby="cb-admin-navigation-toolbar-<?php echo esc_attr( (string) $toolbar_index ); ?>-hide-roles-label">
								<span class="cb-core-field__label" id="cb-admin-navigation-toolbar-<?php echo esc_attr( (string) $toolbar_index ); ?>-hide-roles-label"><?php esc_html_e( 'Roles', 'core-blueprint' ); ?></span>
								<div data-cb-admin-navigation-hide-roles-picker>
									<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output. ?>
									<?php echo $render_audience_picker( $hidden, 'roles', 'cb-admin-navigation-toolbar-' . (string) $toolbar_index . '-hide-roles' ); ?>
									<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							</div>
							<div class="cb-core-field" role="group" aria-labelledby="cb-admin-navigation-toolbar-<?php echo esc_attr( (string) $toolbar_index ); ?>-hide-capabilities-label">
								<span class="cb-core-field__label" id="cb-admin-navigation-toolbar-<?php echo esc_attr( (string) $toolbar_index ); ?>-hide-capabilities-label"><?php esc_html_e( 'Capabilities', 'core-blueprint' ); ?></span>
								<div data-cb-admin-navigation-hide-capabilities-picker>
									<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output. ?>
									<?php echo $render_audience_picker( $hidden, 'capabilities', 'cb-admin-navigation-toolbar-' . (string) $toolbar_index . '-hide-capabilities' ); ?>
									<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							</div>
						</div>

						<div class="cb-core-field cb-core-admin-navigation-row__rename">
							<label><?php esc_html_e( 'Rename to', 'core-blueprint' ); ?></label>
							<input type="text" class="regular-text" maxlength="120" value="<?php echo esc_attr( (string) ( $renamed['label'] ?? '' ) ); ?>" data-cb-admin-navigation-rename-label />
							<p class="description"><?php esc_html_e( 'Leave empty to keep the WordPress/plugin title.', 'core-blueprint' ); ?></p>
						</div>

						<div class="cb-core-admin-navigation-row__audience-fields" data-cb-admin-navigation-rename-audience <?php if ( null === $renamed ) : ?>hidden<?php endif; ?>>
							<div class="cb-core-field" role="group" aria-labelledby="cb-admin-navigation-toolbar-<?php echo esc_attr( (string) $toolbar_index ); ?>-rename-roles-label">
								<span class="cb-core-field__label" id="cb-admin-navigation-toolbar-<?php echo esc_attr( (string) $toolbar_index ); ?>-rename-roles-label"><?php esc_html_e( 'Roles', 'core-blueprint' ); ?></span>
								<div data-cb-admin-navigation-rename-roles-picker>
									<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output. ?>
									<?php echo $render_audience_picker( $renamed, 'roles', 'cb-admin-navigation-toolbar-' . (string) $toolbar_index . '-rename-roles' ); ?>
									<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							</div>
							<div class="cb-core-field" role="group" aria-labelledby="cb-admin-navigation-toolbar-<?php echo esc_attr( (string) $toolbar_index ); ?>-rename-capabilities-label">
								<span class="cb-core-field__label" id="cb-admin-navigation-toolbar-<?php echo esc_attr( (string) $toolbar_index ); ?>-rename-capabilities-label"><?php esc_html_e( 'Capabilities', 'core-blueprint' ); ?></span>
								<div data-cb-admin-navigation-rename-capabilities-picker>
									<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output. ?>
									<?php echo $render_audience_picker( $renamed, 'capabilities', 'cb-admin-navigation-toolbar-' . (string) $toolbar_index . '-rename-capabilities' ); ?>
									<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							</div>
						</div>
					</div>
				</details>
			<?php endforeach; ?>
			</div>
		</section>

		<p>
			<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Save changes', 'core-blueprint' ); ?></button>
			<button type="submit" name="cb_admin_navigation_reset" value="1" class="button cb-core-button cb-core-button--secondary"><?php esc_html_e( 'Reset Admin Navigation', 'core-blueprint' ); ?></button>
		</p>
	</form>
</div>
