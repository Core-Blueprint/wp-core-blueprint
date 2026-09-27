<?php
declare(strict_types=1);

/**
 * BASE-V1-A3 destructive uninstall scenario.
 *
 * The delete stage must succeed through WordPress delete_plugins(). The harness
 * never includes uninstall.php directly and never removes the Base folder as a
 * fallback. Each stage is one fresh PHP/WordPress request.
 */

$stage = isset($argv[1]) ? (string) $argv[1] : '';
$allowed_stages = [
    'install',
    'activate-base',
    'seed',
    'deactivate-base',
    'delete-base',
    'verify',
    'cleanup',
];

if (!in_array($stage, $allowed_stages, true)) {
    fwrite(STDERR, "Usage: php tests/uninstall/request.php <" . implode('|', $allowed_stages) . ">\n");
    exit(64);
}

function cb_a3_uninstall_env(string $name, string $default = ''): string {
    $value = getenv($name);
    return false === $value ? $default : (string) $value;
}

function cb_a3_uninstall_expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cb_a3_uninstall_db_connection(): mysqli {
    $host = cb_a3_uninstall_env('WP_DB_HOST', '127.0.0.1:3306');
    $port = 3306;
    if (preg_match('/^([^:]+):([0-9]+)$/D', $host, $matches)) {
        $host = $matches[1];
        $port = (int) $matches[2];
    }

    $mysqli = new mysqli(
        $host,
        cb_a3_uninstall_env('WP_DB_USER', 'root'),
        cb_a3_uninstall_env('WP_DB_PASSWORD', ''),
        cb_a3_uninstall_env('WP_DB_NAME', 'wordpress_test'),
        $port
    );
    if ($mysqli->connect_errno) {
        throw new RuntimeException('Could not connect to uninstall database: ' . $mysqli->connect_error);
    }

    return $mysqli;
}

function cb_a3_uninstall_drop_tables(): void {
    $prefix = cb_a3_uninstall_env('CB_UNINSTALL_TABLE_PREFIX', 'cbuninstall_');
    cb_a3_uninstall_expect(1 === preg_match('/^[A-Za-z0-9_]+$/D', $prefix), 'Unsafe uninstall table prefix.');

    $mysqli = cb_a3_uninstall_db_connection();
    $result = $mysqli->query('SHOW TABLES');
    if (false === $result) {
        throw new RuntimeException('Could not enumerate uninstall database tables.');
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
            throw new RuntimeException('Could not drop uninstall table: ' . $table);
        }
    }
    $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
    $mysqli->close();
}

if ('cleanup' === $stage) {
    try {
        cb_a3_uninstall_drop_tables();
        fwrite(STDOUT, "[A3 uninstall] cleanup PASS\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "[A3 uninstall] cleanup FAIL: {$e->getMessage()}\n");
        exit(1);
    }
}

$wp_core_dir = rtrim(cb_a3_uninstall_env('WP_CORE_DIR'), "/\\");
$base_file = cb_a3_uninstall_env('CB_PLUGIN_FILE');
$table_prefix = cb_a3_uninstall_env('CB_UNINSTALL_TABLE_PREFIX', 'cbuninstall_');

if ('' === $wp_core_dir || !is_file($wp_core_dir . '/wp-settings.php')) {
    fwrite(STDERR, "[A3 uninstall] WP_CORE_DIR must point to the pinned WordPress runtime.\n");
    exit(1);
}
if ('' === $base_file) {
    fwrite(STDERR, "[A3 uninstall] CB_PLUGIN_FILE is required.\n");
    exit(1);
}
if ('verify' !== $stage && !is_file($base_file)) {
    fwrite(STDERR, "[A3 uninstall] Base plugin file is missing before the delete verification stage.\n");
    exit(1);
}
if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $table_prefix)) {
    fwrite(STDERR, "[A3 uninstall] Unsafe uninstall table prefix.\n");
    exit(1);
}

if ('install' === $stage) {
    cb_a3_uninstall_drop_tables();
}

