<?php
declare(strict_types=1);

function cb_b3_env(string $name, string $default = ''): string {
    $value = getenv($name);
    return false === $value ? $default : (string) $value;
}

function cb_b3_expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cb_b3_db_connection(): mysqli {
    $host = cb_b3_env('WP_DB_HOST', '127.0.0.1:3306');
    $port = 3306;
    if (preg_match('/^([^:]+):([0-9]+)$/D', $host, $matches)) {
        $host = $matches[1];
        $port = (int) $matches[2];
    }
    $mysqli = new mysqli($host, cb_b3_env('WP_DB_USER', 'root'), cb_b3_env('WP_DB_PASSWORD', ''), cb_b3_env('WP_DB_NAME', 'wordpress_test'), $port);
    if ($mysqli->connect_errno) {
        throw new RuntimeException('Could not connect to B3 database: ' . $mysqli->connect_error);
    }
    return $mysqli;
}

function cb_b3_drop_isolated_tables(): void {
    $prefix = cb_b3_env('CB_B3_TABLE_PREFIX', 'cbb3_');
    cb_b3_expect(1 === preg_match('/^[A-Za-z0-9_]+$/D', $prefix), 'Unsafe B3 table prefix.');
    $mysqli = cb_b3_db_connection();
    $result = $mysqli->query('SHOW TABLES');
    if (false === $result) {
        throw new RuntimeException('Could not enumerate B3 database tables.');
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
            throw new RuntimeException('Could not drop B3 table: ' . $table);
        }
    }
    $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
    $mysqli->close();
}

/** @return array<string,array{state:class-string<\CoreBlueprint\Core\Modules\ModuleStateInterface>,capability:string}> */
function cb_b3_modules(): array {
    $modules = \CoreBlueprint\Core\Modules\ActivationRegistry::definitions();
    $expected = ['login-shield','core-shield','core-scanner','content-models','notes','reports','mail','media-replace','media-formats','package-downloads','user-roles','snippets'];
    cb_b3_expect($expected === array_keys($modules), 'ActivationRegistry no longer exposes the exact canonical 12-module order.');
    return $modules;
}

function cb_b3_set_module(string $id, bool $enabled): void {
    $module = cb_b3_modules()[$id] ?? null;
    cb_b3_expect(is_array($module), 'Unknown B3 module: ' . $id);
    $state_class = (string) ($module['state'] ?? '');
    cb_b3_expect(class_exists($state_class), 'Missing state adapter for ' . $id);
    $state_class::set_enabled($enabled, 'b3-request');
    cb_b3_expect($state_class::is_enabled() === $enabled, 'State transition did not persist for ' . $id);
}

function cb_b3_set_all_modules(bool $enabled): void {
    $order = $enabled
        ? ['core-shield','login-shield','core-scanner','content-models','notes','reports','mail','media-replace','media-formats','package-downloads','user-roles','snippets']
        : ['login-shield','core-shield','core-scanner','content-models','notes','reports','mail','media-replace','media-formats','package-downloads','user-roles','snippets'];
    foreach ($order as $id) {
        cb_b3_set_module($id, $enabled);
    }
}

function cb_b3_assert_all_states(bool $enabled): void {
    foreach (cb_b3_modules() as $id => $module) {
        $state_class = (string) ($module['state'] ?? '');
        cb_b3_expect($state_class::is_enabled() === $enabled, sprintf('%s state mismatch; expected %s.', $id, $enabled ? 'ON' : 'OFF'));
    }
}

/** @return string[] */
function cb_b3_registered_pages(): array {
    \CoreBlueprint\Core\Admin\PageRegistry::_reset_for_testing();
    do_action('core_blueprint_register_pages');
    return array_map(static fn($page): string => (string) $page->slug(), \CoreBlueprint\Core\Admin\PageRegistry::all());
}

