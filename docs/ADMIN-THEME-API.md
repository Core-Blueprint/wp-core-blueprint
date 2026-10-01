# Admin Theme API

Core Blueprint Base owns one theme state for the complete WordPress admin. The built-in themes are **Core Blueprint Light** and **Core Blueprint Dark**. There is no separate Core Admin presentation mode.

The theme engine has three integration layers:

1. **Semantic CSS tokens** for normal UI styling.
2. **PHP API and hooks** for WordPress admin integrations.
3. **Browser API and events** for interfaces that must redraw non-CSS surfaces after a live theme switch.

## Presentation policy

WordPress Core is light-first. Base therefore treats its WordPress presentation adapter as **Dark-only**:

- **Dark:** Base adapts WordPress-native admin chrome and Core surfaces to Core Blueprint semantic tokens.
- **Light:** WordPress Core stays as close to native WordPress presentation as possible.
- **Core Blueprint components:** always use semantic tokens and therefore remain intentionally compatible with both Light and Dark.
- **Third-party custom applications:** keep ownership of their bespoke presentation unless they use WordPress-native primitives, consume the public token contract, or have a deliberately curated compatibility bridge.

This avoids maintaining a duplicate Light skin for an interface that is already light by default and reduces the risk of unnecessary WordPress Core overrides.

## CSS is the primary contract

Do not detect a built-in theme slug in extension CSS. Use semantic tokens instead:

```css
.my-extension-card {
    background: var(--cb-surface-1);
    color: var(--cb-text);
    border: 1px solid var(--cb-border);
}

.my-extension-card:focus-within {
    box-shadow: 0 0 0 2px var(--cb-interactive-focus);
}
```

This keeps extensions compatible with Light, Dark, and partner themes registered through `cb_admin_themes`.

## PHP API

```php
use CB\Core\UI\AdminTheme;

$theme = AdminTheme::theme();
$mode  = AdminTheme::mode();
```

`AdminTheme::mode()` is the server-resolved initial mode. When the preference is Auto, the browser can resolve the final built-in Light/Dark mode from `prefers-color-scheme` before paint.

### Declare a compatible screen

A third-party or sibling extension may explicitly declare a screen as compatible after WordPress returns its hook suffix:

```php
$hook = add_menu_page(/* ... */);
AdminTheme::register_screen($hook);
```

Registration is **not** required to receive Light/Dark theme state. The theme engine is global in `wp-admin`. Registration is a compatibility declaration and adds the `cb-admin-theme-compatible` body class on that screen.

Native WordPress screens that need Core Blueprint shared UI may declare semantic requirements at the same boundary:

```php
use CB\Core\UI\AdminTheme;

add_action('current_screen', function ($screen): void {
    if (!$screen instanceof WP_Screen || 'my_post_type' !== $screen->post_type) {
        return;
    }

    AdminTheme::register_screen(
        $GLOBALS['hook_suffix'] ?? '',
        [
            'components' => [
                'buttons',
                'fields',
                'form-controls',
            ],
        ]
    );
});
```

This uses the same public requirement vocabulary as `PageRegistry` and `SettingsRegistry`. Extensions must not enqueue Base private handles or depend on Base CSS filenames. Screen requirements should be registered during `current_screen`, or otherwise before `admin_enqueue_scripts` priority 5.

## Hooks

### Register partner themes

The existing `cb_admin_themes` filter remains the canonical registry. Partner themes must provide the same semantic color-token contract as Base.

### Safety valve for self-contained admin applications

The theme state applies to all normal `wp-admin` screens by default. A developer that owns a self-contained application and knows the Base WordPress presentation adapter is incompatible may opt that screen out:

```php
add_filter('cb_admin_theme_apply', function (bool $apply, $screen): bool {
    if ($screen && 'my_app_page' === $screen->id) {
        return false;
    }

    return $apply;
}, 10, 2);
```

This is a developer compatibility boundary, not a user-facing presentation mode.

### Theme-aware enqueue hook

```php
add_action(
    'cb_admin_theme_enqueue',
    function (string $hookSuffix, string $theme, string $mode, bool $registered): void {
        // Enqueue extension-owned theme-aware assets when needed.
    },
    10,
    4
);
```

Additional hooks:

- `cb_admin_theme_screen_registered`
- `cb_admin_theme_body_classes`

## Internal adapter layers

Base keeps its own presentation adapters modular:

```text
assets/css/admin-theme/
├── core-screens.css
├── core/
│   ├── dashboard.css
│   └── plugins.css
├── compat/
│   └── dashboard-widgets.css
├── gutenberg.css
├── gutenberg-canvas.css
└── integrations/
    ├── bricks.css
    └── happyfiles.css
```

The ownership boundaries are deliberate:

- `core/*` contains selectors owned by WordPress Core.
- `compat/*` contains tightly scoped normalization for ordinary WordPress-native primitives used by third-party widgets. It must not become a generic wildcard skin.
- `integrations/*` contains only explicitly curated third-party bridges.
- Gutenberg UI and editor content are separate because the editor content runs in an iframe.

## Curated third-party bridges

Base does **not** maintain a skin for every WordPress plugin. A curated bridge is only appropriate when it is intentionally supported, small, and primarily maps the third party's own presentation variables to Core Blueprint semantic tokens.

The current curated bridges are:

- **HappyFiles:** maps confirmed `--hf-*` presentation variables and corrects confirmed hardcoded light sidebar/search states.
- **Bricks:** maps confirmed Bricks admin variables such as `--admin-color-border`, `--bricks-bg-light`, and `--bricks-text-light`, plus a small set of confirmed hardcoded light admin surfaces. Bricks keeps ownership of layout and brand accents.

Plugin developers should normally ship their own compatibility layer through the public token and enqueue contracts instead of asking Base to own their UI.

## Gutenberg

WordPress 7.1 always uses an iframe for the Post Editor. Base therefore respects the official WordPress separation:

- `enqueue_block_editor_assets` is used for Gutenberg application chrome.
- `enqueue_block_assets` is used for editor-content assets that WordPress places inside the iframe.

The iframe receives a **low-specificity dark fallback** only while the resolved admin mode is Dark. The fallback uses `:where()` so explicit `theme.json`, theme editor styles, block classes, and inline design choices remain authoritative.

The browser runtime mirrors `data-cb-theme` and `data-cb-mode` into the same-origin Gutenberg iframe so HUD Light/Dark changes can update the editor without a reload.

## Browser API

For charts, canvas renderers, code editors, or similar JavaScript interfaces:

```js
window.cbAdminTheme.theme();
window.cbAdminTheme.mode();
window.cbAdminTheme.state();
```

Listen for a live HUD theme change:

```js
document.addEventListener('cb:admin-theme-change', (event) => {
    const { theme, mode } = event.detail;
    // Redraw non-CSS UI only when necessary.
});
```

The runtime also emits `cb:admin-theme-ready` after the browser API becomes available. Base automatically enables or disables its WordPress Core and curated compatibility adapter stylesheets when the resolved mode changes, so a HUD switch does not require a page reload.

## Ownership rule

Base owns:

- theme state and persistence;
- HUD Light/Dark switching;
- semantic tokens;
- WordPress Core Dark adaptation;
- shared Foundation component presentation;
- public theme integration contracts;
- tightly scoped WordPress-native compatibility normalization;
- a deliberately small set of curated compatibility bridges;
- the Gutenberg admin/editor fallback boundary.

Extensions own their domain composition and must not implement their own theme state, theme toggle, or duplicated shared presentation layer.
