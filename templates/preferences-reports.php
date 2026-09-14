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
			<?php esc_html_e( 'Configure report appearance and optional provider details. Report content is stored as an immutable snapshot; presentation settings can be updated independently.', 'core-blueprint' ); ?>
		</p>

		<?php if ( ! $is_enabled ) : ?>
			<?php
			echo \CB\Core\UI\Notice::render( [
				'variant' => \CB\Core\UI\Notice::INFO,
				'title'   => __( 'Reports is disabled.', 'core-blueprint' ),
				'message' => __( 'Branding below stays editable. Enable Reports from the Dashboard when you want to generate reports again.', 'core-blueprint' ),
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes own output.
			?>
		<?php endif; ?>

		<?php if ( ! $can_manage_branding ) : ?>
			<?php
			echo \CB\Core\UI\Notice::render( [
				'variant' => \CB\Core\UI\Notice::INFO,
				'title'   => __( 'Reports', 'core-blueprint' ),
				'message' => __( 'Sorry, you are not allowed to access this page.' ),
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes own output.
			?>
		<?php else : ?>
			<section class="card">
				<h2><?php esc_html_e( 'Report appearance', 'core-blueprint' ); ?></h2>
				<p>
					<?php esc_html_e( 'Logo and accent colour are applied when a report PDF is viewed or downloaded. Changing them does not change the stored report content.', 'core-blueprint' ); ?>
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
					<div data-cb-design-shell-context>
						<label class="screen-reader-text" for="cb-core-report-type-switcher"><?php esc_html_e( 'Report type', 'core-blueprint' ); ?></label>
						<select id="cb-core-report-type-switcher" aria-label="<?php esc_attr_e( 'Report type', 'core-blueprint' ); ?>">
							<option value="maintenance" selected><?php esc_html_e( 'Maintenance Report', 'core-blueprint' ); ?></option>
						</select>
					</div>
					<div class="cb-core-design-shell__toolbar-group">
						<span data-cb-design-shell-status aria-live="polite"></span>
					</div>
					<div class="cb-core-design-shell__toolbar-group">
						<span data-cb-design-shell-group-label><?php esc_html_e( 'History', 'core-blueprint' ); ?></span>
						<button type="button" class="button cb-core-button" data-cb-design-shell-undo disabled><?php esc_html_e( 'Undo', 'core-blueprint' ); ?></button>
						<button type="button" class="button cb-core-button" data-cb-design-shell-redo disabled><?php esc_html_e( 'Redo', 'core-blueprint' ); ?></button>
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
							aria-label="<?php esc_attr_e( 'Save report settings', 'core-blueprint' ); ?>"
						><?php esc_html_e( 'Save', 'core-blueprint' ); ?></button>
					</div>
				</div>

				<div data-cb-design-shell-toolbar-extension="actions">
					<button type="button" id="cb-core-reset-branding"><?php esc_html_e( 'Reset to defaults', 'core-blueprint' ); ?></button>
				</div>

				<div class="cb-core-design-shell__workspace">
					<section
						class="cb-core-design-shell__palette cb-core-design-shell__palette--tabbed cb-core-design-shell__palette--composed"
						aria-label="<?php esc_attr_e( 'Content', 'core-blueprint' ); ?>"
					>
						<div class="cb-core-design-shell__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Content', 'core-blueprint' ); ?>">
							<button
								type="button"
								class="cb-core-design-shell__tab is-active"
								role="tab"
								aria-selected="true"
								data-cb-design-shell-group="palette"
								data-cb-design-shell-tab="elements"
							><?php esc_html_e( 'Elements', 'core-blueprint' ); ?></button>
						</div>
						<div
							class="cb-core-design-shell__panel"
							data-cb-design-shell-group="palette"
							data-cb-design-shell-panel="elements"
						>
							<div class="cb-core-design-shell__panel-body">
								<div class="cb-core-design-shell__palette-grid" data-cb-report-elements></div>
							</div>
						</div>
					</section>

					<main class="cb-core-design-shell__canvas cb-core-design-shell__canvas--composed">
						<header class="cb-core-design-shell__canvas-header">
							<div class="cb-core-design-shell__canvas-heading">
								<h2 class="cb-core-design-shell__canvas-title"><?php esc_html_e( 'Maintenance Report', 'core-blueprint' ); ?></h2>
							</div>
						</header>

						<div class="cb-core-design-shell__canvas-workarea">
							<div class="cb-core-design-shell__surface cb-core-design-shell__surface--document">
								<div class="cb-core-design-shell__empty-state" data-cb-report-preview-state>
									<?php esc_html_e( 'Loading…' ); ?>
								</div>
								<iframe
									data-cb-report-preview
									title="<?php esc_attr_e( 'Maintenance Report', 'core-blueprint' ); ?>"
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
						<div class="cb-core-design-shell__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Designer panels', 'core-blueprint' ); ?>">
							<button type="button" class="cb-core-design-shell__tab is-active" role="tab" aria-selected="true" data-cb-design-shell-tab="inspector" data-cb-design-shell-sidebar-role="inspector"><?php esc_html_e( 'Inspector', 'core-blueprint' ); ?></button>
							<button type="button" class="cb-core-design-shell__tab" role="tab" aria-selected="false" data-cb-design-shell-tab="layers" data-cb-design-shell-sidebar-role="layers"><?php esc_html_e( 'Layers', 'default' ); ?></button>
							<button type="button" class="cb-core-design-shell__tab" role="tab" aria-selected="false" data-cb-design-shell-tab="settings" data-cb-design-shell-sidebar-role="settings"><?php esc_html_e( 'Settings', 'core-blueprint' ); ?></button>
						</div>

						<div class="cb-core-design-shell__panel cb-core-design-shell__sidebar-panel" data-cb-design-shell-panel="inspector" data-cb-design-shell-sidebar-role="inspector">
							<div class="cb-core-design-shell__panel-body" data-cb-report-inspector></div>
						</div>

						<div class="cb-core-design-shell__panel cb-core-design-shell__sidebar-panel" data-cb-design-shell-panel="layers" data-cb-design-shell-sidebar-role="layers" hidden>
							<div class="cb-core-design-shell__panel-body" data-cb-report-layers></div>
						</div>

						<div class="cb-core-design-shell__panel cb-core-design-shell__sidebar-panel" data-cb-design-shell-panel="settings" data-cb-design-shell-sidebar-role="settings" hidden>
							<div class="cb-core-design-shell__panel-body">
								<section class="cb-core-design-shell__panel-section">
									<h3 class="cb-core-design-shell__panel-section-title"><?php esc_html_e( 'Appearance', 'core-blueprint' ); ?></h3>
									<p class="cb-core-design-shell__panel-section-description">
										<?php esc_html_e( 'Logo and accent colour are applied when a report PDF is viewed or downloaded. Changing them does not change the stored report content.', 'core-blueprint' ); ?>
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
										<span class="cb-core-design-shell__field-hint"><?php esc_html_e( 'Supported: SVG, PNG or JPEG up to 2 MB. For raster logos, use a clear square or landscape image up to 4096 x 4096 px. Shown in the top-left of every report.', 'core-blueprint' ); ?></span>
									</div>

									<label class="cb-core-design-shell__field">
										<span class="cb-core-design-shell__field-label"><?php esc_html_e( 'Accent colour', 'core-blueprint' ); ?></span>
										<input type="color" id="cb-core-accent-color" name="accent_color" value="<?php echo esc_attr( $accent_color ); ?>">
										<input type="text" id="cb-core-accent-hex" value="<?php echo esc_attr( $accent_color ); ?>" maxlength="7" pattern="^#[0-9a-fA-F]{6}$" aria-label="<?php esc_attr_e( 'Accent colour hex value', 'core-blueprint' ); ?>">
										<span class="cb-core-design-shell__field-hint">
											<?php
											printf(
												/* translators: %s: default colour hex code. */
												esc_html__( 'Used for headings, accents, and the rule under the report header. Default: %s.', 'core-blueprint' ),
												esc_html( (string) $fallback['accent_color'] )
											);
											?>
										</span>
									</label>
								</section>

								<section class="cb-core-design-shell__panel-section">
									<h3 class="cb-core-design-shell__panel-section-title"><?php esc_html_e( 'Report provider', 'core-blueprint' ); ?></h3>
									<p class="cb-core-design-shell__panel-section-description"><?php esc_html_e( 'Optional details about the person or organisation preparing the report. Leave both fields empty when the site is self-managed.', 'core-blueprint' ); ?></p>

									<label class="cb-core-design-shell__field">
										<span class="cb-core-design-shell__field-label"><?php esc_html_e( 'Name', 'core-blueprint' ); ?></span>
										<input type="text" id="cb-core-provider-name" name="provider_name" value="<?php echo esc_attr( $provider_name ); ?>" maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Infused', 'core-blueprint' ); ?>">
										<span class="cb-core-design-shell__field-hint"><?php esc_html_e( 'Shown as “Prepared by” in the report header when filled in.', 'core-blueprint' ); ?></span>
									</label>

									<label class="cb-core-design-shell__field">
										<span class="cb-core-design-shell__field-label"><?php esc_html_e( 'Contact', 'core-blueprint' ); ?></span>
										<input type="text" id="cb-core-provider-contact" name="provider_contact" value="<?php echo esc_attr( $provider_contact ); ?>" maxlength="200" placeholder="<?php esc_attr_e( 'e.g. support@yourwebsite.com', 'core-blueprint' ); ?>">
										<span class="cb-core-design-shell__field-hint"><?php esc_html_e( 'Optional single-line contact information for the report provider, such as email, phone, or address.', 'core-blueprint' ); ?></span>
									</label>
								</section>
							</div>
						</div>
					</aside>
				</div>
			</div>
		</form>
	<?php endif; ?>
</div>
