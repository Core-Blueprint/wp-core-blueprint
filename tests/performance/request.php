<?php
declare(strict_types=1);

/**
 * BASE-V1-F1 runtime performance baseline request harness.
 *
 * This file intentionally contains no numeric performance gates. Each invocation
 * is one isolated PHP/WordPress request and emits one JSON record that can be
 * compared across commits, WordPress versions and PHP versions.
 */

$memory_at_start = memory_get_usage(true);
$stage = isset($argv[1]) ? (string) $argv[1] : '';

$profile_scenarios = [
    'frontend'   => [ 'type' => 'frontend', 'page' => '' ],
    'admin'      => [ 'type' => 'admin', 'page' => '' ],
    'dashboard'  => [ 'type' => 'admin', 'page' => 'core-blueprint' ],
    'logs'       => [ 'type' => 'admin', 'page' => 'core-blueprint-logs' ],
    'reports'    => [ 'type' => 'admin', 'page' => 'core-blueprint-reports' ],
    'safeguards' => [ 'type' => 'admin', 'page' => 'core-blueprint-safeguards' ],
];
$allowed_stages = array_merge([ 'install', 'activate', 'disable_modules', 'cleanup' ], array_keys($profile_scenarios));

if (!in_array($stage, $allowed_stages, true)) {
    fwrite(STDERR, "Usage: php tests/performance/request.php <" . implode('|', $allowed_stages) . ">\n");
    exit(64);
}

function cb_f1_env(string $name, string $default = ''): string {
    $value = getenv($name);
    return false === $value ? $default : (string) $value;
}

function cb_f1_expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cb_f1_db_connection(): mysqli {
    $host = cb_f1_env('WP_DB_HOST', '127.0.0.1:3306');
    $port = 3306;
    if (preg_match('/^([^:]+):([0-9]+)$/D', $host, $matches)) {
        $host = $matches[1];
        $port = (int) $matches[2];
    }

    $mysqli = new mysqli(
        $host,
        cb_f1_env('WP_DB_USER', 'root'),
        cb_f1_env('WP_DB_PASSWORD', ''),
        cb_f1_env('WP_DB_NAME', 'wordpress_test'),
        $port
    );
    if ($mysqli->connect_errno) {
        throw new RuntimeException('Could not connect to performance database: ' . $mysqli->connect_error);
    }

    return $mysqli;
}

function cb_f1_drop_isolated_tables(): void {
    $prefix = cb_f1_env('CB_PERFORMANCE_TABLE_PREFIX', 'cbperf_');
    cb_f1_expect(1 === preg_match('/^[A-Za-z0-9_]+$/D', $prefix), 'Unsafe performance table prefix.');

    $mysqli = cb_f1_db_connection();
    $result = $mysqli->query('SHOW TABLES');
    if (false === $result) {
        throw new RuntimeException('Could not enumerate performance database tables.');
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
            throw new RuntimeException('Could not drop performance table: ' . $table);
        }
    }
    $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
    $mysqli->close();

    $wp_core_dir = rtrim(cb_f1_env('WP_CORE_DIR'), "/\\");
    if ('' !== $wp_core_dir) {
        $mail_guard = $wp_core_dir . '/wp-content/mu-plugins/cb-f1-mail-guard.php';
        if (is_file($mail_guard)) {
            @unlink($mail_guard);
        }
    }
}

function cb_f1_asset_path(string $src): string {
    if (!defined('CB_CORE_URL') || !defined('CB_CORE_DIR') || '' === $src) {
        return '';
    }

    $src_path = wp_parse_url($src, PHP_URL_PATH);
    $core_path = wp_parse_url(CB_CORE_URL, PHP_URL_PATH);
    if (!is_string($src_path) || !is_string($core_path) || !str_starts_with($src_path, $core_path)) {
        return '';
    }

    $relative = ltrim(substr($src_path, strlen($core_path)), '/');
    if ('' === $relative || str_contains($relative, '..')) {
        return '';
    }

    $file = CB_CORE_DIR . $relative;
    return is_file($file) ? $file : '';
}

