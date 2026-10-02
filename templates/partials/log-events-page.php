<?php
/**
 * Shared partial - audit-style log viewer.
 *
 * Renders an activity chart, the shared Toolbar with a Plain/Technical
 * toggle, and a native WordPress log table. Used by the Audit and System
 * tabs in the Logs page; any future tab that queries {@see \CB\Core\Log\AuditLog}
 * with the same filter shape can reuse this partial.
 *
 * Layout: title → description → chart → filter toolbar → table. The toolbar
 * follows normal wp-admin document flow; log data uses `widefat striped`
 * rather than an app-style CSS-grid table.
 *
 * Required variables set by the caller:
 *   $page_title          (string) - e.g. 'Audit Log'
 *   $page_description    (string) - description + "%s" for total count
 *   $tab_slug            (string) - 'audit' | 'system' | …
 *   $event_placeholder   (string) - placeholder for the event input field
 *   $export_button_class (string) - CSS class the JS export dispatcher
 *                                    hooks on (e.g. 'cb-core-export-audit').
 *                                    The JS reads the format from a sibling
 *                                    <select> and fires the matching AJAX
 *                                    action (cb_core_export_audit, …).
 *   $result              (array)  - AuditLog::query() shape
 *   $chart_daily         (array)  - optional; rendered above the filter bar
 *
 * @package Core_Blueprint
 */

defined( 'ABSPATH' ) || exit;

$rows        = $result['rows']     ?? [];
$total       = (int) ( $result['total']    ?? 0 );
$current     = (int) ( $result['page']     ?? 1 );
$per_page    = (int) ( $result['per_page'] ?? 50 );
$total_pages = (int) ceil( $total / max( 1, $per_page ) );

$current_event    = $args['event_like'] ?? ( $sys_args['event_like'] ?? '' );
$current_severity = $args['severity']   ?? ( $sys_args['severity']   ?? '' );
$current_period   = $current_period ?? 'all';

