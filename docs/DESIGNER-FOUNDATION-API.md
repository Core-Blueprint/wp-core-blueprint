# Core Blueprint Designer Foundation API

Status: **Designer Foundation v2 SOURCE contract; B2 Shared Selection Foundation under independent SOURCE validation. Installed ZIP acceptance pending**.

**G1d-B v2 SOURCE candidate, installed ZIP acceptance pending:** transactional history, `captureSave()` and `rebase()` are implemented in both session factories and PHP `Assets::DOMAIN_SESSION_API` declares `3` on this B2 candidate branch. This is a source capability, not acceptance of a built, installed artifact. Consumers must require the *same installed Base package* to provide PHP capability `>= 3`, executable WordPress-resolved ESM exports and consumer-specific integration acceptance. No deprecated selection authority or compatibility shim is provided. No consumer may import a low-level history engine as a substitute.

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
	createDesignerCanvasNavigation,
	createDesignerContextMenu,
	resolveDesignerDropPlacement,
	designerDropZoneId,
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

### Domain-neutral sessions (canonical public v2 API)

`createDomainSession({ project, validateProject = null, allowCommand = null, onChange = null, verifyRebase = null, resolveSelectionEntity = null, historyLimit = 100 })` is the supported public session for non-document/non-mail consumers. No document/mail profile, evaluator-specific engine, second selection state, or persistence is introduced.

- `project()` returns the frozen DesignProject; `snapshot()` clones it for consumer-controlled persistence. Only the DesignProject belongs in portable data.
- `replace(project, { source = 'consumer' })` validates and replaces the project. Replacement resets history by default. `validateProject(project)` is a synchronous domain-owned validator: throw on invalid data. Base additionally validates the DesignProject envelope.
- `execute({ label, apply(project) })` commits a domain-owned reversible transition. The command returns `{ project }`. `allowCommand(command, project)` may deny by returning `false`. Consumers must not mutate the frozen input.
- `undo()`, `redo()`, `clearHistory()`, `historyStatus()`, and `dispose()` are Base-owned, session-only mechanics. `historyStatus()` returns a frozen `{ canUndo, canRedo, size }` snapshot; never persist history.
- `subscribe(listener)` (with unsubscribe return) and `onChange(project, event)` observe **fully committed** project revisions. Project and selection observers read the final history stack synchronously. `subscribeHistory()` is implemented in the public v2 SOURCE candidate; installed artifact acceptance is pending. Do not schedule a microtask to compensate for intermediate history state.
- The shared Designer shell now requires a public v2-compatible facade with `historyStatus()`, `subscribeHistory()`, `subscribeDiagnostics()`, `undo()` and `redo()`, or explicit `null` for presentation-only mode. A `subscribe(listener)` project observer alone is **not** a history subscription. The shell does not read `.projectState` or `.history` and does not own the session lifecycle.
- Public PHP capability gate: `CoreBlueprint\\Core\\Design\\Editor\\Assets::DOMAIN_SESSION_API >= 3`. Public JS module and global facade expose `createDomainSession`. The low-level `ProjectState`, `EditorState`, and `CommandHistory` classes stay outside consumer contracts; the v1 profile-based `createSession()` is unchanged.

A consumer adopting P02 must install a compatible Base and extension together. This does not change which Evaluation data may be stored or transmitted.

### B2 Shared Selection Foundation v1.1 — Typed Domain Sessions (SOURCE candidate)

The *same* Base `EditorState.selection` used by CommandHistory is the only transient Selection authority. B2 does not change native Mail/Reports Host admission, profile-bound Selection, Save authority or the schema generation-1 trust boundary. This is not an installed WordPress/browser PASS.

