# Core Blueprint Base G1a | Designer Foundation public contract v2.1 (contract revise candidate)

**Date:** 2026-10-16  
**Prepared by:** Base Luna, for Overseer Luna and the Automations/Evaluator consumer owners  
**Status:** **CONTRACT REVISE / DESIGN ONLY**. Overseer full v2 review CR-01..07 is incorporated as a candidate; **final contract approval and implementation GO are both pending**. No code, repository branch, CI, WordPress database, installation, merge or release change is authorised by this document.  
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

**Overseer authority:** `audits/engineering/2026-10-16-designer-g1a-v2-full-contract-review.md` (CR-01..07) on Overseer's `docs/designer-foundation-golden-no-legacy-20261016` branch. Overseer has read both full v2 files and the already-received Automations/Evaluator P0 requirement reports. This is Base's **revised response**, not an assertion that either P0 owner has approved the revised history semantics. Prior source pins are inspection snapshots; re-pin for the later build.

**Approved direction:** one canonical Base session/history engine; no public `createDesignerStateHistory`, history shim, fallback branch or private API dependency. `DOMAIN_SESSION_API = 2` and `subscribeHistory()` are **principle-approved only**. Real content, immutable report snapshots, actor permissions, workflow execution security, consent and user privacy remain protected.

**Verified source gaps:** `assets/js/design/core/history.js` currently calls `ProjectState.replace()` before finalising undo/redo arrays, and Base Shell `assets/js/design/shell/index.js` reads optional private `session.history` / `session.projectState` and queues microtask reconciliation. These are **implementation findings**, not evidence of a tested fix. Existing Evaluator P02 rebase currently performs successive `replace` + `clearHistory` + `execute`; Automations imports a missing `createDesignerStateHistory`. All runtime integration remains unverified.

## 2. API manifesto: exact proposed public boundary

### 2.1 Canonical module and PHP version

- WordPress script-module identifier: **`@cb-core/design-editor`**; PHP owner `CoreBlueprint\Core\Design\Editor\Assets::MODULE_ID`.
- Proposed breaking capability: `CoreBlueprint\Core\Design\Editor\Assets::DOMAIN_SESSION_API = 2` (today `1`). **Do not bump until the implementation and executable export test exist.** Every affected first-party consumer pins the same Base candidate SHA and requires `>= 2` plus the named JS method(s); version by itself is insufficient.
- `createDomainSession(options)` for arbitrary validated `DesignProject` domain graphs; `createSession({profile, ...options})` for Base profiles `mail`, `document-flow`, `document-fixed`. **They must use one private history implementation**. No separately exported state history constructor.
- Both session factories expose immutable `project()`, detached `snapshot()`, `replace()`, `execute()`, `undo()`, `redo()`, `historyStatus()`, **`subscribeHistory()`**, **`clearHistory()`**, **`captureSave()`**, **`baselineSnapshot()`**, **`rebase()`**, **`subscribeDiagnostics()`**, and `dispose()`. `DOMAIN_SESSION_API=2` advertises the **whole** documented v2 session contract on **both** factories, not only Domain Session. An exact ESM+PHP artifact proof is mandatory. Domain Session keeps `subscribe(listener)`; profile session keeps its existing selection/Inspector/validation and `subscribeSelection()` contracts. Profile-specific APIs remain unchanged unless separately approved.
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
  verifyRebase: null,       // optional pure consumer approval for normalized replay
  historyLimit: 100,
});

const historyStatus = session.historyStatus();
// Object.freeze({canUndo: boolean, canRedo: boolean, size: integer})
// `size` = current undo depth; no persisted history data here.

const unsubscribe = session.subscribeHistory(event => {
  // Deep-frozen: {action,source,revision,historyRevision,
  //   status:{canUndo,canRedo,size}}
});
const unsubscribeDiagnostics = session.subscribeDiagnostics(diagnostic => {
  // Frozen ONLY: {code:'CB_DESIGNER_OBSERVER_FAILED',
  //   phase:'project'|'selection'|'history'|'multiple', recoverable:true}
  // No Error object, stack, project, answers, workflow IDs or credentials.
});

session.execute({label:'Move step', apply:frozenProject => ({project:nextProject})});
session.undo();
session.redo();
session.clearHistory();
// Explicit context reset also invalidates pending save checkpoints,
// even if two documents happen to contain identical project values:
session.replace(nextProject, {source:'context-switch', contextChanged:true});

