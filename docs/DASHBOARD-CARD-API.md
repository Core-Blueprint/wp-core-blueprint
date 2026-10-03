# Dashboard Card API

The Dashboard Card API lets Core Blueprint modules and sibling extensions add
navigation shortcuts to their dashboard card without injecting dashboard HTML,
CSS, JavaScript, or activation callbacks.

Base owns the card layout, compact status menu, optional Base-module activation,
sanitization, and capability checks. Extensions own only their destinations.

Registered shortcuts are rendered inside the card's top-right status menu after
the Base-owned actions such as `Visit module`. Extensions never need to know the
Dashboard markup or popover implementation.

## Stable card IDs

Base module cards use their `ActivationRegistry` slug:

- `content-models`
- `snippets`
- `user-roles`
- `media-replace`
- `mail`
- `package-downloads`
- `notes`
- `reports`

Extension cards use the canonical extension `id` registered through `CoreBlueprint\Core\ExtensionRegistry`, for example:

- `core-blueprint-lms`
- `core-blueprint-certificates`
- `acme-security`

Do not target cards by translated title, plugin basename, or dashboard markup. The extension `id` is the single platform identity and is therefore also the stable Dashboard Card ID. `plugin_file` is only a WordPress inventory locator and never a second card/extension identity.

## Register shortcuts

Register from the public `cb_core_dashboard_register_cards` hook so plugin load
order does not matter:

```php
use CoreBlueprint\Core\Dashboard\CardRegistry;

add_action( 'cb_core_dashboard_register_cards', static function (): void {
    CardRegistry::register_shortcuts( 'core-blueprint-lms', [
        [
            'id'         => 'courses',
            'label'      => __( 'Courses', 'core-blueprint-lms' ),
            'url'        => admin_url( 'edit.php?post_type=cb_lms_course' ),
            'capability' => 'edit_posts',
            'order'      => 10,
        ],
        [
            'id'         => 'assessments',
            'label'      => __( 'Assessments', 'core-blueprint-lms' ),
            'url'        => admin_url( 'edit.php?post_type=cb_lms_assessment' ),
            'capability' => 'edit_posts',
            'order'      => 20,
        ],
        [
            'id'         => 'settings',
            'label'      => __( 'Settings', 'core-blueprint-lms' ),
            'url'        => admin_url( 'admin.php?page=core-blueprint-lms-settings' ),
            'capability' => 'manage_options',
            'order'      => 90,
        ],
    ] );
} );
```

A shortcut supports:

- `id` — required stable identifier.
- `label` — required user-facing label.
- `url` — required destination.
- `capability` — optional WordPress capability. The shortcut is omitted when
  the current user does not have it.
- `order` — optional integer, default `100`.
- `target` — optional `_self` (default) or `_blank`.

Registering the same shortcut `id` again on the same card replaces the previous
entry instead of producing a duplicate.

## Dynamic filters

For request-specific shortcuts use either filter:

```php
cb_core_dashboard_card_shortcuts
cb_core_dashboard_card_shortcuts_{card-id}
```

The global filter receives `($shortcuts, $card_id, $context)`. The card-specific
filter receives `($shortcuts, $context)`.

Context is read-only metadata supplied by Base. Current card types include:

- `module`
- `operation`
- `extension`

Extensions should not depend on undocumented context keys for persistent data.

## Security and ownership

The API accepts navigation shortcuts only. It deliberately does **not** accept:

- raw HTML;
- arbitrary JavaScript;
- activation/deactivation callbacks;
- executable dashboard actions.

Base module activation remains owned by `CoreBlueprint\Core\Modules\ActivationRegistry` and
the Core Blueprint AJAX security boundary. The Dashboard only renders a Turn
on/off action when the current user has the capability declared by that registry.
Sibling WordPress plugins/extensions are never activated or deactivated through
this API; WordPress remains the owner of plugin activation.

URLs are sanitized before rendering and shortcut capabilities are checked by
Base. The final renderer must escape labels and URLs as normal.
