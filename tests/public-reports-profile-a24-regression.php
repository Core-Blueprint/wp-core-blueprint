<?php
declare(strict_types=1);

/**
 * A2.4-S2 source-only profile evidence. In-memory projections only.
 * Never a substitute for installed WordPress or two-worker MariaDB Level 2.
 */
namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    function __( string $message, string $domain = '' ): string { return $message; }
    function sanitize_text_field( string $s ): string { return $s; }
    function sanitize_hex_color( string $s ): ?string { return preg_match( '/^#[0-9a-fA-F]{6}$/D', $s ) ? strtolower( $s ) : null; }
    function maybe_serialize( mixed $s ): string { return serialize( $s ); }
    function wp_json_encode( mixed $s, int $flags = 0 ): string|false { return json_encode( $s, $flags ); }
    function sanitize_option( string $name, mixed $value ): mixed { return $value; }
    function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
        if ( 'pre_update_option_cb_core_settings' === $hook && ! empty( $GLOBALS['s2_filter_rewrite'] ) ) {
            $value['reports']['retention_days'] = 777;
        }
        return $value;
    }
    function do_action( string $hook, mixed ...$args ): void { $GLOBALS['s2_actions'][] = $hook; }
    function wp_cache_delete( string $key, string $group = '' ): bool { return true; }
    function wp_cache_get( string $key, string $group = '', bool $force = false, ?bool &$found = null ): mixed {
        $found = false; return false;
    }
    define( 'CB_CORE_SETTINGS', 'cb_core_settings' );
    define( 'ARRAY_A', 'ARRAY_A' );
    $GLOBALS['s2_actions'] = [];
    $GLOBALS['s2_filter_rewrite'] = false;
}
namespace CoreBlueprint\Core {
    final class Settings {
        public static array $values = [ 'reports' => [
            'retention_days' => 365,
            'email_alerts' => [ 'generation_failed' => false, 'other' => true ],
            'branding' => [ 'legacy_logo_id' => 420 ],
        ] ];
        public static function get(): array { return self::$values; }
        public static function accept_cas( array $value ): void { self::$values = $value; }
    }
}
namespace CoreBlueprint\Core\Settings {
    final class OptionCas {
        public static array $row = [];
        public static function read(): array { return self::$row; }
    }
}
namespace CoreBlueprint\Core\Design\Document {
    final class Authority {
        public static string $state = 'active';
        public static function inspect(): array { return [ 'status' => self::$state ]; }
    }
    final class Repository {
        public const REPORTS = 'base.maintenance-report-design';
        public static function valid_revision( string $revision, bool $zero = true ): bool {
            return preg_match( '/^[1-9][0-9]*$/D', $revision ) === 1
                && strlen( $revision ) <= 19 && (int) $revision <= PHP_INT_MAX;
        }
    }
    final class Schema {
        public static function healthy(): bool { return true; }
        public static function table_name(): string { return 'wp_cb_design_documents'; }
    }
}
namespace CoreBlueprint\Core\Reports\Composer {
    final class MaintenanceTemplate {
        public static function current(): array { return [ 'schema_version' => 1, 'blocks' => [] ]; }
        public static function normalize( array $value ): array { return $value; }
    }
}
namespace CoreBlueprint\Core\Reports {
    final class DesignDocumentAdapter {
        public static array $record = [
            'revision' => '9007199254740993',
            'branding' => [
                'logo_attachment_id' => 0, 'show_logo' => false,
                'provider_name' => 'old', 'provider_contact' => 'private@example.test',
                'accent_color' => '#0064c8', 'surface_style' => 'cards',
                'density' => 'comfortable', 'corner_style' => 'soft', 'text_scale' => 'standard',
            ],
            'composer' => [ 'schema_version' => 1, 'blocks' => [] ],
        ];
        public static function current(): array { return self::$record + [ 'authority' => 'active' ]; }
        public static function read(): array { return [
            'status' => 'found', 'revision' => self::$record['revision'],
            'document' => [
                'branding' => self::$record['branding'],
                'composer' => [ 'maintenance' => self::$record['composer'] ],
            ],
        ]; }
        public static function valid_document( mixed $document ): bool {
            return is_array( $document ) && isset( $document['branding'], $document['composer']['maintenance'] );
        }
    }
}
namespace CoreBlueprint\Core\Profiles {
    interface SectionInterface {
        public function id(): string;
        public function label(): string;
        public function description(): string;
        public function schema_version(): int;
        public function supports_schema_version( int $version ): bool;
        public function migrate( array $incoming, int $version ): array;
        public function export(): array;
        public function normalize( array $incoming ): array;
        public function preflight( array $incoming, array $current ): void;
        public function snapshot(): array;
        public function warnings( array $incoming ): array;
        public function preview( array $current, array $incoming ): array;
        public function apply( array $incoming, string $actor ): void;
        public function restore( array $snapshot, array $incoming, string $actor ): void;
        public function verify( array $incoming ): bool;
    }
    final class CanonicalJson {
        public static function encode( array $array ): string { return json_encode( $array, JSON_THROW_ON_ERROR ); }
    }
    final class Document {
        public const FORMAT = 'core-blueprint-profile';
        public const FORMAT_VERSION = 1;
        public const MAX_CHANGES = 100;
        public static function decode( string $json ): array { return json_decode( $json, true, 32, JSON_THROW_ON_ERROR ); }
    }
    final class SectionRegistry {
        public static function all(): array {
            return [ 'reports' => new \CoreBlueprint\Core\Profiles\Sections\ReportsSection() ];
        }
    }
    final class ApplyLock {
        public static int $acquisitions = 0;
        public static function acquire( string $actor ): string {
            self::$acquisitions++;
            throw new \RuntimeException( 'Active Profile test must never acquire a lock' );
        }
    }
    final class SchemaGuard {
        public static function exact_keys( array $v, array $allowed, string $label ): void {
            if ( array_diff( array_keys( $v ), $allowed ) || array_diff( $allowed, array_keys( $v ) ) ) {
                throw new \InvalidArgumentException( 'invalid section keys' );
            }
        }
        public static function object( mixed $v, string $label ): array {
            if ( ! is_array( $v ) ) { throw new \InvalidArgumentException( 'invalid section object' ); }
            return $v;
        }
        public static function int( mixed $v, string $label ): int {
            if ( ! is_int( $v ) ) { throw new \InvalidArgumentException( 'invalid section integer' ); }
            return $v;
        }
        public static function string( mixed $v, string $label ): string {
            if ( ! is_string( $v ) ) { throw new \InvalidArgumentException( 'invalid section string' ); }
            return $v;
        }
        public static function bool( mixed $v, string $label ): bool {
            if ( ! is_bool( $v ) ) { throw new \InvalidArgumentException( 'invalid section bool' ); }
            return $v;
        }
        public static function invalid_value( string $label ): never { throw new \InvalidArgumentException( 'invalid value' ); }
    }
    abstract class ExactSection implements SectionInterface {
        public function supports_schema_version( int $v ): bool { return $v === $this->schema_version(); }
        public function migrate( array $v, int $source ): array { return $this->normalize( $v ); }
        public function preflight( array $i, array $c ): void {}
        public function snapshot(): array { return $this->export(); }
        public function warnings( array $i ): array { return []; }
        public function preview( array $c, array $i ): array { return []; }
        public function restore( array $s, array $i, string $a ): void { $this->apply( $s, $a ); }
        public function verify( array $i ): bool { return $this->export() === $i; }
    }
}
namespace {
    /**
     * Fake transactional SQL store with two independent row identities.
     * Only the exact prepared queries emitted by the production source pass.
     */
    final class S2FakeDb {
        public string $options = 'wp_options';
        public string $last_error = '';
        public bool $fail_document = false;
        public bool $fail_commit = false;
        public bool $in_transaction = false;
        public mixed $before_lock = null;
        public int $option_writes = 0;
        public int $document_writes = 0;
        private array $statements = [];
        private ?array $backup = null;
        public function prepare( string $sql, mixed ...$args ): string {
            $key = 'stmt-' . count( $this->statements );
            $this->statements[$key] = [ $sql, $args ];
            return $key;
        }
        public function get_var( string $sql ): string {
            if ( 'SELECT @@in_transaction' !== $sql ) { throw new \RuntimeException( 'Unexpected transaction probe' ); }
            return $this->in_transaction ? '1' : '0';
        }
        public function get_row( string $sql, mixed $output = null ): ?array {
            [ $query, $args ] = $this->statements[$sql] ?? [ $sql, [] ];
            if ( str_contains( $query, 'information_schema.TABLES' ) ) { return [ 'engine' => 'InnoDB' ]; }
            if ( str_contains( $query, 'FROM %i' ) && ( $args[0] ?? null ) === $this->options ) {
                $row = \CoreBlueprint\Core\Settings\OptionCas::$row;
                return [
                    'option_name' => CB_CORE_SETTINGS,
                    'option_value' => $row['raw'],
                    'autoload' => $row['autoload'],
                ];
            }
            if ( str_contains( $query, 'FROM %i' )
                && ( $args[0] ?? null ) === \CoreBlueprint\Core\Design\Document\Schema::table_name() ) {
                $row = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record;
                return [
                    'identity_hash' => hash( 'sha256', \CoreBlueprint\Core\Design\Document\Repository::REPORTS . "\0" . 'maintenance' ),
                    'document_type' => \CoreBlueprint\Core\Design\Document\Repository::REPORTS,
                    'document_key' => 'maintenance',
                    'payload_schema' => 1,
                    'revision' => $row['revision'],
                    'document_json' => json_encode( [ 'branding' => $row['branding'], 'composer' => [ 'maintenance' => $row['composer'] ] ] ),
                ];
            }
            throw new \RuntimeException( 'Unexpected S2 read query' );
        }
        public function query( string $sql ): int|false {
            if ( 'START TRANSACTION' === $sql ) {
                if ( $this->in_transaction ) { return false; }
                if ( is_callable( $this->before_lock ) ) {
                    $callback = $this->before_lock;
                    $this->before_lock = null;
                    $callback();
                }
                $this->backup = [
                    \CoreBlueprint\Core\Settings\OptionCas::$row,
                    \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record,
                ];
                $this->in_transaction = true;
                return 0;
            }
            if ( 'ROLLBACK' === $sql ) {
                if ( ! $this->in_transaction ) { return false; }
                \CoreBlueprint\Core\Settings\OptionCas::$row = $this->backup[0];
                \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record = $this->backup[1];
                $this->in_transaction = false;
                $this->backup = null;
                return 0;
            }
            if ( 'COMMIT' === $sql ) {
                if ( $this->fail_commit ) { return false; }
                $this->backup = null;
                $this->in_transaction = false;
                return 0;
            }
            if ( ! isset( $this->statements[$sql] ) || ! $this->in_transaction ) {
                throw new \RuntimeException( 'Unexpected S2 SQL or transaction ownership' );
            }
            [ $query, $args ] = $this->statements[$sql];
            if ( str_contains( $query, 'SET option_value = %s' ) ) {
                [ $table, $raw, $name, $old, $autoload ] = $args;
                $row = &\CoreBlueprint\Core\Settings\OptionCas::$row;
                if ( $row['raw'] !== $old || $row['autoload'] !== $autoload ) { return 0; }
                $row['raw'] = $raw;
                $row['value'] = unserialize( $raw, [ 'allowed_classes' => false ] );
                $this->option_writes++;
                return 1;
            }
            if ( str_contains( $query, 'SET document_json = %s' ) ) {
                if ( $this->fail_document ) { return false; }
                $before = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record;
                if ( $before['revision'] !== $args[6] ) { return 0; }
                $next = json_decode( $args[1], true, 128, JSON_THROW_ON_ERROR );
                \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['revision'] = (string) ( (int) $before['revision'] + 1 );
                \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding'] = $next['branding'];
                \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['composer'] = $next['composer']['maintenance'];
                $this->document_writes++;
                return 1;
            }
            throw new \RuntimeException( 'Unexpected S2 update query' );
        }
    }

