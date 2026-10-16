# Base Designer G1d-A1 | Mail + Reports storage contract design

**Date:** 2026-10-17  
**Author:** Base Luna, read-only architecture investigation for Overseer  
**Base repository:** \`Core-Blueprint/wp-core-blueprint\`  
**Branch:** \`feature/designer-g1d-a-mail-reports-reference-20261017\`  
**Starting authority:** G1d-A escalation \`ba8fc43366c376e4b34a9ccbc37dedb7b5a0199f\` based on accepted G1c \`ece1fa6d3b851a798a0fb4e2da14bbbe79b219b1\`  
**Contract:** G1a v2.1 accepted unchanged at documentation SHA \`ae1fe24157a79e03efea8a08cf8560f62adec316\`  
**Decision status:** **DESIGN COMPLETE; REPORTS SHARED-SETTINGS CAS BLOCKER -> OVERSEER DECISION REQUIRED. G1d-A2 PATCH HOLD.**

Read-only source and WordPress API documentation analysis. **No application/source behavior changes, database activity, WordPress test execution, CI, branch merge, package build or deployment**. Creating this Markdown document is the only G1d-A1 Git change. Local operator worktree status cannot be established through remote GitHub access.

## 1. Direct conclusion and scope boundary

**The current Reports persistence boundary cannot provide full cross-writer stale-write protection solely by adding CAS in \`Ajax/Handlers/Branding.php\`.** Reports branding and Composer live inside the autoloaded *shared* \`CB_CORE_SETTINGS\` option. Its other writers still use \`Settings::persist()\`, which writes a *previously constructed entire settings array* via unconditional \`update_option()\`. They can overwrite a successfully CAS-written Reports subtree or unrelated settings later. \`ReportsSection::apply()\` is a second Reports writer; \`SettingsMigrator::maybe_migrate()\` is another full-option writer. A Redis lock, request mutex, client sequence check, WP filter or naive read/compare/\`update_option\` confined to the Reports handler is **not a sufficient fix**.

**Mail is more isolated:** it owns \`cb_core_mail_template_overrides\`, but all template IDs share one option. A narrow atomic per-row CAS adapter can protect this option **if every first-party Mail save/reset path is routed through it**, the revised option metadata is approved, cache behavior is verified, and no alternate unguarded writer remains. This is a **proposed design**, not verified runtime safety.

**Required Overseer choice before A2:** either authorize coordinated atomicity for *every writer* of \`CB_CORE_SETTINGS\` (broader Base change), or authorize moving Reports Designer branding/Composer to its own revisioned option with reviewed migration, reader/profile routing and rollback (smaller runtime ownership but a storage-model change). No option has yet been approved.

## 2. Inspected persistence boundaries and related reads

| Domain | Exact current write path | Persistence unit/readers | Concurrent-write concern |
| --- | --- | --- | --- |
| Mail AJAX Save | \`Mail/Admin/TemplateActions.php::save_ajax() -> persist() -> TemplateRepository::save()\` | Option \`cb_core_mail_template_overrides\`, PHP array of **all** template overrides; \`TemplateRepository::get()/overrides()\` and Mail Designer context read it. | Each save updates whole option. Two templates edited concurrently overwrite each other. Success body is currently message-only. |
| Mail admin-post Save | \`TemplateActions::save() -> persist() -> TemplateRepository::save()\` | Same option; existing nonce/capability and fallback redirect. | Alternate unguarded write until same expected revision requirement is enforced. |
| Mail admin-post Reset | \`TemplateActions::reset() -> TemplateRepository::reset()\` | Same option; removes only one template override. | Can race with Save or another template. Must be revision-checked, preserve other keys, and report conflict. |
| Reports AJAX Save | \`Ajax/Handlers/Branding.php::save() -> Settings::get() -> Settings::set_key('reports', reports) -> Settings::persist()\` | \`CB_CORE_SETTINGS\`, single serialized **entire Base settings** option; reports subtree \`branding\` and \`composer.maintenance\`. | No server CAS; return value from \`Settings::set_key()\` is ignored; unconditional success even if write failed. |
| Reports AJAX Reset | \`Branding::reset()\` follows same shared option route with defaults. | Same shared option. | Same lost update/failure success issue; Reset is distinct destructive authoring action, not a history checkpoint. |
| Reports Profiles | \`Profiles/Sections/ReportsSection.php::apply() -> Settings::set_key('reports', ...)\` | Same shared option; \`export()\` uses \`Settings::get()\` and \`MaintenanceTemplate::current()\`. | Profile import/export can overwrite Designer state, or reflect an obsolete copy if a new option becomes authoritative. |
| Generic Base Settings | \`Settings::set_key/set_module_enabled/set_all_modules_enabled/set_feature_enabled/apply_recommended_defaults -> private persist()\`; \`SettingsMigrator::maybe_migrate()\` calls \`update_option(CB_CORE_SETTINGS,...,true)\`. | **Same** entire Base settings option; \`Settings::get()\` has a process-local static merged cache. | A stale generic writer bypasses a Reports-only CAS and destroys its ordering guarantee. |
| Reports output readers | \`MaintenanceTemplate::current()\`, \`ReportBranding::current()/for_pdf()\`, \`MaintenanceFlowBranding::resolve()\`, \`ReportsSection::export()\`, settings/preview bootstrap. | Read \`Settings::get()['reports']\`. \`DesignerPreview\` can render unsaved submitted state; \`MaintenancePdf\` renders stored report snapshot with **current** branding. | Readers must agree on one authoritative source after any migration; avoid changing render-time branding or snapshot data. |
| Immutable report snapshots | \`Reports/Storage.php\` table and \`MaintenancePdf.php\`. | Separate immutable report-data rows; PDF generated on demand, branding resolved separately. | Must **never** mutate existing report snapshot/PDF data as part of editor Save/Reset/Undo. |

**Physical row identity:** blog-scoped \`$wpdb->options\` with unique \`option_name\`. For multisite, site option versus network option distinctions must remain unchanged. Avoid guessing a hardcoded \`wp_options\` prefix. Option *autoload* status (including legacy values) must be inspected before a SQL CAS; preserve existing policy except under separately approved migration. The Mail registry/provider identity comes from \`TemplateRegistry\`, not a guessed user-supplied ID.

**WP Options API behavior:** \`get_option()\` uses \`alloptions\`, \`notoptions\` and per-option cache; \`update_option()\` writes through ordinary \`$wpdb->update()\` keyed by name and manages those caches but **does not condition its UPDATE on a previously accepted document revision**. References: https://developer.wordpress.org/reference/functions/get_option/ and https://developer.wordpress.org/reference/functions/update_option/ .

## 3. Narrow atomic CAS proposal for Mail (subject to approval)

### 3.1 Version location and current-shape compatibility

- Store a **strictly increasing integer revision in the same serialized option row**, not an independent revision option, WordPress transient, lock, client fingerprint, timestamp or Base local history revision. An independent option creates a two-row commit problem; SHA-256 of value alone permits an A→B→A version/ABA replay.
- Candidate additive private metadata: reserved invalid-template-ID \`__cb_storage\` containing \`schema:1, revision:<positive integer>\`; all existing registered \`templateId => {subject,project,base_fingerprint,modified_at}\` entries remain **unchanged**. \`TemplateRepository::overrides()\` must exclude the private metadata from the public template map; unknown/inactive-provider template entries must survive. A revised option envelope would be an alternative, but it is a larger migration and is **not** proposed here.
- Legacy no-metadata option or absent option has logical revision \`0\`. First successful CAS write upgrades only the option metadata *in the same atomic write*. Strict parsing: no forged duplicate reserved keys, non-array data, unexpected serialized classes, negative/overflow revision or invalid metadata. Fail closed with safe recovery; never reset/overwrite a corrupt existing row.
- This is a **small persisted shape extension**, still subject to Overseer schema/migration/rollback approval. A backup/readback fixture of the exact prior option is mandatory. Do not claim zero schema impact.

### 3.2 Single-row compare-and-write sequence

All operations use the **same authorized repository method** \`save(templateId,...,expectedRevision)\` / \`reset(templateId,expectedRevision)\` from **both AJAX and admin-post**. The server:

1. Verifies capability, nonce, Designer enabled, canonical provider/template ID; validates bounded Mail DesignProject and sanitized nonempty subject using existing validator/allowlist. Read the **raw option row from primary DB** (\`SELECT option_value, autoload ... WHERE option_name = %s\`), bypassing \`get_option()\` and all runtime/persistent caches for the concurrency decision. Never expose raw serialized values to the browser or logs.
2. Parses *only expected array data*, derives authoritative row revision \`r\`, checks client \`expected_revision === r\`. Reconstructs the entire new map from that **fresh database row**, modifies one registered template ID (or removes just that override for Reset), preserves every other key/unknown provider entry byte-for-byte at value level, and sets \`revision = r+1\`. Provider-default fingerprint must also match the chosen active provider identity; provider changes require safe refresh rather than hidden overwrite.
3. Executes **one conditional SQL statement** against the *same physical row*, with \`WHERE option_name = %s AND BINARY option_value = BINARY %s\` (or equivalent tested byte-exact comparison) and \`SET option_value = <maybe_serialize(candidate)>\`. Parameterize all values through \`$wpdb->prepare()\`; permit **exactly 1 affected row**. Byte-exact WHERE avoids case/accent-insensitive DB collation equating distinct serialized data. The row's unique key and InnoDB row update make same-row concurrent CAS contenders serialize. Ordinary \`$wpdb->update()\` with only \`option_name\` in WHERE is **not** CAS.
4. An absent option is created through an atomic **INSERT under the option_name unique index**, with approved nonautoload policy (subject to actual WP 7.0 schema values); duplicate-key race means conflict/re-read, never an unconditional REPLACE. No SQL DDL, new table or site-wide lock. \`0\` affected rows means stale/conflict, *not* successful no-op (revision increments also on accepted identical Save; repeated old token fails). \`false\`/DB error means failure. Revision overflow must fail closed.
5. After a successful commit: perform cache reconciliation and a fresh **DB** readback that proves the assigned row revision, target entry, sanitized subject and full accepted project match the committed candidate. If the readback shows a *later* legitimate writer's revision, return safe reconciliation/conflict rather than falsely claiming the current browser draft saved or rolling back someone else's write. No content in telemetry.
6. Other-template race may deliberately yield a benign **409** even though the current template's content did not change. That is acceptable for correctness in first version; re-fetch current revision and let the user explicitly retry. Do **not** silently auto-merge same-template edits. Document and test this conservative UX.
7. Save and Reset share version semantics and cache path. Admin-post must require an explicit hidden expected revision, fail closed on missing/stale version, preserve the existing redirect and show a clear conflict/reload notice; do not silently grant the fallback a bypass.

**Suggested outcome:** \`{outcome:'saved', template_id, provider, revision, accepted:{subject,project,customized,base_fingerprint}}\` only after verified readback; HTTP \`409\` with \`code:'SAVE_CONFLICT'\` and safe current revision/refresh guidance, or controlled failure \`500/503\`, no false \`saved\`.

### 3.3 Cache is a separate proof gate

- Raw SQL bypasses WordPress \`update_option()\` cache maintenance and hooks. After any successful write, check the real option's autoload class and invalidate \`wp_cache_delete(optionName,'options')\` and the relevant \`alloptions\`/\`notoptions\` entries. **A generic cache delete alone is not a proven concurrency protocol:** a second PHP request can repopulate a stale \`alloptions\` or per-option value after invalidation.
- Mail authoring/receipt/critical context endpoints must read the authoritative DB row (or a version-verified cache); a stale \`get_option()\` result must never participate in CAS. Verify behavior with *real* persistent object cache and two independent PHP workers, including delayed cache repopulation.
- Avoid globally flushing caches or calling a global lock. Preserve WordPress hooks that existing Mail integrations rely on or record exact compensating events and validate with source/field fixtures. Verify no sensitivity leakage in events.
- If persistent cache coherence or update-option hook parity cannot be established safely with this narrow adapter, **stop G1d-A2 and escalate**, rather than claiming runtime-compatible atomicity.

## 4. Reports architecture: two honest choices, neither pre-authorized

### Option R-A: coordinated CAS for every Base Settings writer

Retain \`CB_CORE_SETTINGS\` as the authoritative shared option and deploy **all write paths** behind one CAS-aware Settings persistence boundary. Starting from fresh *raw row*, merge only the intended scoped mutation, compare expected Reports revision for the Designer, and conditionally update the serialized full document with an incremented same-row monotonic revision. For unrelated Base key writers, on row conflict re-read, reapply their *specific intent* (e.g., one key/feature/module) and retry boundedly; never replay a stale entire-array snapshot. \`ReportsSection::apply()\` and \`SettingsMigrator::maybe_migrate()\` require deliberate corresponding treatment, including same-document/other-key conflict semantics, audits, and cache updates. Existing \`Settings::get()\` static cache requires an explicit invalidation/refresh contract after CAS, not just \`wp_cache_delete()\`.

**Benefit:** existing Reports readers and exports can remain on a single shared option. **Disadvantage/blocker:** this necessarily changes the **general \`Settings::persist()\` architecture, all settings writers and potentially migration semantics**, outside current A2 authorization. Existing writers that build a full document cannot be automatically retried safely without representing their intent. No partial adoption is safe.

### Option R-B: dedicated revisioned Reports Designer option + reviewed cutover

Create a dedicated **blog-scoped, nonautoloaded, revisioned option** (illustrative name \`cb_core_reports_designer_state\`) containing only canonical Reports **branding + maintenance Composer**, not report snapshots, retention policy or other Base settings. Use the same exact-row CAS and monotonically increasing revision as Mail, with bounded normalization and raw DB readback. Route **every authoritative Reports Designer reader/writer** to this option, including \`Branding::save/reset/preview\`, \`ReportBranding::current/for_pdf\`, \`MaintenanceFlowBranding\`, \`MaintenanceTemplate::current\`, Reports UI bootstrap, and \`ReportsSection::export/apply\`. Profiles need a defined write policy across designer + other Reports policy fields; existing partial profile imports must never silently lose or split changes. Keep ordinary \`Settings::persist()\` for non-Designer keys.

**Benefit:** can avoid redesigning generic \`Settings::persist()\` as long as all sanctioned Designer writers/readers use the new authority and old \`CB_CORE_SETTINGS['reports']['branding'|'composer']\` values are no longer treated as mutable authority after cutover. **Disadvantage/blocker:** this changes persisted storage ownership and requires a **reviewed data migration and rollback strategy**:
- first-entry migration must compare current legacy Reports source, cannot silently install defaults or ignore existing custom branding, cannot race live legacy writers; perform a controlled, explicitly authorized cutover/freeze with backup and verified readback;
- legacy values remain backed up but cannot be independently edited after cutover; avoid dual writes and indefinitely maintained compatibility shims;
- all readers including Reports Profile import/export, preview and PDF must be redirected together; old Base code/downgrade behavior requires an explicit rollback protocol, not implicit silent dual-source behavior;
- do not split/report snapshot storage or mutate historical rows. Verify what remains dynamic branding at PDF-render time, preserving existing contract;
- even with Designer state isolated, **other Base Settings keys remain subject to their existing generic concurrent-update weakness**. G1d-A would guarantee Designer isolation from those writes, not claim to fix arbitrary Settings concurrency.

**Recommendation to Overseer:** if the scope must avoid a global Settings rewrite, **review R-B first** as the narrower *runtime write-ownership* solution, but only with approval for storage cutover, migration, Profile integration and rollback. If unchanged storage location is mandatory, R-A requires a separate broader Base Settings architecture GO. **Until one is accepted, Reports G1d-A2 cannot deliver the required atomic stale-write guarantee.** Neither option is implemented.

### Why superficially smaller alternatives fail

- Only add \`version\` in a second option: **two-row nontransactional commit** can tear under request races.
- Put revision in a transient/object cache: no transactional relation to durable option.
- Compare \`get_option()\` then \`update_option()\`: two independent operations and stale caches.
- Perform a Reports-only exact-row CAS on \`CB_CORE_SETTINGS\`: generic \`Settings::persist()\` can overwrite later using a stale full snapshot.
- Use a short PHP process lock / advisory lock only for Reports endpoint: Settings writes are not participants.
- Trust frontend aborts or Base \`captureSave()\` to serialize HTTP writes: the browser cannot stop a server commit already in flight.
- Silently overwrite Reports during profile import/export or Reset: this adds a second unguarded write entry point.

## 5. Client Save, Reset, authoritative acknowledgement, dirty and recovery

**Both reference consumers:** capture \`const checkpoint = session.captureSave()\` **before** request construction; use the detached \`checkpoint.submittedProject\` for serialization. Alongside include expected **server** revision, stable authorized target identity and separate consumer metadata, never transmit the checkpoint token. Server validates existing nonce/capability and returns canonical accepted projection and monotonic revision from durable readback.

- **Ordinary successful Save:** verify exact active document identity, expected request generation, response shape and authorized accepted version; call \`session.rebase({saveToken:checkpoint.token,baselineProject:acceptedProject})\` against Base's *live* latest draft. Keep semantic Undo/Redo before and during Save. Never replace the project or clear history as Save. The client only updates consumer-owned persisted revision and accepted subject/branding after successful matching Base checkpoint acknowledgement; a server write may already be committed when a local replay conflict occurs, so display recovery/readback instead of false Saved.
- **Mail separate subject:** capture subject with checkpoint; maintain accepted subject independently and include it in dirty equality. Provider normalization/sanitization can change the accepted subject; do not erase later subject edits typed during the request. HTML/project preview validation and subject roundtrip must remain intact. Save acceptance must not grant mail dispatch permissions.
- **Reports separate branding:** capture all branding inputs with the Composer checkpoint; dirty includes project/Composer projection plus persisted branding. Preserve subsequent branding edits when applying server accepted state. Undo/Redo affects Composer commands, not already emitted PDF/report snapshots. Render unsaved preview through existing typed pipeline.
- **Reset:** explicit destructive authoring action. Include expected server revision and confirm dialog, reject stale requests; define policy on pending edits and failed/conflicting reset. On success, Reset may perform **an explicit documented context-authoring replacement/history reset** (not masquerade as an ordinary Save), but only after authenticated readback, with affected user's acknowledgment and no silent loss of in-flight edits. If policy requires Reset to be undoable, implement as a domain command with separate checkpoint after review. Never auto-reset a report snapshot.
- **Failure, HTTP 409, wrong identity, stale acknowledgement, malformed result, aborted request, timeout, normalized replay conflict:** preserve local draft and history, show accessible non-destructive recovery to re-fetch latest authoritative revision and explicitly retry/resolve. Do not call \`rebase\` on failed/missing ack or show generic \`saved\`.
- **Admin-post Mail fallback:** same version/actor policy and persistence adapter as AJAX, hidden expected revision in form, differentiated conflict/error notice after redirect, no accidental success on absent version. Mail Reset similarly version-checked.
- **Source compatibility:** Base History engine and PHP \`DOMAIN_SESSION_API = 1\` remain unchanged; no Template Library, Newsletter, Automations/Evaluator, or private-export cleanup.

## 6. Concrete executable acceptance and fault-injection matrix (future authorized work only)

| ID | Fixture / interleaving | Required proof |
| --- | --- | --- |
| M01 | Existing valid override Save → DB readback → reopen | Accepted identity/subject/HTML project + row revision exactly match durable stored values; Mail renderer allowlist unchanged. |
| M02 | Two tabs same template A/B: B saves after A, A stale write attempts | Exactly one matching expected revision wins; stale writer gets 409 and cannot clobber current template. |
| M03 | Two different template IDs with same starting revision | No cross-template overwrite; conflict/retry preserves both and inactive-provider entries. |
| M04 | Mail Save and Reset overlap; Reset and Reset overlap | No stale resurrection of deleted override; reset/version advances only on success. |
| M05 | Mail malformed project, invalid subject/provider, unknown ID, denied actor/nonce | No write and no revision advance; safe errors without content/secret logs. |
| M06 | Admin-post fallback with absent, stale and valid expected revision | Missing/stale fails closed and redirects with conflict; valid uses same version authority as AJAX. |
| M07 | Mail two-worker persistent cache: stale individual option, alloptions, notoptions and delayed refill | CAS decision/readback always DB-authoritative; no incorrect acknowledge or stale hydrated editor after response. |
| M08 | Same-value Save, PHP DB error, readback failure, revision overflow, absent-option INSERT race | Real one-shot ack or honest failure; no false success; no malformed/partially written option. |
| R01 | Reports Save → Undo → Redo; edits during pending Save | Save retains history and dirty value truth; no \`applyComposer()->replace()\` on ordinary save. |
| R02 | Two Reports tabs save in reverse completion order | Server rejects stale writer atomically, keeps newer Composer/branding, client draft/history remains recoverable. |
| R03 | Reports Designer save races unrelated \`Settings::set_key\` / module writer / \`ReportsSection::apply\` | Both the intended Reports state and unrelated settings survive; prove with actual independent PHP requests/interleaving. |
| R04 | SettingsMigrator + Reports writer collision; cache initialized before write | No revert of revision/report settings; no stale PHP \`Settings::$cached\` or cross-worker cache masking. |
| R05 | Reports Reset races Save, Profile import, and local edit | Explicit confirmed version check; stale Reset is 409; never overwrites newer local intent or unrelated report policy. |
| R06 | Reports save returns \`Settings::set_key=false\`, disk/DB failure or readback mismatch | Fail visibly; no audit \`updated\` or UI \`saved\`; unchanged persisted revision. |
| R07 | Profile export/import after cutover (if R-B approved) | Correct branding/Composer and policy fields, no ghost writes into legacy store, rollback/readback fixtures preserve original data. |
| R08 | Immutable existing report-data sentinel and current report PDF/preview | Save/Reset/Undo never mutates snapshot table; preview typed flow + PDF render-time branding policy stays intact. |
| X01 | Stale HTTP Save A then Save B, abort A locally after server began write | B wins or server 409; no false Saved / latest-issued-wins violations. |
| X02 | Wrong template/context or malformed accepted projection despite HTTP 200 | No Base \`rebase\`, no dirty reset, no document leak. |
| X03 | Versioned package rebuild not authorized in A1 | \`DOMAIN_SESSION_API\` stays 1, no v2 public Golden claim. |

Testing sequence after separate A2 source GO: focused JS consumer behavior first; isolated DB-capable PHPUnit CAS + two independent PHP process workers (not just sequential tests); error/failure hooks and actual persistent object-cache variants; \`bash tools/check\`; then **separate explicit Level 2 GO** on new isolated slot. Preserving G1b/G1c test slots and previous receipts is mandatory.

## 7. Concrete safe implementation order requested for A2 (not yet authorized)

1. Overseer choose R-A or R-B and approve exact storage migration/rollback and Mail reserved revision metadata. Resolve Options API hooks/object-cache caveats against installed WP 7.0 + target MariaDB and active cache provider **before** raw SQL introduction; no global cache flush or global lock.
2. Verify local repo/worktree clean and starting G1d-A branch HEAD; save fixture bytes, option autoload characteristics, plugin writers and per-site invariants in tests. A1 docs commit is not a code GO.
3. Implement one **small Mail-specific** CAS repository method, preserving Mail option map; migrate Mail AJAX/admin-post Save and Reset atomically, add typed authoritative response and readback; test no-op/error races.
4. For Reports follow **approved** R-A all-writer Settings change **or** R-B dedicated option with staged migration/readers/Profile conversion. No partial endpoint-only CAS.
5. Implement scoped Mail/Reports JS checkpoint/rebase + server version/dirty/Reset and conflict UX without changing Base history or other consumers.
6. Run targeted source tests, \`bash tools/check\`; provide exact HEAD and review receipts. New isolated WordPress Level 2, package build, merge, CI, field deployment still require separate GO.

## 8. Decisions explicitly requested from Overseer

1. **Reports shared-settings blocker:** approve investigation/implementation of **R-B** dedicated Reports Designer option + controlled migration/profile/reader rewrite (recommended to avoid general Settings refactor), **or** broaden scope to **R-A** coordinated Settings mutation/CAS for *all* Base writers. No choice = **A2 BLOCKED**.
2. **Mail revision shape:** approve one reserved metadata revision entry added under existing \`cb_core_mail_template_overrides\` via one-shot lazy versioning; authorize corresponding read/filter and rollback tests.
3. **Raw SQL CAS + caches:** approve narrowly scoped *prepared* same-row conditional UPDATE/INSERT after an executable object-cache/hook-parity safety plan; determine whether target runtime provider can meet stale-read/invalidated-cache acceptance. If not, request a different durable store for Mail as well.
4. **Reset policy:** confirm explicit full authoring-state Reset with post-success history reset (rather than ordinary Save) and mandatory stale-version conflict; no report snapshot changes.
5. **A2 source GO:** **not granted by this A1 design**. Need user's separate implementation authorization after Overseer decides the above.

**Final recommendation:** **G1d-A1 DESIGN RECORDED / SHARED-SETTINGS BLOCKER ESCALATED; G1d-A2 HOLD; G1d-B HOLD.** Preserve accepted G1b/G1c SHAs and storage untouched.
