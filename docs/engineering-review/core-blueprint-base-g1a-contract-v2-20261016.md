# Core Blueprint Base G1a | Designer Foundation public contract v2

**Date:** 2026-10-16  
**Prepared by:** Base Luna, for Overseer Luna and the Automations/Evaluator consumer owners  
**Status:** **PROPOSAL / DESIGN ONLY**. Overseer has approved the architecture direction, **not the final signatures or implementation**. No code, repository branch, CI, WordPress database, installation, merge or release change is authorised by this document.  
**Owner:** `Core-Blueprint/wp-core-blueprint` is the sole future Base writer. Overseer owns cross-repository contract decisions.  
**Priority:** Base P0 enabler; Automations and Evaluations joint P0 acceptance; Base Mail + Maintenance Reports reference consumers; Newsletter P2.

## 1. Authority and decisions already received

Source pins re-verified for the preceding G1a review:

| Source | Exact SHA | Role |
| --- | --- | --- |
| Base `main` | `00975654517f657ca95be7a0dc0d901e95cb764c` | Inspected current public API; `DOMAIN_SESSION_API = 1` |
| Base historical `feature/designer-domain-state-history-v1` | `2bbb695abba4e7bdf9bfb5c95de82e93a65d0c77` | `createDesignerStateHistory`; **design evidence only** (5 ahead/119 behind Base main) |
| Base category `feature/base-template-library-navigation-categories-20261015` | `000e5939d1ebed7a363f5a93b0fe0bd7d673c99c` | Optional categories candidate (3 ahead/50 behind Base main) |
| Automations folders `feature/automations-workflow-folders-w31-v1` | `f4f7e23c61ca2b9448cfd03615b5dc66b8b1106d` | Current History/Workspace consumer evidence |
| Automations categories `feature/automations-template-category-provider-20261015` | `ccc76ea0a82666f1da585465d5215e779f4aa689` | Current T3B/T5 consumer evidence; diverged from folders branch |
| Evaluator `feature/evaluator-golden-audit-closure-20261016` | `63af69d0746cce7ad10bcbf7c09fb7cad954a4f1` | P02 Domain Session consumer evidence |

**Overseer review:** `audits/engineering/2026-10-16-designer-g1a-base-proposal-overseer-review.md` on Overseer's `docs/designer-foundation-golden-no-legacy-20261016` branch. That review received a *summary*, not the full previous Markdown. This document supersedes the previous Base proposal for decision purposes but does **not** purport to include incoming independent consumer reports.

**Approved direction:** one canonical Base session/history engine; no public `createDesignerStateHistory`, history shim, fallback branch or private API dependency. `DOMAIN_SESSION_API = 2` and `subscribeHistory()` are **principle-approved only**. Real content, immutable report snapshots, actor permissions, workflow execution security, consent and user privacy remain protected.

**Verified source gaps:** `assets/js/design/core/history.js` currently calls `ProjectState.replace()` before finalising undo/redo arrays, and Base Shell `assets/js/design/shell/index.js` reads optional private `session.history` / `session.projectState` and queues microtask reconciliation. These are **implementation findings**, not evidence of a tested fix. Existing Evaluator P02 rebase currently performs successive `replace` + `clearHistory` + `execute`; Automations imports a missing `createDesignerStateHistory`. All runtime integration remains unverified.

## 2. API manifesto: exact proposed public boundary

### 2.1 Canonical module and PHP version

- WordPress script-module identifier: **`@cb-core/design-editor`**; PHP owner `CoreBlueprint\Core\Design\Editor\Assets::MODULE_ID`.
- Proposed breaking capability: `CoreBlueprint\Core\Design\Editor\Assets::DOMAIN_SESSION_API = 2` (today `1`). **Do not bump until the implementation and executable export test exist.** Every affected first-party consumer pins the same Base candidate SHA and requires `>= 2` plus the named JS method(s); version by itself is insufficient.
- `createDomainSession(options)` for arbitrary validated `DesignProject` domain graphs; `createSession({profile, ...options})` for Base profiles `mail`, `document-flow`, `document-fixed`. **They must use one private history implementation**. No separately exported state history constructor.
- Both session factories expose immutable `project()`, detached `snapshot()`, `replace()`, `execute()`, `undo()`, `redo()`, `historyStatus()`, **`subscribeHistory()`**, **`clearHistory()`**, **`rebase()`**, and `dispose()`. Domain Session keeps `subscribe(listener)`; profile session keeps its existing selection/Inspector/validation and `subscribeSelection()` contracts. Profile-specific APIs remain unchanged unless separately approved.
- Existing Domain Session factory options remain `{project, validateProject=null, allowCommand=null, onChange=null, historyLimit=100}`. Profile factory retains its own `profile`, `validate`, `onSelectionChange`, `allowCommand` options. History limit integer **1–1000** (default 100).
- `commands.{insertNode,removeNode,reorderNode,setProperty}` remains public. Other top-level named exports classified in §6.
- No external permission is conveyed by a `DesignProject`, command, history snapshot or toolbar status.