    require_once dirname( __DIR__ ) . '/src/Profiles/VersionGuardedSectionInterface.php';
    require_once dirname( __DIR__ ) . '/src/Profiles/Sections/ReportsSection.php';
    require_once dirname( __DIR__ ) . '/src/Profiles/ReportsProfileTransaction.php';
    require_once dirname( __DIR__ ) . '/src/Profiles/Engine.php';

    function s2_assert( bool $pass, string $label ): void {
        if ( ! $pass ) { throw new \RuntimeException( 'A2.4-S2 Reports Profile: ' . $label ); }
    }
    \CoreBlueprint\Core\Settings\OptionCas::$row = [
        'status' => 'ok', 'raw' => serialize( \CoreBlueprint\Core\Settings::$values ),
        'autoload' => 'on', 'table' => 'wp_options',
        'value' => \CoreBlueprint\Core\Settings::$values,
    ];
    $section = new \CoreBlueprint\Core\Profiles\Sections\ReportsSection();
    $current = $section->export();
    $incoming = $section->normalize( $current );
    s2_assert( $incoming === $current, 'round-trip of represented Reports schema' );
    // Test-first RED: a healthy active canonical document must support
    // READ-ONLY preview. It must not activate mutation/import.
    $section->preflight( $incoming, $current );
    s2_assert( $section->export() === $current, 'active read-only preflight leaves state unchanged' );
    $profile_document = [
        'format' => \CoreBlueprint\Core\Profiles\Document::FORMAT,
        'format_version' => \CoreBlueprint\Core\Profiles\Document::FORMAT_VERSION,
        'profile' => [ 'name' => 'Reports fixture', 'description' => '' ],
        'sections' => [ 'reports' => [ 'schema_version' => 1, 'data' => $current ] ],
    ];
    $preview = \CoreBlueprint\Core\Profiles\Engine::preview( $profile_document );
    s2_assert( isset( $preview['fingerprint'], $preview['witnesses']['reports'] ), 'preview binds opaque Reports revision' );
    $before_revision = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['revision'];
    \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['revision'] = '9007199254740994';
    $advanced = \CoreBlueprint\Core\Profiles\Engine::preview( $profile_document );
    s2_assert( $preview['fingerprint'] !== $advanced['fingerprint'], 'same content at newer document revision invalidates preview' );
    \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['revision'] = $before_revision;
    $active_blocked = false;
    try { \CoreBlueprint\Core\Profiles\Engine::apply( $profile_document, $preview['fingerprint'], 'fixture' ); }
    catch ( \RuntimeException $error ) { $active_blocked = true; }
    s2_assert( $active_blocked, 'Profiles Engine blocks active Reports apply' );
    s2_assert( 0 === \CoreBlueprint\Core\Profiles\ApplyLock::$acquisitions, 'active apply blocked before mutation lock' );
    $witness = $section->version_witness();
    s2_assert(
        '9007199254740993' === $witness['document_revision'],
        'decimal revision above JavaScript safe integer is never rounded'
    );

