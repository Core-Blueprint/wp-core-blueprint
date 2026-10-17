<?php
declare(strict_types=1);

/**
 * A2.4-S1 source-only proof of the central WordPress option CAS boundary.
 * Fake DB and hooks only: not Level 2, real WP, persistent cache or InnoDB.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'CB_CORE_SETTINGS', 'cb_core_settings' );

use CoreBlueprint\Core\Settings\OptionCas;

$GLOBALS['s1_hooks'] = [];
$GLOBALS['s1_veto'] = false;
$GLOBALS['s1_widen'] = false;
$GLOBALS['s1_reads'] = [];

function maybe_serialize( mixed $value ): string {
    return serialize( $value );
}
function maybe_unserialize( mixed $value ): mixed {
    return is_string( $value ) ? unserialize( $value, [ 'allowed_classes' => false ] ) : $value;
}
function sanitize_option( string $name, mixed $value ): mixed {
    $GLOBALS['s1_hooks'][] = 'sanitize:' . $name;
    return $value;
}
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
    $GLOBALS['s1_hooks'][] = 'filter:' . $hook;
    if ( 'pre_update_option_cb_core_settings' === $hook && $GLOBALS['s1_veto'] ) {
        return $args[0];
    }
    if ( 'pre_update_option_cb_core_settings' === $hook && $GLOBALS['s1_widen'] ) {
        $value['unrelated'] = 'hook-widened';
    }
    return $value;
}
function do_action( string $hook, mixed ...$args ): void {
    $GLOBALS['s1_hooks'][] = 'action:' . $hook;
}
function wp_cache_delete( string $key, string $group = '' ): bool {
    $GLOBALS['s1_hooks'][] = 'cache:' . $group . '/' . $key;
    return true;
}
final class SettingsFakeDb {
    public string $options = 'wp_options';
    public string $last_error = '';
    public array $rows = [];
    public bool $fail_write = false;
    public mixed $before_write = null;
    public int $writes = 0;
    private array $statements = [];

    public function prepare( string $sql, mixed ...$args ): string {
        $key = 'statement:' . count( $this->statements );
        $this->statements[$key] = [ $sql, $args ];
        return $key;
    }
    public function get_row( string $statement, mixed $output = null ): ?array {
        [ $sql, $args ] = $this->statements[$statement];
        if ( ! str_contains( $sql, 'FROM %i' ) || ! str_contains( $sql, 'option_value' ) ) {
            throw new RuntimeException( 'Unrecognized SELECT; no real SQL executed.' );
        }
        [ $table, $name ] = $args;
        return $this->rows[$table][$name] ?? null;
    }
    public function query( string $statement ): int|false {
        [ $sql, $args ] = $this->statements[$statement];
        $this->writes++;
        if ( ! str_contains( $sql, 'BINARY option_value' ) ) {
            throw new RuntimeException( 'Expected binary-aware CAS, not collation-sensitive UPDATE.' );
        }
        if ( is_callable( $this->before_write ) ) {
            $cb = $this->before_write;
            $this->before_write = null;
            $cb( $this );
        }
        if ( $this->fail_write ) {
            $this->last_error = 'injected query failure';
            return false;
        }
        [ $table, $raw_new, $option, $raw_expected, $autoload ] = $args;
        $row = $this->rows[$table][$option] ?? null;
        if ( ! is_array( $row ) || $row['option_value'] !== $raw_expected || $row['autoload'] !== $autoload ) {
            return 0;
        }
        $this->rows[$table][$option]['option_value'] = $raw_new;
        return 1;
    }
    public function seed( mixed $value, string $autoload = 'on' ): void {
        $this->rows[$this->options][CB_CORE_SETTINGS] = [
            'option_name' => CB_CORE_SETTINGS,
            'option_value' => maybe_serialize( $value ),
            'autoload' => $autoload,
        ];
    }
    public function value(): mixed {
        return maybe_unserialize( $this->rows[$this->options][CB_CORE_SETTINGS]['option_value'] );
    }
}

/**
 * Tiny Settings projection and observer stand-in. Installed WordPress behavior
 * remains a separate Level 2 gate, not inferred from these source fixtures.
 */
