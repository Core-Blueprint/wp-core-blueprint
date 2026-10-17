# Base Designer G1d-A2.4 | Reports Profiles concurrency and rollback source plan

**Date:** 2026-10-17  
**State:** DESIGN/HANDOVER ONLY; **A2.4 SOURCE Implementation GO HOLD**. No application changes, SQL/DB execution, Level 2, Docker, A2.3b authority activation, CI, release or merge authorised.  
**Source anchor:** `Core-Blueprint/wp-core-blueprint`, `feature/designer-g1d-a-mail-reports-reference-20261017@ec4d31fc55a8538291eb110364f2a5248d90cd19` (operator-reported A2.3a Level 1 PASS; Level 2 OPEN).  
**Authority:** existing [R-C document-store ADR](2026-10-17-designer-g1d-a-rc-document-store-adr.md), Overseer A2.3a dependency order and Profiles section/Engine contracts. No new storage architecture.

## 1. Verified existing fault boundary

- `src/Settings.php`: `get()` caches one full merged `CB_CORE_SETTINGS` document per PHP request; `set_key()`, module and feature setters feed `persist()` which uses unconditional `update_option()`. Two independent PHP workers may overwrite each other's otherwise unrelated Settings intents. A Profiles-only `ApplyLock` does not coordinate these writers.
- `src/Profiles/Sections/ReportsSection.php`: `export()` combines retention/alerts from Settings with branding/Composer from `DesignDocumentAdapter::current()`. Existing `apply()` writes all four fields back to Settings, while `preflight()` deliberately refuses active R-C authority. Existing `restore()` delegates to `ExactSection` and is not a cross-store CAS.
- `src/Profiles/Engine.php`: re-previews after `ApplyLock`, applies sections sequentially and compensates attempted sections in reverse. It is **not** one cross-section DB transaction; its current preview fingerprint contains section values but not a separate document-row revision witness.
- `src/Design/Document/Repository.php` already owns site-scoped InnoDB row CAS, decimal-string revisions and verified readback. `src/Reports/DesignDocumentAdapter.php` already validates the canonical Reports singleton. Reuse them; do not build a parallel persistence engine.
- Independent changes to the Reports document + `wp_options` row need one bounded two-row transaction; this alone does not stop a stale ordinary Settings writer **after COMMIT**. Therefore both Settings-write coordination and Reports transactional apply are P0.

## 2. Two bounded source batches (each reviewable, after separate A2.4 SOURCE GO)

### A2.4-S1: intent-safe Base Settings writes

**Allowed files:**
- `src/Settings.php`: retain existing public entrypoints, but compute each concrete mutation against an authoritative fresh Settings row/expected raw version, never replay a stale complete cached settings array as the next value. Reject conflicts rather than silently overwriting other intents; preserve audit semantics and expose a controlled request-local cache invalidation boundary for post-commit use.
- **New internal** `src/Settings/OptionCas.php`: site-scoped, single-option raw-byte CAS against `$wpdb->options` and `CB_CORE_SETTINGS`; exact/bytewise value comparison (not collation-folded string equality), prepared parameters, distinguish missing/unchanged/conflict/DB error, never `REPLACE` or unconditional write. Preserve established autoload behavior. Perform targeted WP options-cache invalidation and Settings request-cache reset after successful independent writes; do not publish a new extension API.
- **New** `tests/public-settings-cas-regression.php`, registered in `tools/check`.

**Blocking review before code:** inventory *all* first-party and direct `CB_CORE_SETTINGS` writers (including bypasses of `Settings`, WP option hooks, imported Profile sections and multisite). Do not claim universally protected writes while any affected writer can still bypass the CAS path. Respect required WordPress option hook/filter side effects: direct SQL does not automatically dispatch `update_option()` callbacks. If indispensable hooks cannot be retained safely, stop and escalate the precise affected call path rather than silently changing semantics. No unrelated Base Settings redesign.

### A2.4-S2: active Reports Profile cross-store atomic section

