# Core Blueprint Public API — v1

`CB_CORE_API_VERSION` is `1.0`. Only the contracts documented here (and their linked Foundation documents) are considered stable public API throughout Core Blueprint Base 1.x. A PHP method being `public` does **not** by itself make it a supported third-party contract.

## Lifecycle

- `cb_core_booted` — fired on `plugins_loaded` priority 25 after Base has registered its first-party subsystems.
- Lightweight technical/dependency registration may occur during plugin bootstrap. Translation-bearing presentation metadata should be registered on `init` or later, following WordPress' normal i18n lifecycle.

## Declarative extension surfaces

- Dashboard Card API — `cb_core_dashboard_register_cards`, `cb_core_dashboard_card_shortcuts`; see `DASHBOARD-CARD-API.md`.
- HUD Menu API — `cb_hud_register_section_types`, `cb_hud_register_sections`, `cb_hud_register_items`; see `HUD-MENU-API.md`.
- Content Models — `CB\Core\ContentModels\Api` and `cb_core_content_models_register`; see `CONTENT_MODELS.md`.
- Content Models JSON Schema v1 — `format: core-blueprint-content-models` + `format_version: 1` is the stable schema portability document described in `CONTENT_MODELS.md`; Native WordPress discovery/import classes are internal Base tooling.
- Module activation — `cb_core_module_activation_definitions`; state classes must implement `CB\Core\Modules\ModuleStateInterface`.
- Module health/status — `cb_core_module_status_definitions`; providers return the canonical `ok|warn|err|off` status shape.
- Extension registry — `CB\Core\ExtensionRegistry` via `cb_core_register_extensions`; canonical identity/inventory/compatibility boundary.
- Interoperability Foundation — `CB\Core\Interoperability\Registry` via `cb_core_register_interoperability_contracts` and `cb_core_register_interoperability_implementations`; canonical versioned cross-extension contract registration, discovery and runtime resolution boundary; see `INTEROPERABILITY-FOUNDATION.md`.
- Forms Foundation — `CB\Core\Forms\ProviderInterface`, `CB\Core\Forms\SubmissionEmitter`, `CB\Core\Forms\SubmissionEvent` and the public contract constants on `CB\Core\Forms\Foundation`; Base-owned normalized form-submission interoperability boundary using Generic Interoperability provider admission; see `FORMS-FOUNDATION.md`.
- Data Exchange Foundation — `CB\Core\DataExchange\EntityInterface`, optional `CsvEntityInterface`, `CB\Core\DataExchange\Engine` and the public contract/transport constants on `CB\Core\DataExchange\Foundation`; Base-owned bounded import/export infrastructure using Generic Interoperability while extension providers retain authorization, identity resolution, validation and canonical mutations; see `DATA-EXCHANGE-FOUNDATION.md`.
- Data Mapper Foundation — `CB\Core\DataExchange\MappingEntityInterface`, `CB\Core\DataExchange\Mapper`, `CB\Core\DataExchange\Mapper\Renderer` and the documented `window.cbCore.dataMapper` browser/controller boundary; provider-neutral schema mapping and shared Designer Shell workspace with server-side provider validation remaining authoritative; see `DATA-MAPPER.md`. Asset handles, filenames and internal Designer composition details are not public API.
- Settings Hub — `CB\Core\Admin\SettingsRegistry` via `cb_core_register_settings`; canonical extension-configuration directory and routing boundary; see `SETTINGS-HUB-FOUNDATION.md`.
- Capability catalog — `cb_core_capability_catalog`.
- Access Mode request bypass — prefer `CB\Core\Security\AccessMode::register_bypass()`; advanced policy may use `cb_core_access_mode_bypass_request`.
- AI Governance activity reporting — `CB\Core\AIGovernance\Activity::record()`; see `AI-GOVERNANCE.md` for the stable v1 evidence, privacy and attribution contract.

## Canonical module identity and activation

Public module IDs are lower-case **kebab-case**, for example `core-scanner`, `login-shield` or `vendor-feature`. Snake-case aliases are not part of the v1 contract.

Extensions append activation definitions through `cb_core_module_activation_definitions`:

```php
add_filter( 'cb_core_module_activation_definitions', static function ( array $definitions ): array {
    $definitions['vendor-feature'] = [
        'state'      => Vendor\Feature\State::class,
        'capability' => 'manage_options',
    ];
    return $definitions;
} );
```