$_SERVER['HTTP_HOST'] = 'cb-a3-uninstall.local';
$_SERVER['SERVER_NAME'] = 'cb-a3-uninstall.local';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $wp_core_dir . '/index.php';

define('ABSPATH', $wp_core_dir . '/');
define('DB_NAME', cb_a3_uninstall_env('WP_DB_NAME', 'wordpress_test'));
define('DB_USER', cb_a3_uninstall_env('WP_DB_USER', 'root'));
define('DB_PASSWORD', cb_a3_uninstall_env('WP_DB_PASSWORD', ''));
define('DB_HOST', cb_a3_uninstall_env('WP_DB_HOST', '127.0.0.1:3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY', 'cb-a3-uninstall-auth-key');
define('SECURE_AUTH_KEY', 'cb-a3-uninstall-secure-auth-key');
define('LOGGED_IN_KEY', 'cb-a3-uninstall-logged-in-key');
define('NONCE_KEY', 'cb-a3-uninstall-nonce-key');
define('AUTH_SALT', 'cb-a3-uninstall-auth-salt');
define('SECURE_AUTH_SALT', 'cb-a3-uninstall-secure-auth-salt');
define('LOGGED_IN_SALT', 'cb-a3-uninstall-logged-in-salt');
define('NONCE_SALT', 'cb-a3-uninstall-nonce-salt');
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
    fwrite(STDERR, "[A3 uninstall] Could not create MU-plugin directory.\n");
    exit(1);
}
$mail_guard = $mu_dir . '/cb-a3-uninstall-mail-guard.php';
file_put_contents(
    $mail_guard,
    "<?php\nadd_filter( 'pre_wp_mail', static function () { return true; }, PHP_INT_MAX );\n"
);

