<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Template: Privacy & Logging.
 *
 * Rendered by \CB\Core\Admin\Pages\Privacy::render(). Expects the following
 * variables to be in scope:
 *
 *   $current_ip_mode          string
 *   $current_verbosity        array<string,string>
 *   $current_retention        array<string,int>
 *   $active_preset            string (slug or 'custom')
 *   $preset_actually_matches  bool
 *   $preset_definitions       array (from \CB\Core\Privacy\Presets::definitions())
 *   $verbosity_categories     array<string,string>  category → label
 *   $retention_categories     array<string,string>  category → label
 *   $retention_options        array<int,string>     days → label
 *   $estimate_kb_per_year     int
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap cb-core-wrap cb-core-privacy-page">
	<h1 class="cb-core-title"><?php esc_html_e( 'Privacy', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro cb-core-privacy-intro">
		<?php esc_html_e( 'Control what gets logged, how long it is kept, and how personal data is handled. Presets give you a starting point - every setting below stays individually adjustable.', 'core-blueprint' ); ?>
	</p>

	<form id="cb-core-privacy-form" class="cb-core-privacy-form">
		<?php wp_nonce_field( 'cb_core_admin', 'cb_core_privacy_nonce' ); ?>

		<!-- ─── Preset selector ──────────────────────────────────────── -->

		<section class="cb-core-preferences-section cb-core-privacy-presets">
			<h2><?php esc_html_e( 'Governance preset', 'core-blueprint' ); ?></h2>

			<div class="cb-core-radio-grid cb-core-radio-grid--columns-4">
				<?php foreach ( $preset_definitions as $slug => $def ) : ?>
					<?php
					echo \CB\Core\UI\RadioCard::render( [
						'name'    => 'preset',
						'value'   => $slug,
						'label'   => $def['label'],
						'desc'    => $def['description'],
						'checked' => $active_preset === $slug,
						'active'  => $active_preset === $slug && $preset_actually_matches,
					] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
					?>
				<?php endforeach; ?>
			</div>

			<?php if ( 'custom' === $active_preset || ! $preset_actually_matches ) : ?>
				<?php
				$custom_message = __( 'Current configuration: Custom.', 'core-blueprint' );
				if ( 'custom' !== $active_preset ) {
					$ref = $preset_definitions[ $active_preset ]['label'] ?? $active_preset;
					$custom_message = sprintf(
						/* translators: %s: preset name */
						__( 'Current configuration: Custom (based on %s). Click "Apply preset" to reset.', 'core-blueprint' ),
						$ref
					);
				}
				echo \CB\Core\UI\Notice::render( [
					'variant' => \CB\Core\UI\Notice::INFO,
					'message' => $custom_message,
					'class'   => 'cb-core-privacy-custom-notice',
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
				?>
			<?php endif; ?>

			<?php
			echo \CB\Core\UI\Notice::render( [
				'variant' => \CB\Core\UI\Notice::INFO,
				'message' => __( 'Presets are starting points. Core Blueprint does not replace a full AVG/GDPR processor agreement. For organizations handling special-category data, consult a data protection officer.', 'core-blueprint' ),
				'class'   => 'cb-core-privacy-guidance',
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
			?>

			<button type="button" class="button button-primary cb-core-button cb-core-button--primary cb-core-privacy-apply-preset">
				<?php esc_html_e( 'Apply preset', 'core-blueprint' ); ?>
			</button>
		</section>

		<!-- ─── IP Anonymization ─────────────────────────────────────── -->

		<section class="cb-core-preferences-section cb-core-privacy-ip">
			<h2><?php esc_html_e( 'IP address handling', 'core-blueprint' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'How IP addresses from visitors and admin actions are stored in the audit log.', 'core-blueprint' ); ?>
			</p>

			<?php
			echo \CB\Core\UI\RadioGroup::render( [
				'name'    => 'ip_mode',
				'value'   => $current_ip_mode,
				'layout'  => \CB\Core\UI\RadioGroup::LAYOUT_GRID,
				'columns' => 3,
				'options' => [
					[
						'value' => 'anonymized',
						'label' => __( 'Anonymized', 'core-blueprint' ),
						'desc'  => __( 'Last octet zeroed (e.g. 77.174.6.0). AVG-safe default.', 'core-blueprint' ),
					],
					[
						'value' => 'full',
						'label' => __( 'Full IP', 'core-blueprint' ),
						'desc'  => __( 'Complete address stored. Useful for forensics - requires AVG processor agreement.', 'core-blueprint' ),
					],
					[
						'value' => 'none',
						'label' => __( 'None', 'core-blueprint' ),
						'desc'  => __( 'No IP stored at all. Maximum privacy; loses forensic value.', 'core-blueprint' ),
					],
				],
			] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output
			?>
		</section>

		<!-- ─── Verbosity per category ───────────────────────────────── -->

		<section class="cb-core-preferences-section cb-core-privacy-verbosity">
			<h2><?php esc_html_e( 'What gets logged', 'core-blueprint' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Per category of events - control how much detail is captured.', 'core-blueprint' ); ?>
			</p>

			<table class="widefat striped cb-core-privacy-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Category', 'core-blueprint' ); ?></th>
						<th><?php esc_html_e( 'Verbosity', 'core-blueprint' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $verbosity_categories as $cat => $label ) : ?>
						<?php $current = $current_verbosity[ $cat ] ?? 'always'; ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td>
								<select name="verbosity[<?php echo esc_attr( $cat ); ?>]">
									<option value="always"        <?php selected( $current, 'always' ); ?>><?php esc_html_e( 'Always log',    'core-blueprint' ); ?></option>
									<option value="admins_only"   <?php selected( $current, 'admins_only' ); ?>><?php esc_html_e( 'Admins only',   'core-blueprint' ); ?></option>
									<option value="critical_only" <?php selected( $current, 'critical_only' ); ?>><?php esc_html_e( 'Critical only', 'core-blueprint' ); ?></option>
									<option value="disabled"      <?php selected( $current, 'disabled' ); ?>><?php esc_html_e( 'Disabled',      'core-blueprint' ); ?></option>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>

		<!-- ─── Retention per category ───────────────────────────────── -->

		<section class="cb-core-preferences-section cb-core-privacy-retention">
			<h2><?php esc_html_e( 'Retention', 'core-blueprint' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'How long each category stays in the audit log before automatic pruning.', 'core-blueprint' ); ?>
			</p>

			<table class="widefat striped cb-core-privacy-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Category', 'core-blueprint' ); ?></th>
						<th><?php esc_html_e( 'Retention', 'core-blueprint' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $retention_categories as $cat => $label ) : ?>
						<?php $current = (int) ( $current_retention[ $cat ] ?? 365 ); ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td>
								<select name="retention[<?php echo esc_attr( $cat ); ?>]">
									<?php foreach ( $retention_options as $days => $days_label ) : ?>
										<option value="<?php echo (int) $days; ?>" <?php selected( $current, $days ); ?>><?php echo esc_html( $days_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>

		<!-- ─── Storage estimator ────────────────────────────────────── -->

		<section class="cb-core-preferences-section cb-core-privacy-estimate">
			<h2><?php esc_html_e( 'Estimated storage', 'core-blueprint' ); ?></h2>

			<p class="cb-core-privacy-estimate-value">
				<strong id="cb-core-privacy-estimate-kb" data-kb="<?php echo esc_attr( (string) $estimate_kb_per_year ); ?>">
					<?php echo esc_html( number_format_i18n( $estimate_kb_per_year / 1024, 1 ) ); ?>
				</strong>
				<?php esc_html_e( 'MB per year', 'core-blueprint' ); ?>
			</p>

			<p class="description">
				<?php esc_html_e( 'Rough projection based on typical event rates. Actual usage depends on how active your site is.', 'core-blueprint' ); ?>
			</p>
		</section>

		<!-- ─── Save ─────────────────────────────────────────────────── -->

		<?php // Page-level save action - applies to all panels above. ?>
		<div class="cb-core-actions cb-core-privacy-actions">
			<button type="button" class="button button-primary cb-core-button cb-core-button--primary cb-core-privacy-save">
				<?php esc_html_e( 'Save changes', 'core-blueprint' ); ?>
			</button>
			<?php echo \CB\Core\UI\FormStatus::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
		</div>
	</form>
</div>