**Allowed files:**
- `src/Profiles/Sections/ReportsSection.php`: keep export/normalize schema unchanged. Retain the current active-authority import HOLD until all P0 tests pass; then explicitly route **only** active Reports apply/restore through the guarded transaction. Legacy/prepared behavior remains on the corrected Settings CAS path. Keep export and read-only preview available; fail closed on blocked authority, invalid documents or unsafe transactions.
- **New internal** `src/Profiles/ReportsProfileTransaction.php`: one short, owned InnoDB transaction for the Reports document row + **only** Reports retention/email-alert settings within the site-specific `wp_options` row. Lock/re-read fresh rows in a consistent order, check expected original option bytes and exact document revision, merge intended Reports fields without overwriting unrelated keys, perform conditional writes, verify *inside transaction*, then COMMIT and verify authoritative post-commit readback. SQL/commit failure => ROLLBACK + verify; uncertain state => no success, explicit operator recovery. No DDL, WP `update_option()`, arbitrary hooks, external callbacks, notifications or network work inside transaction. Refuse already-open/unknown transaction ownership. Reuse `Repository` CAS and `DesignDocumentAdapter` validation where safe; any change to either existing class requires a documented narrow contract reason before proceeding.
- `src/Profiles/Engine.php` and **new internal** `src/Profiles/VersionGuardedSectionInterface.php` **only for minimal version witness propagation**: current profile-preview fingerprint must bind Reports' canonical document revision and Settings row version/bytes as an opaque internal guard *without changing exported Profile JSON or generic section snapshot equality*. Revalidate that guard after the Profiles lock and inside the actual Reports transaction. No global transaction over arbitrary Profile sections and no second engine.
- **New** `tests/public-reports-profile-a24-regression.php`, registered in `tools/check`. Existing Profiles tests remain intact. No JS changes unless a specific operator feedback regression proves necessary.

**Restore contract:** when a later Profile section fails, rollback the previously applied Reports section through a **new CAS** against *its exact post-apply* Settings state + document revision; never use an unconditional historical snapshot write. If an independent writer advanced either store, refuse compensation of that store, emit privacy-safe `profiles.rollback_failed`, and return a visible recovery-required state. Reports section is atomic; the overall multi-section Profiles apply continues to use the existing guarded compensating model, **not falsely advertised as a single all-sections transaction**. A Profiles contract/UX change beyond this requires Overseer acceptance before active import.

## 3. Mandatory Level 1 source regressions

Fixtures must exercise executable state transitions with faked distinct workers, not only regex:
1. two workers update different Settings keys; stale full-document write cannot erase first worker; rejected conflicts report non-success;
2. same key/Reports retention simultaneous writes and ABA/raw-byte mismatch; only expected state wins; unrelated keys remain unchanged;
3. direct writer bypass/hook contract inventory gate and caches (including `alloptions`, per-option, `notoptions`, `Settings::$cached`), stable autoload, multisite identity;
4. active Reports import selected with other sections: early preflight blocks **before any mutation** until policy enabled; exports/previews remain readable;
5. exact preview/locked-apply revision guard detects identical-value revision advancement; post-preview designer Save conflicts rather than overwrites;
6. transaction success with matched Settings option + Reports row, precise readback and decimal-string revision > 2^53;
7. SQL failure after the first write, bad COMMIT/ROLLBACK, missing/non-InnoDB/invalid singleton or cache failure: no false success or fabricated defaults;
8. failure in a later Profile section triggers guarded Reports compensation; competing writer blocks rollback safely without clobbering its data;
9. rejected/failed apply does not alter immutable maintenance snapshots, Mail, recipient lists, module status or unrelated Settings keys;
10. audit/notice paths contain no Profile payload, branding contact, recipients, secrets or SQL; stale/unverified state fails closed.

**Entry point:** `./tools/check`; PHP lint, source contracts, JS/Python regressions and i18n remain mandatory. Capture exact source SHA and complete pass/fail evidence. Level 1 cannot establish genuine InnoDB transactions, separate PHP-process races, object-cache behavior or installed WordPress semantics.

## 4. Distinct, held Level 2 + field acceptance

After Handbook T2 and a **separate run-specific fresh isolated execution GO**: test MariaDB 10.11.19 / WP 7.0 (and separately 7.1 if supported) with two real independent PHP workers, intentional race both before and after COMMIT, partial SQL fault/rollback, WordPress persistent object-cache profiles, stale Profile preview, multi-section compensation, two sites on multisite and direct/out-of-band writer detection. No legacy `cb-base-test-db` or protected G1b/G1c/A2.1/A2.2 workspaces. Preserve receipts and first-failure diagnostics. Confirm active Reports profile field UX/accessibility and audit-safe operator messaging before enabling A2.3b.

**Gate to A2.3b:** (i) no first-party affected Settings writer bypasses CAS; (ii) one atomic Reports section with verified conflict-safe compensation; (iii) explicit Overseer/user acceptance of multi-section compensation semantics; (iv) isolated Level 2 + user field acceptance. If any gate fails, keep **active Reports Profile apply/import HOLD** and seek a separately accepted user-visible restriction; do **not** perform operational cutover. A2.3a Level 2 remains independently open.

## 5. Owner decision requested

**Request separate explicit GO for A2.4-S1 + A2.4-S2 SOURCE ONLY** with the file allowlist, source fixtures and no app/runtime side effects outside the repository. Escalate an observed direct Settings writer or hook incompatibility before widening scope. All database, Docker, active cutover, release, merge, CI and deployment actions remain separately held.