final class SettingsProjectionStub {
    public static array $defaults = [];
    public static ?array $committed = null;
    public static function defaults(): array { return self::$defaults; }
    public static function accept_cas( array $value ): void { self::$committed = $value; }
}
class_alias( SettingsProjectionStub::class, 'CoreBlueprint\\Core\\Settings' );

require_once dirname( __DIR__ ) . '/src/Settings/OptionCas.php';
function s1_assert( bool $pass, string $label ): void {
    if ( ! $pass ) {
        throw new RuntimeException( 'A2.4 S1 source CAS: ' . $label );
    }
}
function s1_reset(): void {
    $GLOBALS['s1_hooks'] = [];
    $GLOBALS['s1_veto'] = false;
    $GLOBALS['s1_widen'] = false;
}
$wpdb = new SettingsFakeDb();
$initial = [
    'reports' => [
        'retention_days' => 30,
        'email_alerts' => [ 'failure' => false ],
        'branding' => [ 'contact' => 'original' ],
    ],
    'modules' => [ 'scanner' => [ 'enabled' => true ] ],
];

$wpdb->seed( $initial, 'auto-on' );
$first = $initial;
$first['reports']['retention_days'] = 60;
s1_reset();
$result = OptionCas::write( $initial, $first );
s1_assert( 'saved' === $result['status'], 'changed option saved' );
s1_assert( 60 === $wpdb->value()['reports']['retention_days'], 'durable write' );
s1_assert( 'auto-on' === $wpdb->rows['wp_options'][CB_CORE_SETTINGS]['autoload'], 'autoload unchanged' );
$events = $GLOBALS['s1_hooks'];
$expectedPrefix = [
    'sanitize:cb_core_settings',
    'filter:pre_update_option_cb_core_settings',
    'filter:pre_update_option',
    'action:update_option',
];
s1_assert( array_slice( $events, 0, 4 ) === $expectedPrefix, 'exact prehook order' );
s1_assert( in_array( 'cache:options/alloptions', $events, true ), 'autoload cache invalidation' );
s1_assert( in_array( 'cache:options/notoptions', $events, true ), 'negative cache invalidation' );
s1_assert( in_array( 'cache:options/cb_core_settings', $events, true ), 'per-option cache invalidation' );
s1_assert( array_slice( $events, -2 ) === [
    'action:update_option_cb_core_settings', 'action:updated_option',
], 'postcommit hook order' );

// Stale full-section intent must not replay unchanged siblings over fresh writes.
$second = $initial;
$second['reports']['email_alerts']['failure'] = true;
s1_reset();
$result = OptionCas::write( $initial, $second );
s1_assert( 'saved' === $result['status'], 'disjoint nested intent saved' );
s1_assert( 60 === $wpdb->value()['reports']['retention_days'], 'preserve independent retention' );
s1_assert( true === $wpdb->value()['reports']['email_alerts']['failure'], 'apply intended leaf' );
s1_assert( 'original' === $wpdb->value()['reports']['branding']['contact'], 'preserve omitted branding' );

// Same leaf stale author must not rewrite a newer value.
s1_reset();
$result = OptionCas::write( $initial, $first );
s1_assert( 'conflict' === $result['status'], 'same-leaf stale intent denied' );
s1_assert( ! in_array( 'action:updated_option', $GLOBALS['s1_hooks'], true ), 'no false posthook on conflict' );

// Native old-value refusal must be authoritative, with no SQL or post-hook.
$before = $wpdb->value();
$next = $before;
$next['reports']['retention_days'] = 90;
s1_reset();
$GLOBALS['s1_veto'] = true;
$writes = $wpdb->writes;
$result = OptionCas::write( $before, $next );
s1_assert( 'refused' === $result['status'], 'pre-update old-value veto honored' );
s1_assert( $writes === $wpdb->writes, 'veto never reaches SQL' );
s1_assert( ! in_array( 'action:update_option', $GLOBALS['s1_hooks'], true ), 'veto no pre-write action' );
s1_assert( ! in_array( 'action:updated_option', $GLOBALS['s1_hooks'], true ), 'veto no post-write action' );

