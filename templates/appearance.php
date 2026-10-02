<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Template: Appearance (M3a).
 *
 * Full interactive theme switcher replacing the M1 stub.
 *
 * Variables available (set by \CB\Core\Admin\Admin::render_appearance):
 *   $themes         array  - from \CB\Core\Themes::all()
 *   $current_theme  string - resolved theme for the current user
 *   $user_pref      string - raw user_meta, '' = inherit, 'auto' = auto
 *   $site_default   string - raw option, 'auto' possible
 *
 * Interactions:
 *   - Click a tile → instant preview via body[data-cb-theme] swap, AJAX save
 *     through the existing cb_core_set_theme endpoint
 *   - Scope toggle (user vs site) only shown to manage_options capability
 *   - Auto mode respects window.matchMedia('(prefers-color-scheme: ...)')
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$can_manage_site = current_user_can( 'manage_options' );

// Build theme tile data for rendering.
$auto_option = [
	'label'       => __( 'Auto Detect', 'core-blueprint' ),
	'description' => __( 'Follow the browser\'s light/dark preference. Defaults to Dark when the browser has none.', 'core-blueprint' ),
	'mode'        => 'auto',
	'family'      => 'auto',
];
?>
<div class="wrap cb-core-wrap cb-core-appearance">

	<h1 class="cb-core-title"><?php esc_html_e( 'Appearance', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Choose how the Core Blueprint admin interface looks - for yourself, or as the default for everyone. Every Core Blueprint plugin (Hub, Invoice, and the rest) inherits this setting.', 'core-blueprint' ); ?>
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

		<?php echo \CB\Core\UI\FormStatus::render( [ 'block' => true, 'class' => 'cb-core-appearance-status' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
	</div>

	<div class="cb-core-theme-grid" data-user-pref="<?php echo esc_attr( $user_pref ); ?>" data-site-default="<?php echo esc_attr( $site_default ); ?>">

		<?php
		// Auto first - it's the recommended default.
		$auto_selected_user = ( 'auto' === $user_pref );
		$auto_selected_site = ( 'auto' === $site_default );
		?>
		<button type="button"
			class="cb-core-theme-card cb-core-choice-card cb-core-theme-auto<?php echo $auto_selected_user ? ' is-selected-user' : ''; ?><?php echo $auto_selected_site ? ' is-selected-site' : ''; ?>"
			data-theme="auto"
			data-site-badge="<?php esc_attr_e( 'Site default', 'core-blueprint' ); ?>"
			aria-pressed="<?php echo $auto_selected_user ? 'true' : 'false'; ?>">
			<span class="cb-core-theme-preview cb-core-theme-preview-auto">
				<span class="cb-core-theme-preview-half is-dark"></span>
				<span class="cb-core-theme-preview-half is-light"></span>
			</span>
			<span class="cb-core-theme-card-body">
				<span class="cb-core-theme-card-label"><?php echo esc_html( $auto_option['label'] ); ?></span>
				<span class="cb-core-theme-card-desc"><?php echo esc_html( $auto_option['description'] ); ?></span>
			</span>
			<span class="cb-core-theme-card-check cb-core-choice-card__check" aria-hidden="true">✓</span>
		</button>

		<?php foreach ( $themes as $slug => $theme ) :
			$selected_user = ( $slug === $user_pref );
			$selected_site = ( $slug === $site_default );
			$mode          = $theme['mode'];
			$preview_class = 'cb-core-theme-preview-' . sanitize_html_class( $mode );
			$has_partner_svg = ( 'partner' === $theme['family'] && ! empty( $theme['preview_svg'] ) );
		?>
			<button type="button"
				class="cb-core-theme-card cb-core-choice-card<?php echo $selected_user ? ' is-selected-user' : ''; ?><?php echo $selected_site ? ' is-selected-site' : ''; ?>"
				data-theme="<?php echo esc_attr( $slug ); ?>"
				data-site-badge="<?php esc_attr_e( 'Site default', 'core-blueprint' ); ?>"
				aria-pressed="<?php echo $selected_user ? 'true' : 'false'; ?>">
				<?php if ( $has_partner_svg ) : ?>
					<span class="cb-core-theme-preview cb-core-theme-preview-partner">
						<?php echo \CB\Core\Themes::sanitize_preview_svg( (string) $theme['preview_svg'] ); ?>
					</span>
				<?php else : ?>
					<span class="cb-core-theme-preview <?php echo esc_attr( $preview_class ); ?>" data-theme-preview="<?php echo esc_attr( $slug ); ?>">
						<span class="cb-core-theme-preview-chrome"></span>
						<span class="cb-core-theme-preview-body">
							<span class="cb-core-theme-preview-swatch"></span>
							<span class="cb-core-theme-preview-line"></span>
							<span class="cb-core-theme-preview-line is-short"></span>
						</span>
					</span>
				<?php endif; ?>
				<span class="cb-core-theme-card-body">
					<span class="cb-core-theme-card-label"><?php echo esc_html( $theme['label'] ); ?></span>
					<span class="cb-core-theme-card-desc"><?php echo esc_html( $theme['description'] ); ?></span>
					<?php if ( 'partner' === $theme['family'] && ! empty( $theme['author'] ) ) : ?>
						<span class="cb-core-theme-card-author">
							<?php echo esc_html( sprintf( /* translators: %s: theme author */ __( 'By %s', 'core-blueprint' ), $theme['author'] ) ); ?>
						</span>
					<?php endif; ?>
				</span>
				<span class="cb-core-theme-card-check cb-core-choice-card__check" aria-hidden="true">✓</span>
			</button>
		<?php endforeach; ?>

	</div>

	<div class="cb-core-appearance-reset cb-core-preferences-reset">
		<button type="button" class="button-link cb-core-theme-reset cb-core-preferences-reset-button" data-scope="user">
			<?php esc_html_e( 'Reset to site default (clear my preference)', 'core-blueprint' ); ?>
		</button>
	</div>

	<section class="cb-core-preferences-section cb-core-appearance-info">
		<h2 class="cb-core-section-title"><?php esc_html_e( 'How resolution works', 'core-blueprint' ); ?></h2>
		<ol class="cb-core-resolve-chain">
			<li><?php esc_html_e( 'Your personal preference (set above).', 'core-blueprint' ); ?></li>
			<li><?php esc_html_e( 'The site default (set by an administrator).', 'core-blueprint' ); ?></li>
			<li><?php esc_html_e( 'Auto mode: the browser\'s prefers-color-scheme.', 'core-blueprint' ); ?></li>
			<li><?php esc_html_e( 'Fallback: Core Blueprint - Dark.', 'core-blueprint' ); ?></li>
		</ol>
		<p class="description">
			<?php esc_html_e( 'Partner themes register via the filter cb_admin_themes.', 'core-blueprint' ); ?>
		</p>
	</section>

</div>
