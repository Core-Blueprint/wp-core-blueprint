# Base Designer G1c | Public session API and consumer parity handover

**Date:** 2026-10-16  
**Authority:** Owner G1c implementation GO after operator-proven G1b Level 1 + Level 2.  
**Repository:** `Core-Blueprint/wp-core-blueprint`  
**Feature:** `feature/designer-g1c-public-api-parity-20261016`  
**Frozen G1b parent:** `477e174f10d71369165c011e6b6d26cf2a22319e`  
**Status:** SOURCE CANDIDATE, JS/PHP/consumer gates PENDING. No version bump, merge, CI, releasebuild, deployment or database action.

## Source authority / acceptance boundary

- Original normative proposed API and granular-save semantics: [G1a v2.1 contract, review branch](https://github.com/Core-Blueprint/wp-core-blueprint/blob/docs/designer-foundation-g1a-v2-review-20261016/docs/engineering-review/core-blueprint-base-g1a-contract-v2-20261016.md); 100+ scenario matrix is on that same branch.
- G1b accepted full source and isolated WordPress integration for **exact parent SHA**: WordPress 7.0 / PHP 8.4.26, 904 PHPUnit tests / 12,227 assertions, complete 12-gate matrix, final parity receipt, `--inspect` PASS. The original Level 2 evidence belongs to G1b SHA and **does not carry forward to any G1c source change**.
- The G1c patch records public method parity by **actually instantiating** Domain, Mail, Flow and Fixed sessions through the same public module loaded in the Node smoke fixture. Tests assert frozen events, Save/Undo/Redo retention, baseline semantics, disposable sessions and context-token invalidation. These source tests must now be executed by the operator.
- The public doc now describes the G1c source candidate without advertising public v2. Historical v1 project observers have final settled history synchronously and no required `queueMicrotask` workaround.
- `CoreBlueprint\Core\Design\Editor\Assets::DOMAIN_SESSION_API` **remains 1**; it must not become 2 until the same real canonical release artifact proves PHP constant, WordPress import-map/ESM named exports, and both actual factories. An isolated PHP source-string assertion or copied .mjs source smoke does **not** prove the ZIP ABI.
- **Do not remove** presently exported `CommandHistory`, `ProjectState`, `EditorState`, direct command functions or composition handles in G1c. These are not endorsed as public for future consumers but existing source imports remain.

## First-party consumer/import impact ledger (read-only at G1c start)

| Consumer | Exact inspected ref | Actual imported Base API / dependency | G1c decision |
| --- | --- | --- | --- |
| Base Mail | G1b parent `477e174f...` | `assets/js/features/mail-designer.js`: `createSession('mail')`, `commands`, Inspector, Layers, Shell, selection, Mail profile; subject/fingerprint and AJAX save remain consumer-owned | Preserve allowlisted HTML, inline editing, preview, template and subject persistence. No save transport migration without independent server/readback/CAS review |
| Base Maintenance Reports | G1b parent `477e174f...` | `assets/js/features/reports-preferences.js`: `createSession('document-flow')`, shared Shell/Layers/Inspector, flow preview | Preserve immutable report snapshots, report Composer and PDF; do not infer template storage ownership |
| Automations `main` | `e0049f672c569c3759fa07d826a6c5223e8d5244` | `assets/js/admin-builder-history.js` imports **low-level** `CommandHistory, EditorState, ProjectState`, private history button + shortcut adapter | BLOCKED for v2. Automations-owned one-session adapter + server CAS required; Base export removal forbidden |
| Automations Folders W3.1 | `f4f7e23c61ca2b9448cfd03615b5dc66b8b1106d` | G1a v2.1 contract records missing `createDesignerStateHistory`, pending W3.1 reconciliation | Consumer owner controls migration, command granularity, workflow codec, stable IDs and folder independence; no Base fallback/export shim |
| Automations Template Categories | `ccc76ea0a82666f1da585465d5215e779f4aa689` | Divergent candidate noted in G1a review | Reconcile in Automations ownership, **not** by blind Base cherry-pick |
| Evaluator `main` | `d9a439f0a6fa675ff23eb653ceab649e6fb801b2` | `assets/js/admin/evaluator-designer.js` still imports low-level `CommandHistory`; `src/Admin/DesignerAssets.php` references `DESIGNER_COMPOSITION_STYLE` | No low-level export removal; do not change evaluator storage/Rules/Results on Base branch |
| Evaluator Golden/P02 | `63af69d0746cce7ad10bcbf7c09fb7cad954a4f1` | G1a review reports P02 Domain Session consumer, awaiting **specific** CAS/rebase sign-off | Evaluator owner accepts Rules/Results IDs, save/reopen, error handling and rollback on their own branch |
| Newsletter `main` | `034ce4ffd57f95f57f1bd9e4aaf226e0d224b9a6` | `assets/js/designer.js` uses `createSession('mail')`, `createDesignerShell`, Mail profile and commands | P2, no patch in this G1c batch; keep consent/unsubscribe and campaign snapshot guards |
| Certificates `main` | `81b7c99a5541f11c63a807fe65f210981bc3d123` | `assets/js/design-editor.js` uses `createSession`, public profile and a first-party history adapter | Do not remove its imports or rewrite storage/selection without owner approval |
| Commerce | Not verified: initial guessed repository path not found | Import/style/DOM usage unknown | Separate repository discovery/inventory before any cleanup; never claim compatibility |

The ref pin is a **snapshot**, not a claim that remote consumer code has been migrated, tested or installed. Automations and Evaluator new save adapters must independently prove privacy filtering, domain model roundtrip, server version guards, immutable IDs and per-intent post-save history. Base alone cannot prevent an earlier HTTP request from overwriting a later server write.

## Public G1c source assertions

Both factories expose `project`, `snapshot`, `replace`, `execute`, `undo`, `redo`, `historyStatus`, `subscribeHistory`, `subscribeDiagnostics`, `clearHistory`, `captureSave`, `baselineSnapshot`, `rebase`, `dispose`. A single internal `CommandHistory` class backs both factories. Test actual `mail`, `document-flow`, `document-fixed` and domain projects, each with a real Edit / Save acknowledgement / Undo / Redo path. Plain project value equality, not undo depth, drives dirty state; subject/settings remain outside the core baseline.

Canonical Shell requires public v2 history facade or null for a presentation-only Shell. `session.history`/`projectState` fallbacks and microtask state compensation remain forbidden. Observer-failure notifications contain safe frozen code-only diagnostics.

## Required subsequent gates, in order

1. Operator fetch this G1c branch, compare exact HEAD, run affected Node tests and `bash tools/check` (Level 1). Fix any regressions **only on G1c branch**, never on accepted G1b parent.
2. Review tested Base Mail/Reports integration on the same G1c HEAD and produce positive/negative consumer regression receipts. No server behavior migration until explicitly designed and approved.
3. On a **separately approved fresh isolated test slot**, perform WordPress Level 2 for G1c exact HEAD. The G1b slot/database/lock remains protected and must not be reused, reset or cleared.
4. Only after separately approved **canonical releasebuild**, prove ESM module functions, global facade, PHP `DOMAIN_SESSION_API === 2` **in the same exact-built ZIP**, import-map/module assets, dependency styles, package root, ZIP integrity and SHA-256. Do not bump 2 prematurely; plan the bump and new Level 1/Level 2 test cycle before that packaging gate.
5. Automations and Evaluator owners independently provide G1a v2.1 consumer sign-off, codec/CAS/error/readback acceptance, and functional field evidence.
6. Separate owner GO required for merge, CI, deployment, publication, field installation, archive and cleanup.

**Read-only local commands after Git fetch:**

```bash
node --test tests/js/design-editor-public.test.mjs tests/js/design-editor-core.test.mjs tests/js/design-mail-profile.test.mjs tests/js/design-shell-profile-proof.test.mjs
bash tools/check
```

**Not performed in this assistant environment:** real Node, PHP lint/PHPUnit, isolated WordPress, package build, ESM from ZIP, field tests. The operator's accepted G1b Level 2 receipt must not be relabelled as G1c acceptance.