### 2.2 Proposed canonical shapes (normative **candidate**)

```js
const session = createDomainSession({
  project,                 // {schema_version, design_type, root}
  validateProject,         // synchronous; throw to reject invalid domain state
  allowCommand,            // synchronous; return false or throw to deny
  onChange,                // observer: (project, projectEvent) => void
  historyLimit: 100,
});

const historyStatus = session.historyStatus();
// Object.freeze({canUndo: boolean, canRedo: boolean, size: integer})
// `size` is CURRENT UNDO DEPTH, not total (undo + redo) entry count.

const unsubscribe = session.subscribeHistory((event) => {
  // event and event.status are deeply immutable, no project/body/PII:
  // {
  //   action: 'execute'|'undo'|'redo'|'clear'|'replace'|'rebase',
  //   source: string,
  //   revision: number,         // current project revision
  //   historyRevision: number,  // monotonically increasing within session
  //   status: {canUndo, canRedo, size}
  // }
});

session.execute({label: 'Move step', apply: frozenProject => ({project: nextProject})});
session.undo();
session.redo();
session.clearHistory();
session.replace(nextProject, {source: 'context-switch'});

// Proposed atomic save acknowledgement primitive, pending consumer sign-off:
session.rebase({
  baselineProject: serverAcceptedProject,
  currentProject: currentDraftProject,   // omit when no edits followed the request
  source: 'save-baseline',
});

unsubscribe();
session.dispose();
```

**Public signatures:** `subscribeHistory(listener): () => void` (no initial emission); `historyStatus(): Readonly<HistoryStatus>`; `clearHistory(): void`; `replace(project, {source='consumer'}={}): FrozenProject`; `rebase({baselineProject, currentProject=baselineProject, source='save-baseline'}): FrozenProject`; `execute(command): FrozenProject`; `undo()/redo(): boolean`; `dispose(): void` (idempotent). Invalid active API arguments throw synchronously. `project()` is frozen; `snapshot()` is detached from internal mutable state. Public project subscribers continue `subscribe(listener)` with `(project,event)` and the initial `onChange(project,event)` factory convention. **No client permission, persistence or external execution is implied.**

**Rebase rationale and status:** `rebase()` is a **new explicit G1a decision request**, *not yet principle-approved*. It is one operation on the existing session engine, **not** a separate snapshot-history engine. It avoids emitting a temporary server-accepted project when the user has continued editing during asynchronous save. Its implementation should be deferred if the joint Automations/Evaluator requirements provide evidence of a better minimal atomic design; do not silently substitute sequential `replace/execute` while claiming atomic notifications.

## 3. History transaction, ordering and event contract

### 3.1 Transaction model: stage → commit → notify

For every successful model-mutating operation:

1. **Preflight**: assert active, no nested mutation, validate arguments, evaluate `allowCommand` for a new command, call pure `apply()` where applicable, normalize/validate the next project and selection transition. Store all required prior/future snapshots in memory. No public notification and no mutation of committed state at this stage.
2. **Stage**: prepare new project/editor state **and final bounded undo/redo arrays**. Compute whether this is a semantic no-op, preserve redo only for no-op. A command that changes only selection belongs to selection APIs, not history.
3. **Commit**: publish one coherent internal state switch. Project revision increments **only if the project value changes**; history revision increments once for each successful operation that produces a history notification, including a project-context replacement with an empty history stack. Status in `historyStatus()` is final before any observer runs.
4. **Notify synchronously** after full commit, in deterministic order: **(a)** project `subscribe()` listeners and configured `onChange` (if project changed), **(b)** profile selection listeners (if selection changed), **(c)** `subscribeHistory()` listeners (when operation committed). All callbacks, including project observers, can read final `project()` and final `historyStatus()`. **No microtask is needed for correctness.**
5. **Return** the documented operation result after dispatch. No asynchronous external effect participates in model commit.

Intra-stage callbacks are Base-owned only. The public contract guarantees project → selection → history **event classes**, but not registration order across independent listeners of the same class, except that listeners registered first are visited first if still subscribed. A listener may inspect state or unsubscribe during dispatch, not invoke mutating session APIs reentrantly.

**Observer exceptions after commit are not transaction failures.** Base must catch/isolate them, continue other observers, and report a bounded non-sensitive diagnostic via its UI/error path. They must not roll back a committed model/history or cause a successful execute to report failure. An error in external consumer DOM rendering can leave the UI stale and must surface visibly; it cannot make external DOM/network effects atomic. A consumer is responsible for a recoverable full render/refresh, not private mutation of Base history. Exact diagnostic delivery is a bounded G1b implementation concern with mandatory tests (not a new public error payload carrying workflow data).

