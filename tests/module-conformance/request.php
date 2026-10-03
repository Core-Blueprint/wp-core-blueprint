<?php
declare(strict_types=1);

/**
 * BASE-V1-B3 full optional-module conformance scenario.
 *
 * Every invocation is a fresh WordPress/PHP request. Persistent state is shared
 * only through one isolated database prefix and the normal WordPress content
 * directory, so request-local hook/static state cannot leak across ON/OFF boots.
 */

require_once __DIR__ . '/support.php';

$stage = isset($argv[1]) ? (string) $argv[1] : '';
$allowed = [
    'install',
    'activate',
    'seed-enable',
    'verify-on-screen',
    'verify-on-admin-post',
    'disable',
    'verify-off-screen',
    'verify-off-admin-post',
    'reenable',
    'verify-restored-screen',
    'verify-restored-admin-post',
    'cleanup',
];
if (!in_array($stage, $allowed, true)) {
    fwrite(STDERR, "Usage: php tests/module-conformance/request.php <" . implode('|', $allowed) . ">\n");
    exit(64);
}

$wp_core_dir = rtrim(cb_b3_env('WP_CORE_DIR'), "/\\");
$plugin_file = cb_b3_env('CB_PLUGIN_FILE');
$table_prefix = cb_b3_env('CB_B3_TABLE_PREFIX', 'cbb3_');

if ('' === $wp_core_dir || !is_file($wp_core_dir . '/wp-settings.php')) {
    fwrite(STDERR, "[B3] WP_CORE_DIR must point to the pinned WordPress runtime.\n");
    exit(1);
}
if ('' === $plugin_file || !is_file($plugin_file)) {
    fwrite(STDERR, "[B3] CB_PLUGIN_FILE must point to the copied Core Blueprint entrypoint.\n");
    exit(1);
}
if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $table_prefix)) {
    fwrite(STDERR, "[B3] Unsafe table prefix.\n");
    exit(1);
}

if ('install' === $stage) {
    cb_b3_drop_isolated_tables();
}

$is_admin_post = str_contains($stage, 'admin-post');
$script = $is_admin_post ? '/wp-admin/admin-post.php' : '/wp-admin/admin.php';
$_SERVER['HTTP_HOST'] = 'cb-b3.local';
$_SERVER['SERVER_NAME'] = 'cb-b3.local';
$_SERVER['REQUEST_URI'] = $script;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SCRIPT_NAME'] = $script;
$_SERVER['PHP_SELF'] = $script;
$_SERVER['SCRIPT_FILENAME'] = $wp_core_dir . $script;

if (!defined('WP_ADMIN')) {
    define('WP_ADMIN', true);
}
define('ABSPATH', $wp_core_dir . '/');
define('DB_NAME', cb_b3_env('WP_DB_NAME', 'wordpress_test'));
define('DB_USER', cb_b3_env('WP_DB_USER', 'root'));
define('DB_PASSWORD', cb_b3_env('WP_DB_PASSWORD', ''));
define('DB_HOST', cb_b3_env('WP_DB_HOST', '127.0.0.1:3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY', 'cb-b3-auth-key');
define('SECURE_AUTH_KEY', 'cb-b3-secure-auth-key');
define('LOGGED_IN_KEY', 'cb-b3-logged-in-key');
define('NONCE_KEY', 'cb-b3-nonce-key');
define('AUTH_SALT', 'cb-b3-auth-salt');
define('SECURE_AUTH_SALT', 'cb-b3-secure-auth-salt');
define('LOGGED_IN_SALT', 'cb-b3-logged-in-salt');
define('NONCE_SALT', 'cb-b3-nonce-salt');
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
    fwrite(STDERR, "[B3] Could not create MU-plugin directory.\n");
    exit(1);
}
$mail_guard = $mu_dir . '/cb-b3-mail-guard.php';
file_put_contents($mail_guard, "<?php\nadd_filter( 'pre_wp_mail', static function () { return true; }, PHP_INT_MAX );\n");

