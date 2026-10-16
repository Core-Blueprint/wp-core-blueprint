# ADR G1d-A / R-C | Canonical Base Design Document Store

**Date:** 2026-10-17
**Decision:** **R-C CONDITIONAL GO (preferred architecture), G1d-A2 IMPLEMENTATION HOLD.**
**Authority:** Overseer read-only R-C review request; [previous G1d-A1 CAS design](2026-10-17-designer-g1d-a1-storage-contract-design.md).
**Repository:** \`Core-Blueprint/wp-core-blueprint\`
**Review branch:** \`feature/designer-g1d-a-mail-reports-reference-20261017\`
**Immutable parent:** accepted G1c \`ece1fa6d3b851a798a0fb4e2da14bbbe79b219b1\`; previous G1d-A1 HEAD \`2777373c25d34f3592273b41a44c0ab5059f1a90\`.
**Nature of evidence:** inspected exact GitHub source; architecture proposal only. **No new executable tests, local worktree inspection, source patches, SQL, schema migration, merge, CI, ZIP or deployment.**

## Decision in brief

**Prefer R-C, one small Base-internal table/repository dedicated to Base-owned persisted Designer documents.** It eliminates the current **shared-option** lost-update domains: individual Mail templates are independent rows and Reports branding/Composer no longer reside in the general Base Settings option. Plain InnoDB row-revision CAS is simpler than serialized-option whole-value compare-and-swap, and primary DB reads avoid WordPress object-cache consistency hazards for the document data.

This is **not** a second Designer engine, history engine, Template Library or public extension storage API. Automations, Evaluator, Newsletter and other extensions retain domain-specific persistence. It changes **persistent ownership** of two Base design surfaces only and requires a one-time cutover. Retention and alerts stay in regular Base Settings; immutable report snapshots stay in their existing table.

**Conditional caveat:** Reports Profile import/export spans *two physical stores*. Existing \`Profiles\Engine\` performs section-by-section apply and compensating rollback, **not a DB transaction**. An explicit Reports-section transaction and revision-aware profile preview/apply/rollback policy are necessary. Even that transaction **cannot guarantee** other legacy \`Settings::persist()\` writers will not subsequently overwrite *unrelated settings/retention* from a stale entire Base Settings snapshot. R-C protects Designer documents from that class of writer; it **does not** silently solve generic Settings-wide concurrency. If Overseer requires absolute cross-writer atomicity for retained Settings fields, that **remains an additional Settings architecture gate**, not a proven property of R-C.

## A. Alternatives in one table

| Criterion | R-A: CAS all Base Settings | R-B: Reports + Mail option CAS | R-C: Base document table |
| --- | --- | --- | --- |
| Per-template Mail concurrency | Must still fix shared Mail option separately | Entire Mail option CAS: unrelated template edits conflict and retry | **Independent row/revision for each template** |
| Reports versus generic Settings writer | Safe only after **all** Settings writer intentions migrated | Separate Reports option, with Options API cache and raw SQL CAS proof | **Separate Reports document row**, generic Settings writers cannot replace it |
| Revision and write acknowledgement | Whole settings document + separate Mail | Serialized bytes/row version metadata + cache invalidation | **Native integer row CAS + direct DB readback** |
| Hosting/schema impact | No new table; widest application patch | No table; option migration + two bespoke adapters | **One InnoDB table**, uses existing Base schema lifecycle |
| Profiles spanning Settings + Designer | Same Settings document for Reports; other Profile sections still independent | Two options, needs cross-option transaction and cache synchronization | **Options row + design row**, needs small transactional Reports Profile boundary |
| Maintainability | Broadest regression surface | Two near-duplicate option adapters, cache race proof | **Smallest shared persistence primitive**, domain policies remain in consumers |
| Assessment | **NO-GO for G1d-A scope** | **Fallback only**, prior R-B design not implemented | **CONDITIONAL GO; preferred** |

## B. Stable site-scoped table/schema and identity

**Proposed name:** \`{$wpdb->prefix}cb_design_documents\`; \`$wpdb->prefix\` is blog-specific under multisite. Do **not** use \`$wpdb->base_prefix\`, network-wide documents or site/user IDs inferred from untrusted input.

Illustrative *review schema* for \`dbDelta()\` (actual WordPress 7.0 / MariaDB 10.11 DDL parsing must be tested before A2):

\`\`\`sql
CREATE TABLE {blog_prefix}cb_design_documents (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  identity_hash CHAR(64) NOT NULL,
  document_type VARCHAR(48) NOT NULL,
  document_key VARCHAR(191) NOT NULL,
  payload_schema SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  revision BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
  document_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY identity_hash (identity_hash)
) ENGINE=InnoDB {wpdb_get_charset_collate};
\`\`\`

- \`identity_hash = SHA256(document_type + NUL + document_key)\`; check **both** stored type and exact key after lookup. The hash is a stable physical unique index, not authentication or a document content hash. Byte-exact validation avoids collation/prefix-index ambiguity. Enforce documented max key length of 191 bytes/characters **before** migration; if any registered or inactive-provider Mail template identifier is longer, **block cutover** and review rather than truncate it. No keys containing NUL and no new unbounded input.
- Exactly two Base types initially: \`base.mail-template\` with document key = canonical registered provider-qualified template ID, and \`base.maintenance-report-design\` with fixed key \`maintenance\`. A type is server-selected, never granted by arbitrary user input. The registration/provider binding remains authoritative; inactive provider overrides must survive migration.
- Mail \`document_json\` = bounded, validated \`{subject,project,base_fingerprint,customized}\` (not delivery sender/recipient/dispatch state). Project nodes keep existing validated Mail schema and HTML allowlist. Provider-default templates **without overrides** remain a normal *registered default* (no persistent row, logical revision 0); an accepted first customization creates revision 1. Reset **upserts a canonical default-marked document with a new revision**, rather than deleting a row and reintroducing ABA. Existing \`modified_at\` is presentation metadata, **not** the revision.
- Reports \`document_json\` = canonical \`{branding,composer}\`, using \`ReportBrandingInput\` and \`MaintenanceTemplate\` normalizers. The singleton is seeded once, including existing custom branding/Composer from legacy Settings. Reports \`retention_days\`, email alerts, module status and permissions **stay in \`CB_CORE_SETTINGS\`**. The Reports table \`cb_maintenance_reports\` and its immutable snapshot records are untouched.
- Revision is a monotonically increasing positive integer **per row**; check \`PHP_INT_MAX\` and reject overflow. Never derive from timestamps, JS editor history counters, hashes or separate cache/options. \`payload_schema\` version relates solely to persisted document shape; \`SchemaRegistry\` table version has a separate Base-owned option, e.g. \`cb_core_design_docs_db_version\`.

**Lifecycle:** register as a Base-owned schema via existing \`SchemaRegistry::register_base\` before \`DB::maybe_upgrade()\`; use idempotent \`dbDelta()\` and existing reconciliation/health check. Extend *targeted* health verification for InnoDB, columns and unique index, because the registry currently proves table presence more readily than all constraints. Refuse writes if table missing, MyISAM/nontransactional, damaged index or marker disagreement. Never perform DDL inside a data transaction. On multisite, each blog's table/schema marker/migration is independent; switching blogs cannot leak repository state.

## C. Minimal internal repository and real atomic CAS

**Internal operations only:** \`get(type,key)\`, \`create(type,key,payload,expectedRevision=0)\`, \`saveIfRevision(type,key,expectedRevision,payload)\`, \`resetIfRevision(type,key,expectedRevision,canonicalDefault)\`. Do not expose this as a generic public extension API. An outright delete is **not required** for Mail/Reports Reset; keep tombstone/default state with bumped revision to close ABA.

1. Authorize request at consumer boundary: Mail active provider/template registration, \`manage_options\` and matching nonce; Reports \`cb_manage_branding\` and \`cb_core_admin\` nonce. Verify document identity from server, schema/size, actor, field normalization **before SQL**.
2. For an existing row execute one prepared SQL statement (illustrative):
   \`\`\`sql
   UPDATE {blog_prefix}cb_design_documents
   SET document_json = ?, payload_schema = ?, revision = revision + 1, updated_at = ?
   WHERE identity_hash = ? AND document_type = ? AND document_key = ?
     AND revision = ? AND revision < {safe_max_php_int};
   \`\`\`
   Under verified InnoDB, the single-row \`UPDATE\` commits one winner. **Exactly one affected row** indicates the revision precondition matched; zero means 409 \`SAVE_CONFLICT\` or absent target, and \`false\`/database error means non-success. Canonically identical Save may intentionally increment revision to reject replay of an old expected token.
3. For a missing Mail/default row, \`INSERT\` with unique \`identity_hash\`, \`revision=1\` **only for expectedRevision=0**. Duplicate-key conflict is 409, never \`REPLACE INTO\`, blind \`INSERT ... ON DUPLICATE KEY UPDATE\` or a silent overwrite. Reports singleton missing after declared cutover is **not** a default success; fail closed for recovery.
4. Verify result with a fresh DB \`SELECT\` (no WP options/object cache) and exact stored revision + canonical data/identity. After commit, a faster later writer may already have incremented the row: respond with a **safe reconciliation-required** status (not false Saved, no compensating overwrite). The caller may request current version and reconcile. Never put document bodies, submitted save tokens, PII or SQL in logs.
5. Normal response: authenticated \`{document_type,document_key,revision,accepted:{...},code:'SAVED'}\`. Invalid/missing revision, unauthorized row, corrupt document, stale/write error => safe \`409/422/403/500/503\` respectively, with stable codes, no baseline advancement. No raw third-party template content in conflict messages.

**Transactions:** ordinary one-row Save/Reset needs **no multi-statement transaction**. A transaction is required only for controlled cutover and **Reports Profile cross-store apply**, after InnoDB verification. WordPress has no automatic nested-transaction/Savepoint contract for plugin callers; ensure no foreign transaction is silently committed/rolled back. Do not run \`dbDelta()\`, WordPress \`update_option()\`, email sending, hooks with network side effects or external callbacks inside the transaction.

**Caches:** Designer row reads are direct authoritative \`$wpdb\` SQL, not \`get_option()\` and not a general WP object cache. Hence no \`alloptions/notoptions\` race for **document data**. Schema-version/cutover markers are options and must have exact DB verification and targeted cache invalidation. Any scoped SQL update to Base Settings during Profile apply needs **post-COMMIT** WP object-cache invalidation **and** invalidation of \`Settings::$cached\`; WordPress option-cache race tests remain mandatory. This does not imply other Settings writers suddenly gain CAS.

## D. Complete Mail/Reports call-path cutover (single authority only)

| Existing source | Required owned G1d-A2 change |
| --- | --- |
| \`src/Mail/Designer/TemplateRepository.php\` | Replace option-backed \`get/save/reset/overrides\` storage with per-ID repository reads/CAS and provider defaults; preserve inactive-provider overrides and subject/project validation. The old option becomes read-only migration evidence, **not** a fallback read/write authority. |
| \`src/Mail/Admin/TemplateActions.php\` | Both AJAX Save and admin-post Save use the *same* server expected-revision + provider/actor guard and authoritative result; admin-post missing/stale revision fails closed with conflict notice. Reset likewise CAS-protected; no alternate unguarded route. |
| \`src/Mail/Admin/DesignerAjax.php\`, \`src/Mail/Admin/Page.php\`, \`templates/mail-designer.php\` | Context/open loads return current document revision and accepted canonical subject/project, including defaults; hidden admin-post expected revision and page context; preview stays read-only. |
| \`assets/js/features/mail-designer-save.js\`, \`mail-designer.js\` | Capture Base Save checkpoint and subject snapshot, send **submittedProject** and durable expected revision; validate response then \`session.rebase()\`, preserve Undo/Redo, in-flight changes and subject dirty. Abort is *not* proof server did not write. Safe context switch and conflict/reload guidance. |
| \`src/Ajax/Handlers/Branding.php\` | Save and Reset use Reports singleton CAS, validate write/readback before success; preview remains unsaved typed render and authorization bounded. Do not touch snapshot rows. |
| \`src/Reports/Composer/MaintenanceTemplate.php\`, \`ReportBranding.php\`, \`MaintenanceFlowBranding.php\`, \`DesignerPreview.php\`, Reports settings/page bootstrap | All persisted Designer reader paths use the new singleton; preserve render-time branding and typed Flow/PDF behavior. Retention/alerts remain in Settings. No dual authority. |
| \`assets/js/features/reports-preferences.js\` | \`captureSave\` and server revision on Save; \`rebase\` on verified Save **not** \`applyComposer()->session.replace()\`. Track branding separately; distinguish confirmed Reset (explicit domain reset/history policy) from ordinary Save. Preserve preview/selection/dirty and in-flight edits. |
| \`src/Profiles/Sections/ReportsSection.php\`, Profiles Engine integration | Profiles still export/import retention, alerts and normalized Designer state; see **Section E**. Do not silently split the existing profile contract or bypass revision/restore guards. |
| \`src/Reports/Storage.php\`, \`MaintenancePdf.php\` | **No data writes** by this migration. Regression proves the same immutable report snapshot and dynamic branding behavior. |

Inventory at implementation start must enumerate *all* \`TemplateRepository\`, \`MaintenanceTemplate::current\`, \`ReportBranding\`, \`Settings['reports']\` callers, send-time Mail rendering and other Profiles consumers; the above is the **reviewed primary path**, not a claim that a final exhaustive search/test already ran.

## E. One-time migration, Reports Profiles and rollback

### Cutover (one site/blog at a time; no legacy runtime dual-read)

1. Register/verify the table **without touching legacy content**. On an authorized maintenance/cutover operation, fence outstanding old-code Save endpoints and verify schema/InnoDB, active site prefix, template identity bounds, current option shapes, Mail validator and Reports normalizers. If any registered/inactive override cannot be represented losslessly, abort. No partial default recreation.
2. Capture a privacy-protected **recovery snapshot** of raw legacy Mail overrides plus relevant Reports configuration and schema markers, with explicit operator-access/retention policy. Do not log or expose bodies; no customer-data exports without separate operator authorization.
3. With a single short InnoDB transaction on the same WP DB connection, lock both legacy option rows consistently (\`SELECT ... FOR UPDATE\`; define order, include missing-row handling) and verify current state still matches migration source. Insert **all stored custom Mail overrides including inactive-provider entries** as independent rows; seed exactly one Reports singleton from current canonical branding/Composer. Validate readback within the transaction.
4. Write a site-scoped **durable cutover marker** (e.g. \`cb_core_design_docs_cutover\`) with the accepted document schema version and source fingerprint, in the same SQL transaction. This marker is distinct from the SchemaRegistry table-version marker. COMMIT only after all rows and marker are consistent. No WordPress Options API writes/cache events within transaction; reconcile marker cache after commit. On any error ROLLBACK; on partial/uncertain state **fail closed** and require recovery.
5. From the cutover marker onward all authorized Mail/Reports Designer readers/writers use table rows, **not** the legacy option/subtree. Existing legacy values are inert recovery data, never parallel authority. The code must not silently start old-code writes while a rollout is in progress. Target WP 7.0 / MariaDB/InnoDB on ordinary PHP hosting; no Redis, worker, cron, custom isolation service or global lock required.
6. **Rollback is asymmetric:** before the first post-cutover edit, the original backups can support an explicit inspected rollback. After new revisions exist, simply reverting PHP code would lose them; require a deliberate **reverse export/reconcile** of current table documents into legacy shape with operator review and safety snapshot. No automatic downgrade, data deletion or indefinite legacy fallback. No cleanup of backups/tables without separate GO.

**Cutover caveat:** PHP requests already running old application code might write legacy options after transaction commit. A safe rollout requires an operator-controlled maintenance/drain window (or a separately reviewed cross-version write fence). The cutover marker alone does not stop code already executing a legacy write. Do not claim online zero-downtime migration without a proven fence.

### Reports Profile dual-store boundary (the critical conditional gate)

Existing \`Profiles\Engine::apply()\` re-previews under an exclusive **Profiles-only** lease and compensates per section; it **does not make a multi-row transaction**, and the lease does not include normal Designer or generic Settings writers. Its \`ReportsSection::apply()\` currently calls \`Settings::set_key('reports',...)\`; \`ExactSection::restore()\` uses semantic state between before/after rather than a DB revision token.

**Required focused change (not a global Profiles/Settings framework rewrite):**
- Retain the existing Reports Profile external JSON schema and data fields. Its *private* preview/snapshot must include the current Reports document **revision**, so preview → apply cannot accept an ABA same-data change; expose only a private expected revision to its checked apply path, not a public secret.
- On apply, build one **bounded** Reports-section operation. Use a short transaction, lock the document row and **fresh raw Settings option row**, check expected doc revision and persisted relevant Settings fields, prepare both normalized values, update Reports doc by its CAS revision and only the intended retention/alerts paths within a fresh complete Settings row, then COMMIT or ROLLBACK. Never use \`Settings::get()\` cached snapshot or \`update_option()\` inside the transaction; preserve all other Base settings keys. Verify accepted states after commit and invalidate **both** WordPress options caches and \`Settings::$cached\`.
- Because the existing SectionInterface \`apply(incoming,actor)\` does **not accept** an expected revision, a narrowly scoped mechanism for transferring the guarded preflight revision to ReportsSection is needed; do **not** use a stale mutable global or assume \`ApplyLock\` serializes Designer requests. If this requires a change to the general Profiles Engine API, **stop and seek a separate scope review** before editing it.
- If another Profile section fails later, any Reports rollback must be **revision-checked and compensating**, not an unconditional restore that overwrites a third-party accepted Designer edit. Failed compensation leaves a visible operator error/recovery record; do not misreport complete atomic rollback of an entire multi-section Profile. Include tests of failure after ReportsSection apply and concurrent Designer edit before compensation.
- **Residual generic Settings risk:** an unrelated stale \`Settings::persist()\` writer may write its previously fetched full option *after* this transaction commits and revert retention/alerts (while it cannot revert the isolated Designer doc). The existing global Settings concurrent-write limitation is **not solved** by R-C. To guarantee retention/alert preservation against *every* concurrent Settings writer, Overseer must separately authorize generic settings intent-CAS or a policy restricting concurrent Settings mutations during Profile apply. This is a binding acceptance limitation, **not** a hidden R-C pass.

## F. Ordered implementation slices and exact regression plan

**Proposed files (smallest owned set, adjust only with scoped review):**

1. **A2.1 Schema + internal repository:** add \`src/Design/Document/Repository.php\` and \`src/Design/Document/Schema.php\`; register via existing Base boot/SchemaRegistry. Unit tests for site-prefixed identity/hash, InnoDB/unique index, revision CAS, missing-row insert, reset ABA, high revisions, failure/readback and multisite \`switch_to_blog\`. No public API export.
2. **A2.2 Controlled cutover:** add \`src/Design/Document/Migration.php\` (or one small focused helper), safe schema-gated one-time migration and pre/post readback. No customer database mutation without independent Level 2/cutover GO. Tests: active/inactive provider overrides, oversized key reject, Mail subject/project allowlist, Reports previous branding/Composer exact roundtrip, interrupted migration and backup/rollback.
3. **A2.3 Consumer persistence:** adapt \`TemplateRepository\`, Mail AJAX/admin-post/context/page/HTML, \`Branding\`, relevant Reports read paths, and their JS Save/Reset adapters; add only missing behavioral tests, preserving G1c engine/history tests. Tests: same template two tabs stale 409, two different templates update concurrently **both succeed**, Reports stale 409, Save/Undo/Redo/in-flight edits, missing/malformed ack, unknown/forbidden identity, HTML/subject, current preview/PDF/snapshot unchanged, no send.
4. **A2.4 Profiles integration:** confined \`ReportsSection\` transactional cross-store apply/restore + necessary *reviewed* cache-refresh boundary. Test Profile preview ABA, mid-apply DB failure => neither store changes, normal apply with unrelated Settings keys preserved, multi-section later failure+targeted compensating rollback, concurrent Designer save during rollback, concurrent unrelated \`Settings::set_key\` demonstrating the **residual** Settings risk instead of concealing it.
5. **A2.5 Validation and gates:** after distinct owner source GO, execute focused PHP/JS and \`bash tools/check\` on **new exact HEAD**, report actual test count and diff; then obtain independent GO for a **new isolated WordPress Level 2** slot. Package build, public PHP v2 capability and G1d-B remain separate GO.

**Minimum deterministic two-process fault matrix:** CAS A/B same doc, CAS A/B different Mail docs, Redis/object cache present/absent (only Settings marker and Profile branches cache-aware), successful-write/failed-readback, partial transaction injection, CREATE versus CREATE, Reset versus Save, read-only profile export, rollback concurrent edit, Site A/B multisite isolation, retained immutable report sentinel/PDF unchanged, old-code migration race blocked, lost-option-write test exposed as residual when generic Settings still uses \`persist()\`. Tests need real MariaDB/InnoDB and separate PHP requests/workers; same-process sequential mocks do not establish concurrency safety.

## G. GO / NO-GO and explicit Overseer approvals

**R-C architecture: CONDITIONAL GO**, recommended over R-A and R-B. Its per-document durable version, single-row CAS, lack of WP option-cache dependence for Designer documents and existing SchemaRegistry make it sufficiently small and testable on the intended hosting. **Do not call it proven** before tested DDL, migrations, profile atomicity and rollback.

**No GO** for G1d-A2 source patches under this read-only ADR. Overseer must explicitly:
1. approve the **new Base-owned \`cb_design_documents\` table**, two allowed types, site-scoped identity and internal-only repository;
2. approve controlled one-time Mail + Reports migration, schema-marker, backup and post-edit rollback limitations;
3. accept the **Reports Profile dual-store transactional boundary** and explicitly acknowledge the remaining generic Settings concurrency risk **or** authorize a distinct broader fix for it; resolve private expected-revision handoff without an unreviewed general Profiles Engine rewrite;
4. approve protected data/schema/DB tests and source implementation as **separate owner GOs**.

**Fail-close triggers:** non-InnoDB or unavailable schema, migration/cutover ambiguity, unsigned/identity-mismatched server revision, broken Profile atomicity/rollback, unsupported template key/schema, inability to stop old-code writes during cutover. No downgrade to legacy option write paths and no false \`saved\`.

**Final disposition:** **R-C SELECTABLE AS PREFERRED DESIGN; A2 ON HOLD; G1d-B ON HOLD.** Existing G1b/G1c commits and accepted tests remain immutable.