```js
const session = createDomainSession({
  project: validatedDomainProject,
  validateProject(project) { validateDomain(project); },
  resolveSelectionEntity(ref, currentProject) {
    // Pure synchronous membership in the CURRENT validated project only.
    // Return exactly true for one unambiguous typed identity.
    return resolveUniqueDomainIdentity(ref, currentProject) === true;
  },
});
const rendered = session.selection(); // frozen {refs, primary, revision, contextEpoch}
const epochOfRenderedUI = rendered.contextEpoch;
session.select([{kind:'entity', type:'automations.action', id:'send_email'}],
  {kind:'entity', type:'automations.action', id:'send_email'},
  {expectedEpoch: epochOfRenderedUI, source:'ui'});
session.select([], null, {expectedEpoch: epochOfRenderedUI, source:'ui'});
const release = session.subscribeSelection((selection, {event, projectRevision}) => {
  renderDomainSelection(selection, event, projectRevision);
}, {emitCurrent:true});
release();
```

**Strict refs.** Exactly `{kind:'node',path:[...]}` for an existing project-tree node, or `{kind:'entity',type,id}` for a unique resolver-confirmed domain object. Base checks bounds and exact keys, primary membership and duplicates *before* mutation. A duplicate ref is a refusal, never silent deduplication. A missing resolver, wrong type, missing/ambiguous entity, exception, Promise or any non-`true` resolver result fails closed. The resolver grants no DOM, network, Command, Save, REST or native Host authority. Transient Evaluator editor keys remain distinct from persistent evaluation IDs. Automations selects by stable `step_id` / `condition_id`, never array index or DOM identity. Inventory subitems require their own unique consumer membership proof, otherwise only the owning Question may be selected.

**Epoch fence.** `CommandHistory.#contextEpoch` owns the generation. The Domain Selection epoch is a committed projection of that authority, not another counter. Every effective `replace()` increments it; a true no-op does not. `contextChanged:true` always clears selection, regardless of `resetSelection`, and invalidates stale DOM actions. `expectedEpoch` is mandatory for both select and clear; it must be captured with the original rendered DOM context, **not substituted with the newest session epoch inside a late event handler**. Undo/Redo recover valid Selection within the current epoch and never rewind it. Rebase cannot resurrect an older context.

**Subscription and controller.** `subscribeSelection(listener, {emitCurrent})` emits `(frozenSelection, {event,projectRevision})`, returns an idempotent unsubscribe and never writes to the Domain Project. Use `createDesignerSelectionController({typed:'domain', session, renderLayers, renderInspector, syncCanvas})`. The controller's `select(ref, {expectedEpoch})` and `clear({expectedEpoch})` require the epoch captured from their original DOM context. It listens to both Selection and project revisions, then flushes one coherent view at the final History notification; direct Selection-only changes render once without a fictitious History mutation. Native Host remains `typed:true`, with its exact existing `session.subscribe()` and `session.select(refs, primary)` ABI. The common controller remains the only view synchronizer. Consumers own domain labels and Inspector forms.

**B2-F02 History-final notification correlation (SOURCE candidate).** Typed Domain Selection notifications published as part of a genuine Base History commit carry an additive, immutable `event.historyRevision` safe integer equal to the exact already-committed revision in the matching `subscribeHistory()` notification. The marker originates solely from canonical `CommandHistory`; ordinary independent `session.select()` and `session.clear()` events never carry it, even when consumers choose misleading `source` labels such as `command` or `replace`. A no-op selection has no event or redraw. The derived event is frozen; SelectionRefs, Project and persisted payloads do not change.

Commit notifications may be Project → marked Selection → History; Project → History; marked Selection → History (including equal-project context-only replacement); or History only (effective `clearHistory()` or rebase). The shared typed Domain controller synchronizes its Layers, Inspector and Canvas adapters **exactly once within the matching final History listener callback phase** for each effective History notification, including History-only changes. It does not require other independently registered History subscribers to have returned. Standalone, unmarked Selection changes still synchronize immediately. Invalid, repeated, unsafe, out-of-order or mismatched revision markers fail closed without a verified view; History observer exceptions use the existing `subscribeDiagnostics()` mechanism. Ordinary Selection-only observer failures do not fabricate History diagnostics.

