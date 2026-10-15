# Mail Designer M0-M2 | Main reconciliation candidate

**Date:** 2026-10-15
**State:** Exact-blob source integration committed; **Level 1 / Level 2 / Level 3 / field Golden acceptance NOT RUN**.
**Governance:** Feature branch only. No merge, publication, CI/CD, deployment or automated mail delivery.

## Source authority

- Base destination: `feature/base-mail-designer-main-reconciliation-m0-m2-20261015`, starting from protected `main@1cab1e41bda30334fbd54dec24b105812b5aee0c`.
- Mail source: `feature/base-mail-designer-golden-m0-m2-v1@2da0149bbe73e60278c2275a4b577efd74af654f`.
- Shared ancestry: `7bb3f17ae3c6045838a414949a834ae5f9254286`.
- Exact 21 Mail-specific blobs integrated in initial reconciliation commit `65dbec884646247b4f9f1ef5946f14d6c360503c`, with no edits to any other Base runtime file.
- **Verified Git-tree precondition:** on each of the 21 transplanted paths, Base `main` matched the common ancestor (or both lacked the new file). All 21 were independently changed by the Mail candidate. Therefore there are **no path-level source conflicts**.
- This proves file-level non-overlap only, **not** functional compatibility, validation or runtime acceptance.
- Engineering DX is an independent, not-yet-Golden branch. No Engineering DX merge or source-patch cherry-pick has been performed.

## Preserved Mail capability and boundaries

- `mail.rich-text` with bounded allowlisted formatting; legacy `mail.text` preserved.
- `mail.columns` / `mail.column`, structural nesting guards, Outlook-oriented tables and desktop/mobile layout options.
- Canvas copy editing through the existing Base Designer session and history; styling and column configuration remain in the Inspector.
- Mail-only rich-text editor/toolbar, guarded save state, undo/redo, draft-switch and in-flight preview protections.
- Current public Base Designer Foundation and public `CoreBlueprint\\Core\\Mail\\ProjectRenderer` stay the consumer contracts. No second mail editor, arbitrary HTML execution or Newsletter-owned transport added.
- Existing template data remains compatible by design, but real save/reopen and rendered output regression must prove this.

## Newsletter Luna dependency, not yet accepted

Existing Newsletter branch `feature/newsletter-lists-admin-golden-b22-v1@158e228469d8d28e839b0657645cbec5d088cad4` consumes Base's public Designer module and ProjectRenderer. Its campaign allowlist (per `docs/DESIGNER-CONSUMER.md`) currently restricts campaigns to historical core Mail nodes. Adopting the new `mail.rich-text`, `mail.columns` and `mail.column` nodes is a **separate M5 consumer change** with campaign authorization, stored project compatibility, preview/save/reopen, snapshot rendering and provider-independent delivery tests.

Do not silently expand permitted campaign nodes or ship an unfinished Newsletter mail-composer integration as Golden.

## Exact-head validation to run

At a *separate clean local checkout* on this reconciliation branch:

1. `git fetch origin --prune`, `git switch feature/base-mail-designer-main-reconciliation-m0-m2-20261015`, `git pull --ff-only`, inspect `git rev-parse HEAD` and `git status --short`.
2. `./tools/check` for canonical Level 1 including PHP syntax, JS suite and Python failure-path tests.
3. Inspect `./tools/check-integration --preflight` and `--dry-run` with a new, separately approved slot; no existing DB adoption. Run `--execute` only after specific infrastructure GO.
4. Full real WordPress 7.0 and 7.1 runtime integration, PHP 8.4+, Base/Newsletter consumer.
5. On a truly proved isolated environment, canonical Python `tools/build-release`, deterministic ZIP/sidecar checksum, independent archive verification.
6. Browser and editor field: click/select, double-click/F2 inline edit, focus and keyboard, error recovery, rich-text paste/link/list, undo/redo, save/reopen, Light/Dark and mobile.
7. Server and client: legacy mail.text preservation, rich-text XSS rejection, allowed bindings, 50/50 + 60/40 + 40/60 columns, Gmail/Outlook/Apple Mail HTML, responsive stacking and no hidden tracking requests.
8. Newsletter M5 must independently confirm owner/campaign/snapshot boundary and actual persisted content before any customer-ready claim.

**Explicit approval is required** before merging either Base branch, enabling CI, installing to staging, sending email, promoting artifacts or publishing extensions.

## Handover decision

The independent M0-M2 source candidate is now *available on current Base main as a separate patch*. It is **not** merged into main or the Engineering DX/Template Library branches. The immediate next Gate is source Level 1 on this exact reconciled SHA, followed by read-only Level 2 preflight. Customer release and Newsletter activation remain blocked until evidence closes.