### 3.2 Event shape, equality and frequency

- A history event is deeply frozen and contains **only** `{action, source, revision, historyRevision, status}`; no workflow definition, titles, private IDs, actor details or stack records. `status` is a frozen `{canUndo, canRedo, size}`. `revision` and `historyRevision` start at zero, monotonic per session. `source` is a bounded, nonempty consumer/engine label; `execute` uses `'command'`, `undo/redo` `'history'`, `clear` `'consumer'`, and replacement/rebase preserve the validated caller source.
- `subscribeHistory(listener)` rejects a nonfunction, subscribes once and **does not immediately emit**. Read the initial state via `historyStatus()` and subscribe before enabling toolbar interactions. Unsubscribe is idempotent, including after `dispose()`.
- `execute` committed change: exactly one project event and one history event with `action:'execute'`; profile selection event only if actual effective selection changes.
- `undo/redo` with available history: one project event if value changes, one history event, effective selection event if changed. They return `true`. Empty undo/redo: `false`, **zero notifications**, revision unchanged.
- `clearHistory()` with either nonempty undo **or** redo: zero project events, exactly one history event (`action:'clear'`, final status all false, `size:0`), historyRevision +1. Already empty: no notification or revision.
- `replace()` with a different project or nonempty history: clear undo/redo and optionally reconcile/reset selection in a single commit; exactly one history event even if status before/after is numerically equal (context identity can change). Project event only if value changed. Identical project + empty history + unchanged selection: no-op, no event.
- `rebase()` when baseline differs from draft: install `baseline` as undo origin, `current` as current state **with exactly one undo step**; redo empty. When equal: current=`baseline`, both stacks empty. It emits one history event on actual committed state or history change and **at most one project event**. Do not temporarily publish the baseline. Detect no-op via value-equivalence, not object identity.
- `dispose()`: detach observers, release memory/controllers, no project/history notification. `dispose()` itself is idempotent; every other session method, including `historyStatus`, `subscribeHistory`, `project`, `snapshot`, `undo`, after disposal throws a clear error. Calling an already returned unsubscribe is harmless.
- **No-op command**: if canonical project value and selection are unchanged, do not push an undo entry, wipe redo, increment revision, or notify. A rejected/invalid command is also non-mutating. Value comparison is semantic for supported JSON-safe normalized projects and independent of object identity/key insertion order; do not silently accept unsupported values.

### 3.3 Explicit ordering table

| Operation | Validation/policy | Commit | Project/selection notices | History notice | Public return |
| --- | --- | --- | --- | --- | --- |
| `execute(change)` | `allowCommand`, pure `apply`, project/profile validators | Atomic project + editor + undo push; redo cleared | At most one project, at most one selection; both see new stack | Exactly one `execute`, last | Frozen current project |
| `execute(no-op)` | Same validation | No change | None | None | Frozen current project |
| `undo()` / `redo()` | Staged history snapshot validation | Atomic project/editor/stack transition | At most one each; see settled stack | Exactly one `undo` / `redo`, last | `true` |
| Empty undo/redo | None | None | None | None | `false` |
| `clearHistory()` | Active session | Stack cleared if nonempty | None | Exactly one `clear` if changed | `undefined` |
| `replace(project,opts)` | Envelope/profile/domain validation | Context + history reset | Project only on value change; selection when changed | One `replace` if any actual change | Frozen current project |
| `rebase({baseline,current})` | Validate **both** projects before commit | Atomic latest-current + at most one baseline undo entry | At most one each | One `rebase` if changed | Frozen current project |
| `dispose()` | None | Terminate | None | None | `undefined` |
| Failed apply/validation/policy | Throws | None | None | None | Throws; stack/snapshots/revisions unchanged |
| Observer render throws **after** commit | N/A | Already committed | Other listeners still notified | Still emitted | Operation succeeds; visible bounded diagnostic |

### 3.4 Failure, rollback, external effects and reentrancy

