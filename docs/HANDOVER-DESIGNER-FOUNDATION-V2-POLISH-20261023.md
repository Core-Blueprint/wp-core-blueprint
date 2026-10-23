# Handover — Base Designer Foundation v2 polish

**Date:** 2026-10-23  
**Owner:** WP Suite Overseer / Base Luna  
**Repository:** `Core-Blueprint/wp-core-blueprint`  
**New working branch:** `feature/designer-foundation-v2-polish-20261023`  
**Canonical merged `main` / new branch parent:** `5e9d59a2f7ffe384873a6c0650822bbb0e7eb557`  
**Accepted DEV ZIP:** `dist/dev/core-blueprint-1.0.0-dev-5e9d59a2f7ff.zip`  
**Accepted DEV ZIP SHA-256:** `838728ac3ed19bfe246784dec948b540a3d827769d4173fdfc92b80188c62e47`  
**Release state:** Foundation v2 consumer-ready development baseline; **not** a Golden public release or CI/release validation.

## What has been accepted

- Product owner gave explicit GO for `main` merge after Base Level 1 **PASS**, `./tools/build-dev` **PASS**, 981 PHP / 121 shipped JavaScript syntax checks **PASS**, six translation catalogs **PASS**, DEV ZIP/checksum **PASS**, and user-confirmed sandbox **stable**.
- `main` fast-forwarded, without force/squash/new merge commit, from `d7b251fa0fe8df3e5b53dc1e9f1b866df508905e` to `5e9d59a2f7ffe384873a6c0650822bbb0e7eb557`. Independent GitHub reread confirmed exact `main` HEAD and identical compare against accepted commit.
- The `.github/workflows/tests.yml` workflow is `workflow_dispatch` only, so the merge did not itself start the full CI matrix. **No automatic CI/CD, public release or WordPress.org publication was authorized.**
- A separate polish branch was created from the exact `main` commit. All later design/UI work belongs on that new branch, not on `main` or the previous Save-reconciliation branch.

## Stable product contract

Base supplies shared Designer chrome and generic primitives to Mail, Reports and external consumers:

- Public ESM `@cb-core/design-editor`; Domain Session v2 with immutable project snapshots, typed Selection, Command History, Undo/Redo, Save checkpoint `captureSave()` and verified `rebase()`.
- Shared Designer Shell, responsive sidebars, Navigation, Elements, Layers hierarchy, context menu, semantic Layers deletion confirmation, drag-and-drop movement, Inspector controls, fullscreen/Exit Guard, Toast.
- Base owns native Mail/Reports Host document/transactional Save stack. Client-side changes never become confirmed Saved without an authoritative server receipt. Native Mail and Reports must continue Save/Reopen successfully.
- Extension domains retain their own models, policies and verified persistence. Evaluator and Automations must consume the public Foundation rather than importing private Base classes or creating a second Designer. External native Host profile admission is a **separate future topic**, not a hidden dependency for current consumer adoption.
- The Layers `actions.remove.confirmation` descriptor supports domain-accurate warnings: removing an Evaluator Section can move Questions to Unassigned rather than deleting them. Base owns the accessible confirmation; consumers own the action.

## Evidence and known lessons

- A native Mail initial INSERT previously failed with `SAVE_STORAGE_CREATE_QUERY_FAILED` due to raw Unicode JSON and WordPress `wpdb` mixed ASCII/utf8mb4 charset preflight. `Repository::encode()` and `SaveEnvelope::storage_json()` now use SQL-safe escaped-Unicode representation without changing logical content or canonical SHA/digest identity. Do not reverse this or bypass WordPress charset guards.
- Level 1 initially had 53 JS test failures grouped around stale domain generation-one fixtures without verified Host context, typed Selection shape changes, omitted fullscreen test import, outdated Mail tests, obsolete Layers reorder expectations and a stale i18n count. A real structural selection issue in `assets/js/design/core/history.js` and `selection.js` was fixed. Later Python fixture failures due to missing tests and retired `DocumentAdapter.php` were reconciled. Full `./tools/check` ultimately **PASS** on the accepted parent.
- The learning is in Overseer AI Learning & Improvement Center, ChatGPT lesson `CB-2026-011`; for SQL refusal without mysqli errno, inspect WordPress query preflight and ASCII/Unicode storage before adding repetitive diagnostics.
- **No compatibility obligation for unpublished Base v1**; avoid introducing new legacy/fallback writers. Preserve fail-closed security, WP capability gates, original Mail notification fallback, transaction CAS, receipts, Registry integrity and browser iframe boundaries.

