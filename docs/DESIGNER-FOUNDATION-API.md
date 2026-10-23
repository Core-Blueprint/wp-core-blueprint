# Core Blueprint Designer Foundation API

Status: **public v1 frozen contract + v2 SOURCE candidate; installed ZIP acceptance pending**.

**G1d-B v2 SOURCE candidate, installed ZIP acceptance pending:** transactional history, `captureSave()` and `rebase()` are implemented in both session factories and PHP `Assets::DOMAIN_SESSION_API` declares `2` on this candidate branch. This is a source capability, not acceptance of a built, installed artifact. Consumers must require the *same installed Base package* to provide PHP capability `>= 2`, executable WordPress-resolved ESM exports and consumer-specific integration acceptance. Existing v1 users remain supported. No consumer may import a low-level history engine as a substitute.

The Designer Foundation is the Base-owned editor engine and shell boundary for Core Blueprint designers. Consumers own domain semantics, persistence, validation and domain rendering. Base owns editor/session mechanics, shared shell behavior and the public integration boundary.

## Canonical browser module

The only supported browser module identifier for the Designer Foundation is:

```js
import {
	createSession,
	createDomainSession,
	createDesignerShell,
	createDesignerLayerTree,
	createDesignerSelectionController,
	createDesignerDragDropController,
	commands,
	profiles,
	animateLayoutChange,
} from '@cb-core/design-editor';
```

Consumers must not import files from `assets/js/design/` directly. The source tree below the public module is private implementation layout and may change without a consumer migration contract.

The equivalent global facade is `window.cbCore.designEditor`. Consumers should prefer the script-module boundary when they already run as modules. **Export parity:** the actual ESM named export is `animateLayoutChange`, not `motion`. The `motion` convenience object is exposed only at `window.cbCore.designEditor.motion` (including its `animateLayoutChange` method). Never import `{ motion }` from `@cb-core/design-editor`: that binding does not exist and prevents the whole WordPress module from loading. The canonical example above is exercised by the public editor's actual-module regression test.

## Frozen v1 consumer surface

### Sessions

`createSession(options)` is the canonical profile-bound editor-session factory.

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

Both methods live on the existing public session from `createSession({profile})` and preserve existing shell/navigation, history and document-profile semantics. They do not substitute for the separate public domain-neutral state/history session (P02) documented below. Public class exports such as ProjectState/EditorState/CommandHistory are not thereby promoted to consumer contracts.

### Domain-neutral sessions (P02, additive public v1 API)

`createDomainSession({ project, validateProject = null, allowCommand = null, onChange = null, historyLimit = 100 })` is the supported public session for non-document/non-mail consumers. No document/mail profile, evaluator-specific engine, selection model, or persistence is introduced.

- `project()` returns the frozen DesignProject; `snapshot()` clones it for consumer-controlled persistence. Only the DesignProject belongs in portable data.
- `replace(project, { source = 'consumer' })` validates and replaces the project. Replacement resets history by default. `validateProject(project)` is a synchronous domain-owned validator: throw on invalid data. Base additionally validates the DesignProject envelope.
- `execute({ label, apply(project) })` commits a domain-owned reversible transition. The command returns `{ project }`. `allowCommand(command, project)` may deny by returning `false`. Consumers must not mutate the frozen input.
- `undo()`, `redo()`, `clearHistory()`, `historyStatus()`, and `dispose()` are Base-owned, session-only mechanics. `historyStatus()` returns a frozen `{ canUndo, canRedo, size }` snapshot; never persist history.
- `subscribe(listener)` (with unsubscribe return) and `onChange(project, event)` observe **fully committed** project revisions. Project and selection observers read the final history stack synchronously. `subscribeHistory()` is implemented in the public v2 SOURCE candidate; installed artifact acceptance is pending. Do not schedule a microtask to compensate for intermediate history state.
- The shared Designer shell now requires a public v2-compatible facade with `historyStatus()`, `subscribeHistory()`, `subscribeDiagnostics()`, `undo()` and `redo()`, or explicit `null` for presentation-only mode. A `subscribe(listener)` project observer alone is **not** a history subscription. The shell does not read `.projectState` or `.history` and does not own the session lifecycle.
- Public PHP capability gate: `CoreBlueprint\\Core\\Design\\Editor\\Assets::DOMAIN_SESSION_API >= 1`. Public JS module and global facade expose `createDomainSession`. The low-level `ProjectState`, `EditorState`, and `CommandHistory` classes stay outside consumer contracts; the v1 profile-based `createSession()` is unchanged.

