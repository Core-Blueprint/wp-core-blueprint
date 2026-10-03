<?php
declare(strict_types=1);
/** Core Scanner admin view module: ScannerFindingsView.
 * @package Core_Blueprint
 * @since 1.0.0
 */
namespace CoreBlueprint\Core\Integrity\Admin;

use CoreBlueprint\Core\Integrity\Quarantine\Repository as QuarantineRepository;
use CoreBlueprint\Core\Integrity\Quarantine\Service as QuarantineService;
use CoreBlueprint\Core\Integrity\State;
use CoreBlueprint\Core\Integrity\Support\ResultFormatter;
use CoreBlueprint\Core\Integrity\Storage\BaselineReviewRepository;
use CoreBlueprint\Core\Integrity\Storage\ResultRepository;
use CoreBlueprint\Core\UI\Icon;
use CoreBlueprint\Core\UI\FormStatus;
use CoreBlueprint\Core\UI\Notice;
use CoreBlueprint\Core\UI\StateBadge;
use CoreBlueprint\Core\UI\Status;

use function checked;
use function count;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function get_locale;
use function is_array;
use function number_format_i18n;
use function selected;
use function sprintf;
use function strtoupper;
use function ucfirst;

defined( 'ABSPATH' ) || exit;

trait ScannerFindingsView {

    private static function render_findings_empty_state(): void {
        ?>
        <section class="cb-core-integrity-panel cb-core-integrity-empty-state">
            <h2 class="cb-core-section-title"><?php echo esc_html__( 'No scan findings yet', 'core-blueprint' ); ?></h2>
            <p><?php echo esc_html__( 'Run Core Scanner from Overview first. Findings that require human review will appear here.', 'core-blueprint' ); ?></p>
        </section>
        <?php
    }

