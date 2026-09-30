<?php
declare(strict_types=1);

/**
 * BASE-V1-F3A query-trace bootstrap.
 *
 * Loaded through PHP's auto_prepend_file only for explicitly traced
 * performance requests. It enables WordPress SAVEQUERIES before wp-settings.php
 * boots, then writes a bounded, machine-readable trace at shutdown. Production
 * runtime never loads this file.
 */

if ( '1' !== getenv( 'CB_PERFORMANCE_QUERY_TRACE' ) ) {
    return;
}

if ( ! defined( 'SAVEQUERIES' ) ) {
    define( 'SAVEQUERIES', true );
}

register_shutdown_function(
    static function (): void {
        $path = getenv( 'CB_PERFORMANCE_QUERY_TRACE_FILE' );
        if ( false === $path || '' === (string) $path ) {
            return;
        }

        $wpdb = $GLOBALS['wpdb'] ?? null;
        if ( ! is_object( $wpdb ) || ! isset( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
            return;
        }

        $marks = isset( $GLOBALS['cb_f3_phase_marks'] ) && is_array( $GLOBALS['cb_f3_phase_marks'] )
            ? $GLOBALS['cb_f3_phase_marks']
            : [];

        $ordered_marks = [];
        foreach ( [ 'wp_loaded', 'wp', 'enqueue', 'render', 'admin_init', 'admin_menu', 'admin_load_enqueue' ] as $phase ) {
            if ( isset( $marks[ $phase ] ) && is_numeric( $marks[ $phase ] ) ) {
                $ordered_marks[ $phase ] = max( 0, (int) $marks[ $phase ] );
            }
        }

        $limit = 0;
        foreach ( $ordered_marks as $count ) {
            $limit = max( $limit, $count );
        }
        if ( 0 === $limit ) {
            $limit = count( $wpdb->queries );
        }

        $phase_for = static function ( int $position ) use ( $ordered_marks ): string {
            if ( isset( $ordered_marks['wp_loaded'] ) && $position <= $ordered_marks['wp_loaded'] ) {
                return 'bootstrap';
            }
            if ( isset( $ordered_marks['wp'] ) && $position <= $ordered_marks['wp'] ) {
                return 'wp';
            }
            if ( isset( $ordered_marks['enqueue'] ) && $position <= $ordered_marks['enqueue'] ) {
                return 'enqueue';
            }
            if ( isset( $ordered_marks['render'] ) && $position <= $ordered_marks['render'] ) {
                return 'render';
            }
            if ( isset( $ordered_marks['admin_init'] ) && $position <= $ordered_marks['admin_init'] ) {
                return 'admin_init';
            }
            if ( isset( $ordered_marks['admin_menu'] ) && $position <= $ordered_marks['admin_menu'] ) {
                return 'admin_menu';
            }
            if ( isset( $ordered_marks['admin_load_enqueue'] ) && $position <= $ordered_marks['admin_load_enqueue'] ) {
                return 'admin_load_enqueue';
            }
            return 'after_measurement';
        };

        $entries = [];
        foreach ( array_values( $wpdb->queries ) as $index => $row ) {
            $position = $index + 1;
            if ( $position > $limit || ! is_array( $row ) ) {
                continue;
            }

            $sql = isset( $row[0] ) && is_string( $row[0] ) ? $row[0] : '';
            $sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
            $sql = is_string( $sql ) ? $sql : '';

            $entries[] = [
                'index'            => $position,
                'phase'            => $phase_for( $position ),
                'sql'              => $sql,
                'duration_seconds' => isset( $row[1] ) && is_numeric( $row[1] ) ? (float) $row[1] : 0.0,
                'caller'           => isset( $row[2] ) && is_string( $row[2] ) ? $row[2] : '',
            ];
        }

        $payload = [
            'schema'      => 1,
            'purpose'     => 'BASE-V1-F3A query-source attribution; diagnostic only',
            'phase_marks' => $ordered_marks,
            'entries'     => $entries,
        ];

        $directory = dirname( (string) $path );
        if ( ! is_dir( $directory ) ) {
            @mkdir( $directory, 0777, true );
        }

        @file_put_contents(
            (string) $path,
            json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL
        );
    }
);
