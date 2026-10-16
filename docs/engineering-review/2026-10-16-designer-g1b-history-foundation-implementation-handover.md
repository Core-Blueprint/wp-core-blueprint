# Base Designer G1b History Foundation | source candidate handover

**Status:** SOURCE CANDIDATE, NOT TESTED OR RELEASED. Implementation GO only on the feature branch.

- Repo: Core-Blueprint/wp-core-blueprint
- Branch: feature/designer-g1b-history-foundation-20261016
- Base pin: main 00975654517f657ca95be7a0dc0d901e95cb764c
- Contract pin: ae1fe24157a79e03efea8a08cf8560f62adec316 (G1a v2.1)
- Do not merge, run CI, reset a database, install WordPress, deploy, publish, or build release under this permission.

## Code boundaries

- assets/js/design/core/history.js: one canonical CommandHistory transaction coordinator; stage/commit/notify; undo/redo and semantic no-op; synchronous history events and diagnostics; opaque checkpoint CAS; latest-issued-wins; same-baseline Save preserving history; normalized per-intent replay with safe conflict.
- assets/js/design/core/project-state.js: project staging, silent commit and exact rollback.
- assets/js/design/core/selection.js and editor-state.js: staged selection commits and pre-notification rollback.
- assets/js/design/core/value.js: semantic JSON equality.
- assets/js/design/editor.js: public G1b API in domain and mail/document profile session factories.
- assets/js/design/shell/index.js: public history listener and diagnostic, private-history and microtask fallback removed.
- tests/js/design-editor-public.test.mjs and design-shell-profile-proof.test.mjs: new session/history/save/observer and synchronous Shell checks.
- tests/integration/DesignerHistoryChromeContractTest.php: old expectations replaced with public history API assertions.

## Evidence and blocker

All changes were written through the connected GitHub repository service, not an available local clone. Remote ref/diff and limited read-only source-shape checks were performed. No Node/PHP/tests/check, PHPUnit, package build, browser, WordPress or database run has occurred here. No test PASS is claimed.

The public PHP Assets DOMAIN_SESSION_API value stays at 1. It may be changed to 2 only after the entire advertised JS/PHP contract is verified from the same built Base artifact under separately authorized isolated release/test gates. Automations and Evaluator must not consume this source as a verified v2 artifact yet.

## Operator Level 1 safe commands

    cd ~/Downloads/wp-core-blueprint
    git status --short
    git fetch origin --prune
    git switch --track origin/feature/designer-g1b-history-foundation-20261016
    git rev-parse HEAD
    git status --short
    node --check assets/js/design/core/history.js
    node --check assets/js/design/core/project-state.js
    node --check assets/js/design/core/selection.js
    node --check assets/js/design/core/editor-state.js
    node --check assets/js/design/editor.js
    node --check assets/js/design/shell/index.js
    php -l tests/integration/DesignerHistoryChromeContractTest.php
    node --test tests/js/design-editor-core.test.mjs tests/js/design-editor-public.test.mjs tests/js/design-shell-profile-proof.test.mjs
    bash tools/check

Inspect a dirty local worktree before switching. Do not force-checkout/reset. Level 1 checks are source-only. WordPress integration runs require separate isolated database approval.

## Pending acceptance criteria

1. Capture actual syntax, Node, PHP source Level 1 and Composer/Python suite outputs. Repair any failing tests on the feature branch only.
2. Verify full event order, failure atomicity, replay and observer lifecycle, saving A/B out of order, historyLimit truncation, context epoch, undo/redo crossing a save, sanitized diagnostics and explicit recovery.
3. Test Base Mail and Maintenance Reports in source fixture and separately authorized browser runtime.
4. Check PHP capability against actual built ESM from the same verified package before activating API 2.
5. Confirm source graph/imports and wider consumer impact ledger before later removal of private named exports.
6. Request separate GO for isolated runtime/build, checksum, integration, merge, CI or deployment.

## Known risks

- Untrusted consumer command closures are only replayable when pure and deterministic. A normalized baseline may safely raise SAVE_REPLAY_CONFLICT instead of destroying earlier undo history.
- Shell diagnostic retry requires a consumer to acknowledge cb:design-shell:refreshrequest. No reload that could lose the active draft.
- Profile Mail/Reports rendering and permission/persistence semantics have not been field-tested. Template Categories and other UI Golden work are outside G1b.

**Overseer handover decision:** source candidate ready for operator Level 1 validation; no implementation acceptance or Golden yet.
