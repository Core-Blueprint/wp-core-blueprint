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