/**
 * @return array{handles:string[],cb_handles:string[],cb_bytes:int}
 */
function cb_f1_classic_assets(WP_Dependencies $registry): array {
    $queue = array_values(array_unique(array_map('strval', (array) $registry->queue)));
    $registry->all_deps($queue);
    $handles = array_values(array_unique(array_map('strval', (array) $registry->to_do)));

    $cb_handles = [];
    $cb_bytes = 0;
    foreach ($handles as $handle) {
        $item = $registry->registered[$handle] ?? null;
        if (!is_object($item) || !isset($item->src) || !is_string($item->src)) {
            continue;
        }
        $file = cb_f1_asset_path($item->src);
        if ('' === $file) {
            continue;
        }
        $cb_handles[] = $handle;
        $size = filesize($file);
        if (false !== $size) {
            $cb_bytes += (int) $size;
        }
    }

    sort($handles);
    sort($cb_handles);

    return [
        'handles' => $handles,
        'cb_handles' => $cb_handles,
        'cb_bytes' => $cb_bytes,
    ];
}

/**
 * @return array{handles:string[],cb_handles:string[],cb_bytes:int}
 */
function cb_f1_module_assets(): array {
    if (!function_exists('wp_script_modules')) {
        return [ 'handles' => [], 'cb_handles' => [], 'cb_bytes' => 0 ];
    }

    $modules = wp_script_modules();
    if (!is_object($modules) || !method_exists($modules, 'get_queue') || !method_exists($modules, 'get_registered')) {
        return [ 'handles' => [], 'cb_handles' => [], 'cb_bytes' => 0 ];
    }

    $handles = array_values(array_unique(array_map('strval', (array) $modules->get_queue())));
    $resolved = [];
    $pending = $handles;
    while ([] !== $pending) {
        $id = array_shift($pending);
        if (!is_string($id) || '' === $id || isset($resolved[$id])) {
            continue;
        }
        $registered = $modules->get_registered($id);
        if (!is_array($registered)) {
            continue;
        }
        $resolved[$id] = true;
        foreach ((array) ($registered['dependencies'] ?? []) as $dependency) {
            if (!is_array($dependency) || 'dynamic' === (string) ($dependency['import'] ?? 'static')) {
                continue;
            }
            $dependency_id = (string) ($dependency['id'] ?? '');
            if ('' !== $dependency_id && !isset($resolved[$dependency_id])) {
                $pending[] = $dependency_id;
            }
        }
    }
    $handles = array_keys($resolved);
    $cb_handles = [];
    $cb_bytes = 0;
    foreach ($handles as $handle) {
        $registered = $modules->get_registered($handle);
        if (!is_array($registered) || !isset($registered['src']) || !is_string($registered['src'])) {
            continue;
        }
        $file = cb_f1_asset_path($registered['src']);
        if ('' === $file) {
            continue;
        }
        $cb_handles[] = $handle;
        $size = filesize($file);
        if (false !== $size) {
            $cb_bytes += (int) $size;
        }
    }

    sort($handles);
    sort($cb_handles);

    return [
        'handles' => $handles,
        'cb_handles' => $cb_handles,
        'cb_bytes' => $cb_bytes,
    ];
}

/**
 * @return array{total_count:int,total_bytes:int,cb_count:int,cb_bytes:int,cb_options:string[]}
 */
function cb_f1_autoload_metrics(): array {
    $alloptions = wp_load_alloptions();
    $total_bytes = 0;
    $cb_bytes = 0;
    $cb_options = [];

    foreach ($alloptions as $name => $value) {
        $name = (string) $name;
        $serialized = is_string($value) ? $value : maybe_serialize($value);
        $bytes = strlen($name) + strlen((string) $serialized);
        $total_bytes += $bytes;
        if (str_starts_with($name, 'cb_')) {
            $cb_options[] = $name;
            $cb_bytes += $bytes;
        }
    }
    sort($cb_options);

    return [
        'total_count' => count($alloptions),
        'total_bytes' => $total_bytes,
        'cb_count' => count($cb_options),
        'cb_bytes' => $cb_bytes,
        'cb_options' => $cb_options,
    ];
}

