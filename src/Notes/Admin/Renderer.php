<?php
declare(strict_types=1);
namespace CoreBlueprint\Core\Notes\Admin;

use CoreBlueprint\Core\Notes\MarkdownRenderer;
use CoreBlueprint\Core\Notes\Repository;
use CoreBlueprint\Core\Notes\Settings\SettingsRepository;
use CoreBlueprint\Core\Notes\Support\MarkdownPreview;
use CoreBlueprint\Core\UI\Icon;
use CoreBlueprint\Core\UI\StateBadge;

defined( 'ABSPATH' ) || exit;

final class Renderer {
    public static function filters_from_request( array $source ): array {
        return [
            'status'   => isset( $source['status'] ) ? sanitize_text_field( wp_unslash( $source['status'] ) ) : 'all',
            'type'     => isset( $source['type'] ) ? sanitize_text_field( wp_unslash( $source['type'] ) ) : 'all',
            'assigned' => isset( $source['assigned'] ) ? sanitize_text_field( wp_unslash( $source['assigned'] ) ) : 'all',
            'sort'     => isset( $source['sort'] ) ? sanitize_key( wp_unslash( $source['sort'] ) ) : 'updated',
            'per_page' => isset( $source['per_page'] ) ? Repository::sanitize_per_page( (int) $source['per_page'] ) : 20,
            'paged'    => isset( $source['paged'] ) ? max( 1, (int) $source['paged'] ) : 1,
            'search'   => isset( $source['search'] ) ? sanitize_text_field( wp_unslash( $source['search'] ) ) : '',
            'tag'      => isset( $source['tag'] ) ? sanitize_text_field( wp_unslash( $source['tag'] ) ) : '',
        ];
    }

