<?php
declare(strict_types=1);

/**
 * A2.4-S1 executable source-only Settings facade and first-party consumer
 * contract. No WordPress boot, actual database, Docker, network or disk writes.
 */
namespace {
    define( 'ABSPATH', __DIR__ . '/' );
    define( 'ARRAY_A', 'ARRAY_A' );
    define( 'CB_CORE_SETTINGS', 'cb_core_settings' );

    $GLOBALS['s1_option_veto'] = false;
    $GLOBALS['s1_partial_filter'] = false;
    $GLOBALS['s1_pre_write_race'] = null;
    $GLOBALS['s1_actions'] = [];

    function maybe_serialize( mixed $value ): string { return serialize( $value ); }
    function sanitize_option( string $key, mixed $value ): mixed { return $value; }
    function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
        if ( 'pre_update_option_cb_core_settings' === $hook && $GLOBALS['s1_option_veto'] ) {
            return $args[0];
        }
        if ( 'pre_update_option_cb_core_settings' === $hook && $GLOBALS['s1_partial_filter'] ) {
            $value['integrity']['admin_can_run'] = false;
        }
        return $value;
    }
    function do_action( string $hook, mixed ...$args ): void {
        $GLOBALS['s1_actions'][] = $hook;
    }
    function wp_cache_delete( string $key, string $group = '' ): bool { return true; }
    function wp_cache_get( string $key, string $group = '', bool $force = false, ?bool &$found = null ): mixed {
        $found = false;
        return false;
    }
    function sanitize_key( string $value ): string { return strtolower( $value ); }

    final class S1ConsumerFakeDb {
        public string $options = 'wp_options';
        public string $last_error = '';
        public array $rows = [];
        public int $writes = 0;
        public bool $deny = false;
        private array $prepared = [];

        public function prepare( string $sql, mixed ...$args ): string {
            $id = 'statement-' . count( $this->prepared );
            $this->prepared[$id] = [ $sql, $args ];
            return $id;
        }
        public function get_row( string $id, mixed $output = null ): ?array {
            [ $sql, $args ] = $this->prepared[$id];
            if ( ! str_starts_with( $sql, 'SELECT option_name' ) ) {
                throw new \RuntimeException( 'Unexpected fake SELECT.' );
            }
            return $this->rows[$args[0]][$args[1]] ?? null;
        }
        public function query( string $id ): int|false {
            [ $sql, $args ] = $this->prepared[$id];
            if ( ! str_contains( $sql, 'BINARY option_value' ) ) {
                throw new \RuntimeException( 'Conditional binary CAS required.' );
            }
            $this->writes++;
            if ( $this->deny ) {
                $this->last_error = 'injected failure';
                return false;
            }
            if ( is_callable( $GLOBALS['s1_pre_write_race'] ) ) {
                $race = $GLOBALS['s1_pre_write_race'];
                $GLOBALS['s1_pre_write_race'] = null;
                $race( $this );
            }
            [ $table, $new, $name, $old, $autoload ] = $args;
            $row = $this->rows[$table][$name] ?? null;
            if ( ! is_array( $row ) || $row['option_value'] !== $old || $row['autoload'] !== $autoload ) {
                return 0;
            }
            $this->rows[$table][$name]['option_value'] = $new;
            return 1;
        }
        public function seed( array $value ): void {
            $this->rows[$this->options][CB_CORE_SETTINGS] = [
                'option_name' => CB_CORE_SETTINGS,
                'option_value' => serialize( $value ),
                'autoload' => 'on',
            ];
            $this->last_error = '';
        }
        public function value(): array {
            return unserialize( $this->rows[$this->options][CB_CORE_SETTINGS]['option_value'], [
                'allowed_classes' => false,
            ] );
        }
    }

    function get_option( string $key, mixed $default = false ): mixed {
        global $wpdb;
        return isset( $wpdb->rows[$wpdb->options][$key] )
            ? unserialize( $wpdb->rows[$wpdb->options][$key]['option_value'], [
                'allowed_classes' => false,
            ] ) : $default;
    }
}
namespace CoreBlueprint\Core {
    final class SettingsDefaults {
        public static function all(): array {
            return [
                'permissions' => [
                    'hide_from_admins' => false,
                    'privileged_access_mode' => 'enforce',
                    'email_alerts' => [ 'role_change' => true ],
                ],
                'reports' => [ 'admin_can_generate' => [ 'maintenance' => false ] ],
                'integrity' => [ 'admin_can_run' => false ],
            ];
        }
    }
}
namespace CoreBlueprint\Core\Log {
    final class AuditLog {
        public static array $events = [];
        public static function log( string $type, string $level, array $details = [] ): void {
            self::$events[] = [ $type, $level, $details ];
        }
    }
}
namespace {
    require_once dirname( __DIR__ ) . '/src/Settings/OptionCas.php';
    require_once dirname( __DIR__ ) . '/src/Settings.php';

