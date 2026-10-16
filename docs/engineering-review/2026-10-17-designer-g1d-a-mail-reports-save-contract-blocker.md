# Designer G1d-A | Base Mail & Maintenance Reports reference save-contract blocker

**Date:** 2026-10-17  
**Requested by:** Overseer G1d-A scope-review instruction  
**Base repository:** `Core-Blueprint/wp-core-blueprint`  
**New source branch:** `feature/designer-g1d-a-mail-reports-reference-20261017`  
**Exact starting SHA:** `ece1fa6d3b851a798a0fb4e2da14bbbe79b219b1` (accepted G1c Level 1 + Level 2)  
**Status:** **G1d-A SOURCE ANALYSIS / ARCHITECTURE ESCALATION REQUIRED, BEFORE IMPLEMENTATION**. No new behavior patch, full integration tests or package built. This document is an audit, not a claim of runtime reproduction.

## 1. Current state and audit limits

- Remote Base G1c branch still resolves to the exact accepted commit; `main` remains `00975654517f657ca95be7a0dc0d901e95cb764c` at inspection.
- New feature branch created from that immutable accepted SHA. No Base application source files have been edited in G1d-A.
- The assistant can check GitHub remote branch refs and files, but **cannot observe the operator's local `~/Downloads/wp-core-blueprint` worktree**, run WordPress/PHPUnit there or safely claim it is clean. The operator must check local status, current branch and exact SHA before any local commands.
- GitHub source inspection identified cross-boundary behavior requiring a **server-side save acknowledgement and stale-write policy**, not simply additional JS assertions. The user instructed Base Luna to report first when a larger architectural change is required.

## 2. Confirmed code paths, with precise source references

### R-01: Reports Save silently clears history (confirmed by source)

`assets/js/features/reports-preferences.js`:
- `createSession({ profile:'document-flow', ... })` owns a canonical Base history stack (near lines 193–206).
- `applyComposer(next)` calls `session.replace(projectFromComposer(composer), {source:'server',resetSelection:true})` (near lines 335–347). Per Base's accepted `CommandHistory.replace` semantics this resets undo/redo and context.
- Save success (near lines 541–578) calls `applyBranding(response.data)` and `applyComposer(response.data?.template)` and then `setSaveState('saved')`. Reset success (near lines 580–622) does the same.

**Contract contradiction:** G1a v2.1 specifies ordinary Save updates the accepted baseline using `captureSave()` + `rebase()`, **retaining bounded pre-/post-save Undo/Redo**, including redo branches. `replace()` is for an authorized document/context reset, not Save. This finding is from actual source logic, not a performed UI reproduction.

### R-02: Reports in-flight editor updates and cross-request writes are unguarded

Reports save obtains the live `reportsPayload()` immediately before the async POST, then unconditionally applies the returned server template to the then-current session. If the operator changes the Composer while awaiting the response, the accepted older template can overwrite newer local edits via `replace`.

`src/Ajax/Handlers/Branding.php::save()` (near lines 38–81) normalizes input, reads settings, calls `Settings::set_key('reports', ...)`, and returns `$branding + ['logo_url'=>...,'template'=>$template]`. **The inspected handler shows no conditional persisted-version/ETag or compare-and-swap gate**. A client-only request-sequence guard would not prevent stale server writes, especially across tabs.

**Status:** plausible lost-update scenario inferred from source; not yet a tested incident, and report snapshots/PDFs have not been shown corrupted.

### M-01: Mail save success is not an authoritative baseline receipt

`src/Mail/Admin/TemplateActions.php::save_ajax()` (near lines 40–56) returns **only** `['message' => $result['message']]` on success. `TemplateRepository::save()` validates the project, sanitizes subject and saves the template override through `update_option`, but the response does not disclose an independently verified canonical persisted project/subject or version/hash. `assets/js/features/mail-designer-save.js` (near lines 20–46) dispatches generic `saving`, `saved` and `error` events. `assets/js/features/mail-designer.js` (near lines 767–781) treats the checkpoint-time fingerprint as persisted on the generic `saved` event.

Mail can preserve edits made during an in-flight save in its UI fingerprint comparison, but a returned success message alone is insufficient for a **verified v2 Base `rebase`** of the server-accepted project, sanitized subject, and authoritative version. The current save adapter aborts a previous request; aborting the client does **not** guarantee that a previous HTTP POST did not commit server-side.

**Status:** absence of an accepted baseline/version receipt is verified; no existing Mail content-loss incident is asserted.

