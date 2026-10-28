<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Design\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * Shared, opt-in document editing lease. This is NOT WordPress's advisory
 * post _edit_lock and is not a Save/CAS authority.
 *
 * Consumers own the resource identity, permission callback and request
 * transport. A consumer MUST require an owned lease before enabling its
 * editor AND at its server-side Save boundary; revision/CAS still applies.
 *
 * Uses the existing per-site wp_options unique option_name index. All lock
 * reads and conditional writes bypass get_option/object-cache state.
 * No table, migration, scheduler, REST route or parallel Designer is created.
 */
final class DocumentEditLease {
    public const API_VERSION = 1;
    public const TTL_SECONDS = 180;
    private const PREFIX = 'cb_core_edit_lease_v1_';

    /** @return array{status:string,token?:string,expires_at?:int} */
    public static function claim( string $type, string $id, callable $authorize ): array {
        $actor = self::actor( $type, $id, $authorize );
        if ( $actor < 1 ) {
            return [ 'status' => 'denied' ];
        }

        try {
            $token = bin2hex( random_bytes( 32 ) );
        } catch ( \Throwable $error ) {
            return [ 'status' => 'unavailable' ];
        }

        // An accepted editor must have a usable Save transaction probe.
        // This is a preflight only; owns_for_save() remains authoritative.
        if ( ! self::transactional_store_available() || ! self::save_probe_available() ) {
            return [ 'status' => 'unavailable' ];
        }

        $key     = self::key( $type, $id );
        $expires = time() + self::TTL_SECONDS;
        $value   = self::encode( $expires, $actor, $token );
        global $wpdb;

        // The WordPress options table already has a UNIQUE option_name index.
        // INSERT IGNORE claims exactly one free key, even under simultaneous
        // requests from tabs belonging to the same authenticated user.
        $wpdb->last_error = '';
        $created = $wpdb->query( $wpdb->prepare(
            'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
            $wpdb->options, $key, $value, 'off'
        ) );
        if ( false === $created || '' !== (string) $wpdb->last_error ) {
            return [ 'status' => 'unavailable' ];
        }
        if ( 1 === $created ) {
            return [ 'status' => 'acquired', 'token' => $token, 'expires_at' => $expires ];
        }

        $stored = self::read( $key );
        if ( 'found' !== $stored['status'] ) {
            return [ 'status' => 'unavailable' ];
        }
        $existing = self::decode( $stored['value'] );
        if ( null === $existing ) {
            return [ 'status' => 'unavailable' ];
        }
        if ( $existing['expires'] > time() ) {
            return [ 'status' => 'locked' ];
        }

        // Claim an expired row by atomic compare-and-swap: a racing renewal
        // or another claimant makes this update affect zero rows.
        $changed = self::replace( $key, $stored['value'], $value );
        if ( 1 === $changed ) {
            return [ 'status' => 'acquired', 'token' => $token, 'expires_at' => $expires ];
        }
        return [ 'status' => false === $changed ? 'unavailable' : 'locked' ];
    }