    // Test the real isolated transaction's pure preparation boundary, not
    // a fake second document writer. Unrepresented fields remain identical.
    $desired = $incoming;
    $desired['retention_days'] = 900;
    $desired['email_alerts']['generation_failed'] = true;
    $desired['branding']['provider_name'] = 'new';
    $document = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::read()['document'];
    $stage = \CoreBlueprint\Core\Profiles\ReportsProfileTransaction::staged(
        \CoreBlueprint\Core\Settings::$values, $document, $desired
    );
    s2_assert( 'ok' === $stage['status'], 'canonical Reports section stages safely' );
    s2_assert( 900 === $stage['settings']['reports']['retention_days'], 'represented retention staged' );
    s2_assert( true === $stage['settings']['reports']['email_alerts']['generation_failed'], 'represented alert staged' );
    s2_assert( true === $stage['settings']['reports']['email_alerts']['other'], 'other alert preserved' );
    s2_assert( 420 === $stage['settings']['reports']['branding']['legacy_logo_id'], 'legacy branding preserved' );
    s2_assert( 'new' === $stage['document']['branding']['provider_name'], 'represented canonical branding staged' );
    s2_assert( false === $stage['document']['branding']['show_logo'], 'unrepresented show_logo preserved' );
    s2_assert( 'comfortable' === $stage['document']['branding']['density'], 'unrepresented presentation preserved' );
    s2_assert( '9007199254740993' === $section->version_witness()['document_revision'], 'stage is read-only' );
    $blocked = false;
    try { $section->apply( $incoming, 'source-test' ); }
    catch ( \RuntimeException $e ) { $blocked = true; }
    s2_assert( $blocked, 'active Reports Profile apply remains blocked' );
    s2_assert(
        true === \CoreBlueprint\Core\Settings\OptionCas::read()['value']['reports']['email_alerts']['other'],
        'unrepresented alert stays unchanged'
    );
    // Direct INTERNAL transaction fixture. ReportsSection::apply() above remains
    // blocked for active authority; this does not enable a real WP import.
    $wpdb = new S2FakeDb();
    $transaction = \CoreBlueprint\Core\Profiles\ReportsProfileTransaction::class;
    $before_settings = \CoreBlueprint\Core\Settings\OptionCas::$row;
    $before_doc = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record;
    $expected = [
        'settings_sha256' => hash( 'sha256', $before_settings['raw'] ),
        'document_revision' => $before_doc['revision'],
    ];
    $saved = $transaction::apply_guarded( $desired, $expected );
    s2_assert( 'saved' === $saved['status'], 'atomic two-row transaction committed' );
    s2_assert( 1 === $wpdb->option_writes && 1 === $wpdb->document_writes, 'each intended row updated once' );
    s2_assert( 900 === \CoreBlueprint\Core\Settings\OptionCas::$row['value']['reports']['retention_days'], 'committed retention verified' );
    s2_assert( true === \CoreBlueprint\Core\Settings\OptionCas::$row['value']['reports']['email_alerts']['other'], 'unrepresented Settings alert retained' );
    s2_assert( false === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['show_logo'], 'unrepresented canonical logo field retained' );
    s2_assert( '9007199254740994' === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['revision'], 'decimal revision increment is exact' );
    s2_assert( [ 'update_option', 'update_option_cb_core_settings', 'updated_option' ] === $GLOBALS['s2_actions'], 'native prewrite and postcommit ordering preserved' );