	private static function render_findings_state( array $latest, array $settings ): void {
		$batch = max( 10, (int) ( $settings['max_visible_findings'] ?? 50 ) );
		$limit = isset( $_GET['cb_integrity_findings'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
			? max( $batch, absint( wp_unslash( $_GET['cb_integrity_findings'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: $batch;
		$component = isset( $_GET['cb_integrity_component'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
			? sanitize_key( wp_unslash( $_GET['cb_integrity_component'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		if ( ! in_array( $component, [ '', 'core', 'plugins', 'themes', 'uploads' ], true ) ) {
			$component = '';
		}
		$severity = isset( $_GET['cb_integrity_severity'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
			? sanitize_key( wp_unslash( $_GET['cb_integrity_severity'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		if ( ! in_array( $severity, [ '', 'critical', 'warning' ], true ) ) {
			$severity = '';
		}
		$status = isset( $_GET['cb_integrity_status'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
			? sanitize_key( wp_unslash( $_GET['cb_integrity_status'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$search = isset( $_GET['cb_integrity_search'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
			? sanitize_text_field( wp_unslash( $_GET['cb_integrity_search'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$actionable_only = isset( $_GET['cb_integrity_actionable'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cb_integrity_actionable'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$baseline_only = isset( $_GET['cb_integrity_baseline_candidate'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cb_integrity_baseline_candidate'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$baseline_eligibility = ResultRepository::baselineApprovalEligibility( $latest );
		$baseline_candidates = (int) ( $baseline_eligibility['candidates'] ?? 0 );
		$baseline_review = BaselineReviewRepository::progress( $latest );
		$reviewed_candidate_ids = array_fill_keys( BaselineReviewRepository::reviewedIds( $latest ), true );
		$baseline_meta = ResultFormatter::summary( $latest );
		$baseline = is_array( $baseline_meta['baseline'] ?? null ) ? $baseline_meta['baseline'] : [ 'exists' => false ];

		$all_findings = ResultFormatter::review_findings( $latest );
		$status_options = [];
		$filtered = [];
		foreach ( $all_findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$finding_status = sanitize_key( (string) ( $finding['status'] ?? $finding['type'] ?? 'notice' ) );
			$status_options[ $finding_status ] = true;
			$finding_component = sanitize_key( (string) ( $finding['type'] ?? '' ) );
			$finding_component = match ( $finding_component ) {
				'plugin' => 'plugins',
				'theme'  => 'themes',
				default  => $finding_component,
			};
			if ( '' !== $component && $component !== $finding_component ) {
				continue;
			}
			if ( '' !== $severity && $severity !== sanitize_key( (string) ( $finding['severity'] ?? '' ) ) ) {
				continue;
			}
			if ( '' !== $status && $status !== $finding_status ) {
				continue;
			}
			if ( $actionable_only && ! QuarantineService::can_quarantine_finding( $finding ) ) {
				continue;
			}
			if ( $baseline_only && ! ResultRepository::isBaselineCandidateCheck( $finding ) ) {
				continue;
			}
			if ( '' !== $search ) {
				$target = is_array( $finding['target'] ?? null ) ? $finding['target'] : [];
				$haystack = strtolower( implode( ' ', [
					self::finding_target_path( $finding ),
					(string) ( $target['file'] ?? '' ),
					(string) ( $target['slug'] ?? '' ),
					(string) ( $target['label'] ?? '' ),
					(string) ( $finding['message'] ?? '' ),
				] ) );
				if ( false === strpos( $haystack, strtolower( $search ) ) ) {
					continue;
				}
			}
			$filtered[] = $finding;
		}
		ksort( $status_options );
		$total = count( $filtered );
		$visible = array_slice( $filtered, 0, $limit );
		$groups = ResultFormatter::group_review_findings( $visible );
		$passed = ResultFormatter::grouped_passed( $latest, 500 );
		$scan_summary = ResultFormatter::summary( $latest );
		$passed_count = (int) ( $scan_summary['summary']['ok'] ?? 0 );
		$has_more = count( $visible ) < $total;
		$next_limit = $has_more ? min( $total, max( $limit + $batch, $limit * 2 ) ) : $limit;
		$more_args = [ 'cb_integrity_findings' => $next_limit ];
		foreach ( [ 'cb_integrity_component' => $component, 'cb_integrity_severity' => $severity, 'cb_integrity_status' => $status, 'cb_integrity_search' => $search ] as $key => $value ) {
			if ( '' !== $value ) {
				$more_args[ $key ] = $value;
			}
		}
		if ( $actionable_only ) {
			$more_args['cb_integrity_actionable'] = '1';
		}
		if ( $baseline_only ) {
			$more_args['cb_integrity_baseline_candidate'] = '1';
		}

		/* translators: %d: total number of matching findings. */
		$findings_count_label = sprintf( _n( '%d finding', '%d findings', $total, 'core-blueprint' ), $total );
		$review_progress_label = '';
		if ( $baseline_only && $baseline_candidates > 0 ) {
			/* translators: 1: reviewed baseline candidate count, 2: total baseline candidate count. */
			$review_progress_label = sprintf( __( 'Reviewed %1$d of %2$d', 'core-blueprint' ), (int) $baseline_review['reviewed'], (int) $baseline_review['total'] );
		}
		$baseline_action_label = '';
		if ( $baseline_only && State::is_enabled() && self::can_manage_policy() && $baseline_candidates > 0 ) {
			if ( ! empty( $baseline['exists'] ) ) {
				/* translators: %d: number of local baselines to update. */
				$baseline_action_label = sprintf( _n( 'Update %d Local Baseline', 'Update %d Local Baselines', $baseline_candidates, 'core-blueprint' ), $baseline_candidates );
			} else {
				/* translators: %d: number of local baselines to approve. */
				$baseline_action_label = sprintf( _n( 'Approve %d Local Baseline', 'Approve %d Local Baselines', $baseline_candidates, 'core-blueprint' ), $baseline_candidates );
			}
		}
		/* translators: 1: displayed matching findings, 2: total matching findings, 3: total findings in the current scan. */
		$findings_meta_label = sprintf( __( 'Showing %1$d of %2$d matching findings. %3$d findings exist in the current scan.', 'core-blueprint' ), count( $visible ), $total, count( $all_findings ) );
		$show_more_label = '';
		if ( $has_more ) {
			/* translators: %d: maximum number of findings to show after expanding the list. */
			$show_more_label = sprintf( __( 'Show more findings (up to %d)', 'core-blueprint' ), $next_limit );
		}
		/* translators: %d: number of passed integrity checks. */
		$passed_count_label = sprintf( _n( '%d passed check', '%d passed checks', $passed_count, 'core-blueprint' ), $passed_count );
		?>
		<section class="cb-core-integrity-panel cb-core-integrity-findings-workspace">
			<div class="cb-core-integrity-panel-head">
				<div>
					<h2 class="cb-core-section-title"><?php echo esc_html__( 'Findings', 'core-blueprint' ); ?></h2>
					<p class="cb-core-integrity-muted"><?php echo esc_html( $baseline_only ? __( 'Review every local-baseline candidate before trusting its current file state. Approval changes what future scans consider expected.', 'core-blueprint' ) : __( 'Review anomalies, narrow the investigation, and isolate actionable uploads without losing context.', 'core-blueprint' ) ); ?></p>
				</div>
				<div class="cb-core-integrity-findings-head-actions">
					<?php echo StateBadge::render( $findings_count_label, [ 'variant' => $total > 0 ? StateBadge::WARNING : StateBadge::SUCCESS ] ); ?>
					<?php if ( $baseline_only && $baseline_candidates > 0 ) : ?>
						<span class="cb-core-integrity-review-progress"><?php echo esc_html( $review_progress_label ); ?></span>
					<?php endif; ?>
					<?php if ( $baseline_only && State::is_enabled() && self::can_manage_policy() && $baseline_candidates > 0 ) : ?>
						<button type="button" class="button cb-core-button cb-core-button--primary cb-core-integrity-secondary-action" id="cb-core-integrity-approve-baseline" data-cb-integrity-action="approve-baseline" data-cb-integrity-baseline-candidates="<?php echo esc_attr( (string) $baseline_candidates ); ?>" <?php disabled( empty( $baseline_review['complete'] ) ); ?> title="<?php echo esc_attr( empty( $baseline_review['complete'] ) ? __( 'Review every baseline candidate before bulk approval.', 'core-blueprint' ) : '' ); ?>">
							<?php echo esc_html( $baseline_action_label ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<form class="cb-core-toolbar cb-core-toolbar--compact cb-core-integrity-findings-toolbar" method="get">
				<input type="hidden" name="page" value="core-blueprint-safeguards" />
				<input type="hidden" name="tab" value="core-scanner" />
				<input type="hidden" name="cb_integrity_view" value="findings" />
				<label class="cb-core-toolbar__field cb-core-toolbar__field--grow cb-core-integrity-search-field"><span class="screen-reader-text"><?php echo esc_html__( 'Search findings', 'core-blueprint' ); ?></span><input type="search" name="cb_integrity_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search path, component or message…', 'core-blueprint' ); ?>" /></label>
				<label class="cb-core-toolbar__field"><span class="screen-reader-text"><?php echo esc_html__( 'Component', 'core-blueprint' ); ?></span><select name="cb_integrity_component"><option value=""><?php echo esc_html__( 'All components', 'core-blueprint' ); ?></option><?php foreach ( [ 'core' => __( 'Core', 'core-blueprint' ), 'plugins' => __( 'Plugins', 'core-blueprint' ), 'themes' => __( 'Themes', 'core-blueprint' ), 'uploads' => __( 'Uploads', 'core-blueprint' ) ] as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $component, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label class="cb-core-toolbar__field"><span class="screen-reader-text"><?php echo esc_html__( 'Severity', 'core-blueprint' ); ?></span><select name="cb_integrity_severity"><option value=""><?php echo esc_html__( 'All severities', 'core-blueprint' ); ?></option><option value="critical" <?php selected( $severity, 'critical' ); ?>><?php echo esc_html__( 'Critical', 'core-blueprint' ); ?></option><option value="warning" <?php selected( $severity, 'warning' ); ?>><?php echo esc_html__( 'Warning', 'core-blueprint' ); ?></option></select></label>
				<label class="cb-core-toolbar__field"><span class="screen-reader-text"><?php echo esc_html__( 'Status', 'core-blueprint' ); ?></span><select name="cb_integrity_status"><option value=""><?php echo esc_html__( 'All statuses', 'core-blueprint' ); ?></option><?php foreach ( array_keys( $status_options ) as $key ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( self::finding_status_label( $key ) ); ?></option><?php endforeach; ?></select></label>
				<label class="cb-core-toolbar__toggle"><input type="checkbox" name="cb_integrity_actionable" value="1" <?php checked( $actionable_only ); ?> /><span><?php echo esc_html__( 'Actionable only', 'core-blueprint' ); ?></span></label>
				<label class="cb-core-toolbar__toggle"><input type="checkbox" name="cb_integrity_baseline_candidate" value="1" <?php checked( $baseline_only ); ?> /><span><?php echo esc_html__( 'Baseline candidates only', 'core-blueprint' ); ?></span></label>
				<div class="cb-core-toolbar__actions cb-core-toolbar__actions--inline">
					<div class="cb-core-toolbar__actions-row">
						<a class="cb-core-integrity-reset-filters" href="<?php echo esc_url( self::scanner_view_url( 'findings' ) ); ?>"><?php echo esc_html__( 'Reset filters', 'core-blueprint' ); ?></a>
						<button type="submit" class="button cb-core-button cb-core-button--primary cb-core-button--compact"><?php echo esc_html__( 'Apply filters', 'core-blueprint' ); ?></button>
					</div>
				</div>
			</form>

			<p class="cb-core-integrity-findings-meta"><?php echo esc_html( $findings_meta_label ); ?></p>
			<div class="cb-core-integrity-findings" id="cb-core-integrity-findings"><?php self::render_grouped_findings( $groups, false, $reviewed_candidate_ids ); ?></div>
			<?php if ( $has_more ) : ?>
				<p class="cb-core-integrity-findings-more"><a class="button cb-core-button cb-core-button--secondary" href="<?php echo esc_url( self::scanner_view_url( 'findings', $more_args ) ); ?>"><?php echo esc_html( $show_more_label ); ?></a><span class="cb-core-integrity-muted"><?php echo esc_html__( 'Filtering is applied before pagination, so search always covers the complete stored finding set.', 'core-blueprint' ); ?></span></p>
			<?php endif; ?>
		</section>

		<details class="cb-core-integrity-verified-panel cb-core-disclosure cb-core-disclosure--section">
			<summary class="cb-core-disclosure__summary" aria-controls="cb-core-integrity-passed">
				<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-disclosure__icon' ] ); ?>
				<h2 class="cb-core-disclosure__title"><?php echo esc_html__( 'Verified / Passed Checks', 'core-blueprint' ); ?></h2>
				<span class="cb-core-disclosure__meta cb-core-integrity-group-count" aria-label="<?php echo esc_attr( $passed_count_label ); ?>"><?php echo esc_html( (string) $passed_count ); ?></span>
			</summary>
			<div class="cb-core-disclosure__body">
				<div class="cb-core-integrity-findings cb-core-integrity-passed" id="cb-core-integrity-passed"><?php self::render_grouped_findings( $passed, true ); ?></div>
			</div>
		</details>
		<?php
	}

	private static function finding_status_label( string $status ): string {
		return match ( $status ) {
			'baseline_required'    => __( 'Baseline required', 'core-blueprint' ),
			'unsupported'          => __( 'Unsupported', 'core-blueprint' ),
			'verification_failed'  => __( 'Verification failed', 'core-blueprint' ),
			'scan_incomplete'      => __( 'Scan incomplete', 'core-blueprint' ),
			'unreadable'           => __( 'Unreadable', 'core-blueprint' ),
			'unverifiable'         => __( 'Unverifiable', 'core-blueprint' ),
			'symlink_skipped'      => __( 'Symlink skipped', 'core-blueprint' ),
			'changed'              => __( 'Changed', 'core-blueprint' ),
			'new'                  => __( 'New', 'core-blueprint' ),
			'missing'              => __( 'Missing', 'core-blueprint' ),
			'critical'             => __( 'Critical', 'core-blueprint' ),
			'warning'              => __( 'Warning', 'core-blueprint' ),
			'notice'               => __( 'Notice', 'core-blueprint' ),
			default                => ucwords( str_replace( '_', ' ', $status ) ),
		};
	}

	private static function cluster_upload_groups( array $component_groups ): array {
		$clusters = [];
		foreach ( $component_groups as $group ) {
			foreach ( (array) ( $group['findings'] ?? [] ) as $finding ) {
				if ( ! is_array( $finding ) ) {
					continue;
				}
				$path = self::finding_target_path( $finding );
				$relative = preg_replace( '#^/?wp-content/uploads/#', '', str_replace( '\\', '/', $path ) );
				$relative = is_string( $relative ) ? ltrim( $relative, '/' ) : '';
				$parts = '' !== $relative ? explode( '/', $relative ) : [];
				$top = count( $parts ) > 1 ? (string) $parts[0] : '';
				$key = '' !== $top ? $top : '__uploads_root__';
				$severity = sanitize_key( (string) ( $finding['severity'] ?? 'warning' ) );
				if ( ! isset( $clusters[ $key ] ) ) {
					$clusters[ $key ] = [
						'component' => 'uploads',
						'slug'      => '' !== $top ? $top . '/' : __( 'Uploads root', 'core-blueprint' ),
						'path'      => 'wp-content/uploads/' . ( '' !== $top ? $top . '/' : '' ),
						'status'    => 'critical' === $severity ? 'critical' : 'warning',
						'severity'  => 'critical' === $severity ? 'critical' : 'warning',
						'message'   => '',
						'count'     => 0,
						'findings'  => [],
						'can_approve_baseline' => false,
						'baseline'  => [ 'exists' => false ],
					];
				}
				$clusters[ $key ]['count']++;
				$clusters[ $key ]['findings'][] = $finding;
				if ( 'critical' === $severity ) {
					$clusters[ $key ]['severity'] = 'critical';
					$clusters[ $key ]['status'] = 'critical';
				}
			}
		}
		foreach ( $clusters as &$cluster ) {
			$count = (int) $cluster['count'];
			$cluster['message'] = sprintf(
				/* translators: %d: number of scanner findings grouped under this uploads location. */
				_n( '%d scanner finding is grouped under this uploads location.', '%d scanner findings are grouped under this uploads location.', $count, 'core-blueprint' ),
				$count
			);
		}
		unset( $cluster );
		return array_values( $clusters );
	}

	private static function finding_target_path( array $finding ): string {
		$target = is_array( $finding['target'] ?? null ) ? $finding['target'] : [];
		$path   = trim( str_replace( '\\', '/', (string) ( $target['path'] ?? '' ) ) );
		$file   = ltrim( str_replace( '\\', '/', (string) ( $target['file'] ?? '' ) ), '/' );

		if ( '' === $file ) {
			return $path;
		}

		if ( '' === $path || '.' === $path || './' === $path ) {
			return $file;
		}

		return rtrim( $path, '/' ) . '/' . $file;
	}

}
