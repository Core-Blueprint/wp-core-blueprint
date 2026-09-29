<?php
declare(strict_types=1);
namespace CB\Core\Notes;

defined( 'ABSPATH' ) || exit;

final class Repository {
    public static function allowed_statuses(): array {
        return [ 'Backlog', 'Open', 'Important', 'Archived' ];
    }

    public static function allowed_types(): array {
        return [ 'General', 'Maintenance', 'Security' ];
    }

    public static function allowed_formats(): array {
        return [ 'markdown', 'plain' ];
    }

    public static function allowed_per_page(): array {
        return [ 10, 20, 50, 100 ];
    }

    public static function allowed_sort_fields(): array {
        return [
            'updated' => 'updated_at',
            'created' => 'created_at',
            'title'   => 'title',
            'author'  => 'created_by',
        ];
    }

    public static function sanitize_status( string $status ): string {
        return in_array( $status, self::allowed_statuses(), true ) ? $status : 'Backlog';
    }

    public static function sanitize_type( string $type ): string {
        return in_array( $type, self::allowed_types(), true ) ? $type : 'General';
    }

    public static function sanitize_format( string $format ): string {
        return in_array( $format, self::allowed_formats(), true ) ? $format : 'markdown';
    }

    public static function sanitize_per_page( int $per_page ): int {
        return in_array( $per_page, self::allowed_per_page(), true ) ? $per_page : 20;
    }

    public static function query( array $args ): array {
        global $wpdb;

        $table  = $wpdb->prefix . 'cb_core_notes';
        $where  = [];
        $values = [];

        if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
            $where[]  = 'status = %s';
            $values[] = self::sanitize_status( (string) $args['status'] );
        }

        if ( ! empty( $args['type'] ) && 'all' !== $args['type'] ) {
            $where[]  = 'type = %s';
            $values[] = self::sanitize_type( (string) $args['type'] );
        }

        if ( ! empty( $args['assigned'] ) && 'all' !== $args['assigned'] ) {
            if ( 'me' === $args['assigned'] ) {
                $where[]  = 'assigned_to = %d';
                $values[] = get_current_user_id();
            } elseif ( 'unassigned' === $args['assigned'] ) {
                $where[] = '(assigned_to IS NULL OR assigned_to = 0)';
            } elseif ( is_numeric( $args['assigned'] ) ) {
                $where[]  = 'assigned_to = %d';
                $values[] = (int) $args['assigned'];
            }
        }

        if ( ! empty( $args['tag'] ) ) {
            $where[]  = 'tags LIKE %s';
            $values[] = '%' . $wpdb->esc_like( (string) $args['tag'] ) . '%';
        }

        if ( ! empty( $args['search'] ) ) {
            $search   = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
            $where[]  = '(title LIKE %s OR content LIKE %s OR tags LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sort_key = isset( $args['sort'] ) ? sanitize_key( (string) $args['sort'] ) : 'updated';
        $sort_map = self::allowed_sort_fields();
        $sort_col = $sort_map[ $sort_key ] ?? 'updated_at';

        if ( 'updated' === $sort_key ) {
            $order_sql = "ORDER BY FIELD(status, 'Important', 'Open', 'Backlog', 'Archived'), updated_at DESC";
        } elseif ( 'title' === $sort_key ) {
            $order_sql = 'ORDER BY title ASC';
        } else {
            $order_sql = "ORDER BY {$sort_col} DESC";
        }

        $per_page = self::sanitize_per_page( (int) ( $args['per_page'] ?? 20 ) );
        $page     = max( 1, (int) ( $args['paged'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $items_sql = "SELECT * FROM {$table} {$where_sql} {$order_sql} LIMIT %d OFFSET %d";

        $total = $values
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) )
            : (int) $wpdb->get_var( $count_sql );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                $items_sql,
                array_merge( $values, [ $per_page, $offset ] )
            )
        );

