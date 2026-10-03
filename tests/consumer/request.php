<?php
declare(strict_types=1);

/**
 * BASE-V1-A3 pinned Extension Starter consumer scenario.
 *
 * Every invocation is one WordPress request. Registration is never called
 * directly by this harness: Base and the Starter must reach their public
 * contracts through their normal WordPress hooks.
 */

$stage = isset($argv[1]) ? (string) $argv[1] : '';
$allowed_stages = [
    'install',
    'activate-base',
    'activate-starter',
    'runtime',
    'cleanup',
];

if (!in_array($stage, $allowed_stages, true)) {
    fwrite(STDERR, "Usage: php tests/consumer/request.php <" . implode('|', $allowed_stages) . ">\n");
    exit(64);
}

function cb_a3_consumer_env(string $name, string $default = ''): string {
    $value = getenv($name);
    return false === $value ? $default : (string) $value;
}

function cb_a3_consumer_expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cb_a3_consumer_db_connection(): mysqli {
    $host = cb_a3_consumer_env('WP_DB_HOST', '127.0.0.1:3306');
    $port = 3306;
    if (preg_match('/^([^:]+):([0-9]+)$/D', $host, $matches)) {
        $host = $matches[1];
        $port = (int) $matches[2];
    }

    $mysqli = new mysqli(
        $host,
        cb_a3_consumer_env('WP_DB_USER', 'root'),
        cb_a3_consumer_env('WP_DB_PASSWORD', ''),
        cb_a3_consumer_env('WP_DB_NAME', 'wordpress_test'),
        $port
    );
    if ($mysqli->connect_errno) {
        throw new RuntimeException('Could not connect to consumer database: ' . $mysqli->connect_error);
    }

    return $mysqli;
}

function cb_a3_consumer_drop_tables(): void {
    $prefix = cb_a3_consumer_env('CB_CONSUMER_TABLE_PREFIX', 'cbconsumer_');
    cb_a3_consumer_expect(1 === preg_match('/^[A-Za-z0-9_]+$/D', $prefix), 'Unsafe consumer table prefix.');

    $mysqli = cb_a3_consumer_db_connection();
    $result = $mysqli->query('SHOW TABLES');
    if (false === $result) {
        throw new RuntimeException('Could not enumerate consumer database tables.');
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
            throw new RuntimeException('Could not drop consumer table: ' . $table);
        }
    }
    $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
    $mysqli->close();
}

if ('cleanup' === $stage) {
    try {
        cb_a3_consumer_drop_tables();
        fwrite(STDOUT, "[A3 consumer] cleanup PASS\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "[A3 consumer] cleanup FAIL: {$e->getMessage()}\n");
        exit(1);
    }
}

$wp_core_dir = rtrim(cb_a3_consumer_env('WP_CORE_DIR'), "/\\");
$base_file = cb_a3_consumer_env('CB_PLUGIN_FILE');
$starter_file = $wp_core_dir . '/wp-content/plugins/core-blueprint-starter-plugin/core-blueprint-starter.php';
$table_prefix = cb_a3_consumer_env('CB_CONSUMER_TABLE_PREFIX', 'cbconsumer_');

if ('' === $wp_core_dir || !is_file($wp_core_dir . '/wp-settings.php')) {
    fwrite(STDERR, "[A3 consumer] WP_CORE_DIR must point to the pinned WordPress runtime.\n");
    exit(1);
}
if ('' === $base_file || !is_file($base_file)) {
    fwrite(STDERR, "[A3 consumer] CB_PLUGIN_FILE must point to the copied Core Blueprint entrypoint.\n");
    exit(1);
}
if (!is_file($starter_file)) {
    fwrite(STDERR, "[A3 consumer] Pinned Starter fixture is missing.\n");
    exit(1);
}
if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $table_prefix)) {
    fwrite(STDERR, "[A3 consumer] Unsafe consumer table prefix.\n");
    exit(1);
}

if ('install' === $stage) {
    cb_a3_consumer_drop_tables();
}

