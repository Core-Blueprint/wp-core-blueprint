<?php
declare(strict_types=1);
/** Core Scanner admin view module: ScannerOverviewView.
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

trait ScannerOverviewView {

	private static function render_idle_state( array $baseline, bool $is_enabled = true ): void {
		?>
		<div class="cb-core-integrity-actions" aria-label="<?php echo esc_attr__( 'Core Scanner actions', 'core-blueprint' ); ?>">
			<?php if ( $is_enabled ) : ?>
				<button type="button" class="button cb-core-button cb-core-button--primary cb-core-integrity-primary-action" id="cb-core-integrity-run-scan" data-cb-integrity-action="run-scan">
					<?php echo esc_html__( 'Run Core Scanner', 'core-blueprint' ); ?>
				</button>
			<?php endif; ?>
			<?php if ( self::can_manage_policy() && ! empty( $baseline['exists'] ) ) : ?>
				<button type="button" class="button cb-core-button cb-core-button--secondary" id="cb-core-integrity-clear-baseline" data-cb-integrity-action="clear-baseline">
					<?php echo esc_html__( 'Clear Approved Baseline', 'core-blueprint' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<section class="cb-core-integrity-panel cb-core-integrity-empty-state">
			<h2 class="cb-core-section-title"><?php echo esc_html__( 'No active scan result', 'core-blueprint' ); ?></h2>
			<p><?php echo esc_html__( 'Run a scan to analyze the current state of this site. Existing scan history and approved baselines are kept separately.', 'core-blueprint' ); ?></p>
		</section>

		<details class="cb-core-integrity-about-scan cb-core-disclosure cb-core-disclosure--section cb-core-disclosure--subtle">
			<summary class="cb-core-disclosure__summary">
				<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-disclosure__icon' ] ); ?>
				<h2 class="cb-core-disclosure__title"><?php echo esc_html__( 'About this scan', 'core-blueprint' ); ?></h2>
			</summary>
			<div class="cb-core-disclosure__body">
				<?php self::render_about_scan_content(); ?>
			</div>
		</details>
		<?php
	}

	/**
	 * Render the scanning-state UI.
	 *
	 * The progress block is server-rendered as the initial frame
	 * (0% on bar, "Starting…" phase label, 0:00 timer). JS takes
	 * over once it polls the transient - by then this server-side
	 * markup already gave the operator immediate visual feedback.
	 */

	private static function render_scanning_state( array $settings ): void {
		?>
		<section class="cb-core-integrity-panel cb-core-integrity-progress-panel" id="cb-core-integrity-progress" aria-label="<?php echo esc_attr__( 'Scan progress', 'core-blueprint' ); ?>" aria-live="polite">
			<div class="cb-core-integrity-progress-head">
				<h2 class="cb-core-integrity-progress-title">
					<span class="cb-core-spinner is-active" aria-hidden="true"><span class="cb-core-spinner__ring"></span></span>
					<?php echo esc_html__( 'Running Core Scanner', 'core-blueprint' ); ?>
				</h2>
				<span class="cb-core-integrity-progress-timer" id="cb-core-integrity-progress-timer" aria-label="<?php echo esc_attr__( 'Elapsed time', 'core-blueprint' ); ?>">0:00.0</span>
			</div>
			<div class="cb-core-integrity-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<div class="cb-core-integrity-progress-bar-fill" id="cb-core-integrity-progress-fill" style="width: 0%"></div>
			</div>
			<p class="cb-core-integrity-progress-phase" id="cb-core-integrity-progress-phase">
				<?php echo esc_html__( 'Starting…', 'core-blueprint' ); ?>
			</p>
		</section>
		<?php
	}

	/**
	 * Render the result-state UI: summary tiles, components,
	 * findings, verified (collapsed), diff (only if has-changes).
	 */

	private static function render_result_state( array $latest, array $settings, array $baseline, bool $is_enabled = true ): void {
		$summary    = ResultFormatter::summary( $latest );
		$baseline_eligibility = ResultRepository::baselineApprovalEligibility( $latest );
		$baseline_candidates  = (int) ( $baseline_eligibility['candidates'] ?? 0 );
		$incident_lifecycle = is_array( $latest['incident_lifecycle'] ?? null ) ? $latest['incident_lifecycle'] : [];
		$critical   = (int) ( $summary['summary']['critical'] ?? 0 );
		$warning    = (int) ( $summary['summary']['warning'] ?? 0 );
		$ok         = (int) ( $summary['summary']['ok'] ?? 0 );
		$review_count = count( ResultFormatter::review_findings( $latest ) );
		$status     = (string) ( $summary['status'] ?? 'idle' );
		$completion = (string) ( $summary['completion'] ?? 'incomplete' );
		$coverage   = is_array( $summary['coverage'] ?? null ) ? $summary['coverage'] : [];
		$status_label = 'ok' === $status
			? ( 'complete' === $completion ? __( 'No anomalies detected', 'core-blueprint' ) : __( 'No anomalies detected in checked scope', 'core-blueprint' ) )
			: __( 'Review needed', 'core-blueprint' );
		$status_variant = match ( $status ) {
			'ok'                => 'active',
			'warning', 'notice' => 'warning',
			'critical', 'failed', 'error' => 'error',
			default             => 'idle',
		};
		$last_scan  = (string) ( $summary['last_scan'] ?? '' );
		$duration   = (float) ( $latest['duration_seconds'] ?? 0 );
		$anomaly    = is_array( $latest['anomaly'] ?? null ) ? $latest['anomaly'] : null;

		$lifecycle_counts = is_array( $incident_lifecycle['counts'] ?? null ) ? $incident_lifecycle['counts'] : [];
		$has_changes = ! empty( $incident_lifecycle['has_previous'] ) && (
			(int) ( $lifecycle_counts['new'] ?? 0 ) > 0 ||
			(int) ( $lifecycle_counts['changed'] ?? 0 ) > 0 ||
			(int) ( $lifecycle_counts['resolved'] ?? 0 ) > 0 ||
			(int) ( $lifecycle_counts['unconfirmed'] ?? 0 ) > 0
		);
		$baseline_review_label = '';
		if ( $baseline_candidates > 0 ) {
			/* translators: %d: number of local baseline candidates awaiting review. */
			$baseline_review_label = sprintf( _n( 'Review %d Local Baseline', 'Review %d Local Baselines', $baseline_candidates, 'core-blueprint' ), $baseline_candidates );
		}
		$duration_label = '';
		if ( $duration > 0 ) {
			/* translators: %s: formatted scan duration in seconds. */
			$duration_label = sprintf( __( 'Completed in %ss', 'core-blueprint' ), number_format_i18n( $duration, 1 ) );
		}
		$review_findings_label = '';
		if ( $review_count > 0 ) {
			/* translators: %d: number of findings requiring review. */
			$review_findings_label = sprintf( _n( 'Review %d finding', 'Review %d findings', $review_count, 'core-blueprint' ), $review_count );
		}
		$baseline_meta_label = __( 'No approved local baseline yet', 'core-blueprint' );
		if ( ! empty( $baseline['exists'] ) ) {
			/* translators: 1: number of approved baseline entries, 2: baseline creation timestamp. */
			$baseline_meta_label = sprintf( __( '%1$d entries, created %2$s', 'core-blueprint' ), (int) $baseline['entry_count'], (string) $baseline['created_at'] );
		}
		?>
		<div class="cb-core-integrity-actions" aria-label="<?php echo esc_attr__( 'Core Scanner actions', 'core-blueprint' ); ?>">
			<?php if ( $is_enabled ) : ?>
				<button type="button" class="button cb-core-button cb-core-button--primary cb-core-integrity-primary-action" id="cb-core-integrity-run-scan" data-cb-integrity-action="run-scan">
					<?php echo esc_html__( 'Run Core Scanner', 'core-blueprint' ); ?>
				</button>
			<?php endif; ?>
			<?php if ( $is_enabled && self::can_manage_policy() && $baseline_candidates > 0 ) : ?>
				<a class="button cb-core-button cb-core-button--secondary cb-core-integrity-secondary-action" href="<?php echo esc_url( self::scanner_view_url( 'findings', [ 'cb_integrity_baseline_candidate' => '1' ] ) ); ?>">
					<?php echo esc_html( $baseline_review_label ); ?>
				</a>
			<?php endif; ?>
			<?php if ( self::can_manage_policy() ) : ?>
				<details class="cb-core-integrity-maintenance-actions">
					<summary class="button"><?php echo esc_html__( 'Maintenance', 'core-blueprint' ); ?></summary>
					<div class="cb-core-integrity-maintenance-actions__menu">
						<?php if ( ! empty( $baseline['exists'] ) ) : ?>
							<button type="button" class="button cb-core-button cb-core-button--secondary" id="cb-core-integrity-clear-baseline" data-cb-integrity-action="clear-baseline">
								<?php echo esc_html__( 'Clear Approved Baseline', 'core-blueprint' ); ?>
							</button>
						<?php endif; ?>
						<button type="button" class="button cb-core-button cb-core-button--danger" id="cb-core-integrity-clear-results" data-cb-integrity-action="clear-results">
							<?php echo esc_html__( 'Clear Results', 'core-blueprint' ); ?>
						</button>
					</div>
				</details>
			<?php endif; ?>
		</div>

		<section class="cb-core-integrity-overview-panel cb-core-integrity-overview-panel-<?php echo esc_attr( $status ); ?>" aria-live="polite">
			<div class="cb-core-integrity-overview-main">
				<span class="cb-core-integrity-label"><?php echo esc_html__( 'Current scan state', 'core-blueprint' ); ?></span>
				<span id="cb-core-integrity-status" class="cb-core-integrity-current-status"><?php echo Status::render( $status_variant, $status_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status::render() returns escape-clean HTML. ?></span>
				<span id="cb-core-integrity-last-scan" class="cb-core-integrity-muted">
					<?php
					echo esc_html( $last_scan );
					if ( $duration > 0 ) {
						echo ' · ' . esc_html( $duration_label );
					}
					?>
				</span>
				<?php if ( $review_count > 0 ) : ?>
					<a class="button cb-core-button cb-core-button--secondary cb-core-integrity-review-findings" href="<?php echo esc_url( self::scanner_view_url( 'findings' ) ); ?>">
						<?php echo esc_html( $review_findings_label ); ?>
					</a>
				<?php endif; ?>
			</div>
			<div class="cb-core-integrity-overview-metrics">
				<div><span><?php echo esc_html__( 'Critical', 'core-blueprint' ); ?></span><strong id="cb-core-integrity-count-critical"><?php echo esc_html( (string) $critical ); ?></strong></div>
				<div><span><?php echo esc_html__( 'Warnings', 'core-blueprint' ); ?></span><strong id="cb-core-integrity-count-warning"><?php echo esc_html( (string) $warning ); ?></strong></div>
				<div><span><?php echo esc_html__( 'Verified', 'core-blueprint' ); ?></span><strong id="cb-core-integrity-count-ok"><?php echo esc_html( (string) $ok ); ?></strong></div>
				<div><span><?php echo esc_html__( 'Coverage', 'core-blueprint' ); ?></span><strong><?php echo esc_html( ucfirst( $completion ) ); ?></strong></div>
				<div class="cb-core-integrity-baseline-metric"><span><?php echo esc_html__( 'Baseline', 'core-blueprint' ); ?></span><strong id="cb-core-integrity-baseline-status"><?php echo ! empty( $baseline['exists'] ) ? esc_html__( 'Approved', 'core-blueprint' ) : esc_html__( 'None', 'core-blueprint' ); ?></strong><small id="cb-core-integrity-baseline-meta"><?php echo esc_html( $baseline_meta_label ); ?></small></div>
			</div>
		</section>

		<?php self::render_coverage_panel( $coverage ); ?>

		<?php if ( null !== $anomaly && 'slower' === ( $anomaly['type'] ?? '' ) ) : ?>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() returns escape-clean HTML.
			echo Notice::render( [
				'variant' => Notice::WARNING,
				'title'   => __( 'This scan took longer than usual', 'core-blueprint' ),
				'message' => sprintf(
					/* translators: 1: previous scan duration, 2: current scan duration, 3: ratio */
					__( 'Previous scan: %1$ss · this scan: %2$ss · %3$s× slower', 'core-blueprint' ),
					number_format_i18n( (float) $anomaly['previous_seconds'], 1 ),
					number_format_i18n( (float) $anomaly['current_seconds'], 1 ),
					number_format_i18n( (float) $anomaly['ratio'], 1 )
				),
			] );
			?>
		<?php endif; ?>

		<?php if ( $has_changes ) : ?>
			<section class="cb-core-integrity-panel cb-core-integrity-diff-panel">
				<div class="cb-core-integrity-panel-head">
					<div>
						<h2 class="cb-core-section-title"><?php echo esc_html__( 'Anomaly Changes Since Last Scan', 'core-blueprint' ); ?></h2>
						<p id="cb-core-integrity-diff-meta"><?php echo esc_html__( 'This compares detected anomalies between completed scans. A resolved anomaly is only confirmed when the current scan completed coverage for that area.', 'core-blueprint' ); ?></p>
					</div>
				</div>
				<div id="cb-core-integrity-diff">
					<?php self::render_incident_changes( $incident_lifecycle ); ?>
				</div>
			</section>
		<?php endif; ?>

		<section class="cb-core-integrity-panel">
			<div class="cb-core-integrity-panel-head">
				<h2 class="cb-core-section-title"><?php echo esc_html__( 'Components', 'core-blueprint' ); ?></h2>
				<p class="cb-core-integrity-muted"><?php echo esc_html__( 'Open a component directly in the Findings workspace.', 'core-blueprint' ); ?></p>
			</div>
			<div class="cb-core-integrity-component-grid" id="cb-core-integrity-components">
				<?php foreach ( (array) $summary['components'] as $component => $state_value ) : ?>
					<?php
					$component_key = sanitize_key( (string) $component );
					$component_url = self::scanner_view_url( 'findings', [ 'cb_integrity_component' => $component_key ] );
					$is_active_component = false;
					?>
					<a href="<?php echo esc_url( $component_url ); ?>"
						class="cb-core-integrity-component cb-core-integrity-component-filter<?php echo $is_active_component ? ' cb-core-integrity-component-filter-active' : ''; ?>"
						data-cb-integrity-component-filter="<?php echo esc_attr( $component_key ); ?>"
						<?php echo $is_active_component ? 'aria-current="page"' : ''; ?>>
						<span><?php echo esc_html( ucfirst( (string) $component ) ); ?></span>
						<?php echo StateBadge::render( (string) $state_value, [ 'variant' => self::state_badge_variant( (string) $state_value ) ] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</section>

		<details class="cb-core-integrity-about-scan cb-core-disclosure cb-core-disclosure--section cb-core-disclosure--subtle">
			<summary class="cb-core-disclosure__summary">
				<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-disclosure__icon' ] ); ?>
				<h2 class="cb-core-disclosure__title"><?php echo esc_html__( 'About this scan', 'core-blueprint' ); ?></h2>
			</summary>
			<div class="cb-core-disclosure__body">
				<?php self::render_about_scan_content(); ?>
			</div>
		</details>

		<?php
	}

	private static function render_coverage_panel( array $coverage ): void {
		$state = (string) ( $coverage['state'] ?? 'incomplete' );
		$incomplete = is_array( $coverage['incomplete_components'] ?? null ) ? $coverage['incomplete_components'] : [];
		?>
		<section class="cb-core-integrity-panel cb-core-integrity-coverage-panel">
			<div class="cb-core-integrity-panel-head">
				<div>
					<h2 class="cb-core-section-title"><?php echo esc_html__( 'Scan coverage', 'core-blueprint' ); ?></h2>
					<p class="cb-core-integrity-muted">
						<?php echo 'complete' === $state
							? esc_html__( 'All enabled scan areas completed their configured checks. This does not guarantee that the site is secure; it means no part of the configured scan was silently skipped.', 'core-blueprint' )
							: esc_html__( 'Coverage is incomplete. Review the affected scan areas below before interpreting the findings; some files or components could not be verified.', 'core-blueprint' ); ?>
					</p>
				</div>
				<?php echo StateBadge::render( strtoupper( $state ), [ 'variant' => 'complete' === $state ? StateBadge::SUCCESS : StateBadge::WARNING ] ); ?>
			</div>
			<div class="cb-core-integrity-component-grid">
				<?php foreach ( [ 'core', 'plugins', 'themes', 'uploads' ] as $component ) : ?>
					<?php
					$component_coverage = is_array( $coverage[ $component ] ?? null ) ? $coverage[ $component ] : [];
					$component_state = (string) ( $component_coverage['state'] ?? 'unknown' );
					$checked = isset( $component_coverage['files_inspected'] )
						? (int) $component_coverage['files_inspected']
						: (int) ( $component_coverage['verified_files'] ?? 0 )
							+ (int) ( $component_coverage['modified_files'] ?? 0 )
							+ (int) ( $component_coverage['unexpected_files'] ?? 0 )
							+ (int) ( $component_coverage['local_baseline_files_checked'] ?? 0 )
							+ (int) ( $component_coverage['snapshot_files_inspected'] ?? 0 );
					$unexpected = (int) ( $component_coverage['unexpected_files'] ?? 0 );
					$missing = (int) ( $component_coverage['missing_files'] ?? 0 );
					$unreadable = (int) ( $component_coverage['unreadable_files'] ?? $component_coverage['unreadable'] ?? 0 );
					/* translators: 1: checked file count, 2: unexpected file count, 3: missing file count, 4: unreadable file count. */
					$coverage_label = sprintf( __( 'Checked %1$d · unexpected %2$d · missing %3$d · unreadable %4$d', 'core-blueprint' ), $checked, $unexpected, $missing, $unreadable );
					?>
					<?php $component_url = self::scanner_view_url( 'findings', [ 'cb_integrity_component' => $component ] ); ?>
					<a class="cb-core-integrity-component cb-core-integrity-component-filter" href="<?php echo esc_url( $component_url ); ?>">
						<span><?php echo esc_html( ucfirst( $component ) ); ?></span>
						<?php echo StateBadge::render( strtoupper( $component_state ), [ 'variant' => self::state_badge_variant( $component_state ) ] ); ?>
						<small class="cb-core-integrity-muted"><?php echo esc_html( $coverage_label ); ?></small>
					</a>
				<?php endforeach; ?>
			</div>
			<?php if ( ! empty( $incomplete ) ) : ?>
				<p class="cb-core-integrity-hint"><strong><?php echo esc_html__( 'Needs attention:', 'core-blueprint' ); ?></strong> <?php echo esc_html( implode( ', ', array_map( 'ucfirst', $incomplete ) ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/** About-scan content fragment - used by both idle and result states. */
	private static function render_about_scan_content(): void {
		?>
		<div class="cb-core-integrity-about-grid">
			<div>
				<h2 class="cb-core-section-title"><?php echo esc_html__( 'What this scan does', 'core-blueprint' ); ?></h2>
				<p><?php echo esc_html__( 'This scanner is carefully developed to provide a structured and reliable way to monitor the integrity of your website. It compares files against trusted sources such as WordPress.org, approved local baselines, and the current filesystem.', 'core-blueprint' ); ?></p>
				<ul>
					<li><?php echo esc_html__( 'Detects unexpected file changes.', 'core-blueprint' ); ?></li>
					<li><?php echo esc_html__( 'Identifies missing or newly added files.', 'core-blueprint' ); ?></li>
					<li><?php echo esc_html__( 'Compares files against official checksums where available.', 'core-blueprint' ); ?></li>
					<li><?php echo esc_html__( 'Compares files against an approved local baseline.', 'core-blueprint' ); ?></li>
				</ul>
			</div>
			<div>
				<h2 class="cb-core-section-title"><?php echo esc_html__( 'What this scan does not do', 'core-blueprint' ); ?></h2>
				<ul>
					<li><?php echo esc_html__( 'It does not guarantee that your website is fully secure.', 'core-blueprint' ); ?></li>
					<li><?php echo esc_html__( 'It does not detect all types of malware or vulnerabilities.', 'core-blueprint' ); ?></li>
					<li><?php echo esc_html__( 'It cannot verify all premium or custom plugins without a trusted external source.', 'core-blueprint' ); ?></li>
					<li><?php echo esc_html__( 'It does not replace a full security audit, monitoring solution, or firewall.', 'core-blueprint' ); ?></li>
				</ul>
				<p class="cb-core-integrity-muted"><?php echo esc_html__( 'This scan is a best-effort analysis. While carefully designed, it should always be used as part of a broader security and maintenance strategy.', 'core-blueprint' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function render_incident_changes( array $lifecycle ): void {
		$counts = is_array( $lifecycle['counts'] ?? null ) ? $lifecycle['counts'] : [];

		echo '<div class="cb-core-integrity-diff-summary">';
		echo '<span><strong>+' . esc_html( (string) ( $counts['new'] ?? 0 ) ) . '</strong> ' . esc_html__( 'new', 'core-blueprint' ) . '</span>';
		echo '<span><strong>~' . esc_html( (string) ( $counts['changed'] ?? 0 ) ) . '</strong> ' . esc_html__( 'changed', 'core-blueprint' ) . '</span>';
		echo '<span><strong>✓' . esc_html( (string) ( $counts['resolved'] ?? 0 ) ) . '</strong> ' . esc_html__( 'resolved', 'core-blueprint' ) . '</span>';
		echo '<span><strong>?' . esc_html( (string) ( $counts['unconfirmed'] ?? 0 ) ) . '</strong> ' . esc_html__( 'resolution unconfirmed', 'core-blueprint' ) . '</span>';
		echo '</div>';

		$groups = [
			'new'         => __( 'New anomalies', 'core-blueprint' ),
			'changed'     => __( 'Changed anomalies', 'core-blueprint' ),
			'resolved'    => __( 'Resolved anomalies', 'core-blueprint' ),
			'unconfirmed' => __( 'Resolution not confirmed', 'core-blueprint' ),
		];

		echo '<div class="cb-core-integrity-diff-components">';
		foreach ( $groups as $key => $label ) {
			$items = is_array( $lifecycle[ $key ] ?? null ) ? $lifecycle[ $key ] : [];
			if ( [] === $items ) {
				continue;
			}

			echo '<details class="cb-core-integrity-diff-component">';
			echo '<summary><strong>' . esc_html( $label ) . '</strong><span>' . esc_html( (string) count( $items ) ) . '</span></summary>';
			echo '<ul>';
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$target = is_array( $item['target'] ?? null ) ? $item['target'] : [];
				$component = (string) ( $target['label'] ?? $target['slug'] ?? $item['type'] ?? '' );
				$path = self::finding_target_path( $item );
				echo '<li>';
				if ( '' !== $component ) {
					echo '<strong>' . esc_html( $component ) . '</strong>';
				}
				if ( '' !== $path ) {
					echo ( '' !== $component ? ' · ' : '' ) . '<code>' . esc_html( $path ) . '</code>';
				}
				echo '</li>';
			}
			echo '</ul></details>';
		}
		echo '</div>';
	}

}