// Consumer starts asynchronous Save from one immutable, session-bound checkpoint.
const save = session.captureSave();
// save: {token: opaque in-memory non-serializable capability,
//        submittedProject: detached frozen DesignProject, revision: integer}
// Send a separately sanitized DOMAIN payload created from save.submittedProject.
// Server confirms the authorized response and persisted version/identity.
const result = session.rebase({
  saveToken: save.token,
  baselineProject: serverAcceptedProject, // authoritative validated server payload
  source:'save-baseline',
});
// result: {project: frozen current, baselineProject: frozen accepted,
//          retainedUndoSteps: integer, normalized: boolean}
// No caller-supplied currentProject: Base reads the actual latest state.
const acceptedBaseline = session.baselineSnapshot();

unsubscribeDiagnostics();
unsubscribe();
session.dispose();
```

**Factory options (proposed v2.1):** Domain Session adds optional `verifyRebase=null`, a synchronous callback required to approve **normalized replay with concurrent edits**. It receives detached, immutable `{submittedProject,acceptedBaseline,previousCurrent,replayedCurrent}` and must return the boolean `true`. Every profile session accepts the identical optional hook; it does not replace domain permission checks. Input validation rejects nonfunctions and Promise returns; a throwing hook rejects normalization with `SAVE_REPLAY_CONFLICT` and no mutation.

**Public signatures (proposed v2.1):** `subscribeHistory(listener): () => void` (no initial emission); `subscribeDiagnostics(listener): () => void` (no initial emission); `historyStatus(): Readonly<HistoryStatus>`; `clearHistory(): void`; `replace(project,{source='consumer',contextChanged=false}={}): FrozenProject`; `captureSave(): Readonly<{token:OpaqueSaveToken,submittedProject:FrozenDetachedProject,revision:number}>`; `baselineSnapshot(): FrozenDetachedProject`; `rebase({saveToken,baselineProject,source='save-baseline'}): Readonly<{project:FrozenProject,baselineProject:FrozenProject,retainedUndoSteps:number,normalized:boolean}>`; `execute(command): FrozenProject`; `undo()/redo(): boolean`; `dispose(): void` (idempotent). Invalid active API arguments throw synchronously. `project()` is frozen; `snapshot()` is detached from internal mutable state. Public project subscribers continue `subscribe(listener)` with `(project,event)` and the initial `onChange(project,event)` factory convention. **No client permission, persistence or external execution is implied.**

**Rebase rationale and status:** Overseer **principle-approved** an atomic, domain-neutral `rebase()` but **not yet approved this corrected signature, replay and conflict policy**. It is one operation on the existing session engine, **not** a separate snapshot-history engine. It avoids emitting a temporary server-accepted project when the user has continued editing during asynchronous save. Its implementation should be deferred if the joint Automations/Evaluator requirements provide evidence of a better minimal atomic design; do not silently substitute sequential `replace/execute` while claiming atomic notifications.

## 3. History transaction, ordering and event contract

### 3.1 Transaction model: stage → commit → notify

For every successful model-mutating operation:

1. **Preflight**: assert active, no nested mutation, validate arguments, evaluate `allowCommand` for a new command, call pure `apply()` where applicable, normalize/validate the next project and selection transition. Store all required prior/future snapshots in memory. No public notification and no mutation of committed state at this stage.
2. **Stage**: prepare new project/editor state **and final bounded undo/redo arrays**. Compute whether this is a semantic no-op, preserve redo only for no-op. A command that changes only selection belongs to selection APIs, not history.
3. **Commit**: publish one coherent internal state switch. Project revision increments **only if the project value changes**; history revision increments once for each successful operation that produces a history notification, including a project-context replacement with an empty history stack. Status in `historyStatus()` is final before any observer runs.
4. **Notify synchronously** after full commit, in deterministic order: **(a)** project `subscribe()` listeners and configured `onChange` (if project changed), **(b)** profile selection listeners (if selection changed), **(c)** `subscribeHistory()` listeners (when operation committed). All callbacks, including project observers, can read final `project()` and final `historyStatus()`. **No microtask is needed for correctness.**
5. **Return** the documented operation result after dispatch. No asynchronous external effect participates in model commit.

Intra-stage callbacks are Base-owned only. The public contract guarantees project → selection → history **event classes**, but not registration order across independent listeners of the same class, except that listeners registered first are visited first if still subscribed. A listener may inspect state or unsubscribe during dispatch, not invoke mutating session APIs reentrantly.

**Observer exceptions after commit are not transaction failures.** Base must catch/isolate each one, finish **every** remaining project/selection/history callback in the declared order and return success for the committed operation. After the normal notification fanout, emit at most **one** frozen, minimal diagnostic for that transaction via `subscribeDiagnostics`: `{code:'CB_DESIGNER_OBSERVER_FAILED',phase:'project'|'selection'|'history'|'multiple',recoverable:true}`. Do **not** expose the original `Error`/message/stack, actor ID, title, document ID, answers, tokens or project bodies in events, HTML or logs. Diagnostic subscribers are isolated too; a failed diagnostic sink must not recurse, throw into the successful edit or block the other sinks. A constant code-only `console.warn` is permitted as a **last resort** and may not include exceptions/data. A mounted Shell displays a localized, keyboard-visible and screen-reader-announced generic refresh warning (`role='status'`, with a retry affordance), and dispatches **only the same minimal payload** via `cb:design-shell:diagnostic`; consumers can deterministically rerender via their public controller/adapter. Clear the warning after a proven successful repaint. A headless consumer must install a `subscribeDiagnostics` handler for its own visible error path. A DOM callback failure is **not** a rollback of external UI/network side effects.

### 3.2 Event shape, equality and frequency

- A history event is deeply frozen and contains **only** `{action, source, revision, historyRevision, status}`; no workflow definition, titles, private IDs, actor details or stack records. `status` is a frozen `{canUndo, canRedo, size}`. `revision` and `historyRevision` start at zero, monotonic per session. `source` is a **validated non-sensitive ASCII label**, `^[a-z][a-z0-9:-]{0,31}$`, and never an arbitrary filename, actor name or free-text error; `execute` uses `'command'`, `undo/redo` `'history'`, `clear` `'consumer'`, and replacement/rebase preserve a validated caller source.
- `subscribeHistory(listener)` rejects a nonfunction, subscribes once and **does not immediately emit**. Read the initial state via `historyStatus()` and subscribe before enabling toolbar interactions. Unsubscribe is idempotent, including after `dispose()`.
- `execute` committed change: exactly one project event and one history event with `action:'execute'`; profile selection event only if actual effective selection changes.
- `undo/redo` with available history: one project event if value changes, one history event, effective selection event if changed. They return `true`. Empty undo/redo: `false`, **zero notifications**, revision unchanged.
- `clearHistory()` with either nonempty undo **or** redo: zero project events, exactly one history event (`action:'clear'`, final status all false, `size:0`), historyRevision +1. Already empty: no notification or revision.
- `replace()` with a different project or nonempty history: clear undo/redo and optionally reconcile/reset selection in a single commit; exactly one history event even if status before/after is numerically equal (context identity can change). Project event only if value changed. Identical project + empty history + unchanged selection: no-op, no event.
- `captureSave()` is read-only for project/history, publishes no events, and returns a one-shot **session/context/generation-bound checkpoint** plus the exact submitted frozen project. `rebase()` rejects a stale, already-used or wrong-context token before changing anything; it **never accepts `currentProject` from the caller**. On success it retains granular undo/redo according to §4.1a, updates only the saved baseline and, when authoritative normalization requires safe replay, atomically substitutes the fully validated rebased current/history. Emit one `rebase` history event when acknowledged baseline metadata changes (even if undo counts do not); project/selection events only on actual change. No temporary baseline publish and no implicit history clearing. Failed CAS/replay has zero events/revisions/dirty changes.
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
| `captureSave()` | Active session | None; opaque checkpoint issued | None | None | Frozen checkpoint envelope |
| `rebase({saveToken,baselineProject})` | Checkpoint CAS, server baseline + replay validation | Atomic baseline + same latest draft/history or validated granular replay | At most one each, only if current/selection actually change | One `rebase` when baseline accepted, even if history depth unchanged | Frozen result with retained step count |
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

### 4.1 Save checkpoint, CAS acknowledgement and dirty-state authority (CR-01)

**Consumer-owned external trust:** stable document/workflow identity, WordPress permissions/nonces, server-verified persisted version/ETag, sanitized domain payload, request transport, server conflict resolution, draft/baseline UI and error recovery. Base does **not** infer successful persistence from client revisions or HTTP 200. Where overlapping HTTP writes are possible, the consumer/server must use authoritative write ordering / conditional version checks: **Base cannot prevent a stale server write**.

1. Immediately before submitting Save, call `save=session.captureSave()`. The return is a deeply immutable envelope with exact detached `submittedProject`, **session-private opaque token** and `revision` for diagnostics. The token is an **in-memory, object-identity-keyed** one-shot capability, not a bearer credential, exportable serializer, POST parameter or persisted object. Base stores checkpoint `{session identity, contextEpoch, monotonically increasing saveSequence, submitted project value/hash, history cursor identity and lineage}` internally. Never include token or project content in public notification payloads.
2. Build a sanitized **consumer-domain** request from `save.submittedProject` and the captured domain identity + expected **server** version. Consumer verifies the response came from the correct currently authorized object, was successful and committed, matches the current context, and contains a valid accepted baseline plus authoritative new server version. Response rejected/failed/aborted/unknown never calls `rebase()`.
3. `rebase({saveToken:save.token,baselineProject:serverAcceptedProject,source:'save-baseline'})` performs **Base-side compare-and-swap** before staging: token must belong to THIS active session and unchanged context epoch; checkpoint snapshot must be exactly the submitted value; token cannot be consumed; its sequence must equal the **latest issued** saveSequence and exceed the last successfully acknowledged sequence. Invalid, stale or cross-context tokens throw `DesignerSaveCheckpointError` with finite safe `code` (`SAVE_STALE`, `SAVE_CONTEXT_CHANGED`, `SAVE_INVALID`, `SAVE_REPLAY_CONFLICT`) and **zero mutation, history/event, dirty or baseline change**. No original project/body in exception text. Latest-issued-wins deliberately rejects Save A when Save B was initiated later, even if A returns first; the consumer reconciles/retries according to actual server version. A fresh `captureSave()` on the same unchanged editor is allowed after a rejected/failed request; failed tokens cannot silently poison newer saves.
4. Base reads **latest working `session.project()` itself** when rebase begins. There is **no** `currentProject` argument and no path for an outdated caller snapshot to replace a newer B. Current project revision may exceed `save.revision` when B was edited after submission; matching revision is **not required**, because that would reject legitimate in-flight editing. For normalized reconciliation, Base must additionally verify that the saved checkpoint remains on the current editor lineage or fail safely (§4.1a). A real `replace()` invalidates existing save checkpoints; `replace(...,{contextChanged:true})` forces a context epoch and token invalidation **even if old and new project values are equal**.
5. Base stages the complete acknowledgement transaction (accepted baseline, current working project, editor selection, undo/redo chain and revision metadata) without touching committed state. `baselineSnapshot()` returns a detached copy of the **last Base-accepted baseline** (initially the factory project); the consumer separately tracks server document version, publication/settings and whether an initial document is persisted. Commit updates baseline and, only where required by validated normalization, the current project/history in one switch. All observer classes see the committed baseline via `baselineSnapshot()` **before** the first project notification; one `history:rebase` fires when a changed baseline is accepted even if current/history count is identical. The returned `retainedUndoSteps` is the exact current undo depth, `normalized` indicates server baseline != submitted value; this result is not evidence of server authorisation.
6. A duplicate response/token, old Save A after later Save B, wrong workflow/document context, stale caller-provided draft attempt, invalid accepted baseline, replay conflict, denied server write, or disposed session must not move the project, saved baseline, redo stack, revisions or dirty UI. Failed acknowledgement presents a user-visible safe conflict/error and permits recovery with a new checkpoint, server readback and explicit retry; never claim all edits saved.
7. Dirty for a project-only consumer is **value equality** between its current domain-authoring projection and the accepted baseline projection, not history depth. Consumers also combine separate metadata fields (publication, subject, settings). Never treat `historyStatus().canUndo` as dirty. On successful save with no further changes, dirty clears; with B edits it remains dirty. If the user undoes to baseline dirty can clear and become dirty on redo. Consumer updates its own server version/dirty UI as a **post-commit presentation concern**; Base does not issue HTTP or manage document status.

### 4.1a Granular post-Save history and normalization rules (CR-02)

**Decision proposed to both P0 owners: preserve semantic command granularity, not a synthetic one-step `Unsaved edits` operation.** Retain prior meaningful undo/redo, including immediately after an ordinary Save; Save is **not** `clearHistory()`. Reversal of already-persisted edits changes the *current draft* and should be marked dirty; it never automatically reverses a server write, executed workflow, sent mail or already-rendered immutable report. A context switch still resets history.

- **Server accepted bytes/value equal to checkpoint:** update **baseline metadata only**. Keep live current project, selection, *all* existing pre-Save and post-Save undo/redo entries and cursor exactly as they were (including local undo/redo since Save). No `apply()` replay is needed. This is the common path and guarantees stepwise undo/redo A→B1→B2 without collapsing B1 and B2. Publish a single `rebase` history event for baseline metadata change, not a phantom edit.
- **Server normalized accepted baseline differs:** attempt a **pure, deterministic, in-memory, staged replay** of the actual **per-intent commands after the checkpoint** on the validated authoritative baseline, keeping command boundaries, stable IDs and the current cursor; reconstruct undo and redo snapshots from that chain. The checkpoint is a lineage anchor. If the current branch is no longer descended from that anchor, history was cleared, undo crossed the checkpoint with no safe rebasing route, or post-save command intents cannot be reliably reconstructed, reject `SAVE_REPLAY_CONFLICT` **without altering any state**. Command `apply` must remain synchronous/pure/replayable; command state captures/closures must be immutable, not live mutable DOM data.
- **Before-checkpoint undo preservation under normalization:** stage continuity of prior per-intent history and the normalized checkpoint boundary; validate both directions of the adjacent undo/redo edges. Never implicitly delete earlier steps. If a normalization would require pruning, merging, inventing commands or changing protected domain semantics, **reject** and offer recovery rather than silently truncate. No history entries are persisted to the server.
- **Lossless local intent validation:** validate every intermediate replayed project against Base envelope + consumer `validateProject`, and require an optional **consumer-controlled pure** `verifyRebase({submittedProject,acceptedBaseline,previousCurrent,replayedCurrent}) === true` when baseline normalized **and** the latest working project is not identical to the submission. Without explicit approval of the transformed projection, reject. This hook validates domain-sensitive equivalence/stable identifiers and cannot run network/DOM side effects. If any replay/verification throws, returns false, yields a Promise, or produces a no-op that would erase an authored command, abort with `SAVE_REPLAY_CONFLICT`. Never silently lose fields, discard workflow steps, or alter execution principal.
- **Undo/redo after a successful normalized rebase:** stepwise undo reconstructs B2→B1→accepted A; redo reconstructs A→B1→B2 in original intent order, including any staged still-redoable newer operations. Original before-A steps remain individually undoable only if the entire validated chain remains sound. Staging and publish are a *single transaction*. If an undo/redo lineage cannot be safely preserved, the normalized acknowledgement is **not committed**, leaving the draft/history intact for conflict resolution.
- **Explicit tradeoff:** rejection of some normalizations is safer than unapproved coarse history. Evaluator and Automations owners must confirm that this replay requirement is achievable for their command vocabulary and provide fixtures; **their sign-off is still pending**. A deliberate one-step history alternative would require a distinct contract revision and both owner approvals plus browser UX acceptance, not a hidden G1b implementation shortcut.

### 4.2 Context switch / Shell event lifecycle

Existing DOM contract: `[data-cb-design-launch-root]`, `[data-cb-design-shell]`, `[data-cb-design-shell-context] select`; `cb:design-shell:contextrequest` with `{value,previousValue,control,respondWith(promise)}`, followed on success by `cb:design-shell:contextchanged`. Base Designer Mode owns loading/busy/error, selected control rollback and active-shell visual lifecycle.

- `respondWith()` is accepted once **synchronously during event dispatch**; listener must return a Promise for the complete consumer transition. Missing or duplicate response fails visibly and never silently reports success. Current implementation already requires a response but an explicit error state for missing response should be tested as a G1b hardening item.
- Consumer checks dirty policy and pending saves, fetches authoritatively, validates target and permissions, prepares domain state and last commits one `replace(project,{source:'context-switch',contextChanged:true})` **or swaps in one new session after disposing old controllers**. The context reset must occur even for two distinct documents with byte-identical project values; it invalidates pending save tokens. Only then resolve. No URL/history update, earlier-context undo or selection leakage before success.
- Failure **before commit** rejects Promise: Base restores selector to `previousValue`, leaves previous project/history/dirty UI unchanged and provides visible error. No navigation, no fabricated `contextchanged`.
- A consumer must not commit a replacement and *then* reject a Promise: that would misrepresent current active context. If an error occurs after commit, the consumer must handle a defined recovery path and report the **actual** active context. Stale fetch/save responses, closed/disposed Designers and aborted generations must not commit.
- On genuine success: event order `project` → optional `selection` → `history(replace)` → Shell `contextchanged`; focus returns to meaningful context/active canvas, not hidden drawer. Base controls adaptive toolbar/drawers and in-progress loading presentation. No full reload.

### 4.3 Shell integration and life ownership

- `createDesignerShell(root, {session,defaultPanel,defaultPanels,onPanelChange})` requires either **a v2 public session** or explicit `null` for presentation-only shell. If non-null, require `historyStatus`, `subscribeHistory`, `undo`, `redo`; lack of contract must throw/visibly fail rather than use private `session.history` or `session.projectState` fallbacks.
- Initial synchronisation: subscribe to history then immediately read `historyStatus()`; each subsequent committed event updates Undo/Redo button states **synchronously** (no microtask). Subscribe also to `subscribeDiagnostics`; a warning is a user-visible recoverable render issue, not a reverted edit. Unsubscribe both in `shell.destroy()`. Session belongs to consumer, **Shell destroy does not dispose the session**; consumer disposes session exactly once, after destroying dependent controllers/listeners.
- `createDesignerShell` remains idempotent for same root+session, rejects binding an existing root to a different live session. For context switching, prefer replacing session's project rather than creating a second Shell. New session requires destroying Shell/selection/layers/navigation controllers before recreating it.
- Shell handles button clicks/keybindings only when target is appropriate (no interception while typing in text fields, contenteditable or IME composition). A single keyboard shortcut must cause **one** domain edit. Undo/Redo source authority is Base session; Automations removes its document-level duplicate keyboard history listener.
- The session's observer success does not mean Save succeeded. Shell owns **Save/Close presentation**, but consumers own Save callback and navigation/unsaved changes policy; no Base-side default save or silent discard. Close must not implicitly dispose an uncommitted domain draft before consumer policy runs.
- Presentation-owned public events remain `cb:design-editor:ready`, `cb:design-shell:ready`, `cb:design-shell:fullscreenchange`, `cb:design-shell:contextrequest`, `cb:design-shell:contextchanged`, `cb:design-shell:savechange` where currently supported. Only public names/details documented and tested in their respective component contracts; do not expose internal private DOM class names as behavioral selectors.

## 5. Automations P0: exact mapping to one Domain Session

**Current confirmed consumer:** `assets/js/admin-builder-history.js` on pinned Folders branch imports missing `createDesignerStateHistory`, observes `cb-automations:definitionchange`, records complete mutable definition snapshots, applies via `api.replace`, and resets after `cb:design-shell:savechange`. This is a *separate history authority* today and is not compatible with Base main.

**Proposed owner-owned domain adapter, based on Automations' received requirements, pending sign-off on these revised details:**

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

This is an **illustrative serialization mapping**, not a proposal to persist `DesignProject` instead of Automations' existing Definition/Codec. **CR-06: Automations owner sign-off required** on a sanitized/JSON-safe field allowlist, maximum serialized bytes/nesting/array length, canonical typed values (integer/float/string/boolean/null/arrays/plain maps without prototype pollution), 0/1/many trigger/action/condition/state fixture roundtrips, stable step IDs, provider drift errors, import/export parity, and independent folder/document identity. The authoritative persisted schema remains `DefinitionCodec`, with no workflow principal, credentials, tokens, live actor, runtime state or history copied into `DesignProject`. Tests must catch non-finite numbers, executable/unsupported types, over-limit values and silent defaulting. No actor/principal/credentials/execution context is invented or copied into editor history. The `definition` property holds only the *sanitised, authorable* definition state under the extension's own schema/limit contract. Exact step IDs, folders, typed bindings, imports and command vocabulary remain with Automations. A domain change must become `session.execute({label,apply})` at the sole mutation point; `cb-automations:definitionchange` becomes a **view/read-model notification**, not an input into a second recorder. Editor `api.replace()` must be used only for authorised context replacement/recovery, not each keystroke or node move.

- Each workflow editing intent must map to one **pure** domain command, with undo/redo restoring definition and rendering via project observers. Current `onApply` callback and `history.record/reset` are removed completely.
- `session.subscribeHistory()` enables Base shell controls; no separate Ctrl/Cmd+Z document listener; preserve local native text-editor undo semantics and reduced-motion animation (`animateLayoutChange` only around visual rendering, not state authority).
- Post-save uses `captureSave()` and guarded `rebase({saveToken,baselineProject})` against the *actual* live draft, with per-intent commands preserved; Automations supplies its pure `verifyRebase` predicate and server CAS/nonce policy. Folder moves do not reset a workflow's history unless they change its active document identity. A real workflow context switch calls `replace(...,{contextChanged:true})` and invalidates pending saves; **renaming a folder does not grant workflow execution authority**.
- Template instantiation uses extension's authorised endpoint; output always `DISABLED`, `execution_principal_user_id=0`, no copied credentials or literal secrets, no automatic job/run. Template Browser selection cannot call session command and thereby activate a workflow.
- Still requiring Automations owner *specific* v2.1 signature acceptance: exact editable allowlist/size limits, command grouping and replay purity, server CAS race handling, folder identity and triggered `definitionchange` semantics. The original P0 requirements report is received; this is a narrower **new-contract sign-off**, not an unreceived audit.

## 6. Evaluator P0 and Base first-party consumers

- **Evaluator:** retain its already-migrated P02 `createDomainSession` instead of backporting obsolete low-level classes. Evaluate new v2 `captureSave`/`rebase` and optional `verifyRebase` for stable question/option/rule/result revisions with per-intent history. Adopt **mandatory** public `createDesignerNavigation` without older-Base `window.cbCore?...` fallback; promote `createDesignerInspectorSection` and `enableDesignerTextareaAutosize` to explicit supported imports. Keep frontend `cb:evaluator:result` minimal (evaluation ID + result IDs only); do not leak scores/answers, and preserve its second-meta-write rollback. Confirm save baseline response/current dirty behavior with Evaluator Luna.
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
- **CR-03 decision:** `Assets::enqueue_designer_mode($translatedTitle)` is the **presentation-complete, standalone supported interface** for full-page Designer Mode; it enqueues all necessary Base composition/toolbar/form/controls styles exactly once. Evaluator removes its accidental direct `DESIGNER_COMPOSITION_STYLE` dependency and treats `enqueue_designer_mode()` plus the public capabilities as authoritative. `DESIGNER_COMPOSITION_STYLE`, `DESIGNER_MODE_STYLE`, `SHELL_STYLE` and physical CSS file paths are **not supported direct extension dependencies for Designer Mode**. Existing PHP constants may exist internally until the all-consumer impact ledger is closed, but are not a compatibility commitment. Do **not** introduce a replacement public style handle for legacy page registration. A *new* semantic embedded-mode style contract may be proposed only upon an identified modern use case, and requires separate named API, ownership, correct enqueue ordering and regression tests before release.
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
- Read-only global browser: admin privilege **and source ACL**; contextual browser: per-user short-lived server-issued `DesignerScope`, `X-WP-Nonce`, `X-CB-Designer-Scope`; exact extension/designer scope is server enforced. GET metadata remains bounded, local-only. `TemplateQuery` max 48, provider filters before stable paging, opacity of HMAC cursor (actor+query+scope+source set), token TTL 15 min and session binding; allow **multiple valid same-actor/same-WP-session/same-extension+Designer read-only GET requests** within TTL. **Replay denial** means forged token, use after expiration/session change, another actor/session, or other Designer/scope; NOT a second legitimate GET with the same token. No one-time nonce consumption or token persistence/rotation is introduced. `DesignerScope` is read-only browsing scope, **never** POST/create/activate/deletion permission; all writes require fresh nonce + actor/item ACL independently. Never place scope tokens into URLs, logs, storage, exported HTML or external calls.
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

**Coordination:** Overseer has received and accepted both P0 **requirements reports**. Their approval of **this specific revised CAS/replay contract** is still pending. Base changes only Base; the two owners own consumer adapter tests and safe migrations. This document is not a licence to edit application code.


### 9.1 Capability / ABI and export removal release gates (CR-07)

- `Assets::DOMAIN_SESSION_API=2` is **one cohesive version for both factory instances**, tested by `createDomainSession()` and all supported `createSession({profile})` values. The version advertises `captureSave`, `baselineSnapshot`, `rebase`, `subscribeDiagnostics`, `subscribeHistory` and settled pre-notification reads. Do not advertise `2` until these methods actually exist in the **same built Base ZIP** alongside current named Inspector/Navigation/Layers exports and the matching PHP class constant.
- A first-party import scan must cover at least Base Mail/Reports, Automations, Evaluator, Newsletter, Certificates, Commerce and any remaining local source modules. Record exact import path, symbol, consuming branch, proposed migration and tested status. `ProjectState`/`EditorState`/`CommandHistory`/direct named command exports that were never contractual are removed **only at a subsequent coordinated cleanup gate once impacted owners have migrated**. This is a finite gate and not an endorsement of indefinite legacy/alias publication. No unknown consumer silently broken.
- Executable PHP and WordPress-resolved ESM feature checks must use **one pinned commit/package**; a PHP gate or source string check in isolation is not proof. A mismatched install fails visibly with a safe native authoring path when available, never a silent private-history fallback.

## 10. Ordered steps **after** a separate G1a CONTRACT GO

1. Re-pin Base main/feature SHAs; inspect developer handbook/toolchain and safe checkout; complete all first-party public import/selector/handle usage search. Overseer publishes final accepted API manifest and P0 migration ledger.
2. One new dedicated Base-only feature branch: internal history transaction ordering, subscriptions, no-op/rollback/reentrancy, opaque `captureSave` checkpoint + strictly guarded `rebase` + validated granular replay if separately approved, diagnostic fanout and Shell private fallback removal; focused JS tests first. No discretionary UI redesign.
3. Same Base branch, bounded category interface/registry/browser changes with actor/scope security negative tests; maintain existing storage boundaries.
4. Document and test public Inspector, Navigation, Layers, direct/manual Shell, CSS semantic dependencies, module/PHP capability manifests. Source tests **plus executable built-module import probes**.
5. Base Mail + Reports source/build dogfood on pinned commit; then Automations and Evaluator each migrate **in their own repositories** and independently report executable integration results. Source/build is not installed acceptance.
6. Only on approved isolated environment and explicit separate operator GO: WordPress installed tests, mobile/keyboard/a11y/Light-Dark/six locales and owner field acceptance. No merge, CI, release, install or DB writes without explicit authority.

## 11. Requested G1a decisions, distinct from implementation GO

**Already direction-approved:** (A) one history engine behind Domain Session; (B) no legacy; (C) v2 number and history subscription direction.

**Outstanding for Overseer and both P0 Lunas:**

1. Accept or amend exact post-commit project → selection → history notification ordering, payload fields, no initial history callback, observer isolation and no-op/rollback/reentrancy semantics.
2. Approve or revise exact `captureSave()` + `rebase({saveToken,baselineProject,source})` **CAS and granular history** semantics, including retry/conflict UX, and confirm both profile and domain sessions expose it. **Automations and Evaluator owners must sign off command/replay feasibility; no assumed assent.**
3. Confirm Automations' concrete serialized workflow `DesignProject` adapter, command granularity and sensitive-data constraints; confirm Evaluator's save/validation behavior and required profile parity.
4. Confirm category API signatures/bounds and pre-filter-before-provider requirement, source/category filtering and scope token security.
5. Confirm `enqueue_designer_mode()` is a self-contained public presentation API and Evaluator removes direct `DESIGNER_COMPOSITION_STYLE` dependency; approve all-consumer import/asset ledger before pruning implementation exports. No public CSS handle for historical use.
6. Approve the executable test matrix, consumer exact SHA pairings and no-merge/no-runtime gate sequence.

**Next action:** Overseer reviews this **full revised Markdown** and expanded test matrix against CR-01..07; collect targeted Automations/Evaluator **sign-off on granular replay and normalization conflicts**, then issue one definitive **CONTRACT GO** if satisfied. Only a *separate* implementation GO authorises Base code writes. The earlier Overseer architecture-direction GO is not that permission.