<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Profiles;

use CoreBlueprint\Core\Design\Document\Authority;
use CoreBlueprint\Core\Design\Document\Repository;
use CoreBlueprint\Core\Design\Document\Schema;
use CoreBlueprint\Core\Reports\DesignDocumentAdapter;
use CoreBlueprint\Core\Settings;
use CoreBlueprint\Core\Settings\OptionCas;

defined( 'ABSPATH' ) || exit;

/**
 * Internal A2.4-S2 canonical Reports two-row transaction boundary.
 *
 * This class is intentionally NOT connected to active Profiles Apply. Engine
 * and ReportsSection must continue to block active mutation until separate
 * field, WordPress Level 2 and cutover acceptances are recorded.
 *
 * Native option prefilters and prewrite action execute BEFORE START TRANSACTION.
 * No arbitrary WP callbacks run while either row lock is held.
 */
final class ReportsProfileTransaction {
    private const MAX_DOCUMENT_BYTES = 262144;

    /** @return array{status:string,witness?:array<string,string>} */
    public static function witness(): array {
        if ( 'active' !== ( Authority::inspect()['status'] ?? '' ) ) {
            return [ 'status' => 'blocked' ];
        }
        $settings = OptionCas::read();
        $document = DesignDocumentAdapter::read();
        if ( 'ok' !== ( $settings['status'] ?? '' )
            || 'found' !== ( $document['status'] ?? '' )
            || ! Repository::valid_revision( (string) ( $document['revision'] ?? '' ), false ) ) {
            return [ 'status' => 'recovery_required' ];
        }
        return [ 'status' => 'ok', 'witness' => [
            'settings_sha256' => hash( 'sha256', $settings['raw'] ),
            'document_revision' => $document['revision'],
        ] ];
    }

    /**
     * Build just the represented Report fields from the fresh canonical values.
     * Every unrepresented branding setting and any other document field survives.
     *
     * @return array{status:string,settings?:array,document?:array}
     */
    public static function staged( array $stored_settings, array $stored_document, array $incoming ): array {
        if ( ! DesignDocumentAdapter::valid_document( $stored_document )
            || ! isset( $stored_settings['reports'] ) || ! is_array( $stored_settings['reports'] )
            || ! isset( $stored_settings['reports']['email_alerts'] )
            || ! is_array( $stored_settings['reports']['email_alerts'] ) ) {
            return [ 'status' => 'invalid' ];
        }
        $settings = $stored_settings;
        $settings['reports']['retention_days'] = $incoming['retention_days'];
        $settings['reports']['email_alerts']['generation_failed'] = $incoming['email_alerts']['generation_failed'];

        $document = $stored_document;
        foreach ( [ 'provider_name', 'provider_contact', 'accent_color' ] as $field ) {
            $document['branding'][ $field ] = $incoming['branding'][ $field ];
        }
        $document['composer']['maintenance'] = $incoming['composer']['maintenance'];
        if ( ! DesignDocumentAdapter::valid_document( $document ) ) {
            return [ 'status' => 'invalid' ];
        }
        return [ 'status' => 'ok', 'settings' => $settings, 'document' => $document ];
    }