s1_reset();
$writes = $wpdb->writes;
$result = OptionCas::write( $before, $before );
s1_assert( 'unchanged' === $result['status'], 'no-op distinct from saved' );
s1_assert( $writes === $wpdb->writes, 'no-op never writes' );
s1_assert( in_array( 'filter:pre_update_option_cb_core_settings', $GLOBALS['s1_hooks'], true ), 'pre-filter runs even for no-op' );
s1_assert( ! in_array( 'action:update_option', $GLOBALS['s1_hooks'], true ), 'no-op no pre-write action' );

// Filter-widened mutation is not an authorized Base change intent.
s1_reset();
$GLOBALS['s1_widen'] = true;
$result = OptionCas::write( $before, $next );
s1_assert( 'refused' === $result['status'], 'unapproved hook widening rejected' );
s1_assert( ! array_key_exists( 'unrelated', $wpdb->value() ), 'no extra persisted fields' );

// Interleaving another write between snapshot and CAS must be detected.
s1_reset();
$wpdb->before_write = static function ( SettingsFakeDb $db ): void {
    $latest = $db->value();
    $latest['modules']['scanner']['enabled'] = false;
    $db->seed( $latest, 'auto-on' );
};
$result = OptionCas::write( $before, $next );
s1_assert( 'conflict' === $result['status'], 'binary CAS fails on interleaving unrelated writer' );
s1_assert( false === $wpdb->value()['modules']['scanner']['enabled'], 'interleaved write survives' );
s1_assert( ! in_array( 'action:updated_option', $GLOBALS['s1_hooks'], true ), 'race does not send postcommit hook' );

// Existing invalid/falsey state cannot be treated as an absent first install.
$wpdb->seed( false );
s1_reset();
$result = OptionCas::write( $initial, $first );
s1_assert( 'invalid' === $result['status'], 'falsey existing option cannot be overwritten' );
$wpdb->rows = [];
$result = OptionCas::write( $initial, $first );
s1_assert( 'missing' === $result['status'], 'missing option requires explicit initialization' );

$wpdb->seed( $initial );
$wpdb->fail_write = true;
s1_reset();
$result = OptionCas::write( $initial, $first );
s1_assert( 'failed' === $result['status'], 'SQL write failure is not success' );
s1_assert( ! in_array( 'action:updated_option', $GLOBALS['s1_hooks'], true ), 'failed SQL has no post-commit hook' );
// The read projection may contain defaults that were never persisted.
// Committing one intended leaf must not insert all those defaults into storage.
$wpdb->fail_write = false;
$wpdb->rows = [];
$wpdb->seed( [ 'reports' => [ 'retention_days' => 30 ] ] );
SettingsProjectionStub::$defaults = [
    'reports' => [ 'retention_days' => 30, 'branding' => [ 'contact' => 'never-persisted' ] ],
    'modules' => [ 'scanner' => [ 'enabled' => true ] ],
];
$projected = SettingsProjectionStub::defaults();
$changed = $projected;
$changed['reports']['retention_days'] = 60;
s1_reset();
$result = OptionCas::write( $projected, $changed );
s1_assert( 'saved' === $result['status'], 'default-backed leaf update saved' );
s1_assert( [ 'reports' => [ 'retention_days' => 60 ] ] === $wpdb->value(), 'untouched defaults not written to storage' );
s1_assert( [ 'reports' => [ 'retention_days' => 60 ] ] === SettingsProjectionStub::$committed, 'post-commit observer sees committed raw state' );

echo "Core Blueprint Settings A2.4-S1 standalone CAS/refusal/no-op regression PASS\n";