/**
 * @return array{total_events:int,cb_events:int,cb_hooks:string[]}
 */
function cb_f1_cron_metrics(): array {
    $cron = _get_cron_array();
    $total_events = 0;
    $cb_events = 0;
    $cb_hooks = [];

    if (!is_array($cron)) {
        return [ 'total_events' => 0, 'cb_events' => 0, 'cb_hooks' => [] ];
    }

    foreach ($cron as $hooks) {
        if (!is_array($hooks)) {
            continue;
        }
        foreach ($hooks as $hook => $instances) {
            if (!is_array($instances)) {
                continue;
            }
            $count = count($instances);
            $total_events += $count;
            if (str_starts_with((string) $hook, 'cb_')) {
                $cb_events += $count;
                $cb_hooks[] = (string) $hook;
            }
        }
    }

    $cb_hooks = array_values(array_unique($cb_hooks));
    sort($cb_hooks);

    return [
        'total_events' => $total_events,
        'cb_events' => $cb_events,
        'cb_hooks' => $cb_hooks,
    ];
}

function cb_f1_render_admin_scenario(string $scenario, string $page): void {
    require_once ABSPATH . 'wp-admin/includes/admin.php';

    $admin = get_user_by('login', 'cbadmin');
    cb_f1_expect($admin instanceof WP_User, 'Performance administrator is missing.');
    wp_set_current_user((int) $admin->ID);

    do_action('admin_init');
    do_action('admin_menu');

    if ('admin' === $scenario) {
        $hook = 'index.php';
        set_current_screen('dashboard');
        do_action('load-' . $hook);
        do_action('admin_enqueue_scripts', $hook);
        return;
    }

    if ('core-blueprint' === $page) {
        $hook = 'toplevel_page_core-blueprint';
        set_current_screen($hook);
        do_action('load-' . $hook);
        do_action('admin_enqueue_scripts', $hook);
        ob_start();
        \CB\Core\Admin\Admin::render_parent_landing();
        ob_end_clean();
        return;
    }

    $hook = \CB\Core\Admin\PageRegistry::hook_suffix($page);
    cb_f1_expect('' !== $hook, "Core Admin page '{$page}' did not register a hook suffix.");
    set_current_screen($hook);
    do_action('load-' . $hook);
    do_action('admin_enqueue_scripts', $hook);

    $registered_page = \CB\Core\Admin\PageRegistry::get($page);
    cb_f1_expect($registered_page instanceof \CB\Core\Admin\Page, "Core Admin page '{$page}' is not registered.");
    ob_start();
    $registered_page->render();
    ob_end_clean();
}

if ('cleanup' === $stage) {
    try {
        cb_f1_drop_isolated_tables();
        fwrite(STDOUT, "[F1] cleanup PASS\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "[F1] cleanup FAIL: {$e->getMessage()}\n");
        exit(1);
    }
}

$wp_core_dir = rtrim(cb_f1_env('WP_CORE_DIR'), "/\\");
$plugin_file = cb_f1_env('CB_PLUGIN_FILE');
$table_prefix = cb_f1_env('CB_PERFORMANCE_TABLE_PREFIX', 'cbperf_');

if ('' === $wp_core_dir || !is_file($wp_core_dir . '/wp-settings.php')) {
    fwrite(STDERR, "[F1] WP_CORE_DIR must point to the pinned WordPress runtime.\n");
    exit(1);
}
if ('' === $plugin_file || !is_file($plugin_file)) {
    fwrite(STDERR, "[F1] CB_PLUGIN_FILE must point to the copied Core Blueprint entrypoint.\n");
    exit(1);
}
if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $table_prefix)) {
    fwrite(STDERR, "[F1] Unsafe performance table prefix.\n");
    exit(1);
}

