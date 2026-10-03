# Settings Hub Foundation — public v1 contract

Core Blueprint Base owns the canonical configuration directory for Core Blueprint extensions at **Core Blueprint → Extensions**.

The Settings Hub solves one information-architecture problem: extension configuration must remain easy to find without turning the Core Blueprint WordPress submenu into a flat directory of every installed extension. Its user-facing menu and page label is **Extensions**, which keeps it distinct from Base's separate **Preferences** surface.

This is a configuration surface only. Operational work remains in each extension's own appropriate WordPress admin workspace.

## Public registration boundary

Extensions register settings providers during `core_blueprint_register_settings` through:

```php
use CoreBlueprint\Core\Admin\SettingsRegistry;

add_action( 'core_blueprint_register_settings', static function (): void {
    SettingsRegistry::register(
        'vendor-example',
        [
            'label'       => __( 'Example', 'vendor-example' ),
            'description' => __( 'Configure Example behaviour and integrations.', 'vendor-example' ),
            'group'       => SettingsRegistry::GROUP_OTHER,
            'capability'  => 'manage_options',
            'renderer'    => [ Vendor\Example\Admin\Settings::class, 'render' ],
            'icon'        => 'settings',
            'support_url' => 'https://example.com/support',
            'requirements' => [
                'foundations' => [ 'toast' ],
                'components'  => [ 'cards', 'panels', 'form-controls' ],
            ],
        ]
    );
} );
```

The first argument is the extension's existing `ExtensionRegistry` ID. A settings provider is accepted only when that extension identity is already valid and resolvable through `CoreBlueprint\Core\ExtensionRegistry`.

Registration outside `core_blueprint_register_settings` is rejected. Duplicate providers are rejected rather than overwritten.

## Provider fields

Accepted provider metadata is deliberately narrow:

- `label` — required caller-localized display name;
- `description` — required caller-localized short explanation;
- `group` — required Settings Hub group constant;
- `capability` — required WordPress capability;
- `renderer` — required callable that outputs the extension-owned settings body;
- `icon` — optional existing Core Blueprint Icon Foundation key;
- `support_url` — optional developer support URL using `http` or `https`;
- `requirements` — optional semantic `foundations` and `components`, using the same public vocabulary as `PageRegistry`.

Unknown keys fail closed. In particular, providers cannot submit `first_party`, `official`, `developer_name` or `developer_url` metadata.

## Settings groups

The v1 group vocabulary is:

- `SettingsRegistry::GROUP_INFRASTRUCTURE` — infrastructure and site operations;
- `SettingsRegistry::GROUP_CONTENT_PUBLISHING` — content, publishing and learning systems;
- `SettingsRegistry::GROUP_COMMUNITY` — community and member-facing systems;
- `SettingsRegistry::GROUP_BUSINESS` — business workflows and service operations;
- `SettingsRegistry::GROUP_COMMERCE` — commerce and billing systems;
- `SettingsRegistry::GROUP_OTHER` — integrations that do not truthfully fit another group.

Groups organize settings only. They do not determine activation, permissions, product ownership or dependency semantics.

## Developer identity and provenance

**The developer is always shown.** This applies to both official Core Blueprint extensions and third-party extensions, on both the Settings Hub index and the individual settings surface.

Developer identity is not provider-supplied. Base resolves the developer name and developer URL from the registered extension's WordPress plugin metadata through `ExtensionRegistry::identity()`.

First-party provenance is also Base-owned. A provider cannot promote itself to official status.

Base recognizes an extension as first-party only through the existing reserved `ExtensionRegistry` identity invariant:

1. the extension ID is in the reserved `core-blueprint-*` namespace;
2. the plugin folder exactly matches that ID; and
3. the WordPress plugin `Author` header is exactly `Core Blueprint`.

Everything else is presented as a **Third-party extension**. To avoid contradictory support attribution, the exact developer name `Core Blueprint` is reserved on the Settings Hub: a provider that is not recognized as first-party cannot register with that developer identity.

This is a product/provenance indicator, not cryptographic verification or a security trust claim.

### Support boundary

Official settings surfaces show that the extension is developed and supported by Core Blueprint.

Third-party settings surfaces explicitly show:

