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
    private static function read( string $key ): array {
        global $wpdb;
        $wpdb->last_error = '';
        $value = $wpdb->get_var( $wpdb->prepare(
            'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
            $wpdb->options, $key
        ) );
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