    public static function result_html( array $result, array $filters, array $users ): string {
        ob_start();
        ?>
        <?php
        $has_active_filters =
            ! empty( $filters['search'] )
            || ! empty( $filters['tag'] )
            || ( isset( $filters['status'] ) && 'all' !== $filters['status'] )
            || ( isset( $filters['type'] ) && 'all' !== $filters['type'] )
            || ( isset( $filters['assigned'] ) && 'all' !== $filters['assigned'] )
            || ( isset( $filters['sort'] ) && 'updated' !== $filters['sort'] )
            || ( isset( $filters['per_page'] ) && 20 !== (int) $filters['per_page'] );
        ?>

        <div class="cb-notes-results-toolbar" data-cb-notes-results-toolbar>
            <div class="cb-notes-results-toolbar__summary">
                <strong>
                    <?php
                    printf(
                        /* translators: %d: number of note search results. */
                        esc_html( _n( '%d result', '%d results', (int) $result['total'], 'core-blueprint' ) ),
                        (int) $result['total']
                    );
                    ?>
                </strong>

                <?php if ( ! empty( $filters['tag'] ) ) : ?>
                    <span class="cb-core-badge cb-core-badge-neutral cb-notes-active-tag"><?php esc_html_e( 'Tag:', 'core-blueprint' ); ?> <?php echo esc_html( $filters['tag'] ); ?></span>
                <?php endif; ?>

                <?php if ( $has_active_filters ) : ?>
                    <a class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-notes-ajax-link" href="<?php echo esc_url( admin_url( 'admin.php?page=core-blueprint-notes' ) ); ?>">
                        <?php esc_html_e( 'Reset filters', 'core-blueprint' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="cb-notes-results-toolbar__controls">
                <div class="cb-view-switcher cb-action-group" role="group" aria-label="<?php esc_attr_e( 'Notes layout', 'core-blueprint' ); ?>">
                    <button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only cb-view-switcher__button" data-cb-notes-layout="list" aria-label="<?php esc_attr_e( 'List layout', 'core-blueprint' ); ?>">
                        <?php echo Icon::render( 'layout-list', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </button>
                    <button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only cb-view-switcher__button" data-cb-notes-layout="grid-2" aria-label="<?php esc_attr_e( 'Grid layout, 2 columns', 'core-blueprint' ); ?>">
                        <?php echo Icon::render( 'layout-grid', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="cb-view-switcher__label">2</span>
                    </button>
                    <button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only cb-view-switcher__button" data-cb-notes-layout="grid-3" aria-label="<?php esc_attr_e( 'Grid layout, 3 columns', 'core-blueprint' ); ?>">
                        <?php echo Icon::render( 'layout-grid', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="cb-view-switcher__label">3</span>
                    </button>
                </div>

                <?php self::pagination( $filters, $result, 'top' ); ?>
            </div>
        </div>

        <?php if ( ! empty( $result['items'] ) ) : ?>
            <div class="cb-notes-bulk-bar" data-cb-notes-bulk-bar hidden>
                <div class="cb-notes-bulk-bar__summary">
                    <strong data-cb-notes-selected-summary>
                        <?php
                        printf(
                            /* translators: %d: number of selected notes; initialized to zero and updated by the interface. */
                            esc_html( _n( '%d note selected.', '%d notes selected.', 0, 'core-blueprint' ) ),
                            0
                        );
                        ?>
                    </strong>
                    <button type="button" class="button-link cb-notes-select-visible" data-cb-notes-select-visible>
                        <?php
                        printf(
                            /* translators: %d: number of notes currently visible in the list. */
                            esc_html__( 'Select all %d visible notes', 'core-blueprint' ),
                            count( $result['items'] )
                        );
                        ?>
                    </button>
                    <button type="button" class="button-link cb-notes-clear-selection" data-cb-notes-clear-selection>
                        <?php esc_html_e( 'Clear selection', 'core-blueprint' ); ?>
                    </button>
                </div>
                <div class="cb-notes-bulk-bar__actions">
                    <button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact" data-cb-notes-export-selected>
                        <?php esc_html_e( 'Export selected', 'core-blueprint' ); ?>
                    </button>
                    <button type="button" class="button cb-core-button cb-core-button--danger cb-core-button--compact" data-cb-notes-bulk-delete>
                        <?php esc_html_e( 'Delete selected', 'core-blueprint' ); ?>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <div class="cb-notes-list" data-cb-notes-list>

            <?php if ( empty( $result['items'] ) ) : ?>
                <div class="cb-core-empty cb-notes-empty">
                    <strong><?php esc_html_e( 'No notes found.', 'core-blueprint' ); ?></strong>
                    <p><?php esc_html_e( 'Adjust your filters or create a new note.', 'core-blueprint' ); ?></p>
                </div>
            <?php else : ?>
                <?php foreach ( $result['items'] as $note ) : ?>
                    <?php self::note_card( $note, $users, $filters ); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php self::pagination( $filters, $result, 'bottom' ); ?>

        <?php
        return (string) ob_get_clean();
    }

    public static function note_card( object $note, array $users, array $filters = [] ): void {
        $author   = get_userdata( (int) $note->created_by );
        $editor   = get_userdata( (int) $note->updated_by );
        $assigned = ! empty( $note->assigned_to ) ? get_userdata( (int) $note->assigned_to ) : null;
        $search   = isset( $filters['search'] ) ? (string) $filters['search'] : '';
        $note_id  = (string) $note->id;
        $preview  = MarkdownPreview::to_plain_text( (string) $note->content, 26 );
        $tags     = ! empty( $note->tags ) ? array_filter( array_map( 'trim', explode( ',', (string) $note->tags ) ) ) : [];
        ?>
        <details class="cb-notes-card cb-core-card cb-notes-status-<?php echo esc_attr( strtolower( $note->status ) ); ?>">
            <summary class="cb-notes-card__summary">
                <span class="cb-notes-card__topline">
                    <span class="cb-notes-card__identity">
                        <label class="cb-notes-select-note" title="<?php esc_attr_e( 'Select note', 'core-blueprint' ); ?>">
                            <input type="checkbox" value="<?php echo esc_attr( $note_id ); ?>" data-cb-notes-select-note aria-label="<?php esc_attr_e( 'Select note', 'core-blueprint' ); ?>" />
                        </label>
                        <span class="cb-notes-card__title-group">
                            <span class="cb-notes-title"><?php echo wp_kses_post( MarkdownRenderer::highlight( esc_html( (string) $note->title ), $search ) ); ?></span>
                            <span class="cb-notes-card__badges" aria-label="<?php esc_attr_e( 'Note labels', 'core-blueprint' ); ?>">
                                <?php
                                $status_variant = match ( (string) $note->status ) {
                                    'Important' => StateBadge::WARNING,
                                    'Open'      => StateBadge::INFO,
                                    default     => StateBadge::NEUTRAL,
                                };
                                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload.
                                echo StateBadge::render( (string) $note->status, [ 'variant' => $status_variant ] );
                                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                                ?>
                                <span class="cb-core-badge cb-core-badge-neutral"><?php echo esc_html( $note->type ); ?></span>
                            </span>
                        </span>
                    </span>

                    <span class="cb-notes-card__icon-actions" aria-label="<?php esc_attr_e( 'Note actions', 'core-blueprint' ); ?>">
                        <button type="button" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only cb-notes-open-edit" data-cb-notes-template="cb-notes-edit-template-<?php echo esc_attr( $note_id ); ?>" data-cb-notes-modal-title="<?php esc_attr_e( 'Edit Note', 'core-blueprint' ); ?>" title="<?php esc_attr_e( 'Edit note', 'core-blueprint' ); ?>" aria-label="<?php esc_attr_e( 'Edit note', 'core-blueprint' ); ?>">
                            <?php echo Icon::render( 'edit', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </button>
                        <form method="post" class="cb-notes-action-form cb-notes-icon-form">
                                    <?php wp_nonce_field( 'cb_notes_action', 'cb_notes_nonce' ); ?>
                                    <input type="hidden" name="cb_notes_action" value="duplicate" />
                            <input type="hidden" name="note_id" value="<?php echo esc_attr( $note_id ); ?>" />
                            <button type="submit" class="button cb-core-button cb-core-button--secondary cb-core-button--compact cb-core-button--icon-only" title="<?php esc_attr_e( 'Duplicate note', 'core-blueprint' ); ?>" aria-label="<?php esc_attr_e( 'Duplicate note', 'core-blueprint' ); ?>">
                                <?php echo Icon::render( 'copy', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </button>
                        </form>
                        <form method="post" class="cb-notes-action-form cb-notes-delete-form cb-notes-icon-form" data-confirm="<?php esc_attr_e( 'Delete this note permanently? This cannot be undone.', 'core-blueprint' ); ?>">
                                    <?php wp_nonce_field( 'cb_notes_action', 'cb_notes_nonce' ); ?>
                                    <input type="hidden" name="cb_notes_action" value="delete" />
                            <input type="hidden" name="note_id" value="<?php echo esc_attr( $note_id ); ?>" />
                            <button type="submit" class="button cb-core-button cb-core-button--danger cb-core-button--compact cb-core-button--icon-only" title="<?php esc_attr_e( 'Delete note', 'core-blueprint' ); ?>" aria-label="<?php esc_attr_e( 'Delete note', 'core-blueprint' ); ?>">
                                <?php echo Icon::render( 'delete', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </button>
                        </form>
                    </span>
                </span>

                <span class="cb-notes-card__preview"><?php echo wp_kses_post( MarkdownRenderer::highlight( esc_html( $preview ), $search ) ); ?></span>

                <span class="cb-notes-card__footer" aria-label="<?php esc_attr_e( 'Note metadata', 'core-blueprint' ); ?>">
                    <span class="cb-notes-meta-item">
                        <?php echo Icon::render( 'meta-author', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <span><?php echo esc_html( $author ? $author->display_name : '-' ); ?></span>
                    </span>
                    <span class="cb-notes-meta-item">
                        <?php echo Icon::render( 'meta-assigned', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <span><?php echo esc_html( $assigned ? $assigned->display_name : 'Unassigned' ); ?></span>
                    </span>
                    <span class="cb-notes-meta-item">
                        <?php echo Icon::render( 'meta-updated', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <time datetime="<?php echo esc_attr( (string) $note->updated_at ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $note->updated_at ) ); ?></time>
                    </span>
                    <?php if ( ! empty( $tags ) ) : ?>
                        <span class="cb-notes-meta-item cb-notes-card__tag-summary">
                            <?php echo Icon::render( 'meta-tags', [ 'size' => Icon::SIZE_COMPACT ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <span>
                                <?php
                                printf(
                                    /* translators: %d: number of tags on the note. */
                                    esc_html( _n( '%d tag', '%d tags', count( $tags ), 'core-blueprint' ) ),
                                    count( $tags )
                                );
                                ?>
                            </span>
                        </span>
                    <?php endif; ?>
                </span>
            </summary>

            <div class="cb-notes-card__body">
                <div class="cb-notes-rendered">
                    <?php echo MarkdownRenderer::highlight( MarkdownRenderer::render( (string) $note->content ), $search ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>

                <?php if ( ! empty( $tags ) ) : ?>
                    <div class="cb-notes-tags">
                        <?php foreach ( $tags as $tag ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'core-blueprint-notes', 'tag' => $tag ], admin_url( 'admin.php' ) ) ); ?>" class="cb-core-badge cb-core-badge-neutral cb-notes-tag cb-notes-ajax-link"><?php echo esc_html( $tag ); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="cb-notes-quick-actions cb-core-actions">
                    <?php foreach ( [ 'Important', 'Open', 'Backlog', 'Archived' ] as $status ) : ?>
                        <?php if ( $status === (string) $note->status ) : ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <form method="post" class="cb-notes-action-form">
                            <?php wp_nonce_field( 'cb_notes_action', 'cb_notes_nonce' ); ?>
                            <input type="hidden" name="cb_notes_action" value="quick_status" />
                            <input type="hidden" name="note_id" value="<?php echo esc_attr( $note_id ); ?>" />
                            <input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
                            <button type="submit" class="button cb-core-button cb-core-button--secondary cb-core-button--compact">
                                <?php echo esc_html( $status ); ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>

                <p class="cb-notes-footnote">
                    <?php
                    printf(
                        /* translators: 1: note author display name, 2: last editor display name. */
                        esc_html__( 'Created by %1$s. Last edited by %2$s.', 'core-blueprint' ),
                        esc_html( $author ? $author->display_name : '-' ),
                        esc_html( $editor ? $editor->display_name : '-' )
                    );
                    ?>
                </p>
            </div>
        </details>

        <template id="cb-notes-edit-template-<?php echo esc_attr( $note_id ); ?>">
            <div class="cb-notes-modal-content" data-cb-notes-form-content>
                <input type="hidden" name="cb_notes_action" value="update" />
                <input type="hidden" name="note_id" value="<?php echo esc_attr( $note_id ); ?>" />
                <?php self::form_fields( $note, $users, false ); ?>
            </div>
        </template>
        <?php
    }

    public static function form_fields( ?object $note, array $users, bool $is_new ): void {
        $settings    = SettingsRepository::all();
        $title       = $note ? (string) $note->title : '';
        $content     = $note ? (string) $note->content : '';
        $type        = $note ? (string) $note->type : (string) $settings['default_type'];
        $status      = $note ? (string) $note->status : (string) $settings['default_status'];
        $tags        = $note ? (string) $note->tags : '';
        $created_by  = $note ? (int) $note->created_by : get_current_user_id();
        $assigned_to = $note ? (int) $note->assigned_to : (int) $settings['default_assigned_to'];
        $field_key   = $note ? 'edit-' . (int) $note->id : 'create';

        if ( -1 === $assigned_to ) {
            $assigned_to = get_current_user_id();
        }
        ?>
        <input type="hidden" name="content_format" value="markdown" />
        <div class="cb-notes-flow">
            <div class="cb-core-field cb-notes-field--title">
                <label class="cb-core-field__label" for="cb-notes-title-<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Title', 'core-blueprint' ); ?></label>
                <input id="cb-notes-title-<?php echo esc_attr( $field_key ); ?>" type="text" name="title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'What is this note about?', 'core-blueprint' ); ?>" required />
            </div>
            <div class="cb-core-field cb-notes-field--content">
                <label class="cb-core-field__label" for="cb-notes-content-<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Note', 'core-blueprint' ); ?></label>
                <textarea id="cb-notes-content-<?php echo esc_attr( $field_key ); ?>" name="content" rows="<?php echo $is_new ? '8' : '12'; ?>" placeholder="<?php esc_attr_e( 'Write the note first. Details can be added below when needed.', 'core-blueprint' ); ?>"><?php echo esc_textarea( $content ); ?></textarea>
            </div>
            <details class="cb-core-disclosure cb-core-disclosure--compact cb-notes-details" data-cb-notes-details>
                <summary class="cb-core-disclosure__summary">
                    <?php echo Icon::render( 'expand', [ 'size' => Icon::SIZE_COMPACT, 'class' => 'cb-core-disclosure__icon' ] ); ?> <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Proven-safe Core Blueprint UI renderer owns context-specific escaping for its complete public payload. ?>
                    <span class="cb-core-disclosure__title"><?php esc_html_e( 'Details', 'core-blueprint' ); ?></span>
                </summary>
                <div class="cb-core-disclosure__body cb-notes-details__grid">
                    <div class="cb-core-field"><label class="cb-core-field__label" for="cb-notes-type-<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Type', 'core-blueprint' ); ?></label><select id="cb-notes-type-<?php echo esc_attr( $field_key ); ?>" name="type"><?php foreach ( Repository::allowed_types() as $allowed_type ) : ?><option value="<?php echo esc_attr( $allowed_type ); ?>" <?php selected( $type, $allowed_type ); ?>><?php echo esc_html( $allowed_type ); ?></option><?php endforeach; ?></select></div>
                    <div class="cb-core-field"><label class="cb-core-field__label" for="cb-notes-status-<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Status', 'core-blueprint' ); ?></label><select id="cb-notes-status-<?php echo esc_attr( $field_key ); ?>" name="status"><?php foreach ( Repository::allowed_statuses() as $allowed_status ) : ?><option value="<?php echo esc_attr( $allowed_status ); ?>" <?php selected( $status, $allowed_status ); ?>><?php echo esc_html( $allowed_status ); ?></option><?php endforeach; ?></select></div>
                    <div class="cb-core-field"><label class="cb-core-field__label" for="cb-notes-author-<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Author', 'core-blueprint' ); ?></label><select id="cb-notes-author-<?php echo esc_attr( $field_key ); ?>" name="created_by"><?php foreach ( $users as $user ) : ?><option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $created_by, (int) $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option><?php endforeach; ?></select></div>
                    <div class="cb-core-field"><label class="cb-core-field__label" for="cb-notes-assigned-<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Assigned to', 'core-blueprint' ); ?></label><select id="cb-notes-assigned-<?php echo esc_attr( $field_key ); ?>" name="assigned_to"><option value="0" <?php selected( $assigned_to, 0 ); ?>><?php esc_html_e( 'Unassigned', 'core-blueprint' ); ?></option><?php foreach ( $users as $user ) : ?><option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $assigned_to, (int) $user->ID ); ?>><?php echo esc_html( $user->display_name ); ?></option><?php endforeach; ?></select></div>
                    <div class="cb-core-field cb-notes-full"><label class="cb-core-field__label" for="cb-notes-tags-<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Tags', 'core-blueprint' ); ?></label><input id="cb-notes-tags-<?php echo esc_attr( $field_key ); ?>" type="text" name="tags" value="<?php echo esc_attr( $tags ); ?>" placeholder="<?php esc_attr_e( 'maintenance, security, handover', 'core-blueprint' ); ?>" /></div>
                </div>
            </details>
        </div>
        <?php
    }

    public static function pagination( array $filters, array $result, string $placement = 'bottom' ): void {
        if ( $result['total_pages'] <= 1 ) {
            return;
        }

        echo '<nav class="cb-pagination cb-pagination--' . esc_attr( $placement ) . '" aria-label="' . esc_attr__( 'Notes pagination', 'core-blueprint' ) . '"><div class="tablenav-pages">';
        for ( $i = 1; $i <= $result['total_pages']; $i++ ) {
            $url = add_query_arg( array_merge( $filters, [ 'page' => 'core-blueprint-notes', 'paged' => $i ] ), admin_url( 'admin.php' ) );
            echo ( (int) $result['page'] === $i )
                ? '<span class="page-numbers current">' . esc_html( (string) $i ) . '</span> '
                : '<a class="page-numbers cb-notes-ajax-link" href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
        }
        echo '</div></nav>';
    }
}
