<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables execute in local include scope and are not plugin globals.
/**
 * Reports → Overview tab
 *
 * Variables provided by Reports::render_overview_tab():
 *   - $available_types   array<string, array{label,description,tab}>
 *   - $recent_reports    array - output of Storage::find_recent()
 *   - $page_slug         string - admin page slug for tab links
 *
 * The "Delete all reports" flow opens a type-to-confirm modal via
 * cbCore.modal.show() in JS - no inline <dialog> markup is required.
 * Server-side enforces the same typed phrase regardless of the
 * client-side gate.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap cb-core-wrap cb-core-reports">

	<h1 class="cb-core-title"><?php esc_html_e( 'Overview', 'core-blueprint' ); ?></h1>

	<p class="cb-core-intro">
		<?php esc_html_e( 'Generate and download PDF reports for this site. Available reports are listed below.', 'core-blueprint' ); ?>
	</p>

	<section class="cb-core-reports-section cb-core-reports-types-section">
		<h2><?php esc_html_e( 'Available reports', 'core-blueprint' ); ?></h2>

		<table class="widefat striped cb-core-report-types">
			<colgroup>
				<col />
				<col />
				<col class="cb-core-col-icon" />
			</colgroup>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Report', 'core-blueprint' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'core-blueprint' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $available_types as $type_slug => $type ) : ?>
					<?php
					$tab_target = isset( $type['tab'] ) ? (string) $type['tab'] : '';
					$tab_url    = admin_url( 'admin.php?page=' . $page_slug . '&tab=' . $tab_target );
					?>
					<tr>
						<td><strong><?php echo esc_html( (string) ( $type['label'] ?? $type_slug ) ); ?></strong></td>
						<td><?php echo esc_html( (string) ( $type['description'] ?? '' ) ); ?></td>
						<td>
							<?php if ( '' !== $tab_target ) : ?>
								<a href="<?php echo esc_url( $tab_url ); ?>" class="button cb-core-button cb-core-button--secondary cb-core-button--compact">
									<?php esc_html_e( 'Go to report →', 'core-blueprint' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="cb-core-reports-section cb-core-reports-recent-section">
		<h2><?php esc_html_e( 'Recently generated', 'core-blueprint' ); ?></h2>

		<?php if ( empty( $recent_reports ) ) : ?>
			<p class="description">
				<em><?php esc_html_e( 'No reports generated yet on this site.', 'core-blueprint' ); ?></em>
			</p>
		<?php else : ?>
			<?php $can_delete_reports = current_user_can( 'cb_manage_reports' ); ?>
			<table class="widefat striped cb-core-recent-reports">
				<colgroup>
					<col />
					<col />
					<col />
					<col />
					<col class="cb-core-col-actions" />
			</colgroup>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Period', 'core-blueprint' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Generated', 'core-blueprint' ); ?></th>
					<th scope="col"><?php esc_html_e( 'By', 'core-blueprint' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'core-blueprint' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></th>
				</tr>
			</thead>
			<tbody data-cb-core-reports-recent
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'cb_core_admin' ) ); ?>">
				<?php foreach ( $recent_reports as $report ) : ?>
					<?php
					$report_id    = (int) ( $report['id'] ?? 0 );
					$status       = (string) ( $report['status'] ?? '-' );
					$generated_by = (int) ( $report['generated_by'] ?? 0 );
					$user         = $generated_by > 0 ? get_userdata( $generated_by ) : null;
					$user_label   = $user instanceof \WP_User ? $user->user_login : '-';
					$can_download = 'generated' === $status;
					$download_url = $can_download
						? add_query_arg(
							[
								'action'       => 'cb_core_download_maintenance_report',
								'id'           => $report_id,
								'_cb_dl_nonce' => wp_create_nonce( 'cb_core_download_report_' . $report_id ),
							],
							admin_url( 'admin-ajax.php' )
						)
						: '';
					$view_url = $can_download
						? add_query_arg( [ 'disposition' => 'inline' ], $download_url )
						: '';
					$generated_at_raw = (string) ( $report['generated_at'] ?? '' );
					$generated_at_fmt = '' !== $generated_at_raw
						? get_date_from_gmt( $generated_at_raw, 'd-m-Y H:i' )
						: '-';

					// Storage status → semantic Status helper variant.
					// Storage uses descriptive states; Status::render uses
					// outcome-flavoured variants. Mapping here keeps callers
					// of Status::render() outcome-focused.
					$status_variant = 'generated' === $status ? 'active' : 'idle';
					?>
					<tr data-report-id="<?php echo esc_attr( (string) $report_id ); ?>">
						<td>
							<?php echo esc_html( (string) ( $report['period_start'] ?? '-' ) ); ?>
							&nbsp;t/m&nbsp;
							<?php echo esc_html( (string) ( $report['period_end'] ?? '-' ) ); ?>
						</td>
						<td><?php echo esc_html( $generated_at_fmt ); ?></td>
						<td><?php echo esc_html( $user_label ); ?></td>
						<td>
							<?php
							echo \CoreBlueprint\Core\UI\Status::render( $status_variant, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped - Status::render() returns escape-clean HTML.
							?>
						</td>
						<td>
							<?php
							$actions = [];
							if ( $can_download ) {
								$actions['view'] = sprintf(
									'<a href="%s" target="_blank" rel="noopener" class="cb-core-row-action">%s</a>',
									esc_url( $view_url ),
									esc_html__( 'View', 'core-blueprint' )
								);
								$actions['download'] = sprintf(
									'<a href="%s" class="cb-core-row-action">%s</a>',
									esc_url( $download_url ),
									esc_html__( 'Download', 'core-blueprint' )
								);
							}
							if ( $can_delete_reports ) {
								$actions['delete'] = sprintf(
									'<button type="button" class="cb-core-row-action cb-core-row-action--danger cb-core-report-delete" data-report-id="%s">%s</button>',
									esc_attr( (string) $report_id ),
									esc_html__( 'Delete', 'core-blueprint' )
								);
							}

							if ( ! empty( $actions ) ) {
								$actions_html = '<span class="cb-core-row-actions">' . implode( ' <span class="cb-core-row-actions__sep" aria-hidden="true">|</span> ', $actions ) . '</span>';
								echo wp_kses(
									$actions_html,
									[
										'span'   => [ 'class' => true, 'aria-hidden' => true ],
										'a'      => [ 'href' => true, 'target' => true, 'rel' => true, 'class' => true ],
										'button' => [ 'type' => true, 'class' => true, 'data-report-id' => true ],
									]
								);
							} else {
								echo '<span class="description">-</span>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</section>

	<?php if ( ! empty( $recent_reports ) && current_user_can( 'cb_manage_reports' ) ) : ?>
		<section class="cb-core-reports-cleanup-section">
			<h2><?php esc_html_e( 'Cleanup', 'core-blueprint' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Remove all stored reports in one go. Useful when the archive has accumulated test runs or when you are preparing a clean handover. Type-to-confirm gate prevents accidental clicks.', 'core-blueprint' ); ?>
			</p>
			<p class="cb-core-actions">
				<button type="button"
					class="button cb-core-button cb-core-button--danger cb-core-reports-delete-all"
					data-cb-core-delete-all-trigger>
					<?php esc_html_e( 'Delete all reports…', 'core-blueprint' ); ?>
				</button>
			</p>
		</section>
	<?php endif; ?>

</div>