- **Precommit throw** in `allowCommand`, `apply`, schema/profile validator, state cloning, normalization or selection preparation: original project, selection, undo/redo, both revisions and dirty baseline remain unchanged; **no project/selection/history event**. No synthetic rollback revisions. `undo/redo` must **peek, validate, then move** stack entries; never permanently `pop()` before a transition that can fail.
- **Internal commit-phase error**: if an internal step cannot be staged, abort before notification. An unexpected low-level partial commit must be restored silently to the exact previous state before any public notification; test injection required. If restoration is not provable, mark session fatally unusable and visibly fail closed rather than claiming atomicity.
- **Observer error**: committed state remains committed; subsequent observers still run; an observer cannot require rollback. No logging of complete project, credentials, private data, HTML bodies or IDs. A listener calling a mutating session API during notification receives a deterministic reentrancy error; readers/unsubscribe work. No nested history event interleaving.
- **External side effects**: command callbacks must be synchronous and pure in practice, returning `{project}`; Promise results, network requests, post saves, credentials, workflow execution and DOM mutation in `apply` are unsupported. A command callback that performs side effects cannot be rolled back by Base. Undo changes **editor definition only**, never reverses a previously executed automation or already-sent email.
- **Authorisation**: `allowCommand` guards *new* commands but history cannot grant site capabilities. Consumer-owned server save/activation/preview/import endpoints always recheck current actor, nonce, item ACL, execution policy and tenant/site boundary. If runtime actor permission changes mid-session, the consumer must close/replace editor or block persistence; session undo itself does not execute workflows.

## 4. Async saves, baseline, context changes and editor lifecycle

### 4.1 Save request / response

**Consumer-owned:** stable document identity, server-authorised save, dirty fingerprint, request generation/revision, error display, document validation, persistence and recovery. No Base generic save endpoint or Base-managed workflow state.

1. On Save, capture `{contextId, currentRevision, submittedProject}` and send a detached **sanitized consumer-domain payload** to its authorised server endpoint. Do not expose `DesignProject` project history or transient editor keys unless the domain explicitly requires them.
2. Continue allowing editing only if the consumer can track revision conflicts. When the response arrives, require matching active context and latest eligible save generation; discard stale/out-of-context acknowledgements.
3. **Success:** accept the exact server-confirmed normalized project as baseline. If no later edits, call atomic `rebase({baselineProject})`. If later edits, call `rebase({baselineProject, currentProject:latestDraft})`. The latter preserves dirty state and one undo back to accepted baseline **without publishing a temporary baseline**. The consumer updates its own dirty fingerprint atomically with the acknowledged baseline.
4. **Error, denied, invalid server response:** do not call `rebase`; preserve current draft, undo/redo, old acknowledged baseline and dirty indicator. Surface actionable error. Never claim "Saved" based on HTTP 200 alone.
5. A second Save with an older response arriving last must not move baseline backwards. Context switch while Save is pending must be blocked, cancelled, or generation-invalidated before replacing editor state.

**No hidden save policy:** `historyStatus()` is NOT a dirty flag and undo depth is NOT equivalent to unsaved changes; a user may undo back to baseline or edit domain metadata outside DesignProject. Dirty detection remains consumer-owned and must be tested with subject/settings changes as appropriate.

### 4.2 Context switch / Shell event lifecycle

Existing DOM contract: `[data-cb-design-launch-root]`, `[data-cb-design-shell]`, `[data-cb-design-shell-context] select`; `cb:design-shell:contextrequest` with `{value,previousValue,control,respondWith(promise)}`, followed on success by `cb:design-shell:contextchanged`. Base Designer Mode owns loading/busy/error, selected control rollback and active-shell visual lifecycle.

- `respondWith()` is accepted once **synchronously during event dispatch**; listener must return a Promise for the complete consumer transition. Missing or duplicate response fails visibly and never silently reports success. Current implementation already requires a response but an explicit error state for missing response should be tested as a G1b hardening item.
- Consumer checks dirty policy and pending saves, fetches authoritatively, validates target and permissions, prepares domain state and last commits one `replace(project,{source:'context-switch'})` **or swaps in one new session after disposing old controllers**. Only then resolve. No URL/history update, earlier-context undo or selection leakage before success.
- Failure **before commit** rejects Promise: Base restores selector to `previousValue`, leaves previous project/history/dirty UI unchanged and provides visible error. No navigation, no fabricated `contextchanged`.
- A consumer must not commit a replacement and *then* reject a Promise: that would misrepresent current active context. If an error occurs after commit, the consumer must handle a defined recovery path and report the **actual** active context. Stale fetch/save responses, closed/disposed Designers and aborted generations must not commit.
- On genuine success: event order `project` → optional `selection` → `history(replace)` → Shell `contextchanged`; focus returns to meaningful context/active canvas, not hidden drawer. Base controls adaptive toolbar/drawers and in-progress loading presentation. No full reload.

### 4.3 Shell integration and life ownership