The state class must implement `CB\Core\Modules\ModuleStateInterface`. Base-owned IDs are reserved. Malformed definitions are ignored. Unknown, malformed or failing module state is treated as **disabled** by the canonical activation authority.

Activation answers only whether a module is enabled. Health/status is a separate contract.

## Canonical module health/status

Extensions append status definitions through `cb_core_module_status_definitions`:

```php
add_filter( 'cb_core_module_status_definitions', static function ( array $definitions ): array {
    $definitions['vendor-feature'] = [
        'provider' => [ Vendor\Feature\Health::class, 'status' ],
        'label'    => __( 'Vendor Feature', 'vendor-feature' ),
        'url'      => admin_url( 'admin.php?page=vendor-feature' ),
    ];
    return $definitions;
} );
```

The provider is invoked only when status is requested and returns:

```php
[
    'state'  => 'ok', // ok|warn|err|off
    'detail' => 'Short factual status detail.',
    'url'    => admin_url( 'admin.php?page=vendor-feature' ), // optional override
]
```

Base validates and sanitizes the result. Provider exceptions or malformed results degrade to `warn` / `Status unavailable`; they do not break Dashboard/HUD rendering. An unknown status ID has no status and returns no indicator.

Status registration is presentation metadata. Register translated labels at `init` or later when your integration resolves them dynamically.

## Canonical extension registry

`CB\Core\ExtensionRegistry` is the public v1 boundary for Core Blueprint extension registration and discovery. Registration is controlled by the registry; mutating an arbitrary discovery array is not a supported v1 extension mechanism.

Attach the registration callback during plugin bootstrap. Base fires the explicit `cb_core_register_extensions` lifecycle on `init` priority `5`:

```php
use CB\Core\ExtensionRegistry;

add_action( 'cb_core_register_extensions', static function (): void {
    ExtensionRegistry::register( [
        'id'           => 'vendor-security',
        'plugin_file'  => plugin_basename( VENDOR_SECURITY_FILE ),
        'requires_api' => '1.0',
        'menu_url'     => admin_url( 'admin.php?page=vendor-security' ),
        'status_id'    => 'vendor-security',
    ] );
} );
```

`id` is the single Core Blueprint platform identity and uses strict lower-case namespaced kebab-case (`[a-z][a-z0-9]*(?:-[a-z0-9]+)+`). The same ID is used as the extension's Dashboard Card ID. `plugin_file` is only the canonical WordPress plugin basename/inventory locator and is never an alternate extension identity. One extension ID and one plugin basename may each be claimed only once; duplicate or malformed registrations are rejected rather than overwritten.

`requires_api` is required and uses `major.minor`. Compatibility requires the same Core API major and a Base API minor greater than or equal to the requested minor. `requires_base` is optional and should be used only when a concrete Base product release is required beyond the API contract. No Composer-style range grammar is part of v1.

`status_id` is optional and references the separate `Modules\Status` registry. It is not an extension alias. Extension health remains the status vocabulary `ok|warn|err|off|unknown`; `off` is a deliberate state and is not collapsed to an unhealthy boolean.

Read the Base-owned inventory projection through:

```php
$all = ExtensionRegistry::snapshot();
$one = ExtensionRegistry::get( 'vendor-security' );
```

Read display-safe developer/provenance metadata for a valid registered extension through:

```php
$identity       = ExtensionRegistry::identity( 'vendor-security' );
$is_first_party = ExtensionRegistry::is_first_party( 'vendor-security' );
```

`identity()` reports the canonical plugin file, developer name, developer URL and Base-derived first-party classification. Developer identity comes from the registered WordPress plugin metadata. Callers cannot promote an extension into the reserved first-party namespace through display metadata.

Each snapshot keeps these concerns separate:

- `installed` — the WordPress plugin file exists;
- `active` — WordPress currently has the plugin active;
- `registered` — the active plugin submitted a valid registry definition;
- `compatible` — `true|false|unknown` based on the registered Core API/Base requirement;
- `health` — `ok|warn|err|off|unknown` resolved through the referenced status provider.

Inactive first-party plugins may be auto-discovered for inventory display when their folder uses `core-blueprint-*` and the plugin Author header is exactly `Core Blueprint`. That is **discovery only**. Plugin headers are not cryptographic identity and auto-discovery does not imply registration, compatibility, trust or health. Third-party extensions appear through valid active registration; Base does not maintain a hardcoded product list.

