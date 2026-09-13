<?php
/**
 * Preferences → Reports Designer
 *
 * Variables provided by Preferences::render_reports_tab():
 *   - $branding             array current reports.branding values
 *   - $fallback             array raw ReportBranding defaults
 *   - $logo_attachment_id   int current logo attachment ID
 *   - $logo_url             string resolved Media Library URL
 *   - $logo_alt             string attachment alt text
 *   - $nonce                string cb_core_admin nonce
 *
 * Designer presentation is entirely Base-owned. This template supplies only
 * Reports domain controls and the Flow-rendered preview surface.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$provider_name       = (string) ( $branding['provider_name'] ?? '' );
$provider_contact    = (string) ( $branding['provider_contact'] ?? '' );
$accent_color        = (string) ( $branding['accent_color'] ?? $fallback['accent_color'] );
$is_enabled          = class_exists( '\\CB\\Core\\Reports\\State' ) ? \CB\Core\Reports\State::is_enabled() : true;
$can_manage_branding = current_user_can( 'cb_manage_branding' );
?>
<div
	class="wrap cb-core-wrap cb-core-reports-preferences"
	<?php if ( $can_manage_branding ) : ?>
		data-cb-design-launch-root
		data-cb-design-title="<?php esc_attr_e( 'Reports', 'core-blueprint' ); ?>"
	<?php endif; ?>
>
	<div <?php echo $can_manage_branding ? 'data-cb-design-launch-context' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute. ?>>
		<h1 class="cb-core-title"><?php esc_html_e( 'Reports', 'core-blueprint' ); ?></h1>

		<p class="cb-core-intro">
			<?php esc_html_e( 'Design the appearance and provider details used when Maintenance Reports are rendered. Stored report snapshots remain immutable.', 'core-blueprint' ); ?>
		</p>

		<?php if ( ! $is_enabled ) : ?>
			<?php
			echo \CB\Core\UI\Notice::render( [
				'variant' => \CB\Core\UI\Notice::INFO,
				'title'   => __( 'Reports is disabled.', 'core-blueprint' ),
				'message' => __( 'Report appearance stays editable. Enable Reports from the Dashboard when you want to generate reports again.', 'core-blueprint' ),
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes own output.
			?>
		<?php endif; ?>

		<?php if ( ! $can_manage_branding ) : ?>
			<?php
			echo \CB\Core\UI\Notice::render( [
				'variant' => \CB\Core\UI\Notice::INFO,
				'title'   => __( 'Report appearance is operator-managed.', 'core-blueprint' ),
				'message' => __( 'You can manage report generation, but only a Core Blueprint operator may change report branding and provider details.', 'core-blueprint' ),
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes own output.
			?>
		<?php else : ?>
			<section class="card">
				<h2><?php esc_html_e( 'Maintenance Report appearance', 'core-blueprint' ); ?></h2>
				<p>
					<?php esc_html_e( 'Open Designer Mode to preview the real typed report layout while changing the logo, accent colour, and optional provider details. Changes are not stored until you choose Save.', 'core-blueprint' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=core-blueprint-reports&tab=maintenance' ) ); ?>">
						<?php esc_html_e( 'Go to Maintenance Reports', 'core-blueprint' ); ?> →
					</a>
				</p>
			</section>
		<?php endif; ?>
	</div>

	<?php if ( $can_manage_branding ) : ?>
		<form
			id="cb-core-branding-form"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-cb-reports-designer
		>
			<div class="cb-core-design-shell" data-cb-design-shell hidden>
				<div class="cb-core-design-shell__toolbar">
					<div class="cb-core-design-shell__toolbar-group">
						<span data-cb-design-shell-status aria-live="polite"></span>
					</div>
					<div class="cb-core-design-shell__toolbar-group">
						<button
							type="button"
							class="button cb-core-button cb-core-button--secondary"
							data-cb-design-shell-fullscreen
							data-cb-design-shell-fullscreen-enter-label="<?php echo esc_attr__( 'Open Designer Mode', 'core-blueprint' ); ?>"
							data-cb-design-shell-fullscreen-exit-label="<?php echo esc_attr__( 'Close', 'default' ); ?>"
							aria-pressed="false"
						><span data-cb-design-shell-fullscreen-label><?php echo esc_html__( 'Open Designer Mode', 'core-blueprint' ); ?></span></button>
						<button
							type="button"
							class="button button-primary cb-core-button cb-core-button--primary"
							id="cb-core-save-branding"
							data-cb-design-shell-primary-action
						><?php esc_html_e( 'Save', 'core-blueprint' ); ?></button>
					</div>
				</div>

				<div data-cb-design-shell-toolbar-extension="actions">
					<button type="button" id="cb-core-reset-branding"><?php esc_html_e( 'Reset to defaults', 'core-blueprint' ); ?></button>
				</div>

				<div class="cb-core-design-shell__workspace">
					<aside
						class="cb-core-design-shell__palette cb-core-design-shell__palette--composed"
						aria-label="<?php esc_attr_e( 'Report types', 'core-blueprint' ); ?>"
					>
						<div class="cb-core-design-shell__panel-body">
							<section class="cb-core-design-shell__panel-section">
								<h3 class="cb-core-design-shell__panel-section-title"><?php esc_html_e( 'Report type', 'core-blueprint' ); ?></h3>
								<p class="cb-core-design-shell__panel-section-description">
									<?php esc_html_e( 'Choose the report whose presentation you are designing.', 'core-blueprint' ); ?>
								</p>
								<div class="cb-core-design-shell__palette-grid">
									<button
										type="button"
										class="cb-core-design-shell__palette-item"
										data-cb-report-type="maintenance"
										aria-pressed="true"
									><?php esc_html_e( 'Maintenance Report', 'core-blueprint' ); ?></button>
								</div>
							</section>
						</div>
					</aside>

					<main class="cb-core-design-shell__canvas cb-core-design-shell__canvas--composed">
						<header class="cb-core-design-shell__canvas-header">
							<div class="cb-core-design-shell__canvas-heading">
								<h2 class="cb-core-design-shell__canvas-title"><?php esc_html_e( 'Maintenance Report', 'core-blueprint' ); ?></h2>
								<p class="cb-core-design-shell__canvas-description">
									<?php esc_html_e( 'Preview uses the same typed Document Flow compiler and HTML renderer as the production report path.', 'core-blueprint' ); ?>
								</p>
							</div>
						</header>

						<div class="cb-core-design-shell__canvas-workarea">
							<div class="cb-core-design-shell__surface cb-core-design-shell__surface--document">
								<div class="cb-core-design-shell__empty-state" data-cb-report-preview-state>
									<?php esc_html_e( 'Loading report preview…', 'core-blueprint' ); ?>
								</div>
								<iframe
									data-cb-report-preview
									title="<?php esc_attr_e( 'Maintenance Report preview', 'core-blueprint' ); ?>"
									sandbox=""
									width="100%"
									height="980"
									style="display:block;border:0;background:#fff;"
									hidden
								></iframe>
							</div>
						</div>
					</main>

					<aside
						class="cb-core-design-shell__sidebar cb-core-design-shell__sidebar--composed"
						aria-label="<?php esc_attr_e( 'Report appearance', 'core-blueprint' ); ?>"
					>
						<div class="cb-core-design-shell__panel-body">
							<section class="cb-core-design-shell__panel-section">
								<h3 class="cb-core-design-shell__panel-section-title"><?php esc_html_e( 'Appearance', 'core-blueprint' ); ?></h3>
								<p class="cb-core-design-shell__panel-section-description">
									<?php esc_html_e( 'Presentation changes are applied at render time and never rewrite stored report snapshots.', 'core-blueprint' ); ?>
								</p>

								<div class="cb-core-design-shell__field">
									<span class="cb-core-design-shell__field-label"><?php esc_html_e( 'Logo', 'core-blueprint' ); ?></span>
									<input type="hidden" name="logo_attachment_id" id="cb-core-logo-id" value="<?php echo (int) $logo_attachment_id; ?>">
									<div id="cb-core-logo-preview" data-has-logo="<?php echo '' !== $logo_url ? 'yes' : 'no'; ?>">
										<?php if ( '' !== $logo_url ) : ?>
											<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $logo_alt ); ?>" style="display:block;max-width:100%;max-height:96px;width:auto;height:auto;">
										<?php else : ?>
											<span><?php esc_html_e( 'No logo set', 'core-blueprint' ); ?></span>
										<?php endif; ?>
									</div>
									<div class="cb-core-design-shell__panel-actions">
										<button type="button" class="button" id="cb-core-logo-pick">
											<?php echo $logo_attachment_id > 0 ? esc_html__( 'Change logo', 'core-blueprint' ) : esc_html__( 'Select logo', 'core-blueprint' ); ?>
										</button>
										<button type="button" class="button-link" id="cb-core-logo-remove" <?php echo $logo_attachment_id > 0 ? '' : 'hidden'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static boolean attribute. ?>><?php esc_html_e( 'Remove', 'core-blueprint' ); ?></button>
									</div>
									<span class="cb-core-design-shell__field-hint"><?php esc_html_e( 'SVG, PNG or JPEG up to 2 MB. Raster logos may be at most 4096 × 4096 pixels.', 'core-blueprint' ); ?></span>
								</div>

								<label class="cb-core-design-shell__field">
									<span class="cb-core-design-shell__field-label"><?php esc_html_e( 'Accent colour', 'core-blueprint' ); ?></span>
									<input type="color" id="cb-core-accent-color" name="accent_color" value="<?php echo esc_attr( $accent_color ); ?>">
									<input type="text" id="cb-core-accent-hex" value="<?php echo esc_attr( $accent_color ); ?>" maxlength="7" pattern="^#[0-9a-fA-F]{6}$" aria-label="<?php esc_attr_e( 'Accent colour hex value', 'core-blueprint' ); ?>">
									<span class="cb-core-design-shell__field-hint">
										<?php
										printf(
											/* translators: %s: default colour hex code. */
											esc_html__( 'Used by headings and table accents. Default: %s.', 'core-blueprint' ),
											esc_html( (string) $fallback['accent_color'] )
										);
										?>
									</span>
								</label>
							</section>

							<section class="cb-core-design-shell__panel-section">
								<h3 class="cb-core-design-shell__panel-section-title"><?php esc_html_e( 'Report provider', 'core-blueprint' ); ?></h3>
								<p class="cb-core-design-shell__panel-section-description"><?php esc_html_e( 'Optional details about the person or organisation preparing the report.', 'core-blueprint' ); ?></p>

								<label class="cb-core-design-shell__field">
									<span class="cb-core-design-shell__field-label"><?php esc_html_e( 'Name', 'core-blueprint' ); ?></span>
									<input type="text" id="cb-core-provider-name" name="provider_name" value="<?php echo esc_attr( $provider_name ); ?>" maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Infused', 'core-blueprint' ); ?>">
									<span class="cb-core-design-shell__field-hint"><?php esc_html_e( 'Shown as “Prepared by” when filled in.', 'core-blueprint' ); ?></span>
								</label>

								<label class="cb-core-design-shell__field">
									<span class="cb-core-design-shell__field-label"><?php esc_html_e( 'Contact', 'core-blueprint' ); ?></span>
									<input type="text" id="cb-core-provider-contact" name="provider_contact" value="<?php echo esc_attr( $provider_contact ); ?>" maxlength="200" placeholder="<?php esc_attr_e( 'e.g. support@example.com', 'core-blueprint' ); ?>">
									<span class="cb-core-design-shell__field-hint"><?php esc_html_e( 'Email, phone, address, or another short contact line.', 'core-blueprint' ); ?></span>
								</label>
							</section>
						</div>
					</aside>
				</div>
			</div>
		</form>
	<?php endif; ?>
</div>