function cb_b3_assert_screen_contract(bool $enabled): void {
    $pages = cb_b3_registered_pages();
    $module_pages = [
        'content-models' => \CoreBlueprint\Core\ContentModels\Admin\Page::SLUG,
        'notes' => (new \CoreBlueprint\Core\Notes\Admin\Page())->slug(),
        'reports' => \CoreBlueprint\Core\Admin\Pages\Reports::SLUG,
        'mail' => \CoreBlueprint\Core\Mail\Admin\Page::SLUG,
        'media-replace' => \CoreBlueprint\Core\MediaReplace\Admin\Page::SLUG,
        'media-formats' => \CoreBlueprint\Core\MediaFormats\Admin\Page::SLUG,
        'package-downloads' => \CoreBlueprint\Core\PackageDownload\Admin\Page::SLUG,
        'user-roles' => \CoreBlueprint\Core\Permissions\Admin\RolesPage::SLUG,
        'snippets' => \CoreBlueprint\Core\Snippets\Admin\Page::SLUG,
    ];
    foreach ($module_pages as $id => $slug) {
        $present = in_array($slug, $pages, true);
        if ('mail' === $id) {
            cb_b3_expect($present, 'Mail configuration surface must remain registered while its capabilities are OFF.');
            continue;
        }
        cb_b3_expect($present === $enabled, sprintf('%s page registration mismatch while module is %s.', $id, $enabled ? 'ON' : 'OFF'));
    }
    $login_hook = false !== has_filter('login_url', [\CoreBlueprint\Core\Security\LoginShield::class, 'filter_login_url']);
    cb_b3_expect($login_hook === $enabled, 'Login Shield runtime hook mismatch.');
    $fingerprint = \CoreBlueprint\Core\Security\ModuleRegistry::get('fingerprint');
    cb_b3_expect(null !== $fingerprint, 'Fingerprint module missing from Core Shield registry.');
    $shield_hook = false !== has_filter('style_loader_src', [$fingerprint, 'filter_strip_wp_version_query']);
    cb_b3_expect($shield_hook === $enabled, 'Core Shield runtime hook mismatch.');
    $scanner_scheduled = false !== wp_next_scheduled(\CoreBlueprint\Core\Integrity\Scheduler\Cron::HOOK);
    cb_b3_expect($scanner_scheduled === $enabled, 'Core Scanner cron schedule mismatch.');
    cb_b3_expect(post_type_exists('cb_b3_item') === $enabled, 'Content Models runtime post type mismatch.');
    cb_b3_expect(\CoreBlueprint\Core\Mail\Runtime::is_active() === $enabled, 'Mail runtime ownership mismatch.');
    $media_formats_hook = false !== has_filter('upload_mimes', [\CoreBlueprint\Core\MediaFormats\Runtime::class, 'filter_upload_mimes']);
    cb_b3_expect($media_formats_hook === $enabled, 'Media Formats runtime hook mismatch.');
    $media_replace_hook = false !== has_filter('media_row_actions', [\CoreBlueprint\Core\MediaReplace\AdminIntegration::class, 'media_row_action']);
    cb_b3_expect($media_replace_hook === $enabled, 'Media Replace admin-screen hook mismatch.');
    $package_hook = false !== has_filter('plugin_action_links', [\CoreBlueprint\Core\PackageDownload\AdminIntegration::class, 'plugin_action_link']);
    cb_b3_expect($package_hook === $enabled, 'Package Downloads admin-screen hook mismatch.');
    $roles_hook = false !== has_action('rest_api_init', [\CoreBlueprint\Core\Permissions\Rest\RolesController::class, 'register']);
    cb_b3_expect($roles_hook === $enabled, 'User Roles REST registration mismatch.');
    $expected = get_option('cb_b3_expected', []);
    $snippet_id = is_array($expected) ? (string) ($expected['snippet_id'] ?? '') : '';
    cb_b3_expect('' !== $snippet_id, 'B3 Snippets sentinel is missing.');
    $manifest = \CoreBlueprint\Core\Snippets\IndexBuilder::load_runtime_manifest();
    cb_b3_expect(isset($manifest[$snippet_id]) === $enabled, 'Snippets runtime manifest mismatch.');
}

function cb_b3_assert_admin_post_contract(bool $enabled): void {
    $conditional = [
        'Media Replace' => ['admin_post_cb_core_replace_media', [\CoreBlueprint\Core\MediaReplace\AdminIntegration::class, 'handle_replace']],
        'Package Downloads' => ['admin_post_cb_core_download_package', [\CoreBlueprint\Core\PackageDownload\AdminIntegration::class, 'handle_download']],
    ];
    foreach ($conditional as $label => [$hook, $callback]) {
        $registered = false !== has_action($hook, $callback);
        cb_b3_expect($registered === $enabled, $label . ' admin-post registration mismatch.');
    }
    $always_registered = [
        'Mail save' => ['admin_post_cb_core_mail_save', [\CoreBlueprint\Core\Mail\Admin\Actions::class, 'save']],
        'Mail clear log' => ['admin_post_cb_core_mail_clear_log', [\CoreBlueprint\Core\Mail\Admin\Actions::class, 'clear_log']],
        'Media Formats save' => ['admin_post_cb_core_media_formats_save', [\CoreBlueprint\Core\MediaFormats\Admin\Actions::class, 'save']],
        'Snippets save' => ['admin_post_cb_core_snippets_save', [\CoreBlueprint\Core\Snippets\Admin\Actions::class, 'save']],
        'Snippets export' => ['admin_post_cb_core_snippets_export', [\CoreBlueprint\Core\Snippets\Admin\Actions::class, 'export']],
        'Content Models save' => ['admin_post_cb_core_content_models_save_post_type', [\CoreBlueprint\Core\ContentModels\Admin\Actions::class, 'save_post_type']],
    ];
    foreach ($always_registered as $label => [$hook, $callback]) {
        cb_b3_expect(false !== has_action($hook, $callback), $label . ' recovery/fail-closed handler disappeared.');
    }
}

/** @param array<string,mixed> $value @return array<string,mixed> */
function cb_b3_without_enabled(array $value): array {
    unset($value['enabled'], $value['delivery_enabled'], $value['designer_enabled']);
    return $value;
}

