# G1d-A2.1 | Base Design Document Storage Foundation handover

**Date:** 2026-10-17
**Status:** SOURCE PATCH COMMITTED / LEVEL 1 EXECUTION PENDING / LEVEL 2 HOLD
**Authority:** Overseer R-C Architecture GO + explicit owner A2.1 Implementation GO.
**Repository:** `Core-Blueprint/wp-core-blueprint`
**Feature branch:** `feature/designer-g1d-a-mail-reports-reference-20261017`
**Accepted G1c ancestor:** `ece1fa6d3b851a798a0fb4e2da14bbbe79b219b1`
**Preimplementation R-C ADR:** `10668181bb083f92db19ce2c556610c6aa73612e`
**A2.1 implementation source after final patches (before this handover):** `58e84bd4c71bf2d0d4fd586971c8f1d64d3c8f73`.

## 1. Exact bounded implementation

- `src/Design/Document/Schema.php` declares `{$wpdb->prefix}cb_design_documents` with InnoDB, primary auto-increment ID, SHA256(type + NUL + exact key) unique full-length identity, two bounded document type/key columns, per-row unsigned BIGINT revision, schema version, JSON payload and timestamps.
- Registers through existing Base `SchemaRegistry::register_base` and existing DB reconciliation (`src/Core.php`, `plugins_loaded:4`, before `DB::maybe_upgrade` at 5). DDL is idempotent via `dbDelta`; no standalone installer, migrator, lock or new framework.
- Schema health performs physical `information_schema` checks for InnoDB, required columns and types, unsigned BIGINT revision, auto-increment, ASCII binary identity-hash collation, complete nonprefix UNIQUE identity hash and primary key. Writes fail closed on missing/unsafe schema; site prefix is resolved per call, including blog switching.
- `src/Design/Document/Repository.php` provides Base-INTERNAL operations `get/create/save/reset` (Reset is versioned overwrite/tombstone-ready, **not deletion**), strict allowed document types (`base.mail-template`, `base.maintenance-report-design`), byte-bound exact Mail IDs (including inactive providers), Reports singleton `maintenance`, bounded structural JSON validation and SHA256 identity.
- A revision is a strict decimal string with max `PHP_INT_MAX` (64-bit target), no exponent/leading zeros, and stays a **string** in the result. Actual SQL `UPDATE ... WHERE identity_hash/type/key AND revision = CAST(%s AS UNSIGNED)` increments the **same row** atomically; first insert is unique-key-gated; missing/corrupt/schema-error/conflict are distinct non-success statuses. Readback of stored revision/identity/payload required before returning `status:'saved'`; later race produces `uncertain`, not a false save.
- Ordinary schema work and repository writes are deliberately not invoked by existing Mail/Reports; their current Options storage remains authoritative **until A2.2 + A2.3 approved migration and adapter cutover**. Existing Base Settings, Reports snapshots/PDF, Designer history, public ABI v1, Template Library and other extensions are unchanged.

## 2. Checks authored; execution must be reported accurately

- `tests/public-design-document-storage-regression.php`: standalone *fake-wpdb, no real DB* scenario with matching/mismatched revision, duplicate create, multiple template rows, Reset ABA, adversarial interleaving, SQL error, postcommit readback fault, non-InnoDB/missing index fail-closed, >2^53 decimal revisions, invalid revisions and second blog isolation.
- `tools/check`: wires this standalone source regression into mandatory Base Level 1 after canonical PHP syntax checks.
- `tests/integration/DesignDocumentStorageFoundationTest.php`: DB-backed four-CASE PHPUnit suite written for a **future separately authorized isolated** WordPress test slot, including physical schema, real row CAS, independent Mail IDs, BIGINT >2^53 and malformed revision nonwrites. **NOT EXECUTED** under A2.1 source GO.
- **No source-only commands have been run against the operator's local Git worktree by the assistant**. Remote GitHub source/commit readback is not `php -l`, `bash tools/check` or a runtime PASS. Level 1 is **PENDING** until owner supplies actual results. No WordPress/MariaDB queries or existing test slots were accessed.

## 3. Operator Level 1 reproducible read-only commands

```bash
cd ~/Downloads/wp-core-blueprint
git status --short
test -z "$(git status --porcelain)" || { echo "Local changes detected: preserve them and stop"; exit 1; }
git fetch origin --prune
git switch feature/designer-g1d-a-mail-reports-reference-20261017
git pull --ff-only
git rev-parse HEAD
php -l src/Design/Document/Schema.php
php -l src/Design/Document/Repository.php
php -l tests/public-design-document-storage-regression.php
php -l tests/integration/DesignDocumentStorageFoundationTest.php
php tests/public-design-document-storage-regression.php
bash tools/check
```

If the branch does not exist locally yet: `git switch --track origin/feature/designer-g1d-a-mail-reports-reference-20261017`. Do not force-switch a dirty checkout.

**Do not run** `tools/check-integration --execute`, PHPUnit integration, `tools/build-release`, install/activate, Docker/DB schema queries, CI, merge or cleanup under this GO.

## 4. Security and outstanding review gates

- Repository is an **internal persistence primitive**, not an independently authorized user-facing endpoint. Full Mail document/HTML/subject sanitization and actor/provider/nonces remain the future consumer adapters' responsibility. No adapter is switched in A2.1.
- Source-level fake DB exercises conditional state semantics but cannot prove MariaDB concurrency, real `dbDelta` parsing, InnoDB/unique-index DDL, persistent cache, mixed hosting, Site A/B DB isolation or two separate PHP-worker interleavings.
- Future isolated Level 2 must test real MariaDB/InnoDB, charset/index structure, >2^53 SQL exactness, simultaneous update/update and insert/insert by independent workers, malformed/corrupt stored documents, timestamping and readback races, multisite switching, existing Reports snapshot sentinel and no legacy Mail overrides affected.
- 64-bit PHP is a declared baseline. Overflow fails closed at `PHP_INT_MAX`; handling DB unsigned BIGINT values above the PHP signed max is intentionally **not** supported.
- Schema verification performs information_schema reads prior to each current repository operation, intentionally fail-closed; measure query cost in the later Level 2/performance gate before activating production consumers.
- The larger G1d-A2.2 migration, G1d-A2.3 save adapters, G1d-A2.4 Reports Profile cross-store transaction/Settings-hardening and G1d-B PHP v2 capability remain blocked. Profile import cannot claim global Settings-wide CAS.

## 5. Proposed next Overseer disposition

After operator `bash tools/check` PASS and exact SHA match, mark **G1d-A2.1 SOURCE LEVEL 1 ACCEPTED ONLY**. Then seek a distinct G1d-A2.1 isolated WordPress Level 2 GO with fresh protected workspace+database, preserving the accepted G1b/G1c test environments. Do not start Mail/Reports migration on a source-only PASS.

No merges, CI, builds, releases, staging, production or database actions were performed in authoring this patch.
