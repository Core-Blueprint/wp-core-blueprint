# Core Blueprint Foundation v1 Contract

Status: **public v1 freeze candidate**.

## Presentation boundary

- Pages below the Core Blueprint admin menu use Core Admin presentation and `--cb-*` tokens.
- Standalone WordPress admin pages remain WordPress-native and may opt into Foundation behavior through the public enqueue helpers.
- Loading Core tokens alone must never be used to infer that a standalone screen is a Core Admin screen.

## Public behavior/runtime primitives

| Primitive | PHP enqueue | Script-module/runtime |
| --- | --- | --- |
| Icons | `CB\Core\UI\Assets::enqueue_icons()` | `@cb-core/icon`, `window.cbCore.icon` |
| Toast | `CB\Core\UI\Assets::enqueue_toasts()` | `@cb-core/toast`, `window.cbCore.toast` |
| Modal | `CB\Core\UI\Assets::enqueue_modals()` | `@cb-core/modal`, `window.cbCore.modal` |
| Clipboard | `CB\Core\UI\Assets::enqueue_clipboard()` | `@cb-core/clipboard`, `window.cbCore.clipboard` |
| Token Input | `CB\Core\UI\Assets::enqueue_token_inputs()` | `@cb-core/token-input`, `window.cbCore.tokenInput` |
| Time Picker | `CB\Core\UI\Assets::enqueue_time_picker()` | `@cb-core/time-picker`, `window.cbCore.timePicker` |
| Icon Picker | `CB\Core\UI\Assets::enqueue_icon_picker()` | `@cb-core/icon-picker`, `window.cbCore.iconPicker` |
| Capability Picker | `CB\Core\UI\Assets::enqueue_capability_picker()` | `@cb-core/capability-picker`, `window.cbCore.capabilityPicker` |
| Choice Group | `CB\Core\UI\Assets::enqueue_choice_group()` | PHP/CSS primitive; no JavaScript runtime required |
| Object Picker | `CB\Core\UI\Assets::enqueue_object_picker()` | `@cb-core/object-picker`, `window.cbCore.objectPicker` |
| Select Picker | `CB\Core\UI\Assets::enqueue_select_picker()` | `@cb-core/select-picker`, `window.cbCore.selectPicker` |
| Form Composition | `CB\Core\UI\FormComposition::enqueue()` | PHP/CSS primitive; no JavaScript runtime required |

Consumers provide business meaning and exact values. Foundation owns generic behavior, accessibility and presentation adapters.

The Modal Foundation includes the additive public `confirmCheck: { label }` option. Presence means a required, initially unchecked native acknowledgement checkbox. `confirmCheck` is orthogonal to the existing confirm/typed/input modes, never changes their resolved value, and composes with other gates so Confirm is available only when every active gate is valid. Invalid or empty labels fail closed; see `MODAL-FOUNDATION.md`.

`Stack` is the shared vertical composition primitive (`.cb-core-stack`, plus compact/loose spacing variants). `Field` and Stack share one semantic markup contract across Core Admin and standalone WordPress admin screens. Standalone consumers use the narrow WordPress-native Form Composition adapter so WordPress keeps ownership of native controls, colours and chrome while Base owns only field grouping and vertical rhythm; see `FORM-COMPOSITION-FOUNDATION.md`. Child components keep ownership of their internal geometry. `Form Controls` owns the Core-scoped native file-input and `::file-selector-button` presentation, so modules must not redraw upload controls locally.

## Design Editor and Designer Mode

The shared Design Foundation has two public consumption levels:

- `CB\Core\Design\Editor\Assets::enqueue()` loads the Design Editor engine/shell contracts and `@cb-core/design-editor` for embedded or consumer-composed editor experiences.
- `CB\Core\Design\Editor\Assets::enqueue_designer_mode()` adds the canonical Base-owned Designer Mode launch, header/chrome composition, responsive shell geometry and focus/fullscreen lifecycle.

The semantic `design-editor` requirement maps to the engine/shell level. Consumers that want the canonical fullscreen Designer experience call `enqueue_designer_mode()` rather than rebuilding that chrome themselves.

Designer Mode is self-contained for the Base presentation it generates. A standalone WordPress admin consumer does not add `.cb-core-wrap`, import private Base handles or load the full Core Admin theme as a workaround. Base may load narrow shared primitives, such as the Button Foundation, when Designer chrome requires them.

The canonical Designer responsive contract is `>1280px` three columns, `901–1280px` two columns with the sidebar below, and `≤900px` one vertical column. Consumers own content inside palette/canvas/sidebar slots but must not replace the shell grid, fullscreen geometry, panel rails or canonical Designer toolbar presentation.

The normative public contract is `DESIGNER-MODE.md`.

## Document Flow rendering

`CB\Core\Design\Profile\Document\Flow\Api\FlowRenderApi` is the public v1 facade for external consumers that render typed Flow documents.

`FlowRenderApi::preview_html()` returns a safe continuous screen target for isolated Designer/document-canvas previews. `FlowRenderApi::pdf()` returns the authoritative paged PDF output for the same typed layout, `RenderBlock` list, locale and optional `Presentation`. `FlowRenderApi::is_pdf_available()` reports PDF-backend availability; screen preview rendering does not depend on that backend.

The public typed Flow rendering vocabulary is `RenderBlock`, `TableColumn`, `Presentation` and the exact root-owned Flow layout contract. Consumers must not depend on `HtmlRenderer`, `PdfRenderer`, Dompdf or other backend classes merely because their methods are technically public.

