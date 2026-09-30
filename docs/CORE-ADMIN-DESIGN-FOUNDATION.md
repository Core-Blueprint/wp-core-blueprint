# Core Admin Design Foundation

Status: **public v1 Core Admin markup/design contract**.

## Ownership boundary

A `PageRegistry` component requirement means:

```text
semantic component ID
→ documented markup/behavior contract
→ Base-owned presentation/assets
```

It does **not** expose CSS filenames, WordPress handles, enqueue order or bundle boundaries. Base owns the canonical visual language for shared Core Admin primitives, including light/dark presentation, tokens, typography, spacing, surfaces, borders, radii, shadows, hover/focus and semantic states.

First-party extension CSS may position or compose a Base primitive, but must not locally redraw its canonical appearance. Feature-specific product components remain extension-owned and should consume `--cb-*` tokens where practical.

The minimal Core Admin shell does not automatically include every primitive. Registered pages declare only what their markup consumes.

## `nav-tabs`

WordPress-native tab navigation with Core Admin presentation.

```html
<nav class="nav-tab-wrapper cb-core-tab-wrapper" aria-label="Section navigation">
    <a class="nav-tab nav-tab-active" href="...">Overview</a>
    <a class="nav-tab" href="...">Settings</a>
</nav>
```

Contract: keep WordPress `nav-tab` / `nav-tab-active` interaction grammar and add `cb-core-tab-wrapper` to the wrapper. Base owns tab colours, borders, typography, focus and dark/light states.

## `panels`

Shared framed content surface.

```html
<section class="cb-core-panel">
    <h2>Section title</h2>
    <p>Content…</p>
</section>
```

A direct child `table.widefat` is a supported panel composition and inherits Base panel-table presentation. Normal panels keep their standard inner padding around headings, copy, controls and mixed content.

A panel whose only visual content is a management table may opt into the explicit full-bleed table composition:

```html
<section class="cb-core-panel cb-core-panel--table">
    <table class="widefat striped">…</table>
</section>
```

`cb-core-panel--table` removes the panel's inner padding and lets the direct-child `widefat` table use the panel border/radius as its outer frame. Use this modifier only for pure table containers. Do not apply it to panels that also contain headings, explanatory copy, actions or other controls that require normal panel spacing. Panel and table presentation remain Base-owned; extension CSS may position the panel in a grid or layout.

## `cards`

Static information/content card; not a navigation tile.

```html
<section class="cb-core-card">
    <header class="cb-core-card__header">
        <h2 class="cb-core-card__title">Title</h2>
    </header>
    <div class="cb-core-card__body">Content…</div>
</section>
```

Supported structural elements include `__header`, `__title`, `__icon`, `__body`, `__body--flush`, `__lead`, `__empty`, `__footer` and `__footer-stripe`; `cb-core-card--spacious` is the shared spacious variant.

## `metric-tiles`

Compact KPI/value tile rendered with the current `CB\Core\UI\Tile` metric variant. This semantic exposes only the generic metric contract; navigation variants are not included.

```php
echo CB\Core\UI\Tile::render( [
    'variant'    => 'metric',
    'label'      => 'Protected media',
    'value'      => '42',
    'state'      => 'ok',
    'state_text' => 'Healthy',
] );
```

Base owns metric-card surface, label/value hierarchy and semantic state treatment. Consumers own the KPI meaning and layout of multiple tiles. Navigation/status tile variants are not part of this semantic requirement.

## `notices`

Persistent semantic in-page notice rendered by `CB\Core\UI\Notice`.

```php
echo CB\Core\UI\Notice::render( [
    'variant' => CB\Core\UI\Notice::WARNING,
    'title'   => 'Attention required',
    'message' => 'Review this setting before continuing.',
] );
```

Canonical variants are `info`, `success`, `warning` and `error`. Base owns the notice surface, icon geometry, colour/state treatment, typography and responsive presentation. This is distinct from WordPress global `.notice` messages.

## `fields`

Structured form-field wrapper rendered by `CB\Core\UI\Field`.

```php
echo CB\Core\UI\Field::render( [
    'label'   => 'Endpoint',
    'control' => '<input type="text" name="endpoint">',
    'hint'    => 'Used for outbound requests.',
] );
```

Field owns wrapper, label, hint/meta/error structure and field-level states. The actual native input/select/textarea presentation remains the separate `form-controls` contract. A page using `Field` with native controls may therefore declare both `fields` and `form-controls` when it wants to state both semantics explicitly.

## `radio-cards`

Single-select radio options presented as shared clickable cards. Prefer `CB\Core\UI\RadioGroup` / `CB\Core\UI\RadioCard` rather than duplicating markup.

```php
echo CB\Core\UI\RadioGroup::render( [
    'name'    => 'mode',
    'value'   => 'safe',
    'layout'  => 'grid',
    'options' => [
        [ 'value' => 'safe', 'label' => 'Safe', 'desc' => 'Recommended default.' ],
        [ 'value' => 'custom', 'label' => 'Custom', 'desc' => 'Advanced configuration.' ],
    ],
] );
```

