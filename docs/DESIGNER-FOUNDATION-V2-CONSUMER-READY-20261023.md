# Base Designer Foundation v2 — Consumer Ready candidate

**Date:** 2026-10-23  
**Owner:** WP Suite Overseer / Base  
**Repository:** `Core-Blueprint/wp-core-blueprint`  
**Integration branch:** `feature/designer-p0-save-toast-reconciliation-20261023`  
**Authority:** source candidate for owner-approved DF-01 through DF-05, not a main merge or public release. Resolve the exact candidate with `git rev-parse HEAD` when building; do not reuse an older ZIP or checksum.

## Product freeze and scope

**Goal:** Base supplies one shared, stable Designer Foundation that Evaluator and Automations can consume through the public Base API after the accepted Base build is merged to `main`. This is not a new Designer framework or an external native Host-profile registration feature.

Included:
- `@cb-core/design-editor` public `createDomainSession()`, History, `captureSave()`, `rebase()`, shared Designer Shell, Layers, Inspector, Navigation, DnD and Toast.
- Existing Base native Mail and Reports profiles continue to edit with one authoritative Save stack; WordPress Admin stays canonical.
- **DF-01:** `createDesignerLayerTree()` accepts semantic remove confirmation on an `actions.remove` config, including a synchronous descriptor factory. Base owns the accessible dialog and final user confirmation. Consumers supply truthful domain semantics and registered authorization/mutation callbacks. Default parent confirmation no longer falsely says all descendants will be removed; malformed descriptors fail closed.
- **DF-02:** public v2 capability and export pairing documented and covered by `tests/js/design-consumer-ready-api.test.mjs`; no private class imports or extra consumer-owned framework.
- **DF-03:** native Mail Unicode first-create Save and Reports Save have owner-reported functional field PASS from the preceding incident. Full **Save → close → Reopen** for the *exact final candidate* remains a required field gate, not a claim made by SOURCE changes.
- **DF-04:** retired `subject + project` CAS fixture reconciled to native `project + fields`; separate, labelled test-only admission seam does not enter shipped product. The storage test now exercises Unicode SQL-safe serialization and keeps revision CAS/ABA/conflict/failed readback checks. Existing standalone Unicode/receipt/Mail tests are part of the standard `tools/check` gate, and all `tests/js/*.test.mjs` are discovered automatically.
- **DF-05:** verify exact artifact, close product tests and produce a single owner-reviewable source/ZIP candidate before considering a fast-forward to `main`.

**Not included, and not a blocker for public Foundation consumers:**
- New external native Host profile admission / new package artifact trust machinery from the parallel `feature/designer-consumer-admission-v1-20261023` experiment.
- Reports editor polish, additional report elements, publication rendering redesign, or DND-03 gestures.
- A new test harness, legacy document migration, second History engine, second Save authority, WordPress.org publication or CI/CD rollout.

## Public consumer boundary

Consumer code imports only public Base `@cb-core/design-editor`, modal/toast and the intended WordPress Admin contracts. It must pin a compatible Base artifact and verify `Assets::DOMAIN_SESSION_API >= 2` as well as actually resolved ESM exports. Base owns the shared interaction framework, not the consumer's domain authorization, normalizer, revision/Head CAS, persistence and verified receipt.

**Semantic Layers deletion:** if removing a container moves child entities (Evaluator Section to Unassigned Questions), the consumer supplies `actions.remove.confirmation = {title,message,confirmLabel}` or the synchronous descriptor factory; the confirmation text must describe that action accurately. A descriptor is not permission to delete. Existing Base callback, nested dialog, keyboard controls and Undo/Redo contract remain unchanged.

**Save:** consumers `captureSave()` before their own authorized backend Save; only a verified same-document, same-revision authoritative receipt permits `rebase()` and one success feedback. False receipts, server uncertainty, failed persistence or concurrent Save must never masquerade as success. Base's Mail/Reports native Save is not automatically a consumer-domain persistence writer.

## Verification matrix

| Gate | Evidence required | Current classification |
|---|---|---|
| Exact branch and changes | `git status --short` clean, `git rev-parse HEAD`, diff versus main | GitHub SOURCE diff review; exact local evidence OPEN |
| SOURCE syntax and regressions | `./tools/check` exit 0, including all standalone CAS/Unicode/Mail and Node tests | OPEN for new HEAD |
| DEV ZIP and checksum | `./tools/build-dev`, generated SHA-256 and `.sha256` check on exact HEAD | OPEN |
| Mail | Open existing WordPress notification template, edit accented/non-ASCII text and Section, Save, close, Reopen, confirm persistent content; Undo/Redo | Earlier Save functional PASS, final-HEAD Save/Reopen OPEN |
| Reports | Edit document with ordinary and nested elements, Save, close, Reopen; confirm controls, Layers and presentation remain intact | Earlier Save functional PASS, final-HEAD Save/Reopen OPEN |
| Shared Layers | Context menu/keyboard, correct nested-delete dialog, Cancel, Confirm, Undo/Redo, no duplicate Base Toast | SOURCE behavior implemented; installed proof OPEN |
| External API | `@cb-core/design-editor` v2 plus PHP capability from **same installed Base ZIP**; consumer-specific pairing later | SOURCE contract; installed pair OPEN |
| Release/merge | Owner review and separate explicit `main` GO; any release actions separately authorized | HOLD |