- `createDesignerShell(root, {session,defaultPanel,defaultPanels,onPanelChange})` requires either **a v2 public session** or explicit `null` for presentation-only shell. If non-null, require `historyStatus`, `subscribeHistory`, `undo`, `redo`; lack of contract must throw/visibly fail rather than use private `session.history` or `session.projectState` fallbacks.
- Initial synchronisation: subscribe to history then immediately read `historyStatus()`; each subsequent committed event updates Undo/Redo button states **synchronously** (no microtask). Unsubscribe in `shell.destroy()`. Session belongs to consumer, **Shell destroy does not dispose the session**; consumer disposes session exactly once, after destroying dependent controllers/listeners.
- `createDesignerShell` remains idempotent for same root+session, rejects binding an existing root to a different live session. For context switching, prefer replacing session's project rather than creating a second Shell. New session requires destroying Shell/selection/layers/navigation controllers before recreating it.
- Shell handles button clicks/keybindings only when target is appropriate (no interception while typing in text fields, contenteditable or IME composition). A single keyboard shortcut must cause **one** domain edit. Undo/Redo source authority is Base session; Automations removes its document-level duplicate keyboard history listener.
- The session's observer success does not mean Save succeeded. Shell owns **Save/Close presentation**, but consumers own Save callback and navigation/unsaved changes policy; no Base-side default save or silent discard. Close must not implicitly dispose an uncommitted domain draft before consumer policy runs.
- Presentation-owned public events remain `cb:design-editor:ready`, `cb:design-shell:ready`, `cb:design-shell:fullscreenchange`, `cb:design-shell:contextrequest`, `cb:design-shell:contextchanged`, `cb:design-shell:savechange` where currently supported. Only public names/details documented and tested in their respective component contracts; do not expose internal private DOM class names as behavioral selectors.

## 5. Automations P0: exact mapping to one Domain Session

**Current confirmed consumer:** `assets/js/admin-builder-history.js` on pinned Folders branch imports missing `createDesignerStateHistory`, observes `cb-automations:definitionchange`, records complete mutable definition snapshots, applies via `api.replace`, and resets after `cb:design-shell:savechange`. This is a *separate history authority* today and is not compatible with Base main.

**Proposed owner-owned domain adapter, pending Automations Luna contract request:**

```js
const toProject = (definition) => ({
  schema_version: 1,
  design_type: 'automations.workflow',
  root: {
    type: 'automations.workflow',
    provider: 'core-blueprint-automations',
    properties: { definition: normalizeDefinition(definition) },
    children: [],
  },
});
const fromProject = (project) => validateAndDecodeDefinition(project.root.properties.definition);
const session = createDomainSession({
  project: toProject(initialDefinition),
  validateProject: (project) => validateWorkflowEditShape(fromProject(project)),
  allowCommand: (command, project) => canEditWorkflowInContext(command, project),
});
```

This is an **illustrative serialization mapping**, not a proposal to persist `DesignProject` instead of Automations' existing Definition/Codec. No actor/principal/credentials/execution context is invented or copied into editor history. The `definition` property holds only the *sanitised, authorable* definition state under the extension's own schema/limit contract. Exact step IDs, folders, typed bindings, imports and command vocabulary remain with Automations. A domain change must become `session.execute({label,apply})` at the sole mutation point; `cb-automations:definitionchange` becomes a **view/read-model notification**, not an input into a second recorder. Editor `api.replace()` must be used only for authorised context replacement/recovery, not each keystroke or node move.

- Each workflow editing intent must map to one **pure** domain command, with undo/redo restoring definition and rendering via project observers. Current `onApply` callback and `history.record/reset` are removed completely.
- `session.subscribeHistory()` enables Base shell controls; no separate Ctrl/Cmd+Z document listener; preserve local native text-editor undo semantics and reduced-motion animation (`animateLayoutChange` only around visual rendering, not state authority).
- Post-save `rebase()` uses acknowledged sanitized project and any ongoing draft. Folder moves and workflow context switches explicitly invalidate previous history only when a new workflow identity becomes active; **renaming a folder does not grant workflow execution authority**.
- Template instantiation uses extension's authorised endpoint; output always `DISABLED`, `execution_principal_user_id=0`, no copied credentials or literal secrets, no automatic job/run. Template Browser selection cannot call session command and thereby activate a workflow.
- Outstanding from Automations Luna: exact current edit mutation locations, whether `Definition` can contain sensitive literal values needing in-memory protection, stable project root mapping, command granularity/grouping, save racing policy, folder identity and triggered `definitionchange` semantics.

## 6. Evaluator P0 and Base first-party consumers