Base owns card surfaces, selected/focus/hover treatment, responsive radio-grid geometry and the shared checkable state layer. `form-controls` continues to own only the underlying native radio control presentation.

## `master-switch`

Binary consequence-selector rendered by `CB\Core\UI\MasterSwitch`.

```php
echo CB\Core\UI\MasterSwitch::render( [
    'name'       => 'feature',
    'aria_label' => 'Toggle feature',
    'active'     => 'on',
    'states'     => [
        'on'  => [ 'tone' => 'success', 'label' => 'On',  'description' => 'Feature active.' ],
        'off' => [ 'tone' => 'warning', 'label' => 'Off', 'description' => 'Feature inactive.' ],
    ],
] );
```

Base owns the two consequence surfaces, toggle geometry, semantic tones, interaction states and responsive stacking. Business persistence and click handling remain consumer-owned.

## `disclosure`

Native `<details>` disclosure surface for expandable Core Admin sections. This is distinct from the Plain/Technical `description-toggle` contract.

```html
<details class="cb-core-disclosure cb-core-disclosure--section cb-core-disclosure--subtle">
    <summary class="cb-core-disclosure__summary">
        <span class="cb-core-disclosure__icon" aria-hidden="true">…</span>
        <span class="cb-core-disclosure__title">Advanced details</span>
    </summary>
    <div class="cb-core-disclosure__body">…</div>
</details>
```

Canonical variants include `--section`, `--subtle` and `--compact`. Base owns surface, open/hover/focus states, title/meta geometry and icon rotation. Native `<details>` provides the interaction behavior; consumers own the disclosed business content.

## `badges`

Metadata/classification badge.

```html
<span class="cb-core-badge cb-core-badge-tech">PHP 8.5</span>
```

Use `cb-core-badge` plus a Base-defined semantic/classification variant such as `cb-core-badge-tech`, `cb-core-badge-standard`, `cb-core-badge-identity`, `cb-core-badge-cwe`, `cb-core-badge-compliance`, `cb-core-badge-severity` or the documented severity/risk modifiers. Do not invent local colour variants for generic badge states.

## `state-badges`

Compact workflow/state label.

```html
<span class="cb-core-state-badge cb-core-state-badge--success cb-core-state-badge--compact">Active</span>
```

Canonical state modifiers are `--neutral`, `--info`, `--success`, `--warning`, `--danger`/`--error`; size modifiers are `--compact` and `--default`.

## `status`

Informational dot + text status indicator.

```html
<span class="cb-core-status">
    <span class="cb-core-status__dot cb-core-status__dot--success" aria-hidden="true"></span>
    <span class="cb-core-status__label">Healthy</span>
</span>
```

The visible label carries the accessible state meaning; the dot is decorative and should be `aria-hidden`. Canonical dot modifiers include success, warning, danger, info and muted.

Standalone WordPress admin screens load this same semantic markup through
`CB\Core\UI\Assets::enqueue_status()`; Base selects the WordPress-native
presentation adapter without importing the Core Admin token/theme boundary.

## `empty-state`

Standalone empty-result/message surface.

```html
<p class="cb-core-empty">No items yet.</p>
```

For an empty region inside a Card use the Card contract's `cb-core-card__empty` instead.

## `kv-table`

Key/value or diagnostic/current-state table.

```html
<table class="widefat cb-core-kv">
    <tbody>
        <tr>
            <th scope="row">PHP</th>
            <td>8.5</td>
        </tr>
    </tbody>
</table>
```

`th[scope="row"]` is the canonical key column. `cb-core-current` may mark the current row. This semantic is deliberately **not** a generic arbitrary-table contract. Feature-specific tables remain feature-owned until Base defines a true shared table primitive.

## `form-controls`

Core Admin presentation for native WordPress form controls inside the Core Admin surface. Existing WordPress form semantics and accessible labels remain authoritative; Base owns presentation. The minimal Core Admin shell already provides the baseline form-control asset, but the semantic ID remains accepted as an explicit component declaration.

## `description-toggle`

Plain/Technical dual-description block with Base-owned toggle behavior.

Prefer Base's renderer so the accessibility and toggle markup remain canonical:

```php
CB\Core\UI::render_description_block(
    [
        'plain'     => 'Plain explanation…',
        'technical' => 'Technical explanation…',
    ],
    CB\Core\UI::current_mode()
);
```

The resulting contract uses `.cb-core-desc.cb-core-dual[data-active]`, `.cb-core-desc-plain`, `.cb-core-desc-technical` and the `.cb-core-desc-toggle[data-current]` button with its accessible label/title. Base owns the `@cb-core/description-toggle` behavior, current Plain/Technical mode and presentation; consumers should not duplicate the control markup when the renderer is available.

## Not public primitives

Private/page-specific styles such as `table-cols`, `policy-table`, `log-table`, Scanner/Reports-specific table rules and other feature composition CSS are **not** promoted by this contract merely because more than one Base screen uses them. A future public primitive requires a genuinely reusable markup/behavior contract first.

Navigation and status-navigation tile variants are not promoted by the `metric-tiles` contract.