    /** @return array{status:string,expires_at?:int} */
    public static function renew( string $type, string $id, string $token, callable $authorize ): array {
        $actor = self::actor( $type, $id, $authorize );
        if ( $actor < 1 ) {
            return [ 'status' => 'denied' ];
        }
        if ( ! self::valid_token( $token ) ) {
            return [ 'status' => 'invalid' ];
        }

        if ( ! self::transactional_store_available() ) {
            return [ 'status' => 'unavailable' ];
        }
        $key = self::key( $type, $id );
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            $stored = self::read( $key );
            if ( 'found' !== $stored['status'] ) {
                return [ 'status' => 'error' === $stored['status'] ? 'unavailable' : 'lost' ];
            }
            $lease = self::decode( $stored['value'] );
            if ( ! self::is_owner( $lease, $actor, $token ) ) {
                return [ 'status' => 'lost' ];
            }
            $expires = time() + self::TTL_SECONDS;
            if ( $expires <= $lease['expires'] ) {
                return [ 'status' => 'renewed', 'expires_at' => $lease['expires'] ];
            }
            $changed = self::replace( $key, $stored['value'], self::encode( $expires, $actor, $token ) );
            if ( 1 === $changed ) {
                return [ 'status' => 'renewed', 'expires_at' => $expires ];
            }
            if ( false === $changed ) {
                return [ 'status' => 'unavailable' ];
            }
        }
        return [ 'status' => 'lost' ];
    }

    /** Server-side ownership check to be composed with consumer authorization and CAS. */
    public static function owns( string $type, string $id, string $token, callable $authorize ): bool {
        $actor = self::actor( $type, $id, $authorize );
        if ( $actor < 1 || ! self::valid_token( $token ) ) {
            return false;
        }
        $stored = self::read( self::key( $type, $id ) );
        return 'found' === $stored['status']
            && self::is_owner( self::decode( $stored['value'] ), $actor, $token );
    }

    /**
     * Save-time guard. Must run INSIDE the consumer's existing InnoDB
     * transaction and on its same wpdb connection, before the document write.
     * FOR UPDATE prevents lease expiry/takeover between validation and commit.
     * A plain owns() preflight alone is never enough for Save authorization.
     */
    public static function owns_for_save( string $type, string $id, string $token, callable $authorize ): bool {
        $actor = self::actor( $type, $id, $authorize );
        if ( $actor < 1 || ! self::valid_token( $token ) ) {
            return false;
        }
        if ( ! self::active_database_transaction() ) {
            return false;
        }
        if ( ! self::transactional_store_available() ) {
            return false;
        }
        $stored = self::read( self::key( $type, $id ), true );
        return 'found' === $stored['status']
            && self::is_owner( self::decode( $stored['value'] ), $actor, $token );
    }

    /** Best-effort release: never delete a lock acquired by a subsequent owner. */
    public static function release( string $type, string $id, string $token ): bool {
        $actor = self::current_actor( $type, $id );
        if ( $actor < 1 || ! self::valid_token( $token ) ) {
            return false;
        }
        $key = self::key( $type, $id );
        $stored = self::read( $key );
        if ( 'found' !== $stored['status']
            || ! self::is_owner( self::decode( $stored['value'] ), $actor, $token ) ) {
            return false;
        }
        global $wpdb;
        $wpdb->last_error = '';
        return 1 === $wpdb->query( $wpdb->prepare(
            'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s',
            $wpdb->options, $key, $stored['value']
        ) ) && '' === (string) $wpdb->last_error;
    }

    /**
     * Fail-closed transaction probe on the SAME wpdb connection.
     * MariaDB exposes @@in_transaction. MySQL does not: its supported
     * transaction event is performance_schema.events_transactions_current.
     * If the MySQL instrument/consumer is unavailable, refuse Save.
     */
    private static function active_database_transaction(): bool {
        global $wpdb;
        $wpdb->last_error = '';
        $server = $wpdb->get_var( 'SELECT VERSION()' );
        if ( ! is_string( $server ) || '' !== (string) $wpdb->last_error ) {
            return false;
        }

        if ( false !== stripos( $server, 'mariadb' ) ) {
            $wpdb->last_error = '';
            $active = $wpdb->get_var( 'SELECT @@session.in_transaction' );
            return '1' === (string) $active && '' === (string) $wpdb->last_error;
        }

        // MySQL 8.x: only an ACTIVE event for THIS connection is accepted.
        // No cached state, plugin flag, autocommit guess or cross-session trust.
        if ( ! preg_match( '/\\A(?:8|9)\\.[0-9]+\\.[0-9]+/', $server ) ) {
            return false;
        }
        $wpdb->last_error = '';
        $active = $wpdb->get_var(
            "SELECT 1 FROM performance_schema.events_transactions_current AS tx
            INNER JOIN performance_schema.threads AS th ON th.THREAD_ID = tx.THREAD_ID
            WHERE th.PROCESSLIST_ID = CONNECTION_ID() AND tx.STATE = 'ACTIVE' LIMIT 1"
        );
        return '1' === (string) $active && '' === (string) $wpdb->last_error;
    }

    /**
     * Read-only admission preflight. MySQL transaction instrumentation can be
     * disabled even while wp_options is InnoDB, making every Save fail closed.
     * Do not START/COMMIT transactions here: Save still proves ownership inside
     * the consumer's existing transaction through owns_for_save().
     */
    private static function save_probe_available(): bool {
        global $wpdb;
        $wpdb->last_error = '';
        $server = $wpdb->get_var( 'SELECT VERSION()' );
        if ( ! is_string( $server ) || '' !== (string) $wpdb->last_error ) {
            return false;
        }
        if ( false !== stripos( $server, 'mariadb' ) ) {
            $wpdb->last_error = '';
            $active = $wpdb->get_var( 'SELECT @@session.in_transaction' );
            return in_array( (string) $active, [ '0', '1' ], true )
                && '' === (string) $wpdb->last_error;
        }
        if ( ! preg_match( '/\\A(?:8|9)\\.[0-9]+\\.[0-9]+/', $server ) ) {
            return false;
        }
        // Current connection must be instrumented. All three consumer levels
        // and the transaction instrument are required for the Save-time query.
        // LEFT JOIN confirms read access to the same event table as Save.
        $wpdb->last_error = '';
        $ready = $wpdb->get_var(
            "SELECT 1 FROM performance_schema.setup_instruments AS instrument
            INNER JOIN performance_schema.threads AS th
                ON th.PROCESSLIST_ID = CONNECTION_ID() AND th.INSTRUMENTED = 'YES'
            LEFT JOIN performance_schema.events_transactions_current AS tx
                ON tx.THREAD_ID = th.THREAD_ID
            WHERE instrument.NAME = 'transaction' AND instrument.ENABLED = 'YES'
                AND ( tx.THREAD_ID IS NULL OR tx.THREAD_ID = th.THREAD_ID )
                AND (
                    SELECT COUNT(*) FROM performance_schema.setup_consumers
                    WHERE NAME IN (
                        'global_instrumentation',
                        'thread_instrumentation',
                        'events_transactions_current'
                    ) AND ENABLED = 'YES'
                ) = 3
            LIMIT 1"
        );
        return '1' === (string) $ready && '' === (string) $wpdb->last_error;
    }

    /** Editing locks must not admit a session that cannot perform safe Save. */
    private static function transactional_store_available(): bool {
        global $wpdb;
        $wpdb->last_error = '';
        $engine = $wpdb->get_var( $wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
            $wpdb->options
        ) );
        return '' === (string) $wpdb->last_error
            && 0 === strcasecmp( (string) $engine, 'InnoDB' );
    }

    private static function current_actor( string $type, string $id ): int {
        if ( ! self::valid_identity( $type, $id ) || ! function_exists( 'get_current_user_id' )
            || ! function_exists( 'get_current_blog_id' ) || (int) get_current_blog_id() < 1 ) {
            return 0;
        }
        return max( 0, (int) get_current_user_id() );
    }

    private static function actor( string $type, string $id, callable $authorize ): int {
        $actor = self::current_actor( $type, $id );
        if ( $actor < 1 ) {
            return 0;
        }
        try {
            return true === $authorize( $type, $id ) ? $actor : 0;
        } catch ( \Throwable $error ) {
            return 0;
        }
    }

    private static function valid_identity( string $type, string $id ): bool {
        return 1 === preg_match( '/\A[a-z][a-z0-9._-]{1,63}\z/D', $type )
            && strlen( $id ) <= 191
            && 1 === preg_match( '/\A[a-zA-Z0-9][a-zA-Z0-9._:\/-]*\z/D', $id );
    }

    private static function valid_token( string $token ): bool {
        return 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $token );
    }

    private static function key( string $type, string $id ): string {
        // Current blog's options table additionally isolates multisite keys.
        return self::PREFIX . hash( 'sha256', get_current_blog_id() . "\0" . $type . "\0" . $id );
    }

    private static function encode( int $expires, int $actor, string $token ): string {
        // The token itself is never stored, only its SHA-256 digest.
        return 'v1:' . $expires . ':' . $actor . ':' . hash( 'sha256', $token );
    }

    /** @return array{expires:int,actor:int,digest:string}|null */
    private static function decode( string $value ): ?array {
        if ( 1 !== preg_match( '/\Av1:([0-9]{1,12}):([1-9][0-9]{0,11}):([a-f0-9]{64})\z/D', $value, $match ) ) {
            return null;
        }
        $expires = (int) $match[1];
        $actor = (int) $match[2];
        return $expires > 0 && $actor > 0
            ? [ 'expires' => $expires, 'actor' => $actor, 'digest' => $match[3] ]
            : null;
    }

    private static function is_owner( ?array $lease, int $actor, string $token ): bool {
        return null !== $lease && $lease['expires'] > time()
            && $lease['actor'] === $actor
            && hash_equals( $lease['digest'], hash( 'sha256', $token ) );
    }

    /** @return array{status:string,value?:string} */
    private static function read( string $key, bool $for_update = false ): array {
        global $wpdb;
        $wpdb->last_error = '';
        $query = $wpdb->prepare(
            'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
            $wpdb->options, $key
        );
        $value = $wpdb->get_var( $query . ( $for_update ? ' FOR UPDATE' : '' ) );
        if ( '' !== (string) $wpdb->last_error ) {
            return [ 'status' => 'error' ];
        }
        return is_string( $value )
            ? [ 'status' => 'found', 'value' => $value ]
            : [ 'status' => 'missing' ];
    }

    /** SQL single-statement compare-and-swap, no transient or object-cache authority. */
    private static function replace( string $key, string $previous, string $next ): int|false {
        global $wpdb;
        $wpdb->last_error = '';
        $result = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
            $wpdb->options, $next, $key, $previous
        ) );
        return '' === (string) $wpdb->last_error ? $result : false;
    }
}