$_SERVER['HTTP_HOST'] = 'cb-a3-consumer.local';
$_SERVER['SERVER_NAME'] = 'cb-a3-consumer.local';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $wp_core_dir . '/index.php';

define('ABSPATH', $wp_core_dir . '/');
define('DB_NAME', cb_a3_consumer_env('WP_DB_NAME', 'wordpress_test'));
define('DB_USER', cb_a3_consumer_env('WP_DB_USER', 'root'));
define('DB_PASSWORD', cb_a3_consumer_env('WP_DB_PASSWORD', ''));
define('DB_HOST', cb_a3_consumer_env('WP_DB_HOST', '127.0.0.1:3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY', 'cb-a3-consumer-auth-key');
define('SECURE_AUTH_KEY', 'cb-a3-consumer-secure-auth-key');
define('LOGGED_IN_KEY', 'cb-a3-consumer-logged-in-key');
define('NONCE_KEY', 'cb-a3-consumer-nonce-key');
define('AUTH_SALT', 'cb-a3-consumer-auth-salt');
define('SECURE_AUTH_SALT', 'cb-a3-consumer-secure-auth-salt');
define('LOGGED_IN_SALT', 'cb-a3-consumer-logged-in-salt');
define('NONCE_SALT', 'cb-a3-consumer-nonce-salt');
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
    fwrite(STDERR, "[A3 consumer] Could not create MU-plugin directory.\n");
    exit(1);
}
$mail_guard = $mu_dir . '/cb-a3-consumer-mail-guard.php';
file_put_contents(
    $mail_guard,
    "<?php\nadd_filter( 'pre_wp_mail', static function () { return true; }, PHP_INT_MAX );\n"
);

