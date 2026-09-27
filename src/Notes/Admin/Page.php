<?php
declare(strict_types=1);
/**
 * Notes - top-level admin page.
 *
 * Position 22 places Notes between Logs (20) and Reports (25) in the
 * Core Blueprint submenu - the operator's natural workflow:
 * audit (Logs) → site memory (Notes) → client deliverable (Reports).
 *
 * Capability is `cb_manage_notes`. Both administrators and cb_operator
 * inherit it on activation; there is no view/manage split - anyone who
 * can reach this page can also create, edit, delete, and bulk-act on
 * notes. The audit log records who did what for accountability.
 *
 * Asset enqueue (script module + stylesheet) lives in the central
 * {@see \CB\Core\Admin\Admin::enqueue_assets()} alongside every other
 * CB Base module - single enqueue surface, single registration point.
 *
 * @package Core_Blueprint
 * @since   1.0.0
 */

namespace CB\Core\Notes\Admin;

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\Admin\PageBase;
use CB\Core\UI\Icon;
use CB\Core\UI\Notice;
use CB\Core\Notes\Repository;
use CB\Core\Notes\Support\Audit;

defined( 'ABSPATH' ) || exit;

final class Page extends PageBase {

	public function slug(): string { return 'core-blueprint-notes'; }
	public function title(): string { return __( 'Notes', 'core-blueprint' ); }

	public function position(): ?int {
		return 22; // Between Logs (20) and Reports (25).
	}

	public function capability(): string {
		return 'cb_manage_notes';
	}