The `core-blueprint-*` ID namespace is reserved for first-party plugins. A registration using that namespace must point at the matching `core-blueprint-*` plugin folder and the Core Blueprint Author header. This is ownership hygiene for the platform registry, not a security-authentication mechanism.

## Canonical Settings Hub registry

`CB\Core\Admin\SettingsRegistry` is the public v1 boundary for extension configuration contributed to **Core Blueprint → Settings**. Configuration-only extension pages should use this registry instead of registering one flat extension submenu item below Core Blueprint.

A provider must reference an extension that is already valid through `ExtensionRegistry`. Register during the explicit `cb_core_register_settings` lifecycle:

```php
use CB\Core\Admin\SettingsRegistry;

add_action( 'cb_core_register_settings', static function (): void {
    SettingsRegistry::register(
        'vendor-security',
        [
            'label'       => __( 'Vendor Security', 'vendor-security' ),
            'description' => __( 'Configure security policy and integrations.', 'vendor-security' ),
            'group'       => SettingsRegistry::GROUP_INFRASTRUCTURE,
            'capability'  => 'manage_options',
            'renderer'    => [ Vendor\Security\Admin\Settings::class, 'render' ],
            'icon'        => 'settings',
            'support_url' => 'https://vendor.example/support',
            'requirements' => [
                'foundations' => [ 'toast' ],
                'components'  => [ 'cards', 'panels', 'form-controls' ],
            ],
        ]
    );
} );
```

The accepted provider fields are deliberately limited to `label`, `description`, `group`, `capability`, `renderer`, optional `icon`, optional `support_url` and optional semantic `requirements`. Unknown keys fail closed.

Developer identity and first-party provenance are **not** provider metadata. Base derives them from `ExtensionRegistry::identity()`. A provider cannot submit `first_party`, `official`, `developer_name` or `developer_url`. The Settings Hub always shows the developer for both first-party and third-party providers. Third-party detail routes explicitly state that the extension is developed and supported by that developer, not by Core Blueprint; an optional developer support link is shown when registered.

The public group constants are `GROUP_INFRASTRUCTURE`, `GROUP_CONTENT_PUBLISHING`, `GROUP_COMMUNITY`, `GROUP_BUSINESS`, `GROUP_COMMERCE` and `GROUP_OTHER`. Groups are navigation metadata only; they do not grant trust, activation or capabilities.

Capabilities are evaluated both for index visibility and direct provider access. Unknown/malformed providers and users without the provider capability fail closed.

Build the canonical deep link through:

```php
$url = SettingsRegistry::url( 'vendor-security' );
$tab = SettingsRegistry::url( 'vendor-security', [ 'tab' => 'integrations' ] );
```

Provider-owned query arguments such as `tab` may be preserved, but callers cannot replace the Base-owned `page` or `extension` route arguments.

Provider UI requirements use the same semantic `foundations` / `components` vocabulary as `PageRegistry`. Only the selected provider's declared requirements are enqueued; private Base asset handles and filenames remain internal.

The extension remains responsible for settings fields, validation/save authority, domain semantics and its inner renderer. Base owns the Settings Hub route/shell, provenance/developer presentation, support boundary, capability filtering, grouping and shared-UI asset resolution. See `SETTINGS-HUB-FOUNDATION.md` for the normative contract.

## Canonical HUD registry

The HUD public contract is declarative and ordered: section types → sections → items. Base owns rendering, placement, escaping and interaction behavior. Built-in section types are `navigation`, `quick-actions` and `status`. Extensions may register namespaced custom types such as `vendor/metrics`, but only through the controlled Base presentation primitives documented in `HUD-MENU-API.md`; arbitrary renderer callbacks/markup are not a v1 contract.

HUD section and item IDs use strict lower-case kebab-case and duplicates are rejected rather than overwritten. Orphan items and item shapes that do not match the target section type fail closed.

## Core Admin page registration

`CB\Core\Admin\Page` plus `CB\Core\Admin\PageRegistry` is the public v1 boundary for genuine pages contributed beneath the Core Blueprint admin menu. Configuration-only extension surfaces should use `SettingsRegistry` so adding extensions does not expand the WordPress submenu indefinitely. `PageBase` is an internal convenience implementation for Base-owned pages and is not a supported inheritance contract.

Extensions register genuine Core Admin pages during `cb_core_register_pages`:

