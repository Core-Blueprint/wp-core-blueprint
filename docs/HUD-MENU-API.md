# HUD Registry API

Core Blueprint Base owns HUD placement, rendering, sanitization, lifecycle, styling, capability enforcement and site-wide presentation preferences. Extensions contribute declarative section types, sections and items through the documented registry hooks. Arbitrary renderer callbacks, injected HUD markup and private Base CSS/JavaScript are not part of the public v1 contract.

## Lifecycle

HUD registration runs on `init` in three phases:

1. `core_blueprint_hud_register_section_types`
2. `core_blueprint_hud_register_sections`
3. `core_blueprint_hud_register_items`

Register translated presentation metadata during these hooks or another WordPress lifecycle point where translations are available.

## Canonical built-in section types

Base owns three protected built-in types:

- `navigation` — body navigation made from `link` items;
- `quick-actions` — body action group made from `link` items;
- `status` — compact header status strip made from `stat` items.

The built-in type IDs cannot be replaced by extensions.

Base registers these canonical section instances:

- `quick-actions` → type `quick-actions`;
- `cb-content` → type `navigation`;
- `cb-site` → type `navigation`;
- `cb-core` → type `navigation`;
- `status` → type `status`.

Prefer an existing section unless a separate group is genuinely useful.

## Register a custom section type

Custom types use a namespaced lower-case ID such as `vendor/metrics`. They are deliberately restricted to Base-owned presentation primitives. Extensions do not provide arbitrary PHP render callbacks or raw HUD markup.

Hook `core_blueprint_hud_register_section_types`. The callback receives the `CoreBlueprint\Core\HUD\SectionTypeRegistry` class name.

```php
add_action( 'core_blueprint_hud_register_section_types', static function ( string $types ): void {
    $types::register( [
        'id'                  => 'vendor/metrics',
        'presentation'        => 'metrics',
        'item_types'          => [ 'stat' ],
        'capability'          => 'manage_options',
        'manageable'          => true,
        'default_columns'     => 1,
        'default_collapsible' => true,
        'max_items'           => 6,
    ] );
} );
```

Public v1 custom presentations are:

- `list` → `link` items;
- `metrics` → `stat` items.

Custom types are body-placement types. `capability` is required. `default_columns` is `1` or `2`; `max_items` is `0` for unlimited or a positive limit.

Malformed, duplicate or Base-reserved type registrations are rejected safely.

## Register a section

Hook `core_blueprint_hud_register_sections`. The callback receives the `CoreBlueprint\Core\HUD\Registry` class name. Section IDs use strict lower-case kebab-case and must be unique.

```php
add_action( 'core_blueprint_hud_register_sections', static function ( string $registry ): void {
    $registry::register_section( [
        'id'                => 'vendor-fleet',
        'label'             => __( 'Fleet', 'vendor-plugin' ),
        'type'              => 'vendor/metrics',
        'capability'        => 'manage_options',
        'order'             => 40,
        'collapsible'       => true,
        'collapsed_default' => false,
        'columns'           => 1,
    ] );
} );
```

The referenced type must already exist. Unknown types, malformed IDs, duplicate sections, invalid capabilities and unsupported column counts are rejected rather than silently normalized or overwritten.

`manageable` may be set to `false` to keep a body section outside the site-wide HUD menu ordering/visibility editor. A section cannot make itself manageable when its section type forbids it.

## Register an item

Hook `core_blueprint_hud_register_items`. The callback receives the `CoreBlueprint\Core\HUD\Registry` class name.

```php
add_action( 'core_blueprint_hud_register_items', static function ( string $registry ): void {
    $registry::add_item( [
        'id'         => 'core-blueprint-example-overview',
        'label'      => __( 'Example', 'core-blueprint-example' ),
        'type'       => 'link',
        'section'    => 'cb-core',
        'url'        => admin_url( 'admin.php?page=core-blueprint-example' ),
        'capability' => 'manage_options',
        'order'      => 40,
        'icon'       => 'admin-generic',
        'module'     => 'example-module',
        'status'     => 'example-module',
    ] );
} );
```

Stable item IDs are required. Preferences stores overrides against those IDs, so changing an ID discards the user's ordering/visibility preference for that item.

Canonical item types are:

- `link` — navigational/action row;
- `stat` — read-only label/value metric.

The target section type decides which item type is accepted. Items targeting an unknown section, using an unsupported item type or reusing an existing item ID are rejected.

`module` and `status` are canonical kebab-case IDs, not WordPress filter names. When `module` is present, Base asks the canonical activation registry whether the module is enabled; unknown or failing module state hides the item. When `status` is present, Base asks the canonical status registry for health information. An unknown status ID simply produces no status indicator.

Capability and module gates remain fail-closed. Registration/provider failures must not break HUD rendering.

## Preferences contract

Preferences → Floating Menu stores only:

- manageable section order;
- hidden manageable section IDs;
- item order per section;
- hidden item IDs;
- administrator-defined custom links.

The registry remains the availability/source-of-truth layer. Runtime capability checks, module availability, type constraints, explicit `visible=false` and extension registration decisions always win over Preferences. A saved preference can never grant access to a section or item the current request is not permitted to use.

New registry items absent from a saved order are appended using their declarative registry order, so updates/extensions can add shortcuts without migrating stored menu configuration.

## Not part of the v1 contract

The following private/pre-v1 HUD surfaces are not supported public contracts:

- old `integrations`, `wordpress` or `display` built-in sections;
- old `note` item shape;
- arbitrary `layout` strings;
- render-time item/section mutation filters such as `cb_hud_item_args`, `cb_hud_item_{id}_args` and `cb_hud_section_visible_{id}`;
- `cb_core_hud_menu_section_manageable`;
- arbitrary renderer callbacks or raw HUD markup injection.