## Polish scope for the new chat

**Goal:** refine the already-working Designer as a product, using real sandbox issues and focused patches, while keeping the new Base `main` consumer-ready baseline stable.

Prioritize hands-on user-observable polish, one cohesive change at a time:

1. **Native Reports elements**: inspect each current element's editing experience (Elements palette → insert → select → Layers → Inspector → preview → Save → Reopen). Improve missing/awkward controls or visual/layout fidelity in small report-domain patches, without moving shared primitives into Reports.
2. **Shared Foundation consistency**: ensure the context menu, iconography, keyboard accessibility, selection, drag-and-drop, confirmation wording, Inspector, Toast and Exit Guard work the same way in Mail and Reports. Generic behavior belongs in Base primitives, not duplicated in a consumer.
3. **Mail and Reports-specific polish**: eliminate clearly evidenced presentation/interaction defects; protect native WordPress Mail tokens, rich-text sanitization, Reports required Header/Footer, supported schema, PDF/render boundaries.
4. **Stability guard per patch**: preserve canonical Save/Reopen, mixed-charset JSON handling, Undo/Redo, layout and authorized commands. Run proportional syntax/regression checks; do not develop test infrastructure before a new product need is proven.

Not in scope by default: a rewrite, a new designer architecture, additional native Host admission, Evaluator/Automations domain rewrites, new runner/CI/test matrix, public publishing or unrelated feature expansions.

## Rules of engagement

- Start the new conversation by verifying `git fetch origin`, `git switch feature/designer-foundation-v2-polish-20261023`, `git pull --ff-only`, `git status --short`, `git rev-parse HEAD` and that the polish branch descended from the accepted `main` SHA. Do not reset or switch away with dirty work; no `set -e`, no `/tmp` project paths.
- The new branch is the sole edit target. Small coherent patches; describe changes, exact commits/SHA and why they are Foundation-wide or Mail/Reports-specific.
- After each patch, report fetch/switch/pull, tests run, DEV build under `dist/dev` if relevant, checksum, sandbox acceptance status and **next step**.
- Use real WordPress sandbox behavior as the deciding product gate. Distinguish SOURCE, local PASS, installed sandbox PASS, and future Golden/public-release acceptance.
- No merge into `main`, force-push, CI Actions, release ZIP, distribution or deployment without fresh explicit product-owner GO.
- The user wants to **polish in a new chat first**. Evaluator Luna and Automations Luna have existing SOURCE migration branches; do not silently direct or merge their work. Coordinate against the accepted `main` only with explicit owner direction.

## Existing consumer starting points (informational, do not edit in polish)

- Evaluator modern Store reconciliation: `christiaanbruinsma/wp-core-blueprint-evaluator`, branch `feature/evaluator-designer-v2-store-reconciliation-20261023` (previously reviewed HEAD `39034bd689c638934f8da57cbd6546007058c645`). Its Store authority and Section→Unassigned semantics stay Evaluator-owned.
- Automations shared Designer adoption: `Core-Blueprint/wp-core-blueprint-automations`, branch `feature/automations-shared-designer-adoption-20261023` (previously reviewed HEAD `27ef8fb943639f1676f74785f1bcf44761278761`). Its workflow authorization, triggers, conditions, actions and CAS remain Automations-owned.

## Next action

In the new chat, start with a read-only review of the current native Reports element palette and Inspector capabilities **against the actual installed Base `main` baseline**. Propose a short prioritized polish sequence, ask for GO if the owner has not already specified the first polish implementation, and then implement focused changes only on `feature/designer-foundation-v2-polish-20261023`. Keep the stable Foundation contract frozen unless a reproduced cross-consumer defect requires a precise fix.