A consumer adopting P02 must install a compatible Base and extension together. This does not change which Evaluation data may be stored or transmitted.

### Designer v2 SOURCE candidate: shared sessions, immutable history and guarded saves (G1d-B)

This section describes the **v2 SOURCE candidate**, not a released or installed capability. The normative contract is G1a v2.1, with separate Automations and Evaluator owner sign-off. PHP declares v2 on this branch, but do not promote this paragraph into an installed consumer compatibility guarantee until PHP and WordPress-resolved ESM have been verified in the same approved release artifact.

**Both** `createDomainSession()` and `createSession({ profile: 'mail' | 'document-flow' | 'document-fixed' })` expose these common methods: `project()`, `snapshot()`, `replace()`, `execute()`, `undo()`, `redo()`, `historyStatus()`, `subscribeHistory()`, `subscribeDiagnostics()`, `clearHistory()`, `captureSave()`, `baselineSnapshot()`, `rebase()`, and `dispose()`. They use one Base history engine; no consumer-owned second history engine or `createDesignerStateHistory` alias is supported.

```js
const checkpoint = session.captureSave();
// Consumer saves only a validated, sanitized domain projection of
// checkpoint.submittedProject, with its OWN authorized document identity,
// server-side nonce/policy and authoritative persisted version/ETag.
//
// Only after the server confirms the matching successful write:
session.rebase({
  saveToken: checkpoint.token, // opaque, one-use, in-memory only
  baselineProject: serverAcceptedProject,
  source: 'save-baseline',
});
const dirtyProject = !sameDomainAuthoringValue(
  session.project(),
  session.baselineSnapshot(),
);
// Combine dirtyProject with separately persisted subject/settings/status fields.
```

Do not send the token over HTTP, persist it, or accept a caller-supplied `currentProject`. Base applies latest-issued-wins checkpoint CAS, validates session/context identity and preserves individual Undo/Redo steps after normal Save. Normalized server baselines require validated intent replay (and `verifyRebase` for concurrent edits) or fail closed with `SAVE_REPLAY_CONFLICT`, never silently truncating history. Other safe checkpoint errors: `SAVE_INVALID`, `SAVE_STALE`, `SAVE_CONTEXT_CHANGED`. Base does **not** guarantee server write ordering or authorize persistence; the consumer must implement server-side CAS/version checks and permissions.

`subscribeHistory(listener)` emits frozen `{action,source,revision,historyRevision,status}` **after** project and selection observers see the complete state; there is no initial event (call `historyStatus()` first). `subscribeDiagnostics(listener)` emits only `{code:'CB_DESIGNER_OBSERVER_FAILED',phase,recoverable:true}` for isolated callback failures: never exception stacks, project data, answers or secrets. Failed listeners cannot reverse a committed edit. Dispose the shell/controllers before disposing their consumer-owned session.

Historical source exports such as `ProjectState`, `EditorState`, `CommandHistory` and direct command functions **remain present pending a first-party import impact ledger and separate removal approval**. Their presence does not create public support, and their removal is not part of G1c. Base Mail and Reports preserve their present persistence and preview paths until owner-reviewed v2 save adapters are accepted; this source-capability work does not rewrite subject, email HTML, report snapshots, or server write policy.

### Designer Exit Guard v1 (SOURCE candidate)

`enqueue_designer_mode()` supplies the Base-owned `window.cbCore.designerExitGuard.register(shell, { isDirty, save, labels })` interface on same-build capability `DESIGNER_EXIT_GUARD_API >= 1`. Base owns all close/ESC/fullscreen confirmation, modal, single-flight and browser unload guarding; consumer Save must prove persistence and must not supply an alternate exit handler. See [Designer Exit Guard](DESIGNER-EXIT-GUARD.md). Installed acceptance is pending.

### Shell

The stable shell helpers are:

- `createDesignerShell(root, options)`
- `createDesignerNavigation(options)` — opt-in accessible left task/stage navigation (see [Designer Navigation](DESIGNER-NAVIGATION.md))
- `configureDesignerSidebar(root, configuration)`
- `DESIGNER_PALETTE_ROLES` — optional canonical left-role order: `navigation`, `elements`, `layers`
- `configureDesignerViewports(root, options)`
- `createDesignerInspectorIdentity(options)`
- `createDesignerInspectorControls(options)`
- `createDesignerInspectorToggle(options)`
- `createDesignerLayerTree(options)`
- `createDesignerLayerRow(options)`
- `projectDesignerLayerInteractions(options)` — shared, policy-driven command affordance projection
- `createDesignerSelectionController(options)`
- `createDesignerIcon(name, options)`
- `decorateDesignerControl(control, name, options)`
- `decorateDesignerElement(control, type, { label, icon })` — Base-owned canonical element icon mapping and curated fallback

