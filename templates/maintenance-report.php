<?php
/**
 * Core Blueprint - Maintenance Report template.
 *
 * Client-facing combined audit view. Merges System Log (local maintenance)
 * with Connection Log (remote actions via Beacon) into one transparent
 * report of all maintenance activity.
 *
 * Available variables (set by \CB\Core\Admin\Admin::render_maintenance_report):
 *   $mr_result  - output of \CB\Core\Log\MaintenanceReport::query()
 *   $mr_args    - original filter arguments
 *   $mr_summary - 30-day category counts
 *   $mr_actors  - array of distinct user_login strings
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$rows        = $mr_result['rows']     ?? [];
$total       = (int) ( $mr_result['total']    ?? 0 );
$current     = (int) ( $mr_result['page']     ?? 1 );
$per_page    = (int) ( $mr_result['per_page'] ?? 50 );
$total_pages = (int) ceil( $total / max( 1, $per_page ) );

$current_actor    = $mr_args['actor']    ?? '';
$current_category = $mr_args['category'] ?? '';
$current_source   = $mr_args['source']   ?? '';
$current_period   = $current_period      ?? 'all';

$summary_total  = (int) ( $mr_summary['_total']  ?? 0 );
$summary_local  = (int) ( $mr_summary['_local']  ?? 0 );
$summary_remote = (int) ( $mr_summary['_remote'] ?? 0 );

// Pick 4 most-active categories for the summary tiles.
$category_counts = [];
foreach ( \CB\Core\Log\MaintenanceReport::CATEGORIES as $slug => $label ) {
	$category_counts[ $slug ] = (int) ( $mr_summary[ $slug ] ?? 0 );
}
arsort( $category_counts );
$top_categories = array_slice( $category_counts, 0, 4, true );

// Resolve description mode once - controls per-row rendering.
// current_mode() is guaranteed to return 'plain' or 'technical'.
$cb_mode = class_exists( '\CB\Core\UI' ) ? \CB\Core\UI::current_mode() : 'technical';
?>
<div class="wrap cb-core-wrap cb-core-maintenance-report cb-core-logs-page" data-cb-mode="<?php echo esc_attr( $cb_mode ); ?>">

	<h1 class="cb-core-title"><?php esc_html_e( 'Maintenance Log', 'core-blueprint' ); ?></h1>

	<?php
	$mr_description = class_exists( \CB\Core\Log\Language::class )
		? \CB\Core\Log\Language::describe_log( 'maintenance', $cb_mode )
		: '';
	$mr_export_formats = class_exists( \CB\Core\Log\LogExporter::class )
		? array_values( \CB\Core\Log\LogExporter::formats() )
		: [ __( 'CSV', 'core-blueprint' ) ];
	?>

	<?php if ( '' !== $mr_description ) : ?>
		<p class="cb-core-intro cb-core-log-description">
			<?php echo esc_html( $mr_description ); ?>
		</p>
	<?php endif; ?>

	<ul class="cb-core-meta">
		<li class="cb-core-meta__item">
			<?php esc_html_e( 'Read-only - no changes can be made here', 'core-blueprint' ); ?>
		</li>
		<li class="cb-core-meta__item">
			<?php esc_html_e( 'Visible to administrators only', 'core-blueprint' ); ?>
		</li>
		<li class="cb-core-meta__item">
			<?php
			printf(
				/* translators: %s: comma-separated list of export format names */
				esc_html__( 'Exportable as %s', 'core-blueprint' ),
				esc_html( implode( ', ', $mr_export_formats ) )
			);
			?>
		</li>
	</ul>

	<!-- ─── KPI strip (last 30 days) ─────────────────────────────────── -->

	<section class="cb-core-section cb-core-mr-kpi-section">
		<h2 class="cb-core-section-title"><?php esc_html_e( 'Last 30 days', 'core-blueprint' ); ?></h2>

		<div class="cb-core-mr-kpi-tiles">

			<?php
			$lb = $mr_snapshot['last_backup'] ?? [];
			$lu = $mr_snapshot['last_update'] ?? [];
			?>

			<div class="cb-core-tile cb-core-tile--metric <?php echo esc_attr( \CB\Core\UI\Tile::state_class( $lb['state'] ?? 'unknown' ) ); ?>">
				<span class="cb-core-tile__label"><?php esc_html_e( 'Last backup', 'core-blueprint' ); ?></span>
				<span class="cb-core-tile__value">
					<?php
					if ( 'unknown' === ( $lb['state'] ?? 'unknown' ) ) {
						esc_html_e( 'No data yet', 'core-blueprint' );
					} else {
						echo esc_html( \CB\Core\Log\MaintenanceReport::format_age( $lb['age_seconds'] ?? null ) );
					}
					?>
				</span>
				<span class="cb-core-tile__state">
					<?php
					switch ( $lb['state'] ?? 'unknown' ) {
						case 'ok':      esc_html_e( 'On schedule',         'core-blueprint' ); break;
						case 'warn':    esc_html_e( 'Due soon',            'core-blueprint' ); break;
						case 'overdue': esc_html_e( 'Overdue',             'core-blueprint' ); break;
						case 'unknown': esc_html_e( 'Tracking in progress','core-blueprint' ); break;
					}
					?>
				</span>
			</div>

			<div class="cb-core-tile cb-core-tile--metric <?php echo esc_attr( \CB\Core\UI\Tile::state_class( $lu['state'] ?? 'unknown' ) ); ?>">
				<span class="cb-core-tile__label"><?php esc_html_e( 'Last update', 'core-blueprint' ); ?></span>
				<span class="cb-core-tile__value">
					<?php
					if ( 'unknown' === ( $lu['state'] ?? 'unknown' ) ) {
						esc_html_e( 'No data yet', 'core-blueprint' );
					} else {
						echo esc_html( \CB\Core\Log\MaintenanceReport::format_age( $lu['age_seconds'] ?? null ) );
					}
					?>
				</span>
				<span class="cb-core-tile__state">
					<?php
					switch ( $lu['state'] ?? 'unknown' ) {
						case 'ok':      esc_html_e( 'On schedule',         'core-blueprint' ); break;
						case 'warn':    esc_html_e( 'Due soon',            'core-blueprint' ); break;
						case 'overdue': esc_html_e( 'Overdue',             'core-blueprint' ); break;
						case 'unknown': esc_html_e( 'Tracking in progress','core-blueprint' ); break;
					}
					?>
				</span>
			</div>

			<div class="cb-core-tile cb-core-tile--metric cb-core-tile--neutral">
				<span class="cb-core-tile__label"><?php esc_html_e( 'Events this period', 'core-blueprint' ); ?></span>
				<span class="cb-core-tile__value"><?php echo esc_html( number_format_i18n( (int) $mr_snapshot['total_events'] ) ); ?></span>
				<?php if ( null !== $mr_snapshot['trend_pct'] ) : ?>
					<span class="cb-core-tile__state cb-core-mr-kpi-trend cb-core-mr-kpi-trend--<?php echo $mr_snapshot['trend_pct'] >= 0 ? 'up' : 'down'; ?>">
						<?php echo $mr_snapshot['trend_pct'] >= 0 ? '↑' : '↓'; ?>
						<?php echo esc_html( sprintf( '%d%%', abs( (int) $mr_snapshot['trend_pct'] ) ) ); ?>
						<?php esc_html_e( 'vs previous', 'core-blueprint' ); ?>
					</span>
				<?php else : ?>
					<span class="cb-core-tile__state"><?php esc_html_e( 'No prior data', 'core-blueprint' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="cb-core-tile cb-core-tile--metric cb-core-tile--neutral">
				<span class="cb-core-tile__label"><?php esc_html_e( 'Active users', 'core-blueprint' ); ?></span>
				<span class="cb-core-tile__value"><?php echo esc_html( number_format_i18n( (int) $mr_snapshot['active_users']['count'] ) ); ?></span>
				<span class="cb-core-tile__state">
					<?php
					$users = $mr_snapshot['active_users']['list'] ?? [];
					if ( ! empty( $users ) ) {
						$preview = array_slice( $users, 0, 2 );
						$display = implode( ', ', $preview );
						if ( count( $users ) > 2 ) {
							/* translators: %d: number of additional active users not shown in the preview. */
							$display .= sprintf( __( ' +%d more', 'core-blueprint' ), count( $users ) - 2 );
						}
						echo esc_html( $display );
					} else {
						esc_html_e( 'No activity', 'core-blueprint' );
					}
					?>
				</span>
			</div>

			<div class="cb-core-tile cb-core-tile--metric cb-core-tile--neutral">
				<span class="cb-core-tile__label"><?php esc_html_e( 'Top category', 'core-blueprint' ); ?></span>
				<span class="cb-core-tile__value">
					<?php echo esc_html( $mr_snapshot['top_category']['label'] ?: '-' ); ?>
				</span>
				<span class="cb-core-tile__state">
					<?php
					$tc = (int) $mr_snapshot['top_category']['count'];
					if ( $tc > 0 ) {
						printf(
							/* translators: %s: number of events */
							esc_html( _n( '%s event', '%s events', $tc, 'core-blueprint' ) ),
							esc_html( number_format_i18n( $tc ) )
						);
					} else {
						esc_html_e( 'No activity', 'core-blueprint' );
					}
					?>
				</span>
			</div>

			<div class="cb-core-tile cb-core-tile--metric <?php echo esc_attr( \CB\Core\UI\Tile::state_class( $mr_snapshot['sla_status'] ) ); ?>">
				<span class="cb-core-tile__label"><?php esc_html_e( 'Maintenance status', 'core-blueprint' ); ?></span>
				<span class="cb-core-tile__value">
					<?php
					switch ( $mr_snapshot['sla_status'] ) {
						case 'ok':      esc_html_e( 'All good',          'core-blueprint' ); break;
						case 'warn':    esc_html_e( 'Check soon',        'core-blueprint' ); break;
						case 'overdue': esc_html_e( 'Needs attention',   'core-blueprint' ); break;
						case 'unknown': esc_html_e( 'No data yet',       'core-blueprint' ); break;
					}
					?>
				</span>
				<span class="cb-core-tile__state">
					<?php
					if ( 'unknown' === $mr_snapshot['sla_status'] ) {
						esc_html_e( 'Tracking in progress', 'core-blueprint' );
					} else {
						esc_html_e( 'Biweekly tracking', 'core-blueprint' );
					}
					?>
				</span>
			</div>

		</div>

		<?php
		$daily = $mr_snapshot['daily_counts'] ?? [];
		if ( ! empty( $daily ) && class_exists( '\CB\Core\Log\Chart' ) ) {
			echo \CB\Core\Log\Chart::render_activity( $daily, __( 'Activity', 'core-blueprint' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
	</section>

	<!-- ─── Filter toolbar with Plain/Technical/Sync toggle ─────── -->

	<section class="cb-core-section cb-core-log-filters-wrap">
		<form method="get" class="cb-core-toolbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( \CB\Core\Admin\Admin::LOGS_SLUG ); ?>" />
			<input type="hidden" name="tab"  value="maintenance" />

			<div class="cb-core-toolbar__field">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'Mode', 'core-blueprint' ); ?></span>
				<?php \CB\Core\UI::render_mode_switcher(); ?>
			</div>

			<label class="cb-core-toolbar__field">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'User', 'core-blueprint' ); ?></span>
				<select name="actor">
					<option value="" <?php selected( $current_actor, '' ); ?>><?php esc_html_e( 'All users', 'core-blueprint' ); ?></option>
					<?php foreach ( $mr_actors as $actor_login ) : ?>
						<option value="<?php echo esc_attr( $actor_login ); ?>" <?php selected( $current_actor, $actor_login ); ?>>
							<?php echo esc_html( $actor_login ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="cb-core-toolbar__field">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'Category', 'core-blueprint' ); ?></span>
				<select name="category">
					<option value="" <?php selected( $current_category, '' ); ?>><?php esc_html_e( 'All categories', 'core-blueprint' ); ?></option>
					<?php foreach ( \CB\Core\Log\MaintenanceReport::CATEGORIES as $slug => $_ ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_category, $slug ); ?>>
							<?php echo esc_html( \CB\Core\Log\MaintenanceReport::category_label( $slug ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="cb-core-toolbar__field">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'Source', 'core-blueprint' ); ?></span>
				<select name="source">
					<option value=""       <?php selected( $current_source, '' );       ?>><?php esc_html_e( 'All sources', 'core-blueprint' ); ?></option>
					<option value="local"  <?php selected( $current_source, 'local' );  ?>><?php echo esc_html( \CB\Core\Log\MaintenanceReport::source_label( 'local' ) ); ?></option>
					<option value="remote" <?php selected( $current_source, 'remote' ); ?>><?php echo esc_html( \CB\Core\Log\MaintenanceReport::source_label( 'remote' ) ); ?></option>
				</select>
			</label>

			<label class="cb-core-toolbar__field">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'Period', 'core-blueprint' ); ?></span>
				<select name="period">
					<?php foreach ( \CB\Core\Log\TimeFilter::PRESETS as $slug => $_label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_period, $slug ); ?>>
							<?php echo esc_html( \CB\Core\Log\TimeFilter::label( $slug ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<?php
			$export_formats_mr = class_exists( \CB\Core\Log\LogExporter::class )
				? \CB\Core\Log\LogExporter::formats()
				: [ 'csv' => __( 'CSV', 'core-blueprint' ) ];
			?>
			<div class="cb-core-toolbar__actions">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></span>
				<div class="cb-core-toolbar__actions-row">
					<button type="submit" class="button"><?php esc_html_e( 'Apply filters', 'core-blueprint' ); ?></button>
					<?php if ( $current_actor || $current_category || $current_source || ( $current_period && 'all' !== $current_period ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \CB\Core\Admin\Admin::LOGS_SLUG . '&tab=maintenance' ) ); ?>" class="button">
							<?php esc_html_e( 'Clear', 'core-blueprint' ); ?>
						</a>
					<?php endif; ?>
					<label class="screen-reader-text" for="maintenance-report-export-format">
						<?php esc_html_e( 'Export format', 'core-blueprint' ); ?>
					</label>
					<select id="maintenance-report-export-format" class="cb-core-export-format">
						<?php foreach ( $export_formats_mr as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button cb-core-export-maintenance-report">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Export', 'core-blueprint' ); ?>
					</button>
				</div>
			</div>
		</form>
	</section>

	<!-- ─── Result count ─────────────────────────────────────────────── -->

	<?php
	$filters_active = ( $current_actor || $current_category || $current_source || ( $current_period && 'all' !== $current_period ) );
	// When a filter is applied, compute the unfiltered total as well so we
	// can show "X of Y events". Uses a lightweight second query with no
	// filter args.
	$unfiltered_total = $total;
	if ( $filters_active ) {
		$unfiltered_result = \CB\Core\Log\MaintenanceReport::query( [ 'per_page' => 1 ] );
		$unfiltered_total  = (int) ( $unfiltered_result['total'] ?? $total );
	}
	?>
	<p class="cb-core-mr-result-count">
		<?php if ( $filters_active && $unfiltered_total !== $total ) : ?>
			<?php
			printf(
				/* translators: 1: filtered count, 2: total count */
				esc_html__( '%1$s of %2$s events match current filters', 'core-blueprint' ),
				'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>',
				esc_html( number_format_i18n( $unfiltered_total ) )
			);
			?>
		<?php else : ?>
			<?php
			printf(
				/* translators: %s: event count */
				esc_html( _n( '%s event', '%s events', $total, 'core-blueprint' ) ),
				'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
			);
			?>
		<?php endif; ?>
	</p>

	<!-- ─── Events table ─────────────────────────────────────────────── -->

	<?php if ( empty( $rows ) ) : ?>
		<div class="cb-core-empty">
			<?php esc_html_e( 'No maintenance activity matches the current filters.', 'core-blueprint' ); ?>
		</div>
	<?php else : ?>
		<div class="cb-core-log-table-scroll">
			<table class="widefat striped cb-core-log-table cb-core-log-table--maintenance">
				<thead>
					<tr>
						<th scope="col" class="cb-core-log-col-time"><?php esc_html_e( 'Time', 'core-blueprint' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What happened', 'core-blueprint' ); ?></th>
						<th scope="col" class="cb-core-log-col-user"><?php esc_html_e( 'User', 'core-blueprint' ); ?></th>
						<th scope="col" class="cb-core-log-col-category"><?php esc_html_e( 'Category', 'core-blueprint' ); ?></th>
						<th scope="col" class="cb-core-log-col-source"><?php esc_html_e( 'Source', 'core-blueprint' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) :
						$ts = $row['timestamp'] ?? '';
						// Mode-dependent description - older rows fall back to description.
						$desc_plain     = (string) ( $row['description_plain'] ?? $row['description'] ?? '' );
						$desc_technical = (string) ( $row['description_technical'] ?? $row['description'] ?? '' );
						$desc           = 'plain' === $cb_mode ? $desc_plain : $desc_technical;
						$actor          = $row['user_login'] ?? '';
						$actor_role     = $row['actor_role'] ?? '';
						$category       = $row['category'] ?? 'other';
						$source         = $row['source'] ?? '';
					?>
						<tr>
							<td class="cb-core-log-time">
								<time datetime="<?php echo esc_attr( $ts ); ?>" title="<?php echo esc_attr( $ts ); ?>">
									<?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $ts ) ); ?>
								</time>
							</td>
							<td><span class="cb-core-mr-description" title="<?php echo esc_attr( $desc_technical ); ?>"><?php echo esc_html( $desc ); ?></span></td>
							<td>
								<?php if ( $actor ) : ?>
									<span class="cb-core-actor-pill cb-core-actor-user"><?php echo esc_html( $actor ); ?></span>
									<?php if ( $actor_role ) : ?> <span class="cb-core-muted cb-core-maintenance-meta"><?php echo esc_html( $actor_role ); ?></span><?php endif; ?>
								<?php else : ?>
									<span class="cb-core-actor-pill cb-core-actor-system"><?php esc_html_e( 'system', 'core-blueprint' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<span class="cb-core-badge cb-core-mr-category cb-core-mr-category--<?php echo esc_attr( $category ); ?>">
									<?php echo esc_html( \CB\Core\Log\MaintenanceReport::category_label( $category ) ); ?>
								</span>
							</td>
							<td>
								<span class="cb-core-badge cb-core-mr-source cb-core-mr-source--<?php echo esc_attr( $source ); ?>">
									<?php echo esc_html( \CB\Core\Log\MaintenanceReport::source_label( $source ) ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Pagination -->

		<?php if ( $total_pages > 1 ) :
			$base_url = admin_url( 'admin.php?page=' . \CB\Core\Admin\Admin::LOGS_SLUG . '&tab=maintenance' );
			if ( $current_actor )    { $base_url = add_query_arg( 'actor', $current_actor, $base_url ); }
			if ( $current_category ) { $base_url = add_query_arg( 'category', $current_category, $base_url ); }
			if ( $current_source )   { $base_url = add_query_arg( 'source', $current_source, $base_url ); }
			if ( $current_period && 'all' !== $current_period ) { $base_url = add_query_arg( 'period', $current_period, $base_url ); }
		?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php
						printf(
							/* translators: %s: number of events */
							esc_html( _n( '%s event', '%s events', $total, 'core-blueprint' ) ),
							esc_html( number_format_i18n( $total ) )
						);
						?>
					</span>
					<span class="pagination-links">
						<?php if ( $current > 1 ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current - 1, $base_url ) ); ?>">‹</a>
						<?php else : ?>
							<span class="button disabled">‹</span>
						<?php endif; ?>
						<span class="cb-core-separator">
							<?php
							/* translators: 1: current page, 2: total pages */
							$pagination_label = sprintf( __( 'Page %1$d of %2$d', 'core-blueprint' ), $current, $total_pages );
							echo esc_html( $pagination_label );
							?>
						</span>
						<?php if ( $current < $total_pages ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current + 1, $base_url ) ); ?>">›</a>
						<?php else : ?>
							<span class="button disabled">›</span>
						<?php endif; ?>
					</span>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>

</div>
