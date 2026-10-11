<?php
/**
 * Plugin Name: CB Navigation P01 Field Fixture (Development Only)
 * Description: Read-only, non-production acceptance fixture for the Core Blueprint Base Navigation Foundation.
 * Version: 0.1.0
 * Requires PHP: 8.4
 * Requires at least: 7.0
 * License: GPL-2.0-or-later
 * Text Domain: cb-navigation-p01-field-fixture
 */
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * This plugin is NOT a product extension. It registers only a local/staging
 * administrator-only Tools screen and never writes domain data.
 */
final class CB_Navigation_P01_Field_Fixture {
    private static string $hook = '';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    private static function non_production(): bool {
        return in_array(wp_get_environment_type(), ['local', 'development', 'staging'], true);
    }

    public static function register_page(): void {
        if (! self::non_production() || ! current_user_can('manage_options')) {
            return;
        }

        $hook = add_management_page(
            'Navigation P01 Field Fixture',
            'Navigation P01 Fixture',
            'manage_options',
            'cb-navigation-p01-field',
            [self::class, 'render']
        );
        self::$hook = is_string($hook) ? $hook : '';
    }

    public static function enqueue(string $hook): void {
        if (! self::non_production() || ! current_user_can('manage_options') || $hook !== self::$hook || '' === self::$hook) {
            return;
        }

        if (! class_exists('\\CoreBlueprint\\Core\\Design\\Editor\\Assets')) {
            return;
        }

        \CoreBlueprint\Core\Design\Editor\Assets::enqueue_designer_mode('Navigation P01 Field Fixture');
        wp_enqueue_script_module(
            '@cb-field/navigation-p01',
            plugin_dir_url(__FILE__) . 'navigation-field.js',
            ['@cb-core/design-editor'],
            (string) filemtime(__DIR__ . '/navigation-field.js')
        );
    }

    private static function mode(): string {
        $raw = $_GET['mode'] ?? 'solo';
        $mode = is_string($raw) ? sanitize_key(wp_unslash($raw)) : 'solo';
        return 'mixed' === $mode ? 'mixed' : 'solo';
    }