- **Evaluator:** retain its already-migrated P02 `createDomainSession` instead of backporting obsolete low-level classes. Require v2 history events/rebase (if approved) with complete question/option/rule/result revisions and stable IDs. Adopt **mandatory** public `createDesignerNavigation` without older-Base `window.cbCore?...` fallback; promote `createDesignerInspectorSection` and `enableDesignerTextareaAutosize` to explicit supported imports. Keep frontend `cb:evaluator:result` minimal (evaluation ID + result IDs only); do not leak scores/answers, and preserve its second-meta-write rollback. Confirm save baseline response/current dirty behavior with Evaluator Luna.
- **Base Mail:** `createSession({profile:'mail'})`, public Mail rich-text + inline text factories, Sessions/Inspector/Layers and allowlisted HTML. `subscribeHistory` + `clearHistory`/`rebase` must not alter stored Mail HTML/subject, preview, sending or policy. Only register a Template Library source if real Mail template ownership/storage/ACL are proven.
- **Base Maintenance Reports:** `createSession({profile:'document-flow'})`, flow preview; keep immutable report snapshots, canonical Composer input, report PDF/render parity. Template source must not reclassify customer report snapshots as reusable templates.
- **Newsletter P2:** direct Mail contract later; remove old-Base Rich Text availability fallbacks only after Automations/Evaluator wave. Keep unsubscribe, consent, audience, subject and dispatch safeguards.
- **Certificates/Commerce/other first-party:** do a repository-wide import/style/DOM dependency ledger before deleting currently named low-level exports, selectors or handles. **Do not delete on assumption that only five Designers exist.** Owners migrate their code after explicit impact recording.

## 7. Public Shell, Inspector, Navigation, Layers, CSS and DOM

### 7.1 Public JS symbols, not private implementation files

Promote and test these actual Base exported families through `@cb-core/design-editor`:

| Family | Public signatures / required semantics | Ownership |
| --- | --- | --- |
| Engine | `createSession`, `createDomainSession`, `commands`, `profiles`, `motion`, `animateLayoutChange` | Base mechanics; consumer domain policy |
| Shell | `createDesignerShell(root,options)`, `configureDesignerSidebar(root,configuration)`, `configureDesignerViewports(root,options)` | Base root/rail/fullscreen/focus/toolbar/ready |
| Navigation | `createDesignerNavigation({items,activeId,ariaLabel,root,onNavigate,documentRef})` → `{element,setActive(id),update(items,{activeId}),destroy()}`; `DESIGNER_PALETTE_ROLES` | Base navigation DOM/focus; consumer stage/target semantics |
| Layers | `createDesignerLayerTree({…})`, `createDesignerLayerRow({…})`, `DESIGNER_LAYER_ACTIONS`; semantic descriptors, stable item IDs, callback-driven bounded actions | Base tree traversal, rows, keyboard, disclosure; consumer domain policy |
| Selection | `createDesignerSelectionController({session,shell,renderLayers,renderInspector,syncCanvas,inspectorPanel,openInspectorOnInsert})` → `{select,clear,refresh,destroy}` | Base synchronisation for profile sessions; domain graph ↔ selection adapter belongs to consumer where Domain Session has no tree selection model |
| Inspector | `createDesignerInspectorIdentity({label,eyebrow,context,compact,documentRef})`, `createDesignerInspectorControls({documentRef})`, `createDesignerInspectorToggle({label,checked,disabled,dataset,documentRef})`, `createDesignerInspectorSection({…})`, `enableDesignerTextareaAutosize(textarea)` | Base identity/hierarchy/controls; domain edits/validation owned by consumer |
| Icons | `createDesignerIcon`, `decorateDesignerControl` and documented icon names | Base common icon presentation |

Use the **named exports**, not implementation imports under `assets/js/design/*`. Equivalent Base-owned global facade is **namespaced** `window.cbCore.designEditor.shell.{navigation,inspector,layers,selection}`, *not* an identical copy of top-level export layout. Public command constructors go through `commands.*`; `ProjectState`, `EditorState`, `CommandHistory`, direct aliases and private tree helpers are to be pruned **only after an all-consumer audit and coordinated migrations**. The already-existing `createDesignerInspectorSection` and autosize helpers are not hypothetical; they are actual exported symbols consumed by the Evaluator.

### 7.2 Semantic DOM and accessibility

Normative behavioral anchors are **documented** `data-cb-design-*` markers, not accidental CSS class names. Relevant examples: `[data-cb-design-launch-root]`, `[data-cb-design-shell]`, `[data-cb-design-shell-context]`, `[data-cb-design-shell-undo]`, `[data-cb-design-shell-redo]`, `[data-cb-design-shell-primary-action]`, `[data-cb-design-shell-toolbar-extension="view|actions"]`, `[data-cb-design-shell-palette-role]`, declared Inspector/Layers/Settings sidebar roles and navigation/element panels. Do not query private Base-generated toolbar overflow proxies to bind domain callbacks.

Required behaviors: Save and Close pinned in adaptive toolbar, focus return after modal/context and full-screen, one keyboard undo per intent, no consumer-owned mobile drawer/toolbar implementation, ≥200% zoom and desktop/mobile responsive controls, `prefers-reduced-motion`, no context-switch reload, translated visible labels and screen-reader names; Navigation task outline **is not** Layers object hierarchy. Inspector selected-item identity must use the same semantic label as Layers. Base owns 3-rail desktop and canvas-first 2-drawer ≤1280px composition.

