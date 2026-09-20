<?php
declare(strict_types=1);
namespace CB\Core\Notes\Rest;

use CB\Core\Admin\MutationAcknowledgement;
use CB\Core\Notes\Admin\Renderer;
use CB\Core\Notes\Repository;
use CB\Core\Notes\State;
use CB\Core\Notes\Support\Audit;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class NotesController {
    public static function register(): void {
        register_rest_route( 'core-blueprint/v1', '/notes/list',   [ 'methods' => 'GET',  'callback' => [ self::class, 'list'    ], 'permission_callback' => [ self::class, 'can_manage' ] ] );
        register_rest_route( 'core-blueprint/v1', '/notes/action', [ 'methods' => 'POST', 'callback' => [ self::class, 'action'  ], 'permission_callback' => [ self::class, 'can_manage' ] ] );
    }

    public static function can_manage(): bool {
        return current_user_can( 'cb_manage_notes' );
    }

    /**
     * Standard "subsystem off" response shared by Notes write/read paths.
     * Module activation is owned centrally by Modules\ActivationRegistry.
     */
    private static function subsystem_disabled_response(): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success' => false,
                'code'    => 'cb_notes_subsystem_disabled',
                'message' => __( 'Notes is disabled. Enable it from the Core Blueprint Dashboard.', 'core-blueprint' ),
            ],
            403
        );
    }

    public static function list( WP_REST_Request $request ): WP_REST_Response {
        if ( ! State::is_enabled() ) {
            return self::subsystem_disabled_response();
        }
        return self::response_with_list( Renderer::filters_from_request( $request->get_params() ), __( 'Loaded.', 'core-blueprint' ) );
    }

    public static function action( WP_REST_Request $request ): WP_REST_Response {
        if ( ! State::is_enabled() ) {
            return self::subsystem_disabled_response();
        }

        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Invalid nonce.', 'core-blueprint' ) ], 403 );
        }

        $params  = $request->get_json_params();
        $action  = isset( $params['action'] ) ? sanitize_key( (string) $params['action'] ) : '';
        $payload = isset( $params['payload'] ) && is_array( $params['payload'] ) ? $params['payload'] : [];
        $filters = isset( $params['filters'] ) && is_array( $params['filters'] ) ? Renderer::filters_from_request( $params['filters'] ) : Renderer::filters_from_request( [] );
        $id      = isset( $payload['note_id'] ) ? (int) $payload['note_id'] : 0;

        if ( 'create' === $action ) {
            if ( ! Repository::create( $payload ) ) {
                return self::write_failed_response( __( 'Note could not be created.', 'core-blueprint' ) );
            }
            Audit::log( 'note_created', [ 'user_id' => get_current_user_id() ] );
            return self::response_with_list( $filters, __( 'Note created.', 'core-blueprint' ) );
        }

        if ( 'update' === $action && $id > 0 ) {
            if ( ! Repository::find( $id ) ) {
                return self::note_not_found_response();
            }
            if ( ! Repository::update( $id, $payload ) ) {
                return self::write_failed_response( __( 'Note could not be updated.', 'core-blueprint' ) );
            }
            Audit::log( 'note_updated', [ 'note_id' => $id, 'user_id' => get_current_user_id() ] );
            return self::response_with_list( $filters, __( 'Note updated.', 'core-blueprint' ) );
        }

        if ( 'quick_status' === $action && $id > 0 ) {
            $status = isset( $payload['status'] ) ? (string) $payload['status'] : 'Backlog';
            if ( ! Repository::find( $id ) ) {
                return self::note_not_found_response();
            }
            if ( ! Repository::quick_status( $id, $status ) ) {
                return self::write_failed_response( __( 'Note status could not be updated.', 'core-blueprint' ) );
            }
            Audit::log( 'note_status_changed', [ 'note_id' => $id, 'status' => Repository::sanitize_status( $status ), 'user_id' => get_current_user_id() ] );
            return self::response_with_list( $filters, __( 'Status updated.', 'core-blueprint' ) );
        }

        if ( 'duplicate' === $action && $id > 0 ) {
            if ( ! Repository::find( $id ) ) {
                return self::note_not_found_response();
            }
            $new_id = Repository::duplicate( $id );
            if ( $new_id <= 0 ) {
                return self::write_failed_response( __( 'Note could not be duplicated.', 'core-blueprint' ) );
            }
            Audit::log( 'note_duplicated', [ 'note_id' => $id, 'new_note_id' => $new_id, 'user_id' => get_current_user_id() ] );
            return self::response_with_list( $filters, __( 'Note duplicated.', 'core-blueprint' ) );
        }

        if ( 'archive' === $action && $id > 0 ) {
            if ( ! Repository::find( $id ) ) {
                return self::note_not_found_response();
            }
            if ( ! Repository::archive( $id ) ) {
                return self::write_failed_response( __( 'Note could not be archived.', 'core-blueprint' ) );
            }
            Audit::log( 'note_archived', [ 'note_id' => $id, 'user_id' => get_current_user_id() ] );
            return self::response_with_list( $filters, __( 'Note archived.', 'core-blueprint' ) );
        }

        if ( 'delete_all' === $action ) {
            $confirm_phrase = 'DELETE ALL NOTES';
            $typed = isset( $payload['confirm'] ) ? (string) $payload['confirm'] : '';
            if ( $typed !== $confirm_phrase ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => sprintf( __( 'Confirmation phrase did not match. Type %s exactly to confirm.', 'core-blueprint' ), $confirm_phrase ) ], 400 );
            }
            $deleted = Repository::delete_all();
            if ( false === $deleted ) {
                return self::write_failed_response( __( 'Notes could not be deleted.', 'core-blueprint' ) );
            }
            Audit::log( 'notes_bulk_deleted', [ 'count' => $deleted, 'user_id' => get_current_user_id() ] );
            return self::response_with_list( $filters, sprintf( _n( '%d note deleted.', '%d notes deleted.', $deleted, 'core-blueprint' ), $deleted ) );
        }

        if ( 'bulk_delete' === $action ) {
            $confirm_phrase = 'DELETE';
            $typed = isset( $payload['confirm'] ) ? (string) $payload['confirm'] : '';
            $ids = isset( $payload['ids'] ) && is_array( $payload['ids'] ) ? $payload['ids'] : [];
            if ( $typed !== $confirm_phrase ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => sprintf( __( 'Confirmation phrase did not match. Type %s exactly to confirm.', 'core-blueprint' ), $confirm_phrase ) ], 400 );
            }
            $deleted = Repository::bulk_delete( $ids );
            if ( false === $deleted ) {
                return self::write_failed_response( __( 'Selected notes could not be deleted.', 'core-blueprint' ) );
            }
            Audit::log( 'notes_bulk_deleted', [ 'count' => $deleted, 'ids' => array_map( 'absint', $ids ), 'user_id' => get_current_user_id() ] );
            return self::response_with_list( $filters, sprintf( _n( '%d note deleted.', '%d notes deleted.', $deleted, 'core-blueprint' ), $deleted ) );
        }

        if ( 'delete' === $action && $id > 0 ) {
            if ( ! Repository::find( $id ) ) {
                return self::note_not_found_response();
            }
            if ( ! Repository::delete( $id ) ) {
                return self::write_failed_response( __( 'Note could not be deleted.', 'core-blueprint' ) );
            }
            Audit::log( 'note_deleted', [ 'note_id' => $id, 'user_id' => get_current_user_id() ] );
            return self::response_with_list( $filters, __( 'Note deleted.', 'core-blueprint' ) );
        }

        if ( 'export_json' === $action ) {
            $ids = isset( $payload['ids'] ) && is_array( $payload['ids'] ) ? $payload['ids'] : [];
            $notes = Repository::export_notes( $ids );
            Audit::log( 'notes_exported', [ 'count' => count( $notes ), 'user_id' => get_current_user_id() ] );
            return new WP_REST_Response( [ 'success' => true, 'message' => __( 'Notes export prepared.', 'core-blueprint' ), 'export' => [ 'schema' => 'core-blueprint-notes-export-v1', 'plugin' => 'core-blueprint', 'version' => CB_CORE_VERSION, 'exported_at' => current_time( 'mysql' ), 'site_url' => home_url(), 'notes' => $notes ] ] );
        }

        if ( 'import_preview' === $action ) {
            $notes = isset( $payload['notes'] ) && is_array( $payload['notes'] ) ? $payload['notes'] : [];
            return new WP_REST_Response( [ 'success' => true, 'message' => __( 'Import preview prepared.', 'core-blueprint' ), 'preview' => Repository::import_preview( $notes ) ] );
        }

        if ( 'import_commit' === $action ) {
            $notes = isset( $payload['notes'] ) && is_array( $payload['notes'] ) ? $payload['notes'] : [];
            $decisions = isset( $payload['decisions'] ) && is_array( $payload['decisions'] ) ? $payload['decisions'] : [];
            $overwrite_count = count( array_filter( $decisions, static fn( $decision ): bool => 'overwrite' === sanitize_key( (string) $decision ) ) );
            if ( $overwrite_count > 0 ) {
                try {
                    MutationAcknowledgement::require_confirmed(
                        $payload['notes_import_overwrite_acknowledgement'] ?? null,
                        __( 'Confirm your responsibility for backup and recovery before overwriting existing Notes.', 'core-blueprint' )
                    );
                } catch ( \InvalidArgumentException $error ) {
                    return new WP_REST_Response( [ 'success' => false, 'message' => $error->getMessage() ], 400 );
                }
                Audit::log( 'notes_import_overwrite_acknowledged', [
                    'overwrite_count'         => $overwrite_count,
                    'user_id'                 => get_current_user_id(),
                    'recovery_responsibility' => true,
                ] );
            }
            $summary = Repository::import_commit( $notes, $decisions );
            Audit::log( 'notes_imported', array_merge( $summary, [ 'user_id' => get_current_user_id() ] ) );
            return self::response_with_list( $filters, sprintf( __( 'Import complete. Created: %1$d. Overwritten: %2$d. Copied: %3$d. Skipped: %4$d. Failed: %5$d.', 'core-blueprint' ), $summary['created'], $summary['overwritten'], $summary['copied'], $summary['skipped'], $summary['failed'] ) );
        }

        return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Invalid notes action.', 'core-blueprint' ) ], 400 );
    }

    private static function note_not_found_response(): WP_REST_Response {
        return new WP_REST_Response(
            [ 'success' => false, 'message' => __( 'Note not found.', 'core-blueprint' ) ],
            404
        );
    }

    private static function write_failed_response( string $message ): WP_REST_Response {
        return new WP_REST_Response(
            [ 'success' => false, 'message' => $message ],
            500
        );
    }

    private static function response_with_list( array $filters, string $message ): WP_REST_Response {
        $result = Repository::query( $filters );
        $users  = get_users( [ 'fields' => [ 'ID', 'display_name' ] ] );
        return new WP_REST_Response( [ 'success' => true, 'message' => $message, 'html' => Renderer::result_html( $result, $filters, $users ) ] );
    }
}