    /**
     * Source-ready isolated canonical transaction. Not invoked by Engine while
     * Active Profiles Apply is on HOLD. Callers must pass the preview witness.
     *
     * @param array<string,mixed> $incoming normalized Reports Profile section
     * @param array<string,string> $expected exact opaque preview/after witness
     * @return array{status:string,witness?:array<string,string>,reason?:string}
     */
    public static function apply_guarded( array $incoming, array $expected ): array {
        global $wpdb;

        if ( 'active' !== ( Authority::inspect()['status'] ?? '' )
            || ! isset( $wpdb ) || ! is_object( $wpdb )
            || ! is_string( $wpdb->options ?? null ) ) {
            return [ 'status' => 'blocked' ];
        }

        // Never enter someone else's transaction, reuse a non-InnoDB table,
        // or run schema introspection while holding a row lock.
        if ( ! Schema::healthy() || ! self::options_innodb() || ! self::owns_idle_connection() ) {
            return [ 'status' => 'recovery_required', 'reason' => 'storage_unavailable' ];
        }

        $read = OptionCas::read();
        $doc = DesignDocumentAdapter::read();
        if ( 'ok' !== ( $read['status'] ?? '' ) || 'found' !== ( $doc['status'] ?? '' ) ) {
            return [ 'status' => 'recovery_required', 'reason' => 'document_unavailable' ];
        }
        if ( hash( 'sha256', $read['raw'] ) !== ( $expected['settings_sha256'] ?? '' )
            || (string) $doc['revision'] !== ( $expected['document_revision'] ?? '' ) ) {
            return [ 'status' => 'conflict' ];
        }
        if ( ! Repository::valid_revision( (string) $doc['revision'], false )
            || (string) $doc['revision'] === (string) PHP_INT_MAX ) {
            return [ 'status' => 'recovery_required', 'reason' => 'revision_unavailable' ];
        }

        // Validate the complete Profile section BEFORE entering SQL ownership.
        try {
            $section = new Sections\ReportsSection();
            $incoming = $section->normalize( $incoming );
        } catch ( \Throwable $error ) {
            return [ 'status' => 'invalid' ];
        }
        $stage = self::staged( $read['value'], $doc['document'], $incoming );
        if ( 'ok' !== ( $stage['status'] ?? '' ) ) {
            return [ 'status' => 'invalid' ];
        }

        $next_settings = $stage['settings'];
        $next_document = $stage['document'];
        $settings_changed = $read['value'] !== $next_settings;
        $document_changed = $doc['document'] !== $next_document;
        if ( ! $settings_changed && ! $document_changed ) {
            return [ 'status' => 'unchanged' ];
        }

        $options_raw = $read['raw'];
        $options_after = $options_raw;
        if ( $settings_changed ) {
            // Exactly the native sanitize -> named -> general filter order.
            // Any partial overwrite or widening is a refusal, not an import.
            $name = CB_CORE_SETTINGS;
            $filtered = sanitize_option( $name, $next_settings );
            $filtered = apply_filters( "pre_update_option_{$name}", $filtered, $read['value'], $name );
            $filtered = apply_filters( 'pre_update_option', $filtered, $name, $read['value'] );
            if ( ! is_array( $filtered ) || $filtered !== $next_settings ) {
                return [ 'status' => 'refused' ];
            }
            $options_after = maybe_serialize( $filtered );
            if ( ! is_string( $options_after ) ) {
                return [ 'status' => 'invalid' ];
            }
        }

        $document_after = wp_json_encode( $next_document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $document_after ) || '' === $document_after
            || strlen( $document_after ) > self::MAX_DOCUMENT_BYTES ) {
            return [ 'status' => 'invalid' ];
        }
        $document_hash = hash( 'sha256', Repository::REPORTS . "\0" . DesignDocumentAdapter::KEY );
        $revision_before = (string) $doc['revision'];
        $revision_after = (string) ( (int) $revision_before + ( $document_changed ? 1 : 0 ) );

        // WordPress prewrite action is outside the two-row transaction.
        if ( $settings_changed ) {
            do_action( 'update_option', CB_CORE_SETTINGS, $read['value'], $next_settings );
        }

