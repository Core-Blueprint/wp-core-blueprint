# Core Blueprint Designer Mode

Status: **public v1 Designer launch + composition contract**.

> **Base owns Designer Mode. Consumers own what is being designed.**

Base owns the shared Designer Shell chrome, viewport lifecycle, launch transition, responsive shell geometry, adaptive toolbar, canonical composition grammar and focus/fullscreen behavior. Consumers own their editor semantics, domain content, persistence and business behavior.

## Public entry points

The Design Foundation deliberately exposes two different levels of integration.

### Editor engine and shell only

```php
CB\Core\Design\Editor\Assets::enqueue();
```

This loads the public Design Editor module and shared shell/runtime contracts, including `@cb-core/design-editor`. Use it for an embedded or otherwise consumer-composed editor that needs Base session/history/profile/shell APIs but does **not** want the canonical Designer Mode launch/fullscreen experience.

The semantic `design-editor` Foundation requirement resolves to this engine/shell level. It does not grant permission to reproduce Designer Mode chrome locally.

### Canonical Designer Mode

```php
CB\Core\Design\Editor\Assets::enqueue_designer_mode( __( 'Example Designer', 'example' ) );
```

This includes the editor engine and adds the canonical Designer Mode launch, adaptive toolbar composition, Button presentation required by Base-generated chrome, Form Control presentation inside the Designer, panel rails/collapse behavior, canonical panel/canvas composition primitives, responsive drawers and focus/fullscreen lifecycle.

`enqueue_designer_mode()` is presentation-self-contained for the Base chrome and composition grammar it exposes. A standalone WordPress admin consumer does **not** need `.cb-core-wrap`, `.cb-core-form-scope`, private Base asset handles or the full Core Admin theme in order to obtain the canonical Designer launch, controls and shell presentation. Base applies the narrow Designer form scope itself.

Consumers must not import private Base CSS filenames or handles to complete Designer Mode styling.

## Manual mode

Manual mode remains the default. Use it when a normal WordPress admin page should remain visible until the user explicitly chooses to enter the Designer.

```html
<div data-cb-design-launch-root>
    <div data-cb-design-launch-context>…normal admin context…</div>
    <div class="cb-core-design-shell" data-cb-design-shell>…</div>
</div>
```

After `CB\Core\Design\Editor\Assets::enqueue_designer_mode()` Base adds the canonical **Design with Core Blueprint** launch control, owns the vertical rhythm around that control, keeps the shell hidden until launch and returns to the normal admin context when fullscreen closes.

The surrounding admin page may remain WordPress-native. Do not add `.cb-core-wrap` merely to make Designer chrome look correct.

## Direct mode

Direct mode is for routes that already represent an editor session, for example a workflow opened from an Automations library. The route must not visually pass through a normal admin page or the manual launch control.

Declare the mode and a same-origin exit URL in the server-rendered markup:

```html
<div
    data-cb-design-launch-root
    data-cb-design-launch-mode="direct"
    data-cb-design-exit-url="https://example.test/wp-admin/admin.php?page=consumer-library"
>
    <div class="cb-core-design-shell" data-cb-design-shell>…</div>
</div>
```

Direct mode rules:

- `data-cb-design-launch-mode="direct"` and a non-empty `data-cb-design-exit-url` are both required.
- The exit URL must resolve to the current origin. Invalid or cross-origin exit URLs fail back to manual mode when a manual launch context is available.
- Base gives the server-rendered shell fullscreen viewport composition from first paint; consumers must not add overlays, body masks, programmatic launch-button clicks or their own fullscreen geometry.
- Base does not create the **Design with Core Blueprint** manual launch control in direct mode.
- Base hydrates the existing Designer Shell fullscreen controller and keeps the direct route visually fullscreen throughout entry and exit.
- Closing fullscreen, including Escape when no responsive drawer or compact toolbar disclosure is open, navigates directly to the declared exit URL. The underlying WordPress admin page is not an intermediate visual state.
- Consumer Designer Shell roots remain geometrically neutral. Outer margins, fixed positioning, viewport height and fullscreen transitions belong to Base.

Direct mode is transient UI state. It does not change the consumer's document/workflow model and must not be persisted as domain data.

## Canonical responsive geometry

Designer Mode has one Base-owned responsive contract:

| Viewport | Canonical shell composition |
| --- | --- |
| `>1280px` | persistent palette + canvas + sidebar in three rails; palette/sidebar may use the canonical collapse controls |
| `≤1280px` | canvas-first workspace; palette is an off-canvas drawer from the left and sidebar is an off-canvas drawer from the right |

