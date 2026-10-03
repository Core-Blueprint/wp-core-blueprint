<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Preferences > Admin Notices.
 *
 * @var array<string,mixed> $state
 * @var string $notice
 */

defined( 'ABSPATH' ) || exit;

$policy  = is_array( $state['policy'] ?? null ) ? $state['policy'] : \CoreBlueprint\Core\AdminNotices\Policy::defaults();
$sources = is_array( $state['sources'] ?? null ) ? $state['sources'] : [];
$summary = is_array( $state['summary'] ?? null ) ? $state['summary'] : [];

$notice_message = '';
$notice_variant = \CoreBlueprint\Core\UI\Notice::SUCCESS;
if ( 'saved' === $notice ) {
	$notice_message = __( 'Admin Notices policy saved.', 'core-blueprint' );
} elseif ( 'reset' === $notice ) {
	$notice_message = __( 'Admin Notices policy reset to show supported notices to everyone.', 'core-blueprint' );
} elseif ( 'invalid' === $notice ) {
	$notice_message = __( 'The Admin Notices policy could not be saved. Review the audience choices and try again.', 'core-blueprint' );
	$notice_variant = \CoreBlueprint\Core\UI\Notice::ERROR;
}

$picker_nonce = wp_create_nonce( \CoreBlueprint\Core\AdminNotices\Admin::PICKER_NONCE_ACTION );

$audience_references = static function ( array $rule, string $key ): array {
	$audience = is_array( $rule['audience'] ?? null ) ? $rule['audience'] : [];
	$values   = is_array( $audience[ $key ] ?? null ) ? $audience[ $key ] : [];
	return array_values( array_filter( array_map( 'strval', $values ), static fn( string $value ): bool => '' !== $value ) );
};

$render_audience_picker = static function ( array $rule, string $kind, string $id ) use ( $audience_references, $picker_nonce ): string {
	$references = $audience_references( $rule, $kind );
	$is_roles   = 'roles' === $kind;
	$selected   = $is_roles
		? \CoreBlueprint\Core\AdminNotices\Admin::role_picker_items( $references )
		: \CoreBlueprint\Core\AdminNotices\Admin::capability_picker_items( $references );

	return \CoreBlueprint\Core\UI\ObjectPicker::render( [
		'name'      => str_replace( '-', '_', $id ),
		'id'        => $id,
		'multiple'  => true,
		'action'    => $is_roles
			? \CoreBlueprint\Core\AdminNotices\Admin::ROLE_SEARCH_ACTION
			: \CoreBlueprint\Core\AdminNotices\Admin::CAPABILITY_SEARCH_ACTION,
		'nonce'     => $picker_nonce,
		'selected'  => $selected,
		'show_hint' => false,
	] );
};

$kind_labels = [
	\CoreBlueprint\Core\AdminNotices\SourceResolver::KIND_WORDPRESS => __( 'WordPress', 'core-blueprint' ),
	\CoreBlueprint\Core\AdminNotices\SourceResolver::KIND_PLUGIN    => __( 'Plugin', 'core-blueprint' ),
	\CoreBlueprint\Core\AdminNotices\SourceResolver::KIND_MU_PLUGIN => __( 'MU plugin', 'core-blueprint' ),
	\CoreBlueprint\Core\AdminNotices\SourceResolver::KIND_THEME     => __( 'Theme', 'core-blueprint' ),
	\CoreBlueprint\Core\AdminNotices\SourceResolver::KIND_UNKNOWN   => __( 'Unknown source', 'core-blueprint' ),
];
?>
<div
	class="wrap cb-core-wrap"
	data-cb-admin-notices-editor
	data-policy-version="<?php echo esc_attr( (string) \CoreBlueprint\Core\AdminNotices\Policy::VERSION ); ?>"
