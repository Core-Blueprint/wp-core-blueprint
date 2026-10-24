# Designer Foundation v2 — B1 Shared Context Transition Contract

Date: 2026-10-24  
Scope: Base-owned context transition authorization for external Domain Session consumers  
Parent: `feature/designer-foundation-v2-polish-20261023@f847b4b6d2e88b5057b208498e18b4099d78bf82`  
Release state: SOURCE candidate; installed WordPress and exact-artifact gates remain open.

## Ownership and public interface

Base owns the existing Designer Exit Guard, single Save/Discard/Cancel Modal, one capture-phase context-control handler, and the finite authorization window before loading a new context. External consumers own their domain state, authoritative persistence/CAS/receipt verification, target loading, validation and atomic replacement.

`window.cbCore.designerExitGuard.requestTransition(shell, applyTargetContext)` is B1's new public API (Exit Guard API 2). The callback is synchronously invoked exactly once after verified authorization, and returns a Promise that resolves **only after** the consumer actually applied the target; rejection or a resolved `false` means failure. Base holds one per-shell lock across the Modal and the entire target Promise. Base never invokes the callback on Cancel, missing authority, UNKNOWN, busy Save, or failed post-Save clean recheck.

Results are frozen `{status: 'applied', result}`, `{status: 'cancelled'}`, `{status: 'blocked'}`, or `{status: 'failed', error}`. The shared controller updates the selected option and emits `cb:design-shell:contextchanged` **only** on `applied`. Consumer exceptions are not printed in the shared user-facing toast.

`isTransitionDispatchAuthorized(shell)` is an internal read-only handshake used solely by Base's already-registered capture listener. It is true only during the synchronous guarded consumer `cb:design-shell:contextrequest` dispatch. It is not a transferable permission token or a second authorization API. Direct event dispatch without this window remains blocked in capture.

The consumer event has unchanged `detail: { value, previousValue, control, respondWith }`. `respondWith` must be called once **synchronously** during the event, with a Promise that rejects if loading, validation or replacement fails. Consumer domain context must not be mutated before this authorized event. No consumer may dispatch a second change event, replay an approved target, or start its own Save/Discard Modal.

## Safety decisions

- Clean with no active/uncertain Save: proceed without modal.
- Dirty with known Save state: show the Base-owned Save / Discard / Cancel Modal.
- Save: `adapter.save()` must return **exactly true** after server-authoritative persisted success and successful local rebase. Base then checks `adapter.isDirty() === false`, no UNKNOWN, no busy state and unchanged registered adapter.
- Discard: explicit user approval only; never accepted when Save is busy or uncertain.
- UNKNOWN, unresolved, missing adapter, stale/detached shell, adapter replacement: blocked.
- One shell owns one transition or exit at a time; other attempts are rejected without extra modal.
- Authorized event is dispatched only once. Base's context controller marks the previous shell inert and busy while awaiting the target Promise, restores interaction afterward and never shows switch-success for failure. Consumer replacement must remain atomic; partial replacement and rollback belong to that consumer.
- Native Mail and Reports retain their independent Host/Save lifecycle; this shared external context-control path does not modify their registered native profile or document contracts.
- Domain-neutral `schema_version: 0` remains the external public Domain Session project generation. Native generation 1 still requires verified Host registry context.

## Consumer adoption: Automations A2

1. Keep the **one** existing `designerExitGuard.register(shell, {isDirty, isUncertain, save, labels})` adapter. Its `save()` must yield true only after the verified receipt and Base History rebase; returning true because an HTTP request completed is not permitted.
2. Delete the Automations-local `chooseSwitchAction`, `awaitingChoice`, `approvedSwitchUrl`, and the synthetic `change` event replay. They are obsolete under B1.
3. Handle one `cb:design-shell:contextrequest` synchronously: validate the supplied target; call `event.detail.respondWith(switchContext(target))`. `switchContext` must reject on failed validation, fetch or atomic application.
4. Never attach a second Modal or remove Base's capture listeners. Keep consumer-owned CAS, workflow authorization, endpoint, dirty predicate, and Save receipts.
5. If possible keep target application atomic (prepare, verify context identity, then replace once). Do not display a consumer switch-success before `respondWith` resolves.
6. Test dirty Save, Discard, Cancel, UNKNOWN, duplicate selector interactions, late edits, second-tab conflict and target-load rejection in the installed browser.

## B2 proposal, not implemented

The public `createDomainSession()` has the canonical `ProjectState`, `EditorState` and `CommandHistory` but does not expose a Selection facade consumed by `createDesignerSelectionController()`. Review the minimal shared methods `selection()`, `select(refs,primary)`, `subscribeSelection(listener)` and `clearSelection()` on the same existing EditorState; specify node/entity references and domain-specific Inspector adapters. No separate History, native Host registration or second Selection store.

## Evidence and open gates

The B1 changes are SOURCE-only until the candidate passes `./tools/check`, a clean-HEAD `./tools/build-dev`, installed ZIP identity and WordPress browser acceptance. The existing accepted `main` checkpoint is not a B1 test result. Do not merge, run CI/CD, publish, or deploy without separate user authorization.