        if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
            return [ 'status' => 'recovery_required', 'reason' => 'transaction_start' ];
        }

        $options_table = $wpdb->options;
        $documents_table = Schema::table_name();
        $locked_options = $wpdb->get_row( $wpdb->prepare(
            'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s LIMIT 1 FOR UPDATE',
            $options_table, CB_CORE_SETTINGS
        ), ARRAY_A );
        $locked_document = $wpdb->get_row( $wpdb->prepare(
            'SELECT identity_hash, document_type, document_key, payload_schema, revision, document_json FROM %i WHERE identity_hash = %s LIMIT 1 FOR UPDATE',
            $documents_table, $document_hash
        ), ARRAY_A );

        if ( ! is_array( $locked_options ) || ! is_array( $locked_document ) ) {
            return self::abort( 'recovery_required', 'locked_row_missing' );
        }
        if ( $locked_options['option_name'] !== CB_CORE_SETTINGS
            || ! is_string( $locked_options['option_value'] ?? null )
            || $locked_options['option_value'] !== $options_raw
            || ( $locked_options['autoload'] ?? null ) !== $read['autoload']
            || ( $locked_document['identity_hash'] ?? null ) !== $document_hash
            || ( $locked_document['document_type'] ?? null ) !== Repository::REPORTS
            || ( $locked_document['document_key'] ?? null ) !== DesignDocumentAdapter::KEY
            || (string) ( $locked_document['payload_schema'] ?? '' ) !== '1'
            || (string) ( $locked_document['revision'] ?? '' ) !== $revision_before ) {
            return self::abort( 'conflict', 'revision_mismatch' );
        }

        if ( $settings_changed ) {
            $saved = $wpdb->query( $wpdb->prepare(
                'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s AND autoload = %s',
                $options_table, $options_after, CB_CORE_SETTINGS, $options_raw, $read['autoload']
            ) );
            if ( 1 !== $saved ) {
                return self::abort( false === $saved ? 'failed' : 'conflict', 'settings_write' );
            }
        }
        if ( $document_changed ) {
            $saved = $wpdb->query( $wpdb->prepare(
                'UPDATE %i SET document_json = %s, revision = revision + 1, updated_at = %s WHERE identity_hash = %s AND document_type = %s AND document_key = %s AND revision = CAST(%s AS UNSIGNED) AND BINARY document_json = BINARY %s',
                $documents_table, $document_after, gmdate( 'Y-m-d H:i:s' ),
                $document_hash, Repository::REPORTS, DesignDocumentAdapter::KEY,
                $revision_before, $locked_document['document_json']
            ) );
            if ( 1 !== $saved ) {
                return self::abort( false === $saved ? 'failed' : 'conflict', 'document_write' );
            }
        }

        // Verify both rows inside our own transaction before committing.
        $check_settings = $wpdb->get_row( $wpdb->prepare(
            'SELECT option_value, autoload FROM %i WHERE option_name = %s LIMIT 1',
            $options_table, CB_CORE_SETTINGS
        ), ARRAY_A );
        $check_document = $wpdb->get_row( $wpdb->prepare(
            'SELECT revision, document_json FROM %i WHERE identity_hash = %s LIMIT 1',
            $documents_table, $document_hash
        ), ARRAY_A );
        if ( ! is_array( $check_settings ) || ! is_array( $check_document )
            || ( $check_settings['option_value'] ?? null ) !== $options_after
            || ( $check_settings['autoload'] ?? null ) !== $read['autoload']
            || (string) ( $check_document['revision'] ?? '' ) !== $revision_after
            || ( $document_changed && ( $check_document['document_json'] ?? null ) !== $document_after ) ) {
            return self::abort( 'recovery_required', 'precommit_readback' );
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            self::rollback();
            return [ 'status' => 'recovery_required', 'reason' => 'commit_uncertain' ];
        }

        if ( $settings_changed ) {
            foreach ( [ CB_CORE_SETTINGS, 'alloptions', 'notoptions' ] as $cache_key ) {
                wp_cache_delete( $cache_key, 'options' );
                $found = false;
                wp_cache_get( $cache_key, 'options', false, $found );
                if ( $found ) {
                    return [ 'status' => 'recovery_required', 'reason' => 'cache_uncertain' ];
                }
            }
        }

        $post_settings = OptionCas::read();
        $post_document = DesignDocumentAdapter::read();
        if ( 'ok' !== ( $post_settings['status'] ?? '' )
            || 'found' !== ( $post_document['status'] ?? '' )
            || $post_settings['raw'] !== $options_after
            || $post_document['revision'] !== $revision_after
            || $post_document['document'] !== $next_document ) {
            return [ 'status' => 'recovery_required', 'reason' => 'postcommit_uncertain' ];
        }

        if ( $settings_changed ) {
            Settings::accept_cas( $next_settings );
            // No postcommit callback inside InnoDB ownership.
            try {
                do_action( "update_option_" . CB_CORE_SETTINGS, $read['value'], $next_settings, CB_CORE_SETTINGS );
                do_action( 'updated_option', CB_CORE_SETTINGS, $read['value'], $next_settings );
            } catch ( \Throwable $error ) {
                return [ 'status' => 'recovery_required', 'reason' => 'posthook_uncertain' ];
            }
        }

        return [ 'status' => 'saved', 'witness' => [
            'settings_sha256' => hash( 'sha256', $options_after ),
            'document_revision' => $revision_after,
        ] ];
    }

    /** Require the exact post-apply pair before any conditional compensation. */
    public static function reverse_guarded( array $snapshot, array $after_witness ): array {
        return self::apply_guarded( $snapshot, $after_witness );
    }

    private static function owns_idle_connection(): bool {
        global $wpdb;
        $active = $wpdb->get_var( 'SELECT @@in_transaction' );
        return '0' === (string) $active;
    }

    private static function options_innodb(): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
            $wpdb->options
        ), ARRAY_A );
        return is_array( $row ) && 'innodb' === strtolower( (string) ( $row['engine'] ?? '' ) );
    }

    /** The entire old two-row pair remains unchanged when ROLLBACK succeeds. */
    private static function abort( string $status, string $reason ): array {
        return self::rollback()
            ? [ 'status' => $status, 'reason' => $reason ]
            : [ 'status' => 'recovery_required', 'reason' => 'rollback_uncertain' ];
    }

    private static function rollback(): bool {
        global $wpdb;
        return false !== $wpdb->query( 'ROLLBACK' );
    }

    private function __construct() {}
}