**History-only presentation / explicit refresh.** A History-only view flush reads current frozen Project and Selection without changing Selection, epoch, dirty state, History, panel activation or Undo steps. Base never treats adapter execution as a successful consumer view acknowledgement; consumers must preserve actual focus/ARIA/scroll/controls and explicitly witness failures before enabling Save. `controller.refresh()` is a synchronous **independent, known-stable presentation** operation. It rejects a refresh when its own observed commit is pending. Consumers MUST NOT call it inside an in-flight Project, Selection or History observer, even if their callback runs before the controller has learned of the pending commit due to registration order; `refresh()` may not simulate a final History flush or successful consumer acknowledgement. Native Host (`typed:true`) and profile-bound Selection ABI are unchanged. Capability gates remain `DOMAIN_SESSION_API = 3` / `DESIGNER_EXIT_GUARD_API = 2`, but the corrected source and installed artifact must be paired by exact SHA/receipt.
**History and disposal.** Selection is not portable project data, a Save payload, a second command engine, or an independent dirty/Undo step. Commands restore/reconcile typed refs using existing staged EditorState, with rollback restoring both epoch projections. Observers read committed project/Selection/History in project → Selection → History order. Dispose the controller before the session; `destroy()` and unsubscribe are idempotent, and disposed sessions reject new Selection operations. Native Host profile and render adapter lifecycle are unchanged.

Capability: `Assets::DOMAIN_SESSION_API = 3` is authorized **only after** this complete source contract and its B2/R1/R2/R3 tests pass. `DESIGNER_EXIT_GUARD_API = 2` remains unchanged.

### Designer v2 SOURCE candidate: shared sessions, immutable history and guarded saves (G1d-B)

This section describes the **v2 SOURCE candidate**, not a released or installed capability. The normative contract is G1a v2.1, with separate Automations and Evaluator owner sign-off. PHP declares Domain Session API v3 (including v2 History/Save) on this branch, but do not promote this paragraph into an installed consumer compatibility guarantee until PHP and WordPress-resolved ESM have been verified in the same approved release artifact.

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

### Optional Canvas Navigation CN-1 (SOURCE candidate)

