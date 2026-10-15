# Base Template Library T3A: main reconciliation candidate

**Date:** 2026-10-15

**Target:** `Core-Blueprint/wp-core-blueprint`, `feature/base-template-library-t3a-main-reconcile-v1`.

**Authority:** Scanner-hardened `main@1cab1e41bda30334fbd54dec24b105812b5aee0c` plus Template Library `feature/base-template-library-t3-delegated-management-v1@9dc56711bc459ec20f77f536ad1820ac706a8c32`. The original T0–T2 and T3A branches are preserved.

## Scope

- Reintroduces T0–T3A Base Template Library API, scoped registry, admin browser, delegated Preview/Manage navigation, integration and JavaScript contract tests and API documentation.
- Restores the new Base page/registrations in `src/Admin/Admin.php`, `src/Admin/PageRegistry.php`, `src/Core.php`. Those three were unchanged on Scanner main relative to the original feature merge-base, so restoring the feature versions preserves the current main content at those locations.
- Maintains all six Scanner source translations on the current main and appends `languages/template-library/` locale overlay without overwriting the Scanner translation keys. Canonical expected message count **3954**, derived from common ancestor **3912**, Scanner main **+23**, Template Library **+19**. **This arithmetic has not been verified by `tools/check-translations.php` on the reconciled tree: treat this as an explicit local test gate.**
- Leaves `src/Admin/ScreenAssetRegistry.php` and all Scanner UI/runtime sources untouched. Does not merge the separate Admin List View, Mail Designer, or Evaluator P02 feature branches.
- Existing Automations adapter T3B/T5 at `d6a0855bcacee413616eb07e83bee6be1764f30f` is not modified.

## Evidence state

**GitHub source reconciliation only.** Local Base Level 1 on this **new** SHA: PENDING. Full WordPress integration Level 2 and Base `python3 tools/build-release` Level 3: PENDING. Browser/role/automations E2E: PENDING. No CI, merge, deployment, database provision or cleanup performed.

Run `bash tools/check` on the reconciled exact-head candidate in an independent operator-approved checkout/worktree. If i18n reports missing/extra keys, reconcile source to full locale coverage; do not relax checks or overstate passing validation. Once green, separately review protected WordPress Level 2 setup and cross-extension staging. No merge without separate GO.

## Branch housekeeping

Four superseded validation branches remain pending manual remote deletion because the GitHub connector does not expose ref deletion. Preserve `feature/base-integration-execution-gated-v1`, both Evaluator P02 branches, and all current active development branches. Do not infer GitHub deletion from local branch removal.