## DF-04 regression reconciliation after first installed candidate check

The owner's first complete Level 1 run against `bf7d813c6dcc180a4f0fd8292037207b1c00d468` reported **292 PASS, 53 FAIL out of 345 tests** while PHP syntax, translation parity, standalone document/CAS/Unicode/Mail checks, shipped 121 JS syntax, DEV ZIP and SHA-256 check passed. The DEV ZIP checksum from that **obsolete candidate** was `69902f297ad7f004e7368d0a25033cd446fd5627cb692de377496231d826bbe9` and must not be reused.

The failing tests were grouped and reconciled by root cause rather than disabling the suite:

- Legacy domain fixtures incorrectly declared stable `schema_version: 1` without the verified installed Host Registry context. Isolated unit fixtures now remain generation zero, and a separate negative assertion verifies that stable generation one is still blocked for an untrusted standalone consumer.
- Existing Selection tests assumed only `{paths,primary}`, whereas current typed snapshots additionally expose `refs`, `primaryRef` and `contextEpoch`. The tests check node-path projections while retaining typed-identity assertions.
- **Production bug corrected:** History discarded staged `forceSelection` during commit. It now forwards semantic selection intent; SelectionState also honors an explicit forced final batch event. Insert-at-same-path must notify the Inspector/Layers even where path equality masks a new node.
- The fullscreen test isolated ESM files but omitted the public `inspector-value-control.js` dependency. The fixture now copies this module.
- The Layers DnD test expected obsolete per-row reorder buttons instead of the canonical context-menu and bounded sibling DnD interaction.
- The Exit Guard translation check froze a retired global 3990-message count while canonical source now contains 4032 messages. Localized key coverage remains tested and canonical catalog parity is enforced by the PHP translation gate.
- The older Mail tests expected pre-native validator error names and separate Mail-rich-text enqueue. Assertions now check the native domain parent/column invariants and one scoped Base Platform asset authority.

**Independent source evidence:** the new v2 public API/Layers tests previously passed 5/5 direct assertions. The four modified Mail source tests were separately evaluated against current production SOURCE, 4/4 PASS. All reconciled test files passed JavaScript syntax. These targeted checks **do not** replace a complete local Level 1 re-run on the final HEAD.

**Merge rule unchanged:** a fresh exact-HEAD `./tools/check` must exit 0. If any failure persists, inspect the exact test output and fix only a demonstrated test drift or product defect; do not mark the 53 failures ignored, mask them with blanket skips, or merge with a red gate.

## DF-04 Python check reconciliation

The subsequent owner Level 1 run reached Python and reported **165 tests, 15 failures and 1 setup error**. These results had two shared fixture causes:

- `tests/python/test_design_document_a23a_checkpoint_a.py` still attempted to read the retired `src/Mail/Designer/DocumentAdapter.php`. The checkpoint now validates current native `PlatformTransport` and `BaseDocumentRepositoryPort` first-create Save and the existing Reports read-only adapter, while retaining historical cutover authority guards. It explicitly forbids reviving direct legacy document/option writers.
- `tests/python/test_check_entrypoint.py` mounted an isolated mock of `tools/check` but omitted the three recently required standalone Designer regression filenames. This prevented 15 separate test paths from reaching their intended fail-closed assertions. The fixture now creates the three inert required files and updates the exact traced PHP invocation order.

The SOURCE comparison confirms the revised assertions match the actual current PHP files. **A fresh local Python suite and full Level 1 `tools/check` must still PASS before build/merge.** These two changes do not alter shipped product code, security gates or the release authorization boundary.

## Minimal operator check

From `~/Downloads/wp-core-blueprint`:

```bash
git fetch origin
git switch feature/designer-p0-save-toast-reconciliation-20261023
git pull --ff-only origin feature/designer-p0-save-toast-reconciliation-20261023
git status --short
git rev-parse HEAD
./tools/check
./tools/build-dev
ZIP="dist/dev/core-blueprint-1.0.0-dev-$(git rev-parse --short=12 HEAD).zip"
sha256sum "$ZIP"
(cd dist/dev && sha256sum -c "$(basename "$ZIP").sha256")
```

Install only the freshly checked DEV ZIP on the operator's WordPress sandbox. Reopen Mail and Reports after Save, verify visual/History/Toast behavior, and record the exact HEAD and checksum with the field result. If `tools/check` fails, fix the *actual* regression in scope and rerun; do not claim the batch Golden. If an installed failure arises, stop at the exact failed feature and preserve the working existing document. Avoid more broad architecture work.

## Completion / governance

Only after exact-head Level 1 and operator field acceptance can Overseer propose `SOURCE/PRODUCT CONSUMER READY` and request explicit GO for a controlled non-force `main` merge. Verify branch lease and merge-base again at that time; no silent merge, release, deployment, CI/CD or irreversible database modification.

The Evaluator modern Store branch and Automations Designer adoption branch are already SOURCE candidates, but their final cross-repository pairing starts only after an accepted Base `main` target is available per the owner's sequencing request. Their independent Save security, installed field smoke and Golden remain their domain responsibility.
