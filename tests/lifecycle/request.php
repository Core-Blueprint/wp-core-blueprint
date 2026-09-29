<?php
declare(strict_types=1);

/**
 * BASE-V1-A2 request-boundary lifecycle/data scenario.
 *
 * Each invocation represents one PHP/WordPress request. Persistent WordPress
 * state is shared through one isolated table prefix; PHP static memory is not.
 */

$stage = isset($argv[1]) ? (string) $argv[1] : '';
$allowed_stages = [
    'install',
    'first-activation',
    'deactivation',
    'reactivation',
    'damage-schema',
    'repair-schema',
    'schema-contracts',
    'cleanup',
];

if (!in_array($stage, $allowed_stages, true)) {
    fwrite(STDERR, "Usage: php tests/lifecycle/request.php <" . implode('|', $allowed_stages) . ">\n");
    exit(64);
}

function cb_a2_env(string $name, string $default = ''): string {
    $value = getenv($name);
    return false === $value ? $default : (string) $value;
}

function cb_a2_expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cb_a2_db_connection(): mysqli {
    $host = cb_a2_env('WP_DB_HOST', '127.0.0.1:3306');
    $port = 3306;
    if (preg_match('/^([^:]+):([0-9]+)$/D', $host, $matches)) {
        $host = $matches[1];
        $port = (int) $matches[2];
    }

    $mysqli = new mysqli(
        $host,
        cb_a2_env('WP_DB_USER', 'root'),
        cb_a2_env('WP_DB_PASSWORD', ''),
        cb_a2_env('WP_DB_NAME', 'wordpress_test'),
        $port
    );
    if ($mysqli->connect_errno) {
        throw new RuntimeException('Could not connect to lifecycle database: ' . $mysqli->connect_error);
    }

    return $mysqli;
}

function cb_a2_drop_isolated_tables(): void {
    $prefix = cb_a2_env('CB_LIFECYCLE_TABLE_PREFIX', 'cblifecycle_');
    cb_a2_expect(1 === preg_match('/^[A-Za-z0-9_]+$/D', $prefix), 'Unsafe lifecycle table prefix.');

    $mysqli = cb_a2_db_connection();
    $result = $mysqli->query('SHOW TABLES');
    if (false === $result) {
        throw new RuntimeException('Could not enumerate lifecycle database tables.');
    }

    $tables = [];
    while ($row = $result->fetch_row()) {
        $table = isset($row[0]) ? (string) $row[0] : '';
        if ('' !== $table && str_starts_with($table, $prefix)) {
            $tables[] = $table;
        }
    }
    $result->free();

    $mysqli->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $safe = str_replace('`', '``', $table);
        if (!$mysqli->query("DROP TABLE IF EXISTS `{$safe}`")) {
            throw new RuntimeException('Could not drop lifecycle table: ' . $table);
        }
    }
    $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
    $mysqli->close();
}

if ('cleanup' === $stage) {
    try {
        cb_a2_drop_isolated_tables();
        fwrite(STDOUT, "[A2] cleanup PASS\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "[A2] cleanup FAIL: {$e->getMessage()}\n");
        exit(1);
    }
}

$wp_core_dir = rtrim(cb_a2_env('WP_CORE_DIR'), "/\\");
$plugin_file = cb_a2_env('CB_PLUGIN_FILE');
$table_prefix = cb_a2_env('CB_LIFECYCLE_TABLE_PREFIX', 'cblifecycle_');

if ('' === $wp_core_dir || !is_file($wp_core_dir . '/wp-settings.php')) {
    fwrite(STDERR, "[A2] WP_CORE_DIR must point to the pinned WordPress runtime.\n");
    exit(1);
}
if ('' === $plugin_file || !is_file($plugin_file)) {
    fwrite(STDERR, "[A2] CB_PLUGIN_FILE must point to the copied Core Blueprint entrypoint.\n");
    exit(1);
}
if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $table_prefix)) {
    fwrite(STDERR, "[A2] Unsafe lifecycle table prefix.\n");
    exit(1);
}

if ('install' === $stage) {
    cb_a2_drop_isolated_tables();
}

// WordPress CLI boot still expects a minimal HTTP request context for URL
// guessing during installation and for normal site bootstrap.
$_SERVER['HTTP_HOST'] = 'cb-a2.local';
$_SERVER['SERVER_NAME'] = 'cb-a2.local';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '80';