    $after_settings = \CoreBlueprint\Core\Settings\OptionCas::$row;
    $after_document = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record;
    $second = $desired;
    $second['retention_days'] = 901;
    $second['branding']['provider_name'] = 'second';
    // Native prefilter attempted rewrite may never partially confirm Reports.
    $GLOBALS['s2_filter_rewrite'] = true;
    $prefiltered = $transaction::apply_guarded( $second, $saved['witness'] );
    s2_assert( 'refused' === $prefiltered['status'], 'partial native prefilter rewrite refused before SQL' );
    s2_assert( $after_settings === \CoreBlueprint\Core\Settings\OptionCas::$row, 'prefilter refusal leaves Settings unchanged' );
    s2_assert( $after_document === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record, 'prefilter refusal leaves document unchanged' );
    $GLOBALS['s2_filter_rewrite'] = false;
    $wpdb->fail_document = true;
    $actions_before = count( $GLOBALS['s2_actions'] );
    $failed = $transaction::apply_guarded( $second, $saved['witness'] );
    s2_assert( 'failed' === $failed['status'], 'document SQL failure triggers rollback' );
    s2_assert( $after_settings === \CoreBlueprint\Core\Settings\OptionCas::$row, 'failed second write restores entire Settings row' );
    s2_assert( $after_document === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record, 'failed second write preserves canonical document and revision' );
    s2_assert( $actions_before + 1 === count( $GLOBALS['s2_actions'] ), 'failed transaction has prewrite only, not success hooks' );
    $wpdb->fail_document = false;