Public **presentation** CSS primitives explicitly documented in `docs/DESIGNER-MODE.md` (such as `__panel-body`, `__panel-section`, `__field`, `__palette-grid`, `__canvas-workarea`, `__surface` and inspector identity/toggle classes) may be used for **composition**; those do **not** thereby grant permission for consumers to style or query every internal selector. Public document custom properties: `--cb-design-document-width`, `--cb-design-document-min-height`, `--cb-design-document-padding`.

### 7.3 PHP enqueue/style semantics

- **Primary public methods:** `Assets::enqueue()` (embedded public engine/shell), `Assets::enqueue_designer_mode($translatedTitle)` (self-contained Designer Mode including shared Button/Form Control/Composition/toolbar chrome), and `Assets::enqueue_mail_rich_text_controls()` (opt-in Mail editor presentation).
- **Decision proposal:** `Assets::DESIGNER_COMPOSITION_STYLE` is a **supported semantic PHP handle** for a WordPress style dependency when a legacy page registration/dependency graph **genuinely needs** a named composition dependency. `DESIGNER_MODE_STYLE` and `SHELL_STYLE` are Base internal layering details for consumers that use the semantic enqueue methods; all other style/file handles remain implementation details unless separately classified after an import ledger. Do not outlaw the actually purposeful Evaluator style dependency as a matter of dogma; test its registration and order. The physical CSS file path is never an extension contract.
- `Assets::INSPECTOR_COMPOSITION_API = 1` already exists for Inspector composition; any Navigation capability is demonstrated by its actual JS public export and tested layout; do not invent an unsupported numeric capability. New version constants should describe real feature seams only.
- Test standalone wp-admin render (no `.cb-core-wrap` or private `.cb-core-form-scope` wrapper required), stylesheet registration, dependencies and idempotent enqueues. Base adds its own Designer form scope; consumers may not duplicate the stylesheet or copy CSS internals.

## 8. Template Library: category interface and security contract

**Proposal:** selectively port the optional interface from the diverged Base categories branch onto the contemporary Base line, **not** a merge of a stale branch:

```php
namespace CoreBlueprint\Core\TemplateLibrary;
interface TemplateCategorySourceInterface {
    /** @return array<string,string> Category slug => translated display label */
    public function categories(int $user_id): array;
}
// Proposed capability on Base:
// TemplateSourceRegistry::CATEGORY_API = 1;
```

- Source **must** implement `TemplateSourceInterface` to register. The category interface is optional for providers but **mandatory to use** if the provider advertises category facets. A provider without it returns an empty category array, not an old-version fallback. Base registers at `core_blueprint_register_template_sources` during `init` priority 6 after ExtensionRegistry at 5; no early translations.
- Registry method **proposed** `TemplateSourceRegistry::source_categories(?string $extension=null, ?string $designer=null): array` returns `[sourceId => [{id,label},...]]`; when both scope args are supplied, internally filters **before calling `categories()`**, after validating the IDs. For REST Designer requests it accepts only the **verified** `DesignerScope` scope, never arbitrary client args as authority. Public PHP callers without scope must still be authenticated and pass `can_browse()` per source.
- Check current user ID > 0, authorized source `can_browse(user)` and exact registered extension/Designer before evaluating facets. Fail closed for provider exception, bad return type, unknown category ID or label. Sanitise labels as text, reject invalid UTF-8/control characters; max **48 categories per source**, slug passes `TemplateQuery::valid_id`, nonempty visible label max **96 UTF-8 bytes** (proposal requiring PHP multibyte/locale review). Emit **no count**, owner identity, existence of another actor's private template, document body, workflow steps, secrets or untrusted navigation HTML. Any `custom` facet based on stored items must reflect **only items visible to the current actor**, not unviewable personal templates.
- `/template-library/sources` emits categories only for sources already authorized and within verified Designer scope; empty `categories: []` is the one response shape. `@cb-core/template-browser` may filter by `source+category`; duplicate category slugs across sources are not assumed semantically identical. Do not display a misleading merged category label across sources; either group facet choices by source or show only valid selections for chosen source, without fetching all pages.
- Read-only global browser: admin privilege **and source ACL**; contextual browser: per-user short-lived server-issued `DesignerScope`, `X-WP-Nonce`, `X-CB-Designer-Scope`; exact extension/designer scope is server enforced. GET metadata remains bounded, local-only. `TemplateQuery` max 48, provider filters before stable paging, opacity of HMAC cursor (actor+query+scope+source set), token TTL 15 min and session binding; verify rejected missing/expired/forged/replayed tokens. Never place scope tokens into URLs, logs, storage, exported HTML or external calls.
- **No accidental use authority:** metadata selection, `can_browse`, and `can_view` never authorise Save, Import, Preview, Export, Duplicate, Delete or workflow activation. Each extension re-fetches item under actor policy and validates POST nonce + ownership, and re-scrubs template definitions. Automations creates **disabled** workflows with **principal 0** and zero inherited credentials/literal secrets; no automatic Run. Prevent template post type creation from triggering automation jobs.
- Real Template Library source adapters for Base Mail or Reports require demonstrated storage/ACL ownership; no unconditional synthetic source registration and no customer Report snapshot/body in generic template metadata.