### Safety boundaries that must be preserved

- Mail HTML is allowlisted by the existing template/renderer validation pipeline; the subject is separately sanitized. Do not weaken permission, nonce, sender neutrality, template ownership or preview output.
- Reports current Composer/branding, preview host and immutable persisted report snapshot -> PDF pipeline are independent. Do not let editor undo reverse generated PDFs, overwrite report snapshots, or mutate finalized reports. Inspect `src/Reports/DesignerPreview.php`, `MaintenancePdf.php`, `Storage.php` before any changes.
- Existing G1c public Domain/Profile session history contracts are accepted. Do **not** change history engine, bump `DOMAIN_SESSION_API`, introduce `createDesignerStateHistory` or remove low-level exports in G1d-A.

## 3. Existing regression coverage, not to duplicate

- `tests/js/design-editor-public.test.mjs` includes G1b/G1c true command/save/Undo/Redo/session lifecycle checks for all four public session factories. Do not duplicate those as new Mail/Reports tests.
- `tests/integration/MailDesignerSaveInPlaceTest.php` tests canonical AJAX/admin-post route, adapter + shell source contracts and fetch cancellation, but does **not** prove server accepted baseline + concurrent editor change + undo after Save.
- `tests/integration/ReportsDesignerCompositionContractTest.php`, `ReportsDesignerPreviewSampleTest.php`, `ReportsComposerCompilerTest.php` and `MailProjectRendererContractTest.php` cover existing renderer/contract slices; they do not prove the combined async Save/Undo/Redo/refetch integrity for the reference consumers.
- `tools/check` Level 1 is source-only. G1c isolated WordPress Level 2 receipts are for G1c exact SHA only, never an automatic PASS for a future G1d-A patch.

## 4. Minimal revised G1d-A contract decision requested from Overseer

**Do not quietly patch R-01 by merely replacing `replace` with `rebase` while leaving server-confirmation and context rules unspecified.**

Overseer should decide explicitly whether G1d-A includes these **bounded existing-consumer** server and browser changes (not a new shared Foundation architecture):

1. **Authoritative save response** for each consumer: stable template/scope identity, sanitized accepted project and Mail subject or Reports Composer+branding, monotonic persisted version/hash; response bound to the actual persisted result, including readback. Avoid false `saved` based solely on HTTP 200 or a user-visible message.
2. **Optimistic server-side stale-write policy**: conditional expected server version/ETag supplied with authorized nonce and actor; reject mismatches without overwriting saved content, including across tabs and overlapping requests. Avoid naïve read/then-write race; use an established safe atomic update strategy or explicitly separate that datastore change for review.
3. **Base checkpoint use on Save**: `captureSave()` before serializing the request; on verified accepted acknowledgement call `rebase({saveToken,baselineProject})`, not `replace`, retaining per-intent history. Domain normalization that cannot be safely replayed must surface `SAVE_REPLAY_CONFLICT` with draft/history intact.
4. **UI dirty state from accepted values**: Mail combines subject + project identity; Reports combines Composer + branding fields; track pending Save, in-flight edits, failed responses and mismatch without displaying false Saved; never reset unrelated transient selection.
5. **Dedicated behavior tests** for actual save/reopen/failure paths, including dirty when user undoes after Save, redo restores clean state, two HTTP request ordering scenarios, wrong context, stale version and malicious/invalid reply; PHP storage/readback verified under authorized isolation. Reuse existing Base history tests rather than copying them.
6. **Reference rendering invariants**: Mail HTML/subject roundtrip and allowlist; Reports preview, PDF from immutable stored snapshots and lack of mutation from Undo/Redo/Reset, with current tests extended only where their end-to-end boundary is missing.

If this enlarged save-persistence contract is **out of scope**, approve a smaller G1d-A1 of read-only behavior-test inventory and source-level gap recording, mark G1d-A reference consumer acceptance **BLOCKED**, and assign server write CAS design to a separate phase. Do **not** mark Mail/Reports v2 reference save acceptance PASS under the current optimistic receipt.

## 5. Gate and authority reminder

**Next required action:** Overseer review of R-01, R-02 and M-01, giving a **specific** decision on the bounded server acknowledgement/version guard and test isolation. No Base source correction is applied while this architecture-impact escalation is pending.

No WordPress Level 2, test DB actions, releasebuild, CI, merge, cleanup, deployment or publishing under the scope-review instruction. No runtime test has been run in this assistant session.