    $wpdb->fail_commit = true;
    $uncertain = $transaction::apply_guarded( $second, $saved['witness'] );
    s2_assert( 'recovery_required' === $uncertain['status'], 'uncertain COMMIT is never success' );
    s2_assert( $after_settings === \CoreBlueprint\Core\Settings\OptionCas::$row, 'fake failed COMMIT rollback restores Settings' );
    s2_assert( $after_document === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record, 'fake failed COMMIT rollback restores design row' );
    $wpdb->fail_commit = false;

    $stale = $saved['witness'];
    $stale['document_revision'] = '9007199254740993';
    s2_assert( 'conflict' === $transaction::apply_guarded( $second, $stale )['status'], 'stale document revision blocked before transaction' );

    $wpdb->in_transaction = true;
    s2_assert( 'recovery_required' === $transaction::apply_guarded( $second, $saved['witness'] )['status'], 'borrowed transaction refused' );
    s2_assert( true === $wpdb->in_transaction, 'never roll back someone else transaction' );
    $wpdb->in_transaction = false;

    // A writer arriving after preflight but before lock must not be overwritten.
    $wpdb->before_lock = static function (): void {
        $row = &\CoreBlueprint\Core\Settings\OptionCas::$row;
        $row['value']['reports']['email_alerts']['other'] = false;
        $row['raw'] = serialize( $row['value'] );
    };
    $race = $transaction::apply_guarded( $second, $saved['witness'] );
    s2_assert( 'conflict' === $race['status'], 'cross-row prelock race refused' );
    s2_assert( false === \CoreBlueprint\Core\Settings\OptionCas::$row['value']['reports']['email_alerts']['other'], 'independent writer survives conflict' );
    s2_assert( $after_document === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record, 'cross-row conflict preserves doc' );
    $reverse = $transaction::reverse_guarded( $current, $saved['witness'] );
    s2_assert( 'conflict' === $reverse['status'], 'reverse CAS refuses post-apply state changed by another writer' );
    s2_assert( false === \CoreBlueprint\Core\Settings\OptionCas::$row['value']['reports']['email_alerts']['other'], 'reverse CAS cannot overwrite independent writer' );

    echo "Core Blueprint Base A2.4-S2 Reports Profile source-only regression PASS\n";
}