function cb_b3_assert_preserved_data(): void {
    $expected = get_option('cb_b3_expected', []);
    cb_b3_expect(is_array($expected) && 12 === (int) ($expected['module_count'] ?? 0), 'B3 expected-state document is missing or invalid.');
    cb_b3_expect(cb_b3_without_enabled(\CoreBlueprint\Core\Security\LoginShield::config()) === (array) ($expected['login_config'] ?? []), 'Login Shield configuration changed across state transitions.');
    $settings = \CoreBlueprint\Core\Settings::get();
    $fingerprint_features = (array) ($settings['modules']['fingerprint']['features'] ?? []);
    cb_b3_expect($fingerprint_features === (array) ($expected['fingerprint_features'] ?? []), 'Core Shield feature configuration changed.');
    cb_b3_expect(cb_b3_without_enabled(\CoreBlueprint\Core\Integrity\Storage\ResultRepository::settings()) === (array) ($expected['scanner_settings'] ?? []), 'Core Scanner settings changed across state transitions.');
    cb_b3_expect(\CoreBlueprint\Core\ContentModels\Repository::post_type('cb_b3_item') === ($expected['content_model'] ?? null), 'Content Models definition was not preserved.');
    $content_post_id = (int) ($expected['content_post_id'] ?? 0);
    if ($content_post_id > 0) {
        $post = get_post($content_post_id);
        cb_b3_expect($post instanceof WP_Post && 'cb_b3_item' === $post->post_type && 'B3 preserved content' === $post->post_title, 'Content Models WordPress content row was not preserved.');
    }
    $note_id = (int) ($expected['note_id'] ?? 0);
    $note = \CoreBlueprint\Core\Notes\Repository::find($note_id);
    cb_b3_expect(is_object($note) && 'B3 preserved note' === (string) $note->title, 'Notes row was not preserved.');
    $report_id = (int) ($expected['report_id'] ?? 0);
    cb_b3_expect(null !== \CoreBlueprint\Core\Reports\Storage::find($report_id), 'Reports archive row was not preserved.');
    $reports = (array) ($settings['reports'] ?? []);
    cb_b3_expect(cb_b3_without_enabled($reports) === (array) ($expected['reports_settings'] ?? []), 'Reports configuration changed across state transitions.');
    cb_b3_expect(cb_b3_without_enabled(\CoreBlueprint\Core\Mail\Settings::all()) === (array) ($expected['mail_settings'] ?? []), 'Mail transport configuration changed across state transitions.');
    cb_b3_expect(cb_b3_without_enabled(\CoreBlueprint\Core\MediaFormats\Settings::all()) === (array) ($expected['media_formats_settings'] ?? []), 'Media Formats configuration changed across state transitions.');
    $role = get_role('cb_b3_role');
    cb_b3_expect($role instanceof WP_Role, 'User Roles sentinel role disappeared.');
    cb_b3_expect(!empty($role->capabilities['read']) && !empty($role->capabilities['edit_posts']), 'User Roles sentinel capabilities changed.');
    $snippet_id = (string) ($expected['snippet_id'] ?? '');
    $snippet = \CoreBlueprint\Core\Snippets\Repository::get($snippet_id);
    cb_b3_expect(is_array($snippet), 'Snippets metadata disappeared.');
    cb_b3_expect((string) ($snippet['code_hash'] ?? '') === (string) ($expected['snippet_code_hash'] ?? ''), 'Snippets code hash changed across state transitions.');
    cb_b3_expect(\CoreBlueprint\Core\Snippets\Repository::code($snippet_id) === 'body { --cb-b3-preserved: 1; }', 'Snippets code file changed across state transitions.');
}

function cb_b3_assert_disabled_mutation_contracts(): void {
    $note_request = new WP_REST_Request('POST', '/core-blueprint/v1/notes/action');
    $note_response = \CoreBlueprint\Core\Notes\Rest\NotesController::action($note_request);
    cb_b3_expect(403 === $note_response->get_status(), 'Disabled Notes mutation did not fail closed.');
    $note_data = $note_response->get_data();
    cb_b3_expect(is_array($note_data) && 'cb_notes_subsystem_disabled' === ($note_data['code'] ?? ''), 'Disabled Notes returned the wrong refusal code.');
    $scan = (new \CoreBlueprint\Core\Integrity\Rest\ScanController())->scan(new WP_REST_Request('POST', '/core-blueprint/v1/integrity/admin/scan'));
    cb_b3_expect($scan instanceof WP_Error && 'cb_integrity_subsystem_disabled' === $scan->get_error_code(), 'Disabled Scanner did not refuse scan creation.');
    $expected = get_option('cb_b3_expected', []);
    $report_id = (int) ($expected['report_id'] ?? 0);
    cb_b3_expect(0 === \CoreBlueprint\Core\Reports\Storage::cleanup_expired_registered(7), 'Disabled Reports retention pruner performed work.');
    cb_b3_expect(null !== \CoreBlueprint\Core\Reports\Storage::find($report_id), 'Disabled Reports retention removed archive data.');
}
