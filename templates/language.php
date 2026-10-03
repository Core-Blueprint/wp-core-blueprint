<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Template: Language (M3b).
 *
 * Full interactive locale + description-mode switcher replacing the M1 stub.
 *
 * Variables (set by \CoreBlueprint\Core\Admin\Admin::render_language):
 *   $allowed       string[] - from \CoreBlueprint\Core\Locale::allowed()
 *   $current       string   - resolved locale for current user
 *   $user_pref     string   - raw user_meta, '' = inherit, 'auto' = auto
 *   $site_default  string   - raw option, 'auto' possible
 *   $desc_current  string   - resolved description mode for current user
 *   $desc_user     string   - raw user_meta, '' = inherit
 *   $desc_site     string   - raw option, fallback 'plain'
 *
 * Interactions:
 *   - Locale dropdown change → AJAX cb_core_set_locale → reload on success
 *     (locale changes require PHP rerender to take effect)
 *   - Description-mode radios → AJAX cb_core_set_description_mode → no reload
 *     needed (affects subsequent renders)
 *   - Scope toggle (manage_options only) switches between user_meta and option
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$can_manage_site = current_user_can( 'manage_options' );
?>
<div class="wrap cb-core-wrap cb-core-language">

	<h1 class="cb-core-title"><?php esc_html_e( 'Language', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Pick the interface language for Core Blueprint admin screens, and choose how technical content is explained. Multilingual plugins like Polylang or WPML remain authoritative when installed - Core Blueprint only fills in where no one else has spoken.', 'core-blueprint' ); ?>
	</p>

	<div class="cb-core-preferences-scopebar">
		<?php if ( $can_manage_site ) : ?>
			<div class="cb-core-scope-toggle" role="tablist" aria-label="<?php esc_attr_e( 'Scope', 'core-blueprint' ); ?>">
				<button type="button" class="cb-core-scope-option is-active" data-scope="user" role="tab" aria-selected="true">
					<?php esc_html_e( 'My preference', 'core-blueprint' ); ?>
				</button>
				<button type="button" class="cb-core-scope-option" data-scope="site" role="tab" aria-selected="false">
					<?php esc_html_e( 'Site default', 'core-blueprint' ); ?>
				</button>
			</div>
		<?php else : ?>
			<div class="cb-core-scope-label">
				<?php esc_html_e( 'Your personal preference', 'core-blueprint' ); ?>
			</div>
		<?php endif; ?>

		<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes own output ?>
		<?php echo \CoreBlueprint\Core\UI\FormStatus::render( [ 'block' => true, 'class' => 'cb-core-lang-status' ] ); ?>
		<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<!-- ─── Locale ─── -->

	<section class="cb-core-preferences-section cb-core-pref-locale cb-core-section--locale"
		data-user-pref="<?php echo esc_attr( $user_pref ); ?>"
		data-site-default="<?php echo esc_attr( $site_default ); ?>"
		data-effective="<?php echo esc_attr( $current ); ?>">

		<h2 class="cb-core-section-title"><?php esc_html_e( 'Interface language', 'core-blueprint' ); ?></h2>

		<?php
		ob_start();
		?>
			<select id="cb-core-locale-select" class="regular-text cb-core-language-select">
					<?php foreach ( $allowed as $code ) :
						$label    = \CoreBlueprint\Core\Locale::label( $code );
						$is_site  = ( $code === $site_default );
						$selected_user = ( $code === $user_pref );
					?>
						<option value="<?php echo esc_attr( $code ); ?>"
							data-user-selected="<?php echo $selected_user ? '1' : '0'; ?>"
							data-site-selected="<?php echo $is_site ? '1' : '0'; ?>"
							<?php selected( $selected_user ); ?>>
							<?php echo esc_html( $label . ( $is_site ? ' · ' . __( 'site default', 'core-blueprint' ) : '' ) ); ?>
						</option>
					<?php endforeach; ?>
			</select>
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes own output
		echo \CoreBlueprint\Core\UI\Field::render( [
			'label'     => __( 'Language', 'core-blueprint' ),
			'label_for' => 'cb-core-locale-select',
			'control'   => ob_get_clean(),
			'hint'      => sprintf(
				/* translators: 1: resolved locale label, 2: site default label. */
				__( 'Currently active: %1$s · Site default: %2$s', 'core-blueprint' ),
				\CoreBlueprint\Core\Locale::label( $current ),
				\CoreBlueprint\Core\Locale::label( $site_default )
			),
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

		<div class="cb-core-pref-reset cb-core-preferences-reset">
			<button type="button" class="button-link cb-core-locale-reset cb-core-preferences-reset-button">
				<?php esc_html_e( 'Reset to site default (clear my preference)', 'core-blueprint' ); ?>
			</button>
		</div>
	</section>

	<!-- ─── Description mode ─── -->

	<?php if ( class_exists( '\CoreBlueprint\Core\UI' ) ) : ?>
	<section class="cb-core-preferences-section cb-core-pref-descmode cb-core-section--descmode"
		data-user-pref="<?php echo esc_attr( $desc_user ); ?>"
		data-site-default="<?php echo esc_attr( $desc_site ); ?>"
		data-effective="<?php echo esc_attr( $desc_current ); ?>">

		<h2 class="cb-core-section-title"><?php esc_html_e( 'Description style', 'core-blueprint' ); ?></h2>

		<p class="description">
			<?php esc_html_e( 'How Core Blueprint explains security and technical concepts throughout the admin. Plain is written for non-technical audiences; Technical uses the precise jargon preferred by engineers.', 'core-blueprint' ); ?>
		</p>

		<div class="cb-core-radio-grid cb-core-radio-grid--columns-2">
			<?php
			$options = [
				'inherit'   => [
					'label'       => __( 'Use site default', 'core-blueprint' ),
					'description' => __( 'Follow the site-wide preference chosen by an administrator.', 'core-blueprint' ),
					'user_only'   => true,
				],
				'plain'     => [
					'label'       => __( 'Plain', 'core-blueprint' ),
					'description' => __( 'Human-friendly explanations. Good for non-technical users and onboarding clients.', 'core-blueprint' ),
					'user_only'   => false,
				],
				'technical' => [
					'label'       => __( 'Technical', 'core-blueprint' ),
					'description' => __( 'Precise jargon and implementation details. Good for engineers and power users.', 'core-blueprint' ),
					'user_only'   => false,
				],
				'sync'      => [
					'label'       => __( 'Sync with site mode', 'core-blueprint' ),
					'description' => __( 'Plain in production, Technical in development - follows the site\'s security mode.', 'core-blueprint' ),
					'user_only'   => false,
				],
			];
			$effective_user_key = ( '' === $desc_user ) ? 'inherit' : $desc_user;
			foreach ( $options as $key => $opt ) :
				$selected_user = ( $key === $effective_user_key );
				$selected_site = ( ! $opt['user_only'] && $key === $desc_site );
			?>
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
				echo \CoreBlueprint\Core\UI\RadioCard::render( [
					'variant'       => 'checkable',
					'name'          => 'cb-core-desc-mode',
					'value'         => $key,
					'label'         => $opt['label'],
					'desc'          => $opt['description'],
					'checked'       => $selected_user,
					'selected_user' => $selected_user,
					'selected_site' => $selected_site,
					'data'          => [
						'data-mode'      => $key,
						'data-user-only' => $opt['user_only'] ? '1' : '0',
					],
				] );
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

</div>