The named public ESM export \`createDesignerCanvasNavigation\` is also available
as \`window.cbCore.designEditor.shell.canvasNavigation.create\`. It is
independent of responsive \`configureDesignerViewports()\`.
Installed consumers must check
\`CoreBlueprint\Core\Design\Editor\Assets::CANVAS_NAVIGATION_API >= 1\`
and the actual JS export from the same installed build.

\`createDesignerCanvasNavigation({root, viewport, scene, getContentBounds,
getContextKey, isReady, onViewChange?})\` requires a Designer shell root, a
focusable scrollable viewport and nested scene. \`getContentBounds()\` returns
finite unscaled \`{x,y,width,height}\` with positive width and height.
\`getContextKey()\` returns the authorized context identity.

Controller: \`panBy(dx,dy)\` (viewport CSS pixels), \`fitToScreen({padding=32})\`,
\`centerOnContent()\`, \`clientToCanvas({clientX,clientY})\`,
\`canvasToClient({x,y})\`, \`getView()\` and idempotent \`destroy()\`.
The read-only view is \`{x,y,scale,scrollLeft,scrollTop}\`. Scene transforms
are presentation-only; nodes and SVG connections stay in the same scene
coordinate system. Do not apply competing consumer transforms.

Initial positioning fits content. Resize maintains useful positioning;
context changes reset the view. Empty-surface left drag, middle drag and
Space+left drag pan. Ordinary left dragging does not intercept interactive
nodes; optional nested backgrounds use \`data-cb-design-canvas-background\`,
interactive objects use \`data-cb-design-canvas-object\`. Native scrollbar,
wheel and keyboard fallback remain available. The viewport should be focusable
and have an accessible name. \`cb:design-canvas:navigationchange\` only
observes view changes. No project, Save, Selection or History writes occur.
The consumer owns Fit/Center buttons, node coordinate conversion, graph
semantics and persistence.

### Optional Canvas Navigation CN-2 — additive zoom (SOURCE candidate)

`Assets::CANVAS_NAVIGATION_API >= 2` adds two methods to the existing
`createDesignerCanvasNavigation` controller. CN-1 methods and runtime
behavior remain stable; consumers must probe the capability version from
the **same installed Base ZIP** and fail closed if the methods are missing.

- `zoomAt({scale,clientX?,clientY?})`: absolute explicit zoom around a
  viewport client-space point. If the point is omitted, it uses the viewport
  center. Explicit zoom clamps to 0.1–4.0 (10–400%); invalid values throw.
- `zoomBy({factor,clientX?,clientY?})`: relative zoom using the same anchor
  and bounds; factor must be positive and finite.
- Both return `true` after a change, `false` when unchanged/unavailable,
  and publish the existing read-only `getView()` snapshot and
  `cb:design-canvas:navigationchange` signal. Cursor-world position is
  invariant through a successful zoom, including scrolled/transformed
  viewports. A resize after manual zoom does not trigger auto-fit.
- `fitToScreen()` retains its v1 behavior for very large documents and may
  select a scale below 10% to fit all content. Its behavior is intentionally
  not narrowed by the explicit zoom bounds.
- Base does **not** intercept Ctrl/Cmd+wheel, browser zoom or button events;
  consumers implement their own scoped user interaction using this public
  zoom surface. There is no second scene transform/history engine, no
  graph coordinate mutation, no serialized camera state.

### Optional Designer Context Menu CM-1 (SOURCE candidate)

The named ESM export \`createDesignerContextMenu\` is also available at
\`window.cbCore.designEditor.shell.contextMenu.create\`. The installed
Base capability is \`Assets::DESIGNER_CONTEXT_MENU_API >= 1\` and must match
the same actual JS export. This controller is opt-in and does not alter
Mail, Maintenance Reports, Contracts, Reports or their existing shell UI.

\`createDesignerContextMenu({root, viewport, getContextKey, getItems,
canInvoke, onInvoke, ariaLabel?})\` owns a temporary Base-themed flat menu
with viewport-clamped fixed positioning. Registered canvas objects use
\`registerTarget(element,{kind,id})\`, which returns an idempotent unregister
callback. \`open({target?,clientX?,clientY?,invoker?})\` opens for the supplied
registered target or empty canvas; \`close()\` and \`destroy()\` are provided.
The viewport must be within the root; registered targets must be within
the viewport and have stable IDs. Blank nested scene areas may use
\`data-cb-design-canvas-background\` and the scene itself may use
\`data-cb-design-canvas-scene\`.

\`getItems({target})\` synchronously supplies up to 50 unique
\`{id,label,disabled?}\` action descriptors. **No children/submenus in v1.**
A right-click on a registered object or blank scene opens the menu.
The focused target uses Shift+F10. Up/Down, Home/End, Enter/Space and Escape
work through accessible menu/menuitem semantics; disabled items never
execute and focus is restored on close.

Immediately before dispatching an action, Base validates the original
context key, that a registered target still exists, re-reads the action
list, rejects a now-disabled/absent action, and requires synchronous
\`canInvoke({actionId,target}) === true\`. Only then does it call
\`onInvoke({actionId,target})\`. The consumer *again* validates command
authorization at its command boundary. The menu never mutates data,
creates History entries or grants permissions. The optional
\`cb:design-context-menu:open\` and \`...:close\` events are informational.
\`destroy()\` unregisters listeners, removes menu DOM and clears target state.

### Shared Drag & Drop Foundation DND-01 (SOURCE candidate)

The public named ESM export is `createDesignerDragDropController` from
`@cb-core/design-editor`. The equivalent facade is
`window.cbCore.designEditor.shell.dragDrop.createController`.

**Ownership:** Base owns the gesture, registered DOM source/zone lifecycle,
drop indicator attributes, native HTML drag events, keyboard operation and stale-context guard.
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

### Native Save Authority and Base Toast presentation (P0 SOURCE candidate)

The native WordPress Host uses the same Base Toast Foundation as legacy
Designer Mode: `@cb-core/toast`, enqueued through
`CoreBlueprint\\Core\\UI\\Assets::enqueue_toasts()`. Consumers MUST NOT
construct a separate notification system. Save results are presented ONLY
after the canonical SaveCoordinator has returned a classified outcome:

- `committed`: show a success toast only after authenticated receipt
  correlation, full accepted-field validation and baseline rebase.
- `refused`, `conflict`, `idempotency_reused`: show an error toast;
  the server response must establish no-write/rolled-back refusal.
- `unknown`: show a bounded warning, retain original operation identity
  in the private SaveCoordinator and keep the **Verify save status** action.
  No retry with a new operation ID or optimistic success is permitted.
- `persisted-unsynchronized`: show a persistent error toast and block
  context transition. It may have been committed, but local editor History
  has not been reconciled.

The existing `cb:design-shell:savechange` event is sent for interoperability
only; `nativeHandled: true` denotes the native Host's already-rendered Base
toast. Legacy listeners must not duplicate it or disable the recovery control.
In native mode the old persistent notice below the toolbar is used only as a
fallback when the canonical toast adapter was not supplied.

The WordPress transport distinguishes conclusively refused, pre-transaction
`400/403/409` Save responses with an exact authenticated
`{status:'refused'|'conflict'|'idempotency_reused'}` body from ambiguous
network/HTTP/receipt outcomes. Recover responses, CDN error pages and
unverified payloads never establish no-write. Unknown outcome console
diagnostics contain ONLY a bounded phase label; never log user content,
nonce, resource identity, request or receipt.

**P0 field reconciliation, 23 October 2026:** The fullscreen native
Mail/Reports screen is a fixed z-index 100150 layer, not the legacy
`.cb-core-design-shell.is-fullscreen` element. Core Toast must appear above
that native screen (z-index 100160). Browser Save reports previously collapsed
transaction/recovery problems into `SAVE_SERVER_UNVERIFIED` and
`SAVE_RECOVERY_UNVERIFIED`. The operation and ledger now provide a small
allowlist of phase-only `reason` values for unknown outcomes, passed through
the authenticated REST response, SaveCoordinator and Base Toast observer into
a sanitized console category. They do not change Save truth, retry behavior,
no-write proofs or allowed permissions. **No Save runtime PASS is claimed**
until a Mail and Reports Save receives a complete verified receipt and both
documents survive reopen.

**Mail Save P0 field finding:** Live Mail returns `refused` with
`SAVE_DOCUMENT_STORAGE_REFUSED`, while Reports can save. This proves
domain admission has already succeeded and the repository has not returned a
verified `saved` result. The storage port now distinguishes CREATE vs UPDATE
and the finite internal Repository outcomes `INVALID`,
`SCHEMA_UNAVAILABLE`, `QUERY_FAILED`, `READBACK_UNVERIFIED`,
`READ_UNAVAILABLE` and `UNCLASSIFIED`. These codes reach the browser only
after a confirmed outer rollback. Do not infer a SQL root cause or change
transaction semantics before the next bounded runtime category is observed.
Mail Save remains P0 BLOCKED; Reports and shared DND/Toast remain unchanged.

**Unicode/SQL rootcause correction candidate (23 October 2026):**
WordPress `wpdb::get_table_charset()` treats the deliberate mix of
`ascii_bin` hash columns and UTF-8 document/receipt columns as ASCII.
`wpdb::query()` can then reject non-ASCII prepared SQL before mysqli runs;
the default Mail templates contain Unicode punctuation. This explains a
failed first-document Mail INSERT with no driver errno while ASCII-only
Reports documents could already save.

The repository's SQL-bound document JSON now escapes Unicode rather than
using `JSON_UNESCAPED_UNICODE`. The shared SaveEnvelope adds
`storage_json()` for lossless ASCII-safe receipt storage only.
`canonical_json()`, payload/receipt digests, CAS, authorization,
transaction isolation and receipt verification remain unchanged.
Decoded project, fields and receipt values retain all original UTF-8.
No schema migration, WordPress charset-filter override or relaxed database
checks. PHP SOURCE regression:
`tests/public-design-mixed-charset-json-regression.php`.
Mail Save/Reopen on the installed WordPress sandbox is still required before
this can be marked as runtime PASS.

**INSERT evidence follow-up:** The live `SAVE_STORAGE_CREATE_QUERY_FAILED`
response has narrowed Mail to the new-document INSERT. The repository now
captures only the numeric mysqli errno immediately after an unsuccessful
INSERT and before the subsequent readback. After a confirmed rollback, a
bounded `SAVE_STORAGE_CREATE_DB_<errno>` diagnostic may be emitted; the
fallback remains `SAVE_STORAGE_CREATE_QUERY_FAILED` if no numeric code is
available. No SQL text, database name, credentials, template contents or
connection error strings are returned. This is a diagnostic patch, NOT a
database-repair migration or proof of a successful Save.

**Status:** Source implementation and local deterministic checks only. The
real WordPress sandbox must demonstrate Mail and Reports Save/Reopen with
verifiable persisted receipts. A passing build does not prove that the server
schema and commit/recovery path are healthy on the target installation.

### DND-02 — native canvas placement (SOURCE candidate)

Base's shared native Host now uses the same DND-01 controller to connect the
Elements palette to Mail and Reports. The profile's existing `*.insert` command
remains authoritative, with no second Save, History or document mutation engine.

- **Mail:** registered HTML canvas nodes become semantic dropzones. The
  consumer's typed `allowedParents` schema restricts insertion. Zones can
  represent a Section, Column, or an existing child; valid before/inside/after
  positions resolve into the existing Mail insert command.
- **Reports:** the existing isolated `srcdoc` Flow preview remains
  `sandbox="allow-scripts"` and governed by its strict CSP. It reports only
  bounded semantic region IDs with approximate geometry via the established,
  generation-verified preview bridge. The parent creates ordinary DOM overlay
  zones; the iframe never receives a command, project or Save credential.
  Header/Footer and singleton policies remain domain owned. Empty/unmapped
  canvas regions fall back to an end-of-content insertion before the Footer.
- **External consumers:** `resolveDesignerDropPlacement(options)` and
  `designerDropZoneId(path)` are now public ESM named exports. The global
  facade exposes `shell.dragDrop.resolvePlacement` and `shell.dragDrop.zoneId`.
  A consumer can use these optional helpers or provide its own constrained
  resolver to the public DND controller.
- **Lifecycle:** renderer updates unregister replaced HTML nodes. An isolated
  renderer notifies the Host of new proxy zones after a verified preview
  generation. Drag gestures cannot mutate old Host contexts, and disabled
  palette elements cannot become drag sources.

**Limits:** This SOURCE candidate covers palette-to-canvas insertion using
native HTML drag and keyboard modes. Touch/pointer polyfill, repositioning of
existing nodes across parents, auto-scroll and arbitrary nested report-preview
selection remain DND-03 follow-ups. This is not yet a WordPress browser PASS:
the installed dev ZIP, native Mail/Reports acceptance, Save/Reopen and
independent consumer verification remain required. The 17 existing legacy
public editor/Layer test failures must not be relabeled as DND-02 successes.

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
- **Consumer-safe semantic confirmation (Foundation v2):** a Layers tree item
  may supply `actions.remove: { onActivate, disabled?, label?, confirmation? }`.
  `confirmation` is either a descriptor
  `{title: string, message: string, confirmLabel: string}` or a synchronous
  `({label, descendantCount}) => descriptor` factory. Base validates three
  nonempty bounded strings, shows the accessible Base-owned alertdialog and
  invokes the already-authorized `onActivate()` only after explicit
  confirmation. No HTML is interpreted. Missing descriptions use a neutral
  warning that the action affects descendants rather than claiming to delete
  them. Invalid consumer descriptions fail closed. Explicit confirmation also
  applies to an item without visible child rows.
- **Evaluator semantics:** deleting a Section moves Questions to Unassigned;
  the extension can truthfully explain this without implementing a separate
  destructive dialog or copying Base's internal Layers implementation.
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
- A Layers click in the left palette keeps Layers active while updating the right Inspector. For profile-bound sessions, `createDesignerSelectionController()` supplies source-aware selection and synchronization. Typed Domain Sessions use the B2 public `selection()`, `select()` and `subscribeSelection()` facade on the existing Base EditorState, while consumers remain responsible for entity membership and Inspector domain controls.



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
