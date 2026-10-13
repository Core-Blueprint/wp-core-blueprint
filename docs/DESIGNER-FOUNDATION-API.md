# Core Blueprint Designer Foundation API

Status: **public v1 frozen contract**.

The Designer Foundation is the Base-owned editor engine and shell boundary for Core Blueprint designers. Consumers own domain semantics, persistence, validation and domain rendering. Base owns editor/session mechanics, shared shell behavior and the public integration boundary.

## Canonical browser module

The only supported browser module identifier for the Designer Foundation is:

```js
import {
	createSession,
	createDesignerShell,
	createDesignerLayerTree,
	createDesignerSelectionController,
	commands,
	profiles,
	motion,
} from '@cb-core/design-editor';
```

Consumers must not import files from `assets/js/design/` directly. The source tree below the public module is private implementation layout and may change without a consumer migration contract.

The equivalent global facade is `window.cbCore.designEditor`. Consumers should prefer the script-module boundary when they already run as modules.

## Frozen v1 consumer surface

### Sessions

`createSession(options)` is the canonical editor-session factory.

Supported v1 session methods and state accessors are:

- `project()`
- `snapshot()`
- `replace(project, options)`
- `selection()`
- `setSelection(paths, options)` (additive v1, atomic multi-path selection)
- `historyStatus()` (additive v1, frozen undo/redo/size snapshot)
- `select(path, options)`
- `clearSelection(options)`
- `subscribeSelection(listener, options)`
- `execute(command)`
- `undo()`
- `redo()`
- `validate()`
- `inspector()`
- `handleShortcut(event, options)`
- `dispose()`

The session owns project validation against the selected profile, command history and editor-only selection/validation state. Consumers must not persist editor-session state into their domain document.

### Additive v1 public selection and history snapshots (integration candidate, 2026-10-11)

`session.historyStatus()` returns a frozen, non-persisted `{ canUndo, canRedo, size }` snapshot of the committed history stack. It does not expose mutable history implementation details. Call again after command/undo/redo/project replacement to obtain current state. After session disposal the method fails closed.

`session.setSelection(paths, { primary = null, source = 'consumer', external = false })` atomically updates transient multi-path selection. Paths are arrays of non-negative integer child indices; duplicates are removed preserving first occurrence. If no primary is given, the last unique path becomes primary. An **explicit primary must be one of the selected paths**, or the method throws a `RangeError` before mutation, with no event. This tightens D3's earlier invalid-primary semantics: Base never silently discards a requested primary. A successful change publishes exactly one `action: 'set'` selection event; unchanged selection publishes none. Invalid path shapes never partially mutate selection.

**Default `external: false` is fail-closed:** every selected path must reference a current node in the DesignProject. **Explicit `external: true`** permits a consumer-owned transient mapping of external domain element IDs to selection path indices (for example, Certificates). It relaxes only project-node existence validation, not path shape, primary membership, profile validity, project validation, command authorization or persistence. External selections must not grant permission, and consumers own stable ID mapping/reconciliation after reorder/removal/undo/redo/project replacement; Base commands may reconcile or clear a nonproject selection.

Both methods live on the existing public session from `createSession({profile})` and preserve existing shell/navigation, history and document-profile semantics. They **do not** provide Evaluator's proposed domain-neutral state/history session (P02), which remains separate. Public class exports such as ProjectState/EditorState/CommandHistory are not thereby promoted to consumer contracts.

### Domain-neutral state history (additive v1)

`createDesignerStateHistory({ initialState, onApply, limit = 100 })` is the
public transient history adapter for JSON-safe domain models without a DesignProject
profile. The global facade is `window.cbCore.designEditor.createStateHistory`.
Consumers own validation, authority and persistence; Base owns history semantics.

The adapter exposes `record(state)`, `undo()`, `redo()`,
`reset(baseline, { current = null })`, `snapshot()`, `status()`,
and `dispose()`. The frozen status reports `canUndo`, `canRedo`, and `size`.
The bounded stack is never persisted. `onApply` is called before advancing the
history cursor, so a failed render cannot consume a history entry. Reset when
switching document contexts so undo cannot cross two workflows.