```php
use CB\Core\Admin\PageRegistry;

PageRegistry::register(
    new Vendor\Admin\OperationsPage(),
    [
        'foundations' => [ 'modal', 'toast' ],
        'components'  => [ 'nav-tabs', 'panels', 'form-controls' ],
    ]
);
```

Page slugs use strict lower-case kebab-case and must be globally unique. Base-owned slugs are reserved independently of registration order. Duplicate registrations are rejected; a later registration never replaces an existing page. Public extension pages use `null` position or a position of `100` or higher. Positions `1-99` belong to Base. Capabilities must be valid WordPress capability identifiers.

A registered Core Admin page receives the semantic minimal Core Admin shell: Core Blueprint design tokens, baseline layout/typography and focus/accessibility behavior, standard Core Admin form/button geometry, and light/dark integration. This is a functional guarantee; CSS/JavaScript handles, filenames, bundle boundaries and enqueue order are implementation details.

Additional shared UI is declared through semantic requirement identifiers. `foundations` currently supports `toast`, `modal`, `token-input`, `clipboard`, `time-picker`, `choice-group`, `icon-picker`, `capability-picker`, `object-picker`, `select-picker` and `icons`. `components` currently supports `nav-tabs`, `panels`, `cards`, `integration-grid`, `detail-rows`, `notices`, `fields`, `radio-cards`, `master-switch`, `disclosure`, `metric-tiles`, `badges`, `state-badges`, `status`, `empty-state`, `kv-table`, `form-controls` and `description-toggle`. Every component ID represents a documented Core Admin Design Foundation markup/behavior contract, not a stylesheet name; see `CORE-ADMIN-DESIGN-FOUNDATION.md`. `integration-grid` is the provider/integration-level readiness surface documented in `INTEGRATION-GRID-FOUNDATION.md`; `detail-rows` is the concrete object/target/resource-level surface documented in `DETAIL-ROWS-FOUNDATION.md`. `metric-tiles` exposes only the current generic KPI/value-card contract; `Tile::quick`, navigation tiles and status-navigation tiles are not part of that semantic requirement. Unknown requirement identifiers or groups reject the page registration safely, and raw asset handles are never interpreted as requirements. Additive requirement identifiers may be introduced during Base 1.x.

`PageRegistry::hook_suffix( $slug )` is the supported way for an extension to scope its own CSS/JavaScript to its registered page after WordPress menu registration. Extensions remain free to enqueue their own assets. The current internal hook-suffix pattern fallback for unregistered sibling pages is transitional implementation behavior and **not** a public v1 guarantee; pages that want the Core Admin shell must register through `PageRegistry`.

A settings surface migrated to `SettingsRegistry` must remove its old flat `PageRegistry` settings registration rather than keeping a duplicate compatibility route. Operational extension menus are unaffected.

Base owns the canonical appearance of every declared Design Foundation primitive. First-party extension CSS may position or compose those primitives but must not locally redefine their generic colours, typography, spacing, borders, radii, surfaces, shadows or interaction states. Feature-specific extension components remain extension-owned and should consume `--cb-*` tokens where practical.

## UI Foundation

Public enqueue/runtime primitives are frozen in `foundation-v1-contract.md` and the individual Foundation documents. Extensions should enqueue the narrow primitive they need rather than importing Core Admin presentation wholesale.

## Governance / Audit

Extensions record governance-relevant events through the single public write facade:

```php
use CB\Core\Governance\Audit;

Audit::record(
    'vendor.item.updated',
    'notice',
    [ 'item_id' => 42 ]
);
```

`Audit::record()` is best-effort and non-fatal. It returns `true` when Base accepts the event (including an in-request deduplicated event) and `false` when the public contract rejects the input or storage fails. Queueing, storage, actor/IP resolution, retention, querying and export are Base implementation details; `CB\Core\Log\AuditLog` is not public API.

Public event IDs use collision-safe dotted identifiers. The first segment is the namespace/owner, the last segment is the action, and zero or more subject segments may appear between them. Every segment must match `[a-z][a-z0-9]*`; `.` is the only separator. IDs containing `_` or `-`, one-segment IDs, uppercase IDs, Base-reserved namespaces, or IDs whose exact storage identity exceeds 50 characters are rejected at the public write boundary. Base never truncates a public event ID. Examples: `vendor.updated`, `vendor.item.updated`, `vendor.sync.job.completed`.