try {
    require ABSPATH . 'wp-settings.php';

    if ('install' === $stage) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $installed = wp_install('Core Blueprint B3','cbadmin','cbadmin@example.test',true,'','cb-b3-test-password','en_US');
        cb_b3_expect(!empty($installed['user_id']), 'B3 WordPress install did not create the administrator.');
        cb_b3_expect(false === get_option('cb_core_first_activated_at', false), 'Base unexpectedly active after bare B3 install.');
        fwrite(STDOUT, "[B3] install PASS\n");
        exit(0);
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin_basename = plugin_basename($plugin_file);
    cb_b3_expect('core-blueprint/core-blueprint.php' === $plugin_basename, 'B3 must use the canonical core-blueprint/ plugin folder.');
    $admin = get_user_by('login', 'cbadmin');
    cb_b3_expect($admin instanceof WP_User, 'B3 administrator is missing.');

    if ('activate' === $stage) {
        cb_b3_expect(!is_plugin_active($plugin_basename), 'Base must start inactive in B3 activate stage.');
        wp_set_current_user((int) $admin->ID);
        $result = activate_plugin($plugin_basename);
        cb_b3_expect(!is_wp_error($result), 'B3 Base activation returned WP_Error.');
        cb_b3_expect(is_plugin_active($plugin_basename), 'B3 Base activation did not persist.');
        $admin = get_user_by('login', 'cbadmin');
        cb_b3_expect($admin instanceof WP_User, 'B3 administrator disappeared during activation.');
        cb_b3_expect([] !== \CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry::valid_approval_record($admin), 'B3 first operator is not approved.');
        fwrite(STDOUT, "[B3] activate PASS\n");
        exit(0);
    }

    cb_b3_expect(is_plugin_active($plugin_basename), 'Base must be active after B3 activation.');
    wp_set_current_user((int) $admin->ID);
    cb_b3_expect([] !== \CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry::valid_approval_record($admin), 'B3 operator approval is no longer valid.');
    cb_b3_expect(12 === count(cb_b3_modules()), 'B3 canonical module registry count mismatch.');

    if ('seed-enable' === $stage) {
        $login = \CoreBlueprint\Core\Security\LoginShield::save([
            'enabled' => false,
            'slug' => 'cb-b3-login',
            'mode' => \CoreBlueprint\Core\Security\LoginShield::MODE_STANDARD,
            'redirect_after_login' => \CoreBlueprint\Core\Security\LoginShield::REDIRECT_HOMEPAGE,
            'redirect_custom_url' => '',
            'block_response_code' => \CoreBlueprint\Core\Security\LoginShield::RESPONSE_CODE_404,
        ], 'b3-seed');
        cb_b3_expect('cb-b3-login' === $login['slug'], 'Could not seed Login Shield slug.');

        \CoreBlueprint\Core\Settings::set_feature_enabled('fingerprint', 'remove_asset_version_query', false, 'b3-seed');
        \CoreBlueprint\Core\Settings::set_feature_enabled('fingerprint', 'remove_wp_version_meta', true, 'b3-seed');

        \CoreBlueprint\Core\Integrity\State::set_enabled(false, 'b3-seed');
        \CoreBlueprint\Core\Integrity\Storage\ResultRepository::saveSettings([
            'schedule' => 'daily',
            'plugin_checksums' => false,
            'theme_checksums' => true,
            'uploads_scan' => false,
            'max_visible_findings' => 73,
        ]);

        $definition = \CoreBlueprint\Core\ContentModels\Repository::normalize_post_type([
            'key' => 'cb_b3_item',
            'singular_label' => 'B3 Item',
            'plural_label' => 'B3 Items',
            'description' => 'B3 preserved content model',
            'public' => true,
            'show_in_rest' => true,
            'has_archive' => true,
            'hierarchical' => false,
            'rewrite_slug' => 'b3-items',
            'icon' => 'dashicons-admin-post',
            'supports' => ['title','editor'],
        ]);
        \CoreBlueprint\Core\ContentModels\Repository::save_post_type($definition);

        cb_b3_expect(\CoreBlueprint\Core\Notes\Repository::create([
            'title' => 'B3 preserved note',
            'content' => 'Preserve this note while Notes is disabled.',
            'content_format' => 'plain',
            'type' => 'Maintenance',
            'status' => 'Important',
            'tags' => 'b3,preservation',
            'assigned_to' => 0,
        ]), 'Could not seed B3 note.');
        global $wpdb;
        $note_id = (int) $wpdb->insert_id;
        cb_b3_expect($note_id > 0, 'B3 note ID missing.');

        $reports = (array) (\CoreBlueprint\Core\Settings::get()['reports'] ?? []);
        $reports['retention_days'] = 61;
        $reports['branding']['provider_name'] = 'B3 Provider';
        $reports['branding']['provider_contact'] = 'b3@example.test';
        \CoreBlueprint\Core\Settings::set_key('reports', $reports, 'b3-seed');
        $report_id = \CoreBlueprint\Core\Reports\Storage::save([
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'generated_by' => (int) $admin->ID,
            'report_data' => [
                'snapshot_version' => \CoreBlueprint\Core\Reports\MaintenanceAggregator::SNAPSHOT_VERSION,
                'site' => ['title' => 'B3'],
            ],
            'status' => 'generated',
        ]);
        cb_b3_expect($report_id > 0, 'Could not seed B3 report archive row.');
        $wpdb->update(\CoreBlueprint\Core\Reports\Storage::table_name(), ['generated_at' => '2025-01-01 00:00:00'], ['id' => $report_id], ['%s'], ['%d']);

        $mail = \CoreBlueprint\Core\Mail\Settings::all();
        $mail['enabled'] = false;
        $mail['provider'] = 'smtp';
        $mail['from_email'] = 'b3@example.test';
        $mail['from_name'] = 'B3 Mail';
        $mail['smtp_host'] = 'smtp.b3.invalid';
        $mail['smtp_port'] = 2525;
        $mail['smtp_encryption'] = 'none';
        $mail['smtp_auth'] = false;
        $mail['retention_days'] = 60;
        \CoreBlueprint\Core\Mail\Settings::save($mail);
        cb_b3_expect('' === \CoreBlueprint\Core\Mail\Settings::activation_error_code(), 'B3 Mail configuration is not runtime-valid.');

        cb_b3_expect(\CoreBlueprint\Core\MediaFormats\Settings::save([
            'svg_uploads' => false,
            'webp_uploads' => true,
            'avif_uploads' => false,
            'jxl_uploads' => true,
            'heic_imports' => false,
            'output_format' => 'original',
        ], 'b3-seed'), 'Could not seed Media Formats configuration.');

        remove_role('cb_b3_role');
        $role = add_role('cb_b3_role', 'B3 Preserved Role', ['read' => true, 'edit_posts' => true]);
        cb_b3_expect($role instanceof WP_Role, 'Could not seed B3 WordPress role.');

        cb_b3_set_module('snippets', false);
        $snippet = \CoreBlueprint\Core\Snippets\Repository::save([
            'title' => 'B3 preserved CSS',
            'description' => 'B3 conformance sentinel',
            'type' => 'css',
            'location' => 'frontend',
            'priority' => 10,
            'enabled' => true,
            'tags' => ['b3'],
            'conditions' => ['relation' => 'and', 'rules' => []],
        ], 'body { --cb-b3-preserved: 1; }');
        cb_b3_expect(is_array($snippet), 'Could not seed B3 Snippets sentinel.');
        $snippet_id = (string) ($snippet['id'] ?? '');
        cb_b3_expect('' !== $snippet_id, 'B3 Snippets sentinel ID missing.');

        cb_b3_set_all_modules(true);
        \CoreBlueprint\Core\Integrity\Scheduler\Cron::sync_schedule();
        cb_b3_assert_all_states(true);

        $settings = \CoreBlueprint\Core\Settings::get();
        $expected = [
            'module_count' => 12,
            'login_config' => cb_b3_without_enabled(\CoreBlueprint\Core\Security\LoginShield::config()),
            'fingerprint_features' => (array) ($settings['modules']['fingerprint']['features'] ?? []),
            'scanner_settings' => cb_b3_without_enabled(\CoreBlueprint\Core\Integrity\Storage\ResultRepository::settings()),
            'content_model' => \CoreBlueprint\Core\ContentModels\Repository::post_type('cb_b3_item'),
            'content_post_id' => 0,
            'note_id' => $note_id,
            'report_id' => $report_id,
            'reports_settings' => cb_b3_without_enabled((array) ($settings['reports'] ?? [])),
            'mail_settings' => cb_b3_without_enabled(\CoreBlueprint\Core\Mail\Settings::all()),
            'media_formats_settings' => cb_b3_without_enabled(\CoreBlueprint\Core\MediaFormats\Settings::all()),
            'snippet_id' => $snippet_id,
            'snippet_code_hash' => (string) ($snippet['code_hash'] ?? ''),
        ];
        update_option('cb_b3_expected', $expected, false);
        cb_b3_assert_preserved_data();
        fwrite(STDOUT, "[B3] seed-enable PASS\n");
        exit(0);
    }

    if ('verify-on-screen' === $stage) {
        cb_b3_assert_all_states(true);
        cb_b3_assert_screen_contract(true);
        cb_b3_assert_preserved_data();
        $expected = get_option('cb_b3_expected', []);
        cb_b3_expect(is_array($expected), 'B3 expected-state document missing.');
        $content_post_id = (int) ($expected['content_post_id'] ?? 0);
        if (0 === $content_post_id) {
            $content_post_id = wp_insert_post([
                'post_type' => 'cb_b3_item',
                'post_status' => 'publish',
                'post_title' => 'B3 preserved content',
                'post_content' => 'Preserve this native WordPress row while Content Models is disabled.',
            ], true);
            cb_b3_expect(!is_wp_error($content_post_id) && (int) $content_post_id > 0, 'Could not seed B3 Content Models post row.');
            $expected['content_post_id'] = (int) $content_post_id;
            update_option('cb_b3_expected', $expected, false);
        }
        fwrite(STDOUT, "[B3] verify-on-screen PASS\n");
        exit(0);
    }

    if ('verify-on-admin-post' === $stage) {
        cb_b3_assert_all_states(true);
        cb_b3_assert_admin_post_contract(true);
        cb_b3_assert_preserved_data();
        fwrite(STDOUT, "[B3] verify-on-admin-post PASS\n");
        exit(0);
    }

    if ('disable' === $stage) {
        cb_b3_assert_all_states(true);
        cb_b3_assert_preserved_data();
        cb_b3_set_all_modules(false);
        cb_b3_assert_all_states(false);
        cb_b3_assert_preserved_data();
        cb_b3_expect(false === wp_next_scheduled(\CoreBlueprint\Core\Integrity\Scheduler\Cron::HOOK), 'Scanner cron survived the OFF transition.');
        fwrite(STDOUT, "[B3] disable PASS\n");
        exit(0);
    }

    if ('verify-off-screen' === $stage) {
        cb_b3_assert_all_states(false);
        cb_b3_assert_screen_contract(false);
        cb_b3_assert_preserved_data();
        cb_b3_assert_disabled_mutation_contracts();
        fwrite(STDOUT, "[B3] verify-off-screen PASS\n");
        exit(0);
    }

    if ('verify-off-admin-post' === $stage) {
        cb_b3_assert_all_states(false);
        cb_b3_assert_admin_post_contract(false);
        cb_b3_assert_preserved_data();
        fwrite(STDOUT, "[B3] verify-off-admin-post PASS\n");
        exit(0);
    }

    if ('reenable' === $stage) {
        cb_b3_assert_all_states(false);
        cb_b3_assert_preserved_data();
        cb_b3_set_all_modules(true);
        cb_b3_assert_all_states(true);
        cb_b3_assert_preserved_data();
        cb_b3_expect(false !== wp_next_scheduled(\CoreBlueprint\Core\Integrity\Scheduler\Cron::HOOK), 'Scanner cron was not restored from preserved daily schedule.');
        fwrite(STDOUT, "[B3] reenable PASS\n");
        exit(0);
    }

    if ('verify-restored-screen' === $stage) {
        cb_b3_assert_all_states(true);
        cb_b3_assert_screen_contract(true);
        cb_b3_assert_preserved_data();
        fwrite(STDOUT, "[B3] verify-restored-screen PASS\n");
        exit(0);
    }

    if ('verify-restored-admin-post' === $stage) {
        cb_b3_assert_all_states(true);
        cb_b3_assert_admin_post_contract(true);
        cb_b3_assert_preserved_data();
        fwrite(STDOUT, "[B3] verify-restored-admin-post PASS\n");
        exit(0);
    }

    if ('cleanup' === $stage) {
        $expected = get_option('cb_b3_expected', []);
        if (is_array($expected) && '' !== (string) ($expected['snippet_id'] ?? '')) {
            \CoreBlueprint\Core\Snippets\Repository::delete((string) $expected['snippet_id']);
        }
        remove_role('cb_b3_role');
        cb_b3_drop_isolated_tables();
        @unlink($mail_guard);
        fwrite(STDOUT, "[B3] cleanup PASS\n");
        exit(0);
    }

    throw new RuntimeException('Unhandled B3 stage: ' . $stage);
} catch (Throwable $e) {
    fwrite(STDERR, "[B3] {$stage} FAIL: {$e->getMessage()}\n");
    exit(1);
}