### Shell

The stable shell helpers are:

- `createDesignerShell(root, options)`
- `createDesignerNavigation(options)` — opt-in accessible left task/stage navigation (see [Designer Navigation](DESIGNER-NAVIGATION.md))
- `configureDesignerSidebar(root, configuration)`
- `DESIGNER_PALETTE_ROLES` — optional canonical left-role order: `navigation`, `elements`, `dynamic-data`
- `configureDesignerViewports(root, options)`
- `createDesignerInspectorIdentity(options)`
- `createDesignerInspectorControls(options)`
- `createDesignerInspectorToggle(options)`
- `createDesignerLayerTree(options)`
- `createDesignerLayerRow(options)`
- `createDesignerSelectionController(options)`
- `createDesignerIcon(name, options)`
- `decorateDesignerControl(control, name, options)`

The global facade exposes the same capabilities under `window.cbCore.designEditor.shell`.

Navigation is available as `window.cbCore.designEditor.shell.navigation.create` and the frozen extension as `shell.paletteRoles`. Existing palettes are unchanged unless a consumer opts in; Navigation is not Layers or a new editor profile.

A shell controller returned by `createDesignerShell()` is idempotent per root/session pair and exposes `destroy()`. Consumers that dispose or replace a Designer instance must release their shell/selection controllers rather than layering a second controller over the same DOM root.

Inspector identity is also Base-owned. Consumers must pass the same semantic element/block label they expose in Layers to `createDesignerInspectorIdentity()` rather than inventing product-local title markup. Boolean Inspector settings use `createDesignerInspectorToggle()` inside `createDesignerInspectorControls()`; this guarantees one setting per row and preserves the shared title/control hierarchy.

### Commands

`commands` is the supported command namespace. V1 exposes:

- `commands.insertNode`
- `commands.removeNode`
- `commands.reorderNode`
- `commands.setProperty`

Consumers own authorization and policy through the session `allowCommand` callback. They must not mutate the project behind the session in order to bypass command/history semantics.

### Profiles

The stable profile identifiers are:

- `document-flow`
- `document-fixed`
- `mail`

Consumers select a profile through `createSession({ profile })`. Profile APIs are available through `profiles[profileId]` and `session.profile.api`.

For the public `document-flow` profile, `createFlowPreviewHost` is part of the v1 consumer boundary. Maintenance Reports and other first-party flow consumers must obtain it through `@cb-core/design-editor`, never through a private relative Design path.

Profile-specific rendering or document contracts remain governed by their corresponding Foundation documentation.

### Motion

`motion` is the stable shared motion namespace and exposes the Base-owned layout-motion contract. Consumers must not duplicate the shared Designer motion rules locally.

## Public boundary versus implementation exports

The public module may export additional low-level classes or helpers because Base itself composes the Foundation from those pieces. Their presence as JavaScript exports does **not** make them a supported extension contract.

In particular, consumers must not couple themselves to `ProjectState`, `EditorState`, `CommandHistory`, private tree helpers or files below `assets/js/design/`. Consumer integration goes through the frozen surface listed in this document.

First-party Core Blueprint consumers follow the same rule as third-party consumers. A first-party plugin is not permitted to bypass the public boundary merely because it shares the Core Blueprint namespace.

## Designer Mode

The canonical launch/fullscreen/composition experience is loaded through:

```php
CoreBlueprint\Core\Design\Editor\Assets::enqueue_designer_mode( __( 'Example Designer', 'example' ) );
```

See `DESIGNER-MODE.md` for the launch, responsive shell, toolbar and composition contract.

Designer Mode uses bounded readiness retries. Exhausting those retries is an explicit error state: Base must keep the failure visible and must not silently mark an incomplete launch or toolbar as initialized.

## Compatibility rule

This document is the v1 compatibility boundary. Existing members listed above may be fixed internally without changing consumer code. Removing or changing their consumer-visible semantics requires an explicit future API-version decision.

New additive APIs may be introduced during the v1 lifecycle, but they are not part of the frozen contract until documented here and covered by public-boundary regression tests.