The global facade exposes the same capabilities under `window.cbCore.designEditor.shell`.

Navigation is available as `window.cbCore.designEditor.shell.navigation.create` and the frozen extension as `shell.paletteRoles`. Navigation remains distinct from Layers and is not an editor profile.

### Shared Drag & Drop Foundation DND-01 (SOURCE candidate)

The public named ESM export is `createDesignerDragDropController` from
`@cb-core/design-editor`. The equivalent facade is
`window.cbCore.designEditor.shell.dragDrop.createController`.

**Ownership:** Base owns the gesture, registered DOM source/zone lifecycle,
drop indicator attributes, pointer/keyboard operation and stale-context guard.
The consumer owns semantic drop resolution, legal parent/child restrictions,
permission checks and a single synchronous command dispatch via the existing
Domain Session or native Host EditorFacade. The controller never mutates the
project, History, Save Authority, or a consumer's domain state directly.

```js
const dnd = createDesignerDragDropController({
  root: designerShellRoot,
  isReady: () => editorIsReady(),
  // Stable actor/site/resource/profile/Host-context identity. Change this when
  // switching the active document, user, or authorized Host context.
  getContextKey: () => activeContextKey(),
  resolveIntent: ({source, zoneId, edge}) =>
    domainCanvas.resolveDrop({source, zoneId, edge}),
  canDrop: ({source, intent}) =>
    domainCanvas.isAllowedDrop({source, intent}),
  commit: ({source, intent}) =>
    domainCommands.executeDrop({source, intent}),
});
const releaseSource = dnd.registerSource(paletteButton, {
  id: 'vendor/heading', type: 'vendor.heading', kind: 'palette',
});
const releaseZone = dnd.registerZone(canvasRegion, {id: 'section/1'});
// On renderer update: release old zones, register the new DOM/proxy zones.
// On Host context exit: releaseSource(); releaseZone(); dnd.destroy();
```

The five extension capabilities are expressible through the one Base-owned
controller, without importing private Base modules:

1. **Drag-source registration:** `registerSource(element,{id,type,kind,path?})`.
   `kind` is `palette` (new element) or `node` (existing project path).
2. **Dropzone provider:** `registerZone(element,{id})` on an actual DOM zone or
   an adapter-owned positioned proxy.
3. **Domain drop policy:** synchronous `resolveIntent({source,zoneId,edge})`
   and `canDrop({source,intent})`. Null/false, an error or asynchronous result
   refuses the gesture. Both callbacks are advisory, not a Save authorization.
4. **Canvas adapter:** maps rendered nodes to zone IDs and adapts the
   `before|inside|after` edge. A secured iframe must publish only bounded,
   authenticated placement geometry to its adapter; it must not execute
   project mutations or receive unrestricted document commands.
5. **Command adapter:** `commit({source,intent})` synchronously dispatches
   exactly one existing, domain-authorized registered command. It must throw
   on refusal; the controller never writes directly or creates a History stack.

**Safety and lifecycle:** Pointer drags are accepted only after starting from
a registered source in the same Designer root. Text in `DataTransfer` is not
an identity or authorization token. On drop, the controller re-evaluates the
active context, source, target and consumer policy. A changed Host context
invalidates the gesture. Every successful gesture commits at most once.
Unregister/destroy clears handlers and drop-indicator attributes.
`getContextKey()` must return a bounded, nonempty ID using letters, digits,
`._:/-`; it must change after switching the authorized actor/site/resource or
Host context, not merely after hovering/selection.

**Keyboard:** Alt+ArrowDown picks up a focused registered source, Tab moves to
focusable zones, ArrowUp/ArrowDown chooses before/after, Enter or Space commits,
and Escape cancels. Ordinary palette Enter activation is preserved.

**Status:** DND-01 makes the core API available to public consumers; it does
not yet wire native Mail/Reports palette buttons to their canvases. DND-02
provides their DOM/isolated-preview adapters, dropzone geometry and visual
polish. DND-03 adds touch/pointer-specific gestures, auto-scroll and complex
moving/nesting flows. Do not mark installed functional acceptance until those
consumer runtime paths pass. Existing Layers drag/reorder remains unchanged.

### Shared Layers interactions (Mail, Reports and future profiles)

