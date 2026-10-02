<?php
declare(strict_types=1);
/** Core Scanner admin view module: ScannerGroupedFindingsView.
 * @package Core_Blueprint
 * @since 1.0.0
 */
namespace CB\Core\Integrity\Admin;

use CB\Core\Integrity\Quarantine\Repository as QuarantineRepository;
use CB\Core\Integrity\Quarantine\Service as QuarantineService;
use CB\Core\Integrity\Storage\ResultRepository;
use CB\Core\UI\Icon;
use CB\Core\UI\StateBadge;

defined( 'ABSPATH' ) || exit;

trait ScannerGroupedFindingsView {

	private static function render_grouped_findings( array $groups, bool $passed = false, array $reviewed_candidate_ids = [] ): void {
		if ( empty( $groups ) ) {
			echo '<p class="cb-core-integrity-muted">' . ( $passed ? esc_html__( 'No verified checks to show yet.', 'core-blueprint' ) : esc_html__( 'No findings to show.', 'core-blueprint' ) ) . '</p>';
			return;
		}

		$quarantined_findings = [];
		if ( ! $passed ) {
			foreach ( QuarantineRepository::all() as $quarantine_item ) {
				if ( ! is_array( $quarantine_item ) ) {
					continue;
				}
				$status = (string) ( $quarantine_item['status'] ?? '' );
				if ( in_array( $status, [ 'restored', 'deleted' ], true ) ) {
					continue;
				}
				$finding_id = (string) ( $quarantine_item['finding_id'] ?? '' );
				if ( '' !== $finding_id ) {
					$quarantined_findings[ $finding_id ] = $quarantine_item;
				}
			}
		}

		foreach ( $groups as $component => $component_groups ) {
			if ( ! $passed && 'uploads' === (string) $component ) {
				$component_groups = self::cluster_upload_groups( (array) $component_groups );
			}
			$component_label = ucfirst( (string) $component );
			$group_count     = count( (array) $component_groups );
			if ( ! $passed ) {
				$group_count = 0;
				foreach ( (array) $component_groups as $issue_group ) {
					$group_count += max( 0, (int) ( $issue_group['count'] ?? 0 ) );
				}
			}

			$filter_key = match ( (string) $component ) {
				'plugin' => 'plugins',
				'theme'  => 'themes',
				default  => (string) $component,
			};
			if ( $passed ) {
				/* translators: %d: number of verified items in this component group. */
				$group_count_label = sprintf( _n( '%d verified', '%d verified', $group_count, 'core-blueprint' ), $group_count );
			} else {
				/* translators: %d: number of integrity issues in this component group. */
				$group_count_label = sprintf( _n( '%d issue', '%d issues', $group_count, 'core-blueprint' ), $group_count );
			}
			?>
			<section class="cb-core-integrity-finding-group" data-cb-integrity-component-group="<?php echo esc_attr( $filter_key ); ?>">
				<h3>
					<span><?php echo esc_html( $component_label ); ?></span>
					<span class="cb-core-integrity-group-count" aria-label="<?php echo esc_attr( $group_count_label ); ?>"><?php echo esc_html( (string) $group_count ); ?></span>
				</h3>
				<?php foreach ( $component_groups as $group ) : ?>
					<?php
					$status     = (string) ( $group['status'] ?? 'notice' );
					$severity   = (string) ( $group['severity'] ?? 'ok' );
					$slug       = (string) ( $group['slug'] ?? '' );
					$message    = (string) ( $group['message'] ?? '' );
					$count      = (int) ( $group['count'] ?? 0 );
					$group_path = (string) ( $group['path'] ?? '' );
					$first      = (array) ( (array) ( $group['findings'] ?? [] )[0] ?? [] );
					$type       = (string) ( $first['type'] ?? '' );
					$target     = is_array( $first['target'] ?? null ) ? $first['target'] : [];
					$base_slug  = (string) ( $target['slug'] ?? '' );
					$can_approve = ! empty( $group['can_approve_baseline'] );
					$baseline = is_array( $group['baseline'] ?? null ) ? $group['baseline'] : [ 'exists' => false ];
					$baseline_exists = ! empty( $baseline['exists'] );
					$baseline_label = $baseline_exists ? __( 'Update Approved Baseline', 'core-blueprint' ) : __( 'Approve Baseline', 'core-blueprint' );
					if ( $passed ) {
						/* translators: %d: number of passed integrity checks in this group. */
						$count_label = sprintf( _n( '%d passed check', '%d passed checks', $count, 'core-blueprint' ), $count );
					} else {
						/* translators: %d: number of integrity findings in this group. */
						$count_label = sprintf( _n( '%d finding', '%d findings', $count, 'core-blueprint' ), $count );
					}
					$baseline_approved_label = '';
					if ( $baseline_exists ) {
						/* translators: %s: date/time when the integrity baseline was approved. */
						$baseline_approved_label = sprintf( __( 'Approved on %s', 'core-blueprint' ), (string) ( $baseline['approved_at'] ?? $baseline['updated_at'] ?? $baseline['created_at'] ?? '' ) );
					}
					$candidate_id = ResultRepository::baselineCandidateId( $first );
					$candidate_reviewed = '' !== $candidate_id && isset( $reviewed_candidate_ids[ $candidate_id ] );
					$directory_recommendation = null;
					if ( ! $passed && 'uploads' === (string) $component && self::can_manage_policy() ) {
						foreach ( (array) ( $group['findings'] ?? [] ) as $candidate ) {
							$candidate_id = (string) ( $candidate['id'] ?? '' );
							if ( '' !== $candidate_id && ! isset( $quarantined_findings[ $candidate_id ] ) && QuarantineService::can_quarantine_finding( $candidate ) && QuarantineService::directory_action_available( $candidate ) ) {
								$directory_recommendation = $candidate;
								break;
							}
						}
					}
					?>
					<details class="cb-core-integrity-component-result cb-core-integrity-component-result-<?php echo esc_attr( $severity ); ?> cb-core-interactive-surface cb-core-interactive-row cb-core-interactive-row--<?php echo esc_attr( $severity ); ?>">
						<summary class="cb-core-interactive-row__summary">
							<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-interactive-row__icon' ] ); ?>
							<span class="cb-core-integrity-component-title"><?php echo esc_html( $slug ); ?></span>
							<?php echo StateBadge::render( str_replace( '_', ' ', $status ), [ 'variant' => self::state_badge_variant( $status ) ] ); ?>
							<span class="cb-core-integrity-muted"><?php echo esc_html( $count_label ); ?></span>
							<?php if ( is_array( $directory_recommendation ) ) : ?>
								<button type="button" class="button cb-core-button cb-core-button--remediation cb-core-integrity-summary-action cb-core-integrity-quarantine-primary" data-cb-integrity-action="quarantine-finding" data-cb-integrity-finding-id="<?php echo esc_attr( (string) ( $directory_recommendation['id'] ?? '' ) ); ?>" data-cb-integrity-scope="directory"><?php echo Icon::render( 'quarantine', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-button__icon' ] ); ?><span class="cb-core-button__label"><?php echo esc_html__( 'Quarantine folder', 'core-blueprint' ); ?></span></button>
							<?php endif; ?>
							<?php if ( self::can_manage_policy() && $can_approve && '' !== $type && '' !== $base_slug && '' !== $candidate_id ) : ?>
								<?php if ( $candidate_reviewed ) : ?>
									<?php echo StateBadge::render( __( 'Reviewed', 'core-blueprint' ), [ 'variant' => StateBadge::SUCCESS, 'class' => 'cb-core-integrity-reviewed-pill' ] ); ?>
									<button type="button" class="button cb-core-button cb-core-button--primary cb-core-button--compact cb-core-integrity-summary-action" data-cb-integrity-action="approve-component-baseline" data-cb-integrity-type="<?php echo esc_attr( $type ); ?>" data-cb-integrity-slug="<?php echo esc_attr( $base_slug ); ?>" data-cb-integrity-baseline-exists="<?php echo $baseline_exists ? '1' : '0'; ?>">
										<?php echo esc_html( $baseline_label ); ?>
									</button>
								<?php else : ?>
									<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-integrity-summary-action cb-core-integrity-review-action" data-cb-integrity-action="open-baseline-review"><?php echo Icon::render( 'review', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-button__icon' ] ); ?><span class="cb-core-button__label"><?php echo esc_html__( 'Review', 'core-blueprint' ); ?></span></button>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( self::can_manage_policy() && 'missing' === $status && $baseline_exists && '' !== $type && '' !== $base_slug ) : ?>
								<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-integrity-summary-action" data-cb-integrity-action="remove-component-baseline" data-cb-integrity-type="<?php echo esc_attr( $type ); ?>" data-cb-integrity-slug="<?php echo esc_attr( $base_slug ); ?>">
									<?php echo esc_html__( 'Remove from baseline', 'core-blueprint' ); ?>
								</button>
							<?php endif; ?>
						</summary>
						<p><?php echo esc_html( $message ); ?></p>
						<?php if ( 'uploads' !== (string) $component ) : ?>
						<p class="cb-core-integrity-baseline-indicator <?php echo $baseline_exists ? 'is-approved' : 'is-missing'; ?>">
							<span><?php echo esc_html__( 'Baseline:', 'core-blueprint' ); ?></span>
							<?php if ( $baseline_exists ) : ?>
								<?php echo esc_html( $baseline_approved_label ); ?>
							<?php else : ?>
								<?php echo esc_html__( 'Not set', 'core-blueprint' ); ?>
							<?php endif; ?>
						</p>
						<?php endif; ?>
						<?php if ( 'uploads' !== (string) $component && in_array( $status, [ 'changed', 'new', 'needs_baseline', 'verification_failed' ], true ) ) : ?>
							<p class="cb-core-integrity-hint"><?php echo esc_html__( 'This change may be expected after a plugin or theme update. Only update the baseline after confirming the current state is trusted.', 'core-blueprint' ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $group_path ) : ?>
							<p class="cb-core-integrity-path"><span><?php echo esc_html__( 'Path:', 'core-blueprint' ); ?></span> <code><?php echo esc_html( $group_path ); ?></code></p>
						<?php endif; ?>
						<div class="cb-core-integrity-component-details"<?php echo '' !== $candidate_id ? ' data-cb-integrity-candidate-id="' . esc_attr( $candidate_id ) . '"' : ''; ?>>
							<?php foreach ( (array) ( $group['findings'] ?? [] ) as $finding ) : ?>
								<?php
								$finding_severity = (string) ( $finding['severity'] ?? 'ok' );
								$type             = (string) ( $finding['type'] ?? '' );
								$finding_target   = is_array( $finding['target'] ?? null ) ? $finding['target'] : [];
								$file             = (string) ( $finding_target['file'] ?? '' );
								$finding_path     = self::finding_target_path( $finding );
								$finding_message  = (string) ( $finding['message'] ?? '' );
								?>
								<article class="cb-core-integrity-finding cb-core-integrity-finding-<?php echo esc_attr( $finding_severity ); ?>">
									<div>
										<strong><?php echo esc_html( strtoupper( $finding_severity ) ); ?></strong>
										<span><?php echo esc_html( $finding_message ); ?></span>
									</div>
									<?php if ( '' !== $finding_path && $finding_path !== $group_path ) : ?>
										<div class="cb-core-integrity-finding-path"><span><?php echo esc_html__( 'Path:', 'core-blueprint' ); ?></span> <code><?php echo esc_html( $finding_path ); ?></code></div>
									<?php endif; ?>
									<?php
									$verification       = is_array( $finding['verification'] ?? null ) ? $finding['verification'] : [];
									$investigation_meta = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
									$finding_id         = (string) ( $finding['id'] ?? '' );
									$quarantine_item    = '' !== $finding_id ? ( $quarantined_findings[ $finding_id ] ?? null ) : null;
									$children           = is_array( $finding['children'] ?? null ) ? $finding['children'] : [];
									$finding_meta       = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
									$verified_total     = (int) ( $finding_meta['verified_files'] ?? count( $children ) );
									$lifecycle          = is_array( $finding['lifecycle'] ?? null ) ? $finding['lifecycle'] : [];
									$first_detected     = self::format_finding_timestamp( (string) ( $lifecycle['first_detected_at'] ?? '' ) );
									$last_detected      = self::format_finding_timestamp( (string) ( $lifecycle['last_detected_at'] ?? '' ) );
									$last_changed       = self::format_finding_timestamp( (string) ( $lifecycle['last_changed_at'] ?? '' ) );
									$observations       = max( 0, (int) ( $lifecycle['observations'] ?? 0 ) );
									$state              = sanitize_key( (string) ( $lifecycle['state'] ?? '' ) );
									$first_detected_label = '';
									if ( '' !== $first_detected ) {
										/* translators: %s: formatted first-detected timestamp. */
										$first_detected_label = sprintf( __( 'First detected %s', 'core-blueprint' ), $first_detected );
									}
									$last_detected_label = '';
									if ( '' !== $last_detected && $last_detected !== $first_detected ) {
										/* translators: %s: formatted last-detected timestamp. */
										$last_detected_label = sprintf( __( 'last seen %s', 'core-blueprint' ), $last_detected );
									}
									$last_changed_label = '';
									if ( 'changed' === $state && '' !== $last_changed ) {
										/* translators: %s: formatted timestamp when the finding last changed. */
										$last_changed_label = sprintf( __( 'changed %s', 'core-blueprint' ), $last_changed );
									}
									$observations_label = '';
									if ( $observations > 1 ) {
										/* translators: %d: number of observations of this integrity finding. */
										$observations_label = sprintf( _n( '%d observation', '%d observations', $observations, 'core-blueprint' ), $observations );
									}
									/* translators: %d: number of verified files represented by this finding. */
									$verified_files_label = sprintf( _n( '%d verified file', '%d verified files', $verified_total, 'core-blueprint' ), $verified_total );
									$shown_verified_files_label = '';
									if ( $verified_total > count( $children ) ) {
										/* translators: 1: number of displayed verified file paths, 2: total number of verified files. */
										$shown_verified_files_label = sprintf( __( 'Showing %1$d of %2$d verified file paths to keep this result compact.', 'core-blueprint' ), count( $children ), $verified_total );
									}
									?>
									<?php if ( is_array( $quarantine_item ) ) : ?>
										<div class="cb-core-integrity-remediation-actions">
											<a class="button cb-core-button cb-core-button--secondary cb-core-button--compact" href="<?php echo esc_url( self::scanner_view_url( 'quarantine' ) ); ?>"><?php echo esc_html__( 'Open Quarantine Workspace', 'core-blueprint' ); ?></a>
											<span class="cb-core-integrity-muted"><?php echo esc_html__( 'This finding is already isolated in the Quarantine Workspace. Run a new scan to confirm the original anomaly is resolved.', 'core-blueprint' ); ?></span>
										</div>
									<?php elseif ( ! $passed && self::can_manage_policy() && QuarantineService::can_quarantine_finding( $finding ) ) : ?>
										<div class="cb-core-integrity-remediation-actions">
											<button type="button" class="button cb-core-button cb-core-button--remediation cb-core-button--compact cb-core-integrity-quarantine-file" data-cb-integrity-action="quarantine-finding" data-cb-integrity-finding-id="<?php echo esc_attr( $finding_id ); ?>" data-cb-integrity-scope="file"><?php echo Icon::render( 'quarantine', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-button__icon' ] ); ?><span class="cb-core-button__label"><?php echo esc_html__( 'Quarantine file', 'core-blueprint' ); ?></span></button>
											<?php if ( null === $directory_recommendation && QuarantineService::directory_action_available( $finding ) ) : ?>
												<button type="button" class="button cb-core-button cb-core-button--remediation cb-core-integrity-quarantine-primary" data-cb-integrity-action="quarantine-finding" data-cb-integrity-finding-id="<?php echo esc_attr( $finding_id ); ?>" data-cb-integrity-scope="directory"><?php echo Icon::render( 'quarantine', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-button__icon' ] ); ?><span class="cb-core-button__label"><?php echo esc_html__( 'Quarantine folder', 'core-blueprint' ); ?></span></button>
											<?php endif; ?>
										</div>
									<?php endif; ?>
									<details class="cb-core-disclosure cb-core-disclosure--compact cb-core-integrity-finding-technical">
										<summary class="cb-core-disclosure__summary">
											<?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-disclosure__icon' ] ); ?>
											<span class="cb-core-disclosure__title"><?php echo esc_html__( 'Technical details', 'core-blueprint' ); ?></span>
										</summary>
										<div class="cb-core-disclosure__body">
											<?php if ( ! empty( $verification ) ) : ?>
												<div class="cb-core-integrity-verification"><span><?php echo esc_html__( 'Verification:', 'core-blueprint' ); ?></span> <?php echo esc_html( (string) ( $verification['label'] ?? $verification['method'] ?? '' ) ); ?> <?php if ( ! empty( $verification['confidence'] ) ) : ?><em><?php echo esc_html( '(' . ucfirst( (string) $verification['confidence'] ) . ' confidence)' ); ?></em><?php endif; ?></div>
											<?php endif; ?>
											<?php if ( ! empty( $investigation_meta['filesystem_path'] ) ) : ?>
												<?php $filesystem_path = (string) $investigation_meta['filesystem_path']; ?>
												<div class="cb-core-integrity-finding-path cb-core-integrity-finding-path-technical">
													<span><?php echo esc_html__( 'Filesystem location:', 'core-blueprint' ); ?></span>
													<code><?php echo esc_html( $filesystem_path ); ?></code>
													<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-integrity-copy-path" data-cb-integrity-action="copy-path" data-cb-integrity-copy-value="<?php echo esc_attr( $filesystem_path ); ?>"><?php echo esc_html__( 'Copy path', 'core-blueprint' ); ?></button>
												</div>
											<?php endif; ?>
											<?php if ( ! empty( $investigation_meta['expected_hash'] ) ) : ?>
												<div class="cb-core-integrity-verification"><span><?php echo esc_html__( 'Expected hash:', 'core-blueprint' ); ?></span> <code><?php echo esc_html( (string) $investigation_meta['expected_hash'] ); ?></code></div>
											<?php endif; ?>
											<?php if ( ! empty( $investigation_meta['actual_hash'] ) ) : ?>
												<div class="cb-core-integrity-verification"><span><?php echo esc_html__( 'Current hash:', 'core-blueprint' ); ?></span> <code><?php echo esc_html( (string) $investigation_meta['actual_hash'] ); ?></code></div>
											<?php elseif ( ! empty( $investigation_meta['sha256'] ) ) : ?>
												<div class="cb-core-integrity-verification"><span><?php echo esc_html__( 'SHA-256:', 'core-blueprint' ); ?></span> <code><?php echo esc_html( (string) $investigation_meta['sha256'] ); ?></code></div>
											<?php endif; ?>
											<?php if ( isset( $investigation_meta['size'] ) || isset( $investigation_meta['mtime'] ) ) : ?>
												<div class="cb-core-integrity-verification"><span><?php echo esc_html__( 'File context:', 'core-blueprint' ); ?></span> <?php echo isset( $investigation_meta['size'] ) ? esc_html( size_format( (int) $investigation_meta['size'] ) ) : ''; ?><?php if ( isset( $investigation_meta['mtime'] ) ) : ?> · <?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $investigation_meta['mtime'] ) ); ?><?php endif; ?></div>
											<?php endif; ?>
											<?php if ( '' !== $first_detected || '' !== $last_detected ) : ?>
												<div class="cb-core-integrity-verification">
													<span><?php echo esc_html__( 'Detection history:', 'core-blueprint' ); ?></span>
													<?php if ( '' !== $first_detected_label ) : ?><?php echo esc_html( $first_detected_label ); ?><?php endif; ?>
													<?php if ( '' !== $last_detected_label ) : ?> · <?php echo esc_html( $last_detected_label ); ?><?php endif; ?>
													<?php if ( '' !== $last_changed_label ) : ?> · <?php echo esc_html( $last_changed_label ); ?><?php endif; ?>
													<?php if ( '' !== $observations_label ) : ?> · <?php echo esc_html( $observations_label ); ?><?php endif; ?>
												</div>
											<?php endif; ?>
											<code class="cb-core-integrity-technical"><?php echo esc_html( trim( $type . ' ' . $file ) ); ?></code>
											<?php if ( ! empty( $children ) ) : ?>
												<details class="cb-core-integrity-audit-children">
													<summary><?php echo esc_html( $verified_files_label ); ?></summary>
													<?php if ( $verified_total > count( $children ) ) : ?>
														<p class="cb-core-integrity-muted"><?php echo esc_html( $shown_verified_files_label ); ?></p>
													<?php endif; ?>
													<ul>
														<?php foreach ( $children as $child ) : ?>
															<li><code><?php echo esc_html( is_array( $child ) ? self::finding_target_path( $child ) : '' ); ?></code> <span><?php echo esc_html( (string) ( $child['status'] ?? 'ok' ) ); ?></span></li>
														<?php endforeach; ?>
													</ul>
												</details>
											<?php endif; ?>
										</div>
									</details>
								</article>
							<?php endforeach; ?>
						</div>
						<?php if ( ! $passed && self::can_manage_policy() && $can_approve && '' !== $candidate_id && ! $candidate_reviewed ) : ?>
							<div class="cb-core-integrity-baseline-review-footer">
								<div><strong><?php echo esc_html__( 'Review required', 'core-blueprint' ); ?></strong><span class="cb-core-integrity-muted"><?php echo esc_html__( "Confirm that this component's current files are expected before baseline approval becomes available.", 'core-blueprint' ); ?></span></div>
								<button type="button" class="button cb-core-button cb-core-button--primary" data-cb-integrity-action="mark-baseline-reviewed" data-cb-integrity-candidate-id="<?php echo esc_attr( $candidate_id ); ?>"><?php echo esc_html__( 'Mark reviewed', 'core-blueprint' ); ?></button>
							</div>
						<?php endif; ?>
					</details>
				<?php endforeach; ?>
			</section>
			<?php
		}
	}

}
