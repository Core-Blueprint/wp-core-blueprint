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
    function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $value; }
    function do_action( string $hook, mixed ...$args ): void { $GLOBALS['s2_actions'][] = $hook; }
    function wp_cache_delete( string $key, string $group = '' ): bool { return true; }
    function wp_cache_get( string $key, string $group = '', bool $force = false, ?bool &$found = null ): mixed {
        $found = false; return false;
    }
    define( 'CB_CORE_SETTINGS', 'cb_core_settings' );
    define( 'ARRAY_A', 'ARRAY_A' );
    $GLOBALS['s2_actions'] = [];
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
    require_once dirname( __DIR__ ) . '/src/Profiles/VersionGuardedSectionInterface.php';
    require_once dirname( __DIR__ ) . '/src/Profiles/Sections/ReportsSection.php';
    require_once dirname( __DIR__ ) . '/src/Profiles/ReportsProfileTransaction.php';

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
    echo "Core Blueprint Base A2.4-S2 Reports Profile source-only regression PASS\n";
}