    public static function render(): void {
        if (! current_user_can('manage_options') || ! self::non_production()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'default'));
        }

        $mode = self::mode();
        $solo_url = add_query_arg(['page' => 'cb-navigation-p01-field', 'mode' => 'solo'], admin_url('tools.php'));
        $mixed_url = add_query_arg(['page' => 'cb-navigation-p01-field', 'mode' => 'mixed'], admin_url('tools.php'));
        ?>
        <div class="wrap">
            <h1>Navigation P01 Field Fixture</h1>
            <p>Development-only, read-only Designer acceptance. No save, automation or domain persistence.</p>
            <p>
                <a class="button <?php echo 'solo' === $mode ? 'button-primary' : ''; ?>" href="<?php echo esc_url($solo_url); ?>">Navigation only</a>
                <a class="button <?php echo 'mixed' === $mode ? 'button-primary' : ''; ?>" href="<?php echo esc_url($mixed_url); ?>">Navigation + Elements</a>
            </p>
            <?php if (! class_exists('\\CoreBlueprint\\Core\\Design\\Editor\\Assets')) : ?>
                <div class="notice notice-error inline"><p>Core Blueprint Base Designer Foundation is not available. Do not assess this fixture without the exact P01 Base candidate.</p></div>
            <?php else : ?>
            <div data-cb-design-launch-root data-cb-p01-field-mode="<?php echo esc_attr($mode); ?>" data-cb-design-title="Navigation P01">
                <div data-cb-design-launch-context>
                    <p><strong>Test scenario:</strong> <?php echo esc_html('solo' === $mode ? 'Navigation only (untabbed palette)' : 'Navigation and Elements (shared tabbed palette)'); ?>. Select “Design with Core Blueprint” to enter the live Base Designer.</p>
                </div>
                <div class="cb-core-design-shell" data-cb-design-shell hidden>
                    <div class="cb-core-design-shell__toolbar">
                        <button type="button" data-cb-design-shell-fullscreen data-cb-design-shell-fullscreen-enter-label="Enter Designer" data-cb-design-shell-fullscreen-exit-label="Close Designer">Enter Designer</button>
                        <span data-cb-design-shell-status role="status" aria-live="polite"></span>
                    </div>
                    <div class="cb-core-design-shell__workspace">
                        <aside class="cb-core-design-shell__palette cb-core-design-shell__palette--composed" aria-label="Stages and elements">
                            <?php if ('mixed' === $mode) : ?>
                                <div class="cb-core-design-shell__tabs" role="tablist" aria-label="Palette roles">
                                    <button type="button" role="tab" id="cb-p01-tab-navigation" data-cb-design-shell-tab="navigation" data-cb-design-shell-group="palette" aria-controls="cb-p01-panel-navigation">Navigation</button>
                                    <button type="button" role="tab" id="cb-p01-tab-elements" data-cb-design-shell-tab="elements" data-cb-design-shell-group="palette" aria-controls="cb-p01-panel-elements">Elements</button>
                                </div>
                                <div id="cb-p01-panel-navigation" class="cb-core-design-shell__panel" role="tabpanel" aria-labelledby="cb-p01-tab-navigation" data-cb-design-shell-panel="navigation" data-cb-design-shell-group="palette" data-cb-design-shell-palette-role="navigation" data-cb-design-shell-palette-label="Workflow">
                                    <div class="cb-core-design-shell__panel-body"><div data-cb-p01-navigation-mount></div></div>
                                </div>
                                <div id="cb-p01-panel-elements" class="cb-core-design-shell__panel" role="tabpanel" aria-labelledby="cb-p01-tab-elements" data-cb-design-shell-panel="elements" data-cb-design-shell-group="palette" data-cb-design-shell-palette-role="elements" hidden>
                                    <div class="cb-core-design-shell__panel-body">
                                        <section class="cb-core-design-shell__panel-section">
                                            <h3 class="cb-core-design-shell__panel-section-title">Sample elements</h3>
                                            <p class="cb-core-design-shell__panel-section-description">Non-persistent sample controls for layout and keyboard testing.</p>
                                            <div class="cb-core-design-shell__palette-grid">
                                                <button class="cb-core-design-shell__palette-item" type="button" data-cb-p01-element="Heading">Heading</button>
                                                <button class="cb-core-design-shell__palette-item" type="button" data-cb-p01-element="Paragraph">Paragraph</button>
                                            </div>
                                        </section>
                                    </div>
                                </div>
                            <?php else : ?>
                                <div class="cb-core-design-shell__panel-body">
                                    <section class="cb-core-design-shell__panel-section">
                                        <h3 class="cb-core-design-shell__panel-section-title">Workflow stages</h3>
                                        <p class="cb-core-design-shell__panel-section-description">Use links to focus stages in the central canvas.</p>
                                        <div data-cb-p01-navigation-mount></div>
                                    </section>
                                </div>
                            <?php endif; ?>
                        </aside>
                        <section class="cb-core-design-shell__canvas cb-core-design-shell__canvas--composed" aria-label="Stage canvas">
                            <header class="cb-core-design-shell__canvas-header">
                                <div class="cb-core-design-shell__canvas-heading">
                                    <h2 class="cb-core-design-shell__canvas-title">Workflow canvas</h2>
                                    <p class="cb-core-design-shell__canvas-description">Test stage navigation, focus and responsive rails.</p>
                                </div>
                            </header>
                            <div class="cb-core-design-shell__canvas-workarea">
                                <div class="cb-core-design-shell__surface cb-core-design-shell__composition-stack">
                                    <section id="cb-p01-stage-when" aria-label="When stage">
                                        <h3>When</h3>
                                        <p>A sample trigger. Try the Navigation links on the left.</p>
                                    </section>
                                    <section id="cb-p01-stage-if" aria-label="Only if stage">
                                        <h3>Only if</h3>
                                        <p>A sample condition for focus testing.</p>
                                    </section>
                                    <section id="cb-p01-stage-then" aria-label="Then stage">
                                        <h3>Then</h3>
                                        <p>A sample action destination for navigation.</p>
                                    </section>
                                </div>
                            </div>
                        </section>
                        <aside class="cb-core-design-shell__sidebar cb-core-design-shell__sidebar--composed" aria-label="Inspector">
                            <div class="cb-core-design-shell__panel-body">
                                <section class="cb-core-design-shell__panel-section">
                                    <h3 class="cb-core-design-shell__panel-section-title">Inspector</h3>
                                    <p class="cb-core-design-shell__panel-section-description">Fixture-only, read-only content.</p>
                                    <label class="cb-core-design-shell__field">
                                        <span class="cb-core-design-shell__field-label">Label</span>
                                        <input type="text" value="Sample item" readonly>
                                    </label>
                                </section>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

CB_Navigation_P01_Field_Fixture::boot();