**Foundation ownership:** Base owns the Layers tree, icon-only hover actions,
right-click/three-dot/keyboard context menu, accessible focus, drag-and-drop,
nested-delete confirmation and Undo/Redo command integration. Consumers MUST
reuse this primitive rather than implement product-local Layers toolbars.

The public API exposes `projectDesignerLayerInteractions(options)` and
`window.cbCore.designEditor.shell.layers.projectInteractions`. The trusted
profile module implements `layerPolicyFor(node, parent, index)`, returning
`{ remove: boolean, reorder: null | { minIndex, maxIndex }, visibility: null |
{ key, shownValue, hiddenValue } }`. Base validates these bounds and
dispatches only the already-registered `*.remove`, `*.reorder` and `*.set`
commands through the active Host EditorFacade. Schema validation, command
authorization, History and Save Authority remain canonical and unchanged.

- Mail: ordinary sections/content can be moved and deleted; fixed two-column
  children cannot be individually moved or deleted. No Hide/Show is shown
  until Mail gains a genuine persisted visibility field and rendering policy.
- Reports: Header/Footer stay anchored; regular and nested sections obey
  bounded sibling reordering. Only elements with a valid persisted `enabled`
  field expose Hide/Show.
- Deletion of a parent with descendants always requires explicit confirmation.
  No one-click delete action belongs in the canvas or Inspector.
- The shared overlay never permanently reserves horizontal label space;
  left and right Designer rails/drawers are capped at 260px.

No domain-specific layer action assembly belongs in the Host.

### Designer Sidebar Layout & Iconography Standard v1 (canonical SOURCE contract; installed acceptance pending)

**Authority decision, 21 October 2026:** the Base Mail source layout, `DESIGNER-LAYOUT-CONTRACT.md` and the public palette/sidebar factories define the canonical first-party integration target. This resolves the former right-hand Layers / separate Dynamic Data ambiguity. It does **not** promote an untested consumer build or Base v2 history candidate to an installed/released capability. Consumers pin and verify the exact Base PHP/ESM/CSS package before browser acceptance.

- Left rail: **Elements** and, only when supplied, **Layers**. Set `data-cb-design-shell-group="palette"` on matching tab and panel. Set `data-cb-design-shell-palette-role="elements|layers"` on tabs. Base owns tab behavior/icons; consumers own panel contents and selection.
- Right rail: **Inspector** and optional **Settings**. Map them with `data-cb-design-shell-sidebar-role="inspector|settings"`. Selecting a Layer must update Inspector context without switching or hiding the right panel.
- **Dynamic Data is a titled section inside the Elements panel**, not a separate clickable tab. Render it only when tokens exist. Use the Base `braces` glyph beside its heading. Token values, copy behavior, validation and policy remain consumer-owned.
- **Horizontal scrolling is prohibited in both Designer rails and their active panels**. Base bounds composed panel-body grids to `minmax(0, 1fr)`, constrains palette cards to their column, and permits only vertical panel scrolling. Consumers must not add min-content or fixed-width descendants that expand rails.
- **Dynamic Data tokens appear strictly one per row**. Bindings use a single `minmax(0, 1fr)` column; each copy button remains within 100% available width. Long tokens use a one-line ellipsis, with the complete token in `title` and `aria-label`. Elements cards may retain two equal columns, but their labels must truncate within their column and expose the full label on hover.
- **Mail canvas uses headerless white editing chrome**. Base removes the redundant Mail canvas heading and scopes the white outer-page override to the loaded editor iframe only. Desktop/tablet/mobile preview widths remain responsive; delivered email HTML and saved page/content background settings are not rewritten.
- Base decorates the left tabs with `shapes`/ `layers` icons and wraps their labels in `.cb-core-design-shell__tab-label`. Shared right tabs use the existing Inspector/Settings icons. All click targets retain visible text and accessible full labels.
- **Every sidebar tab label is one word.** CSS always keeps tabs to one line, with `text-overflow: ellipsis` as an exception fallback for accidental long labels or translations. Full labels remain available through `aria-label` and `title`; icons never shrink.
- Use `decorateDesignerElement(control, semanticType, { label, icon })` for palette items. Base chooses `heading-1`, `align-left`, `image`, `mouse-pointer-click` or safe `shapes` fallback; a recognized curated icon may be requested by a consumer. No private icon module imports.
- Designers without Layers (for example Automations) **do not render a Layers tab**. Optional data and actions stay absent rather than producing placeholders.
- A separate Dynamic Data tab, right-hand Layers, and consumer-owned replacements for Base rails **fail the canonical source layout**. This contract decision does not change extension markup automatically. Newsletter, Reports, Evaluator and other consumers require their own version-pinned integration checks and explicit acceptance; no Base-only source test can certify their installed behavior.
- A Layers click in the left palette keeps Layers active while updating the right Inspector. For profile-bound sessions, `createDesignerSelectionController()` supplies source-aware selection and synchronization. Domain-neutral `createDomainSession()` does not expose the profile-bound selection API; its consumers must implement domain selection mapping while reusing Base Layers and Inspector presentation.