>
	<h1 class="cb-core-title"><?php esc_html_e( 'Admin Notices', 'core-blueprint' ); ?></h1>
	<p class="cb-core-intro">
		<?php esc_html_e( 'Control who sees supported WordPress admin notice sources. This changes presentation only: permissions, actions and the notice source remain untouched.', 'core-blueprint' ); ?>
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

	<div class="cb-core-status-strip" aria-label="<?php esc_attr_e( 'Admin Notices summary', 'core-blueprint' ); ?>">
		<div class="cb-core-status-card">
			<span class="cb-core-status-card__label"><?php esc_html_e( 'Observed sources', 'core-blueprint' ); ?></span>
			<strong class="cb-core-status-card__value"><?php echo esc_html( (string) (int) ( $summary['sources'] ?? 0 ) ); ?></strong>
		</div>
		<div class="cb-core-status-card">
			<span class="cb-core-status-card__label"><?php esc_html_e( 'Restricted', 'core-blueprint' ); ?></span>
			<strong class="cb-core-status-card__value"><?php echo esc_html( (string) (int) ( $summary['restricted'] ?? 0 ) ); ?></strong>
		</div>
		<div class="cb-core-status-card">
			<span class="cb-core-status-card__label"><?php esc_html_e( 'Protected', 'core-blueprint' ); ?></span>
			<strong class="cb-core-status-card__value"><?php echo esc_html( (string) (int) ( $summary['protected'] ?? 0 ) ); ?></strong>
		</div>
		<div class="cb-core-status-card">
			<span class="cb-core-status-card__label"><?php esc_html_e( 'Unknown', 'core-blueprint' ); ?></span>
			<strong class="cb-core-status-card__value"><?php echo esc_html( (string) (int) ( $summary['unknown'] ?? 0 ) ); ?></strong>
		</div>
	</div>

	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
	echo \CoreBlueprint\Core\UI\Notice::render( [
		'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
		'message' => __( 'Core Blueprint operators and delegated Admin Notices managers always see governed notices. Protected and unattributable sources always remain visible.', 'core-blueprint' ),
	] );
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cb-admin-notices-form>
		<input type="hidden" name="action" value="<?php echo esc_attr( \CoreBlueprint\Core\AdminNotices\Admin::FORM_ACTION ); ?>" />
		<?php wp_nonce_field( \CoreBlueprint\Core\AdminNotices\Admin::NONCE_ACTION, \CoreBlueprint\Core\AdminNotices\Admin::NONCE_NAME ); ?>
		<input
			type="hidden"
			name="cb_admin_notices_payload"
			value="<?php echo esc_attr( (string) wp_json_encode( $policy ) ); ?>"
			data-cb-admin-notices-payload
		/>

		<?php if ( [] === $sources ) : ?>
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Card renderer escapes structured content.
			echo \CoreBlueprint\Core\UI\Card::render( [
				'title' => __( 'Notice sources', 'core-blueprint' ),
				'body'  => '',
				'empty' => [
					'title'       => __( 'No notice sources observed yet', 'core-blueprint' ),
					'description' => __( 'Open normal WordPress admin screens and return here. Core Blueprint records only source metadata from supported notice hooks, never the notice message or action data.', 'core-blueprint' ),
				],
			] );
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		<?php else : ?>
			<section class="cb-core-preferences-section" aria-labelledby="cb-admin-notices-sources-title">
				<h2 id="cb-admin-notices-sources-title"><?php esc_html_e( 'Notice sources', 'core-blueprint' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Choose the audience per supported source. Everyone is the WordPress default. Operators only is intended for technical agency or maintenance notices that clients do not need to see.', 'core-blueprint' ); ?>
				</p>

				<?php foreach ( $sources as $index => $source ) : ?>
					<?php
					$source_id  = (string) ( $source['id'] ?? '' );
					$label      = (string) ( $source['label'] ?? $source_id );
					$kind       = (string) ( $source['kind'] ?? \CoreBlueprint\Core\AdminNotices\SourceResolver::KIND_UNKNOWN );
					$manageable = ! empty( $source['manageable'] );
					$protected  = ! empty( $source['protected'] );
					$rule       = is_array( $source['rule'] ?? null ) ? $source['rule'] : \CoreBlueprint\Core\AdminNotices\Policy::rule_for( $source_id );
					$visibility = (string) ( $rule['visibility'] ?? \CoreBlueprint\Core\AdminNotices\Policy::EVERYONE );
					$hooks      = array_values( array_filter( array_map( 'strval', (array) ( $source['hooks'] ?? [] ) ) ) );
					$active     = \CoreBlueprint\Core\AdminNotices\Policy::EVERYONE !== $visibility;
					?>
					<details
						class="cb-core-disclosure cb-core-disclosure--compact"
						data-cb-admin-notices-source
						data-source-id="<?php echo esc_attr( $source_id ); ?>"
						data-manageable="<?php echo $manageable && ! $protected ? '1' : '0'; ?>"
						<?php if ( $active ) : ?>open<?php endif; ?>
					>
						<summary class="cb-core-disclosure__summary">
							<span class="cb-core-disclosure__icon dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
							<span class="cb-core-disclosure__title">
								<strong><?php echo esc_html( $label ); ?></strong>
								<?php if ( $label !== $source_id ) : ?>
									<code><?php echo esc_html( $source_id ); ?></code>
								<?php endif; ?>
							</span>
							<?php if ( $protected ) : ?>
								<span class="cb-core-state-badge cb-core-state-badge--compact cb-core-state-badge--warning"><?php esc_html_e( 'Protected', 'core-blueprint' ); ?></span>
							<?php elseif ( ! $manageable ) : ?>
								<span class="cb-core-state-badge cb-core-state-badge--compact cb-core-state-badge--neutral"><?php esc_html_e( 'Observed only', 'core-blueprint' ); ?></span>
							<?php elseif ( \CoreBlueprint\Core\AdminNotices\Policy::OPERATORS_ONLY === $visibility ) : ?>
								<span class="cb-core-state-badge cb-core-state-badge--compact cb-core-state-badge--info"><?php esc_html_e( 'Operators only', 'core-blueprint' ); ?></span>
							<?php elseif ( \CoreBlueprint\Core\AdminNotices\Policy::SELECTED === $visibility ) : ?>
								<span class="cb-core-state-badge cb-core-state-badge--compact cb-core-state-badge--info"><?php esc_html_e( 'Selected audience', 'core-blueprint' ); ?></span>
							<?php else : ?>
								<span class="cb-core-state-badge cb-core-state-badge--compact cb-core-state-badge--success"><?php esc_html_e( 'Everyone', 'core-blueprint' ); ?></span>
							<?php endif; ?>
						</summary>

						<div class="cb-core-disclosure__body">
							<p class="description">
								<?php
								printf(
									/* translators: 1: source type, 2: notice hooks, 3: observed callback count */
									esc_html__( 'Source type: %1$s. Hooks: %2$s. Observed callbacks: %3$d.', 'core-blueprint' ),
									esc_html( $kind_labels[ $kind ] ?? $kind ),
									esc_html( [] !== $hooks ? implode( ', ', $hooks ) : __( 'Not observed in this ledger', 'core-blueprint' ) ),
									(int) ( $source['callback_count'] ?? 0 )
								);
								?>
							</p>

							<?php if ( $protected ) : ?>
								<?php
								// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
								echo \CoreBlueprint\Core\UI\Notice::render( [
									'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
									'message' => __( 'This source is protected and always remains visible to everyone.', 'core-blueprint' ),
								] );
								// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							<?php elseif ( ! $manageable ) : ?>
								<?php
								// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
								echo \CoreBlueprint\Core\UI\Notice::render( [
									'variant' => \CoreBlueprint\Core\UI\Notice::INFO,
									'message' => __( 'This source could not be attributed to a stable WordPress, plugin or theme identity, so Core Blueprint will not suppress it.', 'core-blueprint' ),
								] );
								// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							<?php else : ?>
								<fieldset class="cb-core-field" data-cb-admin-notices-visibility-group>
									<legend class="cb-core-field__label"><?php esc_html_e( 'Who should see this notice source?', 'core-blueprint' ); ?></legend>
									<div class="cb-core-field__choices cb-core-admin-notices-visibility-choices">
										<label>
											<input type="radio" name="cb_admin_notices_visibility_<?php echo esc_attr( (string) $index ); ?>" value="everyone" data-cb-admin-notices-visibility <?php checked( \CoreBlueprint\Core\AdminNotices\Policy::EVERYONE, $visibility ); ?> />
											<?php esc_html_e( 'Everyone', 'core-blueprint' ); ?>
										</label>
										<label>
											<input type="radio" name="cb_admin_notices_visibility_<?php echo esc_attr( (string) $index ); ?>" value="operators_only" data-cb-admin-notices-visibility <?php checked( \CoreBlueprint\Core\AdminNotices\Policy::OPERATORS_ONLY, $visibility ); ?> />
											<?php esc_html_e( 'Operators only', 'core-blueprint' ); ?>
										</label>
										<label>
											<input type="radio" name="cb_admin_notices_visibility_<?php echo esc_attr( (string) $index ); ?>" value="selected" data-cb-admin-notices-visibility <?php checked( \CoreBlueprint\Core\AdminNotices\Policy::SELECTED, $visibility ); ?> />
											<?php esc_html_e( 'Selected roles or capabilities', 'core-blueprint' ); ?>
										</label>
									</div>
								</fieldset>

								<div data-cb-admin-notices-selected-audience <?php if ( \CoreBlueprint\Core\AdminNotices\Policy::SELECTED !== $visibility ) : ?>hidden<?php endif; ?>>
									<div class="cb-core-field" role="group" aria-labelledby="cb-admin-notices-<?php echo esc_attr( (string) $index ); ?>-roles-label">
										<span class="cb-core-field__label" id="cb-admin-notices-<?php echo esc_attr( (string) $index ); ?>-roles-label"><?php esc_html_e( 'Roles', 'core-blueprint' ); ?></span>
										<div data-cb-admin-notices-roles-picker>
											<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output. ?>
											<?php echo $render_audience_picker( $rule, 'roles', 'cb-admin-notices-' . (string) $index . '-roles' ); ?>
											<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</div>
									</div>
									<div class="cb-core-field" role="group" aria-labelledby="cb-admin-notices-<?php echo esc_attr( (string) $index ); ?>-capabilities-label">
										<span class="cb-core-field__label" id="cb-admin-notices-<?php echo esc_attr( (string) $index ); ?>-capabilities-label"><?php esc_html_e( 'Capabilities', 'core-blueprint' ); ?></span>
										<div data-cb-admin-notices-capabilities-picker>
											<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Foundation renderer escapes output. ?>
											<?php echo $render_audience_picker( $rule, 'capabilities', 'cb-admin-notices-' . (string) $index . '-capabilities' ); ?>
											<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</div>
									</div>
									<p class="description"><?php esc_html_e( 'Selected audience uses OR matching: a matching role or capability is enough. Operators remain visible regardless of this selection.', 'core-blueprint' ); ?></p>
								</div>
							<?php endif; ?>
						</div>
					</details>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>

		<p>
			<button type="submit" class="button button-primary cb-core-button cb-core-button--primary"><?php esc_html_e( 'Save changes', 'core-blueprint' ); ?></button>
			<button type="submit" name="cb_admin_notices_reset" value="1" class="button cb-core-button cb-core-button--secondary"><?php esc_html_e( 'Reset Admin Notices', 'core-blueprint' ); ?></button>
		</p>
	</form>
</div>