    function s1_consumer_assert( bool $condition, string $reason ): void {
        if ( ! $condition ) {
            throw new \RuntimeException( 'A2.4-S1 consumer regression: ' . $reason );
        }
    }

    $wpdb = new S1ConsumerFakeDb();
    $original = \CoreBlueprint\Core\SettingsDefaults::all();
    $original['reports']['branding'] = [ 'provider_name' => 'untouched' ];
    $wpdb->seed( $original );

    $changes = [
        'permissions.hide_from_admins' => true,
        'permissions.privileged_access_mode' => 'monitor',
        'reports.admin_can_generate.maintenance' => true,
        'integrity.admin_can_run' => true,
    ];
    $settings = \CoreBlueprint\Core\Settings::class;

    $GLOBALS['s1_option_veto'] = true;
    s1_consumer_assert( ! $settings::set_paths( $changes, 'profile' ), 'refused grouped mutation is not saved' );
    s1_consumer_assert( $wpdb->value() === $original, 'refusal changes no group' );
    s1_consumer_assert( [] === \CoreBlueprint\Core\Log\AuditLog::$events, 'no success audit on refusal' );
    s1_consumer_assert( ! in_array( 'updated_option', $GLOBALS['s1_actions'], true ), 'no updated hook on refusal' );
    $GLOBALS['s1_option_veto'] = false;

    // A pre-update filter may not silently drop one requested permission
    // leaf while another still changes. Verify the REAL Settings facade does
    // not emit a complete-intent audit for a partial attempted write.
    $GLOBALS['s1_partial_filter'] = true;
    $writes_before = $wpdb->writes;
    s1_consumer_assert( ! $settings::set_paths( $changes, 'profile' ), 'partial filter must refuse grouped mutation' );
    s1_consumer_assert( 'refused' === $settings::last_write_status(), 'partial filter classified as refused' );
    s1_consumer_assert( $writes_before === $wpdb->writes, 'partial filter performs no SQL' );
    s1_consumer_assert( $original === $wpdb->value(), 'partial filter persists no group' );
    s1_consumer_assert( [] === \CoreBlueprint\Core\Log\AuditLog::$events, 'partial filter emits no settings.changed audit' );
    s1_consumer_assert( ! in_array( 'updated_option', $GLOBALS['s1_actions'], true ), 'partial filter emits no success hook' );
    $GLOBALS['s1_partial_filter'] = false;

    s1_consumer_assert( $settings::set_paths( $changes, 'profile' ), 'accepted grouped mutation saved' );
    $persisted = $wpdb->value();
    s1_consumer_assert( true === $persisted['permissions']['hide_from_admins'], 'permissions leaf committed' );
    s1_consumer_assert( 'monitor' === $persisted['permissions']['privileged_access_mode'], 'policy leaf committed' );
    s1_consumer_assert( true === $persisted['reports']['admin_can_generate']['maintenance'], 'reports access committed' );
    s1_consumer_assert( true === $persisted['integrity']['admin_can_run'], 'integrity access committed' );
    s1_consumer_assert( 'untouched' === $persisted['reports']['branding']['provider_name'], 'unrelated branding preserved' );
    s1_consumer_assert( 1 === count( \CoreBlueprint\Core\Log\AuditLog::$events ), 'one confirmed audit for grouped mutation' );

    s1_consumer_assert( ! $settings::set_paths( $changes, 'profile' ), 'no-op is distinct from durable mutation' );
    s1_consumer_assert( 'unchanged' === $settings::last_write_status(), 'unchanged confirmed by fresh row' );
    s1_consumer_assert( 1 === count( \CoreBlueprint\Core\Log\AuditLog::$events ), 'no false audit on no-op' );