A shell controller returned by `createDesignerShell()` is idempotent per root/session pair and exposes `destroy()`. Consumers that dispose or replace a Designer instance must release their shell/selection controllers rather than layering a second controller over the same DOM root.

Inspector identity is also Base-owned. Consumers must pass the same semantic element/block label they expose in Layers to `createDesignerInspectorIdentity()` rather than inventing product-local title markup. Boolean Inspector settings use `createDesignerInspectorToggle()` inside `createDesignerInspectorControls()`; this guarantees one setting per row and preserves the shared title/control hierarchy.

### Mail profile rich-text controls (additive candidate)

The public `profiles.mail.createMailRichTextControls({ documentRef, value, onCommit })`
factory creates Base-owned, allowlisted Rich Text editor controls with the same
Visual / Advanced source, selection toolbar and HTML sanitization used by the
built-in Base Mail Designer. Its return value exposes `{ element, commit, editor }`.
The consumer mounts `element` in the Inspector, performs the authorized
`commands.setProperty(path, ['markup'], nextValue)` in `onCommit` and
returns `false` if the edit was rejected. The server remains authoritative
for rendering and policy. Consumers must not import private Mail JS modules
or implement a competing rich-text renderer.

For the Base Mail canvas, `profiles.mail.createMailInlineTextController({ session,
executeProperty, select, onStatus, onEditingChange })` is exposed on the same
public profile object. It preserves the existing selection/inline draft lifecycle,
but is **not** an independent extension editing framework. First-party consumers
must use the public Mail profile factory instead of importing
`assets/js/design/mail/inline-text.js` or other private Mail implementation files.

The matching opt-in PHP style boundary is
`CoreBlueprint\\Core\\Design\\Editor\\Assets::enqueue_mail_rich_text_controls()`.
This enqueues Base-owned Mail editor styling and the public Design Editor
module without loading Base's Mail template management UI. The Mail profile
factory and stylesheet are an **additive v1 candidate** pending real browser,
keyboard, save/reopen and first-party consumer integration acceptance.

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

The Flow Preview Host accepts an optional `onFirstSizeTimeout` callback. It fires only after a valid preview render fails to receive its first accepted sizing handshake within the bounded timeout; the failed generation stays inert. A consumer may request a fresh render, but must bound retries to avoid loops and must never treat the callback as success. Invalid HTML, stale sizing messages and unsuccessful AJAX responses must remain fail-closed. Reports attempts at most one fresh preview render per Designer opening and only while fullscreen is active.

Profile-specific rendering or document contracts remain governed by their corresponding Foundation documentation.

### Motion

The named ESM export `animateLayoutChange` is the supported Base-owned layout-motion helper. The equivalent global facade exposes it as `window.cbCore.designEditor.motion.animateLayoutChange`; `motion` is **not** an ESM named export. Consumers must not duplicate the shared Designer motion rules locally or import a private motion module.

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

## Base Designer notification contract (shared Toast presentation)

Use the Base-owned Toast Foundation for transient success, error, warning and information feedback. The shared Designer Mode presentation maps the public `cb:design-shell:savechange` event to a Toast only on `saved` or `error`. `saving` and `idle` continue to control busy states without showing a notification. Pass the authoritative outcome in the event; never show a second consumer Toast for the same Save, and never dispatch `saved` merely because an HTTP response succeeded.

```js
shell.dispatchEvent(new CustomEvent('cb:design-shell:savechange', {
  detail: { state: 'saved' }, // only after durable receipt and safe history rebase
}));
```

Consumers that need preview failure notifications use the existing `window.cbCore.toast.error(message)` API, not toolbar text or private Toast imports. The Designer Mode asset helper enqueues Toast presentation. Other standalone screens explicitly enqueue `CoreBlueprint\\Core\\UI\\Assets::enqueue_toasts()` with the appropriate adapter and depend on `@cb-core/toast`. In Designer fullscreen, Toasts stack above the editor. Dirty state, Save progress, field validation and unrecoverable launch errors remain their own accessible persistent UI. See `docs/TOAST-FOUNDATION.md` for the full normative standard and adoption tests.