        return [
            'items'       => $items,
            'total'       => $total,
            'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
            'page'        => $page,
            'per_page'    => $per_page,
        ];
    }

    public static function create( array $data ): bool {
        global $wpdb;

        $table   = $wpdb->prefix . 'cb_core_notes';
        $now     = current_time( 'mysql' );
        $user_id = get_current_user_id();

        $row = [
            'title'          => self::clean_title( (string) ( $data['title'] ?? '' ) ),
            'content'        => self::clean_content( (string) ( $data['content'] ?? '' ) ),
            'content_format' => self::sanitize_format( (string) ( $data['content_format'] ?? 'markdown' ) ),
            'type'           => self::sanitize_type( (string) ( $data['type'] ?? 'General' ) ),
            'status'         => self::sanitize_status( (string) ( $data['status'] ?? 'Backlog' ) ),
            'tags'           => self::clean_tags( (string) ( $data['tags'] ?? '' ) ),
            'created_by'     => self::clean_user_id( $data['created_by'] ?? $user_id, $user_id ),
            'updated_by'     => $user_id,
            'assigned_to'    => self::clean_assigned( $data['assigned_to'] ?? 0 ),
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ];

        return false !== $wpdb->insert( $table, $row, $formats );
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $table    = $wpdb->prefix . 'cb_core_notes';
        $existing = self::find( $id );

        if ( ! $existing ) {
            return false;
        }

        $row = [
            'title'          => self::clean_title( (string) ( $data['title'] ?? '' ) ),
            'content'        => self::clean_content( (string) ( $data['content'] ?? '' ) ),
            'content_format' => self::sanitize_format( (string) ( $data['content_format'] ?? 'markdown' ) ),
            'type'           => self::sanitize_type( (string) ( $data['type'] ?? 'General' ) ),
            'status'         => self::sanitize_status( (string) ( $data['status'] ?? 'Backlog' ) ),
            'tags'           => self::clean_tags( (string) ( $data['tags'] ?? '' ) ),
            'created_by'     => self::clean_user_id( $data['created_by'] ?? $existing->created_by, (int) $existing->created_by ),
            'updated_by'     => get_current_user_id(),
            'assigned_to'    => self::clean_assigned( $data['assigned_to'] ?? 0 ),
            'updated_at'     => current_time( 'mysql' ),
        ];

        $formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ];

        return false !== $wpdb->update( $table, $row, [ 'id' => $id ], $formats, [ '%d' ] );
    }


    public static function find( int $id ): ?object {
        global $wpdb;

        $note = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cb_core_notes WHERE id = %d",
                $id
            )
        );

        return $note ?: null;
    }

    public static function duplicate( int $id ): int {
        global $wpdb;

        $source = self::find( $id );

        if ( ! $source ) {
            return 0;
        }

        $table   = $wpdb->prefix . 'cb_core_notes';
        $now     = current_time( 'mysql' );
        $user_id = get_current_user_id();

        $row = [
            'title'          => self::clean_title( sprintf( __( '%s copy', 'core-blueprint' ), (string) $source->title ) ),
            'content'        => self::clean_content( (string) $source->content ),
            'content_format' => self::sanitize_format( (string) $source->content_format ),
            'type'           => self::sanitize_type( (string) $source->type ),
            'status'         => self::sanitize_status( (string) $source->status ),
            'tags'           => self::clean_tags( (string) $source->tags ),
            'created_by'     => $user_id,
            'updated_by'     => $user_id,
            'assigned_to'    => self::clean_assigned( $source->assigned_to ),
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $formats = array_map(
            static function ( string $key ): string {
                return in_array( $key, [ 'created_by', 'updated_by', 'assigned_to' ], true ) ? '%d' : '%s';
            },
            array_keys( $row )
        );

        $inserted = $wpdb->insert( $table, $row, $formats );

        if ( false === $inserted ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public static function quick_status( int $id, string $status ): bool {
        global $wpdb;

        if ( ! self::find( $id ) ) {
            return false;
        }

        $table = $wpdb->prefix . 'cb_core_notes';

        return false !== $wpdb->update(
            $table,
            [
                'status'     => self::sanitize_status( $status ),
                'updated_by' => get_current_user_id(),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );
    }

    public static function archive( int $id ): bool {
        return self::quick_status( $id, 'Archived' );
    }

    public static function delete( int $id ): bool {
        global $wpdb;

        if ( ! self::find( $id ) ) {
            return false;
        }

        return false !== $wpdb->delete(
            $wpdb->prefix . 'cb_core_notes',
            [ 'id' => $id ],
            [ '%d' ]
        );
    }

    public static function delete_all(): int|false {
        global $wpdb;

        $deleted = $wpdb->query( "DELETE FROM {$wpdb->prefix}cb_core_notes" );

        return false === $deleted ? false : (int) $deleted;
    }

    public static function bulk_delete( array $ids ): int|false {
        global $wpdb;

        $ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', $ids ),
                    static fn ( int $id ): bool => $id > 0
                )
            )
        );

        if ( empty( $ids ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}cb_core_notes WHERE id IN ({$placeholders})",
                $ids
            )
        );

        return false === $deleted ? false : (int) $deleted;
    }

    private static function clean_title( string $title ): string {
        $title = sanitize_text_field( $title );
        return '' !== $title ? $title : __( 'Untitled note', 'core-blueprint' );
    }

    private static function clean_content( string $content ): string {
        return wp_kses_post( wp_unslash( $content ) );
    }

    private static function clean_tags( string $tags ): string {
        $tags  = sanitize_text_field( $tags );
        $parts = array_filter( array_map( 'trim', explode( ',', $tags ) ) );
        return implode( ', ', array_slice( $parts, 0, 20 ) );
    }

    private static function clean_assigned( $assigned ): int {
        $assigned = (int) $assigned;

        if ( $assigned <= 0 ) {
            return 0;
        }

        return get_userdata( $assigned ) ? $assigned : 0;
    }

    private static function clean_user_id( $user_id, int $fallback ): int {
        $user_id = (int) $user_id;

        if ( $user_id > 0 && get_userdata( $user_id ) ) {
            return $user_id;
        }

        return $fallback > 0 && get_userdata( $fallback ) ? $fallback : get_current_user_id();
    }

    public static function export_notes( array $ids = [] ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'cb_core_notes';
        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

        if ( $ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $notes = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY updated_at DESC", $ids ) );
        } else {
            $notes = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC" );
        }

        return array_map( [ self::class, 'note_to_export_array' ], $notes ?: [] );
    }

    public static function note_to_export_array( object $note ): array {
        return [
            'note_uuid'      => self::export_uuid( $note ),
            'title'          => (string) $note->title,
            'content'        => (string) $note->content,
            'content_format' => (string) $note->content_format,
            'type'           => (string) $note->type,
            'status'         => (string) $note->status,
            'tags'           => (string) $note->tags,
            'created_by'     => (int) $note->created_by,
            'updated_by'     => (int) $note->updated_by,
            'assigned_to'    => (int) $note->assigned_to,
            'created_at'     => (string) $note->created_at,
            'updated_at'     => (string) $note->updated_at,
        ];
    }

    public static function normalize_import_note( array $note ): array {
        return [
            'note_uuid'      => sanitize_text_field( (string) ( $note['note_uuid'] ?? '' ) ),
            'title'          => self::clean_title( (string) ( $note['title'] ?? '' ) ),
            'content'        => self::clean_content( (string) ( $note['content'] ?? '' ) ),
            'content_format' => self::sanitize_format( (string) ( $note['content_format'] ?? 'markdown' ) ),
            'type'           => self::sanitize_type( (string) ( $note['type'] ?? 'General' ) ),
            'status'         => self::sanitize_status( (string) ( $note['status'] ?? 'Backlog' ) ),
            'tags'           => self::clean_tags( (string) ( $note['tags'] ?? '' ) ),
            'assigned_to'    => self::clean_assigned( $note['assigned_to'] ?? 0 ),
        ];
    }

    public static function import_preview( array $notes ): array {
        $rows = [];

        foreach ( $notes as $index => $note ) {
            if ( ! is_array( $note ) ) {
                continue;
            }

            $incoming = self::normalize_import_note( $note );
            $existing = self::find_import_match( $incoming );
            $status = 'new';
            $changes = [];

            if ( $existing ) {
                $changes = self::diff_import_note( $incoming, $existing );
                $status = empty( $changes ) ? 'identical' : 'changed';
            }

            $rows[] = [
                'index'       => (int) $index,
                'status'      => $status,
                'existing_id' => $existing ? (int) $existing->id : 0,
                'title'       => $incoming['title'],
                'changes'     => $changes,
                'incoming'    => $incoming,
            ];
        }

        return $rows;
    }

    public static function import_commit( array $notes, array $decisions, bool $overwrite_acknowledged = false ): array {
        $summary = [ 'created' => 0, 'overwritten' => 0, 'copied' => 0, 'skipped' => 0, 'failed' => 0 ];
        $prepared = [];

        // Resolve every target before the first mutation. This guarantees that an
        // unacknowledged overwrite can never occur after earlier rows were already
        // created or copied in the same import request.
        foreach ( $notes as $index => $note ) {
            if ( ! is_array( $note ) ) {
                continue;
            }

            $incoming = self::normalize_import_note( $note );
            $decision = isset( $decisions[ (string) $index ] ) ? sanitize_key( (string) $decisions[ (string) $index ] ) : 'skip';
            $existing = self::find_import_match( $incoming );

            if ( 'overwrite' === $decision && $existing && ! $overwrite_acknowledged ) {
                throw new \InvalidArgumentException( __( 'Confirm your responsibility for backup and recovery before overwriting existing Notes.', 'core-blueprint' ) );
            }

            $prepared[] = [
                'incoming' => $incoming,
                'decision' => $decision,
                'existing' => $existing,
            ];
        }

        foreach ( $prepared as $item ) {
            $incoming = $item['incoming'];
            $decision = (string) $item['decision'];
            $existing = $item['existing'];

            if ( 'skip' === $decision ) {
                $summary['skipped']++;
                continue;
            }

            if ( 'overwrite' === $decision && $existing ) {
                if ( self::update( (int) $existing->id, $incoming ) ) {
                    $summary['overwritten']++;
                } else {
                    $summary['failed']++;
                }
                continue;
            }

            if ( 'copy' === $decision || 'create' === $decision || ! $existing ) {
                if ( 'copy' === $decision && $existing ) {
                    $incoming['title'] = sprintf( __( '%s imported copy', 'core-blueprint' ), $incoming['title'] );
                }
                if ( self::create( $incoming ) ) {
                    'copy' === $decision ? $summary['copied']++ : $summary['created']++;
                } else {
                    $summary['failed']++;
                }
            }
        }

        return $summary;
    }

    private static function find_import_match( array $incoming ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cb_core_notes WHERE title = %s ORDER BY updated_at DESC LIMIT 1", $incoming['title'] ) ) ?: null;
    }

    private static function diff_import_note( array $incoming, object $existing ): array {
        $fields = [ 'title', 'content', 'content_format', 'type', 'status', 'tags', 'assigned_to' ];
        $changes = [];

        foreach ( $fields as $field ) {
            $old = property_exists( $existing, $field ) ? (string) $existing->{$field} : '';
            $new = isset( $incoming[ $field ] ) ? (string) $incoming[ $field ] : '';
            if ( $old !== $new ) {
                $changes[ $field ] = [ 'existing' => $old, 'incoming' => $new ];
            }
        }

        return $changes;
    }

    private static function export_uuid( object $note ): string {
        return hash( 'sha256', home_url() . '|' . (string) $note->id . '|' . (string) $note->created_at . '|' . (string) $note->title );
    }
}