## 9. Known cross-repo changes and gate ownership

| Consumer | Current pinned source | Desired G1a change | Hard invariants | Responsible writer |
| --- | --- | --- | --- | --- |
| Automations (P0) | Missing `createDesignerStateHistory`; T3B/T5 category interface on divergent branch | Domain Session commands + post-commit events + rebase, categories and one history authority | workflow disabled on instantiate, principal zero, no credential leak, folder identity preserved | Automations Luna |
| Evaluator (P0) | P02 Domain Session already, optional old-Base Navigation fallback; composition style handle | v2 history semantics, no fallback, canonical Inspector/Nav/Layer API and CSS contract | Rules/Results/Stack Analyzer, stable IDs, frontend `cb:evaluator:result` data minimization, meta-write rollback | Evaluator Luna |
| Base Mail (ref) | `createSession('mail')` + Layers/Inspector + rich-text | profile history parity, source acceptance, UI shell | allowlisted HTML, sender-neutral preview/save, existing templates remain intact | Base Luna |
| Base Reports (ref) | `createSession('document-flow')` + preview host | profile history parity, controlled template source | immutable snapshots, report PDF/preview/render parity | Base Luna |
| Newsletter (P2) | older-Base Mail/Rich Text method_exists fallback | later pinned Mail consumer migration | consent, unsubscribe, dispatch policy, campaign snapshots | Newsletter Luna |
| Other first party | not exhaustively audited | manifest-driven dependency inventory before pruning | no silent breaking of Certificates, Commerce or any embedded Designer | coordinated owning Luna |

**Coordination:** Automations/Evaluator independent requirements reports remain **pending**. This Base document states candidate semantics and concrete requests; it is not their acceptance and is not a licence to edit their repositories.

## 10. Ordered steps **after** a separate G1a CONTRACT GO

1. Re-pin Base main/feature SHAs; inspect developer handbook/toolchain and safe checkout; complete all first-party public import/selector/handle usage search. Overseer publishes final accepted API manifest and P0 migration ledger.
2. One new dedicated Base-only feature branch: internal history transaction ordering, subscriptions, no-op/rollback/reentrancy, atomic `rebase` if separately approved, Shell private fallback removal; focused JS tests first. No discretionary UI redesign.
3. Same Base branch, bounded category interface/registry/browser changes with actor/scope security negative tests; maintain existing storage boundaries.
4. Document and test public Inspector, Navigation, Layers, direct/manual Shell, CSS semantic dependencies, module/PHP capability manifests. Source tests **plus executable built-module import probes**.
5. Base Mail + Reports source/build dogfood on pinned commit; then Automations and Evaluator each migrate **in their own repositories** and independently report executable integration results. Source/build is not installed acceptance.
6. Only on approved isolated environment and explicit separate operator GO: WordPress installed tests, mobile/keyboard/a11y/Light-Dark/six locales and owner field acceptance. No merge, CI, release, install or DB writes without explicit authority.

## 11. Requested G1a decisions, distinct from implementation GO

**Already direction-approved:** (A) one history engine behind Domain Session; (B) no legacy; (C) v2 number and history subscription direction.

**Outstanding for Overseer and both P0 Lunas:**

1. Accept or amend exact post-commit project → selection → history notification ordering, payload fields, no initial history callback, observer isolation and no-op/rollback/reentrancy semantics.
2. Approve or reject `rebase({baselineProject,currentProject,source})` as an **atomic** shared save-acknowledgement mechanic on the existing engine; decide whether both profile and domain sessions expose it.
3. Confirm Automations' concrete serialized workflow `DesignProject` adapter, command granularity and sensitive-data constraints; confirm Evaluator's save/validation behavior and required profile parity.
4. Confirm category API signatures/bounds and pre-filter-before-provider requirement, source/category filtering and scope token security.
5. Accept `Assets::DESIGNER_COMPOSITION_STYLE` as the one documented semantic CSS dependency (besides enqueue methods); approve consumer import ledger before pruning any implementation exports.
6. Approve the executable test matrix, consumer exact SHA pairings and no-merge/no-runtime gate sequence.

**Next action:** send this **full Markdown** plus the companion **G1a test matrix** to Overseer; receive Automations/Evaluator signed requirements; issue one revised **CONTRACT GO** first. Only a *separate* implementation GO authorises Base code writes. The earlier Overseer architecture-direction GO is not that permission.