try {
    require ABSPATH . 'wp-settings.php';

    if ('install' === $stage) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $installed = wp_install(
            'Core Blueprint A3 Consumer',
            'cbadmin',
            'cbadmin@example.test',
            true,
            '',
            'cb-a3-consumer-password',
            'en_US'
        );

        cb_a3_consumer_expect(!empty($installed['user_id']), 'Consumer site installation did not create an administrator.');
        cb_a3_consumer_expect('http://cb-a3-consumer.local' === untrailingslashit((string) get_option('siteurl', '')), 'Consumer site URL was not persisted correctly.');
        cb_a3_consumer_expect(false === get_option('cb_core_first_activated_at', false), 'Base must be unactivated after bare consumer-site install.');
        fwrite(STDOUT, "[A3 consumer] install PASS\n");
        exit(0);
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $base_basename = plugin_basename($base_file);
    $starter_basename = plugin_basename($starter_file);
    cb_a3_consumer_expect('core-blueprint/core-blueprint.php' === $base_basename, 'Consumer harness must use the canonical Base folder.');
    cb_a3_consumer_expect('core-blueprint-starter-plugin/core-blueprint-starter.php' === $starter_basename, 'Consumer harness must use the canonical Starter folder.');

    $admin = get_user_by('login', 'cbadmin');
    cb_a3_consumer_expect($admin instanceof WP_User, 'Consumer administrator is missing.');

    if ('activate-base' === $stage) {
        cb_a3_consumer_expect(!is_plugin_active($base_basename), 'Base must start inactive in activate-base stage.');
        wp_set_current_user((int) $admin->ID);

        $result = activate_plugin($base_basename);
        cb_a3_consumer_expect(!is_wp_error($result), 'Base activation returned WP_Error.');
        cb_a3_consumer_expect(is_plugin_active($base_basename), 'Base was not persisted as active.');
        cb_a3_consumer_expect(!is_plugin_active($starter_basename), 'Starter must remain inactive after Base activation.');

        fwrite(STDOUT, "[A3 consumer] activate-base PASS\n");
        exit(0);
    }

    if ('activate-starter' === $stage) {
        cb_a3_consumer_expect(is_plugin_active($base_basename), 'Base must already be active before Starter activation.');
        cb_a3_consumer_expect(!is_plugin_active($starter_basename), 'Starter must start inactive in activate-starter stage.');
        cb_a3_consumer_expect(function_exists('cb_starter_base_ready') === false, 'Inactive Starter must not already have bootstrapped its runtime.');
        wp_set_current_user((int) $admin->ID);

        $result = activate_plugin($starter_basename);
        cb_a3_consumer_expect(!is_wp_error($result), 'Starter activation returned WP_Error.');
        cb_a3_consumer_expect(is_plugin_active($starter_basename), 'Starter was not persisted as active.');
        cb_a3_consumer_expect(function_exists('cb_starter_base_ready'), 'Starter activation did not load its dependency gate.');
        cb_a3_consumer_expect(cb_starter_base_ready(), 'Starter rejected the active Base public API during activation.');

        fwrite(STDOUT, "[A3 consumer] activate-starter PASS\n");
        exit(0);
    }

    cb_a3_consumer_expect('runtime' === $stage, 'Unhandled consumer stage.');
    cb_a3_consumer_expect(is_plugin_active($base_basename), 'Base must be active for consumer verification.');
    cb_a3_consumer_expect(is_plugin_active($starter_basename), 'Starter must be active for consumer verification.');
    cb_a3_consumer_expect(function_exists('cb_starter_base_ready') && cb_starter_base_ready(), 'Starter dependency gate is not satisfied on a normal subsequent request.');

    $definition = \CoreBlueprint\Core\ExtensionRegistry::definition('core-blueprint-starter-plugin');
    cb_a3_consumer_expect(is_array($definition), 'Starter was not collected through cb_core_register_extensions.');
    cb_a3_consumer_expect('1.0' === ($definition['requires_api'] ?? ''), 'Starter registration does not require Core API 1.0.');
    cb_a3_consumer_expect($starter_basename === ($definition['plugin_file'] ?? ''), 'Starter registration exposes the wrong plugin basename.');
    cb_a3_consumer_expect('core-blueprint-starter-plugin' === ($definition['status_id'] ?? ''), 'Starter registration exposes the wrong status ID.');

    $inventory = \CoreBlueprint\Core\ExtensionRegistry::get('core-blueprint-starter-plugin');
    cb_a3_consumer_expect(is_array($inventory), 'Starter is missing from the Base extension inventory.');
    cb_a3_consumer_expect(true === ($inventory['installed'] ?? null), 'Starter inventory does not report installed=true.');
    cb_a3_consumer_expect(true === ($inventory['active'] ?? null), 'Starter inventory does not report active=true.');
    cb_a3_consumer_expect(true === ($inventory['registered'] ?? null), 'Starter inventory does not report registered=true.');
    cb_a3_consumer_expect(true === ($inventory['compatible'] ?? null), 'Starter inventory does not report compatible=true.');
    cb_a3_consumer_expect('ok' === ($inventory['health'] ?? ''), 'Starter health did not resolve through the public status contract.');

    $status = \CoreBlueprint\Core\Modules\Status::get('core-blueprint-starter-plugin');
    cb_a3_consumer_expect(is_array($status) && 'ok' === ($status['state'] ?? ''), 'Starter status provider did not resolve to ok.');
    cb_a3_consumer_expect('Starter Plugin' === ($status['label'] ?? ''), 'Starter status label is not available after init.');

    cb_a3_consumer_expect('Starter example updated' === \CoreBlueprint\Core\Governance\EventRegistry::label('starter.example.updated'), 'Starter Governance event was not registered on init.');
    cb_a3_consumer_expect('general' === \CoreBlueprint\Core\Governance\EventRegistry::retention_category('starter.example.updated'), 'Starter Governance event has the wrong retention category.');
    cb_a3_consumer_expect(\CB\Starter\Governance\Events::record_example(42), 'Starter could not record through the public Governance Audit facade.');

    global $wpdb;
    $audit_table = \CoreBlueprint\Core\DB::audit_log_table();
    $audit_count = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$audit_table} WHERE event_type = %s", 'starter_example_updated')
    );
    cb_a3_consumer_expect(1 === $audit_count, 'Starter Governance event was not stored exactly once.');

    fwrite(STDOUT, "[A3 consumer] runtime PASS\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[A3 consumer] {$stage} FAIL: {$e->getMessage()}\n");
    exit(1);
}
