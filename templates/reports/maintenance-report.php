<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Reports → Maintenance Report tab
 *
 * Variables provided by Reports::render_maintenance_tab():
 *   - $can_manage   bool   - current user can cb_manage_reports
 *   - $pdf_ready    bool   - Renderer::is_available()
 *   - $page_slug    string - admin page slug
 *   - $months       array<string, array{start, end, label}> - last 12 months, newest first
 *   - $default      array{start, end} - default period (previous full month)
 *
 * Generation flow: "Generate report" persists an immutable report-data
 * snapshot. The PDF is rendered on demand when View or Download is requested
 * from Overview; no report PDF is stored permanently.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$overview_url = admin_url( 'admin.php?page=' . $page_slug );
?>
<div class="wrap cb-core-wrap cb-core-maintenance-report">

	<h1 class="cb-core-title"><?php esc_html_e( 'Maintenance Report', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Generate a Maintenance Report for a specific period. Core Blueprint stores the report data as an immutable snapshot. The PDF is rendered only when you view or download the report from Overview.', 'core-blueprint' ); ?>
	</p>

	<?php if ( ! $pdf_ready ) : ?>
		<?php
		echo \CB\Core\UI\Notice::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output.
			'variant' => \CB\Core\UI\Notice::WARNING,
			'title'   => __( 'PDF rendering is not available.', 'core-blueprint' ),
			'message' => __( 'You can still generate and store report snapshots, but View and Download require Dompdf and its PHP extensions to be available.', 'core-blueprint' ),
		] );
		?>
	<?php endif; ?>

	<?php if ( ! $can_manage ) : ?>
		<?php
		echo \CB\Core\UI\Notice::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output.
			'variant' => \CB\Core\UI\Notice::INFO,
			'message' => __( 'Maintenance Reports are managed by your Core Blueprint operator. You can download previously generated reports from the Overview tab.', 'core-blueprint' ),
		] );
		?>
	<?php else : ?>

		<section class="cb-core-mr-section cb-core-mr-generate-section">
			<h2><?php esc_html_e( 'Generate report', 'core-blueprint' ); ?></h2>

			<form
				id="cb-core-generate-maintenance-form"
				class="cb-core-form cb-core-mr-form"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'cb_core_admin' ) ); ?>"
				data-overview-url="<?php echo esc_attr( $overview_url ); ?>"
			>
				<div class="cb-core-field">
					<label for="cb-core-mr-period" class="cb-core-field__label">
						<?php esc_html_e( 'Period', 'core-blueprint' ); ?>
					</label>
					<div class="cb-core-field__control">
						<select id="cb-core-mr-period" name="period_preset" class="regular-text">
						<?php
						$default_key = substr( $default['start'], 0, 7 );
						foreach ( $months as $key => $month ) :
							$selected = selected( $key, $default_key, false );
							?>
							<option value="<?php echo esc_attr( $key ); ?>"
								data-start="<?php echo esc_attr( $month['start'] ); ?>"
								data-end="<?php echo esc_attr( $month['end'] ); ?>"
								<?php echo $selected; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<?php echo esc_html( $month['label'] ); ?>
								(<?php echo esc_html( $month['start'] ); ?> - <?php echo esc_html( $month['end'] ); ?>)
							</option>
						<?php endforeach; ?>
						<option value="custom"><?php esc_html_e( 'Custom period…', 'core-blueprint' ); ?></option>
						</select>
					</div>
					<p class="description cb-core-field__hint">
						<?php esc_html_e( 'Select a calendar month or choose "Custom period" for a custom range.', 'core-blueprint' ); ?>
					</p>
				</div>

				<div class="cb-core-field cb-core-mr-custom-row" hidden>
					<span class="cb-core-field__label">
						<?php esc_html_e( 'Custom period', 'core-blueprint' ); ?>
					</span>
					<div class="cb-core-field__control cb-core-mr-date-range">
						<label for="cb-core-mr-start" class="screen-reader-text">
							<?php esc_html_e( 'Start date', 'core-blueprint' ); ?>
						</label>
						<input
							type="date"
							id="cb-core-mr-start"
							name="period_start"
							class="regular-text"
							value="<?php echo esc_attr( $default['start'] ); ?>"
						>
						<span class="cb-core-mr-date-sep" aria-hidden="true"><?php esc_html_e( 'to', 'core-blueprint' ); ?></span>
						<label for="cb-core-mr-end" class="screen-reader-text">
							<?php esc_html_e( 'End date', 'core-blueprint' ); ?>
						</label>
						<input
							type="date"
							id="cb-core-mr-end"
							name="period_end"
							class="regular-text"
							value="<?php echo esc_attr( $default['end'] ); ?>"
						>
					</div>
				</div>

				<div class="cb-core-actions">
					<button
						type="submit"
						class="button button-primary cb-core-button cb-core-button--primary"
						id="cb-core-mr-generate"
					>
						<?php esc_html_e( 'Generate report', 'core-blueprint' ); ?>
					</button>

					<?php
					// The "Generate & Send" affordance is hidden until v1.2 ships
					// the email-delivery feature it relies on. Keeping it out of
					// the DOM rather than disabled-with-tooltip avoids dead-state
					// clutter on every page load.
					?>

					<?php echo \CB\Core\UI\FormStatus::render( [ 'id' => 'cb-core-mr-status' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - helper escapes own output ?>
				</div>
			</form>
		</section>

		<section class="cb-core-mr-section cb-core-mr-info-section">
			<h2><?php esc_html_e( 'What is in the report', 'core-blueprint' ); ?></h2>

			<p>
				<?php esc_html_e( 'A Maintenance Report is a snapshot of what happened on this site during the selected period. The PDF is structured for clients and management who want a clear summary without digging through the audit log themselves.', 'core-blueprint' ); ?>
			</p>

			<ul class="cb-core-mr-info-list">
				<li>
					<strong><?php esc_html_e( 'KPI overview', 'core-blueprint' ); ?></strong>
					<?php esc_html_e( 'Headline numbers for the period: total updates installed, security events, login attempts, and notable activity counts.', 'core-blueprint' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Theme and plugin updates', 'core-blueprint' ); ?></strong>
					<?php esc_html_e( 'Detail table of every theme and plugin update applied in the period, including version transitions and timestamps.', 'core-blueprint' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'WordPress core updates', 'core-blueprint' ); ?></strong>
					<?php esc_html_e( 'Core version transitions and minor security patches applied during the period.', 'core-blueprint' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Security events', 'core-blueprint' ); ?></strong>
					<?php esc_html_e( 'Notable security activity logged in the period: failed login attempts, scanner findings, and policy changes.', 'core-blueprint' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Report branding', 'core-blueprint' ); ?></strong>
					<?php esc_html_e( 'The current report appearance and optional provider details are applied when the PDF is rendered. The stored report content itself never changes.', 'core-blueprint' ); ?>
				</li>
			</ul>
		</section>

	<?php endif; ?>

</div>