Screen preview deliberately does not simulate PDF pagination/page counters. PDF remains authoritative for page breaks and fixed per-page footer behavior. The normative contract is `DOCUMENT-FLOW-RENDERING.md`.

## Core Admin component contracts

The frozen component layer includes Button, Badge, StateBadge, Status, Notice, Busy, Field, Form Controls, Stack, CheckRow, ChoiceGroup, ObjectPicker, SelectPicker, Toolbar, Disclosure, MasterSwitch, ChoiceCard/RadioCard, Empty State, Overview cards, metric tiles, Integration Grid, Detail Rows, KV Table and Scrollbar.

`IntegrationGrid` is the Base-owned presentation primitive for integration/readiness cards on registered Core Admin pages. Consumers own detection/readiness semantics, labels and action destinations; Base owns card/grid presentation and maps the public `ready|needs-setup|optional|unavailable` states to the existing Status primitive. The normative contract is `INTEGRATION-GRID-FOUNDATION.md`.

`DetailRows` is the Base-owned presentation primitive for compact object/target/resource rows composed inside a consumer-owned card or section. Consumers own row inventory, domain meaning, readiness logic, labels, destinations and surrounding guidance; Base owns row anatomy, responsive presentation and composition with the existing Status/Button primitives. Detail Rows uses the existing generic Status semantics directly and does not extend Integration Grid with nested target semantics. The normative contract is `DETAIL-ROWS-FOUNDATION.md`.

`StatusMenu` is a Base-owned Core Admin composition primitive for compact status + action popovers (currently used by Dashboard cards). It is intentionally not a public extension enqueue contract: sibling extensions contribute declarative Dashboard shortcuts through `CardRegistry`, while Base owns rendering and interaction.

Page CSS may own composition (grid, section rhythm, task-specific preview sizing) but must not visually clone one of these components.

### Public Core Admin Design Foundation semantics

Registered Core Admin extension pages consume shared visual primitives through `PageRegistry` component IDs, not asset handles. The public v1 semantic set is `nav-tabs`, `panels`, `cards`, `metric-tiles`, `integration-grid`, `detail-rows`, `notices`, `fields`, `radio-cards`, `master-switch`, `disclosure`, `badges`, `state-badges`, `status`, `empty-state`, `kv-table`, `form-controls` and `description-toggle`. The Integration Grid contract is normative in `INTEGRATION-GRID-FOUNDATION.md`; the Detail Rows contract is normative in `DETAIL-ROWS-FOUNDATION.md`; the remaining minimal markup/behavior contracts are normative in `CORE-ADMIN-DESIGN-FOUNDATION.md`. Base remains free to change internal CSS filenames, handles, bundle boundaries and implementation details.

Base owns canonical appearance for these primitives. First-party extension styles may position or compose them, but may not duplicate their generic surfaces, colour, typography, borders, radii, spacing, shadows, focus, hover or state styling. A deliberately different product-specific component or variant remains extension-owned and should use Base tokens where practical.

Design ownership does not imply universal loading. The minimal Core Admin shell remains narrow; a page receives an optional primitive only when it declares that semantic requirement (or when a Base-owned screen manifest selects it).

## Settings Hub Foundation

`CB\Core\Admin\SettingsRegistry` is the public v1 boundary for extension configuration contributed to **Core Blueprint → Extensions**. The Settings Hub keeps extension configuration centralized without adding one flat Core Blueprint submenu item per extension. **Extensions** is the final Base-owned submenu item and remains distinct from Base's separate **Preferences** surface. Operational extension administration remains in the extension's own appropriate workspace.

A provider registers during `cb_core_register_settings` and refers to an existing `ExtensionRegistry` ID. Base owns routing, capability filtering, developer/provenance attribution, grouping, shell presentation and semantic shared-UI requirements. The extension owns its settings fields, save/validation logic, domain semantics and inner renderer.

Developer identity is always visible. Base derives it from the registered plugin identity; providers cannot submit their own developer identity or first-party/official flag. First-party provenance uses the reserved `core-blueprint-*` ExtensionRegistry invariant. Every other provider is presented as third-party, and the individual settings surface explicitly states that support belongs to that developer rather than Core Blueprint.

Provider assets remain declarative: `requirements` uses the same public Foundation/component vocabulary as `PageRegistry`, and only the selected provider's requirements are loaded. Private Base handles, filenames and bundle boundaries remain internal.

The canonical routing helper is `SettingsRegistry::url()`. Provider-owned query arguments such as `tab` may be added, while Base-owned `page` and `extension` routing remain authoritative. The internal route remains `core-blueprint-settings`; only the user-facing navigation label is **Extensions**.

The normative contract is `SETTINGS-HUB-FOUNDATION.md`.

## Compatibility

- Historical Toolbar aliases (`cb-core-filter-bar*`) remain supported for backwards compatibility, but Base itself uses `cb-core-toolbar*`.
- Legacy `Tile::quick` remains available for backwards compatibility; new navigation surfaces should use the formal navigation/status tile variants or Overview navigation cards as appropriate. The public `metric-tiles` semantic exposes only the current generic metric/KPI tile contract and does not promote those legacy/navigation variants.
- Modal confirm presentation uses only the canonical `confirmVariant` option.

## Release guardrails

A Base release is blocked when any of the following fail:

- plugin ZIP root is not exactly `core-blueprint/`;
- plugin basename changes from `core-blueprint/core-blueprint.php`;
- packaged source differs from the validated worktree;
- PHP/JS/CSS validation fails;
- a public Foundation dependency is referenced without being registered/enqueued on that route;
- a standalone wp-admin consumer receives Core Admin presentation unintentionally.