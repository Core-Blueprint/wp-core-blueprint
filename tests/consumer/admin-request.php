<?php
declare(strict_types=1);

/** BASE-V1-A3 real admin-request consumer verification. */

function cb_a3_admin_env(string $name, string $default = ''): string {
    $value = getenv($name);
    return false === $value ? $default : (string) $value;
}

function cb_a3_admin_expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$wp_core_dir = rtrim(cb_a3_admin_env('WP_CORE_DIR'), "/\\");
$base_file = cb_a3_admin_env('CB_PLUGIN_FILE');
$starter_file = $wp_core_dir . '/wp-content/plugins/core-blueprint-starter-plugin/core-blueprint-starter.php';
$table_prefix = cb_a3_admin_env('CB_CONSUMER_TABLE_PREFIX', 'cbconsumer_');

if ('' === $wp_core_dir || !is_file($wp_core_dir . '/wp-settings.php')) {
    fwrite(STDERR, "[A3 consumer] admin FAIL: WP_CORE_DIR is invalid.\n");
    exit(1);
}
if ('' === $base_file || !is_file($base_file) || !is_file($starter_file)) {
    fwrite(STDERR, "[A3 consumer] admin FAIL: Base or pinned Starter fixture is missing.\n");
    exit(1);
}
if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $table_prefix)) {
    fwrite(STDERR, "[A3 consumer] admin FAIL: unsafe consumer table prefix.\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = 'cb-a3-consumer.local';
$_SERVER['SERVER_NAME'] = 'cb-a3-consumer.local';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=core-blueprint-starter-plugin';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin.php';
$_SERVER['PHP_SELF'] = '/wp-admin/admin.php';
$_SERVER['SCRIPT_FILENAME'] = $wp_core_dir . '/wp-admin/admin.php';
$_GET['page'] = 'core-blueprint-starter-plugin';
$_REQUEST['page'] = 'core-blueprint-starter-plugin';

define('WP_ADMIN', true);
define('WP_NETWORK_ADMIN', false);
define('WP_USER_ADMIN', false);
define('WP_BLOG_ADMIN', true);
define('ABSPATH', $wp_core_dir . '/');
define('DB_NAME', cb_a3_admin_env('WP_DB_NAME', 'wordpress_test'));
define('DB_USER', cb_a3_admin_env('WP_DB_USER', 'root'));
define('DB_PASSWORD', cb_a3_admin_env('WP_DB_PASSWORD', ''));
define('DB_HOST', cb_a3_admin_env('WP_DB_HOST', '127.0.0.1:3306'));
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

try {
    require ABSPATH . 'wp-settings.php';

    // Match wp-admin/admin.php's canonical Administration API layer. This loads
    // WP_Screen and the normal admin helpers; no WordPress class is stubbed.
    require_once ABSPATH . 'wp-admin/includes/admin.php';

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $base_basename = plugin_basename($base_file);
    $starter_basename = plugin_basename($starter_file);
    cb_a3_admin_expect(is_plugin_active($base_basename), 'Base is not active.');
    cb_a3_admin_expect(is_plugin_active($starter_basename), 'Starter is not active.');
    cb_a3_admin_expect(function_exists('cb_starter_base_ready') && cb_starter_base_ready(), 'Starter dependency gate is not satisfied.');

    $admin = get_user_by('login', 'cbadmin');
    cb_a3_admin_expect($admin instanceof WP_User, 'Consumer administrator is missing.');
    wp_set_current_user((int) $admin->ID);

    // Public registration remains hook-driven. No Starter registration method is
    // called directly by this harness.
    do_action('admin_menu');

    $page = \CoreBlueprint\Core\Admin\PageRegistry::get('core-blueprint-starter-plugin');
    cb_a3_admin_expect($page instanceof \CoreBlueprint\Core\Admin\Page, 'Starter page was not registered through cb_core_register_pages.');

    $hook = \CoreBlueprint\Core\Admin\PageRegistry::hook_suffix('core-blueprint-starter-plugin');
    cb_a3_admin_expect('' !== $hook, 'Starter page did not receive a WordPress hook suffix.');

    set_current_screen($hook);
    do_action('admin_enqueue_scripts', $hook);
    cb_a3_admin_expect(wp_style_is('cb-starter-admin', 'enqueued'), 'Starter-owned stylesheet was not scoped to its registered page hook.');

    ob_start();
    do_action($hook);
    $html = (string) ob_get_clean();
    cb_a3_admin_expect(str_contains($html, 'Core Blueprint Starter Plugin'), 'Registered Starter page did not render through its WordPress page hook.');
    cb_a3_admin_expect(str_contains($html, 'cb-core-panel'), 'Starter page did not consume the documented panels contract.');
    cb_a3_admin_expect(str_contains($html, 'cb-core-notice'), 'Starter page did not render the documented Base Notice primitive.');

    fwrite(STDOUT, "[A3 consumer] admin PASS\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[A3 consumer] admin FAIL: {$e->getMessage()}\n");
    exit(1);
}
