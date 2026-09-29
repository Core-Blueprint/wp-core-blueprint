<?php
declare(strict_types=1);
/** Core Scanner admin view module: ScannerQuarantineView.
 * @package Core_Blueprint
 * @since 1.0.0
 */
namespace CB\Core\Integrity\Admin;

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\Integrity\Quarantine\Repository as QuarantineRepository;
use CB\Core\Integrity\Quarantine\Service as QuarantineService;
use CB\Core\Integrity\State;
use CB\Core\Integrity\Support\ResultFormatter;
use CB\Core\Integrity\Storage\BaselineReviewRepository;
use CB\Core\Integrity\Storage\ResultRepository;
use CB\Core\UI\Icon;
use CB\Core\UI\FormStatus;
use CB\Core\UI\Notice;
use CB\Core\UI\StateBadge;
use CB\Core\UI\Status;

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

trait ScannerQuarantineView {

	private static function render_quarantine_workspace( bool $can_manage_policy ): void {
		$items = QuarantineRepository::items();
		$open  = QuarantineRepository::open_count();
		?>
		<section class="cb-core-integrity-panel cb-core-quarantine-workspace" id="cb-core-quarantine-workspace" aria-labelledby="cb-core-quarantine-heading">
			<div class="cb-core-integrity-panel-head">
				<div>
					<h2 id="cb-core-quarantine-heading"><?php echo esc_html__( 'Quarantine Workspace', 'core-blueprint' ); ?></h2>
					<p><?php echo esc_html__( 'Isolate reviewed Scanner findings from the active site, inspect them later, and restore or permanently remove them with a full audit trail.', 'core-blueprint' ); ?></p>
				</div>
				<?php echo StateBadge::render( sprintf( _n( '%d open item', '%d open items', $open, 'core-blueprint' ), $open ), [ 'variant' => $open > 0 ? StateBadge::WARNING : StateBadge::SUCCESS ] ); ?>
			</div>
			<?php if ( empty( $items ) ) : ?>
				<p class="cb-core-integrity-muted"><?php echo esc_html__( 'Nothing is quarantined. Quarantine actions appear on actionable Uploads findings after a scan.', 'core-blueprint' ); ?></p>
			<?php else : ?>
				<div class="cb-core-quarantine-list">
					<?php foreach ( $items as $item ) :
						$status = sanitize_key( (string) ( $item['status'] ?? 'awaiting_review' ) );
						$is_closed = in_array( $status, [ 'restored', 'deleted' ], true );
						$is_transition_attention = in_array( $status, [ 'restoring', 'deleting' ], true );
						$id = (string) ( $item['id'] ?? '' );
					?>
						<article class="cb-core-quarantine-item <?php echo $is_closed ? 'is-closed' : 'is-open'; ?>" data-cb-quarantine-id="<?php echo esc_attr( $id ); ?>">
							<div class="cb-core-quarantine-item__main">
								<div><strong><?php echo esc_html( (string) ( $item['relative_path'] ?? '' ) ); ?></strong><span class="cb-core-integrity-muted"><?php echo esc_html( sprintf( __( '%1$s · %2$d files · %3$s', 'core-blueprint' ), self::quarantine_kind_label( (string) ( $item['kind'] ?? 'file' ) ), (int) ( $item['file_count'] ?? 0 ), size_format( (int) ( $item['total_bytes'] ?? 0 ) ) ) ); ?></span></div>
								<?php echo StateBadge::render( self::quarantine_status_label( $status ), [ 'variant' => self::state_badge_variant( $status ) ] ); ?>
							</div>
							<div class="cb-core-quarantine-item__meta"><span><?php echo esc_html( sprintf( __( 'Quarantined %s', 'core-blueprint' ), (string) ( $item['quarantined_at'] ?? '' ) ) ); ?></span><?php if ( ! empty( $item['evidence_sha256'] ) ) : ?><code><?php echo esc_html( (string) $item['evidence_sha256'] ); ?></code><?php endif; ?></div>
							<div class="cb-core-quarantine-item__actions">
								<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact" data-cb-integrity-action="quarantine-inspect" data-cb-quarantine-id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html__( 'Inspect', 'core-blueprint' ); ?></button>
								<?php if ( $can_manage_policy && ! $is_closed && ! $is_transition_attention ) : ?>
									<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact" data-cb-integrity-action="quarantine-note" data-cb-quarantine-id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html__( 'Add note', 'core-blueprint' ); ?></button>
									<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact" data-cb-integrity-action="quarantine-state" data-cb-quarantine-id="<?php echo esc_attr( $id ); ?>" data-cb-quarantine-state="reviewed"><?php echo esc_html__( 'Mark reviewed', 'core-blueprint' ); ?></button>
									<button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact" data-cb-integrity-action="quarantine-restore" data-cb-quarantine-id="<?php echo esc_attr( $id ); ?>"><?php echo Icon::render( 'restore', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-button__icon' ] ); ?><span class="cb-core-button__label"><?php echo esc_html__( 'Restore', 'core-blueprint' ); ?></span></button>
									<button type="button" class="button cb-core-button cb-core-button--danger cb-core-button--compact" data-cb-integrity-action="quarantine-delete" data-cb-quarantine-id="<?php echo esc_attr( $id ); ?>"><?php echo Icon::render( 'delete', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-button__icon' ] ); ?><span class="cb-core-button__label"><?php echo esc_html__( 'Permanently delete', 'core-blueprint' ); ?></span></button>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<p class="cb-core-integrity-muted"><?php echo esc_html__( 'Quarantine is intentionally finding-driven; Core Scanner does not expose a general-purpose web file manager.', 'core-blueprint' ); ?></p>
			<?php if ( $can_manage_policy ) : ?>
				<template id="cb-integrity-quarantine-restore-acknowledgement-template">
					<?php MutationAcknowledgement::render(
						'quarantine_restore_acknowledgement',
						'cb-integrity-quarantine-restore-acknowledgement',
						__( "I understand that restoring this quarantined item changes the site's active filesystem and that I am responsible for having a recent backup or other recovery option available.", 'core-blueprint' ),
						__( 'The restore is refused if the original path is occupied or the quarantined payload no longer matches its evidence.', 'core-blueprint' )
					); ?>
				</template>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Map Scanner workflow/severity state to the shared StateBadge semantic palette.
	 */

	private static function state_badge_variant( string $status ): string {
		return match ( sanitize_key( $status ) ) {
			'ok', 'complete', 'completed', 'done', 'passed', 'reviewed', 'restored', 'deleted' => StateBadge::SUCCESS,
			'notice', 'info', 'pending', 'running' => StateBadge::INFO,
			'warning', 'needs_baseline', 'baseline_required', 'awaiting_review', 'keep_quarantined', 'marked_for_deletion',
			'incomplete', 'scan_incomplete', 'distribution_drift', 'modified', 'missing', 'changed', 'new', 'unexpected',
			'unexpected_unverified', 'unexpected_root_executable', 'unreadable', 'unreadable_unexpected', 'symlink_skipped',
			'verification_failed', 'unsupported', 'executable_upload' => StateBadge::WARNING,
			'critical', 'restoring', 'deleting' => StateBadge::DANGER,
			'failed', 'error', 'restore_failed', 'delete_failed' => StateBadge::ERROR,
			default => StateBadge::NEUTRAL,
		};
	}

	private static function quarantine_status_label( string $status ): string {
		return match ( $status ) {
			'awaiting_review'      => __( 'Awaiting review', 'core-blueprint' ),
			'reviewed'             => __( 'Reviewed', 'core-blueprint' ),
			'keep_quarantined'     => __( 'Keep quarantined', 'core-blueprint' ),
			'marked_for_deletion'  => __( 'Marked for deletion', 'core-blueprint' ),
			'restoring'            => __( 'Restore needs attention', 'core-blueprint' ),
			'restore_failed'       => __( 'Restore failed', 'core-blueprint' ),
			'restored'             => __( 'Restored', 'core-blueprint' ),
			'deleting'             => __( 'Deletion needs attention', 'core-blueprint' ),
			'delete_failed'        => __( 'Deletion failed', 'core-blueprint' ),
			'deleted'              => __( 'Deleted', 'core-blueprint' ),
			default                => ucwords( str_replace( '_', ' ', $status ) ),
		};
	}

	private static function quarantine_kind_label( string $kind ): string {
		return 'directory' === $kind ? __( 'Directory', 'core-blueprint' ) : __( 'File', 'core-blueprint' );
	}

	/**
	 * Format an ISO/UTC finding timestamp in the WordPress site timezone.
	 */

}