	public function render(): void {
		$this->guard();
		$notice = $this->handle_actions();

		$filters = Renderer::filters_from_request( $_GET );
		$result  = Repository::query( $filters );
		$users   = get_users( [ 'fields' => [ 'ID', 'display_name' ] ] );
		?>
		<div class="wrap cb-core-wrap cb-notes-wrap">
			<div class="cb-notes-page-header">
				<div class="cb-notes-page-header__intro">
					<h1 class="cb-core-title"><?php esc_html_e( 'Notes', 'core-blueprint' ); ?></h1>
					<p class="cb-core-intro"><?php esc_html_e( 'Site-specific notes for maintenance, security context, and operational handover. Markdown supported. Visible to every administrator and operator on this site.', 'core-blueprint' ); ?></p>
				</div>
				<div class="cb-notes-page-actions">
					<button type="button" class="button cb-core-button cb-core-button--primary cb-notes-open-create" data-cb-notes-modal-title="<?php esc_attr_e( 'New Note', 'core-blueprint' ); ?>">
						<?php echo Icon::render( 'add', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="cb-core-button__label"><?php esc_html_e( 'New Note', 'core-blueprint' ); ?></span>
					</button>
					<div class="cb-notes-actions-menu" data-cb-notes-actions-menu>
						<button type="button"
							class="button cb-core-button cb-core-button--secondary cb-notes-actions-menu__trigger"
							data-cb-notes-actions-menu-trigger
							aria-haspopup="menu"
							aria-expanded="false"
							aria-controls="cb-notes-actions-menu-panel">
							<?php esc_html_e( 'Actions', 'core-blueprint' ); ?>
							<?php echo Icon::render( 'chevron-down', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-notes-actions-menu__caret' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
						<ul id="cb-notes-actions-menu-panel"
							class="cb-notes-actions-menu__panel"
							data-cb-notes-actions-menu-panel
							role="menu"
							hidden>
							<li role="none">
								<button type="button" class="cb-notes-actions-menu__item" role="menuitem" data-cb-notes-import-json>
									<?php echo Icon::render( 'import', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-notes-actions-menu__icon' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span><?php esc_html_e( 'Import JSON', 'core-blueprint' ); ?></span>
								</button>
							</li>
							<li role="none">
								<button type="button" class="cb-notes-actions-menu__item" role="menuitem" data-cb-notes-export-all>
									<?php echo Icon::render( 'export', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-notes-actions-menu__icon' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span><?php esc_html_e( 'Export all JSON', 'core-blueprint' ); ?></span>
								</button>
							</li>
							<li role="none">
								<a class="cb-notes-actions-menu__item" role="menuitem" href="<?php echo esc_url( admin_url( 'admin.php?page=core-blueprint-preferences&tab=notes' ) ); ?>">
									<?php echo Icon::render( 'settings', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-notes-actions-menu__icon' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span><?php esc_html_e( 'Notes preferences', 'core-blueprint' ); ?></span>
								</a>
							</li>
							<li class="cb-notes-actions-menu__separator" role="separator"></li>
							<li role="none">
								<button type="button" class="cb-notes-actions-menu__item cb-notes-actions-menu__item--danger cb-notes-delete-all-trigger" role="menuitem" data-cb-notes-delete-all-trigger>
									<?php echo Icon::render( 'delete', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-notes-actions-menu__icon' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span><?php esc_html_e( 'Delete all notes', 'core-blueprint' ); ?></span>
								</button>
							</li>
						</ul>
					</div>
				</div>
			</div>

			<?php if ( $notice ) : ?>
				<?php
				$notice_variant = 'success' === $notice['type'] ? Notice::SUCCESS : Notice::ERROR;
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() returns escape-clean HTML.
				echo Notice::render( [ 'variant' => $notice_variant, 'message' => (string) $notice['message'], 'class' => 'cb-notes-page-notice' ] );
				?>
			<?php endif; ?>

            <template id="cb-notes-create-template">
                <div class="cb-notes-modal-content" data-cb-notes-form-content>
                    <input type="hidden" name="cb_notes_action" value="create" />
                    <?php Renderer::form_fields( null, $users, true ); ?>
                </div>
            </template>
            <template id="cb-notes-import-overwrite-acknowledgement-template">
                <?php MutationAcknowledgement::render(
                    'notes_import_overwrite_acknowledgement',
                    'cb-notes-import-overwrite-acknowledgement',
                    __( 'I understand that this import will overwrite existing Notes and that I am responsible for having a recent backup or other recovery option available.', 'core-blueprint' ),
                    __( 'Only Notes explicitly set to Overwrite existing are replaced.', 'core-blueprint' )
                ); ?>
            </template>
            <?php $this->render_filters( $filters, $users ); ?>

            <div id="cb-notes-results">
                <?php echo Renderer::result_html( $result, $filters, $users ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </div>
        <?php
    }

    private function handle_actions(): ?array {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['cb_notes_action'] ) ) {
            return null;
        }

        check_admin_referer( 'cb_notes_action', 'cb_notes_nonce' );

        $action = sanitize_key( wp_unslash( $_POST['cb_notes_action'] ) );
        $id     = isset( $_POST['note_id'] ) ? (int) $_POST['note_id'] : 0;

        if ( 'create' === $action ) {
            if ( ! Repository::create( $this->posted_note_data() ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note could not be created.', 'core-blueprint' ) ];
            }
            Audit::log( 'note_created', [ 'user_id' => get_current_user_id() ] );
            return [ 'type' => 'success', 'message' => __( 'Note created.', 'core-blueprint' ) ];
        }

        if ( 'update' === $action && $id > 0 ) {
            if ( ! Repository::find( $id ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note not found.', 'core-blueprint' ) ];
            }
            if ( ! Repository::update( $id, $this->posted_note_data() ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note could not be updated.', 'core-blueprint' ) ];
            }
            Audit::log( 'note_updated', [ 'note_id' => $id, 'user_id' => get_current_user_id() ] );
            return [ 'type' => 'success', 'message' => __( 'Note updated.', 'core-blueprint' ) ];
        }

        if ( 'quick_status' === $action && $id > 0 ) {
            $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'Backlog';
            if ( ! Repository::find( $id ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note not found.', 'core-blueprint' ) ];
            }
            if ( ! Repository::quick_status( $id, $status ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note status could not be updated.', 'core-blueprint' ) ];
            }
            Audit::log( 'note_status_changed', [ 'note_id' => $id, 'status' => Repository::sanitize_status( $status ), 'user_id' => get_current_user_id() ] );
            return [ 'type' => 'success', 'message' => __( 'Note status updated.', 'core-blueprint' ) ];
        }

        if ( 'duplicate' === $action && $id > 0 ) {
            if ( ! Repository::find( $id ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note not found.', 'core-blueprint' ) ];
            }
            $new_id = Repository::duplicate( $id );

            if ( $new_id <= 0 ) {
                return [ 'type' => 'error', 'message' => __( 'Note could not be duplicated.', 'core-blueprint' ) ];
            }

            Audit::log( 'note_duplicated', [ 'note_id' => $id, 'new_note_id' => $new_id, 'user_id' => get_current_user_id() ] );
            return [ 'type' => 'success', 'message' => __( 'Note duplicated.', 'core-blueprint' ) ];
        }

        if ( 'archive' === $action && $id > 0 ) {
            if ( ! Repository::find( $id ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note not found.', 'core-blueprint' ) ];
            }
            if ( ! Repository::archive( $id ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note could not be archived.', 'core-blueprint' ) ];
            }
            Audit::log( 'note_archived', [ 'note_id' => $id, 'user_id' => get_current_user_id() ] );
            return [ 'type' => 'success', 'message' => __( 'Note archived.', 'core-blueprint' ) ];
        }

        if ( 'delete_all' === $action ) {
            $confirm_phrase = 'DELETE ALL NOTES';
            $typed = isset( $_POST['confirm'] ) ? (string) wp_unslash( $_POST['confirm'] ) : '';

            if ( $typed !== $confirm_phrase ) {
                return [ 'type' => 'error', 'message' => __( 'Confirmation phrase did not match.', 'core-blueprint' ) ];
            }

            $deleted = Repository::delete_all();
            if ( false === $deleted ) {
                return [ 'type' => 'error', 'message' => __( 'Notes could not be deleted.', 'core-blueprint' ) ];
            }
            Audit::log( 'notes_bulk_deleted', [ 'count' => $deleted, 'user_id' => get_current_user_id() ] );
            return [ 'type' => 'success', 'message' => __( 'All notes deleted.', 'core-blueprint' ) ];
        }

        if ( 'delete' === $action && $id > 0 ) {
            if ( ! Repository::find( $id ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note not found.', 'core-blueprint' ) ];
            }
            if ( ! Repository::delete( $id ) ) {
                return [ 'type' => 'error', 'message' => __( 'Note could not be deleted.', 'core-blueprint' ) ];
            }
            Audit::log( 'note_deleted', [ 'note_id' => $id, 'user_id' => get_current_user_id() ] );
            return [ 'type' => 'success', 'message' => __( 'Note deleted.', 'core-blueprint' ) ];
        }

        return [ 'type' => 'error', 'message' => __( 'Unknown notes action.', 'core-blueprint' ) ];
    }

    private function posted_note_data(): array {
        return [
            'title'          => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
            'content'        => isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '',
            'content_format' => isset( $_POST['content_format'] ) ? sanitize_key( wp_unslash( $_POST['content_format'] ) ) : 'markdown',
            'type'           => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'General',
            'status'         => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'Backlog',
            'tags'           => isset( $_POST['tags'] ) ? wp_unslash( $_POST['tags'] ) : '',
            'created_by'     => isset( $_POST['created_by'] ) ? (int) $_POST['created_by'] : get_current_user_id(),
            'assigned_to'    => isset( $_POST['assigned_to'] ) ? (int) $_POST['assigned_to'] : 0,
        ];
    }

    private function render_filters( array $filters, array $users ): void {
        ?>
        <form method="get" class="cb-notes-filters" id="cb-notes-filters">
            <input type="hidden" name="page" value="core-blueprint-notes" />
            <label class="cb-core-field cb-notes-filter cb-notes-filter--search"><span class="cb-core-field__label"><?php esc_html_e( 'Search', 'core-blueprint' ); ?></span><input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" /></label>
            <label class="cb-core-field cb-notes-filter"><span class="cb-core-field__label"><?php esc_html_e( 'Status', 'core-blueprint' ); ?></span><select name="status"><option value="all"><?php esc_html_e( 'All', 'core-blueprint' ); ?></option><?php foreach ( Repository::allowed_statuses() as $status ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( $status ); ?></option><?php endforeach; ?></select></label>
            <label class="cb-core-field cb-notes-filter"><span class="cb-core-field__label"><?php esc_html_e( 'Type', 'core-blueprint' ); ?></span><select name="type"><option value="all"><?php esc_html_e( 'All', 'core-blueprint' ); ?></option><?php foreach ( Repository::allowed_types() as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['type'], $type ); ?>><?php echo esc_html( $type ); ?></option><?php endforeach; ?></select></label>
            <label class="cb-core-field cb-notes-filter cb-notes-filter--assigned"><span class="cb-core-field__label"><?php esc_html_e( 'Assigned', 'core-blueprint' ); ?></span><select name="assigned"><option value="all"><?php esc_html_e( 'All', 'core-blueprint' ); ?></option><option value="me" <?php selected( $filters['assigned'], 'me' ); ?>><?php esc_html_e( 'Me', 'core-blueprint' ); ?></option><option value="unassigned" <?php selected( $filters['assigned'], 'unassigned' ); ?>><?php esc_html_e( 'Unassigned', 'core-blueprint' ); ?></option><?php foreach ( $users as $user ) : ?><option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $filters['assigned'], (string) $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option><?php endforeach; ?></select></label>
            <label class="cb-core-field cb-notes-filter"><span class="cb-core-field__label"><?php esc_html_e( 'Sort', 'core-blueprint' ); ?></span><select name="sort"><option value="updated" <?php selected( $filters['sort'], 'updated' ); ?>><?php esc_html_e( 'Updated', 'core-blueprint' ); ?></option><option value="created" <?php selected( $filters['sort'], 'created' ); ?>><?php esc_html_e( 'Created', 'core-blueprint' ); ?></option><option value="title" <?php selected( $filters['sort'], 'title' ); ?>><?php esc_html_e( 'Title', 'core-blueprint' ); ?></option><option value="author" <?php selected( $filters['sort'], 'author' ); ?>><?php esc_html_e( 'Author', 'core-blueprint' ); ?></option></select></label>
            <label class="cb-core-field cb-notes-filter cb-notes-filter--per-page"><span class="cb-core-field__label"><?php esc_html_e( 'Per page', 'core-blueprint' ); ?></span><select name="per_page"><?php foreach ( Repository::allowed_per_page() as $per_page ) : ?><option value="<?php echo esc_attr( (string) $per_page ); ?>" <?php selected( (int) $filters['per_page'], $per_page ); ?>><?php echo esc_html( (string) $per_page ); ?></option><?php endforeach; ?></select></label>
            <a class="button cb-core-button cb-core-button--secondary cb-notes-ajax-link" href="<?php echo esc_url( admin_url( 'admin.php?page=core-blueprint-notes&status=Important' ) ); ?>"><?php esc_html_e( 'Only Important', 'core-blueprint' ); ?></a>
        </form>
        <?php
    }}