// Resolve the effective description mode once per render. Used for:
//   - per-row rendering (Event column + Context column)
// current_mode() is guaranteed to return 'plain' or 'technical'
// (sync resolves to site-default at the UI layer).
$cb_mode = class_exists( '\CB\Core\UI' ) ? \CB\Core\UI::current_mode() : 'technical';
?>
<div class="wrap cb-core-wrap cb-core-logs-page" data-cb-mode="<?php echo esc_attr( $cb_mode ); ?>">

	<h1 class="cb-core-title"><?php echo esc_html( $page_title ); ?></h1>

	<?php
	// Log description in the active mode. `$log_type` is set by the
	// caller template (audit-log.php → 'audit', system-log.php → 'system').
	// If the caller doesn't set it, we skip the description block entirely
	// so this partial stays backwards-compatible with any future callers
	// that haven't been updated yet.
	$log_type_for_desc = isset( $log_type ) ? (string) $log_type : '';
	$log_description   = '' !== $log_type_for_desc && class_exists( \CB\Core\Log\Language::class )
		? \CB\Core\Log\Language::describe_log( $log_type_for_desc, $cb_mode )
		: '';

	// Meta strip: retention, access, export formats. Keeps AVG-relevant
	// facts visible at a glance alongside the total count so non-technical
	// readers (Peter's gemeente/zorg contacts) see the transparency story
	// without digging into Preferences.
	$log_retention_categories = class_exists( \CB\Core\Governance\RetentionPolicy::class )
		? count( \CB\Core\Governance\RetentionPolicy::CATEGORIES )
		: 0;
	$log_export_formats = class_exists( \CB\Core\Log\LogExporter::class )
		? array_values( \CB\Core\Log\LogExporter::formats() )
		: [ __( 'CSV', 'core-blueprint' ) ];
	?>

	<?php if ( '' !== $log_description ) : ?>
		<p class="cb-core-intro cb-core-log-description">
			<?php echo esc_html( $log_description ); ?>
		</p>
	<?php endif; ?>

	<ul class="cb-core-meta">
		<li class="cb-core-meta__item">
			<?php
			printf(
				/* translators: %s: formatted number of total events */
				esc_html( $page_description ),
				'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
			);
			?>
		</li>
		<?php if ( $log_retention_categories > 0 ) : ?>
			<li class="cb-core-meta__item"><?php esc_html_e( 'Retention: category policy', 'core-blueprint' ); ?></li>
		<?php endif; ?>
		<li class="cb-core-meta__item">
			<?php esc_html_e( 'Visible to administrators only', 'core-blueprint' ); ?>
		</li>
		<li class="cb-core-meta__item">
			<?php
			printf(
				/* translators: %s: comma-separated list of export format names (e.g. "CSV, JSON") */
				esc_html__( 'Exportable as %s', 'core-blueprint' ),
				esc_html( implode( ', ', $log_export_formats ) )
			);
			?>
		</li>
	</ul>

	<!-- ─── Chart (overview) ─────────────────────────────────────── -->

	<section class="cb-core-section cb-core-log-chart">
		<?php
		$chart_rows = $chart_daily ?? ( $sys_chart_daily ?? [] );
		if ( ! empty( $chart_rows ) && class_exists( '\CB\Core\Log\Chart' ) ) {
			echo \CB\Core\Log\Chart::render_activity( $chart_rows, __( 'Activity', 'core-blueprint' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
	</section>

	<!-- ─── Filter toolbar with Plain/Technical/Sync toggle ─────────── -->

	<section class="cb-core-section cb-core-log-filters-wrap">
		<form method="get" class="cb-core-toolbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( \CB\Core\Admin\Admin::LOGS_SLUG ); ?>" />
			<input type="hidden" name="tab"  value="<?php echo esc_attr( $tab_slug ); ?>" />

			<div class="cb-core-toolbar__field">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'Mode', 'core-blueprint' ); ?></span>
				<?php
				// Plain / Technical / Sync switcher - same component used in
				// the HUD header. Click handling is in core/mode-switcher.js;
				// features/logs-toggle.js listens for the broadcast event
				// and reloads (Logs renders different columns per-mode).
				\CB\Core\UI::render_mode_switcher();
				?>
			</div>

			<label class="cb-core-toolbar__field">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'Event type contains', 'core-blueprint' ); ?></span>
				<input type="text" name="event" value="<?php echo esc_attr( $current_event ); ?>" placeholder="<?php echo esc_attr( $event_placeholder ); ?>" />
			</label>

			<label class="cb-core-toolbar__field">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'Severity', 'core-blueprint' ); ?></span>
				<select name="severity">
					<option value="" <?php selected( $current_severity, '' ); ?>><?php esc_html_e( 'Any', 'core-blueprint' ); ?></option>
					<option value="info"     <?php selected( $current_severity, 'info' ); ?>>info</option>
					<option value="notice"   <?php selected( $current_severity, 'notice' ); ?>>notice</option>
					<option value="warning"  <?php selected( $current_severity, 'warning' ); ?>>warning</option>
					<option value="critical" <?php selected( $current_severity, 'critical' ); ?>>critical</option>
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
			$export_formats = class_exists( \CB\Core\Log\LogExporter::class )
				? \CB\Core\Log\LogExporter::formats()
				: [ 'csv' => __( 'CSV', 'core-blueprint' ) ];
			?>
			<div class="cb-core-toolbar__actions">
				<span class="cb-core-toolbar__label"><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></span>
				<div class="cb-core-toolbar__actions-row">
					<button type="submit" class="button">
						<?php esc_html_e( 'Apply filters', 'core-blueprint' ); ?>
					</button>
					<?php if ( $current_event || $current_severity || ( $current_period && 'all' !== $current_period ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \CB\Core\Admin\Admin::LOGS_SLUG . '&tab=' . $tab_slug ) ); ?>" class="button">
							<?php esc_html_e( 'Clear', 'core-blueprint' ); ?>
						</a>
					<?php endif; ?>
					<label class="screen-reader-text" for="<?php echo esc_attr( $tab_slug ); ?>-export-format">
						<?php esc_html_e( 'Export format', 'core-blueprint' ); ?>
					</label>
					<select id="<?php echo esc_attr( $tab_slug ); ?>-export-format" class="cb-core-export-format">
						<?php foreach ( $export_formats as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button <?php echo esc_attr( $export_button_class ); ?>">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Export', 'core-blueprint' ); ?>
					</button>
				</div>
			</div>
		</form>
	</section>

	<!-- ─── Table (detail) ───────────────────────────────────────── -->

	<section class="cb-core-section cb-core-log-table-wrap">
		<?php if ( empty( $rows ) ) : ?>
			<div class="cb-core-empty">
				<?php esc_html_e( 'No events match the current filters.', 'core-blueprint' ); ?>
			</div>
		<?php else : ?>
			<div class="cb-core-log-table-scroll">
				<table class="widefat striped cb-core-log-table cb-core-log-table--events cb-core-log-table--<?php echo esc_attr( $tab_slug ); ?>">
					<thead>
						<tr>
							<th scope="col" class="cb-core-log-col-time"><?php esc_html_e( 'Time', 'core-blueprint' ); ?></th>
							<th scope="col" class="cb-core-log-col-severity"><?php esc_html_e( 'Severity', 'core-blueprint' ); ?></th>
							<th scope="col" class="cb-core-log-col-event"><?php esc_html_e( 'Event', 'core-blueprint' ); ?></th>
							<th scope="col" class="cb-core-log-col-user"><?php esc_html_e( 'User', 'core-blueprint' ); ?></th>
							<th scope="col" class="cb-core-log-col-ip"><?php esc_html_e( 'IP', 'core-blueprint' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Context', 'core-blueprint' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) :
							$context = is_array( $row->context_decoded ?? null ) ? $row->context_decoded : [];

							// Technical context preview: raw key=value · pairs.
							$context_preview_technical = '';
							if ( ! empty( $context ) ) {
								$parts = [];
								foreach ( $context as $k => $v ) {
									if ( is_scalar( $v ) ) {
										$parts[] = $k . '=' . substr( (string) $v, 0, 40 );
									} elseif ( is_array( $v ) ) {
										$parts[] = $k . '=' . wp_json_encode( $v );
									}
								}
								$context_preview_technical = implode( ' · ', $parts );
							}

							// Plain context keeps relevant attribution/change data and hides raw noise.
							$context_preview_plain = '';
							if ( ! empty( $context ) ) {
								$plain_parts = [];
								if ( ! empty( $context['actor'] ) ) {
									/* translators: %s: actor identifier from the log context. */
									$plain_parts[] = sprintf( __( 'by %s', 'core-blueprint' ), (string) $context['actor'] );
								}
								if ( ! empty( $context['reason'] ) ) {
									/* translators: %s: reason recorded in the log context. */
									$plain_parts[] = sprintf( __( 'reason: %s', 'core-blueprint' ), (string) $context['reason'] );
								}
								if ( ! empty( $context['from'] ) && ! empty( $context['to'] ) ) {
									/* translators: 1: previous value, 2: new value. */
									$plain_parts[] = sprintf( __( 'from %1$s to %2$s', 'core-blueprint' ), (string) $context['from'], (string) $context['to'] );
								}
								if ( ! empty( $context['changed'] ) ) {
									/* translators: %s: changed value recorded in the log context. */
									$plain_parts[] = sprintf( __( 'changed: %s', 'core-blueprint' ), (string) $context['changed'] );
								}
								if ( ! empty( $context['module'] ) ) {
									/* translators: %s: module identifier recorded in the log context. */
									$plain_parts[] = sprintf( __( 'module: %s', 'core-blueprint' ), (string) $context['module'] );
								}
								if ( ! empty( $context['feature'] ) ) {
									/* translators: %s: feature identifier recorded in the log context. */
									$plain_parts[] = sprintf( __( 'feature: %s', 'core-blueprint' ), (string) $context['feature'] );
								}
								$context_preview_plain = implode( ' · ', $plain_parts );
							}
							$severity = sanitize_key( (string) $row->severity );
						?>
							<tr>
								<td class="cb-core-log-time">
									<time datetime="<?php echo esc_attr( $row->created_at ); ?>" title="<?php echo esc_attr( $row->created_at ); ?>">
										<?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $row->created_at ) ); ?>
									</time>
								</td>
								<td>
									<span class="cb-core-badge cb-core-badge-severity cb-core-badge-severity--<?php echo esc_attr( $severity ); ?>">
										<?php echo esc_html( $row->severity ); ?>
									</span>
								</td>
								<td>
									<?php if ( 'plain' === $cb_mode ) : ?>
										<span class="cb-core-event-plain"><?php echo esc_html( \CB\Core\Log\Language::describe_event( $row->event_type, $context, 'plain' ) ); ?></span>
									<?php else : ?>
										<span class="cb-core-event-type"><?php echo esc_html( $row->event_type ); ?></span><br />
										<span class="cb-core-muted"><?php echo esc_html( \CB\Core\Log\AuditLog::event_label( $row->event_type, 'technical' ) ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $row->user_login ) : ?>
										<?php echo esc_html( $row->user_login ); ?> <span class="cb-core-muted">#<?php echo esc_html( (string) $row->user_id ); ?></span>
									<?php else : ?>
										<span class="cb-core-muted">-</span>
									<?php endif; ?>
								</td>
								<td class="cb-core-log-ip">
									<?php if ( $row->ip_address ) : ?>
										<code><?php echo esc_html( $row->ip_address ); ?></code>
									<?php else : ?>
										<span class="cb-core-muted">-</span>
									<?php endif; ?>
								</td>
								<td>
									<?php $context_preview = 'plain' === $cb_mode ? $context_preview_plain : $context_preview_technical; ?>
									<span class="cb-core-context-preview" title="<?php echo esc_attr( $context_preview_technical ); ?>">
										<?php echo esc_html( $context_preview ?: '-' ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- ─── Pagination ───────────────────────────────────────── -->

			<?php if ( $total_pages > 1 ) :
				$base_url = admin_url( 'admin.php?page=' . \CB\Core\Admin\Admin::LOGS_SLUG . '&tab=' . $tab_slug );
				$qs       = [];
				if ( $current_event )    { $qs['event']    = $current_event; }
				if ( $current_severity ) { $qs['severity'] = $current_severity; }
				if ( $current_period )   { $qs['period']   = $current_period; }
				$base_with_filters = $base_url . ( $qs ? '&' . http_build_query( $qs ) : '' );
			?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: formatted total number of log items. */
								esc_html( _n( '%s item', '%s items', $total, 'core-blueprint' ) ),
								esc_html( number_format_i18n( $total ) )
							);
							?>
						</span>
						<span class="pagination-links">
							<?php
							$prev = max( 1, $current - 1 );
							$next = min( $total_pages, $current + 1 );
							?>
							<a class="button" href="<?php echo esc_url( $base_with_filters . '&paged=' . $prev ); ?>">«</a>
							<span class="paging-input">
								<?php
								printf(
									/* translators: 1: current page number, 2: total number of pages. */
									esc_html__( '%1$s of %2$s', 'core-blueprint' ),
									esc_html( (string) $current ),
									esc_html( (string) $total_pages )
								);
								?>
							</span>
							<a class="button" href="<?php echo esc_url( $base_with_filters . '&paged=' . $next ); ?>">»</a>
						</span>
					</div>
				</div>
			<?php endif; ?>

		<?php endif; ?>
	</section>

</div>
