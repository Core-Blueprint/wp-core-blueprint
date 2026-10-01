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

### Shell

The stable shell helpers are:

- `createDesignerShell(root, options)`
- `configureDesignerSidebar(root, configuration)`
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
CB\Core\Design\Editor\Assets::enqueue_designer_mode( __( 'Example Designer', 'example' ) );
```

See `DESIGNER-MODE.md` for the launch, responsive shell, toolbar and composition contract.

Designer Mode uses bounded readiness retries. Exhausting those retries is an explicit error state: Base must keep the failure visible and must not silently mark an incomplete launch or toolbar as initialized.

## Compatibility rule

This document is the v1 compatibility boundary. Existing members listed above may be fixed internally without changing consumer code. Removing or changing their consumer-visible semantics requires an explicit future API-version decision.

New additive APIs may be introduced during the v1 lifecycle, but they are not part of the frozen contract until documented here and covered by public-boundary regression tests.