Tablet and mobile **never stack** palette/sidebar above or below the canvas. Base inserts responsive toolbar openers for both drawers, a shared backdrop and the drawer close behavior. Opening one drawer closes the other so the canvas remains the stable primary workspace.

Responsive drawer behavior is also Base-owned:

- drawers start closed when entering `≤1280px`;
- the left drawer opens from the left and the right drawer opens from the right;
- closed drawers are `aria-hidden` and `inert` so hidden controls do not remain keyboard-focusable;
- focus moves into the drawer when opened and returns to its toolbar opener when closed through the drawer control, backdrop or Escape;
- Escape closes an open drawer before the fullscreen Escape lifecycle is allowed to run;
- returning to `>1280px` restores the persistent three-rail shell.

Consumers must not redefine the Designer Shell column/drawer model, reorder these structural regions with local CSS, add their own responsive overlays/backdrops, or introduce product-specific breakpoints that replace this contract. A consumer may style layout **inside** its palette, canvas or sidebar slots only where the public composition contract deliberately leaves domain content open.

## Adaptive toolbar composition

The canonical Designer toolbar is capability-driven. Consumers declare controls and actions; Base decides whether those controls remain explicit or are compressed into compact disclosures when horizontal space becomes constrained.

Base measures the **actual toolbar width** rather than relying on a product-specific viewport breakpoint. The internal compact threshold is not part of the public consumer API and may evolve without requiring consumer changes.

Wide toolbar behavior:

- viewport controls remain explicit when the consumer exposes `data-cb-design-shell-viewport` controls;
- Undo/Redo, fullscreen/exit and the primary Save action remain explicit;
- desktop palette/sidebar collapse controls keep their normal shell presentation;
- declared toolbar extensions receive a Base-owned wide presentation.

Compact toolbar behavior:

- viewport controls are represented by one **View** disclosure whose trigger follows the active viewport icon;
- Undo/Redo plus fullscreen/exit are represented by one **Actions** disclosure;
- the primary action declared with `data-cb-design-shell-primary-action` stays pinned and is never moved into an overflow disclosure;
- responsive left/right drawer openers stay pinned;
- status text and redundant explicit controls are hidden while their compact equivalents are active;
- only one disclosure may be open at a time;
- clicking outside closes the open disclosure;
- Escape closes the disclosure first and restores focus to its trigger before drawer/fullscreen Escape behavior may continue.

The compact disclosure uses Base-owned proxy controls that forward activation to the original command control. Disabled, active, pressed, label and icon state are synchronized from the original control, so consumer-owned callbacks and command authority remain unchanged.

### Extending the toolbar

Additional consumer actions are declared as **command sources**, outside the internal toolbar structure. Base keeps those sources hidden and creates both the wide toolbar presentation and the compact disclosure presentation.

For an additional action:

```html
<div data-cb-design-shell-toolbar-extension="actions">
    <button type="button" data-my-duplicate-command>Duplicate</button>
</div>
```

For an additional view-related capability:

```html
<div data-cb-design-shell-toolbar-extension="view">
    <button type="button" data-my-outline-command>Outline</button>
    <button type="button" data-my-preview-command>Preview</button>
</div>
```

Supported public values are:

- `data-cb-design-shell-toolbar-extension="view"`
- `data-cb-design-shell-toolbar-extension="actions"`

The command-source buttons remain consumer-owned: attach the domain callback, disabled state and pressed/active state to those buttons as usual. Base mirrors that state into its presentation and forwards activation back to the source button. Consumers do not inject markup into Base's generated toolbar zones and do not need to know the internal toolbar DOM.

If a command source exposes a supported Designer icon through `data-cb-design-shell-icon`, Base may reuse that icon in the generated presentation. Text labels remain required because they provide the accessible action name and the compact disclosure label.

Do not declare the primary Save command as a toolbar extension. `data-cb-design-shell-primary-action` remains the canonical pinned primary-action contract.

Consumers must not implement their own mobile toolbar, overflow menu, compact breakpoint or duplicate proxy callbacks around these actions.

## Canonical composition grammar

Full Designer Mode consumers should compose their domain UI with the public Base primitives below instead of recreating Mail, Automations or another product's local presentation.

Base provides the visual grammar; the consumer supplies the labels, controls and domain rendering.

### Canonical panel composition

Palette and sidebar presentation use one Base-owned composition grammar, regardless of whether a consumer exposes tabs.

For an untabbed panel, use the composed panel modifier and Base panel-body/section primitives:

```html
<aside
    class="cb-core-design-shell__palette cb-core-design-shell__palette--composed"
    aria-label="Content"
>
    <div class="cb-core-design-shell__panel-body">
        <section class="cb-core-design-shell__panel-section">
            <h3 class="cb-core-design-shell__panel-section-title">Elements</h3>
            <p class="cb-core-design-shell__panel-section-description">Add content to the document.</p>

            <div class="cb-core-design-shell__palette-grid">
                <button type="button" class="cb-core-design-shell__palette-item">Heading</button>
                <button type="button" class="cb-core-design-shell__palette-item">Text</button>
            </div>
        </section>
    </div>
</aside>
```

For a sidebar field section, use the same panel grammar:

```html
<div class="cb-core-design-shell__panel-body">
    <section class="cb-core-design-shell__panel-section">
        <h3 class="cb-core-design-shell__panel-section-title">Template</h3>
        <label class="cb-core-design-shell__field">
            <span class="cb-core-design-shell__field-label">Title</span>
            <input type="text" />
            <span class="cb-core-design-shell__field-hint">Used as the document title.</span>
        </label>
        <div class="cb-core-design-shell__panel-actions">…domain actions…</div>
    </section>
</div>
```

Base owns panel inset, scroll behavior, section spacing/dividers, title/description hierarchy, field rhythm, action-row spacing and palette item visual states. Consumers supply the labels, domain controls, values, validation and behavior.

**Form Control presentation is Base-owned inside Designer Mode.** `enqueue_designer_mode()` loads the canonical Base Form Controls Foundation and applies its safe form scope to the Designer shell itself. Consumers render semantic native controls inside the public field/panel primitives; they must not add `.cb-core-form-scope`, restyle inputs/selects/textareas locally or depend on an incidental Core Admin wrapper.

The public panel primitives are:

- `.cb-core-design-shell__palette--composed`
- `.cb-core-design-shell__sidebar--composed`
- `.cb-core-design-shell__panel-body`
- `.cb-core-design-shell__panel-section`
- `.cb-core-design-shell__panel-section-title`
- `.cb-core-design-shell__panel-section-description`
- `.cb-core-design-shell__field`
- `.cb-core-design-shell__field-label`
- `.cb-core-design-shell__field-hint`
- `.cb-core-design-shell__panel-actions`
- `.cb-core-design-shell__palette-grid`
- `.cb-core-design-shell__palette-item`

Existing `.cb-core-design-shell__tabs` / `__panel` and `.cb-core-design-shell__sidebar-tabs` / `__sidebar-panel` remain the only canonical tab rails. When tabs are present, place the Base panel-body grammar inside the applicable active panel; Base neutralizes the legacy tab-panel inset so `__panel-body` remains the single spacing authority. Do not create a second tab system.

Inspector, Layers and Settings remain capability-driven roles. A consumer without those capabilities may use an untabbed composed sidebar, but the absence of tabs does **not** permit a product-specific panel grammar.

Consumers must not redefine panel padding, section dividers, field rhythm or palette item presentation locally.

### Canvas

Use the composed canvas modifier when the Designer has a visual work area:

```html
<section class="cb-core-design-shell__canvas cb-core-design-shell__canvas--composed">
    <header class="cb-core-design-shell__canvas-header">
        <div class="cb-core-design-shell__canvas-heading">
            <h2 class="cb-core-design-shell__canvas-title">Document canvas</h2>
            <p class="cb-core-design-shell__canvas-description">Select an item to edit it.</p>
        </div>
        <div class="cb-core-design-shell__canvas-actions">…domain controls…</div>
    </header>

    <div class="cb-core-design-shell__canvas-workarea">
        …domain canvas…
    </div>
</section>
```

The header, heading hierarchy, action rail, work-area spacing, scrolling and responsive stacking are Base-owned. Consumers may populate the slots but must not restyle the canonical rail locally.

### Visual surfaces

Use `.cb-core-design-shell__surface` for a generic preview/edit surface inside the work area.

For page-like documents, add `.cb-core-design-shell__surface--document`. Base owns its paper presentation. Consumers may tune only these public custom properties when their domain requires a different page size:

- `--cb-design-document-width`
- `--cb-design-document-min-height`
- `--cb-design-document-padding`

Do not copy the document-surface CSS into the extension.

Email, workflow, certificate and contract semantics remain consumer-owned. A viewport switcher is **not** mandatory merely because Mail uses one; expose viewport controls only where the domain supports meaningful viewport states.

### Empty states and internal flow

Use `.cb-core-design-shell__empty-state` for an empty canvas/panel state and `.cb-core-design-shell__composition-stack` for canonical vertical composition inside a Designer slot.