- `Third-party extension`;
- `Developer: {developer}`;
- that the extension is developed and supported by that developer, **not by Core Blueprint**;
- a `Developer support` link when `support_url` is supplied.

This attribution remains visible on direct/deep-linked settings routes so an end customer does not need to visit the Settings Hub index to understand who owns support.

## Canonical routing

Base owns the Settings Hub route:

```text
admin.php?page=core-blueprint-settings&extension={extension-id}
```

The internal route and public PHP contract remain `core-blueprint-settings` / `SettingsRegistry`; **Extensions** is the user-facing navigation label. This avoids an unnecessary API rename while keeping the WordPress menu unambiguous.

Extensions should build links with:

```php
SettingsRegistry::url( 'vendor-example' );
```

Provider-owned query arguments may be added:

```php
SettingsRegistry::url(
    'vendor-example',
    [ 'tab' => 'integrations' ]
);
```

Base-owned `page` and `extension` route arguments cannot be replaced through the helper.

An extension may keep its own internal settings navigation, such as `tab=general` or `tab=integrations`, inside the canonical Settings Hub route.

## Capability and fail-closed behaviour

The provider's declared capability controls both index visibility and direct provider access.

A provider that is unknown, malformed, lacks a resolvable developer identity, has an unknown group/icon/semantic requirement, has an invalid capability or renderer, or duplicates an existing provider is rejected rather than repaired or broadened.

A user who cannot satisfy a provider's capability does not see that provider on the index and cannot render it through a direct Settings Hub route.

## Presentation and asset ownership

Base owns:

- the WordPress submenu entry `Core Blueprint → Extensions`;
- placement of **Extensions** as the final Base-owned submenu item, after **Preferences**;
- the Settings Hub shell and routing;
- provenance grouping;
- developer/support attribution presentation;
- capability filtering;
- group ordering;
- card/index presentation;
- semantic Foundation/component requirement validation and enqueueing;
- light/dark and responsive Core Admin presentation.

The extension owns:

- its settings fields;
- validation and save actions;
- domain semantics;
- its provider label/description/group/icon choice;
- optional developer support URL;
- its inner settings renderer;
- feature-specific CSS/JavaScript that does not redraw Base Foundation primitives.

Provider requirements use the same semantic IDs documented for `PageRegistry`. Private Base asset handles, filenames and bundle boundaries are not public API.

Only the currently selected provider's declared semantic requirements are folded into the Settings page requirement set and resolved through Base's canonical admin asset resolver.

## Settings Hub vs PageRegistry

`PageRegistry` remains the public boundary for genuine Core Admin pages that deserve their own submenu/surface.

For **extension configuration**, `SettingsRegistry` is the canonical boundary. A migrated extension must not keep a second flat Core Blueprint settings submenu solely as a compatibility alias.

Pre-v1 migration rule:

```text
old: extension settings → PageRegistry → flat Core Blueprint submenu
new: extension settings → SettingsRegistry → Core Blueprint → Extensions
```

Operational extension menus remain independent. For example, an LMS may keep Courses, Enrollments or Assessment Attempts in its own LMS workspace while its configuration lives in the Settings Hub.

## Preferences remains separate

Base's existing **Preferences** page is not the extension-settings directory. It currently owns Base/personal/site-wide configuration such as appearance, language, privacy, permissions, Notes defaults and reference surfaces.

The pilot does not merge Preferences into the Extensions hub. Any later information-architecture consolidation requires a separate decision and field review.

## Pilot scope

The first v1 pilot intentionally does **not** add:

- extension lifecycle/install/activation management to the Extensions hub;
- Extensions search;
- automatic migration of existing extension pages;
- automatic hidden/disabled extension listings;
- a mass rewrite of first-party plugins.

Base ships the registry and hub first. Representative first-party extensions can then adopt the contract on separate branches for staging UX validation before suite-wide migration.

## Release boundary

The Settings Hub Foundation is part of the Core Blueprint Base `1.0` public API family and the pre-launch `1.0.0-rc1` line.

It does not change `CB_CORE_API_VERSION` or `CB_CORE_DB_VERSION`, adds no database schema, and introduces no legacy alias for migrated settings pages.