$is_install = 'install' === $stage;
$is_activate = 'activate' === $stage;
$is_disable_modules = 'disable_modules' === $stage;
$is_profile = isset($profile_scenarios[$stage]);
$is_admin = $is_profile && 'admin' === ($profile_scenarios[$stage]['type'] ?? '');
$page = $is_profile ? (string) ($profile_scenarios[$stage]['page'] ?? '') : '';

if ($is_install) {
    cb_f1_drop_isolated_tables();
}

$_GET = [];
$_POST = [];
$_REQUEST = [];
$_SERVER['HTTP_HOST'] = 'cb-f1.local';
$_SERVER['SERVER_NAME'] = 'cb-f1.local';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '80';

if ($is_admin) {
    if ('' !== $page) {
        $_GET['page'] = $page;
        $_REQUEST['page'] = $page;
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . rawurlencode($page);
        $_SERVER['SCRIPT_NAME'] = '/wp-admin/admin.php';
        $_SERVER['PHP_SELF'] = '/wp-admin/admin.php';
        $_SERVER['SCRIPT_FILENAME'] = $wp_core_dir . '/wp-admin/admin.php';
    } else {
        $_SERVER['REQUEST_URI'] = '/wp-admin/index.php';
        $_SERVER['SCRIPT_NAME'] = '/wp-admin/index.php';
        $_SERVER['PHP_SELF'] = '/wp-admin/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $wp_core_dir . '/wp-admin/index.php';
    }
} else {
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $wp_core_dir . '/index.php';
}

define('ABSPATH', $wp_core_dir . '/');
define('DB_NAME', cb_f1_env('WP_DB_NAME', 'wordpress_test'));
define('DB_USER', cb_f1_env('WP_DB_USER', 'root'));
define('DB_PASSWORD', cb_f1_env('WP_DB_PASSWORD', ''));
define('DB_HOST', cb_f1_env('WP_DB_HOST', '127.0.0.1:3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY', 'cb-f1-auth-key');
define('SECURE_AUTH_KEY', 'cb-f1-secure-auth-key');
define('LOGGED_IN_KEY', 'cb-f1-logged-in-key');
define('NONCE_KEY', 'cb-f1-nonce-key');
define('AUTH_SALT', 'cb-f1-auth-salt');
define('SECURE_AUTH_SALT', 'cb-f1-secure-auth-salt');
define('LOGGED_IN_SALT', 'cb-f1-logged-in-salt');
define('NONCE_SALT', 'cb-f1-nonce-salt');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false);
define('DISABLE_WP_CRON', true);
define('WP_ENVIRONMENT_TYPE', 'local');

if ($is_admin) {
    define('WP_ADMIN', true);
}
if ($is_install) {
    define('WP_INSTALLING', true);
}

$mu_dir = ABSPATH . 'wp-content/mu-plugins';
if (!is_dir($mu_dir) && !mkdir($mu_dir, 0777, true) && !is_dir($mu_dir)) {
    fwrite(STDERR, "[F1] Could not create MU-plugin directory.\n");
    exit(1);
}
$mail_guard = $mu_dir . '/cb-f1-mail-guard.php';
file_put_contents(
    $mail_guard,
    "<?php\nadd_filter( 'pre_wp_mail', static function () { return true; }, PHP_INT_MAX );\n"
);