Supported severities are `info`, `notice`, `warning` and `critical`. Invalid severity values are rejected rather than silently rewritten. Context must be a structured array. Base applies bounded defense-in-depth sanitization before storage: secret-bearing keys are redacted, strings/context depth and size are bounded, and objects/resources are never serialized. Extensions remain responsible for not submitting secrets in the first place.

Human-readable event metadata is registered through the controlled registry on `init` or later:

```php
use CB\Core\Governance\EventRegistry;

EventRegistry::register( [
    'id'                 => 'vendor.item.updated',
    'label'              => __( 'Vendor: item updated', 'vendor-plugin' ),
    'retention_category' => 'maintenance', // optional; defaults to general
] );
```

Definitions must be registered on `init` or later. Malformed definitions, duplicate IDs, storage-normalization collisions, invalid retention categories and attempts to claim Base-reserved namespaces are rejected. The first valid registration keeps ownership. A syntactically valid event does not require a label definition in order to be recorded; unknown labels fall back to the event ID.

The historical `cb_core_event_labels` filter is not a v1 public contract. Event metadata uses `EventRegistry`; direct access to Base repositories, query builders or storage classes is not a public compatibility promise in 1.x unless explicitly documented here. Extension-owned table lifecycle uses the dedicated `Database\SchemaRegistry` contract below.

## Retention policy

AuditLog retention has exactly five canonical categories: `security`, `maintenance`, `logins`, `settings` and `general`. `general` is the catch-all, so every AuditLog event resolves to exactly one category. Extensions may assign a registered event to one of these categories through `EventRegistry::register()`; custom categories are rejected.

```php
use CB\Core\Governance\RetentionPolicy;

$days = RetentionPolicy::days( 'security' );
$all  = RetentionPolicy::all();
$cat  = RetentionPolicy::category_for_event( 'vendor.item.updated' );
```

The persisted option layout, destructive query implementation and cron runner are Base internals. There is no second global AuditLog retention override in v1. A retention value of `0` means keep indefinitely; invalid categories return no policy value and are never pruned.

Dedicated datastores are separate from AuditLog categories. A plugin that owns an operational log table may contribute it through the narrow `RetentionStoreRegistry` boundary:

```php
use CB\Core\Governance\RetentionStoreRegistry;

RetentionStoreRegistry::register( [
    'id'           => 'vendor-request-log',
    'label'        => __( 'Vendor request log', 'vendor-plugin' ),
    'days'         => 30,
    'prune'        => [ Vendor\RequestLog::class, 'prune' ], // receives $days
    'settings_url' => admin_url( 'admin.php?page=vendor-settings' ),
] );
```

Store IDs are unique lower-case kebab-case identifiers. Duplicate/malformed registrations, negative windows and non-callable prune handlers are rejected. The callback is a retention-specific operation and receives the effective day window; this registry is not a general scheduled-job API. Dedicated stores retain ownership of their table schema and their own setting UI.

## Database schema registration

Extensions that own database tables register their schema declaratively through `CB\Core\Database\SchemaRegistry`. Base owns **when** reconciliation happens; the extension installer owns only **how** the declared schema is created or upgraded.

```php
use CB\Core\Database\SchemaRegistry;

SchemaRegistry::register( [
    'id'         => 'vendor-jobs',
    'version'    => '1.0',
    'option_key' => 'vendor_jobs_db_version',
    'tables'     => [
        [ Vendor\Jobs\Schema::class, 'jobs_table' ],
    ],
    'install'    => [ Vendor\Jobs\Schema::class, 'install' ],
] );
```

Schema IDs use strict lower-case kebab-case. Duplicate schema IDs and duplicate ownership of the same version option are rejected. Base-owned schema IDs/version options are reserved and cannot be claimed through the extension registration path. `version` uses numeric dot-separated components such as `1`, `1.0` or `2.3.1`. `tables` contains one or more callables returning the exact fully-prefixed table names owned by that schema.

Install callbacks are normatively **idempotent and re-runnable**. Base may call the same installer for first installation, a schema-version upgrade, or repair of a missing declared table. Installers must not update the schema version option themselves. Base advances the marker only after the installer completes without exception/explicit failure **and every declared table is verified to exist**. Multi-table schemas therefore advance their marker all-or-nothing.

