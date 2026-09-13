# Core Blueprint Designer Mode

Status: **public v1 Designer launch contract**.

> **Base owns Designer Mode. Consumers own what is being designed.**

Base owns the shared Designer Shell chrome, viewport lifecycle, launch transition, responsive shell geometry and focus/fullscreen behavior. Consumers own their editor semantics, canvas content, persistence and business behavior.

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

This includes the editor engine and adds the canonical Designer Mode launch, toolbar composition, Button presentation required by Base-generated chrome, panel rails/collapse behavior and focus/fullscreen lifecycle.

`enqueue_designer_mode()` is presentation-self-contained for the Base chrome it generates. A standalone WordPress admin consumer does **not** need `.cb-core-wrap`, private Base asset handles or the full Core Admin theme in order to obtain the canonical Designer launch and shell presentation.

Consumers must not import private Base CSS filenames or handles to complete Designer Mode styling.

## Manual mode

Manual mode remains the default. Use it when a normal WordPress admin page should remain visible until the user explicitly chooses to enter the Designer.

```html
<div data-cb-design-launch-root>
    <div data-cb-design-launch-context>…normal admin context…</div>
    <div class="cb-core-design-shell" data-cb-design-shell>…</div>
</div>
```

After `CB\Core\Design\Editor\Assets::enqueue_designer_mode()` Base adds the canonical **Design with Core Blueprint** launch control, keeps the shell hidden until launch and returns to the normal admin context when fullscreen closes.

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
- Closing fullscreen, including Escape, navigates directly to the declared exit URL. The underlying WordPress admin page is not an intermediate visual state.
- Consumer Designer Shell roots remain geometrically neutral. Outer margins, fixed positioning, viewport height and fullscreen transitions belong to Base.

Direct mode is transient UI state. It does not change the consumer's document/workflow model and must not be persisted as domain data.

## Canonical responsive geometry

Designer Mode has one Base-owned responsive contract:

| Viewport | Canonical shell composition |
| --- | --- |
| `>1280px` | palette + canvas + sidebar in three columns |
| `901–1280px` | palette + canvas in two columns; sidebar below across the full width |
| `≤900px` | palette, canvas and sidebar in one vertical column |

Panel-collapse controls are a wide-layout affordance and are hidden at `≤1280px`.

Consumers must not redefine the Designer Shell column model, reorder these structural regions with local CSS, or introduce product-specific breakpoints that replace this contract. A consumer may style layout **inside** its palette, canvas or sidebar slots.

## Ownership boundary

Base owns:

- launch mode interpretation and the **Design with Core Blueprint** launch control;
- canonical Designer brand/header composition;
- shared history/viewport/fullscreen/save-control presentation;
- first-paint viewport composition for direct mode;
- fullscreen/focus lifecycle and Escape handling;
- palette/canvas/sidebar structural layout;
- responsive `3 → 2 → 1` shell geometry;
- panel rails, headers and collapse affordances;
- canonical sidebar roles/order/icons where those roles are provided;
- direct-mode exit navigation;
- the Base Button presentation required by Designer chrome.

Consumers own:

- deciding which route should request manual or direct mode;
- rendering the shared shell markup and their domain-specific slots;
- deciding what objects/content/workflows are being edited;
- palette items and canvas rendering;
- Inspector/Layers/Settings domain content;
- domain validation and command policy;
- persistence, permissions and save authority;
- supplying the same-origin exit URL for direct mode;
- domain-specific preview behavior and product-specific controls inside the shared slots.

## Consumer restrictions

A Designer Mode consumer must not:

- require or add `.cb-core-wrap` as a presentation workaround;
- enqueue private `cb-core-css-*` handles or Base CSS filenames directly;
- reproduce the launch control or Base toolbar locally;
- implement its own fullscreen/focus overlay, fixed viewport shell or Escape lifecycle;
- override `.cb-core-design-shell__workspace` / `__workspace--collapsible` column geometry;
- redraw panel rails, collapse controls or canonical sidebar role presentation;
- copy Base Button styling into consumer CSS;
- treat Mail, Automations or another first-party product's local CSS as the public Designer API.

If a reusable Designer behavior is missing, stop and extend the Base Foundation contract deliberately instead of adding a product-local workaround.

## External-consumer conformance

The public Designer contract must remain usable by a standalone extension that is not inside `.cb-core-wrap` and does not receive incidental Core Admin assets.

Before a Base Designer change is considered release-ready, source/regression coverage and field QC should verify at minimum:

- canonical launch control presentation outside `.cb-core-wrap`;
- `>1280`, `901–1280` and `≤900` shell geometry;
- light and dark presentation where the host provides supported Core Blueprint tokens/theme state;
- fullscreen, Escape and focus behavior;
- keyboard/focus order for canonical chrome;
- no private-handle dependency in consumer code;
- no full Core Admin theme leak onto a standalone WordPress admin page;
- no browser-console or PHP errors introduced by the integration.

A Base-owned Mail screen may remain a visual quality benchmark, but it is not sufficient by itself to prove the external public consumer boundary.