try {
    require ABSPATH . 'wp-settings.php';

    if ($is_install) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $installed = wp_install(
            'Core Blueprint F1',
            'cbadmin',
            'cbadmin@example.test',
            true,
            '',
            'cb-f1-test-password',
            'en_US'
        );
        cb_f1_expect(!empty($installed['user_id']), 'WordPress performance site installation did not create the administrator.');
        fwrite(STDOUT, "[F1] install PASS\n");
        exit(0);
    }

    if ($is_activate) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $admin = get_user_by('login', 'cbadmin');
        cb_f1_expect($admin instanceof WP_User, 'Performance administrator is missing before activation.');
        wp_set_current_user((int) $admin->ID);

        $plugin_basename = plugin_basename($plugin_file);
        cb_f1_expect('core-blueprint/core-blueprint.php' === $plugin_basename, 'Performance harness must use the canonical core-blueprint/ plugin folder.');
        cb_f1_expect(!is_plugin_active($plugin_basename), 'Core Blueprint must start inactive before the F1 activation stage.');
        $result = activate_plugin($plugin_basename);
        cb_f1_expect(!is_wp_error($result), 'Core Blueprint activation returned WP_Error in performance setup.');
        cb_f1_expect(is_plugin_active($plugin_basename), 'Core Blueprint did not become active in performance setup.');

        fwrite(STDOUT, "[F1] activate PASS\n");
        exit(0);
    }

    if ($is_disable_modules) {
        $admin = get_user_by('login', 'cbadmin');
        cb_f1_expect($admin instanceof WP_User, 'Performance administrator is missing before module disablement.');
        wp_set_current_user((int) $admin->ID);

        $definitions = \\CB\\Core\\Modules\\ActivationRegistry::definitions();
        cb_f1_expect([] !== $definitions, 'Canonical module activation registry is empty.');

        $disabled = [];
        foreach ($definitions as $id => $definition) {
            $state = $definition['state'] ?? '';
            cb_f1_expect(is_string($state) && '' !== $state, 'Module activation definition has no state class: ' . (string) $id);
            $state::set_enabled(false, 'performance-harness');
            cb_f1_expect(false === $state::is_enabled(), 'Module remained enabled after canonical disablement: ' . (string) $id);
            $disabled[] = (string) $id;
        }

        sort($disabled);
        fwrite(STDOUT, "[F3A] canonical module disablement PASS: " . implode(',', $disabled) . "\n");
        exit(0);
    }

    if ('frontend' === $stage) {
        wp();
        do_action('wp_enqueue_scripts');
    } else {
        cb_f1_render_admin_scenario($stage, $page);
    }

    global $wpdb;
    cb_f1_expect($wpdb instanceof wpdb, 'WordPress database object is unavailable.');

    $request_memory_usage = memory_get_usage(true);
    $request_memory_peak = memory_get_peak_usage(true);
    $request_queries = (int) $wpdb->num_queries;

    $styles = cb_f1_classic_assets(wp_styles());
    $scripts = cb_f1_classic_assets(wp_scripts());
    $modules = cb_f1_module_assets();
    $autoload = cb_f1_autoload_metrics();
    $cron = cb_f1_cron_metrics();

    $result = [
        'schema' => 1,
        'scenario' => $stage,
        'wordpress' => get_bloginfo('version'),
        'php' => PHP_VERSION,
        'metrics' => [
            'memory' => [
                'usage_bytes' => $request_memory_usage,
                'peak_bytes' => $request_memory_peak,
                'peak_delta_bytes' => max(0, $request_memory_peak - $memory_at_start),
            ],
            'database' => [
                'queries' => $request_queries,
            ],
            'assets' => [
                'style_count' => count($styles['handles']),
                'script_count' => count($scripts['handles']),
                'script_module_count' => count($modules['handles']),
                'cb_style_count' => count($styles['cb_handles']),
                'cb_script_count' => count($scripts['cb_handles']),
                'cb_script_module_count' => count($modules['cb_handles']),
                'cb_local_bytes' => $styles['cb_bytes'] + $scripts['cb_bytes'] + $modules['cb_bytes'],
                'cb_styles' => $styles['cb_handles'],
                'cb_scripts' => $scripts['cb_handles'],
                'cb_script_modules' => $modules['cb_handles'],
            ],
            'autoload' => $autoload,
            'cron' => $cron,
        ],
    ];

    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fwrite(STDOUT, $json . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[F1] {$stage} FAIL: {$e->getMessage()}\n");
    exit(1);
}