Base serializes reconciliation per schema with an internal owner-token lock. Lock acquisition and stale-lock takeover are atomic, state is re-read after acquisition, and a lock is released only by its current owner. Extensions must not implement or invoke Base's migration locking/controller directly.

Table health verification is controlled/throttled. A current marker does **not** cause every registered table to be probed on every normal request. Version mismatches reconcile immediately; periodic health verification detects externally removed tables; registration that occurs after the normal sweep (for example during plugin activation) is reconciled immediately for that newly registered schema.

The following are implementation details and are **not** public API: `CB\Core\DB`, `DB::maybe_upgrade()`, Base query builders, repositories/storage classes, lock options, health-throttle state, and the SQL used to verify tables. Extensions register schemas only through `SchemaRegistry::register()`.

## Integrity / Core Scanner service

`CB\Core\Integrity\Api\IntegrityApi` is the public v1 PHP boundary for integrations that need to start a Core Scanner run or read its canonical result projection. REST controllers, scanner repositories, resumable-job persistence and storage classes remain internal.

```php
use CB\Core\Integrity\Api\IntegrityApi;

$scan = IntegrityApi::request_scan( 'vendor-feature' );
if ( ! is_wp_error( $scan ) ) {
    $scan_id = $scan['scan_id'];
}
```

The public methods are:

```text
IntegrityApi::request_scan( $source )
IntegrityApi::scan_status( $scan_id )
IntegrityApi::summary( $scan_id )
IntegrityApi::findings( $scan_id, $offset = 0, $limit = 50, $component = '' )
```

`scan_id` is an opaque identifier. Callers must use the exact identifier returned by `request_scan()` rather than deriving or parsing it. Base currently retains one full completed Scanner result; scan-ID scoping prevents accidental reads of a different/latest run but does **not** promise indefinite historical finding retention. An older scan ID whose full result is no longer retained returns a not-found error rather than silently falling back to another scan.

`request_scan()` respects the Core Scanner master switch and the single global scan lock. A successful request returns `scan_id`, `status=queued` and `started_at`. If another scan is already active, the call returns a `WP_Error` with HTTP-style status metadata `409` plus the active `scan_id`. Dispatch/scheduling failures return a non-fatal `WP_Error`; no partial result is published as complete.

`scan_status()` returns the active resumable progress projection or `status=done` for the retained completed result. `summary()` and `findings()` require a completed retained result for the requested scan ID; asking for them while that scan is still running returns a pending/conflict error rather than data from another run.

`findings()` exposes **Finding Schema 1** only. Its response includes `finding_schema=1`, the requested `scan_id`, a canonical summary, paginated `findings`, pagination metadata and the normalized component filter. Public Finding records use the single canonical `type + target + meta` representation established by Finding Schema 1. Controller/UI grouping, passed-check presentation and raw persisted `checks[]` are implementation details and are not part of this service contract. When `verification.scope` is present it is semantic evidence scope: `component` means the record represents a whole-component verification/snapshot, while `file` means file-level evidence. Baseline approval is only available for review-worthy whole-component local-baseline snapshots; file-level drift is never itself an approvable baseline candidate.

The PHP service is an in-process capability, not an authentication mechanism. Extensions that expose Scanner operations through REST/AJAX/CLI must secure their own entrypoint appropriately. Base still enforces Scanner lifecycle/state/locking semantics. The optional `$source` is audit/operational metadata and must never be treated as authentication or trust proof.

The following are **not** public v1 API: `Integrity\Rest\ScanController`, `ResultRepository`, `ScanJobRepository`, `ScanJobDispatcher`, progress transients, Scanner storage option names, or direct access to Storage Schema 1 payloads. Integrations use `IntegrityApi`; Base may refactor those internals while preserving the documented service behavior.

## Compatibility policy

- Additive changes may land in 1.x.
- Existing public v1 hooks keep their argument meaning and ordering throughout 1.x.
- Replacements are introduced before an existing public contract is deprecated.
- Removal of a public v1 contract is reserved for a future major version, except for an urgent security issue where compatibility would itself be unsafe.
- Internal `CB\Core\...` implementation details may change between 1.x releases.

## Explicitly not public

The historical `cb_core_event_labels`, `cb_core_safeguard_status_*`, `cb_core_module_status_*` per-module filter families, `Modules\Resolver`, HUD `display|integrations|wordpress` sections, HUD `note` items, and render-time HUD mutation filters are not part of the v1 public API. New integrations use the canonical declarative registries above.
