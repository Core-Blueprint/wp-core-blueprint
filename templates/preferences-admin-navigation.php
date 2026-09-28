<?php
/**
 * Preferences > Admin Navigation.
 *
 * @var array<string,mixed> $state
 * @var string $notice
 */

defined( 'ABSPATH' ) || exit;

$policy  = is_array( $state['policy'] ?? null ) ? $state['policy'] : \CB\Core\AdminNavigation\Policy::defaults();
$menu    = is_array( $state['menu'] ?? null ) ? $state['menu'] : [];
$toolbar = is_array( $state['toolbar'] ?? null ) ? $state['toolbar'] : [];

$notice_message = '';
$notice_variant = \CB\Core\UI\Notice::SUCCESS;
if ( 'saved' === $notice ) {
	$notice_message = __( 'Admin Navigation policy saved.', 'core-blueprint' );
} elseif ( 'reset' === $notice ) {
	$notice_message = __( 'Admin Navigation policy reset to WordPress defaults.', 'core-blueprint' );
} elseif ( 'invalid' === $notice ) {
	$notice_message = __( 'The Admin Navigation policy could not be saved. Review the values and try again.', 'core-blueprint' );
	$notice_variant = \CB\Core\UI\Notice::ERROR;
}

$audience_value = static function ( ?array $rule, string $key ): string {
	$audience = is_array( $rule['audience'] ?? null ) ? $rule['audience'] : [];
	$values   = is_array( $audience[ $key ] ?? null ) ? $audience[ $key ] : [];
	return implode( ', ', array_map( 'strval', $values ) );
};
?>
<div
	class="wrap cb-core-wrap"
	data-cb-admin-navigation-editor
	data-policy-version="<?php echo esc_attr( (string) \CB\Core\AdminNavigation\Policy::VERSION ); ?>"
>
	<h1 class="cb-core-title"><?php esc_html_e( 'Admin Navigation', 'core-blueprint' ); ?></h1>
	<p class="cb-core-intro">
		<?php esc_html_e( 'Control presentation of the native WordPress top-level admin menu and Toolbar. Hidden items remain directly accessible to users who still have the required WordPress capability.', 'core-blueprint' ); ?>
	</p>

	<?php if ( '' !== $notice_message ) : ?>
		<?php
		echo \CB\Core\UI\Notice::render( [
			'variant' => $notice_variant,
			'message' => $notice_message,
		] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes own output.
		?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cb-admin-navigation-form>
		<input type="hidden" name="action" value="<?php echo esc_attr( \CB\Core\AdminNavigation\Admin::FORM_ACTION ); ?>" />
		<?php wp_nonce_field( \CB\Core\AdminNavigation\Admin::NONCE_ACTION, \CB\Core\AdminNavigation\Admin::NONCE_NAME ); ?>
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
					<?php foreach ( $menu as $item ) : ?>
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
							<button
								type="button"
								class="button-link cb-core-admin-navigation-row__drag"
								data-cb-core-reorder-handle
								aria-label="<?php echo esc_attr( sprintf( __( 'Reorder %s', 'core-blueprint' ), $label ) ); ?>"
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
									<div class="cb-core-field">
										<label><?php esc_html_e( 'Role slugs', 'core-blueprint' ); ?></label>
										<input type="text" class="regular-text" value="<?php echo esc_attr( $audience_value( $hidden, 'roles' ) ); ?>" data-cb-admin-navigation-hide-roles />
										<p class="description"><?php esc_html_e( 'Comma-separated. Empty means every role.', 'core-blueprint' ); ?></p>
									</div>
									<div class="cb-core-field">
										<label><?php esc_html_e( 'Capabilities', 'core-blueprint' ); ?></label>
										<input type="text" class="regular-text" value="<?php echo esc_attr( $audience_value( $hidden, 'capabilities' ) ); ?>" data-cb-admin-navigation-hide-capabilities />
										<p class="description"><?php esc_html_e( 'Comma-separated. Every listed capability must pass current_user_can().', 'core-blueprint' ); ?></p>
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

			<?php foreach ( $toolbar as $item ) : ?>
				<?php
				$id      = (string) ( $item['id'] ?? '' );
				$label   = (string) ( $item['label'] ?? $id );
				$present = ! empty( $item['present'] );
				$hidden  = is_array( $item['hidden'] ?? null ) ? $item['hidden'] : null;
				$renamed = is_array( $item['renamed'] ?? null ) ? $item['renamed'] : null;
				?>
				<article class="cb-core-admin-navigation-row cb-core-admin-navigation-row--toolbar" data-cb-admin-navigation-toolbar-row data-navigation-id="<?php echo esc_attr( $id ); ?>">
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
						<span><?php esc_html_e( 'Hide this Toolbar node when the audience matches', 'core-blueprint' ); ?></span>
					</label>

					<div class="cb-core-field cb-core-admin-navigation-row__rename">
						<label><?php esc_html_e( 'Rename to', 'core-blueprint' ); ?></label>
						<input type="text" class="regular-text" maxlength="120" value="<?php echo esc_attr( (string) ( $renamed['label'] ?? '' ) ); ?>" data-cb-admin-navigation-rename-label />
						<p class="description"><?php esc_html_e( 'Leave empty to keep the WordPress/plugin title.', 'core-blueprint' ); ?></p>
					</div>

					<div
						class="cb-core-admin-navigation-row__audience-fields"
						data-cb-admin-navigation-hide-audience
						<?php if ( null === $hidden ) : ?>hidden<?php endif; ?>
					>
						<div class="cb-core-field">
							<label><?php esc_html_e( 'Hide audience role slugs', 'core-blueprint' ); ?></label>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $audience_value( $hidden, 'roles' ) ); ?>" data-cb-admin-navigation-hide-roles />
						</div>
						<div class="cb-core-field">
							<label><?php esc_html_e( 'Hide audience capabilities', 'core-blueprint' ); ?></label>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $audience_value( $hidden, 'capabilities' ) ); ?>" data-cb-admin-navigation-hide-capabilities />
						</div>
					</div>

					<div
						class="cb-core-admin-navigation-row__audience-fields"
						data-cb-admin-navigation-rename-audience
						<?php if ( '' === (string) ( $renamed['label'] ?? '' ) ) : ?>hidden<?php endif; ?>
					>
						<div class="cb-core-field">
							<label><?php esc_html_e( 'Rename audience role slugs', 'core-blueprint' ); ?></label>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $audience_value( $renamed, 'roles' ) ); ?>" data-cb-admin-navigation-rename-roles />
						</div>
						<div class="cb-core-field">
							<label><?php esc_html_e( 'Rename audience capabilities', 'core-blueprint' ); ?></label>
							<input type="text" class="regular-text" value="<?php echo esc_attr( $audience_value( $renamed, 'capabilities' ) ); ?>" data-cb-admin-navigation-rename-capabilities />
						</div>
					</div>
				</article>
			<?php endforeach; ?>
		</section>

		<p>
			<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Save changes', 'core-blueprint' ); ?></button>
			<button type="submit" name="cb_admin_navigation_reset" value="1" class="button cb-core-button cb-core-button--secondary"><?php esc_html_e( 'Reset Admin Navigation', 'core-blueprint' ); ?></button>
		</p>
	</form>
</div>