Palette and sidebar tabs continue to use the existing Base shell primitives. Inspector, Layers and Settings are capability-driven canonical roles: consumers should expose only roles they actually implement.

## Ownership boundary

Base owns:

- launch mode interpretation and the **Design with Core Blueprint** launch control;
- launch-control spacing and branded presentation;
- canonical Designer brand/header composition;
- shared history/viewport/fullscreen/save-control presentation;
- adaptive toolbar compression, View/Actions disclosures, pinned primary action and toolbar-extension proxy/state synchronization;
- first-paint viewport composition for direct mode;
- fullscreen/focus lifecycle and Escape handling;
- palette/canvas/sidebar structural layout;
- persistent desktop three-rail geometry and tablet/mobile off-canvas drawer geometry;
- responsive drawer toolbar openers, backdrop, exclusivity, focus management and Escape-first-close behavior;
- panel rails, headers and collapse affordances;
- canonical palette/sidebar tabs and sidebar roles/order/icons where those roles are provided;
- canonical palette/sidebar body inset, sections, dividers, field rhythm, action rows, form-control and palette-item presentation;
- canonical canvas header/work-area composition, visual surfaces and empty-state presentation;
- direct-mode exit navigation;
- the Base Button presentation required by Designer chrome.

Consumers own:

- deciding which route should request manual or direct mode;
- rendering the shared shell markup and selecting applicable public composition primitives;
- declaring optional toolbar command sources through `data-cb-design-shell-toolbar-extension`;
- deciding what objects/content/workflows are being edited;
- palette item labels and semantics, not their shared presentation;
- domain canvas rendering;
- labels and domain controls placed in Base-owned composition slots;
- Inspector/Layers/Settings domain content;
- domain validation and command policy;
- persistence, permissions and save authority;
- supplying the same-origin exit URL for direct mode;
- domain-specific preview behavior and product-specific controls inside the shared slots.

## Consumer restrictions

A Designer Mode consumer must not:

- require or add `.cb-core-wrap` or `.cb-core-form-scope` as a presentation workaround;
- enqueue private `cb-core-css-*` handles or Base CSS filenames directly;
- reproduce the launch control or Base toolbar locally;
- inject product markup into Base-generated toolbar zones instead of using the public toolbar-extension source contract;
- implement a product-specific compact toolbar, overflow dropdown, responsive toolbar breakpoint or duplicate action proxy layer;
- implement its own fullscreen/focus overlay, fixed viewport shell or Escape lifecycle;
- override `.cb-core-design-shell__workspace` / `__workspace--collapsible` desktop rail or responsive drawer geometry;
- add consumer-owned responsive drawer toggles, backdrops or sidebar stacking rules;
- redraw panel rails, collapse controls, canonical tabs, canvas header/work-area rails or canonical sidebar role presentation;
- redefine Base panel padding, section dividers, field rhythm, form controls, palette grids/items or shared panel action presentation in consumer CSS;
- copy Base Button, document-surface or Designer spacing rules into consumer CSS;
- treat Mail, Automations or another first-party product's local CSS as the public Designer API.

If a reusable Designer behavior is missing, stop and extend the Base Foundation contract deliberately instead of adding a product-local workaround.

## External-consumer conformance

The public Designer contract must remain usable by a standalone extension that is not inside `.cb-core-wrap` and does not receive incidental Core Admin assets.

Before a Base Designer change is considered release-ready, source/regression coverage and field QC should verify at minimum:

- canonical launch control presentation and rhythm outside `.cb-core-wrap`;
- canonical palette/sidebar composition with and without tabs;
- canonical panel sections, fields, form controls and palette items without product-local presentation CSS;
- canonical panel and canvas composition without product-local shell CSS;
- `>1280px` persistent three-rail geometry;
- `≤1280px` canvas-first off-canvas drawers with no palette/sidebar stacking;
- left/right drawer open/close, backdrop, exclusivity, Escape and focus-return behavior;
- wide toolbar explicit-control presentation;
- constrained-width View and Actions disclosures with Save and drawer controls pinned;
- compact toolbar outside-click, Escape, focus-return, disabled/active state synchronization and toolbar-extension discovery;
- toolbar extension sources remain presentation-inert while Base owns their wide/compact proxies;
- light and dark presentation where the host provides supported Core Blueprint tokens/theme state;
- fullscreen, Escape and focus behavior;
- keyboard/focus order for canonical chrome;
- no private-handle dependency in consumer code;
- no full Core Admin theme leak onto a standalone WordPress admin page;
- no browser-console or PHP errors introduced by the integration.

A Base-owned Mail screen may remain a visual quality benchmark, but it is not sufficient by itself to prove the external public consumer boundary.