    // Simulate another worker committing between this request's snapshot and a
    // proposed update to the same policy leaf. A stale intent must be rejected.
    $fresh = $persisted;
    $fresh['permissions']['privileged_access_mode'] = 'enforce';
    $wpdb->seed( $fresh );
    s1_consumer_assert( ! $settings::matches_paths( [ 'permissions.privileged_access_mode' => 'monitor' ] ), 'fresh-row check rejects stale no-op' );
    s1_consumer_assert( ! $settings::set_paths( [
        'permissions.hide_from_admins' => false,
        'permissions.privileged_access_mode' => 'enforce',
    ], 'profile' ), 'stale grouped source intent rejected' );
    s1_consumer_assert( $wpdb->value() === $fresh, 'failed grouped intent cannot partially write' );
    s1_consumer_assert( 1 === count( \CoreBlueprint\Core\Log\AuditLog::$events ), 'no false audit on stale conflict' );

    // Test-first P0: an explicitly submitted leaf which already matched
    // the request cache must still be guarded against a concurrent writer.
    // Both leaves are part of one administrative capability intent.
    $explicit_baseline = $original;
    $explicit_baseline['reports']['admin_can_generate']['maintenance'] = true;
    $explicit_changes = [
        'reports.admin_can_generate.maintenance' => true,
        'integrity.admin_can_run' => true,
    ];

    // Positive control: a concurrent edit to a NON-requested leaf is safe.
    // CAS must preserve it while committing the complete explicit intent.
    $wpdb->seed( $explicit_baseline );
    $settings::accept_cas( $explicit_baseline );
    s1_consumer_assert(
        true === $settings::get()['reports']['admin_can_generate']['maintenance']
        && false === $settings::get()['integrity']['admin_can_run'],
        'explicit-path positive fixture primes the request cache'
    );
    $disjoint = $explicit_baseline;
    $disjoint['reports']['branding']['provider_name'] = 'independent-writer';
    $wpdb->seed( $disjoint );
    $before_writes = $wpdb->writes;
    $before_audits = count( \CoreBlueprint\Core\Log\AuditLog::$events );
    s1_consumer_assert(
        $settings::set_paths( $explicit_changes, 'permissions.tab' ),
        'disjoint unrequested concurrent edit must allow complete explicit intent'
    );
    $after_disjoint = $wpdb->value();
    s1_consumer_assert( 1 === $wpdb->writes - $before_writes, 'disjoint intent commits once' );
    s1_consumer_assert( true === $after_disjoint['reports']['admin_can_generate']['maintenance'], 'requested unchanged Reports leaf remains true' );
    s1_consumer_assert( true === $after_disjoint['integrity']['admin_can_run'], 'requested Integrity leaf commits true' );
    s1_consumer_assert( 'independent-writer' === $after_disjoint['reports']['branding']['provider_name'], 'unrequested concurrent branding edit survives' );
    s1_consumer_assert( $before_audits + 1 === count( \CoreBlueprint\Core\Log\AuditLog::$events ), 'disjoint saved intent has one audit' );

    // NEGATIVE / intentionally RED until expressly authorized CAS fix:
    // a competing writer changes an explicitly requested, cache-equal leaf.
    // Saving only the other changed leaf must NEVER count as full success.
    $wpdb->seed( $explicit_baseline );
    $settings::accept_cas( $explicit_baseline );
    s1_consumer_assert(
        true === $settings::get()['reports']['admin_can_generate']['maintenance'],
        'Reports is already true in A request-local cache'
    );
    $raced = $explicit_baseline;
    $raced['reports']['admin_can_generate']['maintenance'] = false;
    $wpdb->seed( $raced );
    $before_writes = $wpdb->writes;
    $before_audits = count( \CoreBlueprint\Core\Log\AuditLog::$events );
    $before_actions = count( $GLOBALS['s1_actions'] );
    s1_consumer_assert(
        ! $settings::set_paths( $explicit_changes, 'permissions.tab' ),
        'stale explicitly requested unchanged Reports leaf must reject entire intent'
    );
    s1_consumer_assert( 'conflict' === $settings::last_write_status(), 'unchanged explicit leaf race is a conflict' );
    s1_consumer_assert( $before_writes === $wpdb->writes, 'unchanged explicit leaf race makes no SQL write' );
    s1_consumer_assert( $raced === $wpdb->value(), 'unchanged explicit leaf race preserves complete competing row' );
    s1_consumer_assert( $before_audits === count( \CoreBlueprint\Core\Log\AuditLog::$events ), 'unchanged explicit leaf race emits no settings.changed audit' );
    s1_consumer_assert( $before_actions === count( $GLOBALS['s1_actions'] ), 'unchanged explicit leaf race emits no prewrite or success option hooks' );

    echo "Core Blueprint Settings A2.4-S1 facade/consumer regression PASS\n";
}
