<?php
declare(strict_types=1);
/**
 * AIActivityTab - metadata-first AI and agent activity inside Logs.
 *
 * The AI Governance subsystem owns collection, privacy, persistence, export
 * and retention semantics. This renderer only presents that evidence through
 * the canonical Logs shell.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */
namespace CB\Core\Admin\Pages\Logs\Tabs;

use CB\Core\Admin\Admin;
use CB\Core\Admin\TabNav;
use CB\Core\AIGovernance\Activity;
use CB\Core\AIGovernance\Admin\Actions;
use CB\Core\AIGovernance\Repository;
use CB\Core\AIGovernance\Settings;

defined( 'ABSPATH' ) || exit;

final class AIActivityTab {
	public const SLUG = 'ai-activity';

	/**
	 * @param string               $tab        Resolved active-tab slug.
	 * @param array<string,string> $tab_labels Visible Logs tabs.
	 */
	public static function render( string $tab, array $tab_labels ): void {
		$activity_id = isset( $_GET['activity'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['activity'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		if ( '' !== $activity_id ) {
			self::render_detail( $activity_id );
		} else {
			self::render_list();
		}
		$html = ob_get_clean();

		echo TabNav::inject( $html, Admin::LOGS_SLUG, $tab, $tab_labels ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function render_list(): void {
		$filters = self::filters_from_request();
		$query_args = $filters;
		$query_args['page'] = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query_args['per_page'] = 50;

		$result       = Repository::query( $query_args );
		$rows         = $result['rows'];
		$total        = $result['total'];
		$current_page = $result['page'];
		$total_pages  = $result['total_pages'];
		$raw          = self::raw_filter_values();
		?>
		<div class="wrap cb-core-wrap cb-core-logs-page cb-core-ai-activity-page">
			<h1 class="cb-core-title"><?php esc_html_e( 'AI Activity', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro cb-core-log-description"><?php esc_html_e( 'Evidence-based activity records for WordPress abilities, agents and machine integrations. Unknown attribution stays unknown; raw prompts and responses are not captured by default.', 'core-blueprint' ); ?></p>

			<ul class="cb-core-meta">
				<li class="cb-core-meta__item">
					<?php printf( esc_html__( 'Recorded activity: %s', 'core-blueprint' ), '<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>' ); ?>
				</li>
				<li class="cb-core-meta__item"><?php esc_html_e( 'Evidence model: metadata-first', 'core-blueprint' ); ?></li>
				<li class="cb-core-meta__item"><?php esc_html_e( 'Visible to administrators only', 'core-blueprint' ); ?></li>
				<li class="cb-core-meta__item"><?php esc_html_e( 'Exportable as CSV, JSON', 'core-blueprint' ); ?></li>
			</ul>

			<?php if ( isset( $_GET['retention'] ) && 'updated' === sanitize_key( (string) $_GET['retention'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'AI activity retention updated.', 'core-blueprint' ); ?></p></div>
			<?php endif; ?>

			<section class="cb-core-section cb-core-log-filters-wrap">
				<form method="get" class="cb-core-toolbar">
					<input type="hidden" name="page" value="<?php echo esc_attr( Admin::LOGS_SLUG ); ?>" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( self::SLUG ); ?>" />

					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'From', 'core-blueprint' ); ?></span>
						<input type="date" name="from" value="<?php echo esc_attr( $raw['from'] ); ?>" />
					</label>
					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'To', 'core-blueprint' ); ?></span>
						<input type="date" name="to" value="<?php echo esc_attr( $raw['to'] ); ?>" />
					</label>
					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Actor user ID', 'core-blueprint' ); ?></span>
						<input type="number" min="1" name="actor" value="<?php echo esc_attr( $raw['actor'] ); ?>" />
					</label>
					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Source ID', 'core-blueprint' ); ?></span>
						<input type="text" name="source" value="<?php echo esc_attr( $raw['source'] ); ?>" placeholder="wordpress-mcp-adapter" />
					</label>
					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Operation type', 'core-blueprint' ); ?></span>
						<select name="type">
							<option value=""><?php esc_html_e( 'Any', 'core-blueprint' ); ?></option>
							<?php foreach ( Activity::OPERATION_TYPES as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $raw['type'], $type ); ?>><?php echo esc_html( $type ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Transport', 'core-blueprint' ); ?></span>
						<select name="transport">
							<option value=""><?php esc_html_e( 'Any', 'core-blueprint' ); ?></option>
							<?php foreach ( Activity::TRANSPORTS as $transport ) : ?>
								<option value="<?php echo esc_attr( $transport ); ?>" <?php selected( $raw['transport'], $transport ); ?>><?php echo esc_html( $transport ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Provider ID', 'core-blueprint' ); ?></span>
						<input type="text" name="provider" value="<?php echo esc_attr( $raw['provider'] ); ?>" />
					</label>
					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Model ID', 'core-blueprint' ); ?></span>
						<input type="text" name="model" value="<?php echo esc_attr( $raw['model'] ); ?>" />
					</label>
					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Operation contains', 'core-blueprint' ); ?></span>
						<input type="text" name="operation" value="<?php echo esc_attr( $raw['operation'] ); ?>" placeholder="plugin/ability" />
					</label>
					<label class="cb-core-toolbar__field">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Outcome', 'core-blueprint' ); ?></span>
						<select name="outcome">
							<option value=""><?php esc_html_e( 'Any', 'core-blueprint' ); ?></option>
							<?php foreach ( Activity::OUTCOMES as $outcome ) : ?>
								<option value="<?php echo esc_attr( $outcome ); ?>" <?php selected( $raw['outcome'], $outcome ); ?>><?php echo esc_html( $outcome ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<div class="cb-core-toolbar__actions">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></span>
						<div class="cb-core-toolbar__actions-row">
							<button type="submit" class="button"><?php esc_html_e( 'Apply filters', 'core-blueprint' ); ?></button>
							<?php if ( self::has_filters( $raw ) ) : ?>
								<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Clear', 'core-blueprint' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</form>
			</section>

			<section class="cb-core-section cb-core-log-table-wrap">
				<?php if ( [] === $rows ) : ?>
					<div class="cb-core-empty">
						<h2><?php esc_html_e( 'No AI or agent activity recorded yet', 'core-blueprint' ); ?></h2>
						<p><?php esc_html_e( 'Records will appear when WordPress abilities are observed or a supported integration reports governance activity. No AI provider needs to be configured in Core Blueprint.', 'core-blueprint' ); ?></p>
					</div>
				<?php else : ?>
					<div class="cb-core-log-table-scroll">
						<table class="widefat striped cb-core-log-table cb-core-log-table--ai-activity">
							<thead><tr>
								<th scope="col" class="cb-core-log-col-time"><?php esc_html_e( 'Time', 'core-blueprint' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actor', 'core-blueprint' ); ?></th>
								<th scope="col" class="cb-core-log-col-source"><?php esc_html_e( 'Source', 'core-blueprint' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Operation', 'core-blueprint' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Outcome', 'core-blueprint' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Target', 'core-blueprint' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Evidence', 'core-blueprint' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td class="cb-core-log-time"><a href="<?php echo esc_url( self::url( [ 'activity' => (string) $row->activity_id ] ) ); ?>"><?php echo esc_html( (string) $row->created_at ); ?></a></td>
									<td><?php echo esc_html( self::actor_label( $row ) ); ?></td>
									<td><?php echo esc_html( self::source_label( $row ) ); ?><br><code><?php echo esc_html( (string) $row->transport ); ?></code></td>
									<td><code><?php echo esc_html( (string) $row->operation ); ?></code></td>
									<td><code><?php echo esc_html( (string) $row->outcome ); ?></code></td>
									<td><?php echo esc_html( self::target_label( $row ) ); ?></td>
									<td><code><?php echo esc_html( (string) $row->capture_state ); ?></code></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php self::render_pagination( $current_page, $total_pages, $raw ); ?>
				<?php endif; ?>
			</section>

			<section class="cb-core-section">
				<h2 class="cb-core-section-title"><?php esc_html_e( 'Export activity', 'core-blueprint' ); ?></h2>
				<p><?php esc_html_e( 'Export the selected period and active filters. JSON preserves structured evidence; CSV serializes structured fields as JSON cells.', 'core-blueprint' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-toolbar">
					<input type="hidden" name="action" value="<?php echo esc_attr( Actions::EXPORT_ACTION ); ?>" />
					<?php wp_nonce_field( Actions::EXPORT_ACTION ); ?>
					<?php foreach ( $raw as $key => $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php endforeach; ?>
					<label class="cb-core-toolbar__field" for="cb-ai-export-format">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Format', 'core-blueprint' ); ?></span>
						<select id="cb-ai-export-format" name="format"><option value="csv">CSV</option><option value="json">JSON</option></select>
					</label>
					<div class="cb-core-toolbar__actions">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></span>
						<div class="cb-core-toolbar__actions-row"><button type="submit" class="button button-secondary"><?php esc_html_e( 'Export activity', 'core-blueprint' ); ?></button></div>
					</div>
				</form>
			</section>

			<section id="retention" class="cb-core-section">
				<h2 class="cb-core-section-title"><?php esc_html_e( 'AI activity retention', 'core-blueprint' ); ?></h2>
				<p><?php esc_html_e( 'AI Activity is a dedicated governance datastore and is pruned by Core Blueprint’s daily retention runner. Use 0 to retain records indefinitely.', 'core-blueprint' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cb-core-toolbar">
					<input type="hidden" name="action" value="<?php echo esc_attr( Actions::RETENTION_ACTION ); ?>" />
					<?php wp_nonce_field( Actions::RETENTION_ACTION ); ?>
					<label class="cb-core-toolbar__field" for="cb-ai-retention-days">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Retention days', 'core-blueprint' ); ?></span>
						<input id="cb-ai-retention-days" type="number" name="retention_days" min="0" max="<?php echo esc_attr( (string) Settings::MAX_RETENTION_DAYS ); ?>" value="<?php echo esc_attr( (string) Settings::retention_days() ); ?>" />
					</label>
					<div class="cb-core-toolbar__actions">
						<span class="cb-core-toolbar__label"><?php esc_html_e( 'Actions', 'core-blueprint' ); ?></span>
						<div class="cb-core-toolbar__actions-row"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save retention', 'core-blueprint' ); ?></button></div>
					</div>
				</form>
			</section>
		</div>
		<?php
	}

	private static function render_detail( string $activity_id ): void {
		$row = Repository::get( $activity_id );
		?>
		<div class="wrap cb-core-wrap cb-core-logs-page cb-core-ai-activity-page">
			<h1 class="cb-core-title"><?php esc_html_e( 'AI activity detail', 'core-blueprint' ); ?></h1>
			<p class="cb-core-intro"><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Back to AI Activity', 'core-blueprint' ); ?></a></p>
			<?php if ( null === $row ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The requested AI activity record was not found.', 'core-blueprint' ); ?></p></div>
			</div>
			<?php return; endif; ?>

			<section class="cb-core-section">
				<table class="cb-core-kv"><tbody>
				<?php
				$details = [
					__( 'Activity ID', 'core-blueprint' ) => $row->activity_id,
					__( 'Correlation ID', 'core-blueprint' ) => $row->correlation_id ?: '—',
					__( 'Parent activity ID', 'core-blueprint' ) => $row->parent_activity_id ?: '—',
					__( 'Observed at', 'core-blueprint' ) => $row->created_at,
					__( 'Completed at', 'core-blueprint' ) => $row->completed_at ?: '—',
					__( 'Actor', 'core-blueprint' ) => self::actor_label( $row ),
					__( 'Operation type', 'core-blueprint' ) => $row->operation_type,
					__( 'Operation', 'core-blueprint' ) => $row->operation,
					__( 'Transport', 'core-blueprint' ) => $row->transport,
					__( 'Source', 'core-blueprint' ) => self::source_label( $row ),
					__( 'Outcome', 'core-blueprint' ) => $row->outcome,
					__( 'Capture state', 'core-blueprint' ) => $row->capture_state,
					__( 'Target', 'core-blueprint' ) => self::target_label( $row ),
					__( 'Duration (ms)', 'core-blueprint' ) => null !== $row->duration_ms ? (string) $row->duration_ms : '—',
					__( 'Error code', 'core-blueprint' ) => $row->error_code ?: '—',
				];
				foreach ( $details as $label => $value ) : ?>
					<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</section>

			<section class="cb-core-section">
				<h2 class="cb-core-section-title"><?php esc_html_e( 'Evidence', 'core-blueprint' ); ?></h2>
				<pre><?php echo esc_html( wp_json_encode( $row->evidence_decoded ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '{}' ); ?></pre>
				<h2 class="cb-core-section-title"><?php esc_html_e( 'Context', 'core-blueprint' ); ?></h2>
				<pre><?php echo esc_html( wp_json_encode( $row->context_decoded ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '{}' ); ?></pre>
			</section>
		</div>
		<?php
	}

	/** @return array<string,mixed> */
	private static function filters_from_request(): array {
		$raw = self::raw_filter_values();
		$filters = [];
		if ( self::valid_date( $raw['from'] ) ) {
			$filters['since'] = $raw['from'] . ' 00:00:00';
		}
		if ( self::valid_date( $raw['to'] ) ) {
			$filters['until'] = $raw['to'] . ' 23:59:59';
		}
		if ( '' !== $raw['actor'] ) {
			$filters['actor'] = max( 0, (int) $raw['actor'] );
		}
		foreach ( [ 'source', 'type', 'transport', 'provider', 'model', 'operation', 'outcome' ] as $key ) {
			if ( '' !== $raw[ $key ] ) {
				$filters[ $key ] = $raw[ $key ];
			}
		}
		return $filters;
	}

	/** @return array{from:string,to:string,actor:string,source:string,type:string,transport:string,provider:string,model:string,operation:string,outcome:string} */
	private static function raw_filter_values(): array {
		$outcome = isset( $_GET['outcome'] ) ? sanitize_key( (string) wp_unslash( $_GET['outcome'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return [
			'from'      => isset( $_GET['from'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'to'        => isset( $_GET['to'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'actor'     => isset( $_GET['actor'] ) ? (string) max( 0, (int) $_GET['actor'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'source'    => isset( $_GET['source'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['source'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'type'      => isset( $_GET['type'] ) ? sanitize_key( (string) wp_unslash( $_GET['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'transport' => isset( $_GET['transport'] ) ? sanitize_key( (string) wp_unslash( $_GET['transport'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'provider'  => isset( $_GET['provider'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['provider'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'model'     => isset( $_GET['model'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['model'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'operation' => isset( $_GET['operation'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['operation'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'outcome'   => in_array( $outcome, Activity::OUTCOMES, true ) ? $outcome : '',
		];
	}

	private static function actor_label( object $row ): string {
		if ( ! empty( $row->actor_user_login ) ) {
			return (string) $row->actor_user_login . ( ! empty( $row->actor_user_id ) ? ' (#' . (int) $row->actor_user_id . ')' : '' );
		}
		return __( 'Unknown actor', 'core-blueprint' );
	}

	private static function source_label( object $row ): string {
		$parts = [];
		if ( ! empty( $row->source_label ) ) {
			$parts[] = (string) $row->source_label;
		} elseif ( ! empty( $row->source_id ) ) {
			$parts[] = (string) $row->source_id;
		}
		if ( ! empty( $row->provider_label ) ) {
			$parts[] = (string) $row->provider_label;
		} elseif ( ! empty( $row->provider_id ) ) {
			$parts[] = (string) $row->provider_id;
		}
		if ( ! empty( $row->model_label ) ) {
			$parts[] = (string) $row->model_label;
		} elseif ( ! empty( $row->model_id ) ) {
			$parts[] = (string) $row->model_id;
		}
		$parts = array_values( array_unique( array_filter( $parts ) ) );
		return [] !== $parts ? implode( ' · ', $parts ) : __( 'Unknown source', 'core-blueprint' );
	}

	private static function target_label( object $row ): string {
		if ( ! empty( $row->target_label ) ) {
			return (string) $row->target_label;
		}
		if ( ! empty( $row->target_type ) || ! empty( $row->target_id ) ) {
			return trim( (string) $row->target_type . ( ! empty( $row->target_id ) ? ':' . (string) $row->target_id : '' ), ':' );
		}
		return '—';
	}

	/** @param array<string,string> $raw */
	private static function has_filters( array $raw ): bool {
		return [] !== array_filter( $raw, static fn( string $value ): bool => '' !== $value );
	}

	/** @param array<string,string> $raw */
	private static function render_pagination( int $current, int $total, array $raw ): void {
		if ( $total <= 1 ) {
			return;
		}
		$base_args = array_filter( $raw, static fn( $value ): bool => '' !== $value );
		echo '<p class="tablenav-pages">';
		if ( $current > 1 ) {
			$prev = self::url( array_merge( $base_args, [ 'paged' => $current - 1 ] ) );
			echo '<a class="button" href="' . esc_url( $prev ) . '">&larr; ' . esc_html__( 'Previous', 'core-blueprint' ) . '</a> ';
		}
		printf( esc_html__( 'Page %1$d of %2$d', 'core-blueprint' ), $current, $total );
		if ( $current < $total ) {
			$next = self::url( array_merge( $base_args, [ 'paged' => $current + 1 ] ) );
			echo ' <a class="button" href="' . esc_url( $next ) . '">' . esc_html__( 'Next', 'core-blueprint' ) . ' &rarr;</a>';
		}
		echo '</p>';
	}

	/** @param array<string,mixed> $args */
	private static function url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'page' => Admin::LOGS_SLUG, 'tab' => self::SLUG ], $args ),
			admin_url( 'admin.php' )
		);
	}

	private static function valid_date( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d' ) === $value;
	}
}
