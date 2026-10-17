<?php
declare(strict_types=1);

/**
 * Binary-aware, option-scoped CAS for Base Settings.
 *
 * This is an internal persistence boundary, not a replacement for the public
 * WordPress Options API. Independent third-party update_option() calls are
 * explicitly outside first-party CAS coordination.
 */
namespace CoreBlueprint\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class OptionCas {

    private const MAX_PATHS = 512;
    private const MAX_DEPTH = 16;

    /**
     * Persist exactly the changed leaf paths between two request-local
     * snapshots. A fresh row supplies every sibling value; stale intended
     * paths fail instead of overwriting a concurrent edit.
     *
     * @param array<string,mixed> $explicit_paths Caller-supplied trusted leaf targets, including cache-equal paths.
     * @return array{status:string,before?:array,after?:array}
     */
    public static function write( array $expected, array $desired, ?string $exact_raw = null, array $explicit_paths = [] ): array {
        if ( count( $explicit_paths ) > 64 ) {
            return [ 'status' => 'invalid' ];
        }

        $intent = [];
        if ( ! self::collect( $expected, $desired, [], $intent ) ) {
            return [ 'status' => 'invalid' ];
        }

        $row = self::read();
        if ( 'ok' !== $row['status'] ) {
            return [ 'status' => $row['status'] ];
        }

        if ( null !== $exact_raw && ! hash_equals( $exact_raw, $row['raw'] ) ) {
            return [ 'status' => 'conflict' ];
        }

        $stored = $row['value'];
        $current = $stored;
        if ( class_exists( \CoreBlueprint\Core\Settings::class, false ) ) {
            $current = self::deep_merge( \CoreBlueprint\Core\Settings::defaults(), $stored );
        }

        // A cache-equal leaf can still be an explicit part of a multi-path
        // intent. Compare every submitted target against this same fresh row
        // that the binary SQL CAS later guards; never check only diff paths.
        foreach ( $explicit_paths as $path => $wanted ) {
            if ( ! is_string( $path )
                || ! preg_match( '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){1,7}$/D', $path ) ) {
                return [ 'status' => 'invalid' ];
            }
            $parts = explode( '.', $path );
            [ $desired_exists, $desired_value ] = self::at( $desired, $parts );
            if ( ! $desired_exists || $desired_value !== $wanted ) {
                return [ 'status' => 'invalid' ];
            }
            [ $expected_exists, $expected_value ] = self::at( $expected, $parts );
            [ $live_exists, $live_value ] = self::at( $current, $parts );
            if ( $expected_exists !== $live_exists
                || ( $expected_exists && $expected_value !== $live_value ) ) {
                return [ 'status' => 'conflict' ];
            }
        }

        // Base defaults are a READ projection, never an implicit write intent.
        // Persist only the raw stored document plus expressly changed paths.
        $candidate = $stored;
        foreach ( $intent as $change ) {
            [ $present, $value ] = self::at( $current, $change['path'] );
            if ( $present !== $change['before_exists']
                || ( $present && $value !== $change['before'] ) ) {
                return [ 'status' => 'conflict' ];
            }
            if ( ! self::replace_path( $candidate, $change['path'], $change['after_exists'], $change['after'] ) ) {
                return [ 'status' => 'invalid' ];
            }
        }

        $name = CB_CORE_SETTINGS;
        $proposed = $candidate;
        $candidate = sanitize_option( $name, $candidate );
        $candidate = apply_filters( "pre_update_option_{$name}", $candidate, $stored, $name );
        $candidate = apply_filters( 'pre_update_option', $candidate, $name, $stored );

        // The existing pre_update_option_cb_core_settings old-value veto must
        // remain meaningful, including for the first-party Level 2 fixtures.
        if ( $candidate === $stored && $candidate !== $proposed ) {
            return [ 'status' => 'refused' ];
        }
        if ( ! is_array( $candidate ) ) {
            return [ 'status' => 'refused' ];
        }

        // Pre-update filters may veto an option write, but may not silently
        // attenuate or transform any requested leaf in a multi-path intent.
        // A partial match is not a successful Settings mutation.
        foreach ( $intent as $requested ) {
            [ $present, $value ] = self::at( $candidate, $requested['path'] );
            if ( $present !== $requested['after_exists']
                || ( $present && $value !== $requested['after'] ) ) {
                return [ 'status' => 'refused' ];
            }
        }

        // Also hold filters to the caller's cache-equal explicit leaves.
        // Project defaults for a leaf that was never stored rather than
        // accidentally materializing unrelated defaults in the option row.
        $filtered_view = $candidate;
        if ( class_exists( \CoreBlueprint\Core\Settings::class, false ) ) {
            $filtered_view = self::deep_merge( \CoreBlueprint\Core\Settings::defaults(), $candidate );
        }
        foreach ( $explicit_paths as $path => $wanted ) {
            [ $present, $value ] = self::at( $filtered_view, explode( '.', $path ) );
            if ( ! $present || $value !== $wanted ) {
                return [ 'status' => 'refused' ];
            }
        }

        $actual = [];
        if ( ! self::collect( $proposed, $candidate, [], $actual ) ) {
            return [ 'status' => 'invalid' ];
        }
        foreach ( $actual as $change ) {
            $allowed = false;
            foreach ( $intent as $requested ) {
                if ( self::within( $requested['path'], $change['path'] ) ) {
                    $allowed = true;
                    break;
                }
            }
            if ( ! $allowed ) {
                return [ 'status' => 'refused' ];
            }
        }

        if ( $candidate === $stored || maybe_serialize( $candidate ) === $row['raw'] ) {
            return [ 'status' => [] === $intent ? 'unchanged' : 'refused' ];
        }
        if ( [] === $intent ) {
            // A filter must not manufacture mutations for an empty intent.
            return [ 'status' => 'refused' ];
        }

        // WordPress pre-write action is not a post-commit success event.
        // It is outside any database transaction and is emitted once.
        do_action( 'update_option', $name, $stored, $candidate );

        $next_raw = maybe_serialize( $candidate );
        if ( ! is_string( $next_raw ) ) {
            return [ 'status' => 'failed' ];
        }

        global $wpdb;
        $affected = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s AND autoload = %s',
            $row['table'],
            $next_raw,
            $name,
            $row['raw'],
            $row['autoload']
        ) );

        if ( false === $affected ) {
            return [ 'status' => 'failed' ];
        }
        if ( 1 !== $affected ) {
            return [ 'status' => 'conflict' ];
        }

        // Clearing all three WordPress option-cache forms is required before
        // any post-write observer can fetch a value; a missed key returns
        // false on many object caches and is not itself a cache failure.
        foreach ( [ $name, 'alloptions', 'notoptions' ] as $cache_key ) {
            wp_cache_delete( $cache_key, 'options' );
            // WordPress returns false for both missing keys and some cache
            // delete failures. Independently inspect the found flag so a
            // retained stale value can never be certified as a successful
            // Settings write.
            $found = false;
            wp_cache_get( $cache_key, 'options', false, $found );
            if ( $found ) {
                return [ 'status' => 'uncertain' ];
            }
        }

        $confirmed = self::read();
        if ( 'ok' !== $confirmed['status'] || $confirmed['raw'] !== $next_raw
            || $confirmed['autoload'] !== $row['autoload'] ) {
            // Database may already have committed. Never claim success.
            return [ 'status' => 'uncertain' ];
        }

        // Post-commit observers must never see a stale Settings request cache.
        if ( class_exists( \CoreBlueprint\Core\Settings::class, false ) ) {
            \CoreBlueprint\Core\Settings::accept_cas( $candidate );
        }
        do_action( "update_option_{$name}", $stored, $candidate, $name );
        do_action( 'updated_option', $name, $stored, $candidate );
        return [ 'status' => 'saved', 'before' => $stored, 'after' => $candidate ];
    }

    /**
     * Return actual option identity and bytes, never a get_option() cache.
     *
     * @return array{status:string,table?:string,raw?:string,autoload?:string,value?:array}
     */
    public static function read(): array {
        global $wpdb;
        $table = $wpdb->options ?? null;
        if ( ! is_string( $table ) || '' === $table || ! isset( $wpdb->last_error ) ) {
            return [ 'status' => 'failed' ];
        }

        $wpdb->last_error = '';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s LIMIT 1',
                $table,
                CB_CORE_SETTINGS
            ),
            ARRAY_A
        );
        if ( '' !== (string) $wpdb->last_error ) {
            return [ 'status' => 'failed' ];
        }
        if ( null === $row ) {
            return [ 'status' => 'missing' ];
        }
        if ( ! is_array( $row ) || ! is_string( $row['option_value'] ?? null )
            || ! is_string( $row['autoload'] ?? null ) || '' === $row['autoload']
            || ( $row['option_name'] ?? null ) !== CB_CORE_SETTINGS ) {
            return [ 'status' => 'invalid' ];
        }

        // Existing Base Settings are an array; objects and falsey scalar
        // stored values must not be silently replaced with defaults.
        $decoded = @unserialize( $row['option_value'], [ 'allowed_classes' => false ] );
        if ( ! is_array( $decoded ) || ! self::safe_array( $decoded ) ) {
            return [ 'status' => 'invalid' ];
        }
        return [
            'status' => 'ok',
            'table' => $table,
            'raw' => $row['option_value'],
            'autoload' => $row['autoload'],
            'value' => $decoded,
        ];
    }

    private static function safe_array( array $value, int $depth = 0 ): bool {
        if ( $depth > self::MAX_DEPTH ) {
            return false;
        }
        foreach ( $value as $child ) {
            if ( is_object( $child ) || is_resource( $child )
                || ( is_array( $child ) && ! self::safe_array( $child, $depth + 1 ) ) ) {
                return false;
            }
        }
        return true;
    }

    private static function collect( array $before, array $after, array $prefix, array &$intent, int $depth = 0 ): bool {
        if ( $depth > self::MAX_DEPTH ) {
            return false;
        }
        foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
            $exists_before = array_key_exists( $key, $before );
            $exists_after = array_key_exists( $key, $after );
            $was = $exists_before ? $before[$key] : null;
            $now = $exists_after ? $after[$key] : null;
            if ( $exists_before && $exists_after && $was === $now ) {
                continue;
            }
            $path = [ ...$prefix, $key ];
            if ( $exists_before && $exists_after && is_array( $was ) && is_array( $now )
                && ! array_is_list( $was ) && ! array_is_list( $now ) ) {
                if ( ! self::collect( $was, $now, $path, $intent, $depth + 1 ) ) {
                    return false;
                }
            } else {
                $intent[] = [
                    'path' => $path, 'before_exists' => $exists_before,
                    'before' => $was, 'after_exists' => $exists_after, 'after' => $now,
                ];
                if ( count( $intent ) > self::MAX_PATHS ) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function at( array $root, array $path ): array {
        $value = $root;
        foreach ( $path as $key ) {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                return [ false, null ];
            }
            $value = $value[$key];
        }
        return [ true, $value ];
    }

    private static function replace_path( array &$root, array $path, bool $exists, mixed $value ): bool {
        $last = array_pop( $path );
        $cursor = &$root;
        foreach ( $path as $key ) {
            if ( ! array_key_exists( $key, $cursor ) ) {
                if ( ! $exists ) {
                    return true;
                }
                $cursor[$key] = [];
            }
            if ( ! is_array( $cursor[$key] ) ) {
                return false;
            }
            $cursor = &$cursor[$key];
        }
        if ( $exists ) {
            $cursor[$last] = $value;
        } else {
            unset( $cursor[$last] );
        }
        return true;
    }

    private static function within( array $parent, array $path ): bool {
        if ( count( $parent ) > count( $path ) ) {
            return false;
        }
        foreach ( $parent as $i => $part ) {
            if ( $path[$i] !== $part ) {
                return false;
            }
        }
        return true;
    }

    private static function deep_merge( array $defaults, array $override ): array {
        foreach ( $override as $key => $value ) {
            if ( is_array( $value ) && isset( $defaults[$key] ) && is_array( $defaults[$key] ) ) {
                $defaults[$key] = self::deep_merge( $defaults[$key], $value );
            } else {
                $defaults[$key] = $value;
            }
        }
        return $defaults;
    }
}