define('ABSPATH', $wp_core_dir . '/');
define('DB_NAME', cb_a2_env('WP_DB_NAME', 'wordpress_test'));
define('DB_USER', cb_a2_env('WP_DB_USER', 'root'));
define('DB_PASSWORD', cb_a2_env('WP_DB_PASSWORD', ''));
define('DB_HOST', cb_a2_env('WP_DB_HOST', '127.0.0.1:3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY', 'cb-a2-auth-key');
define('SECURE_AUTH_KEY', 'cb-a2-secure-auth-key');
define('LOGGED_IN_KEY', 'cb-a2-logged-in-key');
define('NONCE_KEY', 'cb-a2-nonce-key');
define('AUTH_SALT', 'cb-a2-auth-salt');
define('SECURE_AUTH_SALT', 'cb-a2-secure-auth-salt');
define('LOGGED_IN_SALT', 'cb-a2-logged-in-salt');
define('NONCE_SALT', 'cb-a2-nonce-salt');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', true);
define('WP_DEBUG_LOG', false);
define('DISABLE_WP_CRON', true);
define('WP_ENVIRONMENT_TYPE', 'local');

if ('install' === $stage) {
    define('WP_INSTALLING', true);
}

$mu_dir = ABSPATH . 'wp-content/mu-plugins';
if (!is_dir($mu_dir) && !mkdir($mu_dir, 0777, true) && !is_dir($mu_dir)) {
    fwrite(STDERR, "[A2] Could not create MU-plugin directory.\n");
    exit(1);
}
$mail_guard = $mu_dir . '/cb-a2-mail-guard.php';
file_put_contents(
    $mail_guard,
    "<?php\nadd_filter( 'pre_wp_mail', static function () { return true; }, PHP_INT_MAX );\n"
);

try {
    require ABSPATH . 'wp-settings.php';

    if ('install' === $stage) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $installed = wp_install(
            'Core Blueprint A2',
            'cbadmin',
            'cbadmin@example.test',
            true,
            '',
            'cb-a2-test-password',
            'en_US'
        );

        cb_a2_expect(!empty($installed['user_id']), 'WordPress lifecycle site installation did not create the administrator.');
        cb_a2_expect(null !== get_role('administrator'), 'Administrator role missing after lifecycle site installation.');
        cb_a2_expect('http://cb-a2.local' === untrailingslashit((string) get_option('siteurl', '')), 'Lifecycle site URL was not persisted correctly.');
        cb_a2_expect(false === get_option('cb_core_first_activated_at', false), 'Base must be unactivated after bare WordPress install.');
        fwrite(STDOUT, "[A2] install PASS\n");
        exit(0);
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $plugin_basename = plugin_basename($plugin_file);
    cb_a2_expect('core-blueprint/core-blueprint.php' === $plugin_basename, 'Lifecycle harness must use the canonical core-blueprint/ plugin folder.');

    $first_admin = get_user_by('login', 'cbadmin');
    cb_a2_expect($first_admin instanceof WP_User, 'Lifecycle administrator is missing.');

    $audit_table_exists = static function (): bool {
        global $wpdb;
        $table = \CB\Core\DB::audit_log_table();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return $found === $table;
    };

    $audit_count = static function (string $event_type): int {
        global $wpdb;
        $table = \CB\Core\DB::audit_log_table();
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $event_type)
        );
    };

    if ('first-activation' === $stage) {
        cb_a2_expect(!is_plugin_active($plugin_basename), 'Base must start inactive for first-activation stage.');
        wp_set_current_user((int) $first_admin->ID);

        $result = activate_plugin($plugin_basename);
        cb_a2_expect(!is_wp_error($result), 'First activation returned WP_Error.');
        cb_a2_expect(is_plugin_active($plugin_basename), 'Base was not persisted as active.');

        $first_admin = get_user_by('login', 'cbadmin');
        cb_a2_expect($first_admin instanceof WP_User, 'First administrator disappeared during activation.');
        cb_a2_expect(in_array(\CB\Core\Permissions\Roles::OPERATOR_ROLE, (array) $first_admin->roles, true), 'First administrator was not assigned the CB Operator role.');

        $approval = \CB\Core\Permissions\PrivilegedAccessRegistry::valid_approval_record($first_admin);
        cb_a2_expect([] !== $approval, 'First operator was not approved for its exact privilege fingerprint.');

        $first_activated_at = get_option('cb_core_first_activated_at', false);
        $guard_marker = get_option('cb_core_privileged_guard_bootstrapped', false);
        cb_a2_expect(is_string($first_activated_at) && '' !== $first_activated_at, 'First activation marker missing.');
        cb_a2_expect(false !== $guard_marker && 0 < (int) $guard_marker, 'Privileged guard bootstrap marker missing.');
        cb_a2_expect('1.0' === (string) get_option('cb_core_db_version', ''), 'Audit schema marker not current after first activation.');
        cb_a2_expect(\CB\Core\Permissions\RolePolicySchema::current_schema() === (int) get_option('cb_core_role_policy_schema_version', 0), 'Role Policy schema not initialized on first activation.');
        cb_a2_expect(1 === (int) get_option('cb_core_trust_schema_version', 0), 'Trust Schema not initialized on first activation.');
        cb_a2_expect('auto' === (string) get_option('cb_core_theme_default', ''), 'Theme default was not initialized.');
        cb_a2_expect('auto' === (string) get_option('cb_locale_default', ''), 'Locale default was not initialized.');
        cb_a2_expect(is_array(get_option(CB_CORE_SETTINGS, null)), 'Base settings option missing after first activation.');
        cb_a2_expect('' !== (string) get_option(CB_CORE_BYPASS_TOK, ''), 'Failsafe bypass token missing after first activation.');
        cb_a2_expect(1 === (int) get_option('cb_core_option_policy_version', 0), 'Active option policy marker missing.');
        cb_a2_expect($audit_table_exists(), 'Audit table missing after first activation.');
        cb_a2_expect(false !== wp_next_scheduled(\CB\Core\Log\Retention::CRON_HOOK), 'Retention cron missing after first activation.');
        cb_a2_expect(false !== wp_next_scheduled('cb_core_privileged_guard_cron_sweep'), 'Privileged Access Guard cron missing after first activation.');
        cb_a2_expect(false === wp_next_scheduled(\CB\Core\Integrity\Scheduler\Cron::HOOK), 'Scanner cron must remain unscheduled with the default disabled schedule.');
        cb_a2_expect(1 === $audit_count('plugin_activated'), 'First activation must write exactly one plugin_activated event.');

        cb_a2_expect(\CB\Core\Settings::set_key('site_mode', 'development', 'a2_test'), 'Could not persist lifecycle sentinel setting.');
        update_option('cb_core_theme_default', 'dark', false);
        update_option('cb_a2_expected_first_activation', $first_activated_at, false);
        update_option('cb_a2_expected_guard_marker', $guard_marker, false);
        update_option('cb_a2_expected_first_approval', $approval, false);

        fwrite(STDOUT, "[A2] first-activation PASS\n");
        exit(0);
    }

    if ('deactivation' === $stage) {
        cb_a2_expect(is_plugin_active($plugin_basename), 'Base must start active for deactivation stage.');
        wp_set_current_user((int) $first_admin->ID);

        set_transient(\CB\Core\Security\Failsafe::BYPASS_TRANSIENT, 'active', \CB\Core\Security\Failsafe::BYPASS_WINDOW);
        update_option(CB_CORE_BYPASS_OPT, 'emergency', false);

        deactivate_plugins($plugin_basename);

        cb_a2_expect(!is_plugin_active($plugin_basename), 'Base remained active after deactivation.');
        cb_a2_expect(false === get_transient(\CB\Core\Security\Failsafe::BYPASS_TRANSIENT), 'Transient bypass window survived deactivation.');
        cb_a2_expect('emergency' === get_option(CB_CORE_BYPASS_OPT, false), 'Persistent emergency bypass must survive deactivation.');
        cb_a2_expect(false === wp_next_scheduled(\CB\Core\Log\Retention::CRON_HOOK), 'Retention cron survived deactivation.');
        cb_a2_expect(false === wp_next_scheduled('cb_core_privileged_guard_cron_sweep'), 'Privileged Access Guard cron survived deactivation.');
        cb_a2_expect(false === wp_next_scheduled(\CB\Core\Integrity\Scheduler\Cron::HOOK), 'Scanner cron survived deactivation.');
        cb_a2_expect(false === get_option('cb_core_option_policy_version', false), 'Inactive option policy marker survived deactivation.');

        cb_a2_expect(get_option('cb_a2_expected_first_activation', false) === get_option('cb_core_first_activated_at', false), 'First activation marker changed during deactivation.');
        cb_a2_expect('development' === (string) (\CB\Core\Settings::get()['site_mode'] ?? ''), 'Base settings were lost during deactivation.');
        cb_a2_expect('dark' === (string) get_option('cb_core_theme_default', ''), 'User-selected theme default was lost during deactivation.');
        cb_a2_expect($audit_table_exists(), 'Audit table was removed by deactivation.');
        cb_a2_expect(null !== get_role(\CB\Core\Permissions\Roles::OPERATOR_ROLE), 'CB Operator role was removed by deactivation.');

        $first_admin = get_user_by('login', 'cbadmin');
        cb_a2_expect($first_admin instanceof WP_User, 'First administrator missing after deactivation.');
        cb_a2_expect(in_array(\CB\Core\Permissions\Roles::OPERATOR_ROLE, (array) $first_admin->roles, true), 'Operator assignment was removed by deactivation.');
        cb_a2_expect(
            get_option('cb_a2_expected_first_approval', []) === \CB\Core\Permissions\PrivilegedAccessRegistry::valid_approval_record($first_admin),
            'Signed first-operator approval changed during deactivation.'
        );
        cb_a2_expect(1 === $audit_count('plugin_deactivated'), 'Deactivation must write exactly one plugin_deactivated event.');

        delete_option(CB_CORE_BYPASS_OPT);

        fwrite(STDOUT, "[A2] deactivation PASS\n");
        exit(0);
    }

    if ('reactivation' === $stage) {
        cb_a2_expect(!is_plugin_active($plugin_basename), 'Base must start inactive for reactivation stage.');

        // Simulate established-site drift while Base is inactive. Reactivation may
        // observe this state, but it must not use missing metadata as authority to
        // repair roles or recreate trust.
        $admin_role = get_role('administrator');
        cb_a2_expect(null !== $admin_role, 'Administrator role missing before reactivation drift setup.');
        cb_a2_expect($admin_role->has_cap('cb_manage_media_replace'), 'Expected canonical admin capability missing before drift setup.');
        $admin_role->remove_cap('cb_manage_media_replace');
        delete_option('cb_core_role_policy_schema_version');
        delete_option('cb_core_trust_schema_version');

        $second_admin = get_user_by('login', 'cbsecond');
        if (!($second_admin instanceof WP_User)) {
            $second_id = wp_insert_user([
                'user_login' => 'cbsecond',
                'user_pass' => 'cb-a2-second-password',
                'user_email' => 'cbsecond@example.test',
                'role' => 'administrator',
            ]);
            cb_a2_expect(!is_wp_error($second_id), 'Could not create second administrator before reactivation.');
            $second_admin = get_user_by('id', (int) $second_id);
        }
        cb_a2_expect($second_admin instanceof WP_User, 'Second administrator missing before reactivation.');
        wp_set_current_user((int) $second_admin->ID);

        $result = activate_plugin($plugin_basename);
        cb_a2_expect(!is_wp_error($result), 'Reactivation returned WP_Error.');
        cb_a2_expect(is_plugin_active($plugin_basename), 'Base was not persisted as active after reactivation.');

        cb_a2_expect(get_option('cb_a2_expected_first_activation', false) === get_option('cb_core_first_activated_at', false), 'Reactivation changed the genuine first-activation marker.');
        cb_a2_expect(get_option('cb_a2_expected_guard_marker', false) === get_option('cb_core_privileged_guard_bootstrapped', false), 'Reactivation rewrote the privileged guard trust-root marker.');
        cb_a2_expect(false === get_option('cb_core_trust_schema_version', false), 'Reactivation silently recreated missing Trust Schema metadata.');
        cb_a2_expect(null === \CB\Core\Permissions\RolePolicySchema::stored_schema(), 'Reactivation silently recreated missing Role Policy schema metadata.');

        $admin_role = get_role('administrator');
        cb_a2_expect(null !== $admin_role && !$admin_role->has_cap('cb_manage_media_replace'), 'Reactivation silently repaired established-site role drift.');

        $drift = get_option('cb_core_role_policy_drift', []);
        $issues = is_array($drift) && is_array($drift['issues'] ?? null) ? $drift['issues'] : [];
        cb_a2_expect(in_array('schema_marker_missing_or_invalid', $issues, true), 'Role Policy missing-marker drift was not recorded.');
        cb_a2_expect(in_array('administrator_missing_cap:cb_manage_media_replace', $issues, true), 'Role Policy capability drift was not recorded.');

        $first_admin = get_user_by('login', 'cbadmin');
        $second_admin = get_user_by('login', 'cbsecond');
        cb_a2_expect($first_admin instanceof WP_User && $second_admin instanceof WP_User, 'Lifecycle administrators missing after reactivation.');

        cb_a2_expect(in_array(\CB\Core\Permissions\Roles::OPERATOR_ROLE, (array) $first_admin->roles, true), 'Original operator role assignment was lost on reactivation.');
        cb_a2_expect(
            get_option('cb_a2_expected_first_approval', []) === \CB\Core\Permissions\PrivilegedAccessRegistry::valid_approval_record($first_admin),
            'Original signed approval changed on reactivation.'
        );
        cb_a2_expect(!in_array(\CB\Core\Permissions\Roles::OPERATOR_ROLE, (array) $second_admin->roles, true), 'Reactivation minted a new CB Operator from the activating administrator.');
        cb_a2_expect([] === \CB\Core\Permissions\PrivilegedAccessRegistry::valid_approval_record($second_admin), 'Reactivation minted an approval for the activating administrator.');

        cb_a2_expect('development' === (string) (\CB\Core\Settings::get()['site_mode'] ?? ''), 'Custom settings were overwritten by reactivation.');
        cb_a2_expect('dark' === (string) get_option('cb_core_theme_default', ''), 'User-selected theme default was overwritten by reactivation.');
        cb_a2_expect(1 === (int) get_option('cb_core_option_policy_version', 0), 'Active option policy marker was not restored on reactivation.');
        cb_a2_expect(false !== wp_next_scheduled(\CB\Core\Log\Retention::CRON_HOOK), 'Retention cron was not restored on reactivation.');
        cb_a2_expect(false !== wp_next_scheduled('cb_core_privileged_guard_cron_sweep'), 'Privileged Access Guard cron was not restored on reactivation.');
        cb_a2_expect(2 === $audit_count('plugin_activated'), 'Fresh request reactivation must persist a second plugin_activated event.');

        // Restore intentionally-created drift through the explicit Role Policy
        // repair boundary. Trust Schema has no public historical migration in v1,
        // so the test restores its known marker directly after proving no auto-heal.
        $repair = \CB\Core\Permissions\RolePolicySchema::repair();
        cb_a2_expect(!empty($repair['canonical']), 'Explicit Role Policy repair did not restore canonical state.');
        update_option('cb_core_trust_schema_version', 1, false);
        cb_a2_expect(1 === (int) get_option('cb_core_trust_schema_version', 0), 'Could not restore Trust Schema marker after drift assertion.');

        fwrite(STDOUT, "[A2] reactivation PASS\n");
        exit(0);
    }

    if ('damage-schema' === $stage) {
        cb_a2_expect(is_plugin_active($plugin_basename), 'Base must be active before schema damage stage.');
        global $wpdb;
        $table = \CB\Core\DB::audit_log_table();
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        update_option('cb_core_db_health_checked_at', 0, true);

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        cb_a2_expect($found !== $table, 'Audit table damage setup failed.');
        cb_a2_expect('1.0' === (string) get_option('cb_core_db_version', ''), 'Schema damage stage must keep the current version marker.');

        fwrite(STDOUT, "[A2] damage-schema PASS\n");
        exit(0);
    }

    if ('repair-schema' === $stage) {
        cb_a2_expect(is_plugin_active($plugin_basename), 'Base must remain active for next-request schema repair.');
        cb_a2_expect($audit_table_exists(), 'Next normal request did not repair the missing current-version audit table.');
        cb_a2_expect('1.0' === (string) get_option('cb_core_db_version', ''), 'Schema marker changed unexpectedly during health repair.');
        cb_a2_expect(0 < (int) get_option('cb_core_db_health_checked_at', 0), 'Successful health repair did not advance the health-check marker.');

        fwrite(STDOUT, "[A2] repair-schema PASS\n");
        exit(0);
    }

    if ('schema-contracts' === $stage) {
        cb_a2_expect(is_plugin_active($plugin_basename), 'Base must be active for schema registry contracts.');

        global $wpdb;
        $ok_table = $wpdb->prefix . 'cb_a2_schema_ok';
        $bad_table_one = $wpdb->prefix . 'cb_a2_schema_partial_one';
        $bad_table_two = $wpdb->prefix . 'cb_a2_schema_partial_two';

        foreach ([$ok_table, $bad_table_one, $bad_table_two] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        delete_option('cb_a2_schema_ok_version');
        delete_option('cb_a2_schema_duplicate_version');
        delete_option('cb_a2_schema_partial_version');

        $create_table = static function (string $table) use ($wpdb): void {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE {$table} (id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) {$charset}");
        };

        $registered = \CB\Core\Database\SchemaRegistry::register([
            'id' => 'a2-schema-ok',
            'version' => '1.0',
            'option_key' => 'cb_a2_schema_ok_version',
            'tables' => [static fn(): string => $ok_table],
            'install' => static function () use ($create_table, $ok_table): void {
                $create_table($ok_table);
            },
        ]);
        cb_a2_expect($registered, 'Valid late schema registration was rejected.');
        cb_a2_expect('1.0' === (string) get_option('cb_a2_schema_ok_version', ''), 'Late schema registration was not reconciled immediately after the normal sweep.');
        cb_a2_expect($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($ok_table))) === $ok_table, 'Late registered schema table was not created.');

        cb_a2_expect(
            !\CB\Core\Database\SchemaRegistry::register([
                'id' => 'a2-schema-ok',
                'version' => '1.0',
                'option_key' => 'cb_a2_schema_duplicate_version',
                'tables' => [static fn(): string => $ok_table],
                'install' => static function (): void {},
            ]),
            'Duplicate schema id was accepted.'
        );
        cb_a2_expect(
            !\CB\Core\Database\SchemaRegistry::register([
                'id' => 'a2-schema-other',
                'version' => '1.0',
                'option_key' => 'cb_a2_schema_ok_version',
                'tables' => [static fn(): string => $ok_table],
                'install' => static function (): void {},
            ]),
            'Duplicate schema option ownership was accepted.'
        );
        cb_a2_expect(
            !\CB\Core\Database\SchemaRegistry::register([
                'id' => 'audit-log',
                'version' => '1.0',
                'option_key' => 'cb_a2_schema_reserved_id',
                'tables' => [static fn(): string => $ok_table],
                'install' => static function (): void {},
            ]),
            'Extension schema claimed a Base-reserved schema id.'
        );
        cb_a2_expect(
            !\CB\Core\Database\SchemaRegistry::register([
                'id' => 'a2-schema-reserved-option',
                'version' => '1.0',
                'option_key' => 'cb_core_db_version',
                'tables' => [static fn(): string => $ok_table],
                'install' => static function (): void {},
            ]),
            'Extension schema claimed a Base-reserved version option.'
        );

        $diagnostic_log = sys_get_temp_dir() . '/cb-a2-schema-' . getmypid() . '.log';
        @unlink($diagnostic_log);
        $previous_error_log = ini_get('error_log');
        ini_set('log_errors', '1');
        ini_set('error_log', $diagnostic_log);

        $partial_registered = \CB\Core\Database\SchemaRegistry::register([
            'id' => 'a2-schema-partial',
            'version' => '1.0',
            'option_key' => 'cb_a2_schema_partial_version',
            'tables' => [
                static fn(): string => $bad_table_one,
                static fn(): string => $bad_table_two,
            ],
            'install' => static function () use ($create_table, $bad_table_one): void {
                $create_table($bad_table_one);
            },
        ]);

        ini_set('error_log', false === $previous_error_log ? '' : (string) $previous_error_log);

        cb_a2_expect($partial_registered, 'Valid multi-table schema definition itself was rejected.');
        cb_a2_expect(false === get_option('cb_a2_schema_partial_version', false), 'Schema marker advanced despite a declared table missing after install.');
        cb_a2_expect($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($bad_table_two))) !== $bad_table_two, 'Incomplete schema unexpectedly created its missing table.');

        $diagnostic = is_file($diagnostic_log) ? (string) file_get_contents($diagnostic_log) : '';
        cb_a2_expect(str_contains($diagnostic, 'declared_table_missing_after_install'), 'Incomplete schema did not emit the expected bootstrap diagnostic.');
        @unlink($diagnostic_log);

        foreach ([$ok_table, $bad_table_one, $bad_table_two] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        delete_option('cb_a2_schema_ok_version');
        delete_option('cb_a2_schema_duplicate_version');
        delete_option('cb_a2_schema_partial_version');

        fwrite(STDOUT, "[A2] schema-contracts PASS\n");
        exit(0);
    }

    throw new RuntimeException('Unhandled lifecycle stage: ' . $stage);
} catch (Throwable $e) {
    fwrite(STDERR, "[A2] {$stage} FAIL: {$e->getMessage()}\n");
    exit(1);
}