try {
    require ABSPATH . 'wp-settings.php';

    if ('install' === $stage) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $installed = wp_install(
            'Core Blueprint A3 Uninstall',
            'cbadmin',
            'cbadmin@example.test',
            true,
            '',
            'cb-a3-uninstall-password',
            'en_US'
        );

        cb_a3_uninstall_expect(!empty($installed['user_id']), 'Uninstall site installation did not create an administrator.');
        cb_a3_uninstall_expect('http://cb-a3-uninstall.local' === untrailingslashit((string) get_option('siteurl', '')), 'Uninstall site URL was not persisted correctly.');
        fwrite(STDOUT, "[A3 uninstall] install PASS\n");
        exit(0);
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $base_basename = 'core-blueprint/core-blueprint.php';
    $admin = get_user_by('login', 'cbadmin');
    cb_a3_uninstall_expect($admin instanceof WP_User, 'Uninstall administrator is missing.');

    if ('activate-base' === $stage) {
        cb_a3_uninstall_expect(!is_plugin_active($base_basename), 'Base must start inactive in uninstall activate-base stage.');
        wp_set_current_user((int) $admin->ID);

        $result = activate_plugin($base_basename);
        cb_a3_uninstall_expect(!is_wp_error($result), 'Base activation returned WP_Error in uninstall scenario.');
        cb_a3_uninstall_expect(is_plugin_active($base_basename), 'Base was not persisted as active in uninstall scenario.');
        cb_a3_uninstall_expect(null !== get_role('cb_operator'), 'CB Operator role was not created by Base activation.');

        fwrite(STDOUT, "[A3 uninstall] activate-base PASS\n");
        exit(0);
    }

    if ('seed' === $stage) {
        cb_a3_uninstall_expect(is_plugin_active($base_basename), 'Base must be active before uninstall state is seeded.');
        wp_set_current_user((int) $admin->ID);

        update_option('cb_core_mail_enabled', '1', false);
        update_option('cb_core_integrity_a3_generation', 'delete-me', false);
        update_option('cb_core_schema_lock_a3', ['owner' => 'base'], false);
        update_option('cb_core_quarantine_mutation_lock_a3', ['owner' => 'base'], false);
        update_option('cb_core_2fa_ch_lock_a3', time(), false);
        update_option('cb_core_quarantine_workspace', ['evidence' => 'preserve-me'], false);
        update_option('vendor_extension_state', ['owner' => 'vendor', 'value' => 'preserve-me'], false);
        update_option('cb_core_beacon_a3_sentinel', 'preserve-me', false);

        set_transient('cb_core_alert_a3', 'delete-me', DAY_IN_SECONDS);
        set_transient('cb_core_2fa_ch_a3', ['user_id' => (int) $admin->ID], DAY_IN_SECONDS);
        set_transient('cb_core_two_factor_profile_notice_' . (int) $admin->ID, ['message' => 'delete-me'], DAY_IN_SECONDS);
        set_transient('vendor_keep_transient', 'preserve-me', DAY_IN_SECONDS);

        update_user_meta((int) $admin->ID, 'cb_core_theme', 'dark');
        update_user_meta((int) $admin->ID, '_cb_core_privileged_review', 'delete-me');
        update_user_meta((int) $admin->ID, '_cb_core_two_factor_totp_secret', ['v' => 1, 'ciphertext' => 'delete-me']);
        update_user_meta((int) $admin->ID, '_cb_core_two_factor_recovery_hashes', ['delete-me']);
        update_user_meta((int) $admin->ID, '_cb_core_two_factor_enrolled_at', time());
        update_user_meta((int) $admin->ID, '_cb_core_two_factor_last_timestep', 123456);
        update_user_meta((int) $admin->ID, '_cb_core_two_factor_challenge_generation', str_repeat('a', 64));
        update_user_meta((int) $admin->ID, '_cb_core_two_factor_pending_enrollment', ['delete' => 'me']);
        update_user_meta((int) $admin->ID, 'vendor_keep_user_meta', 'preserve-me');

        $admin_role = get_role('administrator');
        cb_a3_uninstall_expect(null !== $admin_role, 'Administrator role is missing before uninstall seed.');
        $admin_role->add_cap('vendor_keep_admin_cap');

        if (null !== get_role('vendor_a3_role')) {
            remove_role('vendor_a3_role');
        }
        add_role(
            'vendor_a3_role',
            'Vendor A3 Role',
            [
                'read' => true,
                'cb_manage_notes' => true,
                'vendor_keep_cap' => true,
            ]
        );
        $vendor_role = get_role('vendor_a3_role');
        cb_a3_uninstall_expect(null !== $vendor_role && $vendor_role->has_cap('cb_manage_notes'), 'Could not seed Base capability on synthetic vendor role.');
        cb_a3_uninstall_expect($vendor_role->has_cap('vendor_keep_cap'), 'Could not seed vendor capability on synthetic vendor role.');

        $post_id = wp_insert_post(
            [
                'post_type' => 'post',
                'post_status' => 'draft',
                'post_title' => 'A3 uninstall ownership sentinel',
            ],
            true
        );
        cb_a3_uninstall_expect(!is_wp_error($post_id) && (int) $post_id > 0, 'Could not create uninstall content sentinel.');
        $post_id = (int) $post_id;
        update_option('vendor_a3_post_id', $post_id, false);
        update_post_meta($post_id, '_cb_media_replaced_at', '2026-09-03 19:00:00');
        update_post_meta($post_id, '_cb_media_replaced_by', (int) $admin->ID);
        update_post_meta($post_id, '_cb_media_replace_revision', 3);
        update_post_meta($post_id, 'vendor_content_model_value', 'preserve-me');

        global $wpdb;
        $base_tables = [
            $wpdb->prefix . 'cb_core_audit_log',
            $wpdb->prefix . 'cb_core_mail_log',
            $wpdb->prefix . 'cb_core_notes',
            $wpdb->prefix . 'cb_maintenance_reports',
        ];
        foreach ($base_tables as $table) {
            cb_a3_uninstall_expect(1 === preg_match('/^[A-Za-z0-9_]+$/D', $table), 'Unsafe Base table identifier in uninstall seed.');
            $wpdb->query("CREATE TABLE IF NOT EXISTS `{$table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))");
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            cb_a3_uninstall_expect($found === $table, 'Could not establish Base-owned table sentinel: ' . $table);
        }

        $vendor_table = $wpdb->prefix . 'vendor_a3_owned';
        cb_a3_uninstall_expect(1 === preg_match('/^[A-Za-z0-9_]+$/D', $vendor_table), 'Unsafe vendor table identifier.');
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$vendor_table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, value VARCHAR(64) NOT NULL DEFAULT '', PRIMARY KEY (id))");
        $wpdb->insert($vendor_table, ['value' => 'preserve-me'], ['%s']);

        $base_cron_hooks = [
            'cb_core_daily_prune',
            'cb_core_privileged_guard_cron_sweep',
            'cb_core_integrity_scan_run',
            'cb_core_integrity_run_scan_async',
        ];
        foreach ($base_cron_hooks as $offset => $hook) {
            $args = ['a3'];
            wp_schedule_single_event(time() + 3600 + $offset, $hook, $args);
            cb_a3_uninstall_expect(false !== wp_next_scheduled($hook, $args), 'Could not seed Base cron sentinel: ' . $hook);
        }
        $vendor_cron_args = ['a3'];
        wp_schedule_single_event(time() + 7200, 'vendor_extension_cron', $vendor_cron_args);
        cb_a3_uninstall_expect(false !== wp_next_scheduled('vendor_extension_cron', $vendor_cron_args), 'Could not seed vendor cron sentinel.');

        $uploads = wp_get_upload_dir();
        $uploads_dir = (string) ($uploads['basedir'] ?? '');
        cb_a3_uninstall_expect('' !== $uploads_dir, 'WordPress uploads directory is unavailable.');
        if (!is_dir($uploads_dir) && !wp_mkdir_p($uploads_dir)) {
            throw new RuntimeException('Could not create uploads directory for Media Replace lock sentinel.');
        }
        $lock_file = trailingslashit($uploads_dir) . '.core-blueprint-media-replace.lock';
        cb_a3_uninstall_expect(false !== file_put_contents($lock_file, 'a3-lock'), 'Could not create Media Replace lock sentinel.');
        cb_a3_uninstall_expect(is_file($lock_file), 'Media Replace lock sentinel is missing after creation.');

        fwrite(STDOUT, "[A3 uninstall] seed PASS\n");
        exit(0);
    }

    if ('deactivate-base' === $stage) {
        cb_a3_uninstall_expect(is_plugin_active($base_basename), 'Base must be active before destructive deletion.');
        wp_set_current_user((int) $admin->ID);
        deactivate_plugins($base_basename);
        cb_a3_uninstall_expect(!is_plugin_active($base_basename), 'Base remained active after deactivation.');
        cb_a3_uninstall_expect(is_file($base_file), 'Deactivation must not delete Base plugin files.');

        fwrite(STDOUT, "[A3 uninstall] deactivate-base PASS\n");
        exit(0);
    }

    if ('delete-base' === $stage) {
        cb_a3_uninstall_expect(!is_plugin_active($base_basename), 'Base must be inactive before delete_plugins().');
        cb_a3_uninstall_expect(is_file($base_file), 'Base plugin file must exist before delete_plugins().');

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $method = get_filesystem_method([], WP_CONTENT_DIR, true);
        cb_a3_uninstall_expect('direct' === $method, 'Disposable uninstall runtime did not resolve the direct WordPress filesystem method.');
        cb_a3_uninstall_expect(WP_Filesystem(), 'WordPress filesystem initialization failed before delete_plugins().');
        global $wp_filesystem;
        cb_a3_uninstall_expect(is_object($wp_filesystem), 'WordPress filesystem global was not initialized.');

        $result = delete_plugins([$base_basename]);
        if (is_wp_error($result)) {
            throw new RuntimeException('delete_plugins() returned WP_Error: ' . $result->get_error_message());
        }
        cb_a3_uninstall_expect(true === $result, 'delete_plugins() must return true; null or any non-true result is a hard failure.');
        cb_a3_uninstall_expect(!is_file($base_file), 'delete_plugins() reported success but the Base entrypoint still exists.');
        cb_a3_uninstall_expect(!is_dir(dirname($base_file)), 'delete_plugins() reported success but the Base plugin directory still exists.');

        fwrite(STDOUT, "[A3 uninstall] delete-base PASS\n");
        exit(0);
    }

    if ('verify' === $stage) {
        cb_a3_uninstall_expect(!is_file($base_file), 'Base entrypoint survived destructive deletion.');
        cb_a3_uninstall_expect(!is_dir(dirname($base_file)), 'Base plugin directory survived destructive deletion.');
        cb_a3_uninstall_expect(!is_plugin_active($base_basename), 'Deleted Base remains marked active.');

        cb_a3_uninstall_expect(null === get_role('cb_operator'), 'CB Operator role survived uninstall.');
        $admin = get_user_by('login', 'cbadmin');
        cb_a3_uninstall_expect($admin instanceof WP_User, 'Administrator disappeared during Base uninstall.');
        cb_a3_uninstall_expect(!in_array('cb_operator', (array) $admin->roles, true), 'CB Operator assignment survived uninstall.');

        $admin_role = get_role('administrator');
        cb_a3_uninstall_expect(null !== $admin_role, 'Administrator role disappeared during Base uninstall.');
        cb_a3_uninstall_expect(!$admin_role->has_cap('cb_manage_notes'), 'Base-owned Administrator capability survived uninstall.');
        cb_a3_uninstall_expect($admin_role->has_cap('vendor_keep_admin_cap'), 'Third-party Administrator capability was deleted by Base uninstall.');

        $vendor_role = get_role('vendor_a3_role');
        cb_a3_uninstall_expect(null !== $vendor_role, 'Synthetic vendor role was deleted by Base uninstall.');
        cb_a3_uninstall_expect(!$vendor_role->has_cap('cb_manage_notes'), 'Base-owned capability survived on a third-party role.');
        cb_a3_uninstall_expect($vendor_role->has_cap('vendor_keep_cap'), 'Third-party capability was deleted from a third-party role.');

        foreach (['cb_core_settings', 'cb_core_bypass_token', 'cb_core_mail_enabled', 'cb_core_integrity_a3_generation', 'cb_core_schema_lock_a3', 'cb_core_quarantine_mutation_lock_a3', 'cb_core_2fa_ch_lock_a3'] as $option) {
            cb_a3_uninstall_expect(false === get_option($option, false), 'Base-owned option survived uninstall: ' . $option);
        }
        cb_a3_uninstall_expect(['evidence' => 'preserve-me'] === get_option('cb_core_quarantine_workspace', null), 'Quarantine evidence index was deleted by Base uninstall.');
        cb_a3_uninstall_expect(['owner' => 'vendor', 'value' => 'preserve-me'] === get_option('vendor_extension_state', null), 'Synthetic extension option was deleted by Base uninstall.');
        cb_a3_uninstall_expect('preserve-me' === get_option('cb_core_beacon_a3_sentinel', false), 'Sibling-style Core Blueprint option was deleted by Base uninstall.');

        cb_a3_uninstall_expect(false === get_transient('cb_core_alert_a3'), 'Base-owned transient survived uninstall.');
        cb_a3_uninstall_expect(false === get_transient('cb_core_2fa_ch_a3'), 'Base-owned 2FA challenge transient survived uninstall.');
        cb_a3_uninstall_expect(false === get_transient('cb_core_two_factor_profile_notice_' . (int) $admin->ID), 'Base-owned 2FA profile notice transient survived uninstall.');
        cb_a3_uninstall_expect('preserve-me' === get_transient('vendor_keep_transient'), 'Third-party transient was deleted by Base uninstall.');

        cb_a3_uninstall_expect('' === (string) get_user_meta((int) $admin->ID, 'cb_core_theme', true), 'Base-owned user theme metadata survived uninstall.');
        cb_a3_uninstall_expect('' === (string) get_user_meta((int) $admin->ID, '_cb_core_privileged_review', true), 'Base privileged-review metadata survived uninstall.');
        foreach ([
            '_cb_core_two_factor_totp_secret',
            '_cb_core_two_factor_recovery_hashes',
            '_cb_core_two_factor_enrolled_at',
            '_cb_core_two_factor_last_timestep',
            '_cb_core_two_factor_challenge_generation',
            '_cb_core_two_factor_pending_enrollment',
        ] as $meta_key) {
            cb_a3_uninstall_expect(
                '' === (string) get_user_meta((int) $admin->ID, $meta_key, true),
                'Base-owned 2FA user metadata survived uninstall: ' . $meta_key
            );
        }
        cb_a3_uninstall_expect('preserve-me' === get_user_meta((int) $admin->ID, 'vendor_keep_user_meta', true), 'Third-party user metadata was deleted by Base uninstall.');

        $post_id = (int) get_option('vendor_a3_post_id', 0);
        cb_a3_uninstall_expect($post_id > 0 && null !== get_post($post_id), 'Ordinary WordPress content was deleted by Base uninstall.');
        foreach (['_cb_media_replaced_at', '_cb_media_replaced_by', '_cb_media_replace_revision'] as $meta_key) {
            cb_a3_uninstall_expect('' === (string) get_post_meta($post_id, $meta_key, true), 'Media Replace operational metadata survived uninstall: ' . $meta_key);
        }
        cb_a3_uninstall_expect('preserve-me' === get_post_meta($post_id, 'vendor_content_model_value', true), 'Ordinary content metadata was deleted by Base uninstall.');

        global $wpdb;
        $base_tables = [
            $wpdb->prefix . 'cb_core_audit_log',
            $wpdb->prefix . 'cb_core_mail_log',
            $wpdb->prefix . 'cb_core_notes',
            $wpdb->prefix . 'cb_maintenance_reports',
        ];
        foreach ($base_tables as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            cb_a3_uninstall_expect(null === $found, 'Base-owned database table survived uninstall: ' . $table);
        }

        $vendor_table = $wpdb->prefix . 'vendor_a3_owned';
        $vendor_found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($vendor_table)));
        cb_a3_uninstall_expect($vendor_found === $vendor_table, 'Third-party database table was deleted by Base uninstall.');
        cb_a3_uninstall_expect(1 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$vendor_table}` WHERE value = 'preserve-me'"), 'Third-party database table contents were altered by Base uninstall.');

        foreach (['cb_core_daily_prune', 'cb_core_privileged_guard_cron_sweep', 'cb_core_integrity_scan_run', 'cb_core_integrity_run_scan_async'] as $hook) {
            cb_a3_uninstall_expect(false === wp_next_scheduled($hook), 'Base-owned normal cron hook survived uninstall: ' . $hook);
            cb_a3_uninstall_expect(false === wp_next_scheduled($hook, ['a3']), 'Base-owned A3 cron sentinel survived uninstall: ' . $hook);
        }
        cb_a3_uninstall_expect(false !== wp_next_scheduled('vendor_extension_cron', ['a3']), 'Third-party cron hook was deleted by Base uninstall.');

        $uploads = wp_get_upload_dir();
        $lock_file = trailingslashit((string) ($uploads['basedir'] ?? '')) . '.core-blueprint-media-replace.lock';
        cb_a3_uninstall_expect(!is_file($lock_file), 'Media Replace filesystem lock survived uninstall.');

        fwrite(STDOUT, "[A3 uninstall] verify PASS\n");
        exit(0);
    }

    throw new RuntimeException('Unhandled uninstall stage.');
} catch (Throwable $e) {
    fwrite(STDERR, "[A3 uninstall] {$stage} FAIL: {$e->getMessage()}\n");
    exit(1);
}
