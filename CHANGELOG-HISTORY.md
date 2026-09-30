## 1.0.0-rc5 — 2026-09-05

### Modal Foundation confirmation checkbox gate

- Add the public additive `confirmCheck: { label }` Modal Foundation option as a required, initially unchecked native acknowledgement gate without changing existing modal result types.
- Centralize confirm-button eligibility so typed confirmation, required input, `confirmCheck`, and async `onConfirm` busy state cannot independently overwrite each other's disabled state.
- Keep `confirmCheck` orthogonal to existing modal modes, with all active gates required before Confirm becomes available and invalid labels failing closed.
- Present the shared gate through both Core Admin and WordPress-native Modal adapters without introducing consumer-specific modal code or business logic.
- Keep the public Core API version and database schema version at `1.0`; stable `1.0.0` remains a separate explicit approval gate after staging/manual validation.

## 1.0.0-rc4 — 2026-09-05

### Golden Standard release-quality closure

- Promote the next testable Base candidate to `1.0.0-rc4`; stable `1.0.0` remains a separate explicit approval gate after staging/manual validation.
- Align the locale allowlist and endonym labels with all seven shipped languages: EN, NL, DE, FR, ES, IT and PT.
- Continue the release-quality architecture cleanup identified by the Golden Standard QC without changing the public API version (`1.0`) or database schema version (`1.0`).
- Keep Failsafe hardening out of rc4 unless the previously identified abuse concern is reproduced as a production defect.

## 1.0.0-rc3.41 — 2026-09-04

### BASE-V1-H — Final Base release candidate

- Mark rc3.41 as the controlled final-RC candidate for the Base v1 release-closure cycle; stable `1.0.0` remains a separate explicit approval gate after staging/manual validation.
- Add an allowlist-based deterministic production ZIP builder that always packages below the canonical `core-blueprint/` root and emits a SHA-256 checksum.
- Exclude repository/test tooling, Composer development metadata, PHPUnit configuration and GitHub workflow files from public release packages by construction rather than by cleanup after archiving.
- Add CI package-boundary validation for required runtime payload, canonical root, version consistency and packaged PHP syntax.
- Add real WordPress 7.0 / PHP 8.4 release-package smoke coverage for both a fresh rc3.41 ZIP install/activation and an update from pinned canonical rc3.40 to rc3.41 while preserving active state.
- Keep Base production domain/security behavior unchanged in H apart from the rc3.41 version marker.

### v1 hardening completed after rc3.28

The rc3.29-rc3.40 development interval was dominated by the structured BASE-V1 release-hardening roadmap rather than feature expansion. Major closure work included:

- reproducible PHPUnit/WordPress CI foundations across WordPress 7.0/7.1 and PHP 8.4/8.5;
- request-boundary lifecycle and data-ownership scenarios plus isolated destructive uninstall coverage;
- canonical Extension Starter source/consumer conformance and the full optional-module enable/disable/re-enable matrix;
- privileged-request, filesystem-recovery, Role Policy/Failsafe, Scanner/provenance and Media Replace durable-state conformance;
- removal of duplicated activation authority and centralization of extension lifecycle ownership;
- centralization of Core Admin asset-hook ownership and request-aware Content Models bootstrap boundaries;
- measured performance attribution, query-source provenance and targeted HUD update-transient cache priming without adding Base-owned cache state;
- public-API freeze/open-source hygiene, including the canonical root GPL license file.

The complete pre-H development history through `1.0.0-rc3.28` is preserved verbatim in [`CHANGELOG-HISTORY.md`](CHANGELOG-HISTORY.md).

## 1.0.0-rc3.28 — 2026-08-31

### BASE-10E.2.1 — Core Admin Design Foundation contract repair

- Keep rc3.27 screen-scoped asset architecture and minimal Core Admin shell unchanged.
- Expand PageRegistry with a small semantic Design Foundation component set: nav-tabs, panels, cards, badges, state-badges, status, empty-state, kv-table, form-controls and description-toggle.
- Route public component semantics through the private AdminAssetCatalog so extensions never depend on Base CSS handles, filenames or bundle boundaries.
- Add normative markup/behavior contracts and make Base the canonical visual owner of shared Core Admin primitives; first-party extensions may compose but not locally redraw those primitives.
- Deliberately do not promote table-cols, policy-table, log-table or other page/feature styles to public Foundation contracts.

## 1.0.0-rc3.25 — 2026-08-31

### Media Replace — native attachment authorization + D3 AJAX UI correction

- Separate site-wide Media Replace management (`cb_manage_media_replace`) from per-attachment use (`cb_replace_media`).
- Make `cb_replace_media` an attachment meta-capability that delegates ownership/editability to WordPress' native `edit_post` mapping and additionally requires `upload_files`; missing or non-attachment objects fail closed.
- Keep module enabled-state authorization separate from attachment authorization; CB Operator receives module-management authority but no implicit content/media rights.
- Keep Role Policy at public schema 1, add `cb_manage_media_replace` to the canonical Administrator + CB Operator policy, and make explicit role-policy repair remove stored Base meta-capabilities from any role.
- Restore `attachment_fields_to_edit` during Media Library AJAX so the Upload replacement action is available in the grid/modal as well as normal attachment-admin flows.
- Keep Trust Schema 1 unchanged; the new management capability is outside the privileged fingerprint boundary.

## 1.0.0-rc3.24 — 2026-08-31

### Media Formats — WordPress image-editor hook contract hotfix

- Register the `image_editor_output_format` callback with only the single MIME-format mapping argument that Media Formats actually consumes.
- Remove unused filename/MIME callback parameters so WordPress image-editor save/subsize paths may pass nullable auxiliary values without triggering a PHP 8 `TypeError`.
- Keep Media Formats output-policy behavior unchanged for original, WebP and AVIF generation; this release is a hook-boundary fix only.

## 1.0.0-rc3.23 — 2026-08-31

### BASE-10D.3 — Mixed admin integration boundary

- Split Package Downloads admin integration by request context: normal admin screens keep plugin/theme UI wiring, while `admin-post.php` alone owns the package-download mutation handler.
- Split Media Replace admin integration by request context: normal admin screens keep UI wiring plus attachment preview cache-busting, Media Library AJAX keeps only the preview cache filter, and `admin-post.php` alone owns the replacement mutation handler.
- Keep existing Package Download and Media Replace handler/filter implementations unchanged; this release changes bootstrap wiring only.
- Preserve Content Models, HUD/public lifecycle, schema checks, Retention scheduling and Privileged Access resilience unchanged, closing BASE-10D without further hook-count-only optimization.

## 1.0.0-rc3.22 — 2026-08-31

### BASE-10D.2 — Admin action + CLI context boundary

- Add a small internal request-context classifier for normal admin screens, `admin-ajax.php`, `admin-post.php` and WP-CLI without introducing a public context framework.
- Keep Core Admin menus/pages/extension inventory/theme prepaint off `admin-post.php`; form-submit endpoints no longer boot browser presentation infrastructure.
- Register Mail, Snippets, Media Formats and HUD Preferences form-mutation handlers only on `admin-post.php`, while normal admin notices remain screen-scoped.
- Install the WP-CLI `plugins_loaded` command-registration hook only during real WP-CLI requests; command registry, timing and security semantics remain unchanged.
- Leave Media Replace, Content Models, Retention scheduling and Privileged Access cron resilience unchanged because their WordPress/runtime boundaries do not justify further gating in this phase.

## 1.0.0-rc3.21 — 2026-08-31

### BASE-10D.1 — Conditional runtime boot + Role Policy schema

- Split normal wp-admin and `admin-ajax.php` bootstrap work so Base does not wake admin presentation services for AJAX requests while preserving the public Core lifecycle.
- Register Access Mode, Login Shield and Core Shield enforcement hooks only when their runtime is actually active; management/discovery paths remain available independently.
- Replace per-request role/capability auto-repair with Role Policy Schema 1: normal runtime detects and audits drift but never repairs missing/corrupt policy state.
- Make `cb_core_hud_use` a real Administrator + CB Operator capability and remove the HUD `user_has_cap` compatibility mapper without changing Trust Schema 1 or privileged fingerprints.
- Add the narrow, idempotent `cb permissions repair-role-policy` recovery command; browser Console requires role-management authority while server WP-CLI remains the break-glass path.
- Treat a missing `cb_operator` role definition as zero effective Operators even when stale usermeta still contains the role slug, preserving the zero-operator failsafe without silently recreating the role.

## 1.0.0-rc3.20 — 2026-08-31

### BASE-10C.11 — Vendor-neutral Content Models portability

- Remove the pre-v1 vendor-specific Content Models migration layer completely; Base no longer contains vendor importer runtime, UI, actions, transients, value migration or compatibility scaffolding.
- Formalize Content Models JSON Schema v1 (`core-blueprint-content-models`, format version 1) as the stable schema-only portability format; customer content values remain outside the document.
- Add a Base-internal Native WordPress Import workflow that discovers only WordPress runtime registrations for custom post types, taxonomies and explicitly registered metadata.
- Fail closed for lossy or ambiguous runtime semantics, require explicit UI mapping for registered metadata, never infer Option Pages or scan arbitrary metadata keys, and never copy customer values.
- Use user-scoped, short-lived, integrity-signed import plans with repository, registration and exact selected-value revalidation before one all-or-nothing schema merge.

## 1.0.0-rc3.19 — 2026-08-31

### BASE-10C.10 — Canonical retention policy and destructive correctness

- Make the five AuditLog retention categories (`security`, `maintenance`, `logins`, `settings`, `general`) the only v1 audit-retention policy and remove the old global audit retention fallback.
- Centralize event-to-retention-category resolution and make destructive pruning use that same mapper, fixing normalized `system_login` under-deletion without a second SQL prefix model.
- Add optional canonical retention metadata to Governance event definitions and a separate controlled dedicated-store registry for Mail, Reports and first-party datastore retention.
- Remove the global CLI `--days` destructive override; category pruning now follows the configured governance policy.

## 1.0.0-rc3.18 — 2026-08-31

### BASE-10C.9 — Canonical Modal danger variant

- Migrate destructive Base modal calls to the canonical `confirmVariant: 'danger'` option.
- Remove the pre-v1 `danger` option alias from the shared Modal Foundation; typed-confirm, input, dismiss-only and callback semantics are unchanged.
- Update the public Modal Foundation documentation so `confirmVariant` is the only supported confirm presentation API.

## 1.0.0-rc3.17 — 2026-08-31

### BASE-10C.8 — Canonical extension registry

- Replace mutable extension-array enrichment with the controlled public `CB\Core\ExtensionRegistry` registration/discovery boundary.
- Use one canonical extension ID, unique WordPress `plugin_file` ownership, Core API compatibility and separate installed/active/registered/compatible/health states.
- Remove private `cb-*`/hardcoded product discovery and extension `card_id` aliases; first-party auto-discovery is inventory-only.
- Resolve extension health through the canonical `Modules\Status` registry and keep `off` distinct from unknown/unhealthy presentation.

## 1.0.0-rc3.16 — 2026-08-31

- Fixed Core Scanner bulk baseline updates so reviewed component candidates merge atomically into an existing approved baseline instead of replacing unrelated trusted entries.
- Baseline update, lookup and explicit removal now share one canonical component identity (`type + slug`).
- Bulk baseline approval validates every selected candidate before one persistence commit; any unsafe candidate leaves the existing baseline unchanged.

## 1.0.0-rc3.15 — 2026-08-30

### BASE-10C.7C — Baseline candidate correctness

- Distinguish whole-component local-baseline snapshots from file-level drift through canonical `verification.scope` semantics.
- Baseline review/approval now selects only review-worthy component snapshots; file-level drift and passed local-baseline checks never become baseline candidates.
- Keep incomplete component snapshots visible as candidates while the independent completeness guard continues to block unsafe approval.
- Preserve failed-approval fail-closed behavior: an existing trusted baseline is not modified unless the selected component snapshot is complete and approval-safe.

## 1.0.0-rc3.14 — 2026-08-30

### BASE-10C.7B — Public Integrity/Core Scanner service

- Rebase `IntegrityApi` to the public scan-ID-scoped v1 service: request scan, read status, summary and paginated Finding Schema 1 anomalies.
- Keep Scanner controllers, repositories, resumable-job persistence and Storage Schema 1 payloads internal; stale scan IDs never fall back to a different/latest result.
- Make the internal wp-admin REST controller adapt to the public service while preserving the existing admin polling transport.
- Remove the private external `ScanController` bridge/source filter; Beacon now exposes its authenticated remote Scanner transport through `IntegrityApi`.

## 1.0.0-rc3.13 — 2026-08-30

### BASE-10C.7A — Canonical Scanner v1 storage + Finding Schema 1

- Rebase Core Scanner findings to one canonical Finding Schema 1 representation built around `type + target + meta`; remove duplicate flat finding aliases from persisted/operator-facing finding objects.
- Rebase Scanner persistence to Storage Schema 1 with `checks[]` for completed/job streams and `entries[]` for approved baselines; remove duplicate result/baseline shapes and pre-chunked compatibility reads.
- Make Scanner Storage Schema 1 shape-strict: latest results, resumable jobs, history and baselines are accepted only when they match their canonical v1 payload contract.
- Keep quarantine evidence and quarantined-file state independent and unchanged; only the live Finding-to-Quarantine adapter now consumes the canonical Finding shape.
- No private Scanner migration/reset code is shipped. Development Scanner state is reset externally before the first rc3.13 staging run, followed by a fresh scan and explicit trusted baseline.

## 1.0.0-rc3.12 — 2026-08-30

### BASE-10C.6 — Public Core Admin PageRegistry boundary

- Formalize `CB\Core\Admin\Page` + `PageRegistry` as the public v1 Core Admin page contract while keeping `PageBase` internal.
- Enforce strict kebab-case extension slugs, Base-reserved slug ownership, duplicate rejection and extension positions at `100+`/`null`.
- Add semantic `foundations`/`components` requirements so extensions declare shared UI capabilities rather than Base asset handles or filenames.
- Keep the current sibling hook-pattern detector transitional/internal for BASE-10E; registered pages remain the canonical public screen boundary.
- Remove the private `UI::enqueue_for_sibling_screens()` compatibility helper; Hub migrates separately to the public PageRegistry requirement contract.

## 1.0.0-rc3.11 — 2026-08-30

### BASE-10C.5 — Public database schema registry

- Add the public declarative `Database\SchemaRegistry` boundary and keep `DB`/`maybe_upgrade()` migration internals private.
- Make Base own schema-version advancement, exact multi-table verification, throttled health checks and token-owned atomic migration locking.
- Require idempotent installers and advance a schema marker only after every declared table verifies successfully.
- Migrate Base-owned Audit, Notes, Mail and Reports schemas to the same canonical lifecycle used by first-party extensions.

## 1.0.0-rc3.10 — 2026-08-30

### BASE-10C.4 — Public Governance/Audit facade

- Added the public `CB\Core\Governance\Audit::record()` boundary with strict collision-safe dotted event IDs and strict severity validation.
- Added bounded context sanitization/redaction and a controlled `EventRegistry` for human-readable event metadata.
- Kept `Log\AuditLog`, queueing, persistence, queries, exports and retention internals outside the public API.
- Removed the active `cb_core_event_labels` compatibility path and rebased Base event metadata onto canonical dotted definitions without changing existing stored event identities.
- Updated the normative public API documentation; Hub, Beacon and Backups are migrated separately as first-party consumers.

## 1.0.0-rc3.9 — 2026-08-30

### BASE-10C.3 — Canonical HUD registry

- Added the controlled public HUD section-type registry with protected `navigation`, `quick-actions`, and `status` built-ins.
- Added `cb_hud_register_section_types` and declarative custom namespaced section types using Base-owned `list`/`metrics` presentation primitives.
- Rebased HUD sections/items to strict kebab-case IDs with duplicate, orphan, incompatible-item and custom item-limit validation.
- Removed private/dead HUD sections `display`, `integrations`, and `wordpress`, plus the unused `note` item shape.
- Removed render-time HUD mutation filters and moved section manageability into canonical registry metadata.
- Removed the dead Display-section renderer, display-card JavaScript, and display-only CSS while preserving active BrandRegistry/header theme behavior.
- Updated the normative HUD/Public API documentation.

## 1.0.0-rc3.8 — 2026-08-30

- Complete BASE-10C.2 status-tile cleanup: remove the runtime-inert `Admin\StatusTile` producer and retired `cb_core_status_tiles` Base contract remnants.
- Remove the unused rich `Tile` status variant and its status-tile-only CSS while preserving canonical navigation, status-nav, quick and metric tile variants.
- Keep Dashboard module health on the canonical `Modules\Status` registry introduced in BASE-10C.1; no bootstrap, asset-scope, retention or Scanner contract work is included.

## 1.0.0-rc3.6 — 2026-08-30

- Complete BASE-10B pre-public source hygiene: remove the private version-normalization runtime path and dead legacy cleanup event label.
- Rebase active source documentation to public `@since 1.0.0` semantics and remove private development-version references from current code comments/Foundation docs.
- No canonical contract, bootstrap, asset, retention, Scanner Finding-schema, or extension compatibility refactors are included; those remain scoped to BASE-10C+.

## 1.0.0-rc3.5 — 2026-08-30

- Rebase Privileged Access from private privilege-schema history to canonical public Trust Schema 1 without changing approval signatures, privilege fingerprints, role policy, Enforce/Monitor semantics or Failsafe behavior.
- Replace the private `PrivilegeSchemaMigrator` / `cb_core_privilege_schema_version` contract with `TrustSchemaMigrator` / `cb_core_trust_schema_version`; public v1 has no historical trust migration steps.
- Remove private v6/v7 trust-root migration state, schema-specific approval rotation and automatic operator grandfathering. Missing guard markers now recover fail-closed and never create trust.

## 1.0.0-rc3 — 2026-08-30

- Start BASE-10B pre-launch lean-core cleanup: remove never-public standalone Notes/Integrity migrations, private Access Mode/event-type migration history, dead Scanner/HUD prototype code, and the predecessor-table cleanup command.
- Make Console registration require the canonical `CommandInterface`; malformed/non-interface and duplicate registrations are rejected instead of exposed through a compatibility path.
- Remove pre-v1 alert AJAX aliases, the old PDF KPI breakdown string input, and the unused `AuditLog::export_csv()` compatibility method.
- Remove the pre-`init` translation firewall; Core Blueprint integrations must follow the documented WordPress/Core Blueprint lifecycle instead of relying on early translated presentation calls.
- Keep settings schema, privilege/trust schema, Scanner storage schema, retention architecture, module/HUD contracts, bootstrap slimming and admin asset refactors unchanged for their dedicated BASE-10 phases.

## 1.0.0-rc1 — 2026-08-28

- Add the optional Media Formats module with governed SVG uploads and mandatory sanitization, WordPress-native WebP/AVIF handling, experimental JPEG XL uploads, HEIC/HEIF imports, generated-image output format policy, runtime compatibility diagnostics and `cb_upload_svg` capability governance.
- Expand Safeguards > Access Mode into four explicit policies: Public, Coming Soon, Maintenance and Admin-Only, with an explicit apply flow instead of an instant binary toggle.
- Add Coming Soon page selection with temporary 302 routing and configurable index/noindex landing-page semantics, plus Maintenance page rendering with HTTP 503 and optional Retry-After while preserving the originally requested URL.
- Add a centralized Access Mode request-bypass registry/filter for machine/webhook integrations while keeping admin, login, REST, AJAX, cron, WP-CLI and failsafe recovery paths reachable.
- Add the shared progressive-enhancement Select Picker Foundation for grouped native selects; Content Models Field Type now renders Basic, Choice, Media and Relations through the Foundation while preserving the real `<select>` as submitted/no-JS source of truth.
- Fix WP-CLI command registration so the audit-log reader is exposed as `wp cb logs tail` beside `wp cb logs prune`, avoiding an executable-parent/child namespace collision that previously aborted every WP-CLI request.
- Harden WP-CLI bootstrap against future registry namespace collisions from extensions: executable parent commands that also own child paths are skipped with a warning instead of taking down the complete `wp cb` command surface.
- Add Content Models Relations foundation with Post/Object, User and Taxonomy/Term relation fields, typed single/multiple native-ID storage, target restrictions, REST schemas, validation and non-destructive stale-target handling.
- Add the shared async Object Picker Foundation with Core/WP-native presentations; relation editors search server-side through field/location/capability-validated Content Models queries instead of loading large object catalogs into selects.
- Bump the Content Models persisted schema contract to v4 for relation field configuration while keeping existing v3 models/data readable without a destructive migration.
- Extend Content Models with Phase 2 native Custom Fields: governed Field Groups, post-type location rules, WordPress Metadata API registration, 14 foundational field types, native editor meta boxes, schema validation, opt-in REST exposure, immutable field identity/type, and non-destructive field/group deletion.
- Add the first Content Models foundation: an optional master-switched Base module for governed custom post types and taxonomies, native WordPress registration/storage semantics, immutable model keys, audit events, safe definition deletion, and deferred rewrite-rule refresh.
- Fix WordPress 6.7+ text-domain timing: Base now loads `core-blueprint` at `init` priority 0, defers pre-`init` audit-alert email presentation until `init`, keeps bootstrap requirement/role creation paths translation-free, and uses WordPress' `NOOP_Translations` as a pre-`init` firewall so sibling extensions cannot accidentally trigger Base JIT translation loading early.
- Normalize the pre-launch public Base version to `1.0.0-rc1` while preserving all internal database and privilege-schema versions.
- Extract Hub/Beacon runtime, pairing UI, Hub REST mirrors and Hub-owned lifecycle/cleanup into the standalone Core Blueprint Hub extension; Base retains only generic extension boundaries.
- Standardize Notes, Reports, Mail, Media Replace, Package Downloads, User Roles and Snippets on the shared immediate module activation contract and MasterSwitch Foundation.
- Integrate the native Snippets subsystem with operator-only governance, signed privilege-schema migration, quarantine/fingerprint coverage, integrity checks and emergency safe mode.
- Harden privilege-schema origin detection so the public version reset can never be misinterpreted as trusted legacy schema metadata; unknown origins remain fail-closed.
- Log the private `2.0.0-rc*` → public `1.0.0-rc*` transition as an explicit version normalization event for an accurate governance trail.

## 2.0.0-rc134

- Integrated the native Snippets subsystem for managed PHP, CSS, JavaScript and HTML snippets with file-based runtime storage, integrity fingerprints, atomic writes, import/export and emergency safe mode.
- Added the operator-only `cb_manage_snippets` trust capability to the signed privilege boundary and append-only privilege schema 3; existing approved operators migrate before role top-up.
- Snippets now consumes the shared immediate module MasterSwitch contract; switching state rebuilds the runtime index transactionally and preserves all stored snippet code/metadata.
- Added Snippets to Dashboard/HUD/module status discovery and central Core Blueprint admin asset loading.

## 2.0.0-rc133

- Added the shared immediate MasterSwitch contract to Media Replace, Package Downloads, and User Roles.
- Disabled Media Replace and Package Downloads now unregister their WordPress integrations and reject direct mutation/download requests while preserving stored data and settings.
- User Roles can now disable only the optional role-editor/profile integration; Operator approval, privileged-access quarantine, capability governance, and Core Shield enforcement remain always active.
- Dashboard module cards and the central module resolver now report the new module states consistently.

## 2.0.0-rc132

- Added a shared optional-module activation contract and AJAX boundary for Notes, Reports, and Mail.
- Notes, Reports, and Mail now use the same immediate MasterSwitch behavior; server state is authoritative and pages reload after successful transitions.
- Mail activation now validates provider readiness before the module can be switched on, while disabling always preserves configuration and logs the transition.

# Changelog — Core Blueprint

## 2.0.0-rc131

- Add a defensive duplicate-entrypoint guard so an accidentally activated second Core Blueprint copy cannot redefine constants or bootstrap a second runtime in the same request.
- Add `tools/package-release.py`: canonical `core-blueprint/` packaging plus blocking root/version/junk/symlink/PHP/JS/CSS validation.
- Document the hardened Base release process and stable plugin identity.


## 2.0.0-rc130

- Freeze the shared Overview contract on Icon + Button Foundation: Lucide icons/chevrons, open quick-actions section, no Dashicon/native-button implementation inside the shared partial.
- Add legacy Overview icon-slug aliases to the central Icon registry so existing callers remain compatible while rendering Lucide.
- Move Connection Log from historical `cb-core-filter-bar*` classes to the canonical Toolbar contract and migrate its actions/export icon to Button + Icon Foundation.
- Add `docs/foundation-v1-contract.md` documenting public enqueue/runtime boundaries, compatibility rules and release guardrails.


## 2.0.0-rc129

- Modernize Hub Pairing with shared Notice, Button, Clipboard and Form Controls Foundation primitives; remove local copy/Dashicon/form-control clones.
- Constrain Hub Pairing to the shared narrow Core Admin document width.
- Remove the Dashboard-local Empty State clone so the landing page consumes the shared Empty State Foundation directly.


## 2.0.0-rc128 — CLI multi-word command registry fix

- Fixed CLI command-name normalisation so multi-word subcommands such as `cb failsafe disable`, `cb failsafe enable`, `cb scan run` and `cb logs prune` retain their spaces instead of being incorrectly registered as concatenated aliases.

## 2.0.0-rc127 — Package Downloads narrow document layout

- Applied the shared `cb-core-wrap--narrow` document-page modifier to Package Downloads so navigation cards and archive guidance use the established 900px Core Admin content width instead of stretching across the full admin canvas.

## 2.0.0-rc126 — Package Downloads WordPress-Pro/governance audit

- Audited package archive creation/streaming, path containment, symlink skipping, temporary-file cleanup, nonce checks and audit logging; no critical archive-engine defects were found.
- Hardened theme package exports to require `install_themes`, matching the privileged nature of exporting complete theme source.
- Kept native Plugins/Themes integrations WordPress-native and corrected the theme-card action to the native `button-small` geometry.
- Replaced legacy quick tiles with the shared Overview navigation-card Foundation and replaced the Archive policy panel with Notice Foundation.

## 2.0.0-rc125

- Fixed the shared KV Table Foundation separated-border model by setting `border-spacing: 0`, removing native browser gaps between key/value cells while preserving rounded outer borders.

## 2.0.0-rc124

- Fixed the shared KV Table Foundation so row-header labels are left-aligned instead of inheriting the browser-default centered `<th>` alignment.

## 2.0.0-rc123 — Media Replace WordPress-Pro/Foundation audit

- Audited the transactional Media Replace engine, capability/nonce gating, rollback, metadata regeneration and audit logging; no critical runtime defects were found.
- Rebuilt the Core Admin Media Replace overview and replacement workflow around Status, Notice, KV Table and Button Foundation primitives.
- Removed obsolete panel-around-panel, local status-dot, safety-box and fact-list presentation clones.
- Added the shared Lucide `file` icon to the canonical Icon registry for non-image attachment previews.
- Kept native WordPress Media Library/attachment integrations native outside the Core Admin presentation boundary.

## 2.0.0-rc122 — 2026-08-27

- Notes interaction polish: icon-only duplicate/delete actions now use the shared Busy Foundation spinner state without replacing the icon with visible Saving text; Busy Foundation gains a reusable spinner-only button mode while preserving existing busy-button behavior.

## 2.0.0-rc121 — 2026-08-27

- Notes final polish: Modal Foundation now applies the shared scrollbar presentation to the actual scrolling dialog; expanded notes keep a neutral open state while selection owns the accent border; rendered note content no longer creates an inner panel; current status and duplicate Archive actions are removed from quick actions; Important/Backlog semantics are corrected; and note timestamps follow the WordPress site date/time format.

## 2.0.0-rc120 — 2026-08-27

- Notes workspace modernization: the main workspace now consumes the shared Scrollbar, Button, Badge, StateBadge, Icon, Field, Empty State and Notice Foundations; all remaining Dashicons and local badge/icon-button clones are removed. Actions-menu keyboard navigation is hardened, note action fallback forms now include the required nonce, nested import scrolling follows Core dark/light presentation, and stale Notes CSS selectors are removed.

## 2.0.0-rc119 — 2026-08-27

- Notes modal modernization: create, edit and JSON import now use the shared Modal, Field, Form Controls and Disclosure Foundations. The legacy Notes dialog engine and modal-specific form presentation were removed. Modal Foundation gains wide presentation, explicit initial focus and async `onConfirm` hooks that can validate/save before closing and keep the dialog open on failure.

## 2.0.0-rc118 — 2026-08-27

- Notes hardening: REST and classic POST mutations now verify database outcomes before reporting success, no-JS form fallbacks emit the same audit events as REST, invalid author/assignee IDs are normalized to valid WordPress users, import summaries track failed writes, destructive database failures remain distinguishable from zero-row results, and Notes timestamp documentation now correctly reflects WordPress site-local time.

## 2.0.0-rc117 — 2026-08-27

- WordPress-Pro audit: User Roles keeps its two-pane management workspace while moving actions, badges, capability disclosures and modal/filter fields onto the shared Button, Badge, Disclosure, Icon and Field Foundations.

## 2.0.0-rc116 — 2026-08-27

- Mail Log polish: add a clear visual gap between the filter toolbar and the native log table without changing global table or toolbar spacing.

## 2.0.0-rc115 — 2026-08-27

- WordPress-Pro audit: Mail Log now uses native `widefat striped` management tables, shared Notice/StateBadge/Badge/Button/Modal Foundations, open filter/destructive sections, and removes the legacy custom log grid, severity pills, panel wrappers and native confirm flow.

## 2.0.0-rc114 — 2026-08-27

- Fixed Mail → Test Email action-row cascade so the recipient field and submit button use the intended responsive spacing/layout instead of collapsing against each other.

## 2.0.0-rc113 — 2026-08-27

- Mail → Test Email UX polish: successful test sends now use Toast Foundation, failures remain persistent Notice feedback, delivery status is compact, and the recipient/send action shares one responsive task row.

## 2.0.0-rc112 — 2026-08-27

- Mail → Test Email now follows the WordPress-Pro/Foundation contract: open operational sections replace panels, delivery results use Notice Foundation, and the send action uses Button Foundation.

## 2.0.0-rc111 — 2026-08-27

- Mail → Settings now follows the WordPress-Pro/Foundation contract: open settings sections replace panel wrappers, subsystem enablement uses MasterSwitch, runtime state uses Status, structured checkbox rows use the shared Form Controls check-row primitive, and Save uses Button Foundation.
- Hardened Form Controls so native `hidden` semantics remain intact for form-state controls with explicit Foundation display styles.

## 2.0.0-rc110 — 2026-08-27

- Fixed Field Foundation hidden-state semantics so conditional fields remain hidden when the shared field layout supplies `display`.
- Added native date inputs to the shared Form Controls Foundation, including dark/light browser chrome via `color-scheme`.


## 2.0.0-rc109 — 2026-08-27

- Reports → Maintenance Report now follows the WordPress-Pro/Foundation contract: open sections replace panel-around-form/documentation wrappers, fields use the shared Field/Form Controls Foundation, notices use the Notice Foundation, and Generate uses the Button Foundation.
- Removed the now-unused legacy Banner CSS component from the Core Admin asset manifest.
- Fixed the Reports Overview bulk-cleanup success path to remove the renamed cleanup section without requiring a page reload.

## 2.0.0-rc108 — 2026-08-27

- Reports Overview now keeps native management tables outside visual panel containers, aligns report navigation with the Button Foundation, and separates destructive archive cleanup with document rhythm instead of a card surface.

## 2.0.0-rc107 — 2026-08-27

- Give Preferences → About a fuller WordPress-Pro reference layout with Suite identity/version metadata, three product principles, Active/Inactive extension state, and shared KV project information; preserve native management-table geometry and shared Foundation semantics.

## 2.0.0-rc106 — 2026-08-27

- Preferences → CLI now consumes the shared Clipboard Foundation for command copy actions; removed the local clipboard fallback/state implementation and moved Copy buttons onto the Core Button Foundation.

## 2.0.0-rc105 — 2026-08-27

- Keep the Permissions operator header on one line by consuming the shared 80px operator column and nowrap utility; rename redundant settings row labels to “Administrator access” and “Allowed actions”.

## 2.0.0-rc104

- Move Permissions identity badges into the shared Badge Foundation and align all Permissions save actions with the Core Button Foundation.

## 2.0.0-rc103

- Fixed Core Admin native `form-table` foreground colors so row labels, cell text, and descriptions remain readable in dark mode without changing WordPress settings geometry.

## 2.0.0-rc102

### Preferences / Notes — WordPress-Pro cleanup

- Removed obsolete Notes Preferences grid/header CSS left behind by the earlier pre-WordPress-Pro layout.
- Removed the stale Preferences select `width: 100%` cascade so the active WordPress-Pro form-table sizing is the single source of truth.
- Updated the Notes Preferences save action to consume the Core Admin Button Foundation.
- Preserved the native WordPress `form-table`, shared MasterSwitch, Notice, settings persistence, Notes enable-state, and all Notes runtime behaviour.

## 2.0.0-rc101

### Preferences / Reports — Field + Button Foundation adoption

- Migrated the ordinary report-branding fields (accent colour, provider name, provider contact) to the shared Field Foundation classes while preserving their existing IDs and report-specific controls.
- Updated the Reports Preferences primary/secondary actions and logo picker action to consume the Core Admin Button Foundation.
- Kept the logo preview, colour swatch layout, and tertiary Remove link task-specific; no generic lookalike component was introduced.
- Clarified the legacy Maintenance Report form-row CSS comment so it no longer claims Preferences/Notifications consume those page-local rules.
- No Reports enable-state logic, branding persistence, media selection, PDF rendering, or report-generation behaviour changed.

## 2.0.0-rc100

### Preferences / Appearance — shared scope + choice-card state contract

- Removed the legacy `cb-core-appearance-toolbar` class from Language and Appearance so the shared Preferences scopebar is the only geometry source.
- Moved the repeated reset-to-site-default action presentation into the shared Preferences stylesheet while preserving page-specific JS hooks.
- Added the shared `cb-core-choice-card` interactive state layer for two-scope selectable cards: hover/focus, user-selected, site-default badge, and checkmark presentation now have one Foundation source.
- Updated checkable RadioCards and Appearance theme cards to consume the same choice-card state contract while keeping their own content/preview geometry.
- Removed Appearance-local selected-user/site overrides and RadioCard-local duplicate state styling.
- No appearance preference resolution, theme registry, locale logic, persistence, or AJAX behaviour changed.

## 2.0.0-rc99

### Preferences / Language — shared scope control + RadioCard contract cleanup

- Moved the duplicated **My preference / Site default** scope-control geometry from Language and Appearance into the shared Preferences stylesheet. Both tabs now consume one source of truth.
- Language Description Style now uses the shared `cb-core-radio-grid--columns-2` Foundation modifier instead of page-local grid geometry.
- Folded the WordPress-Pro presentation of `RadioCard::VARIANT_CHECKABLE` into the RadioCard Foundation itself: top-aligned compact card geometry, no dashboard-style lift, and normal shared selection borders.
- Replaced the hard-coded CSS `SITE DEFAULT` content with a localised `data-site-badge` value emitted by `RadioCard`.
- No locale resolution, preference persistence, description-mode persistence, or AJAX behaviour changed.

## 2.0.0-rc98

### Preferences → Notifications — Foundation consolidation

- Replace the Notifications-local recipient row layout with the shared `Field` inline Foundation contract while preserving recipient input sizing and inline `FormStatus` feedback.
- Route recipient Save actions through the formal Core Button Foundation without changing their AJAX behavior.
- Add shared Badge severity-classification modifiers (`info`, `notice`, `warning`, `critical`) because severity is classification metadata rather than workflow state.
- Migrate Preferences → Notifications and Core Logs to the same shared severity Badge primitive; remove the Logs-local severity palette while preserving its presentation.
- Leave notification routing, permission checks, alert toggle behavior, recipient validation, throttling and persistence unchanged.

## 2.0.0-rc97

- Foundation: standardized card content alignment so shared cards anchor title/description content at the top.
- Form Controls no longer overrides RadioCard component alignment through the generic label/radio adjacency rule.
- RadioCard indicators now explicitly align to the top/title row.
- MasterSwitch / Consequence Selector option cards now follow the same top-aligned content contract.

## 2.0.0-rc96

### Preferences → Privacy — RadioCard grid contract

- Add shared `cb-core-radio-grid--columns-2|3|4` Foundation modifiers with responsive 4→2→1 / 3→2→1 / 2→1 collapse behavior.
- Extend `RadioGroup::render()` with an optional `columns` argument for explicit equal-column grid layouts while preserving the existing auto-fill grid when omitted.
- Render the four Governance presets as four equal columns on wide screens and the three IP handling choices as three equal columns.
- Remove the remaining Privacy-local preset grid geometry so responsive RadioCard layout is owned by Foundation rather than page CSS.
- No Privacy persistence, preset, logging, retention or IP-handling behavior changed.

## 2.0.0-rc95

### Preferences → Privacy — Foundation radio-card consolidation

- Replace the page-local Governance preset card clone with the shared `RadioCard` Foundation primitive while retaining Privacy-owned 2-column layout only.
- Add an explicit visual `active` state to `RadioCard` so a staged radio selection can differ from the currently effective configuration without conflating checked state and applied state. Existing consumers remain backward-compatible: when no explicit active state is supplied, checked radios keep the previous visual behavior.
- Move the whole-card keyboard focus outline into the shared RadioCard Foundation instead of keeping it as Privacy-only presentation.
- Route Privacy primary actions through the formal Core Button Foundation classes without changing their save/apply behavior.
- Declare Modal and Toast as explicit Privacy script-module dependencies; retain a native `window.confirm()` fallback if the shared Modal runtime is unexpectedly unavailable.
- Preserve Privacy persistence, preset matching/application, IP handling, verbosity, retention and storage-estimate logic unchanged.

## 2.0.0-rc94

### Token Input Foundation — Core segment geometry

- Prevent Core Form Controls styling from leaking into Token Input text segments by giving the internal segment-input contract sufficient component specificity.
- Keep text segments borderless, transparent and intrinsically sized inside the outer Token Input editor while preserving the shared Core form-control styling for normal standalone inputs.
- No Token Input runtime, parser, serialization, keyboard or presentation-boundary behavior changed.

## 2.0.0-rc93

### Token Input Foundation — Core Admin presentation

- Resolve Token Input presentation from the actual wp-admin screen when no adapter is explicitly supplied: Core Blueprint submenu pages use Core Admin; standalone extension screens remain WP Native.
- Keep Core-token presence alone presentation-neutral so standalone extensions cannot accidentally inherit the Core Admin skin.
- Align the Core Token Input editor, chips and disabled/read-only states with WordPress-Pro form-control density while retaining Core Blueprint dark/light tokens.
- Route available-variable controls through the shared compact secondary Button Foundation instead of local Token Input button styling.
- Preserve the parser, serializer, keyboard model and public Token Input runtime API unchanged.

## 2.0.0-rc92

- Fixed the shared `cb-core-kv` table component so the final body row no longer draws a second bottom border inside the table outer border.

## 2.0.0-rc91

### Token Input Foundation — WP Native presentation refinement

- Make Token Input presentation consumer-owned: the default `wp-native` adapter is no longer overridden merely because shared Core token styles are present on the request.
- Keep explicit `core` presentation available for Core Admin consumers.
- Restyle the WP Native Token Input as a native WordPress form control with white editor surface, standard border/focus treatment and compact neutral variable chips.
- Render available-variable controls as real WordPress secondary buttons on standalone admin screens.
- Replace opacity-based disabled/read-only presentation with native light-grey form-control states and muted chips.
- Preserve the Token Input parser, serializer, keyboard model, native-input state and public API unchanged.

## 2.0.0-rc90

### Clipboard Foundation — shared copy-to-clipboard contract

- Add `UI\Assets::enqueue_clipboard()` and the public `@cb-core/clipboard` module for Base and standalone extensions.
- Expose `window.cbCore.clipboard.copy()` and `window.cbCore.clipboard.enhance()` with explicit consumer-owned values; Clipboard never infers copy payloads from visible DOM text.
- Use the native Clipboard API with a local `execCommand('copy')` fallback and shared success/error Toast feedback.
- Enhance existing real buttons with shared Lucide copy/success icon state, busy handling, accessible labels and a destroyable instance lifecycle.
- Keep standalone screens WordPress-native while Core Admin reuses Button Foundation; Clipboard adds only presentation-neutral icon alignment.
- Add central Lucide clipboard aliases and Dutch Foundation strings.
- Leave existing legacy clipboard consumers unchanged for a later controlled migration sweep.

## 2.0.0-rc89

### Token Input Foundation — extension-ready public contract

- Add a shared progressive-enhancement Token Input around the original native text input; that input remains the only submitted/persistent serialized form state.
- Use native text/token segments without `contenteditable` or a hidden mirror field.
- Support consumer allowlists, cursor-position insertion, manual recognition, paste tokenization, atomic Backspace/Delete, token-boundary arrow navigation, accessible remove controls, serialized Select All/Copy, form-reset synchronization and external native-input resync.
- Expose `window.cbCore.tokenInput.create()` with `getValue()`, `setValue()`, `focus()` and `destroy()`.
- Add opt-in `UI\Assets::enqueue_token_inputs()` plus the `@cb-core/token-input` dependency and separate Core Admin / WP Native presentation adapters.
- Keep token meaning, required-token rules, preview/resolution, uniqueness and other business validation completely consumer-owned.
- Add Foundation i18n and public contract documentation.

## 2.0.0-rc88

### Overview Foundation — shared navigation-card contract

- Remove the complete Preferences-specific `cb-core-tab-card` override fork so Preferences and Safeguards consume the exact same shared Overview navigation-card component.
- Unify navigation-card grid behavior, gap, padding, radius, icon sizing, label/description typography, hover/focus behavior, trailing-arrow behavior and responsive layout through `overview-framework.css`.
- Preserve all Preferences and Safeguards navigation targets, labels, descriptions, Lucide aliases and functional behavior.
- No settings persistence, security logic, routing or backend behavior changed.

## 2.0.0-rc87

### Overview Foundation — shared status-card contract

- Move status-strip and status-card geometry out of the Safeguards page stylesheet into the shared `overview-framework` component where the shared `Overview::render()` contract belongs.
- Remove all Preferences-specific status-card lookalike overrides so Preferences and Safeguards now consume the exact same Foundation component for border, accent edge, radius, spacing, label typography, value typography and responsive grid behavior.
- Keep page-specific content and state semantics unchanged; Preferences remains neutral/accent while Safeguards can continue to use `ok`, `warning` and `critical` state accents.
- Remove the legacy Appearance theme override that redundantly restyled status cards and could suppress their semantic left-edge state colours.
- Leave Preferences navigation-card refinements and all functional settings logic unchanged.

## 2.0.0-rc86

### Preferences — Overview summary-card consistency

- Split the three Preferences overview summaries back into independent compact cards instead of one continuous segmented strip.
- Match the established Safeguards overview geometry with consistent card gaps, borders and radius while keeping Preferences metadata semantically neutral.
- Preserve the existing Admin theme, Language and Description style values, links and all remaining Preferences overview navigation unchanged.

## 2.0.0-rc85

### Preferences — About WordPress-Pro polish

- Remove the oversized shared Card wrapper around ordinary About content.
- Present the suite introduction as normal admin copy and keep the installed-plugin inventory as a native `widefat striped` table.
- Replace the presentation-card footer stripe with a quiet contact/footer row.
- Preserve the authoritative Extensions detector, Core Blueprint self-detection, fallback active-plugin scan, capability gate and all displayed plugin metadata.

## 2.0.0-rc84

### Preferences — CLI WordPress-Pro polish

- Replace the stacked app-style command cards with native `widefat striped` command-reference tables grouped by use case.
- Keep every command, example, capability hint and description unchanged while making the catalog substantially denser and easier to scan.
- Reuse the shared Badge Foundation for capability metadata and native WordPress buttons for copy actions.
- Flatten the Setup content into normal documentation flow and move the host-specific Cloud86 guidance to the shared Notice Foundation.
- Preserve the existing local clipboard/fallback behavior and all CLI runtime registration, capability enforcement and command implementations.

## 2.0.0-rc83

### Preferences — Permissions WordPress-Pro polish

- Flatten CB Operators, Page visibility and Administrator capabilities from visible panel cards into normal Preferences sections.
- Replace the local self-status banner and hard-coded check/cross glyphs with the shared Status Foundation while preserving the current operator/read-only explanation.
- Keep operator identity as Badge metadata, but remove the page-local duplicate badge base geometry and inherit the shared Badge Foundation.
- Present Page visibility and Administrator capabilities as native WordPress `form-table` settings while retaining three independent save boundaries.
- Move the read-only permissions message to the shared Notice Foundation.
- Preserve operator assignment, capability checks, lockout prevention, disabled-state behavior and all existing AJAX save contracts.

## 2.0.0-rc82

### Preferences — Notes WordPress-Pro polish

- Remove the redundant visible panel around the Notes MasterSwitch and use the shared transparent MasterSwitch shell.
- Move the disabled Notes state and POST save feedback to the shared Notice Foundation.
- Replace the two-column custom defaults grid with a native WordPress `form-table` for Default type, status, assignment, list layout and Details section.
- Keep Notes-specific defaults and the existing POST persistence contract unchanged.
- Declare `@cb-core/toast` explicitly for the Notes master-switch module because its failure path consumes the shared Toast Foundation.
- No note storage, repository, REST write gate, menu visibility, default values or state behavior changed.

## 2.0.0-rc81

### Preferences — Reports WordPress-Pro polish

- Remove the redundant visible panel around the Reports MasterSwitch and use the shared transparent MasterSwitch shell.
- Move the disabled Reports state from a local banner to the shared Notice Foundation.
- Flatten the Report appearance form from one large panel into the shared Preferences section flow while keeping Logo, Accent colour and Report provider as legitimate domain-specific controls.
- Reduce branding sub-section heading treatment and separators to normal WordPress-Pro settings geometry.
- Preserve Save/Reset FormStatus, Media Library logo selection, colour synchronisation and the existing reset confirmation semantics.
- No Reports enable/disable endpoint, immutable report data, branding persistence, logo resolution, PDF rendering or retention behavior changed.

## 2.0.0-rc80

### Preferences — Appearance WordPress-Pro polish

- Keep theme previews as a purpose-built visual selector because direct appearance preview adds real value; do not flatten them into a generic settings table.
- Present My preference / Site default as a restrained compact scope switch and tighten the surrounding toolbar/status geometry.
- Reduce theme-card padding/radius and remove hover lift while preserving live preview, partner-theme previews and selected-state clarity.
- Move How resolution works into the shared Preferences section rhythm.
- Repair the stale Appearance FormStatus hook so save/reset feedback reaches the canonical Foundation status element again.
- Make the Site default theme-card badge use the existing translated string instead of hard-coded English CSS content.
- No theme registry, preference resolution, auto-detect logic, partner theme API or persistence behavior changed.

## 2.0.0-rc79

### Preferences — Language WordPress-Pro polish

- Replace the custom oversized locale-select shell and decorative chevron with the shared native select/form-control presentation.
- Present My preference / Site default as a restrained compact scope switch instead of a pill-style app control.
- Keep Description Style on the shared checkable RadioCard primitive, but remove lift behaviour and reduce card geometry.
- Move Locale and Description Style into the shared Preferences section rhythm.
- Repair stale Language JS/template hooks so the canonical FormStatus receives save feedback and scope switching can re-sync the locale and description-mode sections correctly.
- No locale resolution, multilingual-plugin precedence, description-mode persistence or AJAX endpoint behavior changed.

## 2.0.0-rc78

### Preferences — Notifications WordPress-Pro polish

- Flatten Audit, Permissions, Core Scanner and Reports notification groups from visible panel cards into normal Preferences sections.
- Remove decorative Dashicons from section headings; the text hierarchy now carries the navigation and meaning.
- Keep each group's routing policy as a native `widefat striped` table with restrained WordPress-Pro spacing.
- Tighten recipient input/save/status rows and event-toggle spacing without changing the existing AJAX contracts.
- Move the Failsafe routing scope note to the shared Notice Foundation.
- No recipients, fallback routing, security gates, alert keys, throttling, toggle persistence or mail delivery behavior changed.

## 2.0.0-rc77

### Preferences — Privacy WordPress-Pro polish

- Flatten ordinary Privacy settings from visible panel wrappers into normal Preferences sections while preserving every setting and form field.
- Keep Governance Presets as a purpose-built selector, but remove the old cyberpunk corner accents, glow and lift animation in favor of restrained WordPress-Pro selection geometry.
- Move Custom-configuration and AVG/GDPR guidance to the shared Notice Foundation.
- Keep What gets logged and Retention as native `widefat striped` management tables with calmer table geometry.
- Reduce Estimated storage from an oversized accent KPI to compact settings metadata.
- Correct Apply preset semantics: it still requires confirmation, but no longer presents as `danger` because applying a preset is consequential rather than a destructive/irreversible action.
- No privacy persistence, preset values, IP handling, verbosity, retention, pruning, storage estimation or AJAX contracts changed.

## 2.0.0-rc76

### Preferences — Overview WordPress-Pro polish

- Keep the Preferences navigation cards because they provide useful orientation across the large tab set, but flatten their geometry to the WordPress-Pro direction.
- Consolidate the three informational current-state cards into one compact management summary strip.
- Remove card lift/shadow behaviour, reduce padding/radius, and use quieter navigation-card typography while preserving all links, capability gates, and current-state data.
- Scope the new presentation strictly to the Preferences submenu; the shared Overview framework and other consumers remain unchanged.
- No preference persistence, capability, theme, locale, description-mode, or navigation behavior changed.

## 2.0.0-rc75

### Logs — Audit table column layout polish

- Prevent Audit Log Context content from forcing Event, User and IP columns into unreadable one-character widths.
- Give the Audit Log a predictable fixed column layout with a wider minimum table width and horizontal overflow on narrower admin viewports.
- Make Context previews real block-level ellipsis containers so long technical context remains bounded to the Context column.
- No log data, queries, filtering, export, retention, System Log, Mail Log, Maintenance Log or Retention behavior changed.

## 2.0.0-rc74

### Logs — WordPress Pro management-data cleanup

- Migrated Audit Log, System Log and Maintenance Log event views from the custom CSS-grid table presentation to native WordPress `widefat striped` tables.
- Migrated the core Logs filters to the canonical shared `cb-core-toolbar` contract and removed Logs-only sticky positioning.
- Kept log severity as classification metadata using shared Badge geometry; Retention workflow/system states now use the shared `StateBadge` primitive.
- Flattened Retention from panel-wrapped tables into normal read-only wp-admin sections.
- Removed Dashicons from Audit/System/Maintenance export buttons and fixed a pre-existing stray closing `</section>` in the Maintenance Log template.
- No logging queries, storage, export actions, retention rules or prune behavior changed.

## 2.0.0-rc73

- Promote the existing Modal Foundation to an extension-ready public contract without replacing the native `<dialog>` runtime.
- Add `\CB\Core\UI\Assets::enqueue_modals()` so standalone Core Blueprint extensions can opt into Modal without loading the Core Admin Theme.
- Add an explicitly scoped WordPress-native Modal presentation adapter while retaining the existing Core Admin presentation under the Core Blueprint menu.
- Add `dismissOnly: true` for informational/help/reference dialogs that need one quiet Close action instead of redundant Cancel + Confirm controls.
- Pass shared Modal presentation and default i18n labels through the `@cb-core/modal` module data contract.
- Scope Core modal and semantic button styling so Core Admin token rules cannot bleed into the standalone WP Native adapter.
- Document the public Modal API, enqueue contract, supported modes, presentation boundary, and minimal Certificates Keyboard Shortcuts integration.
- Update the Dutch catalog for the shared Modal defaults, including Confirm, mismatch feedback, and generic input labelling.

## 2.0.0-rc72

- Finish the Core Scanner WordPress-Pro consistency sweep without changing scan, baseline, finding, quarantine, or REST behavior.
- Flatten the Scanner workspace sub-navigation from a segmented app-style control into a restrained WordPress-Pro underline navigation pattern.
- Replace the oversized custom Overview scan-state treatment with the shared Status Foundation and sentence-case status labels.
- Present Overview metrics as a compact management summary strip instead of five separate KPI cards, while preserving all scan counts and baseline metadata.
- Remove redundant outer panel surfaces from Findings and Quarantine so their existing toolbar, interactive rows, quarantine records, disclosures, badges, and actions define the workspace hierarchy.
- Tighten finding and quarantine row density and normalize remaining Scanner radii to the current Foundation geometry.
- Update the Dutch catalog for the revised Scanner status labels.

## 2.0.0-rc71

- Continue the Core Scanner WordPress-Pro review without changing scanner, baseline, finding, quarantine, or REST behavior.
- Present Scan History as a WordPress `widefat striped` management table instead of a stack of app-style history cards, while preserving StateBadge status and compact metadata badges.
- Flatten Distribution locale and Scanner settings into normal administration sections and replace the settings card-grid with a WordPress `form-table` layout.
- Route Scanner settings save feedback through the shared FormStatus primitive while retaining Toast feedback.
- Replace the Scanner disabled banner and performance-anomaly block with the shared Notice Foundation.
- Declare Modal and Toast as explicit Core Scanner module dependencies rather than relying on incidental page-level availability.
- Remove legacy Scanner quarantine fallback colour/radius variables and use the current Core Blueprint Foundation tokens consistently.

## 2.0.0-rc70

- Give Core Admin Toasts a full semantic border matching their `success`, `info`, `warning`, or `error` state for clearer visual feedback.
- Keep the standalone WP Native adapter unchanged so it continues to follow WordPress notice geometry with a semantic left stripe.

## 2.0.0-rc69

- Promote the existing Toast Foundation to an extension-ready public contract with `window.cbCore.toast(...)` as the shared runtime API.
- Add `\CB\Core\UI\Assets::enqueue_toasts()` so standalone Core Blueprint extensions can opt into Toast without loading the Core Admin Theme.
- Add a WordPress-native Toast presentation adapter for standalone wp-admin pages while preserving the existing Core Admin presentation under the Core Blueprint menu.
- Harden Toast accessibility and lifecycle behavior with an explicit keyboard-accessible dismiss control, hover/focus timeout pause, reduced-motion support, visible-message deduplication, and optional persistent/duration settings.
- Correct Toast semantic styling so actual `error` feedback uses the error token rather than the destructive-intent `danger` token.
- Document the public extension contract and a minimal Certificates integration example in `docs/TOAST-FOUNDATION.md`.

## 2.0.0-rc68

- Normalize Access Mode toggle direction so visual ON means Public Mode / site live and OFF means Admin-Only Mode / public site locked, without changing the stored `public` / `admin_only` backend modes.
- Add shared Consequence Selector geometry tokens and make Access Mode and MasterSwitch consume the same 920px max-width, column gap, card padding/radius, and 44×24 toggle geometry.
- Align MasterSwitch responsive stacking with Access Mode at 860px, including the same vertical toggle orientation on narrow layouts.
- Document the suite-wide rule that an illuminated binary toggle represents the normal active or available state rather than a restrictive/locked mode.

## 2.0.0-rc67

- Normalize Safeguards MasterSwitch presentation to the same transparent consequence-selector geometry used by Access Mode.
- Remove redundant `cb-core-panel` wrappers around Beacon, Login Shield, and Core Scanner master switches while preserving their internal consequence cards and toggle behaviour.
- Add the shared transparent `cb-core-master-switch-shell` layout hook and document that MasterSwitch consumers must not create box-in-box panel hierarchy.
- Remove Beacon's old extra master-panel top margin and Core Scanner's local wrapper padding so the shared Foundation owns the presentation consistently.
- Leave Reports and Notes legacy MasterSwitch wrappers unchanged until their dedicated WordPress-Pro module rounds.

## 2.0.0-rc66

- Restyle Beacon toward the WordPress-Pro management pattern while preserving all pairing, secret-key, Hub and endpoint backends.
- Replace the disabled-state and redirect-success presentation with the shared Notice Foundation and flatten Connection status into a normal settings section using the shared Status primitive.
- Flatten Stored secret key into a normal security-settings section, migrate Clear secret key to the danger Button Foundation with a destructive confirmation modal, and present Hub Pairing as a secondary action.
- Remove legacy Beacon-specific WordPress notice/form-table styling and keep only module-specific section rhythm in the page stylesheet.
- Declare Beacon Modal and Toast dependencies explicitly and add the shared delete icon to the permanent key-clear confirmation.

## 2.0.0-rc65

- Restyle Failsafe toward the WordPress-Pro management pattern while preserving all recovery, token, bypass, and password-confirmation backends.
- Replace emergency and token-warning presentation with the shared Notice Foundation and migrate live layer states to StateBadge.
- Present Failsafe layers and self-test results as WordPress `widefat` management tables instead of panel-wrapped or fully tinted result rows.
- Flatten Secret bypass URL and Emergency controls into normal settings sections with semantic Button Foundation actions.
- Use the shared Busy API for asynchronous actions, make the one-time bypass URL keyboard-copyable, and correct confirmation variants for token rotation and emergency bypass activation.
- Update Dutch translations for the revised Failsafe interface.

## 2.0.0-rc64

- Rework the Core Shield recommended-settings area into a single left-aligned vertical settings block beneath the MasterSwitch.
- Separate the Security level / Active preset label from its Status value so the current preset reads as a normal WordPress-Pro setting instead of a floating inline status.
- Keep Apply recommended settings and its helper text directly beneath the current Shield level, fully independent from the Off-state column.
- Preserve the MasterSwitch content inset so the Shield status indicator aligns with the On/Off state dots.
- Update Dutch translations for the new standalone Security level and Active preset labels.

## 2.0.0-rc63

- Move the Core Shield master control to the top of the page so the global security gate is encountered before detector/governance context.
- Increase major-section rhythm between the master control, complementary-plugin context, Privileged Access Guard, Security Modules, and Diagnostics.
- Align the Security level status and recommended-settings action to the same three-column geometry as the MasterSwitch; place helper text beneath its action instead of beside it.
- Add breathing room to expanded module and feature content and align standards/risk badges to the same left edge as the description text above them.
- Preserve all Core Shield module, feature, approval and diagnostic hooks; this release changes presentation hierarchy and spacing only.

## 2.0.0-rc62

- Add feature-level progressive disclosure inside Core Shield modules so enabled protections remain scannable even when a parent module is expanded.
- Keep feature status, title, concise Plain/Technical summary, toggle, and details chevron in the compact row; move full explanations, standards metadata, and delegated context behind the nested details control.
- Add reusable `UI::render_description_text()` output for mode-aware descriptions without repeating inline TECH/PLAIN controls at every information level.
- Remove inline description-toggle noise from Core Shield module/feature detail content while preserving the global Plain/Technical mode switch.
- Preserve all module/feature AJAX toggle hooks and keep nested technical details closed by default on each page load.

## 2.0.0-rc61

- Rework Core Shield information hierarchy around progressive disclosure while preserving all security, approval, module-toggle and diagnostic endpoints.
- Collapse privileged review evidence behind a clear review summary and keep healthy privileged-access state compact.
- Move delegated detector details behind a compact disclosure and combine Shield level plus recommended settings into one control row.
- Make compact Security Module headers true summary rows: status, title, short description, toggle and chevron; move full descriptions and technical badges into the expanded body.
- Reset the persisted module expansion UI key once so rc61 starts from the new collapsed default, then continues remembering operator choices.
- Group the Security Header Test under a dedicated Diagnostics section and update Dutch translations for the new UI copy.

## 2.0.0-rc60

- Restyle Core Shield toward WordPress-Pro geometry while preserving all hardening, privilege, module-toggle, header-test, and permission logic.
- Replace the complementary-plugin detector and privileged trust-boundary callouts with the shared Notice Foundation; move pending/healthy approval state to StateBadge and active preset state to Status.
- Correct modal semantics so applying recommended defaults and approving a verified privileged identity use primary consequential confirmation instead of destructive danger presentation.
- Add a reusable `cb-core-module-rack--compact` density variant, remove Core Shield pulse presentation, migrate collapse controls to the shared Lucide registry, and move risk metadata to shared Badge variants.
- Restyle the Security header test as a normal operation section with Button/Busy/Icon/StateBadge foundations while retaining its native WordPress `widefat` results table.
- Remove stale local module/risk/detector presentation CSS and hard-coded English header-test labels; update Dutch translations.

## 2.0.0-rc59

- Restyled Login Shield toward the WordPress Pro admin geometry without changing authentication, redirect or enforcement behavior.
- Replaced Login Shield-specific runtime banners with the shared semantic Notice Foundation.
- Promoted Advanced and capability-boundary help to the shared Disclosure primitive.
- Migrated Login Shield actions to the shared Button Foundation while preserving the custom login URL builder as a domain-specific control.
- Removed the outer Configuration card so ordinary settings now follow the page flow more closely.

## 2.0.0-rc58

### Changed
- Restyle Safeguards > Access Mode toward WordPress-Pro geometry while preserving the existing Public/Admin-Only AJAX behaviour, permissions, optimistic update, and rollback flow.
- Replace Access Mode hand-written SVG/emoji presentation with semantic Lucide aliases, the shared Notice primitive, and the shared Status indicator.
- Make the Public/Admin-Only choices native button controls with live `aria-pressed` state, compact the central mode toggle, and remove decorative glow/icon-bubble presentation.
- Extend the shared Notice primitive with optional escaped bullet items so consequence/prerequisite lists do not require module-specific warning-card components.

## 2.0.0-rc57

### Changed
- Restyled the shared MasterSwitch toward WordPress Pro geometry: tighter consequence cards, compact state indicators, a smaller binary control, and no decorative glow or pulse animation.
- Promoted the compact label/description layout to the shared MasterSwitch so Scanner no longer owns a local MasterSwitch presentation override.

## 2.0.0-rc56

- Formalizes Dashboard card semantics with separate `navigation` and `status-nav` variants while retaining `quick` as a backwards-compatible legacy variant.
- Migrates the Core Blueprint Dashboard so Safeguards and state-bearing Operations/Extensions use status navigation cards, while ordinary Operations, CMS Tools, Preferences, and About use calm navigation cards.
- Keeps the existing dashboard grid, card dimensions, content hierarchy, and status dots, but removes decorative lift/gradient behaviour from the new formal navigation variants in favour of a quieter WordPress-Pro hover/focus treatment.
- Documents navigation/status/metric card usage in the UI Foundation contract so new Base and extension surfaces do not overload one tile variant with multiple meanings.

## 2.0.0-rc55

- Starts the WordPress Pro Core Admin presentation direction: WordPress-like page geometry and density with Core Blueprint colours, dark/light identity, semantics, and Foundation components preserved.
- Compacts the global Core Admin page heading, intro copy, wrap spacing, and section rhythm.
- Restyles shared Core Admin tab navigation toward native WordPress `nav-tab` geometry while retaining Core Blueprint theme tokens and focus treatment.
- Reduces shared Panel and Card padding/radius, removes decorative section/card heading transforms, and keeps richer custom geometry reserved for workflows that genuinely need it.
- Documents the WordPress-geometry/Core-Blueprint-identity boundary in the UI Foundation contract.

## 2.0.0-rc54 — 2026-08-26

### Changed
- Extend the shared `CB\Core\UI\Field` contract with explicit error/help/meta slots, optional message IDs for caller-owned ARIA wiring, and a shared error state that uses the semantic `--cb-error` presentation rather than page-specific validation styling.
- Promote the existing suite-wide Filter Bar into the canonical `cb-core-toolbar*` composition contract while keeping `cb-core-filter-bar*` as a backwards-compatible alias for Logs/Reports; add compact workspace density, growable search fields, toggle controls, and shared action clusters.
- Migrate the Core Scanner Findings filter row to the shared Toolbar composition and remove its page-specific grid/surface/control-height implementation.
- Expand Form Control scope to explicit Foundation-owned overlays so shared modal inputs use the same control presentation as Core Admin forms without affecting unrelated WordPress admin chrome.
- Harden the shared native-dialog Modal Foundation with labelled title/body relationships, accessible input naming, error-token feedback, constrained viewport height, Cancel-first focus, and reliable focus return to the opener after close.
- Document Field, Toolbar, Modal, and form-presentation boundaries in the UI Foundation contract.

## 2.0.0-rc53 — 2026-08-26

### Added
- Add the shared `CB\Core\UI\StateBadge` / `cb-core-state-badge` primitive for compact workflow and security state, with independent semantic variants (`neutral`, `info`, `success`, `warning`, `danger`, `error`) and compact/default density.
- Add the shared `@cb-core/busy` / `window.cbCore.busy` API for button and region busy/loading state, preserving original button markup and using the existing Spinner primitive.

### Changed
- Migrate Core Scanner compact status pills to StateBadge so component state, coverage, findings, review state, history, and quarantine status no longer own page-specific colour semantics.
- Migrate Core Blueprint Notice icons from Dashicons to the central Lucide registry with semantic feedback aliases for info, success, warning, and error.
- Scope busy-region interaction locking to explicit Core Blueprint busy regions instead of globally styling every `[aria-busy="true"]` element in WordPress admin.
- Move generic busy-button spinner alignment into the Button Foundation and reduce Scanner to a thin consumer of the shared busy API.
- Align inline `FormStatus` failure feedback with the `--cb-error` token rather than destructive-intent `--cb-danger`, and document the shared status/feedback/loading contract in README.

## 2.0.0-rc52 — 2026-08-26

### Added
- Add `components/interactive-surfaces.css` as the shared Core Admin Theme home for reusable `cb-core-disclosure`, `cb-core-interactive-surface`, and `cb-core-interactive-row` primitives.
- Add central interaction-state tokens for hover, open, border-hover, focus, and transition timing so interactive components share one suite-wide state language.

### Changed
- Promote Scanner disclosure and finding-row presentation out of page-specific CSS into the UI Foundation; Scanner now keeps only finding/layout-specific overrides.
- Make section disclosures and interactive rows self-contained components with their own surface, border, radius, spacing, and semantic accent variants instead of relying on Scanner panel/card classes.
- Remove Scanner-specific disclosure state JavaScript and data hooks. Shared expandable components now rely on native `<details>/<summary>` behaviour and accessibility semantics, with hover/focus/open represented as component states rather than module-specific variants.
- Document the Interactive Surface contract and the Core Admin Theme versus WordPress-native presentation boundary in README.

## 2.0.0-rc51 — 2026-08-26

### Added
- Add a central curated Lucide icon registry through `CB\Core\UI\Icon`, with semantic aliases, safe inline SVG rendering, shared compact/default/large sizing, and a matching browser API exposed as `@cb-core/icon` / `window.cbCore.icon`.
- Add `CB\Core\UI\Assets::enqueue_icons()` so standalone WordPress admin screens can opt into the shared icon primitive without loading the Core Admin Theme.
- Add bundled Lucide/Feather license attribution for the curated icon subset.

### Changed
- Make Icon sizing a first-class Foundation concern and let Button consume the shared icon-size tokens rather than owning icon geometry.
- Extend shared modal confirmations with optional icon support and migrate Scanner quarantine, restore, delete, review, and disclosure affordances to the central icon registry while preserving text labels and action semantics.
- Replace Scanner CSS-drawn disclosure chevrons with registry-backed icons and document the cross-plugin icon contract in README.

## 2.0.0-rc50 — 2026-08-26

### Added
- Formalize the shared Core Blueprint Button primitive with central default/compact density tokens, semantic action variants, shared icon/label anatomy, and explicit busy/disabled states.
- Document the Core Blueprint UI Foundation contract in README, including Core Admin Theme versus WordPress-native presentation boundaries and the planned shared Lucide icon registry.

### Changed
- Separate button semantics, density, and interaction state so component meaning no longer depends on WordPress `button-small` or module-specific sizing. Default buttons remain localization-safe through `min-height` plus tokenized padding rather than a fixed height.
- Migrate Core Scanner to the formal Button API as the reference consumer: folder-level quarantine remains default remediation density, file-level and row utility actions use compact density, and destructive actions use the shared danger variant.
- Migrate existing explicit semantic button consumers in User Roles, Reports, Failsafe, and shared modals to the Button base primitive while preserving their existing action semantics.
- Remove Scanner-local button color semantics that duplicated the shared Foundation; Scanner now owns layout only while action meaning and presentation come from the shared component layer.

## 2.0.0-rc49 — 2026-08-26

### Changed
- Introduced semantic Core Blueprint admin action variants for primary, secondary, remediation, and danger actions while preserving legacy WordPress button compatibility.
- Extended the shared modal API with `confirmVariant`; legacy `danger: true` callers remain backward compatible.
- Migrated Core Scanner quarantine actions to the shared remediation variant so inline and modal actions use the same amber, reversible-remediation semantics.
- Normalized user-facing quarantine terminology to file/folder and removed Scanner-specific quarantine color overrides.

## 2.0.0-rc48 — 2026-08-26

### Changed
- Introduce a Scanner-local interactive-surface primitive and semantic `cb-core-interactive-row` component for Findings, sharing the same hover, open, border and focus tokens as the disclosure primitive without conflating component semantics.
- Give collapsed and expanded finding rows the same polished interactive affordance as other Scanner disclosures: internal spacing, rounded hover state, chevron state, focus ring and open-state treatment while preserving existing Review, baseline and quarantine actions.
- Synchronize `aria-expanded` for interactive finding rows through the existing native disclosure binding so keyboard and assistive-technology state stays aligned with the visual state.

## 2.0.0-rc47 — 2026-08-26

### Changed
- Introduce a reusable Core Scanner disclosure primitive (`cb-core-disclosure`) with shared summary, title, meta and body anatomy plus section, subtle and compact variants. About this scan, Verified / Passed Checks and Technical details now use the same native `<details>` pattern.
- Refine disclosure affordance with consistent inner padding, rounded hover/open states, chevron motion, keyboard focus, count alignment and body spacing so clickable headers no longer run flush against their hover surface.
- Replace the custom Technical details toggle/state JavaScript with native disclosure behavior while keeping remediation actions visible outside technical evidence.

## 2.0.0-rc46 — 2026-08-26

### Changed
- Polish the Core Scanner Findings workspace with consistent disclosure affordances: Verified / Passed Checks now has an explicit chevron, pointer/hover/focus states, and synchronized ARIA expansion state; Technical details uses the same stable chevron pattern.
- Compact the Findings filter toolbar by grouping Reset filters with Apply filters and replace textual component issue counts with compact accessible count badges.
- Strengthen local-baseline governance with a user-scoped, scan-scoped review state. Candidates must be explicitly marked reviewed before component approval becomes available, and bulk approval remains disabled and server-side blocked until every current candidate has been reviewed.

### Added
- Add reviewed-progress tracking (for example, “Reviewed 3 of 27”) and audit-log events when an operator marks a baseline candidate as reviewed. Review progress automatically resets for a new completed scan without mutating scan evidence.

## 2.0.0-rc45 — 2026-08-26

### Changed
- Refine the Core Scanner Overview into a clearer scan → review workflow: add a direct Review Findings call-to-action, make Scan Coverage tiles open their component in Findings, move Components ahead of the collapsed About section, and reduce the visual dominance of the Scanner On/Off control.
- Replace direct bulk local-baseline approval on Overview with a dedicated baseline-candidate review flow in Findings. The existing approval/update action remains available only after entering that review context and keeps its existing confirmation and permission boundaries.
- Move destructive result/baseline cleanup actions behind a compact Maintenance menu so routine scanning and investigation remain visually primary.

### Added
- Add an exact Baseline candidates filter to the Findings workspace, backed by the same candidate predicate used by the existing baseline approval engine.

## 2.0.0-rc44 — 2026-08-26

### Changed
- Give the Core Scanner scan-state heading the full summary width and place Critical, Warnings, Verified, Coverage, and Baseline in one equal five-tile row on desktop, leaving more room for longer localized status labels while retaining responsive fallbacks.

## 2.0.0-rc43 — 2026-08-26

### Changed
- Rework Core Scanner into a focused workspace with local Overview, Findings, Quarantine, History, and Settings views while preserving the existing scanner and quarantine security boundaries.
- Replace the nested Findings scroll area with normal page scrolling and progressive server-side rendering. Search and filters now operate on the complete stored finding set before pagination.
- Group Uploads evidence by its top-level uploads location for triage, so related executable files such as `cb-linktrees/` appear as one incident while every individual finding remains available as evidence.
- Make reversible quarantine remediation visually prominent without presenting it as a destructive action. Safe directory quarantine is surfaced once as the recommended folder action; permanent deletion remains the destructive danger action.
- Keep investigation cards compact by showing the relevant path and remediation first, with filesystem locations, hashes, file context, detection history, and other technical evidence available on demand.
- Redirect successful quarantine actions directly to the Quarantine Workspace and preserve dedicated search/filter state only inside the Findings view.

### Added
- Add a Findings toolbar with full-set search, Component, Severity, Status, and Actionable-only filters.
- Add finding and open-quarantine counters to the local Core Scanner workspace navigation.

## 2.0.0-rc42 — 2026-08-26

### Security
- Add a finding-driven Core Scanner Quarantine Workspace for actionable Uploads findings. Files and safe top-level uploads directories are revalidated against the latest scanned SHA-256 immediately before any filesystem mutation; stale or changed evidence is refused.
- Move quarantined payloads atomically out of the active WordPress tree into a private per-installation vault outside canonical `ABSPATH`. Symlinks, path escapes, broad year directories, restore collisions and tampered vault payloads are rejected fail-closed.
- Keep quarantine remediation operator-only behind `cb_manage_integrity_policy`; Scanner reviewers can inspect evidence, but only a CB Operator can quarantine, restore, change review state, add notes or permanently delete payloads.
- Make permanent deletion a two-phase audited transition. The payload is never deleted unless the `deleting` state is persisted first; ambiguous post-delete persistence failures remain visible as attention states instead of being reported as safely restorable.
- Serialize mutating actions per quarantine item and revalidate the canonical uploads boundary again inside the Vault immediately before restore. Parent symlinks, unsafe reconstructed destinations and concurrent Restore/Delete/State races fail closed.

### Added
- Add Quarantine Workspace item history with original path, finding ID, SHA-256 evidence, file count, size, actor/timestamps, notes and review state. All mutating actions also emit central Core Blueprint Audit Log events.
- Add read-only quarantine inspection, including safe text previews and per-file selection for quarantined directories without restoring executable content to the active site.
- Add Restore and typed-confirm permanent Delete actions. Restore never overwrites an occupied original path and verifies the quarantined payload against its stored manifest before moving it back.
- Preserve quarantined workspace evidence across Core Blueprint uninstall by storing it outside the ordinary `cb_core_integrity_*` cleanup namespace.
- Remove only short-lived quarantine mutation leases on uninstall; retained workspace records and private vault evidence are left untouched.

### Changed
- Count the actual number of findings in component issue headings instead of counting only grouped issue buckets (for example `Uploads (44 issues)` rather than `Uploads (1 issue)`).
- Keep remediation intentionally finding-driven rather than exposing a general-purpose browser file manager.

## 2.0.0-rc41 — 2026-08-26

### Changed
- Make Scanner component filtering server-side and pagination-aware. Core, Plugins, Themes, and Uploads filters now operate on the complete stored finding set rather than only the currently rendered first page, while remaining simple normal links that work without JavaScript.
- Make WordPress.org plugin/theme provenance conservative and independent from update transients. The installed component slug is tried directly against the official checksum source; third-party/self-hosted updater metadata is no longer treated as distribution proof.
- Clarify the global baseline action as an explicit bulk operation. The button now shows the number of eligible local plugin/theme baselines and the confirmation copy explains that all eligible component snapshots will become trusted reference states.
- Rank official-verification failures as verification/coverage problems ahead of ordinary first-run baseline setup notices.

### Fixed
- Use the prioritized anomaly list for the server-rendered Scanner admin page, not only REST consumers. Critical and real filesystem anomalies can no longer be hidden behind dozens of first-run baseline notices in the initial findings page.
- Fix component filtering so a finding beyond the first 50 rendered rows remains discoverable immediately by selecting its component. Progressive expansion preserves the active component filter.
- Prevent plugin/theme update-transient entries from causing premium or custom components to be mislabeled as failed WordPress.org verification.
- Force the shared Core Blueprint spinner wrapper and ring to fixed square flex geometry so the scan indicator remains circular instead of being compressed into an oval inside buttons.

## 2.0.0-rc40 — 2026-08-26

### Changed
- Prioritize operator-facing Scanner findings before pagination: critical findings appear first, followed by real anomalies, verification/coverage problems, and finally baseline/setup requirements. Scan execution order remains unchanged internally, but first-run baseline notices can no longer hide a later security-relevant finding beyond the initial findings page.
- Report the number of files inspected while capturing a first local plugin/theme baseline snapshot. These files are counted as inspected, not verified, so incomplete coverage remains explicit until the baseline is approved and checked file by file.
- Publish one compact successful WordPress Core verification check after a completely clean Core checksum scan so the Verified / Passed Checks UI reflects that Core was actually verified instead of showing zero.

### Fixed
- Left-align the Core Scanner action bar instead of pushing the primary scan action to the far right.
- Replace the rotating Dashicons update glyph in the scan button and progress header with the shared fixed-size Core Blueprint ring spinner. The spinner now rotates around its actual centre and remains contained inside the button.
- Preserve snapshot inspection counts in compact scan-history coverage metadata so historical coverage does not lose first-baseline inspection context.

## 2.0.0-rc39 — 2026-08-26

### Security
- Harden Core Scanner job execution with a per-slice execution lease and watchdog recovery. Duplicate workers for the same persisted job can no longer process the same cursor concurrently, while abandoned slices can be reclaimed safely after the lease expires.
- Treat the persisted scan job and central scan lock as the authoritative running state. Stale cron callbacks are inert and can no longer recreate cancelled or already-finished scans.
- Add stable single-read file hashing for checksum verification. MD5 and investigation SHA-256 are derived from the same open file handle, and observable file mutation during the read is reported as unverifiable/incomplete instead of being misclassified as a checksum mismatch.
- Reuse the stable checksum observation when classifying WordPress distribution-locale drift, avoiding a second read of the discriminator file and closing that TOCTOU classification window.

### Changed
- Make plugin/theme WordPress.org provenance detection conservative: an author profile or generic directory URL no longer proves that the installed component itself came from WordPress.org. Premium/custom components fall back to local-baseline verification unless component provenance is actually established.
- Rework “Changes Since Last Scan” around Scanner incident lifecycle rather than raw record diffing. The UI now distinguishes new, changed, resolved, and resolution-unconfirmed anomalies and only confirms resolution when current coverage for the relevant scope is complete.
- Keep scan progress/status REST responses compact by omitting bulk findings, diff data, and lifecycle payloads that already have dedicated paginated/result endpoints. This prevents large finding sets from being retransmitted on every progress poll.
- Use stable file probes for local baseline snapshots and investigation hashes so Scanner metadata is not silently produced from an observably changing file.

### Fixed
- Give coexisting findings on the same file independent incident identities where the signals are semantically different (for example an executable upload plus a suspicious PHP pattern), while preserving one stable identity for normal modified/missing/unexpected integrity drift of the same file.
- Fix the Scanner changes panel so new and resolved anomalies are not lost behind the old `added`/`removed` key assumptions.
- Allow the Scanner admin UI and Console to resume observing an already-running scheduled/API scan even when the local browser never created that job.
- Prevent expired progress transients from hiding a valid persisted scan job or creating phantom running state.
- Report checksum read/hash failures and files that change during verification as incomplete coverage with their exact paths instead of claiming a modified-file conclusion that was not reliably established.

## 2.0.0-rc38 — 2026-08-26

### Security
- Add a versioned Core Blueprint privilege schema for protected `cb_operator` capabilities. Legitimate Core-managed trust-capability additions can now rotate an already-valid signed operator approval without weakening Privileged Access Guard's fail-closed response to unrelated privilege drift.
- Approval migration is restricted to operators that had a cryptographically valid approval for their exact pre-migration state, and the post-migration state must differ only by the explicitly registered schema capability delta. Unapproved operators and unexpected concurrent privilege changes remain untrusted and are reconciled into quarantine.

### Fixed
- Prevent Core Blueprint updates that introduce a new protected operator capability from locking an existing approved CB Operator out of wp-admin. This fixes the rc37 upgrade regression caused by adding `cb_manage_integrity_policy` to the operator trust boundary.
- Seed the privilege-schema marker safely for rc37 installations that already contain the new Scanner policy capability, avoiding unnecessary approval rotation after the manual rc37 recovery path.
- Remove the privilege-schema option on uninstall and add explicit audit events for trusted schema migrations, rejected approval rotations, skipped migrations and migration failures.

## 2.0.0-rc37 — 2026-08-26

### Security
- Rebuild Core Scanner execution around persistent resumable scan jobs with one central cross-entrypoint lock. Manual, scheduled, Hub, API and WP-CLI scans now share the same bounded execution engine instead of falling back to long synchronous requests.
- Add bidirectional filesystem integrity comparison for WordPress Core, WordPress.org plugins and WordPress.org themes so modified, missing and unexpected files are all detectable. Unsafe manifest paths are rejected rather than resolved outside their intended component root.
- Replace aggregate-only local plugin/theme baselines with operator-approved per-file SHA-256 manifests. Premium/custom components can now identify the exact modified, missing or unexpected file; aggregate-only legacy baselines are no longer accepted as sufficient integrity proof.
- Add canonical filesystem containment and explicit symlink handling. Symlinks are not followed across scan boundaries, unreadable/path-escape/filesystem errors are retained as concrete findings, and affected coverage is reported as incomplete rather than silently passing.
- Split Scanner execution/review authority from Scanner policy authority. Ordinary Administrators may be allowed to run/review scans without receiving the operator-only capability that controls baselines, evidence removal, Scanner policy or alert routing.

### Added
- Add resumable file-level batching for Core, individual plugins, individual themes and uploads. The old 5,000-upload-file ceiling and other finding/path truncation limits are removed; work is bounded per execution slice instead of by a total scan cap.
- Add packet-safe generation-based chunked option storage for active jobs, latest scan results and local baselines. Large finding sets and large per-file manifests are written in verified chunks with pointer-last activation so partial writes cannot become the active state.
- Add explicit scan-coverage metadata that distinguishes anomaly status from verification completeness. Incomplete scans retain the affected component/reason and cannot be presented as a clean complete scan.
- Add Scanner incident lifecycle data for new, changed, persistent and confirmed-resolved findings. Resolution is only confirmed when the relevant scan scope was completely verified.
- Add richer investigation context including exact filesystem paths, expected/actual hashes, file size, modification time, first/last detection, change time and observation count where available.
- Add progressive finding retrieval and filesystem-path copy support in the Scanner admin UI so large result sets remain navigable without rendering every finding at once.
- Add dedicated Scanner notification preferences for critical incidents, warning incidents and confirmed resolutions.

### Changed
- Baseline approval now approves the exact complete per-file evidence captured by the reviewed resumable scan and immediately schedules a fresh verification scan; it no longer re-hashes a potentially large component synchronously inside the approval request.
- Treat WordPress cron scheduling independently from `DISABLE_WP_CRON`: installations using a real server cron can still run queued Scanner jobs, while WP-CLI `--wait` advances the same resumable job directly.
- Scanner wording now reports detected deviations and scan coverage instead of implying that a site is inherently safe.
- Separate successful/green scan samples from actual findings so UI limits cannot hide anomalies behind passed checks.

### Fixed
- Prevent overlapping manual/cron/Hub/API scans from racing on latest/history state.
- Persist partially completed plugin/theme component cursors between execution slices so very large components resume instead of restarting their first batch.
- Prevent incomplete coverage from generating false resolved-incident notifications.
- Route mixed critical and warning Scanner incidents independently so warning notification preferences cannot be bypassed by a critical finding in the same scan.
- Cancel active Scanner job/lock state on plugin deactivation and remove Scanner chunked storage plus policy capability state on uninstall.

## 2.0.0-rc36 — 2026-08-26

### Fixed
- Make the dedicated Privileged Access Guard WP-Cron sweep independent from the opportunistic `admin_init` throttle. A recent admin request can no longer suppress the autonomous sweep and delay detection of an inactive privileged identity changed outside WordPress APIs.
- Keep the ten-minute transient only on the admin-traffic fallback; the scheduled cron callback now always runs the existing full-user reconciliation when WordPress dispatches the event.

## 2.0.0-rc35 — 2026-08-25

### Security
- Add a dedicated ten-minute WP-Cron sweep for Privileged Access Guard reconciliation so inactive administrator-like identities changed outside normal WordPress APIs no longer depend on legitimate wp-admin traffic for detection.
- Keep the existing immediate mutation hooks, login/current-user reconciliation, and opportunistic `admin_init` sweep; the new cron event reuses the same reconciliation and quarantine registry instead of introducing a second security path.

### Changed
- Self-heal the Privileged Access Guard cron schedule while Core Blueprint is active and clear it on plugin deactivation so ZIP updates and lifecycle transitions cannot leave the unattended sweep missing or orphaned.

## 2.0.0-rc34 — 2026-08-25

### Security
- Reconcile the Administrator role against Core Blueprint's current trust model on load: retain the documented administrator view capabilities, remove Base-owned operator-only capabilities left behind by older builds, and keep dynamic report/integrity grants policy-driven instead of persisted. Sibling-plugin capabilities are not touched.
- Protect the CB Operator role on WordPress' native user Role path as well as Core Blueprint's Additional roles UI. Non-operators no longer see CB Operator in editable roles, and crafted base-role assignment requests are rejected server-side.

### Fixed
- Prevent stale Administrator capabilities such as `cb_manage_permissions` and `cb_use_cli` from collapsing the Privileged Access Guard trust boundary on upgraded staging installs.

## 2.0.0-rc33 — 2026-08-25

### Changed
- Replace the Test Email delivery-status table with a compact semantic detail list using the shared Core Blueprint status indicator for module/runtime state. This removes the awkward full-width 50/50 table treatment while keeping status information accessible and responsive.

### Fixed
- Give the Mail Log timestamp column enough fixed space for the full `Y-m-d H:i:s` value plus the optional Test badge, and keep the time cell on one line so it can no longer overlap the Status column.

## 2.0.0-rc32 — 2026-08-25

### Added
- Add a reusable Core Blueprint `Notice` UI primitive with `info`, `success`, `warning`, and `error` variants. Notices use the shared design tokens, semantic accent border, tinted surface, icon, and accessible status/alert roles so Base modules and sibling plugins can surface operator-facing states consistently without depending on WordPress admin notice styling.

### Changed
- Replace the Mail transport-conflict panel with the shared warning notice. Active transports such as FluentSMTP now receive a prominent amber warning treatment with warning icon and semantic title while Mail conflict behaviour remains unchanged.
- Remove the Mail-specific conflict-card CSS now that notice presentation is owned centrally by Base.

## 2.0.0-rc31 — 2026-08-25

### Fixed
- Keep Mail admin templates presentation-only by preparing the provider label and retention choices in the Mail admin page controller before rendering. This prevents the settings page from stopping at Mail Log retention and restores the Save Mail settings action.
- Apply the same presentation-only provider-label contract to the Test Email tab so it cannot fail when the Mail runtime becomes active.
- Style an active mail-transport conflict as an explicit Core Blueprint warning panel using the shared warning tokens, making conflicts such as FluentSMTP visually prominent without changing conflict behaviour.

## 2.0.0-rc30 — 2026-08-25

### Fixed
- Keep the selected value of native Core Blueprint `<select>` controls on `--cb-text-strong` during WordPress admin hover, focus, focus-visible and active/open states. This prevents WordPress admin form styling from turning the current select value dark in Core Blueprint dark mode.

## 2.0.0-rc29 — 2026-08-25

### Fixed
- Move suite-wide native text input, textarea and select styling out of the Security page stylesheet into the shared `components/form-controls.css` contract, so every Core Blueprint module receives the same form-control theme treatment.
- Theme native `<option>` and `<optgroup>` popup entries explicitly and advertise the active Core Blueprint `color-scheme`, preventing dark text on dark select dropdowns in dark mode while preserving light-mode behaviour.
- Remove the Language page's duplicate option-colour rule now that native select popup styling is owned centrally.

## 2.0.0-rc28 — 2026-08-25

### Added
- Added the first Core Blueprint Mail module release with an explicit master switch; when disabled it registers no mail transport hooks.
- Added Brevo transactional email delivery through the Brevo HTTPS API and a provider-independent Generic SMTP transport through WordPress PHPMailer.
- Added encrypted-at-rest Brevo API key and SMTP password storage using libsodium.
- Added a dedicated privacy-first Mail Log table with delivery metadata, provider status, test-mail markers, failures, message IDs and retention pruning; message bodies and attachment contents are never stored.
- Added Mail > Settings, Test Email and Logs plus a shared Mail Log tab in the central Core Blueprint Logs hub.
- Added conflict detection for common SMTP/mail transport plugins so Core Blueprint stays dormant while a competing transport is active.
- Added WordPress 6.9+/7 inline-embed awareness: Generic SMTP preserves native CID embeds, while the Brevo API transport fails clearly instead of silently sending broken inline content when embeds are present.
- Added Mail retention visibility to Logs > Retention and audit/system events for mail administration and delivery failures.

All notable changes to the Core Blueprint plugin.

Format based on [Keep a Changelog](https://keepachangelog.com/); versions follow [Semantic Versioning](https://semver.org/).

## [2.0.0-rc27] — 2026-08-25

### Fixed
- Refine Privileged Access Guard classification so security-sensitive Editor-level capabilities (`switch_themes`, `edit_theme_options`, `unfiltered_html`) remain part of an already-privileged identity's signed fingerprint without independently forcing ordinary Editor-like users into quarantine. Hard site-control, plugin/theme code-management, user-privilege, network, Core Blueprint trust, and `manage_options` capabilities remain immediate quarantine triggers.

## [2.0.0-rc26] — 2026-08-25

### Fixed
- Persist current-user Privileged Access Guard reconciliation on the early `init` lifecycle instead of `admin_init`. Direct database privilege changes that invalidate a signed approval are now written to quarantine before wp-admin authorization can redirect the blocked account, keeping runtime enforcement, Core Shield visibility, audit logging, and security notifications in sync. The periodic full-user sweep remains as the fallback for inactive identities changed outside WordPress APIs.

## [2.0.0-rc25] — 2026-08-25

### Added
- Add `CB\Core\PDF\Api\PdfApi` as the supported public PDF-rendering facade for sibling Core Blueprint plugins. The API exposes renderer availability and HTML-to-PDF binary rendering while keeping Dompdf, storage, download handling, caller-specific logging, and future certificate concerns outside the public contract.

## [2.0.0-rc24] — 2026-08-25

### Changed
- Improve Privileged Access Guard review-table readability by widening the Account and Detected columns, keeping account email addresses and detection timestamps on one line, and preserving horizontal scrolling on narrower admin viewports instead of forcing security details into cramped columns.

## [2.0.0-rc23] — 2026-08-25

### Added
- Add a fail-closed Privileged Access Guard to Core Shield. New administrators and custom admin-like identities are quarantined until an existing CB Operator approves their exact privilege fingerprint; WordPress role metadata is preserved while effective elevated capabilities are blocked.
- Add a Core Shield quarantine review panel with account, role, critical-capability, detection-source, and approval details. Privilege-bearing state changes invalidate prior approval and require a fresh operator decision.
- Add default-on, operator-controlled email alerts for privileged-account quarantine events, routed through the existing Permissions notification recipient.

### Security
- Protect the CB Operator trust boundary from silent privilege escalation, including direct role/capability changes that bypass normal WordPress role hooks. Runtime capability enforcement is backed by login/admin reconciliation and a periodic direct-state sweep.
- Remove automatic administrator self-promotion to CB Operator. Only first-ever plugin activation may establish the initial operator trust root; later deactivate/reactivate cycles never mint operator authority. Trusted server-side CLI remains the explicit zero-operator recovery path.
- Bind privileged approvals to an HMAC-signed current-state fingerprint and treat `cb_manage_permissions`, `cb_manage_roles`, and browser Console access (`cb_use_cli`) as trust-level capabilities.
- Restrict Permissions notification routing/toggles to CB Operators so a normal administrator cannot silence or reroute privileged-account quarantine alerts.

### Fixed
- Normalize Permissions audit event slugs consistently in the email router so existing operator-change / Operator Guard notification subscriptions match the event types emitted by AuditLog.

## [2.0.0-rc22] — 2026-08-25

### Fixed
- Rasterize user-supplied SVG Maintenance Report logos locally through Imagick before PDF rendering. This avoids Dompdf/php-svg-lib SVG paint-server limitations such as unsupported `linearGradient` fills while keeping the original Media Library SVG unchanged.
- Keep PDF logo rendering self-contained: SVG conversion happens in memory with a transparent background, is bounded to a 400×110 PNG render surface, rejects non-fragment SVG resource references before Imagick receives the source, and falls back to the bundled Core Blueprint logo if Imagick/SVG/PNG support or rasterization is unavailable. Existing JPEG/PNG logo handling is unchanged.

## [2.0.0-rc21] — 2026-08-25

### Fixed
- Optically align the shared Notes / Observations status-icon cell with each note title by moving the common icon position down 5px. The correction applies uniformly to every note item and every status state (`ok`, `info`, `warn`, `critical`); no Backups-specific styling or header changes are introduced.

## [2.0.0-rc20] — 2026-08-25

### Fixed
- Refine the shared Maintenance Report header metadata icon optical offset from 2px to 1px. All four icons move up by exactly 1px relative to rc19; icon geometry, sizing, spacing and the rc18 alignment contract remain unchanged.

## [2.0.0-rc19] — 2026-08-25

### Fixed
- Nudge all four Maintenance Report header metadata SVG icons down by a uniform 2px optical offset. The shared alignment contract from rc18 remains intact; no per-icon corrections or other layout changes are introduced.

## [2.0.0-rc18] — 2026-08-25

### Fixed
- Vertically align Maintenance Report header metadata icons with their labels and values using a shared Dompdf-safe table-cell contract. Text now uses a natural line box centred by the cell, while icon cells use zero line-height with an inline SVG centred in the same row height; no per-icon offsets are used.

## [2.0.0-rc17] — 2026-08-25

### Fixed
- Replace the generic PDF header metadata bullets with embedded path-based SVG icons for Period, Generated, Prepared by and Contact. The header now uses the same Dompdf-safe, local-only icon strategy as the status and note markers.

## [2.0.0-rc16] — 2026-08-25

### Fixed
- Render Maintenance Report banner and note-status icons as self-contained path-based SVG data URIs instead of font glyphs. This keeps check, info, warning and critical marks optically centred and prevents Dompdf font-metric/rendering differences from dropping the small note icons.
- Keep the icon resources local and embedded; PDF remote resource access remains disabled and no external icon/font dependency is introduced.

## [2.0.0-rc15] — 2026-08-25

### Fixed
- Separate backup-adapter registration from maintenance-report recovery state. Reports now count only providers that explicitly opt in as full-site recovery providers and whose `availability()` check succeeds, so a registered adapter is no longer treated as a configured backup system.
- Mark All-in-One WP Migration as a full-site recovery provider while keeping the built-in database export as a partial backup capability. Database-only exports therefore no longer satisfy the Maintenance Report's site-recovery health check.
- Preserve period-bound backup counts while surfacing the most recent known backup outside the selected period when available. A quiet report period can now show both `0 backups` and the site's latest backup timestamp without borrowing that backup into the period count.

## [2.0.0-rc14] — 2026-08-24

### Fixed
- Register the shared backup-provider registry regardless of Beacon pairing state while keeping Beacon REST routes paired-only. Maintenance Reports can now detect the existing All-in-One WP Migration provider and its `.wpress` backups without requiring Hub pairing.
- Resolve intrinsic SVG logo dimensions from `width`/`height` or `viewBox` data before rendering the maintenance PDF. Valid SVG report logos are no longer silently skipped by the raster-only `getimagesizefromstring()` path.
- Replace personal Infused/Chris email examples and translation metadata with generic placeholders. Report contact examples now use `support@yourwebsite.com`; CLI examples use reserved `example.com` addresses.

## [2.0.0-rc13] — 2026-08-24

### Changed
- Replace the agency-specific maintenance-report identity fields with optional generic `provider_name` and `provider_contact` settings. The report provider is no longer assumed to exist and no provider fallback is applied when the fields are empty.
- Rework the Reports Preferences copy and layout around `Report appearance` (logo + accent colour) and an optional `Report provider` section (name + contact).
- Make the PDF header always identify the reported WordPress site using the immutable snapshot site title and site URL. Optional provider details now appear separately as `Prepared by` and `Contact`.
- Bump the settings schema to v6. The obsolete agency/contact identity keys are removed cleanly; existing logo and accent-colour settings are preserved while the new provider fields start empty.

## [2.0.0-rc12] — 2026-08-24

### Fixed
- Restore the packaged Dompdf 3.1.6 runtime and its dependencies to upstream behavior. Remove the blanket Core Blueprint `strict_types` injection from third-party PHP files and remove the temporary rc10/rc11 Dompdf compatibility casts that were only compensating for that injected strict mode.
- Reconstruct the seven Dompdf 3.1.6 production changes from the pristine 3.1.5 baseline and verify the security-sensitive/runtime files against the official 3.1.6 Git blob hashes. Third-party vendor code now contains no Core Blueprint patches or comments.
- Remove the blanket Core Blueprint `strict_types` transformation from the packaged Composer/autoloader files and keep dependency security policy in the Core Blueprint `Renderer` wrapper instead of modifying vendor code.
- Restore report-logo support for local SVG Media Library attachments alongside JPEG and PNG. Logos remain bounded to 2 MiB; raster logos retain the 4096 × 4096 dimension limit, while SVG and the trusted bundled fallback logo are embedded as local data URIs so Dompdf remote access stays disabled.

### Changed
- Document the PDF integration rule that third-party dependencies must remain verbatim and must be excluded from Core Blueprint strict-types, formatting and hardening transforms.

## [2.0.0-rc11] — 2026-08-24

### Fixed
- Extend the bundled Dompdf 3.1.6 PHP 8+ compatibility patch to cast Dompdf's numeric internal frame IDs, generated frame IDs, child counts and list counters to strings before passing them to `DOMElement::setAttribute()`. This fixes the confirmed Maintenance Report render failure `DOMElement::setAttribute(): Argument #2 ($value) must be of type string, int given`.
- Expand the PDF renderer maintenance note so future Dompdf upgrades verify both the page-ID/`mb_strlen()` and DOM-attribute compatibility patches against upstream before removing them.

## [2.0.0-rc10] — 2026-08-24

### Fixed
- Fix the confirmed Dompdf 3.1.6 PHP 8+ page-rendering TypeError: `newPage()` passes an integer page ID to `o_contents()`, while `o_contents()` calls `mb_strlen()` on that value. The bundled compatibility patch now casts that page ID to the string type required by Dompdf's own `string|array` contract.
- Document the bundled Dompdf compatibility patch in the Core Blueprint PDF renderer so future dependency updates explicitly verify whether upstream has incorporated an equivalent fix before the local patch is removed.

## [2.0.0-rc9] — 2026-08-24

### Changed
- Remove SVG/data-URI icons from the maintenance PDF template and use Dompdf-safe HTML/CSS glyph markers instead as PDF-rendering hardening.
- Keep the browser branding preview free to use the bundled SVG fallback, but restrict the PDF render path to validated JPEG/PNG logo data only.
- Note: this SVG hardening did not resolve the subsequently confirmed Dompdf 3.1.6 integer page-ID TypeError; that root cause is fixed in rc10.

## [2.0.0-rc8] — 2026-08-24

### Changed
- Rework Maintenance Reports around immutable database snapshots. Report generation now stores report data only; PDFs are rendered on demand when an authorised user views or downloads a report and are never persisted in public uploads storage.
- Harden the PDF boundary: update the bundled Dompdf metadata and security fixes to 3.1.6, disable remote resources, embedded PHP and PDF JavaScript, and apply a bounded decoded-image memory limit.
- Restrict custom report logos to local JPEG/PNG Media Library attachments up to 2 MiB and 4096 × 4096 pixels; branding is resolved at PDF-render time instead of duplicating base64 snapshots in each report row.
- Replace the Reports table with the clean v2 snapshot-only schema; remove obsolete PDF path/hash, branding snapshot and mail-delivery fields from the active data contract.
- Make report periods strict `Y-m-d` values in the WordPress site timezone and preserve true section totals when printable detail rows are capped at 500.
- Isolate backup-provider failures so one unavailable provider cannot abort an otherwise valid maintenance report.
- Update the Reports CLI, notifications, audit labels and admin copy to the snapshot/on-demand PDF model.

### Fixed
- Treat snapshot persistence failure as generation failure instead of reporting a successful orphaned report.
- Route Reports generation-failure alerts through the actual normalized audit event.
- Fix the Delete All Reports UI cleanup selector so the cleanup panel disappears immediately after the archive is emptied.
- Render stored UTC timestamps in the WordPress site timezone in report overview/PDF output.

## [2.0.0-rc7] — 2026-08-24

### Fixed
- Fix theme Download URLs passed through script-module JSON. `wp_nonce_url()` HTML-escapes query separators as `&amp;`, which is correct in HTML attributes but remains literal inside JSON and caused the admin-post handler to receive `amp;type` / `amp;package` instead of `type` / `package`.
- Build the nonce query argument as a raw URL value and keep HTML escaping at the existing HTML output boundary. Plugin downloads remain unchanged in behavior.

## [2.0.0-rc6] — 2026-08-24

### Fixed
- Rebuild the release archive with deterministic WordPress-safe filesystem metadata: directories are packaged as `0755` and files as `0644`, with no inherited setgid/group-write bits from the build workspace.
- Strip non-essential ZIP extra metadata from the release artifact so WordPress' upgrader receives a conventional plugin package during extraction and copy.
- Preserve the rc5 Package Downloads implementation unchanged; this release candidate is a packaging/installability correction after the rc5 upload installation failed part-way through and left an incomplete plugin tree.

## [2.0.0-rc5] — 2026-08-24

### Fixed
- Fix Appearance → Themes Download actions disappearing during the real WordPress admin lifecycle. Core `theme.js` replaces the complete `.themes` collection when its Backbone Appearance view initializes, which detached the rc4 observer together with the server-rendered cards.
- Observe the persistent `.theme-browser` lifecycle root instead, so Download actions are applied to the final Backbone-rendered cards and survive later filtering/re-rendering.
- Use WordPress core's native `data-slug` card attribute as the primary stylesheet identifier, retaining existing element-id parsing only as a defensive fallback.
- Keep Theme Details untouched and retain the vanilla-JavaScript implementation with no jQuery usage.

## [2.0.0-rc4] — 2026-08-24

### Changed
- Move the Appearance → Themes **Download** action to the installed-theme overview cards for one-click access; Theme Details is intentionally left untouched.
- Replace the rc3 Theme Details adapter with a small vanilla-JavaScript overview adapter; no jQuery dependency is introduced.
- Resolve current WordPress theme-card identities from core's existing `{stylesheet}-action` / `{stylesheet}-name` element ids, with `data-slug` as a compatibility fallback.
- Keep Download actions idempotent across WordPress theme filtering/re-rendering and stop button clicks from bubbling into card-level interactions.

## [2.0.0-rc3] — 2026-08-24

### Changed
- Limit the Appearance → Themes **Download** action to the native **Theme Details** dialog; normal installed-theme cards remain unchanged.
- Make Theme Details injection resilient to WordPress reusing the same overlay while navigating between themes by tracking the currently injected theme slug on the Download link itself.
- Append the secondary Download action to the existing Theme Details action row rather than altering the normal theme-card controls.

## [2.0.0-rc2] — 2026-08-24

### Fixed
- Attempt to restore the **Download** action on Appearance → Themes by broadening theme-card stylesheet resolution beyond the rc1 `data-slug` path.
- Resolve theme stylesheet slugs from WordPress core's existing theme identifiers instead of assuming a custom `data-slug` attribute.
- Add the **Download** action to the dynamically rendered Theme Details overlay as well as the normal theme card.
- Observe the full theme browser so download actions survive WordPress theme filtering and Theme Details re-rendering.

## [2.0.0-rc1] — 2026-08-24

### Added
- Add first-party **Package Downloads** support for installed WordPress plugins and themes, replacing the external Download Plugin utility for the Core Blueprint baseline.
- Add a **Download** action to the native Plugins list for users with `install_plugins`.
- Add a vanilla-JavaScript **Download** button to installed theme cards for users with `switch_themes`; no jQuery dependency is introduced.
- Build plugin and theme ZIP archives in WordPress temporary storage and remove them immediately after streaming; package source directories are never used for temporary archives.
- Support both normal directory plugins and WordPress single-file plugins. Single-file plugins are wrapped in a matching root folder inside the ZIP without creating a temporary directory in `WP_PLUGIN_DIR`.
- Validate requested packages against WordPress' installed-plugin/theme registries and enforce package-root path boundaries before archiving.
- Skip filesystem symlinks in both archive paths so package downloads cannot follow linked files outside the validated package root.
- Add per-package nonces, native WordPress capability checks, no-cache/download headers, and audit-log events for successful and failed package downloads.
- Add **Core Blueprint → Package Downloads** as a lightweight overview and expose it in Dashboard → CMS Tools.
- Use PHP `ZipArchive` when available with the WordPress-bundled PclZip implementation as compatibility fallback.

### Release candidate
- Start the Core Blueprint 2.0 release-candidate line. Production v2.0.0 remains gated on full plugin-suite review, runtime validation, and external developer/quality audit.

## [1.9.4] — 2026-08-24

### Improved
- Media Replace now shows an immediate local preview of a selected replacement image before upload, allowing the current and replacement images to be compared side by side.
- Replacement previews use a browser object URL only; the selected file is not uploaded until **Replace file** is explicitly submitted.

## [1.9.3] — 2026-08-24

### Fixed
- Media Replace admin previews now use a persistent cache revision after same-URL replacements, including Media Library AJAX requests.
- Added a unique per-replacement revision token so repeated replacements cannot reuse a stale full-size browser cache entry.
- Existing replacements from v1.9.0-v1.9.2 remain cache-safe through the recorded replacement timestamp fallback.

## [1.9.2] — 2026-08-24

### Fixed

- **Media Replace:** fixed a PHP 8 `TypeError` during WordPress image sub-size regeneration when an image editor calls `image_editor_output_format` with nullable filename/MIME arguments (notably the GD sub-size path). Replacement now follows WordPress' nullable image-editor contract safely.
- **Media Replace diagnostics:** unexpected replacement failures now record the wrapped exception class and message in the Core Blueprint Audit Log while keeping the public admin notice non-sensitive.

## [1.9.1] — 2026-08-24

### Media Replace admin integration

- Promote **Media Replace** to a first-party `Core Blueprint → Media Replace` page registered through the central `PageRegistry`.
- Remove the hidden `Media` submenu registration that could leave WordPress without a valid admin page title and trigger `strip_tags(null)` deprecation notices on PHP 8.4.
- Route attachment replacement links through the Core Blueprint admin page while keeping Media Library row/modal/edit-screen entry points intact.
- Use the standard Core Blueprint admin asset/theme pipeline, including canvas dark/light theming and the existing shared components.
- Add a lightweight Media Replace module overview showing active status, current preserve-filename strategy, capability, future rename/reference-update direction, and a Media Library shortcut.
- Add a dedicated **CMS Tools** section to the Core Blueprint Dashboard and surface User Roles + Media Replace there as first-party CMS baseline modules.
- Style the native file-selector button with Core Blueprint theme tokens.

## [1.9.0] — 2026-08-24

### Media Replace

- Add native **Media Replace** actions to the Media Library list, attachment modal compatibility fields, and attachment edit screen.
- Add the `cb_replace_media` primitive capability, exposed through the existing User Roles capability catalog; CB Operator owns it as a required system capability and other roles can receive it through User Roles.
- Replace attachment files transactionally: validate and stage the upload, serialize concurrent replacements with a filesystem lock, SHA-256 verify temporary backups, swap the source, regenerate WordPress attachment metadata/sub-sizes, and restore the original files/metadata on failure.
- Preserve attachment ID, filename, public URL, and upload location in the initial replacement strategy.
- Enforce the preserve-filename invariant during metadata regeneration: suppress full-image scaling/conversion, normalize JPEG EXIF orientation in-place, and block WordPress from promoting `-scaled`/`-rotated` siblings while leaving normal derivative-format filters available for sub-sizes.
- Remove only derivative files explicitly recorded in the attachment metadata before regeneration, avoiding broad filename-pattern deletion.
- Add one-request admin preview cache-busting after replacement while leaving stored/public URLs unchanged.
- Record successful and failed replacement events in the existing Core Blueprint audit log and store last-replaced time/user on the attachment.
- Introduce a replace-strategy contract with `PreserveFilenameStrategy`; a future use-new-filename strategy can select a different target and pair with reference updating without rewriting the transactional file layer.
- Retain a private verified recovery backup if rollback itself cannot fully restore the original files.
- No telemetry, remote services, third-party libraries, upsells, or compatibility shims are introduced.

## [1.8.1] — 2026-08-23

### User Roles integration safety

- Add the `cb_core_role_delete_reasons` extension point so sibling plugins can protect roles that are still referenced by external configuration.
- External deletion reasons are merged with Core Blueprint's own protected-role rules; integrations cannot accidentally remove the built-in safety reasons.
- Existing User Roles UI and repository deletion checks automatically consume the added reasons through `RolePolicy::role_state()`.

## [1.8.0] — 2026-08-23

### User Roles

- Add **Core Blueprint → User Roles** as a first-party role and capability editor inside the existing Permissions subsystem.
- Add `cb_manage_roles` as an operator capability; administrators do not receive it automatically.
- Add `RoleRepository`, `RolePolicy`, and `CapabilityCatalog` layers so WordPress remains the source of truth while Core Blueprint owns safety policy and capability metadata.
- Protect Administrator, CB Operator, built-in/default roles, required system capabilities, and roles that still have assigned users from unsafe mutations.
- Prevent operator-only accounts from granting capabilities they do not hold themselves.
- Add REST-backed role CRUD, capability search/grouping, dynamic-policy indicators, and audit events for role-definition changes.
- Add additive user-role assignments on native WordPress user profiles: one base role plus zero or more additional roles.
- Preserve additional roles when the native WordPress base-role field is saved, preventing `WP_User::set_role()` from silently wiping multi-role assignments.
- Keep CB Operator assignment behind the existing `cb_manage_permissions` governance boundary while allowing trusted Administrators to assign application-specific roles such as Academy or client membership roles.
- Add `cb_core_base_role` user metadata as a stable base-role designation with lazy migration for existing multi-role users.
- Clean up the `cb_operator` role, Core Blueprint-owned capabilities, and `cb_core_base_role` metadata during uninstall.

## [1.7.0] — 2025-08-18


### ⚠️ Security

- **Critical**: Fix X-Forwarded-For trust issue to prevent IP spoofing in audit logs by adding trusted proxy validation for Cloudflare and standard reverse proxy headers. Only trusts proxy headers when the request originates from a trusted IP range (localhost, private networks).
- **Critical**: Use prepared statements for DROP TABLE in uninstall.php to prevent SQL injection vulnerabilities.
- **High**: Strengthen HTTPS enforcement in REST API by removing WP_DEBUG bypass, ensuring all Beacon endpoints require HTTPS unless explicitly allowed via `CB_CORE_ALLOW_INSECURE_REST` constant.

### ↑ Performance

- Add object caching for `Settings::get()` to reduce database queries on repeated requests.

### ⚙ Code Quality

- Add `declare(strict_types=1)` to all PHP files in `src/` for better type safety and error detection.
- Bump WordPress requirement from 6.7 to **7.0** for latest compatibility and API support.

### `.cb-core-section-title` follow-up: dashboard + section-context templates

Two adjustments based on cntrl.infused.nl validation:

**Margin tweak.** `.cb-core-section-title` was `margin: var(--cb-space-6) 0 var(--cb-space-4)` — `32px` top + `16px` bottom. The top margin compounded with the page's natural top spacing (page header + intro paragraph already provide breathing room), so the first section ended up far below the page intro. Now `margin: 0 0 var(--cb-space-4)` — let surrounding context provide the top space, the class only owns the bottom margin between heading and content. The first-of-type rule kept (now redundant since base is already 0, retained for clarity if base ever changes).

**Templates that needed the class but didn't get it.** The previous round (audit at `<h2 class=...>` time) only covered `src/` PHP files; six raw `<h2>` elements inside `<section class="cb-core-section">` blocks living in `templates/` didn't get migrated and so lost their underline:

- `templates/dashboard.php` — Safeguards / Operations / Preferences / Extensions
- `templates/maintenance-report.php` — Last 30 days
- `templates/core-shield.php` — Security modules
- `templates/appearance.php` — How resolution works
- `templates/language.php` — Interface language / Description style

All migrated. The 23 `<h2>` instances that sit inside `cb-core-panel` containers were intentionally NOT migrated — they get their underline from `.cb-core-panel > h2` (different selector, different border colour `--cb-surface-2` to match panel chrome). The standalone bypass-token `<h2>` in `templates/failsafe.php` L49 also stays without the class — it's an inline alert block, not a page section.

**Files changed:**
- `assets/css/layout.css` — `.cb-core-section-title` margin tweak
- `templates/dashboard.php` (4 instances)
- `templates/maintenance-report.php`
- `templates/core-shield.php`
- `templates/appearance.php`
- `templates/language.php` (2 instances)

### Panel sub-section refinements

Two small adjustments to `cb-core-panel` to tighten the visual rhythm inside panels.

**Panel `<h2>` gets bottom margin matching its bottom padding.** Previously `.cb-core-panel > h2` had `padding-bottom: var(--cb-space-3)` (border line) but no `margin-bottom`, which made the heading's bottom border sit visually too close to the first content row below it. Adding `margin-bottom: var(--cb-space-3)` so the gap above and below the divider line matches gives a balanced "header strip" appearance — Chris's call.

**Panel tables sit within panel padding instead of full-bleed.** `.cb-core-panel > table.widefat` (and the five other named-class table selectors) used negative margin to pull the table edge-to-edge with the panel border:
```
margin: var(--cb-space-3) calc( -1 * var(--cb-space-5) ) var(--cb-space-4);
width: calc( 100% + 2 * var(--cb-space-5) );
```
This was visually neat for full-bleed tables but crowded the table rows against the panel's outer edge on every page that had inline content alongside (Permissions Operators, Privacy verbosity, Failsafe Layers). Now:
```
margin: var(--cb-space-3) 0 var(--cb-space-4);
width: 100%;
```
The `:last-child` variant similarly drops the negative bottom-pull and bottom-border so the table sits flush with the panel's inner bottom padding. Tables now read as a regular panel child with the same horizontal padding as paragraphs and form-table rows above/below them.

**Files changed:**
- `assets/css/components/panels.css` — h2 margin-bottom added; table pull-out margins removed (base + last-child variants)

### HUD fallback tokens — render correctly on every screen

The HUD relied on `tokens.css` declarations scoped under `html[data-cb-theme="..."]`. The `Themes::emit_prepaint_hooks()` routine that sets that attribute only fires on `on_cb_admin_screen()` — so on the WP frontend and on third-party admin pages (Wordfence, LiteSpeed, etc.) the attribute was never applied, all `--cb-*` tokens resolved to nothing, and the HUD rendered transparent / unstyled.

**New file:** `assets/css/components/hud-fallback-tokens.css` — declares dark-theme token values directly on `.cb-hud, .cb-hub-toggle` selectors. Custom properties inherit, so the fallback cascades into every HUD descendant. On CB Base admin pages the proper `html[data-cb-theme]`-scoped declarations still win (same property declared at higher specificity wins the cascade), so light theme keeps working there.

**Why dark-only fallbacks:** the canvas/light-mode flip routine doesn't fire on non-CB screens anyway, and the HUD is a dark UI by default. Light-theme operators on CB Base admin pages get their light tokens via the proper scoped route (unchanged).

**Files changed:**
- `assets/css/components/hud-fallback-tokens.css` (new) — HUD-scoped token fallbacks
- `src/HUD/Assets.php` — enqueues `cb-core-css-hud-fallback-tokens` before `cb-core-css-hud`; adds it as a dependency on the HUD style handle so cascade order is deterministic

### `.cb-core-wrap h2` — opt-in via `.cb-core-section-title`

The Suite-wide `.cb-core-wrap h2` selector applied a heavy section-heading treatment (`32px` top margin, `16px` bottom margin, `8px` padding-bottom, `1px` border-bottom, uppercase, `0.05em` letter-spacing) to every `<h2>` descendant of any CB page wrap. Worked for top-level page sections; bled into module-card titles inside dashboard grids — making cards feel taller and headings feel heavier than the design intended (visible on Hub Dashboard, where every grid card carried this loud treatment via `<h2 class="cb-hub-section-title">`).

The selector is now opt-in via class:
- `.cb-core-wrap h2` keeps the typography baseline (font-size, weight, line-height, color) but resets margin / padding / border to zero
- `.cb-core-wrap h2.cb-core-section-title` (or `.cb-core-wrap .cb-core-section-title`) gets the loud treatment — used by top-level page sections that want to declare themselves
- `.cb-core-wrap .cb-core-section:first-of-type` first-child margin reduction now also keys on the class, so it only kicks in for opt-in titles

**CB Base raw `<h2>` instances migrated to opt-in:**
- `src/Admin/Pages/Logs/Tabs/RetentionTab.php` (2 instances — "Current retention rules", "Prune schedule")
- `src/Integrity/Admin/Page.php` (8 instances — "Scan History", "No active scan result", "Changes Since Last Scan", "Components", "Findings", "Verified / Passed Checks", "What this scan does", "What this scan does not do")
- `src/Notes/Admin/PreferencesPage.php` (1 instance — "Note modal defaults")

The `<h2 id="cb-core-integrity-locale-heading">` in the integrity locale-panel does NOT get the class — it sits in its own `cb-core-integrity-panel-head` block with its own styling, not a top-level page section.

**Hub side:** `cb-hub-section-title` keeps its own compact module-title styling (`sites-table.css`). Hub Dashboard cards stop receiving the loud `.cb-core-wrap h2` treatment that was inflating their height — the perceived "lompe" extra space is gone.

**Modal titles, card titles, console titles** — all already had their own classes (`cb-core-card__title`, `cb-console__form-title`, etc.), so they were already cascading over the implicit catch-all. They keep working unchanged.

**Files changed:**
- `assets/css/layout.css` — selector rewrite + first-of-type rule re-keyed on the class
- `src/Admin/Pages/Logs/Tabs/RetentionTab.php`
- `src/Integrity/Admin/Page.php`
- `src/Notes/Admin/PreferencesPage.php`

### HUD refactor — Phase 2 / M4 follow-up: UX rhythm pass

Direct response to a visual review on cntrl.infused.nl. Five concrete changes that together turn the panel from "many competing elements" into "single quiet flow".

**1. Section-headers desensibilised.** The accent-tinted banner on expanded section-headers is removed entirely. CONTENT, SITE, CORE BLUEPRINT no longer sit under a loud blue block — only the caret rotation + accent colour signal expansion now. Quick Actions' plain title now reads consistently with the others; the visual rhythm across all four sections matches.

**2. Single horizontal padding system.** Header, status-strip, sections all sit on `0.875rem` (14px) horizontal padding. Items, section-titles, stat labels line up on a single vertical guide line down the panel — the eye finds rest because every left-edge anchors to the same column.

**3. Vertical rhythm.** Items within a section pack tighter (1px gap); sections themselves breathe more (0.75rem top, 0.5rem bottom padding). The `border-top` between sections is removed — whitespace alone separates them now, less visual noise. Section-title margin-bottom unified at 0.375rem.

**4. Stats-strip simplified.** Vertical separators between stat-pills are gone. The subtle accent-tinted background stays as a visual marker that this row is system status, but the divider lines that competed with the section borders below are removed. `gap` between pills now does the spacing, not borders.

**5. Ghost-toggle moved to header-actions slot.** The full-width ghost-toggle row with switch + drag-hint is removed from below the status-strip. Replaced by a compact ghost icon next to the theme-toggle in the header-actions slot. State reflects via `is-active` class — accent-tinted background when ghost mode is on, neutral when off. Same `setGhostMode()` flow underneath, so all persistence (localStorage + REST) stays consistent. On-demand toggleable as Chris asked: stays prominently reachable when the floating button is in the way, but takes near-zero panel real-estate when not in use.

**Generic active-state contract for header-actions.** Any future stateful action button (partner extensions, e.g. "mute notifications") gets the same visual treatment by including a `data-{id}-state` field set to `on`/`active`/`true` in the action's `data_attrs`. The renderer auto-applies `is-active` class; partners get the styled state for free.

**Files changed:**
- `src/HUD/HUD.php` — old `cb-hud__prefs` ghost-toggle markup block removed entirely
- `src/HUD/HeaderActions.php` — ghost-toggle registered as second default action; generic state-reflection contract via `*-state` data-attrs; ghost SVG icon
- `assets/js/features/hud.js` — `[data-cb-hud-ghost-action]` button hooks into existing `setGhostMode()` flow; legacy `[data-cb-hud-ghost-toggle]` checkbox handler kept for backwards compat
- `assets/css/components/hud.css` — section-header expanded state stripped of accent-tinted bg; padding system unified at 0.875rem; vertical rhythm rebalanced; stats-strip separators removed; legacy `cb-hud__prefs` / `cb-hud__ghost-toggle` / `cb-hud__switch` / `cb-hud__hint` rules deleted; `is-active` state-treatment added for header-actions

### HUD refactor — Phase 2 / M4: density pass + Display section cleanup + Quick Actions polish

Final visual pass on the HUD. Three things land together: the Display section is removed from the render pipeline (its UI is gone, the section ID stays reserved for white-label later), spacing across every region tightens by ~25% vertically, and Quick Actions items get a distinct tinted treatment so verbs read as verbs rather than nav targets.

**Display section removed from render.** `HUD::render()` no longer calls `render_display_section()`. The brand-picker + theme-card UI that lived there is replaced by the header theme-toggle (M1). The section ID `display` stays declared in `Registry::register_default_sections()` so when white-label lands in 1.8.0+ a brand-picker can re-register itself via `cb_hud_register_items` without restructuring the renderer. The skip in the section loop alongside `'status'` keeps the empty section from appearing in the regular flow. The private `render_display_section()` and `render_display_row()` methods stay on the class for potential reference but aren't reachable from any public path.

**Density pass.** Vertical space tightens across the HUD:
- Section padding: 0.5rem → 0.375rem top, 0.375rem → 0.25rem bottom
- Section title margin-bottom: 0.375rem → 0.25rem
- Section title font-size: 0.6875rem → 0.625rem
- Status-strip pill padding: 0.25rem → 0.125rem
- Status-strip value font: 0.8125rem → 0.75rem
- Prefs row padding: 0.5rem → 0.375rem
- Prefs row font: 0.6875rem → 0.625rem

Net effect: a default operator-shaped HUD fits more content above the fold without scrolling, while every individual element still has breathing room. No layout shifts — only padding, margin, and font-size tweaks.

**Quick Actions visual treatment.** Items in the `quick-actions` section render with a subtle accent-tinted background (`color-mix` 6% accent over surface), accent-coloured icons, and slightly heavier label weight. Hover lifts the tint to 14%. The result reads as "these are actions you can take" vs the neutral nav-target rows in CONTENT / SITE / CORE BLUEPRINT. No structural change — the section is still a regular section per CP-11; the styling lives in `[data-section="quick-actions"]` selectors so it's easy to override and doesn't bleed into other sections.

**Prefs row (ghost-toggle) tone-down.** Marked subtler with reduced opacity (0.85), smaller font, and tighter padding — clear signal that this is transitional UI bound for the Preferences → HUD admin tab when Phase 3 lands. Functionality unchanged: ghost mode still toggles, drag hint still surfaces.

**Files changed:**
- `src/HUD/HUD.php` — `render_display_section()` call removed from main render pipeline; the section-loop skip keeps `display` and `status` out of the regular flow
- `assets/css/components/hud.css` — density pass on header, status-strip, prefs row, sections; Quick Actions section gets accent-tinted item treatment

### HUD refactor — Phase 2 / M3: 2-column grid + section-visible filter

Nav-sections (Content, Site, Core Blueprint) lay out in two columns at the default panel width. Short labels like Posts, Pages, Plugins, Themes, Notes pack densely; the panel uses vertical real-estate more efficiently. Quick Actions stays one column — its verb labels (Run integrity scan, Generate report) are too long for two columns.

**Section schema integration.** The `columns` field set on `register_section()` (Phase 1) is now read by the renderer. Sections with `columns: 2` get a CSS-grid layout (`cb-hud__section-content--cols-2`); sections without keep the existing flex-column layout. The default register set:
- Content / Site / Core Blueprint → columns: 2
- Quick Actions / Display / status / legacy sections → columns: 1

**Container-query responsive collapse.** The HUD panel declares `container-type: inline-size` and `container-name: cb-hud-panel`. 2-col sections collapse back to one column under 17rem (~272px) panel width via `@container cb-hud-panel (max-width: 17rem)`. The breakpoint is calibrated to the default 22.5rem (~360px) panel — 2-col is comfortable there with ~165px per column, plenty for short labels with icon + status-dot. When the HUD is docked into a narrower container (third-party admin layout with sidebars at <300px) the 2-col falls back automatically without a viewport-size dependency.

**`cb_hud_section_visible_{id}` filter (new public hook).** Runs at the start of each section's render. Return false to hide the entire section regardless of how many items it contains. Use for runtime visibility (maintenance mode, time-of-day, license state, feature flags) the registration-time capability + module gates can't express. The filter also receives the section definition and the resolved items list so callbacks can make state-aware decisions.

**Built-in items in CB-core section had three icon gaps closed:**
- Preferences → `admin-generic`
- CLI → `editor-code`
- Console → `arrow-right-alt2`

These were missed in Phase 1 because they're registered in their own subsystem `Bootstrap.php` (Preferences/Bootstrap, CLI/Bootstrap, Console/Bootstrap) rather than the central `Registry::register_builtin_items()`. Hub items still don't carry icons — Hub stays legacy-compatible in 1.7.0 as agreed; it migrates to the new schema fields in its own next release.

**Files changed:**
- `src/HUD/HUD.php` — `render_section()` reads `columns`, applies `cb_hud_section_visible_{id}` filter, emits `--cols-2` modifier class + `data-columns` attribute
- `src/Preferences/Bootstrap.php`, `src/CLI/Bootstrap.php`, `src/Console/Bootstrap.php` — icons added on existing HUD item registrations
- `assets/css/components/hud.css` — panel marked as named container, `.cb-hud__section-content--cols-2` grid layout, container-query breakpoint at 17rem

### HUD refactor — Phase 2 / M2: item render with icons + status-dots + filters

Items in HUD sections now render their full visual contract — the data laid down in Phase 1 finally surfaces. Built-in items get dashicons before their label, status-dots after, descriptions stay accessible via title attribute without crowding the row.

**Icons.** Items with an `icon` field render a dashicon before the label (`<span class="cb-hud__link-icon dashicons dashicons-{icon}">`). Dashicons font is enqueued as a HUD style dependency so icons render on frontend pages too, not just inside admin where dashicons auto-loads. Color is `--cb-text-muted` by default; inherits the accent on hover/focus so the hovered row stays visually coherent.

**Status-dots.** Items with a `status_filter` field render a colored dot right of the label. State maps to color via tokens — `--cb-success` (ok), `--cb-warning` (warn), `--cb-danger` (err), muted (off). The dot exists alongside an `aria-hidden="true"` decorative span and a `screen-reader-text` span so assistive tech receives the full state + detail line. Dot resolution is defensive at every step: a partner returning a malformed shape from the filter results in no dot rather than a broken row.

**Descriptions hidden by default.** The `cb-hud__link-desc` span renders into the markup but is `display: none` by default. The link's `title` attribute carries the description (or status detail when no description is set) so accessible technology + native browser tooltips still surface the text. Density of the row stays predictable — short labels pack densely without descriptions pushing rows taller. M4 may revisit with a hover-reveal pattern if telemetry shows operators want it back.

**Two new public hooks for partner integration:**

- `cb_hud_item_args` — applied to every item just before render. Receives the full item array, returns a possibly-modified array. Use for cheap label tweaks, conditional icon swaps, runtime overrides. Runs in the inner render loop, so callbacks must stay cheap; heavy operations (DB queries, remote calls) need to be wrapped in transients at the call site.
- `cb_hud_item_{id}_args` — same but scoped to a single item id. Cheaper than the universal filter when the partner only cares about one item — no need to pattern-match on id inside the callback. The id-scoped filter runs *after* the universal one so per-item filters can override universal changes.

Both filters are part of the public Extension API contract — backwards-compatible across minor versions, documented in `docs/HUD_EXTENSION.md` (landing in M4 alongside the example reference plugin).

**Visible result on built-in items (per the Phase 1 work):**

- CONTENT — Posts, Pages, Media, Comments now show admin-post / admin-page / admin-media / admin-comments dashicons.
- SITE — Plugins, Themes, Users, Tools, Settings show admin-plugins / admin-appearance / admin-users / admin-tools / admin-settings dashicons.
- CORE BLUEPRINT — CB Dashboard (dashboard icon), Logs (list-view), Notes (admin-page), Reports (chart-bar), Safeguards (shield-alt). Logs / Notes / Reports also display their status-dot from the Phase 1 status contributors. Safeguards stays icon-only for now — the aggregate status_filter (worst-state across the six safeguards modules) lands later when an aggregator contributor is added.
- QUICK ACTIONS — Run integrity scan (controls-play), Add note (plus-alt2), Generate report (media-document).

**Files changed:**
- `src/HUD/HUD.php` — `render_item()` refactored: filter chain at entry, icon + status-dot blocks for link items, descriptions hidden behind title attribute, row classes for `--has-icon` / `--has-status` / `--has-action` modifiers
- `src/HUD/Assets.php` — dashicons added to HUD style dependency chain
- `assets/css/components/hud.css` — link layout flipped from column-stack to row-flex (icon-label-dot inline), `.cb-hud__link-icon` / `.cb-hud__link-dot--{state}` classes, descriptions display:none by default

### HUD refactor — Phase 2 / M1: header refactor + cb_hud_header_actions

The HUD header collapses to one effective row of controls beside the avatar/name + mode-pills. New `cb-hud__header-actions` slot sits between the mode-pills and the fixed ⚙ Preferences icon; the `× Close` button stays at the far right.

**Theme-toggle in header.** A single moon/sun button replaces the Display-section's segmented light/dark control as the primary theme switcher. State is reflected by the icon: moon when light theme is active (clicking goes to dark), sun when dark theme is active (clicking goes to light). The icon swap happens client-side after `selectTheme()` returns; falls back to the existing 300ms cross-fade transition + REST sync via `Themes::set_user()` so brand/theme persistence works exactly as before.

**⚙ Preferences icon in header.** Quick-access entry for operators. Capability-gated on `cb_view_permissions` so admins without operator caps don't see it (their HUD has no Preferences page to link to anyway). Hits the existing Preferences page directly — no new route or page.

**`cb_hud_header_actions` filter (new public hook).** Partner plugins extend the header by hooking this filter and returning a modified actions array. Each entry is a shape:

- `id` (string, required) — kebab-case identifier
- `label` (string, required) — aria-label and tooltip
- `icon_svg` (string, required) — inline SVG markup
- `url` (string, optional) — when set, renders as `<a href>`
- `data_attrs` (array, optional) — `data-*` attributes for JS hooks (without the `data-` prefix)
- `capability` (string, optional) — `current_user_can` gate at render-time
- `order` (int, default 10) — lower renders first; theme-toggle uses 10 so partners get clear before/after slots

Theme-toggle is registered into this filter list as a default entry with id `cb-theme-toggle`. Filter callbacks receive the full list and may modify, replace, or remove entries — partners that want to swap the theme-toggle for a combined brand+theme picker (eventual white-label) replace it by id. Capability-gating runs after the filter so partners can register actions without first checking caps themselves.

**Files changed:**
- `src/HUD/HeaderActions.php` (new) — filter resolution, render method, default theme-toggle, moon/sun SVGs
- `src/HUD/HUD.php` — header markup adds HeaderActions render slot + Preferences icon
- `assets/js/features/hud.js` — `[data-cb-hud-theme-toggle]` click handler + `refreshThemeToggleIcon()` for client-side icon swap
- `assets/css/components/hud.css` — header switched from CSS-grid to flex-row to accommodate the new actions slot; new `.cb-hud__header-action` button class shared by every header button (theme-toggle, Preferences, future partner buttons)

The legacy ghost-toggle / hint block (`.cb-hud__prefs`) and the Display section's brand+theme-card UI are still rendered as before — those are M4 cleanup once the Preferences → HUD admin tab is in place to host the ghost-button preference. M1 is purely additive on the header itself.

### HUD refactor — Phase 1: schema, resolvers, status pipeline

Foundation work for the HUD redesign. Phase 1 lands the data layer; the renderer changes that turn it into something visible follow in Phase 2 (next release of the 1.7.x track). After Phase 1 the HUD looks identical to 1.6.x — every change is wiring beneath the rendered surface, ready for the visual pass to consume it.

**Schema extensions on `Registry::add_item()`** — three new optional fields:
- `icon` — dashicon ID without the `dashicons-` prefix; renderer (Phase 2) draws it before the label.
- `module` — module slug for visibility-gating (`notes`, `reports`, `core_scanner`, `beacon`). When set, the new `Modules\Resolver::is_enabled()` decides at registration time whether the item survives the visibility chain. Items without `module` are unconditionally registered (Logs and Failsafe deliberately have no `module` field — they cannot be disabled by design).
- `status_filter` — name of a WordPress filter that returns the item's current `{ state, detail, url, label }` shape. Renderer (Phase 2) uses it to draw a colored dot beside the label. The value `state` must be one of `ok | warn | err | off`.

**Schema extension on `Registry::register_section()`** — one new optional parameter:
- `columns` (1 or 2, default 1) — layout columns at tablet width and up. Other values fall back to 1 with a `_doing_it_wrong()` notice; we deliberately don't support 3-col / auto / grid / masonry. Nav-sections (`cb-content`, `cb-site`, `cb-core`) now declare `columns: 2` so the Phase 2 renderer can pack short labels (Posts, Pages, Plugins, Notes) more densely. Quick Actions stays at `columns: 1` — verb labels are longer and would truncate awkwardly.

**Quick Actions = regular section.** The `pinned` synthesis layer that earlier drafts proposed is dropped entirely. Modules register their own action items into the `quick-actions` section the same way they register any nav item:
- Integrity → `cb-hud-quick-run-scan` (Run integrity scan)
- Notes → `cb-hud-quick-add-note` (Add note)
- Reports → `cb-hud-quick-generate-report` (Generate report)

A soft limit guards the section: registering a 5th item or beyond emits a `_doing_it_wrong()` notice. The intent is 3-4 verbs; growth past that is the slippery slope to the icon-grid we already decided against. Items still register, the notice is informational.

**`Modules\Resolver` (new class).** Central `is_enabled(slug)` dispatcher with a built-in map covering Notes, Reports, Beacon, and Core Scanner. Sibling plugins extend via the `cb_module_is_enabled_{slug}` filter — return a callable yielding a bool. Unknown slugs default to enabled (backward-compat). Resolver failures fail-open and log via `error_log()` rather than hide an item the operator can't find.

**`Modules\Status` (new class).** Generalised status pipeline lifted from `Safeguards\Status`. Same defensive contract (every failure mode degrades gracefully — never throws), broader `MODULES` constant covering the Safeguards-six plus `logs`, `notes`, `reports`, `hub`. Filter contract: `cb_core_module_status_{slug}` returns `{ state, detail, url, label }`. For the Safeguards-six the legacy `cb_core_safeguard_status_{slug}` filter is consulted as a fallback chain so existing contributors keep working without coordination. Every safeguard module can migrate to the new filter at its own pace.

**`Safeguards\Status` becomes a delegating wrapper.** Public surface unchanged (`STATES`, `MODULES`, `all()`, `get()`, `dot_class()`, `label()`). Dashboard tile renderer, Hub plugin, and any other consumer keep compiling. Internally every method delegates to `Modules\Status` — single source of truth, zero breaking change.

**Status contributors registered for Logs / Notes / Reports.** New `Status` classes in each subsystem hook the corresponding `cb_core_module_status_*` filter:
- `Log\Status` returns `ok` with "Logging active". Logs cannot be disabled (#TamperProof), so `off` is never returned.
- `Notes\Status` returns `ok` when enabled, defensive `off` otherwise.
- `Reports\Status` returns `ok` when enabled, defensive `off` otherwise.

Wired in their respective `Bootstrap.php` via `Status::boot()`. Phase 1 implementations are minimal — `warn` and `err` states will be added as we land telemetry that justifies finer signal.

**Capability audit on built-in HUD items.** Every `cb-hud-wp-*` and `cb-hud-cb-*` item reviewed; capabilities tightened where appropriate. Icons added across Posts, Pages, Media, Comments, Plugins, Themes, Users, Tools, Settings, CB Dashboard, Logs, Notes, Reports, Safeguards. The existing `module` / `status_filter` integrations sit alongside the existing `capability` gate — the resolution order is now: capability → module → explicit-hide.

**HUD section structure.** Display section ID stays declared in `register_default_sections()` for white-label later (1.8.0+); no items registered for now. Quick Actions section explicitly registered with `columns: 1` and a sentence-case label ("Quick actions"). Legacy/utility sections (`integrations`, `wordpress`, `status`) keep their order numbers so partner items registered in earlier sessions don't clump at one end of the panel.

**Locked decisions for the rest of the 1.7.0 track:**
- Mode-pills per role: Operator P/T/S, Admin P/T, Editor none.
- Notes in Core Blueprint section, module-gated.
- Audit Scanner integrates via existing `cb_hud_register_items` into `cb-core` — no separate section in 1.7.0.
- Hub stays legacy-compatible — no mandatory migration.

### Migration notes

`Safeguards\Status` is now a thin delegator around `Modules\Status`. The public surface is identical, so existing consumers keep working without changes. New code targeting the broader module set (Logs, Notes, Reports, Hub) should call `Modules\Status::get()` directly.

Capability tightening on built-in HUD items is conservative — most items had correct caps already. If an item disappears from a role you expected to keep it, the role likely never had that capability and was relying on the default (which is now stricter). Add the necessary cap via your role-management plugin if the change is unintended.

The `pinned` field that was sketched in earlier 1.7.0 drafts is **not** part of the schema. Ignore any external draft documentation that mentions it. Quick Actions is a regular section now.

## [1.6.4-dev] — unreleased

### Notes — page header refactor + Actions dropdown

The Notes admin page gets a tighter header layout. The "NOTES OVERVIEW" panel below the page intro is removed — its `<h2>` and subtitle restated what the page intro already conveyed, costing visual space without adding information. The four secondary buttons that lived in that panel (Import JSON, Export all JSON, Notes preferences, Delete all notes) are folded into a single `Actions ▾` dropdown beside the primary `+ New Note` button, anchored to the top-right of the page header.

**Header structure:**

The intro (h1 + description) and the action cluster now sit on a single row inside `.cb-notes-page-header` — flex-row on desktop, stacking to column on viewports under 640px. Primary action (`+ New Note`) and dropdown trigger (`Actions ▾`) are visually adjacent so the operator's eye lands on the action they need most often without scanning past a separate panel.

**Actions dropdown:**

Module-scoped component, not extracted to a shared layer yet — rule of three: only Notes uses this pattern today. If Reports or another module needs an Actions dropdown later, we promote `.cb-notes-actions-menu` to a shared `cb-action-menu` component then. Markup is `<button data-cb-notes-actions-menu-trigger> + <ul role="menu" data-cb-notes-actions-menu-panel>` with menu items keyed off the same `data-cb-notes-*` attributes the existing handlers already match on — no handler rewrites, items just live in a different DOM container.

The Delete-all entry sits below a separator and uses danger-token styling (`var(--cb-danger)` text + `color-mix` background on hover). The existing typed-confirm modal flow ("type DELETE ALL NOTES") still gates the actual destructive call, so the dropdown placement is presentational only — no new opportunity for accidental triggering.

Toggle behavior: click trigger to open, click outside or press Escape to close, click any menu item to fire its action and close on the next tick (so toast/modal flows from the action handler aren't racing the menu teardown). `aria-haspopup="menu"`, `aria-expanded`, `aria-controls`, `role="menuitem"`, `role="separator"` all wired for screen-reader correctness. Keyboard focus returns to the trigger after Escape.

**CSS cleanup:**

Removed orphaned rules: `.cb-notes-toolbar` (panel wrapper), `.cb-notes-toolbar h2/p` (panel typography), `.cb-notes-toolbar__actions` (button row layout), and the two `@media (max-width: 900px)` blocks that rebuilt that panel for narrow viewports. Replaced with `.cb-notes-page-header` + `.cb-notes-actions-menu` component blocks. All styling stays on `--cb-*` tokens — `--cb-surface-1` for the panel, `--cb-border` for outline + separator, `--cb-accent-soft` for hover, `--cb-danger` for the destructive item. No hardcoded colors.

**Files**

Updated:
- `src/Notes/Admin/Page.php` — `.cb-core-panel cb-notes-toolbar` block replaced with `.cb-notes-page-header` flex-row containing the intro and the action cluster (`+ New Note` button + Actions dropdown markup with `<ul role="menu">` items)
- `assets/css/pages/notes.css` — removed orphaned `.cb-notes-toolbar*` rules and their mobile media-block; added `.cb-notes-page-header`, `.cb-notes-page-header__intro`, `.cb-notes-page-actions`, `.cb-notes-actions-menu` (relative wrapper), `.cb-notes-actions-menu__trigger` (with caret rotation on `aria-expanded="true"`), `.cb-notes-actions-menu__panel` (absolute, right-anchored, shadow via `color-mix`), `.cb-notes-actions-menu__item` (menu-item with dashicon), `.cb-notes-actions-menu__item--danger` (delete-all variant), `.cb-notes-actions-menu__separator`, plus mobile fallback under 640px
- `assets/js/features/notes.js` — `initActionsMenu()` function added (toggle, outside-click close, Escape close, focus return) and invoked at module init alongside `applyNotesLayout()`
- `languages/core-blueprint.pot` — version bump to 1.6.4-dev, creation date refreshed, removed `msgid "Notes overview"` and `msgid "Create, review and maintain operational notes from one compact overview."`
- `languages/core-blueprint-nl_NL.po` — same removal mirrored, project version + revision date refreshed
- `languages/core-blueprint-nl_NL.mo` — recompiled
- `core-blueprint.php` — version 1.6.4-dev (header + `CB_CORE_VERSION` constant)

### Migration

None. Data, schema, REST endpoints, and capability map are unchanged. Pure UI refactor of an existing admin page.


### Notes — design polish: separated card actions, lighter filter row, plural copy

Second pass on the Notes UI. Three smaller refinements toward the Core Blueprint 2.0 visual language — strakker, rustiger, modern zonder druk te worden.

**Card actions: from grouped to standalone icon buttons.**

The edit / duplicate / delete trio in each note card was wrapped in `.cb-action-group--compact`, rendering as a single bordered pill with internal dividers. That visual container fought with the badges next to the title and added a frame the actions didn't really need. The wrapper is gone. Each icon is now an independent button with its own subtle hover background. Edit and duplicate fade to the standard accent color on hover; delete fades to `var(--cb-danger)` with a `color-mix` background of 12% danger — explicit destructive signaling without dyeing the icon red at rest. The actions read as three separate things you can do, not as one indivisible widget.

A new module-scoped class `cb-notes-icon-btn` (with `--danger` modifier) handles the styling. The shared `cb-action-btn` + `cb-action-group` pair stays as it is — the view switcher still uses it legitimately for its mutually-exclusive list/grid-2/grid-3 toggle, where group-as-radio is the right pattern. Card actions are independent operations, so they get their own component. Clean separation: `cb-action-group` for "pick one of these", `cb-notes-icon-btn` for "do any of these".

**Filter row: panel frame removed.**

The filter row (Search / Status / Type / Assigned / Sort / Per page / Only Important) sat in a panel with the same `--cb-surface-1` background, `--cb-border` outline, and `--cb-radius-md` corners as the note cards below it. Two stacked panels with identical framing competing for attention. Frame stripped: filters are now a transparent flex row with vertical breathing room from `--cb-space-3` margins. Field-level styling (input borders, select chevrons) takes over as the visual structure, and the cards become the dominant frame on the page.

Note: the inputs inside this row still fall back to WP-admin defaults until the suite-wide form-controls extension lands in 1.6.5-dev. That release will theme `input[type="text|search|...]`, `<textarea>`, and `<select>` against `--cb-*` tokens across all CB modules — at which point the filter row gets its final dark/light-aware look without further changes here.

**"1 results" → proper plural.**

`<strong>%d</strong> results` was hardcoded with no plural form. With one match the page read "1 results" — small thing but loud once you see it. Replaced with `_n('%d result', '%d results', $total, 'core-blueprint')`. Dutch translation supplied: `%d resultaat` / `%d resultaten`.

**Files**

Updated:
- `src/Notes/Admin/Renderer.php` — `<span class="cb-action-group cb-action-group--compact cb-notes-card__icon-actions">` wrapper removed; three buttons (`cb-notes-icon-btn`, last one with `cb-notes-icon-btn--danger`) now sit as independent flex children with 2px gap. Results count rewritten with `_n()` + `printf` for proper singular/plural.
- `assets/css/pages/notes.css` — `.cb-notes-filters` panel-frame stripped (transparent background, no border, vertical-only margin); new `.cb-notes-icon-btn` block with hover/focus/active states; `.cb-notes-icon-btn--danger` variant; `.cb-notes-card__icon-actions` updated as flex container with gap.
- `languages/core-blueprint.pot` — `msgid "results"` replaced with `msgid_plural` block referencing the new `_n()` call site; creation date refreshed.
- `languages/core-blueprint-nl_NL.po` — same plural block with `%d resultaat` / `%d resultaten` translations; revision date refreshed.
- `languages/core-blueprint-nl_NL.mo` — recompiled.

### Migration

None. Visual-only changes on top of the existing Notes admin page. No data, schema, REST, capability, or behavior changes.


### Notes — bulk-bar dark/light theming, checkbox glow removal, delete-icon hover color, plural fixes

Visual polish items uncovered while testing the second pass. Three small fixes that, together, mean every interactive state on the Notes page now reads cleanly in both light and dark themes.

**Bulk-bar dark/light compatibility.**

The selection bar that appears when one or more notes are checked used `var(--cb-accent-soft)` as a full-fill background. In light mode that produced a soft cyan tint; in dark mode the same token was too bright against the dark page surface and forced the inner text into a low-contrast no-man's-land — also showed up as a hard-edged light slab clashing with the card surface below it. Replaced the fill with `color-mix(in srgb, var(--cb-accent) 10%, var(--cb-surface-1))` — the surface stays as the base, with a subtle 10% accent tint blended on top. In light mode that reads as a gentle accent wash; in dark mode it reads as a darker panel with a soft cyan glow. Tokens for text (`--cb-text`) and links (`--cb-accent`) explicitly set on the bar so they don't pick up unintended cascade colors. The drop-shadow now uses `color-mix` with `--cb-text-strong` instead of a hardcoded `rgba(0,0,0,...)` so it tracks both themes.

**Checkbox & radio: decorative glow removed + WP `::before` checkmark suppressed.**

`assets/css/components/form-controls.css` had two `box-shadow` rules — one on `:checked`, one boosted under `html[data-cb-theme="core_blueprint_dark"]` — that painted a 6-8px accent halo around every checked input. Three problems with that: (1) it duplicated the focus-ring's job; the dedicated `:focus-visible` outline already signals focus correctly, (2) it leaked beyond the box edge in a way that visually misaligned with the white check-mark sitting inside, (3) it added decoration that the suite's "rustig & strak" direction doesn't ask for. Both `box-shadow` rules removed. Checked state is now: accent fill, accent border, white check-mark, nothing more. Focus state is unchanged (2px accent outline with offset).

**Followup discovery:** the lingering "shadow over the check-mark" on the screenshots was actually WordPress core's own `input[type=checkbox]:checked::before` rule (in `wp-admin/css/forms.css`) drawing a second SVG check-mark via a CSS pseudo-element. Its hardcoded margins are sized for WP's default checkbox dimensions, not our 18px `appearance: none` box, so the pseudo-element landed off-center and read as a misaligned shadow over our background-image check-mark. Added an explicit `content: none` override on `:checked::before` and `:indeterminate::before` to suppress WP's pseudo-element entirely. Only our SVG remains.

**Followup #2 — focus-ring flash:** with the decorative glow gone, the legitimate `:focus-visible` accessibility ring (`outline: 2px solid var(--cb-accent); outline-offset: 2px`) became more noticeable on click. Modern browsers fire `:focus-visible` on input-focus regardless of whether it came from keyboard or mouse, and `outline` can't be transitioned reliably across browsers — so on a click the ring rendered instantly, then disappeared just as instantly on blur. Reads as a glitch even though it's functionally correct. Switched the focus indicator from `outline` to `box-shadow: 0 0 0 2px var(--cb-accent)`, picking up the baseline `transition: ... box-shadow 200ms ease` already on the input. The ring now fades in and fades out at the same 200ms rhythm as the checked-state transitions — same accessibility, calm visual.

**Delete-icon hover color.**

`.cb-notes-icon-btn--danger:hover` set `color: var(--cb-danger)` but the WordPress `button-link` cascade prevented that from reaching the dashicon glyph itself — the background-tint was red but the icon stayed muted-grey. Added `!important` on the hover color and a dedicated `.cb-notes-icon-btn--danger:hover .dashicons` rule so the icon glyph picks up the danger color too. Edit and duplicate icons stay neutral on hover (accent), only delete shifts to red — clear destructive signal without colorizing the rest of the row.

**"1 notes selected" plural fix.**

Same issue as the results count — hardcoded plural with no singular form. Trickier here because the count is updated client-side (JS-driven). Solution wired through the existing `script_module_data_@cb-core/notes` channel: server passes `i18n.noteSelected` and `i18n.notesSelected` template strings (both already translation-ready via `__()`), JS picks the right one based on count and substitutes via `replace('%d', count)`. Markup simplified from `<span data-cb-notes-selected-count>` + static label to a single `<strong data-cb-notes-selected-summary>` that JS rewrites entirely. Dutch translations added: `%d notitie geselecteerd.` / `%d notities geselecteerd.`

**Files**

Updated:
- `assets/css/pages/notes.css` — `.cb-notes-bulk-bar` rewritten: `color-mix` accent-on-surface background, explicit `color: var(--cb-text)`, `color-mix` shadow, `.cb-notes-bulk-bar a { color: var(--cb-accent); }` for the inline links; `.cb-notes-icon-btn--danger:hover/focus-visible` color forced with `!important` + dedicated dashicon override
- `assets/css/components/form-controls.css` — `box-shadow` on `input[type="checkbox"]:checked` and `input[type="radio"]:checked` removed; dark-mode `box-shadow` boost block (the `html[data-cb-theme="core_blueprint_dark"]` rule) removed in full
- `src/Notes/Admin/Renderer.php` — bulk-bar markup: count span + static label collapsed into one `<strong data-cb-notes-selected-summary>` with `_n()`-rendered initial text
- `src/Admin/Admin.php` — notes module `data` extended with `i18n.noteSelected` / `i18n.notesSelected` for client-side plural rendering
- `assets/js/features/notes.js` — `updateBulkBar()` now selects the right template from `config.i18n` and substitutes `%d`
- `languages/core-blueprint.pot` — orphan `msgid "notes selected."` replaced with `msgid_plural` block; creation date refreshed
- `languages/core-blueprint-nl_NL.po` — same plural block with Dutch translations; revision date refreshed
- `languages/core-blueprint-nl_NL.mo` — recompiled


## [1.6.3-dev] — unreleased

### CB Console — async-polling for `cb scan run`

The last placeholder command ships its real implementation. `cb scan run` now schedules a Core Scanner integrity scan via wp-cron from the Console and tracks progress live in the output panel until completion. The full CLI surface is now runnable from the browser.

**Run flow:**

1. Operator selects `cb scan run`, picks themself in the user-picker, clicks Run.
2. State-change banner shows. Click triggers a POST to `/console/run` which schedules the scan via `wp_schedule_single_event` + `spawn_cron`, primes the TransientProgressReporter, and returns immediately with `data.async = true, data.job_id = scan_xyz`.
3. Console JS detects `data.async`, switches to polling mode. Output panel renders the running banner, a spinner, the live phase line ("collecting checksums" → "verifying signatures" → …), and an elapsed-seconds counter that ticks every second.
4. Background polls every 1000ms against the new `/console/job-progress?job_id=…` endpoint. Phase changes re-render the phase line; elapsed counter ticks independently.
5. On `status === 'done'`, the response embeds the final scan result (status, completed_at, issue count) — no second roundtrip needed. Output renders via the standard Result path so structured-data viewer + banner styling are consistent with sync commands.
6. On `status === 'error'`, the error message renders in a red banner.
7. On `status === 'gone'` (transient TTL expired without reaching done — rare, but handled), the operator is told to check Logs and run `cb scan latest`.

**Stop button:**

A "Stop showing progress" button below the spinner aborts the JS poll loop. The backend scan continues — the progress transient keeps updating, the audit log still records completion. Refresh the page to resume tracking. This is intentional: it's a UI affordance, not a cancel-the-scan affordance. Cooperative cancel that actually stops the engine is on the parking list and would need a refactor of `ScannerEngine::run()` with a per-phase abort check.

### REST endpoint

```
GET /core-blueprint/v1/console/job-progress?job_id=<id>
```

Operator-only. Reads the TransientProgressReporter transient and normalises the response shape:

```
{
  status:       'pending' | 'running' | 'done' | 'error' | 'gone',
  phase:        string,            // current phase name
  started_at:   float|null,        // microtime(true) when scan began
  completed_at: float|null,
  error:        string|null,
  final_result: array|null,        // Result-shaped, only on status='done'
}
```

When `status === 'done'`, `final_result` carries the same shape an `execute()` Result would — `status`, `message`, `lines`, `data` — so the JS can pipe it straight into `renderOutput()`.

### Async pattern in Result

The `Result::data.async = true, data.job_id = …` convention is the contract for any future async command. Future commands that need long-running execution can adopt the same pattern: schedule via wp-cron, return a Result with the async flag, the JS picks it up and polls the job-progress endpoint. No new endpoints per command — one polling channel handles all of them.

For now `cb scan run` is the only command using this; nothing else fits the pattern yet (operator promote/demote, report generation, cleanup all complete in a single request).

### Files

Updated:
- `src/CLI/Commands/Scan/Run.php` — execute() schedules via wp-cron, returns async-shaped Result; resolve_user refactored to nullable for dual-use Console + CLI; __invoke() handles null resolve gracefully
- `src/Console/Rest/RunController.php` — `/console/job-progress` route + handler; embeds final_result on done
- `src/Admin/Admin.php` — async-poll i18n (`asyncScheduled`, `asyncPending`, `elapsedSeconds`, `stopProgress`, `asyncStopped`, `asyncDoneNoResult`, `asyncFailedGeneric`, `asyncGone`); `pendingPhase` simplified now that no command is pending
- `assets/js/features/console.js` — `asyncPollLoop()` with 1s tick + setInterval-driven elapsed counter; `renderAsyncRunning()` for live progress; `state.asyncPoll` abort handle; `performRun()` detects async and delegates
- `assets/css/pages/console.css` — `.cb-console__banner--running`, `.cb-console__async-progress` (spinner + phase + elapsed), CSS keyframe `cb-console-spin`
- `core-blueprint.php` — version 1.6.3-dev

### CB Console — feature-complete milestone

With this release the Console is functionally complete:

- 24 of 24 registered commands runnable
- Read-only commands: direct execution, structured-data viewer
- State-change commands: persistent banner-warning above Run
- Destructive commands: modal-confirm with explicit action lines + server-side confirm-token check + red Run button
- Sensitive output (rotate-token bypass URL): dedicated copy-to-clipboard modal, output panel shows placeholder
- Long-running commands (`cb scan run`): async-polling with live phase + elapsed counter + stop-tracking option

Sibling plugins extending `cb_console_register_commands` continue to work without changes — atomic CommandInterface contract is unchanged across 1.6.0-dev → 1.6.3-dev.

### Migration

No data migration. CLI users see no change. The `cb scan run` CLI invocation still works fully (terminal users get the unchanged `--wait` flag for blocking polling).

### Polish pass — quality + consistency cleanup

A polish round at the end of the 1.6.x track. No new features, no behavioural changes for end-users; everything below is internal hygiene against the Core Blueprint house rules. All fixes ship inside the 1.6.3-dev cycle (no version bump).

**PHP**

- 8 unused `use` statements removed (`Locale.php`, `Verbosity.php`, `Security/Module.php`, `Reports/Generator.php`, `Admin/Admin.php`, `Integrity/Rest/ScanController.php`, `Integrity/Scheduler/Cron.php`).
- `EmailAlerts::is_severity_enabled()` removed — dead method, logic was already inlined at the only caller.
- `LoginShield::save()` now writes via `Settings::set_key()` instead of a direct `update_option( CB_CORE_SETTINGS, … )` call. Existing granular `login.shield_enabled`, `login.shield_disabled`, and `login.url_changed` audit events keep firing; the change adds a `settings.changed` event with diff-paths so non-toggle subkey edits (slug, mode, redirect, response code) are now traceable in the audit log too.

**JS**

- Deprecated wrapper `HUDController.switchSide()` (since 1.4.9-dev) removed. Callers were already migrated to `stepPosition()`.

**CSS — token system**

- New tokens in `tokens.css`:
  - `--cb-error` (semantic distinct from `--cb-danger`: manifest failure vs. potential/intent), with matching `--cb-tint-error`, `--cb-glow-error`, `--cb-on-error`. Light theme overrides `--cb-error` for contrast on light surfaces.
  - `--cb-ff-mono` — Console + CLI Preferences mono stack, formalised so per-theme overrides can land later.
  - `--cb-ff-sans` — same rationale for the HUD chrome which crosses admin + frontend contexts.
- Bug fix: `--cb-on-warning` was `#ffffff` but `--cb-warning` is amber `#ffb547` — wit-op-amber haalt geen WCAG AA (≈3.0:1). Token now `#000000` (≈12:1 contrast). The brand-badge in `hud.css` was hardcoding `color: #000` for exactly this reason; that consumer now reads the token.

**CSS — fallback sweep**

Strict reading of "no hardcoded colors" includes hex/rgba fallbacks inside `var(--cb-token, #fallback)` expressions. With `tokens.css` shipped as a core dependency, those fallbacks only resolve when the file fails to load (which means the plugin is broken anyway).

- 250 hex/rgba fallbacks removed across `hud.css`, `console.css`, `notes.css`, `safeguards-core-scanner.css` via a balanced-paren parser that handles nested `color-mix(in srgb, var(--cb-x, #...) N%, transparent)` correctly.
- 105 numeric fallbacks (`var(--cb-space-N, NNpx)` etc.) removed across all CSS files. Some of these were misleading: e.g. `var(--cb-space-4, 20px)` while the actual `--cb-space-4` token is `16px`. Only `var(--cb-log-cols, 1fr)` is preserved — `1fr` is a functional grid-track default, not an outdated fallback.
- 5 incidental hardcoded color declarations replaced with tokens: `color: #fff` × 3 on `--cb-danger` fills (Console run-button danger variant + modal-confirm) → `var(--cb-on-danger)`; `box-shadow: inset 0 0 0 1px rgba(220, 53, 69, .30)` → `color-mix(in srgb, var(--cb-danger) 30%, transparent)`; `box-shadow: 0 0 0 1px rgba(0, 160, 210, .25)` (core-scanner primary action) → `color-mix(in srgb, var(--cb-accent) ...)`.

**CSS — intentionally NOT touched**

- `themes/canvas.css` — WP admin chrome overrides (`#wpwrap`, `#wpcontent`, `#wpfooter`) that sit outside `.cb-core-wrap` and need per-theme hex literals.
- `pages/appearance.css` theme-preview mockup — hex codes ARE the visualisation of what the user is selecting.
- `pages/security.css` severity-pills under `body[data-cb-theme="..._dark"]` — Tailwind-derived palette, gedocumenteerd als bewuste keuze.
- HUD brand-glow recipes under `[data-brand="core-blueprint"]` — gelaagde glow met `#0037ff` / `#00d8ff` is brand-specifiek; white-label brands kunnen het hele blok overriden.
- Pure depth-shadows (`rgba(0, 0, 0, X)`) en hairlines (1–3px) blijven px omdat dat de natuurlijke vorm is voor sub-rem visual detail.

**CSS — duplicate selectors**

12 echte top-level duplicate selector blocks geconsolideerd (parser onderscheidt eigen base-blok-vs-media-override van toevallige verstrooiing, zodat responsieve overrides niet onbedoeld samengeschoven worden):

- `hud.css` — `.cb-hud__side-switch` (twee blokken: hide-by-default + visual styling) → één blok
- `console.css` — drie `grid-area` toewijzingen op één rij geïntegreerd in de visuele blokken (`.cb-console__picker`, `__form`, `__output`)
- `notes.css` — `.cb-notes-title`, `.cb-notes-card__summary`, `.cb-notes-card__body`, `.cb-notes-rendered`, `.cb-notes-bulk-bar` consolidated (5 dupes, historisch gegroeid)
- `safeguards-core-scanner.css` — `.cb-core-spin` consolidated; daarbij verwijderd: redundante `@keyframes cb-core-spin` (dubbele van `cb-core-spin-rotate`); `.cb-core-integrity-empty-state` + `.cb-core-integrity-empty-state h2` consolidated in de gedocumenteerde Empty-state sectie

**CSS — `notes.css` rem-pass**

Page-level outlier qua px-gebruik (oudere file). Drie sub-passes:

- 13 `font-size: NNpx` declaraties → `var(--cb-fs-*)` tokens. Outliers (`18px`, `22px`) → rem zonder visuele verandering.
- Spacing-properties (`gap`, `padding`, `margin`, varianten): exact 4/8/12/16/24/32/48 → `var(--cb-space-1..7)` tokens (44 lines). Niet-token-fittende waarden (6px, 10px, 14px etc.) → equivalente rem.
- Dimensions (`max-width`, `height`, etc.) en outlier border-radii naar rem. `999px` (4 cases) → `var(--cb-radius-pill)`. Twee `line-height: NNpx` op icon-spans → unit-less `1`.

**Token additions to `--cb-error`**

`--cb-error` consumers in `safeguards-core-scanner.css` (Remove-from-baseline danger button) lazen het via fallback `var(--cb-error, #c0392b)`. Token nu live; fallbacks weg.

### Scrollbar primitive — suite-wide thin scrollbar

The HUD body has shipped a thin custom scrollbar since 1.4.x. That same look is now a suite-wide UI primitive, applied automatically on every scrollable element inside `.cb-core-wrap` (which includes Console, Logs, Notes, Reports, all Safeguards tabs, all Preferences tabs — every built-in CB page).

**New stylesheet:** `assets/css/components/scrollbar.css`. Loaded right after `tokens.css` and before any other component or page CSS so the rules sit at the cascade base. Enqueue handle is `cb-core-css-scrollbar` for sibling CB plugins to declare as a dependency.

**New tokens in `tokens.css`:**

- `--cb-scrollbar-width` (0.375rem)
- `--cb-scrollbar-radius` (0.1875rem)
- `--cb-scrollbar-thumb` (defaults to `var(--cb-border)`)
- `--cb-scrollbar-thumb-hover` (defaults to `var(--cb-text-muted)`)
- `--cb-scrollbar-track` (transparent)

**Two activation paths:**

1. **Automatic** — every scrollable descendant of `.cb-core-wrap` inherits the look (Firefox `scrollbar-color` cascades; WebKit pseudo-elements are explicitly targeted via `.cb-core-wrap ::-webkit-scrollbar-*` selectors).
2. **Opt-in `.cb-scrollbar`** — for portal / floating elements appended outside the wrap (modals on `<body>`, fixed-position toasts, popovers).

**HUD refactor:** `hud.css` previously had its own thin-scrollbar block hard-coded against `--cb-border`/`--cb-text-muted` with literal width `0.375rem` and radius `0.1875rem`. That block now reads from the new `--cb-scrollbar-*` tokens, so the HUD scrollbar shares its source of truth with the rest of the suite. HUD floats outside `.cb-core-wrap` so it can't use the wrap-scope pattern from scrollbar.css; the tokens give it the same look without duplicate values.

**For sibling CB plugins:** declare `cb-core-css-scrollbar` as a CSS dependency in your enqueue chain. Either render inside `.cb-core-wrap` (recommended — gets the look automatically) or apply `.cb-scrollbar` on your own scrollable container.

**For brand / per-theme overrides:** re-declare just the `--cb-scrollbar-*` tokens under your `html[data-cb-theme="..."]` block. The pseudo-element rules in scrollbar.css read from the tokens, so the look re-themes without re-implementing any of the rules.

### Page meta strip — semantic refactor + suite component

Audit/System Log and Maintenance Log render a row of contextual facts under the page title (totals, retention policy, access rule, export options). Two visual bugs surfaced from a markup model that didn't match the content:

- **Audit/System Log:** `Total events: <strong>929</strong>.` had the trailing period drift away from the count, because the period was a rogue text node inside a flexbox container — flexbox treats every direct child including text-nodes as a separate flex item, so the period got two `gap` widths around it.
- **Maintenance Log:** the meta strip began with a leading bullet because the first item carried `::before { content: "·" }` and no preceding item existed to make the bullet read as a separator.

Diagnosis: the `<p>` + `<span>` model treated the four facts as "a sentence with details", but they're not — they're peer items, equally weighted, with the count happening to be one of them. Peer items deserve a list.

**New markup contract:**

```html
<ul class="cb-core-meta">
  <li class="cb-core-meta__item">Total events: <strong>929</strong></li>
  <li class="cb-core-meta__item">Retention: 180 days</li>
  <li class="cb-core-meta__item">Visible to administrators only</li>
  <li class="cb-core-meta__item">Exportable as CSV, JSON</li>
</ul>
```

Bullet between items via `:not(:first-child)::before` — works for any item count, with or without a "lead" fact, no per-page modifier needed.

**New stylesheet:** `assets/css/components/meta.css`. Generic class names — not log-specific — so any CB page reuses the same pattern. Enqueue handle `cb-core-css-meta`. Loaded after tokens and scrollbar in the cascade base. Sibling CB plugins declare it as a dependency and reuse the markup.

**Templates updated:** `partials/log-events-page.php` (Audit + System), `maintenance-report.php`, `connection-log.php`. Old `cb-core-log-meta` page-specific class fully removed; no alias retained (memory rule: no backwards-compat in development).

**Translatable strings — trailing periods removed.** The middle-dot separator IS the sentence boundary; a period before it is double-punctuation. Affected strings: `Total events: %s`, `Total recorded: %s`, `Retention: %d day` / `Retention: %d days`, `Visible to administrators only`, `Exportable as %s`, `Read-only - no changes can be made here`. NL translations updated in lockstep — punctuation-only changes auto-confirmed (fuzzy markers cleared) so no translator round-trip needed.

**Accessibility benefit:** screen readers now announce "list with N items, item 1 of N" on the meta strip, matching what a sighted reader gets visually. The previous `<p>` with mixed text and spans had no list affordance.

### Reading-mode switcher unified across HUD and pages

Two parallel mode-switching systems existed: HUD's compact P/T/S segmented control (REST-driven, `.cb-core-dual` block flips) and the Logs Plain/Technical pill (AJAX-driven, page reload). Persistence pointed to the same user_meta, but the UI components were independent — and the HUD's mode-switch didn't propagate to Logs because Logs has no `.cb-core-dual` blocks (it renders different columns server-side per mode).

**One component now powers both.**

Single source: `assets/css/components/mode-switcher.css` plus `assets/js/core/mode-switcher.js` plus `UI::render_mode_switcher( array $args )`. Templates render with one PHP call:

```php
\CB\Core\UI::render_mode_switcher();                              // page-level, full text
\CB\Core\UI::render_mode_switcher( [ 'compact' => true ] );       // HUD header, P/T/S
```

**Three buttons everywhere — Plain | Technical | Sync.** Sync is a persistence-state ("follow site default"), not a render mode; the UI shows it as a third selectable highlight, render code never sees it.

**Architecture clean-up:**

- `UI::current_mode()` now guarantees a return of `'plain'` or `'technical'` — Sync resolves to site-default at the API layer. Render code drops defensive `if ( ! in_array( $cb_mode, [ 'plain', 'technical' ], true ) )` fallbacks (3 templates: log-events-page.php, maintenance-report.php, connection-log.php).
- `UI::current_user_preference()` is the read-side for switcher UIs that need to highlight Sync correctly (returns `'plain'/'technical'/'sync'/'inherit'`; `'inherit'` is mapped to `'sync'` for display, since they're the same state).
- HUD's REST `POST /hud/mode` endpoint and `handle_mode()` method removed — superseded by the suite-wide `cb_core_set_description_mode` AJAX endpoint (which already existed for Logs/Preferences). Single persistence path.
- `.cb-hud__mode-switcher` and `.cb-hud__mode-btn` CSS rules removed (~45 lines in hud.css). HUD now uses the generic component class with the `--compact` modifier.
- `.cb-core-mode-toggle` and `.cb-core-mode-toggle__btn` CSS rules removed (~40 lines in pages/logs.css). Logs renders the same component class.
- `hud.js`: removed `selectMode()`, `currentMode()`, `applyMode()` methods (~80 lines) plus the `modeBtns` constructor reference and the click-binding loop. Core mode-switcher handles it all.
- `logs-toggle.js` reduced from a click-handler module to a 10-line event-listener: when `cb-core-mode-changed` fires on a `.cb-core-logs-page`, `preventDefault()` + `window.location.reload()`.

**Cross-component sync via custom event.** When any switcher (HUD or page-level) successfully writes a new mode, `cb-core-mode-changed` is dispatched on `document`. The event is cancelable: pages that need a hard reload listen and call `preventDefault()`; pages with `.cb-core-dual` blocks let the default soft-flip run. Multiple switcher instances on one page (HUD + page-level Logs switcher) automatically stay in sync — every switcher's `is-active` button updates after the write.

**Sibling-plugin friendly.** New CB plugins declare `cb-core-css-mode-switcher` and `@cb-core/mode-switcher` as dependencies; render via the PHP helper. They don't reimplement click-handling, persistence, or DOM-flipping — the suite component is the API.

**Visual consistency:** rounded-square shape (var(--cb-radius-md)) replaces the previous Logs pill. Active state: `var(--cb-accent)` fill + `var(--cb-on-accent)` text in both contexts. Pages get full-text labels ("Plain | Technical | Sync"), HUD gets compact letters (P | T | S) — same component, two size variants via `--compact`.

### Plain-language coverage for log events

The Audit Log's Plain mode showed raw event slugs ("consoleexecuted", "permissionsoperatoradded", "systemoption_changed") for any event whose plain template hadn't shipped yet. The fallback path in `Language::describe_event()` correctly fell through to the technical label, but the technical label is the slug — so Plain mode looked broken even though the data was right.

**Audit of all `AuditLog::log()` and `SystemLog::log()` call sites** found 64 distinct event slugs emitted across the codebase; only 38 had plain templates. The other 35 fell through to the slug.

**All 35 missing translations added.** The catalog (`Language::EVENTS_PLAIN`) is now 72 entries and matches every slug that any code path emits. Templates use `{placeholder}` tokens that interpolate against the event's context array, so the row reads naturally with the actual values — `Console command 'integrity:scan' was run` instead of `consoleexecuted`.

Coverage now includes: Console execution, Login URL changes, Beacon CLI ping, redirect-key rotation, legacy-table cleanup, bulk module toggles, the full Permissions surface (operators added/removed/promoted, role drift events, admin-cap changes, page-visibility toggles, Operator-Guard interventions, recovery-grants), Privacy preset + IP-mode changes, the Reports lifecycle (generated/failed/downloaded/deleted/bulk-deleted, branding updates), log exports (system + maintenance), and the System events that previously slipped through (Foundation install/upgrade, login_failed, option_changed).

Both lookup paths verified — the dotted catalog key (`console.executed`) for in-memory callers, and the `sanitize_key()`-flattened form (`consoleexecuted`) for rows read back from the database, since `AuditLog::log()` runs every event_type through `sanitize_key()` before writing.

Second-pass audit added 22 more events (Integrity / Core Scanner subsystem, Notes subsystem, plus `system.login` success). These were missed in the first scan because they emit through wrapper helpers — `Audit::log()` for Integrity, `$this->audit()` (private wrapper) inside `ScanController`, and `AuditLog::queue()` for the high-volume `system.login` success event. The catalog now stands at 93 entries and matches every distinct slug emitted via any of these paths.

### Filter bar - suite-wide component

The Logs filter bars (Audit, System, Connection, Maintenance) had three layout problems: empty space above the unlabelled controls (Mode-switcher, Apply button, Export group) because some siblings carried `<label>` headings and others didn't; inconsistent control heights (mode-switcher narrower than `<select>` narrower than WP `.button`, with the export-format select pinned to a hardcoded `30px`); and no visual separation between filtering and acting — Apply and Export floated mid-row.

**One generic component now handles all of it.**

New file: `assets/css/components/filter-bar.css`. Class `cb-core-filter-bar` with `__field` (label-stack), `__label` (the heading span above each control), `__actions` (the rightmost cluster), and `__actions-row` (the inline row of action buttons inside the actions cluster). Generic on purpose — Notes overview, Reports overview, Hub fleet management can adopt the same markup. Sibling CB plugins declare `cb-core-css-filter-bar` as a dependency.

**Markup contract:**

```php
<form class="cb-core-filter-bar">
  <div class="cb-core-filter-bar__field">
    <span class="cb-core-filter-bar__label">Mode</span>
    <?php UI::render_mode_switcher(); ?>
  </div>
  <label class="cb-core-filter-bar__field">
    <span class="cb-core-filter-bar__label">Event type contains</span>
    <input type="text" name="event">
  </label>
  ...
  <div class="cb-core-filter-bar__actions">
    <span class="cb-core-filter-bar__label">Actions</span>
    <div class="cb-core-filter-bar__actions-row">
      <button class="button">Apply filters</button>
      <select class="cb-core-export-format">…</select>
      <button class="button">Export</button>
    </div>
  </div>
</form>
```

`<label>` for fields wrapping a single input/select (semantically correct, click-to-focus). `<div>` for the mode-switcher field (radiogroup, no single input) and for the actions cluster (no input). All visually identical via `__field`.

**Uniform control height.** New token `--cb-control-height: 32px` matches WordPress core's `.button` so WP-themed buttons drop in without measurement drift. Applied to every input, select, button, and the mode-switcher inside a filter bar via direct-child selectors. The mode-switcher's inner padding becomes horizontal-only; the height now comes from the container token. No more 30px/32px/4-vertical-padding mismatches.

**Labels everywhere - including the actions cluster.** Each filter has a label span (uppercase, muted, letter-spaced); the actions cluster has its own "Actions" label. This eliminates the empty-space-above-unlabelled-controls problem and makes every column read as one consistent unit.

**Responsive.** flex-wrap with row + column gap; the actions cluster stays right-aligned via `margin-left: auto` even when wrapped. At ≤782px (WP's tablet breakpoint) gap tightens; at ≤480px the bar stacks fully — one field per row, full-width inputs, actions wrap and right-align within their own row.

**Removed (no aliases, no shims):** `.cb-core-audit-filters` rules from `pages/security.css` (~17 lines), `.cb-core-log-filters`, `.cb-core-export-group`, and `.cb-core-log-filters__export` BC class from `pages/logs.css` (~50 lines). The wrap class `.cb-core-log-filters-wrap` stays — it's the sticky positioning context, not the bar styling.

**Templates migrated:** `partials/log-events-page.php` (Audit + System), `maintenance-report.php`, `connection-log.php`. Form-class `cb-core-audit-filters cb-core-log-filters cb-core-mr-filters` replaced by single `cb-core-filter-bar`. Per-input `<label>` upgraded to `<label class="cb-core-filter-bar__field"><span class="cb-core-filter-bar__label">…</span><input/select/></label>` pattern. Mode-switcher wrapped in matching `<div>` field. Apply + Clear + Export grouped into `__actions` with their own "Actions" label.

### Event-type normalisation - dot → underscore

Audit Log Technical mode showed unreadable run-together event slugs: `consoleexecuted`, `systemfoundation_upgraded`, `beaconcli_ping`. Cause: `AuditLog::log()` passed every event_type through `sanitize_key()`, which strips dots without replacement. Source code wrote `console.executed` (readable namespace-style); the DB stored `consoleexecuted` (collapsed and ambiguous).

**Latent bug uncovered.** `AuditLog::log()` line 141 had `if ( 0 === strpos( $event_type, 'system.' ) )` to gate the verbosity filter — but the `sanitize_key()` call on the line above had already stripped the dot, so the prefix could never match. The verbosity filter for `system_*` events had been silently inactive. Same issue in `Verbosity::category_for()` (all seven prefix checks). `MaintenanceReport::system_category()` had dual-checks that admitted the bug existed but never resolved it (`'systemplugin_'` OR `'system.plugin_'` — only the first ever fired because the dot was already gone by the time the DB row was read).

**New canonical form: `dot → underscore` before sanitize.**

`AuditLog::normalize_event_type( string $raw )`: a static helper that runs `str_replace( '.', '_', $raw )` first, then `sanitize_key()`. So `system.login` → `system_login`, `console.executed` → `console_executed`, idempotent on already-underscored slugs (`note_created` stays `note_created`). 50-char cap unchanged.

Applied at the `AuditLog::log()` write path; `AuditLog::queue()` flows through `log()` at flush time, so queued events normalise too.

**Read-side updates:**

- `Language::plain_lookup()` now indexes the catalog under normalized keys (calling the same helper) instead of the old `sanitize_key()` form. Catalog keys stay dotted for readability; the cache map stores `system_login` so DB-stored rows match directly.
- `Language::describe_event()` does the same on lookup-by-row.
- `AuditLog::event_label()` falls back to `normalize_event_type()` matching for both the catalog key and the queried slug — matches whether callers pass dotted or underscore form.
- `Verbosity::category_for()` and the `AuditLog::log()` verbosity gate now use `'system_'` prefix (the form rows actually have). Verbosity filtering for `system_*` events works again.
- `MaintenanceReport::system_category()` reduced to single-prefix checks against the underscore form. The dotted-form fallback branches removed (dead code under the new normalisation).

**One-shot DB migration: `EventTypeMigration` class** in `src/Log/`.

Walks 74 known canonical event slugs (every dotted form emitted via any code path: `AuditLog::log`, `SystemLog::log`, `AuditLog::queue`, `Audit::log`, the Integrity wrapper `$this->audit()`), computes the legacy dotless form and the new underscore form, and runs `wpdb->update()` per pair. Idempotent: a permanent `cb_core_event_type_normalised_v1` option flag tells subsequent admin requests to skip. Surfaces a one-shot admin notice with the row-count if any rows actually got renamed; auto-clears via 60s transient.

Hooks via `admin_init` from `Log\Bootstrap::boot()`. Bails gracefully if the audit-log table doesn't exist yet (first-install case). Unknown event_types (third-party hooks, future events without a dotted form) are not in the mapping and stay untouched.

**Visible result for Chris's screenshots after install:**

| Before | After |
|---|---|
| `consoleexecuted` | `console_executed` |
| `systemfoundation_upgraded` | `system_foundation_upgraded` |
| `systemfoundation_installed` | `system_foundation_installed` |
| `systemlogin` | `system_login` |
| `loginroute_blocked` | `login_route_blocked` |
| `beaconcli_ping` | `beacon_cli_ping` |
| `settingschanged` | `settings_changed` |
| `beaconredirectconsumed` | `beacon_redirect_consumed` |
| `beaconredirectminted` | `beacon_redirect_minted` |
| `loginshield_enabled` | `login_shield_enabled` |
| `loginurl_changed` | `login_url_changed` |
| `diagnosticheader_test` | `diagnostic_header_test` |
| `settingsfeature_toggled` | `settings_feature_toggled` |

## [1.6.2-dev] — unreleased

### CB Console — full atomic refactor + complex-arg UI

Three remaining CLI commands ship as atomic Console-runnable classes, plus two new argument-input types support their forms.

**24 of 24 registered commands now implement `CommandInterface`** — the full CLI surface is now uniformly atomic. The single exception is `cb scan run`, which has a working CommandInterface contract but its `execute()` returns a "1.6.3-dev" placeholder because async-polling UI is the next milestone (the CLI invocation continues to work fully).

### New atomic commands

- `cb operator add` — state-change. Required user-picker arg. Banner-warning, no confirm-modal. Audits `permissions.operator_added`. Refuses gracefully if user is already an operator.

- `cb operator remove` — destructive. User-picker + force boolean. Lockout-guard refuses to drop the last operator unless force is on. Modal-confirm with explicit lockout-warning in the action list. Audits `permissions.operator_removed` with severity `warning` and `forced` flag.

- `cb reports generate` — state-change. Type select (maintenance only for now), two date fields for period start/end. Defaults to the previous full calendar month if dates are empty. Returns the report ID + file path + download URL in `data` for the structured-data viewer.

### New argument types

**`user`** — autocomplete picker. Text input with a dropdown of matching users (login, email, display name) populated from a debounced fetch (200ms) against the new `/console/user-search` endpoint. Empty input shows recent users (registered DESC) as a sensible starting list. Numeric query lookups by ID directly. Stored value is the user's ID as a string; the displayed value is `login (display name)` so the operator sees what they picked.

Keyboard support: ArrowDown/ArrowUp to navigate, Enter to select, Esc to close. Outside-click closes too.

**`date`** — native `<input type="date">`. Empty string allowed; commands handle defaults server-side. Server-side validator rejects anything that doesn't match `^\d{4}-\d{2}-\d{2}$` and substitutes empty (which triggers the default-date logic).

### REST endpoints

```
GET /core-blueprint/v1/console/user-search?q=…&limit=8
```

Operator-only (gated on `cb_use_cli`). Returns up to N users matching the query against login/email/nicename/display_name via `WP_User_Query`. Empty query returns the most-recently-registered users. Numeric query takes the ID-lookup path first for direct ID matches.

### Architecture: atomic refactor complete

`OperatorCommand` and `ReportsCommand` removed — all functionality now lives in atomic siblings:

- `OperatorCommand::add()`    → `Commands\Operator\Add`
- `OperatorCommand::remove()` → `Commands\Operator\Remove`
- `OperatorCommand::list()`   → `Commands\Operator\ListOperators` (already migrated in 1.6.0-dev)
- `ReportsCommand::generate()`→ `Commands\Reports\Generate`

WP-CLI registration in `CLI\Registry` continues to use dotted-name entries (`operator add`, `operator remove`, `reports generate`) — each subcommand is its own atomic class.

The user-resolution helper (`resolve_user`) is duplicated across `Operator\Add` and `Operator\Remove` rather than extracted to a shared trait. Three atomic commands isn't enough to justify a shared layer per the rule-of-three; if a fourth user-arg command appears we'll extract it then.

### Files

New:
- `src/CLI/Commands/Operator/{Add,Remove}.php`
- `src/CLI/Commands/Reports/Generate.php`

Updated:
- `src/Console/Rest/RunController.php` — `/console/user-search` endpoint added; schema-normalizer accepts `date` and `user` types; phase note updated
- `src/Console/Registry.php` — atomic class refs for operator add/remove and reports generate
- `src/CLI/Registry.php` — dotted-name entries per atomic class
- `src/CLI/Commands/Scan/Run.php` — pending message updated to 1.6.3-dev
- `src/Admin/Admin.php` — i18n strings for user-picker (`userSearchPlaceholder`, `searching`, `noUsersFound`, `searchFailed`); `pendingPhase` updated to 1.6.3-dev
- `assets/js/features/console.js` — `buildUserPicker()` with autocomplete, debounced search, keyboard navigation, outside-click close; native `date` input rendering; user-search REST helper with cache
- `assets/css/pages/console.css` — `.cb-console__user-picker`, dropdown, options grid (login/name/email columns); `.cb-console__field-input--date` width cap
- `core-blueprint.php` — version 1.6.2-dev

Removed:
- `src/CLI/OperatorCommand.php` — replaced by atomic siblings
- `src/CLI/ReportsCommand.php` — replaced by atomic class

### Migration

No data migration. CLI users see no change — every refactored command keeps its WP-CLI dispatch wrapper. Sibling plugins extending `\CB\Core\CLI\OperatorCommand` or `\CB\Core\CLI\ReportsCommand` need to update class references.

The `cb_console_register_commands` filter contract is unchanged.

## [1.6.1-dev] — unreleased

### CB Console — write-action support

The Console runner now executes state-change and destructive commands in addition to the read-only set shipped in 1.6.0-dev. Two new UI building blocks support this:

**Banner-warning** for state-change commands. A persistent badge appears in the form footer next to the side-effects label, before the operator clicks Run. Visual emphasis without blocking interaction — the operator sees what's at stake then runs directly.

**Modal-confirm** for destructive commands. Clicking Run opens a dialog with a per-command "what will happen" action list, an explicit "irreversible" notice, and a red "Confirm and run" button. Cancel + Esc close without running. The Run button itself turns red when a destructive command is selected, so the colour change in the form footer mirrors the seriousness.

**Secret-token modal** for `cb failsafe rotate-token`. The new bypass URL is rendered in a dedicated copy-to-clipboard dialog rather than the regular output panel — the URL never lands in the always-visible output stream where it would linger until the operator clicks Clear. The output panel shows a placeholder ("Sensitive output rendered in a separate dialog and not stored here") instead. Commands return `data.sensitive_output: true` to opt into this flow; the runner reads the flag and routes accordingly.

### Confirm-token gate (server-side)

Destructive commands require an explicit confirm-token in the run request body. The token is a `wp_create_nonce`-based HMAC tied to the command id and the current user's session, fetched from a separate `/console/confirm-token` endpoint when the operator clicks "Confirm" in the modal. This is on top of the standard WP REST nonce — even an attacker who steals the REST nonce can't replay a destructive run without going through the modal.

State-change commands run with no extra token; the banner is the friction.

### Atomic write-action commands

Nine commands ship with full `execute()` + `args_schema()` + `side_effects()` implementations:

- `cb failsafe disable` — destructive, optional `--reason=<text>` arg saved to audit log
- `cb failsafe enable` — state, no args; reports any remaining bypass layers (constant in wp-config.php, transient window)
- `cb failsafe test` — state, no args; runs the self-test suite, returns structured pass/fail map
- `cb failsafe rotate-token` — destructive, no args; secret-token modal flow
- `cb failsafe close-window` — state, no args; idempotent
- `cb permissions show-page` — state, no args
- `cb permissions hide-page` — state, no args; refuses with explicit error when zero operators exist (lockout-guard)
- `cb cleanup legacy-tables` — destructive, optional `--dry-run` boolean; allowlist-only, drops `cb_beacon_connection_log` + `cb_sec_audit_log`
- `cb logs prune` — state, optional `--days=<n>` int override

Two stub `execute()` methods from 1.6.0-dev got real implementations:

- `cb beacon rotate-key` — destructive, calls `KeyManager::rotate()`, audit-logs the rotation
- `cb logs prune` — calls `AuditLog::prune()` with retention from settings or `--days` override

### Refactored from legacy classes

`PermissionsCommand` and `CleanupCommand` removed — their functionality now lives in atomic classes:

- `PermissionsCommand::show_page()` → `Commands\Permissions\ShowPage`
- `PermissionsCommand::hide_page()` → `Commands\Permissions\HidePage`
- `CleanupCommand::legacy_tables()` → `Commands\Cleanup\LegacyTables`

`Commands\Failsafe\Actions` (the multi-method placeholder from 1.6.0-dev) removed — replaced by five atomic siblings: `Disable`, `Enable`, `Test`, `RotateToken`, `CloseWindow`. WP-CLI registration in `CLI\Registry` updated accordingly — each subcommand is now its own dotted-name entry (`failsafe disable`, `failsafe enable`, etc.).

### Architecture summary after 1.6.1-dev

21 of 24 registered commands implement `CommandInterface` and are runnable from the Console. The remaining three (`operator add`, `operator remove`, `reports generate`) need user-pickers and date-args, scheduled for **1.6.2-dev**.

CLI invocations (`wp cb …`) work unchanged — every refactored command keeps its WP-CLI dispatch wrapper for terminal use.

### Files

New:
- `src/CLI/Commands/Failsafe/{Disable,Enable,Test,RotateToken,CloseWindow}.php`
- `src/CLI/Commands/Permissions/{ShowPage,HidePage}.php`
- `src/CLI/Commands/Cleanup/LegacyTables.php`

Updated:
- `src/Console/Rest/RunController.php` — runnable gate widened; confirm-token endpoint added; audit-log severity tied to side-effects level
- `src/Console/Registry.php` — write-action class refs point at atomic classes
- `src/CLI/Registry.php` — failsafe and permissions write-actions registered as dotted names per atomic class
- `src/CLI/Commands/Logs/Prune.php` — execute() implementation
- `src/CLI/Commands/Beacon/RotateKey.php` — execute() implementation
- `src/Admin/Admin.php` — Console i18n strings expanded with banner/modal/secret-token labels and per-command action lines
- `assets/js/features/console.js` — banner-warning rendering for state-change, modal-confirm + secret-token modal flows for destructive, confirm-token fetch flow
- `assets/css/pages/console.css` — banner-warning styles, modal overlay + dialog, danger-button variant, secret-token URL box, sensitive-output placeholder
- `core-blueprint.php` — version 1.6.1-dev

Removed:
- `src/CLI/Commands/Failsafe/Actions.php` — replaced by five atomic siblings
- `src/CLI/PermissionsCommand.php` — replaced by atomic classes
- `src/CLI/CleanupCommand.php` — replaced by atomic class

### Migration

No data migration required. CLI users see no change — every refactored command keeps its WP-CLI dispatch wrapper. Sibling plugins that previously extended `\CB\Core\CLI\PermissionsCommand`, `\CB\Core\CLI\CleanupCommand`, or `\CB\Core\CLI\Commands\Failsafe\Actions` need to update class references; the new atomic classes carry the same logic at new namespace paths.

The `cb_console_register_commands` filter contract is unchanged — third-party plugins extending the runner continue to work without changes.

## [1.6.0-dev] — unreleased

### CB Console — browser-based command runner

A new top-level admin page `Core Blueprint › Console` (slug `core-blueprint-console`, position 95, capability `cb_use_cli`, operator-only) lets operators run CB CLI commands from the browser without opening a terminal.

```
┌──────────┬─────────────────────────┐
│ Picker   │ Argument form           │
│ (search  │ (selected command's     │
│  + list) │  schema-driven inputs)  │
├──────────┴─────────────────────────┤
│ Output (banner + lines + data)     │
└────────────────────────────────────┘
```

Phase-1 scope: ten read-only commands runnable, the rest of the CLI surface is listed but with a disabled Run button. State-change and destructive commands land in **1.6.1-dev** with banner-warnings and modal-confirms respectively.

**Read-only commands runnable now:**

- `cb status` — operator-friendly site snapshot
- `cb version` — version + db-schema + WP/PHP runtime
- `cb scan latest` — most recent Core Scanner result
- `cb beacon status` — Beacon connection state
- `cb beacon ping` — Beacon loopback connectivity test
- `cb logs` — tail audit log with filters (limit, since, severity, event-prefix)
- `cb permissions status` — permissions configuration snapshot
- `cb operator list` — list cb_operator users
- `cb failsafe status` — failsafe state + active layers
- `cb diag i18n` — translation-loading diagnostic

**Listed but disabled (1.6.1-dev):**

- `cb scan run` — async scan trigger with polling
- `cb logs prune` — audit retention prune
- `cb beacon rotate-key` — destructive
- `cb failsafe disable / enable / test / rotate-token / close-window` — destructive
- `cb operator add / remove`, `cb permissions show-page / hide-page`, `cb reports generate`, `cb cleanup legacy-tables` — pending refactor

### Atomic command refactor

Sub-commands that previously lived as methods on a single CLI class are now split into atomic classes per sub-command, each implementing `\CB\Core\Console\CommandInterface`:

```
src/CLI/Commands/
├── Status.php                 (was monolithic, refactored)
├── Version.php                (was monolithic, refactored)
├── Scan/
│   ├── Run.php                (was Scan::run())
│   └── Latest.php             (was Scan::latest())
├── Beacon/
│   ├── Status.php             (was Beacon::status())
│   ├── Ping.php               (was Beacon::ping())
│   └── RotateKey.php          (was Beacon::rotate_key())
├── Logs/
│   ├── Tail.php               (was Logs::__invoke())
│   └── Prune.php              (was Logs::prune())
├── Failsafe/
│   ├── Status.php             (was Failsafe::status())
│   └── Actions.php            (disable/enable/test/rotate-token/close-window — multi-method by design, one CLI namespace)
├── Operator/
│   └── ListOperators.php      (was OperatorCommand::list())
├── Permissions/
│   └── Status.php             (was PermissionsCommand::status())
└── Diag/
    └── I18n.php               (was DiagCommand::i18n())
```

Each atomic class implements three methods:

- `execute(array $args): Result` — pure business logic, returns a structured Result
- `args_schema(): array` — declares form-fields for the Console UI
- `side_effects(): 'none'|'state'|'destructive'` — drives the runnable gate + future warning UI

The WP-CLI `__invoke()` method is now a thin wrapper that calls `execute()` and formats the Result as terminal output. CLI users see no behavioural change — same flags, same output, same exit codes.

### Result value object

New `\CB\Core\Console\Result` carries the output of every command:

- `status: 'success'|'warning'|'error'`
- `message: string` — single-line summary
- `lines: string[]` — plain-text output, monospace, line-by-line
- `data: array|null` — structured payload, foldable JSON view in Console
- `meta: array` — execution metadata (duration_ms, etc.)

Both the CLI dispatch wrapper and the Console REST endpoint consume the same Result. Same source of truth, two presentations.

### REST endpoints

```
GET  /core-blueprint/v1/console/commands
POST /core-blueprint/v1/console/run
```

Both gated on `cb_use_cli` capability. The catalog endpoint returns the full Registry::commands() list with each command's args_schema + side_effects, plus a `runnable` flag the UI uses to enable / disable the Run button. The run endpoint dispatches to the command's `execute()`, audit-logs the outcome (`console.executed`), and returns the Result as JSON.

In 1.6.0-dev the runnable gate is `side_effects() === 'none'`. Sessie 2 (1.6.1-dev) extends this with confirm-flows for `state` and `destructive` levels.

### HUD item

Operator-only quick-launch into the Console page. Order 65 — directly after the CLI documentation entry (60) so docs and runner cluster in the operator's mental model. Capability `cb_use_cli`.

### Architecture extension points

White-label and sibling plugins can extend the Console surface through filters:

- `cb_console_register_commands` — append atomic commands to the runner. Each entry needs `id`, `name`, `class`, `description`, optional `group` and `capability`. The class must implement `CommandInterface`.

### Files

New:
- `src/Console/Bootstrap.php`
- `src/Console/Page.php`
- `src/Console/Registry.php`
- `src/Console/Result.php`
- `src/Console/CommandInterface.php`
- `src/Console/Rest/RunController.php`
- `src/CLI/Commands/Scan/{Run,Latest}.php`
- `src/CLI/Commands/Beacon/{Status,Ping,RotateKey}.php`
- `src/CLI/Commands/Logs/{Tail,Prune}.php`
- `src/CLI/Commands/Failsafe/{Status,Actions}.php`
- `src/CLI/Commands/Operator/ListOperators.php`
- `src/CLI/Commands/Permissions/Status.php`
- `src/CLI/Commands/Diag/I18n.php`
- `assets/css/pages/console.css`
- `assets/js/features/console.js`

Removed:
- `src/CLI/Commands/Scan.php`     (replaced by Scan/{Run,Latest}.php)
- `src/CLI/Commands/Beacon.php`   (replaced by Beacon/{Status,Ping,RotateKey}.php)
- `src/CLI/Commands/Logs.php`     (replaced by Logs/{Tail,Prune}.php)
- `src/CLI/Commands/Failsafe.php` (replaced by Failsafe/{Status,Actions}.php)
- `src/CLI/DiagCommand.php`       (replaced by Diag/I18n.php — atomic)

Changed:
- `core-blueprint.php` — version 1.6.0-dev
- `src/Core.php` — boot Console subsystem
- `src/Admin/Admin.php` — added `CONSOLE_SLUG`, page-CSS list includes `console`, registered `@cb-core/console` JS module
- `src/CLI/Registry.php` — `builtin_commands()` rewritten with dotted names (`scan run`, `beacon ping`, etc.) mapping to atomic classes
- `src/CLI/Commands/Status.php`  — refactored to execute()-based pattern
- `src/CLI/Commands/Version.php` — refactored to execute()-based pattern

### Migration

No data migration required. CLI users see no change. The audit-log event `console.executed` joins the existing event vocabulary; old events keep their labels.

Sibling plugins that previously extended the legacy `Commands\Scan`, `Commands\Beacon`, `Commands\Logs`, or `Commands\Failsafe` classes need to update class references — the classes have moved to subdirectories (`Commands\Scan\Run`, etc.). The CLI Registry's `cb_core_cli_register_commands` filter contract is unchanged; only the package paths shift.

## [1.5.1-dev] — unreleased

### HUD — Display section: combined brand + theme rows (Optie C)

The Display section is collapsed from four rows (BRAND header → brand-card → THEME header → theme-cards) into one row per registered brand: brand trigger on the left, theme segment on the right.

```
┌─ DISPLAY ──────────────────────────────────────┐
│ [logo] Core Blueprint              [☀] [☾]    │
└────────────────────────────────────────────────┘
```

In 1.5.1-dev only Core Blueprint is registered as a brand, so the Display section is a single row. When a white-label plugin registers a second brand via the existing `cb_core_register_brands` action, the section automatically expands to one row per brand without any code change here — every additional brand gets the same trigger + segment treatment.

**Theme glyphs**: the theme segment uses sun (light) and moon (dark) glyphs from a small inline-SVG set rather than coloured swatches. White-label brands shipping themes whose `mode` falls outside the binary (e.g. a custom "high-contrast" or "sepia") get a generic palette glyph as fallback.

**Theme is global, not per-brand**: clicking ☀ or ☾ in any row sets the site-wide active theme through the existing Themes API. The active theme is reflected across every Display row simultaneously. Per-brand theme memory is not implemented in 1.5.1-dev — it would only matter when a second brand exists; the architecture leaves room for it without breaking the current option key (`cb_core_theme`).

### White-label architecture for brand + theme

Three contracts now exist for white-label and theme-pack plugins to extend the HUD without subclassing built-in classes:

**`AbstractBrand`** — new convenience base class providing default implementations of `themes()` and `render_trigger()`. Built-in brands (CoreBlueprint, Achterhood) extend it. Third-party brand classes can extend it too, or implement `BrandInterface` directly when they need full control.

**`BrandInterface::themes(): array`** — declares which theme variants a brand provides. Default in `AbstractBrand`: one light + one dark. Each entry shape: `[ 'slug' => 'theme_slug', 'label' => 'Display name', 'mode' => 'light|dark|other' ]`. The slug must also be registered through the global `cb_admin_themes` filter so the Themes system itself recognises it.

**`BrandInterface::render_trigger(): string`** — returns the HTML for the left-side trigger area (logo + label by default). White-label brands override to render their own composition: wordmark only, logo only, custom compound shape. The default `AbstractBrand::render_trigger()` renders a standard logo + label block using the existing `cb-hud__brand-trigger` / `cb-hud__brand-logo` / `cb-hud__brand-label` classes — keeping that class set lets the row layout stay consistent across brands.

**Filter `cb_core_brand_themes_{brand_id}`** — wraps the return of `themes()` so a separate plugin can replace a brand's theme list without subclassing the brand. Useful for shipping a "Theme Pack" extension. Example:

```php
add_filter( 'cb_core_brand_themes_core-blueprint', function (): array {
    return [
        [ 'slug' => 'achterhood_cream', 'label' => 'Cream', 'mode' => 'light' ],
        [ 'slug' => 'achterhood_slate', 'label' => 'Slate', 'mode' => 'dark' ],
    ];
} );
```

`HUD::sanitize_logo_svg()` is now public (was private) so brand classes can sanitise their own SVG inside `render_trigger()` without duplicating the allowlist logic.

### HUD — Section header padding +25%, section-title font sm

Section toggle padding lifted from `0.3125rem 0.5rem` (5/8px) to `0.4375rem 0.625rem` (7/10px). Section-title font from `0.5625rem` (9px) to `0.6875rem` (11px). Letter-spacing trimmed from `0.1em` to `0.08em` so the wider letters at the larger size don't read as too-tracked. Negative horizontal margin updated to match the new padding so the hover/expanded background still bleeds to the section edge.

Visual delta: section headers feel more clickable and the labels read at a more comfortable size, without disturbing the panel's overall rhythm.

### HUD — CSS px → rem sweep

Walked the entire `assets/css/components/hud.css` (1290 lines) converting pixel values to `rem` on a 16px-base. **142 rem** values now, **81 px** retained.

Retained px (intentional):
- 1px hairline borders (rem rounding loses sub-pixel rendering at default zoom)
- box-shadow offsets and blur radii (don't scale meaningfully with font-size)
- breakpoints inside `@media` queries (px is the convention)
- drag-coordinate values in JS (not visual, not user-scaled)

Future white-label themes that ship larger or smaller base font sizes will now scale the HUD proportionally — a theme using 18px-base sees the panel grow ~12.5%, an 14px-base shrinks it ~12.5%. Previously every dimension was hardcoded.

### Preferences › CLI tab — dark-mode contrast fix

Three contrast issues fixed:

- **Capability badge** — was `--cb-accent-soft` solid background with `--cb-text-strong` text, which rendered as washed-out blue-on-blue in dark mode. Now uses the standard chip pattern: `--cb-surface-2` background, 1px `--cb-border`, `--cb-text-muted` foreground. Matches how badges render elsewhere in CB and stays legible in both themes.
- **Code blocks** — example commands in `<pre><code>` now use `--cb-text-strong` instead of `--cb-text` for sharper contrast against the `--cb-surface-2` background, especially in dark mode.
- **Host-note (Cloud86 callout)** — was solid `--cb-accent-soft`, which blends into the surrounding surface in dark mode. Now uses a subtle `color-mix(in srgb, var(--cb-accent) 8%, transparent)` overlay plus a 1px border and a 4px-equivalent left accent rule, giving a definite shape regardless of theme.

Source mode was used wherever available — `0.25rem` for the left-accent rule means the visual weight of the note still scales with browser font-size.

### Files

New:
- `src/HUD/Brand/AbstractBrand.php`

Changed:
- `core-blueprint.php` — version 1.5.1-dev
- `src/HUD/Brand/BrandInterface.php` — added `themes()` and `render_trigger()`
- `src/HUD/Brand/CoreBlueprint.php` — extends AbstractBrand
- `src/HUD/Brand/Achterhood.php` — extends AbstractBrand
- `src/HUD/HUD.php` — `sanitize_logo_svg()` is now public; render_display_section rewritten to combined-row layout
- `assets/css/components/hud.css` — px → rem sweep, section-header padding +25%, brand/theme card CSS replaced by display-row + theme-segment styling
- `assets/css/pages/preferences-cli.css` — capability badge / code block / host-note contrast fixes

### Migration

No data migration required. Existing brand registrations that implement `BrandInterface` directly (without extending `AbstractBrand`) need to add `themes()` and `render_trigger()` methods — the interface contract is now binding for both. Built-in brands (CoreBlueprint, Achterhood) and any future brand using `AbstractBrand` get the defaults for free.

Existing `data-cb-hud-theme-card` selectors in third-party JS continue to work — the attribute is preserved on the new theme-segment buttons.

## [1.5.0-dev] — unreleased

### CB CLI — `wp cb` command surface unified under a single namespace

The 1.5.0-dev release introduces `wp cb` as the canonical command-line interface for Core Blueprint. Every previous `wp core-blueprint …` command moves to its `wp cb` equivalent (no backwards-compat shim — CB Suite is in development, every site updates simultaneously). Seven new operator-friendly commands ship alongside, and the whole surface is now filter-driven so sibling plugins can register their own subcommands without touching CB Base.

**New operator commands**

| Command | Purpose |
|---|---|
| `wp cb status` | Operator snapshot: version, modules-on/off, Beacon connection, last scan, pending updates |
| `wp cb version` | Plugin + suite component versions; siblings extend via `cb_core_cli_version_components` filter |
| `wp cb scan run [--user=<user>] [--wait]` | Async-trigger a Core Scanner integrity scan; optional blocking wait |
| `wp cb scan latest [--format=<fmt>]` | Print the most recent scan result |
| `wp cb beacon status [--format=<fmt>]` | Beacon connection state (paired, last poll, last Hub origin, recent failures) |
| `wp cb beacon ping` | Loopback REST connectivity test — confirms the route resolves and the auth gate is active |
| `wp cb logs [--limit=N] [--since=<date>] [--severity=<lvl>] [--event-prefix=<p>]` | Tail recent audit-log entries with filters |

All commands support `--format=text|json|yaml` where machine-readable output makes sense (status, version, beacon status, scan latest), so deploy scripts can parse output directly. `wp cb scan run --user=` is the only write-action that requires an explicit operator reference — the audit log records the actor and the CLI has no implicit "current user".

**Migrated commands (one-to-one)**

| Old | New |
|---|---|
| `wp core-blueprint status` | `wp cb failsafe status` |
| `wp core-blueprint emergency-disable` | `wp cb failsafe disable` |
| `wp core-blueprint emergency-enable` | `wp cb failsafe enable` |
| `wp core-blueprint test-failsafe` | `wp cb failsafe test` |
| `wp core-blueprint rotate-token` | `wp cb failsafe rotate-token` |
| `wp core-blueprint close-window` | `wp cb failsafe close-window` |
| `wp core-blueprint prune-audit` | `wp cb logs prune` |
| `wp core-blueprint beacon-rotate-key` | `wp cb beacon rotate-key` |
| `wp core-blueprint operator …` | `wp cb operator …` |
| `wp core-blueprint permissions …` | `wp cb permissions …` |
| `wp core-blueprint reports …` | `wp cb reports …` |
| `wp core-blueprint cleanup …` | `wp cb cleanup …` |
| `wp core-blueprint diag …` | `wp cb diag …` |

Naming convention now consistent across the surface: namespaces are nouns (`failsafe`, `beacon`, `logs`, `operator`), subcommands are verbs (`disable`, `rotate-token`, `prune`, `add`). The ad-hoc verb-with-prefix shape (`emergency-disable`, `prune-audit`) is gone — single naming standard, less to remember.

**Architecture**

`src/CLI/Bootstrap.php` registers commands during plugin load (must run before WP-CLI's command-resolution pass). `src/CLI/Registry.php` exposes the public `cb_core_cli_register_commands` filter so siblings extend the surface without touching CB Base — same shape as the HUD's filter-driven item registry. Each command lives in its own class under `src/CLI/Commands/` (Status, Version, Scan, Beacon, Logs, Failsafe) keeping the surface mappable: one namespace = one file.

The legacy `src/CLI/Command.php` (which housed status/emergency-disable/etc as one mixed class) is removed; its methods live in `Commands/Failsafe.php` and `Commands/Beacon.php` now.

**Capability**

New `cb_use_cli` operator-only capability. No admin-toggle: WP-CLI access is a server-shell concern, not something a site admin grants/revokes through CB. The cap gates the Preferences › CLI documentation tab and the HUD CLI item; actual `wp cb` execution is gated by whoever has shell access to the server.

**Async scan from CLI**

`wp cb scan run` schedules through the same `ASYNC_SCAN_HOOK` the REST endpoint uses (`wp_schedule_single_event` + `spawn_cron`), then polls the progress transient if `--wait` is passed. Single execution path across REST and CLI — single observable state, single audit-trail. On hosts where `DISABLE_WP_CRON` is true, falls back to a synchronous run inside the CLI process with a warning.

### HUD — `cb-core` section completes with every CB Base admin page

Every Core Blueprint top-level page now appears as a HUD item under the cb-core section (Operator's territory). Previously only the Dashboard was wired; this milestone lights up the full set:

| Item | Conditional on | Capability |
|---|---|---|
| CB Dashboard | always | `cb_view_permissions` (existing) |
| Logs | always | `manage_options` |
| Notes | Notes module enabled | `cb_manage_notes` |
| Reports | Reports module enabled | `cb_view_reports` |
| Safeguards | always | `cb_view_permissions` |
| Preferences | always | `cb_view_permissions` |
| CLI documentation | always | `cb_use_cli` |

Items render in the order Logs → Notes → Safeguards → Reports → Preferences → CLI, gated by the existing per-module master switch (Notes/Reports honour their `State::is_enabled()`). Disabled modules drop their HUD item without the rest of the section reflowing — the same gating principle as the admin sidebar.

Each subsystem registers its own HUD item via `cb_hud_register_items` from a dedicated `Bootstrap.php` (Notes/Reports already had one and got an extra `register_hud_item()` method; Logs/Safeguards/Preferences are new lightweight Bootstraps following the same pattern).

The CLI documentation entry deep-links to `Preferences → CLI` rather than to a top-level page — the tab is pure documentation in 1.5.0-dev, no execution surface yet.

### Preferences › CLI tab — full reference for `wp cb`

New tab under Preferences (between Permissions and About). Pure reference page: every `wp cb` subcommand listed under three sections (daily operator commands, maintenance, permissions, recovery), each with a one-line description, a copy-ready example, the required capability, and a "Setup" section with WP-CLI installation guidance plus a Cloud86-specific note.

UI:

- Native ES module `@cb-core/preferences-cli` for copy-to-clipboard buttons (no jQuery, native `navigator.clipboard.writeText` with a `document.execCommand('copy')` fallback for older Safari)
- All styling via `--cb-*` design tokens — light + dark themes work without an extra theme switch
- Mobile-responsive: code blocks and copy buttons stack vertically below 600px viewport
- Cap-gated on `cb_use_cli` — operators only

No execution surface in this tab — that lands in Phase 2 (in-browser terminal emulator) which will reuse the same Commands classes built here.

### Capabilities

- New: `cb_use_cli` — operator-only. Granted automatically to the `cb_operator` role on activation.
- `Permissions\Roles::OPERATOR_CAPS` extended with the new cap.
- `ADMIN_TOGGLE_MAP` in `Permissions\Caps` is unchanged — `cb_use_cli` is intentionally not toggleable.

### Files

New:
- `src/CLI/Bootstrap.php`, `src/CLI/Registry.php`, `src/CLI/RootCommand.php`
- `src/CLI/Commands/Status.php`, `Version.php`, `Scan.php`, `Beacon.php`, `Logs.php`, `Failsafe.php`
- `src/Log/Bootstrap.php`, `src/Safeguards/Bootstrap.php`, `src/Preferences/Bootstrap.php`
- `assets/css/pages/preferences-cli.css`
- `assets/js/features/preferences-cli.js`
- `templates/preferences-cli.php`

Removed:
- `src/CLI/Command.php` (methods migrated to Commands/Failsafe.php and Commands/Beacon.php)

Changed:
- `core-blueprint.php` — version 1.5.0-dev, CLI registration via `Bootstrap::register_cli()`
- `src/Core.php` — boots Log/Safeguards/Preferences/CLI Bootstrap classes
- `src/Permissions/Roles.php` — `cb_use_cli` added to `OPERATOR_CAPS`
- `src/Admin/Pages/Preferences.php` — CLI tab definition + render_cli_tab method
- `src/Admin/Admin.php` — CSS + JS module registrations for the new tab
- `src/Notes/Bootstrap.php` — HUD-item registration (conditional on master switch)
- `src/Reports/Bootstrap.php` — HUD-item registration (conditional on master switch)
- `src/CLI/OperatorCommand.php`, `PermissionsCommand.php`, `ReportsCommand.php`, `CleanupCommand.php`, `DiagCommand.php` — docblocks updated to `wp cb …`

### Migration

No data migration required. The legacy `wp core-blueprint …` commands stop working on update; their successors are listed in the migration table above. Audit log entries from before 1.5.0-dev keep their original event_type values; the labels remain valid.

## [1.4.9-dev] — unreleased

### HUD — Up/down step buttons complete the 4-direction navigation grid

The 1.4.8-dev release got side-switch (left/right) right but operators still had to drag the toggle to move it vertically. This adds up/down step buttons to the footer center, completing a 4-direction step pad that reaches every anchor on the 3×3 grid via clicks alone.

**Footer layout** is now:

```
←        ↑↓        →
```

Left and right buttons stay in the lower corners (positioned absolutely as before). A new `cb-hud__vside-group` flex container holds the up/down buttons in the center, positioned absolutely at `left: 50%; transform: translateX(-50%)`. The group adapts to its visible children — when only one of ↑/↓ is visible, the single button stays centered; when both are visible, they sit side-by-side at the center.

**Per-anchor visibility matrix** (each direction shows when that move is available):

| Anchor | ← | → | ↑ | ↓ |
|---|---|---|---|---|
| top-left | — | ✓ | — | ✓ |
| top-center | ✓ | ✓ | — | ✓ |
| top-right | ✓ | — | — | ✓ |
| middle-left | — | ✓ | ✓ | ✓ |
| middle-right | ✓ | — | ✓ | ✓ |
| bottom-left | — | ✓ | ✓ | — |
| bottom-center | ✓ | ✓ | ✓ | — |
| bottom-right | ✓ | — | ✓ | — |

CSS-driven via attribute selectors on `data-anchor` — no JS for show/hide.

**Step rules per axis** (mirror each other on horizontal vs vertical):

| | Horizontal step | Vertical step |
|---|---|---|
| Path through center | top/bottom rows step through `*-center` | left/right columns step through `middle-*` |
| Skip center | middle row jumps directly across (no `middle-center` exists) | center column jumps directly across (no `middle-center`) |

So a `top-center` toggle clicked ↓ goes directly to `bottom-center` (skipping the non-existent `middle-center`); a `top-left` toggle clicked ↓ goes to `middle-left` then another click to `bottom-left`.

The opposite axis is preserved on every step — vertical clicks don't change column, horizontal clicks don't change row. No accidental cross-axis jumps.

**Smoke test**: 32/32 transitions verified — all 8 starting positions × 4 directions match the expected target table.

**JS refactor**: `switchSide(targetSide)` is replaced by `stepPosition(direction)` which handles all four directions in one method. The old method stays as a deprecated wrapper for any external callers. Buttons now carry `data-direction` (with values `left|right|up|down`) instead of the previous `data-target-side`.

**Translation strings**: "Move HUD up", "Move HUD down" added. 1326 nl_NL translated (was 1324).

**Files touched**: `src/HUD/HUD.php` (footer markup adds vside-group with two new buttons), `assets/css/components/hud.css` (vside-group center positioning + 4-direction visibility rules), `assets/js/features/hud.js` (stepPosition method, click bindings updated, switchSide deprecated wrapper). No schema, no REST.

---

## [1.4.8-dev] — unreleased

### HUD — Side-switch button is now stepped on top/bottom rows, jumped on middle row

Two related issues with the side-switch transitions:

**top-center → click → landed at bottom-{target}**, not top-{target}. The 1.4.6-dev `switchSide()` had `bottom-${targetSide}` hardcoded for the center-anchor branch — a mistake from the original implementation when only `bottom-center` existed. The vertical anchor (top / middle / bottom) wasn't being preserved across the transition, so a top-center toggle that got clicked toward the left or right teleported to the bottom row.

**Side-switch buttons jumped fully across the row.** A toggle at `top-right` clicked toward the left went straight to `top-left`, skipping `top-center`. That made the button feel binary (left ↔ right) when in fact the row has three logical positions (left, center, right) and operators may want to land in the middle without dragging.

Both addressed by rewriting `switchSide()` with row-aware behavior:

| Toggle position | Click `<` | Click `>` |
|---|---|---|
| `top-left` | (no-op, already left) | `top-center` |
| `top-center` | `top-left` | `top-right` |
| `top-right` | `top-center` | (no-op, already right) |
| `middle-left` | (no-op) | `middle-right` |
| `middle-right` | `middle-left` | (no-op) |
| `bottom-left` | (no-op) | `bottom-center` |
| `bottom-center` | `bottom-left` | `bottom-right` |
| `bottom-right` | `bottom-center` | (no-op) |

**Top and bottom rows step through center.** A click moves the toggle one column at a time. Reaching the edge (`*-left` or `*-right`) makes that direction's button hidden via existing CSS rules, so the operator can only step back toward center.

**Middle row jumps directly across.** There's no `middle-center` anchor (would put the toggle in the dead center of the screen, blocking content), so the button skips center and lands on the opposite side in one click. Matches the existing CSS rule that hides the only-on-center buttons for middle-anchored toggles — they aren't visible there because there's nowhere to step to.

**Vertical anchor is always preserved.** A `top-*` toggle stays in the top row across all switches; a `bottom-*` toggle stays in the bottom row. No more cross-row jumps.

**Smoke test**: 16/16 transitions verified — all 8 starting positions × both target sides — matching the expected table above.

**Files touched**: `assets/js/features/hud.js` only — single method rewritten, ~30 lines. No CSS changes (the visibility rules from 1.4.6-dev still work correctly with the new transition pattern). No PHP changes.

---

## [1.4.7-dev] — unreleased

### HUD — Three CSS bug fixes after live-test of 1.4.6-dev's 8-anchor matrix

Live-testing all eight dock positions revealed three issues — one functional bug, two visual edge-cases.

**Side-switch buttons were always visible.** The "default hidden" rule at line ~307 (`.cb-hud__side-switch { display: none; }`) was overridden by a SECOND rule with the same class selector at line ~655 that included `display: flex` among its base styling. Both rules had equal specificity, the later one wins, so the `display: none` default never took effect. Result: at every anchor, BOTH `target-left` and `target-right` buttons rendered — a `<` chevron in the lower-left and a `>` chevron in the lower-right of the footer regardless of where the toggle sat.

The intended behavior was: right-anchor → only target-left visible (chevron pointing where the panel will move), left-anchor → only target-right, center-anchor → both buttons visible. After the fix, only the second block was modified — `display: flex` was removed so that the `display: none` default wins and the anchor-specific rules (specificity higher) upgrade matching buttons to flex. The base block keeps positioning + dimensions.

**Middle-anchor panel could overflow shorter viewports.** The 1.4.6-dev rule placed middle-anchored panels at `top: 50%` with `margin-top: -320px` (half of the 640px panel height). On viewports under ~720px tall, half the panel extended above the viewport top and the other half below — clipping invisibly through both edges.

Fix: the panel height at middle anchors clamps tighter than the global default, using `min(640px, calc(100vh - 80px))` and the negative margin-top is computed via `calc()` against the same expression so the panel stays vertically centered regardless of which clamp wins. 80px reservation (40 top + 40 bottom) keeps the panel breathing on shorter screens.

**Top-anchor toggles overlapped the WordPress admin bar.** A toggle docked at `top-left` / `top-center` / `top-right` sat 18px from the viewport top — half-overlapping the 32px-tall admin bar (or 46px on mobile widths under 782px). The toggle was barely clickable and visually crashed into the admin bar branding.

Fix: new `--cb-hud-top-safe` CSS custom property that defaults to the existing `--cb-hud-safe` (18px) but adds the admin bar height when `body.admin-bar` is present (32px on desktop, 46px on mobile via media query). All three top-* toggle positions plus the top-anchored panel offset use this variable. On frontend pages without admin bar, behavior is unchanged.

`body.admin-bar` is the standard WP class added when `wp_admin_bar_render()` runs — covers admin pages and frontend-with-admin-bar contexts equally.

**Files touched**: `assets/css/components/hud.css` only — no PHP, no JS, no schema. Three small CSS edits, no behavior contract changes.

---

## [1.4.6-dev] — unreleased

### HUD — Middle-anchor panel placement + dual side-switch buttons at center anchors

Two issues observed after 1.4.5-dev's data-anchor architecture landed in production:

**Middle-anchor toggles overlapped the panel.** A toggle at `middle-left` or `middle-right` sits at the viewport's vertical center; the 1.4.5-dev rule that grouped middle-* with bottom-* put the panel anchored to the bottom edge — meaning a 640px panel hanging from the bottom would extend up THROUGH the toggle's vertical position. The toggle button visually floated INSIDE the panel.

Fix: explicit `middle-left` and `middle-right` selectors, separate from the bottom-* group. Panel is now vertically centered (`top: 50%` + `margin-top: -320px`, half the panel height) AND horizontally shifted past the toggle by 56px (toggle's 46px width + 10px gap). The two never overlap regardless of viewport size.

The `transform: translateY(-50%)` alternative was rejected because it conflicts with the open-state's existing `transform: translateY(0)` animation. Margin-trick keeps the transform property exclusively for the open/close motion.

**Center anchors now show TWO side-switch buttons.** `top-center` and `bottom-center` toggles have no horizontal opposite to flip to — but they DO have two valid horizontal destinations (left or right). The 1.4.5-dev solution hid the side-switch button entirely at center anchors, but that left the operator without a quick-action UI for moving the panel off-center.

This release renders BOTH buttons (`target-left` and `target-right`) in the footer always; CSS shows the appropriate set per anchor:

- **Right anchor** → only `target-left` button visible (chevron points left, in lower-left corner)
- **Left anchor** → only `target-right` button visible (chevron points right, in lower-right corner)
- **Center anchor** → BOTH buttons visible, one in each corner, each chevron pointing to its destination

Each button has a fixed chevron direction (matching its target-side); no JS rotation logic needed. CSS-driven visibility via `[data-anchor$="-right/-left/-center"]` selectors keeps the markup static and the JS minimal.

**Cleaner JS contract**: the `refreshSideSwitchButton()` and `updateSideSwitchChevron()` methods are removed — they existed to flip a single button's chevron when the anchor changed, which is no longer needed since each button now has a fixed chevron. `applyDockPosition()` simplifies to just updating toggle position + panel anchor; CSS handles button visibility from the anchor change.

**JS event binding**: `querySelectorAll('[data-cb-hud-side-switch]')` replaces the previous `querySelector(...)` so click handlers attach to both buttons. Each button's `data-target-side` tells `switchSide()` where to flip; the existing flip-logic stays unchanged.

**Translation strings**: "Move HUD to the left", "Move HUD to the right" replace the previous templated "Move HUD to the %s" + "left"/"right" parts. Static strings render predictably via `__()` without the format-string intermediation. 1324 nl_NL translated (was 1322).

**Files touched**: `src/HUD/HUD.php` (two-button footer markup), `assets/css/components/hud.css` (middle-* anchor rules + button visibility per anchor), `assets/js/features/hud.js` (querySelectorAll + simplified applyDockPosition + removed two helper methods).

---

## [1.4.5-dev] — unreleased

### HUD — Panel anchor fully follows toggle position (8-anchor support)

After 1.4.4-dev landed the side-switch button on screen, live testing revealed two related issues:

1. **Drag-to-dock left the panel anchor stale.** When the operator dragged the toggle from right to left, the panel kept hanging off the right edge — the side update only ran at server-render time, not on drag-snap. Side-switch button after a drag became inert because it thought the panel was already on the side it wanted to flip to.
2. **Center anchors had no panel-position handling.** A toggle at `bottom-center` or `top-center` should put the panel above or below the toggle (gravitating to the closer screen edge), but 1.4.4-dev's `data-side` attribute only spoke "left/right" and forced center-anchored toggles to default to right-side panel position.

Both issues collapse into one architectural fix: panel anchoring is now driven by an 8-position `data-anchor` attribute on the HUD root, which mirrors the toggle's full dock position. Single source of truth, no separate side state to keep in sync.

**`data-anchor` replaces `data-side` as the panel's anchoring driver.** The attribute carries the full toggle position string (`bottom-right`, `top-center`, `middle-left`, etc.) and CSS resolves both the horizontal and vertical anchoring axes from it via attribute-end-suffix and attribute-start-prefix selectors:

- `[data-anchor$="-right"]` → panel anchors to right edge
- `[data-anchor$="-left"]` → panel anchors to left edge
- `[data-anchor$="-center"]` → panel horizontally centered (`left: 50%` + negative margin equal to half panel-width)
- `[data-anchor^="top-"]` → panel hangs **below** the toggle (`top: ...`)
- `[data-anchor^="middle-"]`, `[data-anchor^="bottom-"]` → panel hangs **above** the toggle (`bottom: ...`)

So a `top-center` toggle now puts a horizontally-centered panel just below the toggle. A `bottom-center` toggle puts a horizontally-centered panel just above. Right-side toggles get right-anchored panels, left-side toggles get left-anchored panels — all eight dock positions resolve to a sensible panel placement.

**`applyDockPosition()` is the single hub** that updates everything: toggle position, panel anchor, side-switch button target side + chevron icon. Drag-to-dock and explicit side-switch clicks both flow through this method, so the post-condition is always consistent regardless of how the position changed.

The post-pointerup refresh means panel anchor only changes on drag-end, not during the drag itself — no mid-flight panel jumps. The toggle slides to its drop point, then both toggle and panel snap to the new anchor at once.

**Side-switch button hides at center anchors.** Center has no horizontal opposite to flip to — the button has nothing meaningful to do, so showing it would be deceptive UX. CSS rule `.cb-hud[data-anchor$="-center"] .cb-hud__side-switch { display: none; }` handles the hiding; operators can still drag-to-dock to move the panel anywhere.

**`derive_side_from_position()` retained** as a public helper but no longer called from the render path. It's kept because external code or future features might still want a simple left/right read of the current position; removing it would be a needless API breakage.

**Files touched**: `src/HUD/HUD.php` (data-anchor attribute, removed unused $side variable assignment), `assets/css/components/hud.css` (anchor selectors for both axes + side-switch hide), `assets/js/features/hud.js` (applyDockPosition extended, refreshSideSwitchButton extracted, switchSide simplified). No schema, no REST, no breaking changes — all behavior changes are purely client-side.

---

## [1.4.4-dev] — unreleased

### HUD — Comments-conditional, all canonical sections collapsible, side-switch button

Three workflow improvements after live testing on multiple sites — comments-disabled sites declutter, the operator can collapse sections they don't actively use, and the panel can move to whichever side of the screen suits the operator's workflow.

**Comments item — only shown when comments are actually in use.** Many CB-managed sites disable comments entirely (default closed for new posts, no historical comments, no pending moderation). On those sites the Comments shortcut was dead real-estate. Now the item is hidden when ALL three of these signals are cold: `default_comment_status === 'closed'`, `wp_count_comments()->total_comments === 0`, and pending moderation queue is 0.

Filter `cb_core_hud_show_comments` overrides the auto-detection in either direction — return true to force-show, false to force-hide. Useful for multisite uniformity, or for sites that disable comments via a plugin where `default_comment_status` may still report 'open' but the UI is suppressed elsewhere.

**Three canonical sections are now collapsible.** `cb-content`, `cb-site`, and `cb-core` all respect `register_section()`'s `$collapsible = true` flag (default expanded). The infrastructure was already in place from 1.4.0-dev for the Display section; this just turns it on for the three role-natural sections too.

State persists per-section in browser localStorage (`cb_core_hud_section_<id>_collapsed`) — operators who collapse SITE because they only do content work get to keep that arrangement across page loads. The collapse uses the same grid-rows trick from 1.4.3-dev so the animation is smooth, not snappy.

Default expanded matches operator expectation: opening HUD is meant to navigate, not to find content hidden behind clicks. Operators opt into collapsing.

**Side-switch button — panel can move to the opposite screen edge.** New footer at the bottom of the panel (32px tall) with a single chevron-button positioned in the corner OPPOSITE to where the panel currently is. When the panel is on the right, the button sits in the lower-LEFT corner with a left-pointing chevron — pointing toward where the panel will move after click. The button itself shows the destination; no tooltip needed to figure out direction.

**Single source of truth: panel side derives from toggle position.** Rather than introducing a separate `panel_side` setting that could drift out of sync with the toggle's dock anchor, the panel side is computed server-side via `derive_side_from_position()` from the existing toggle position. Right-side toggle anchors → panel right; left-side anchors → panel left; center anchors default to right.

The side-switch button effectively flips the toggle's horizontal anchor: a `*-right` position becomes the corresponding `*-left` (and vice versa). Center anchors (`top-center`, `bottom-center`) have no horizontal info to preserve, so they explicitly move to `bottom-{target}` — predictable behaviour: button + panel both end up at the bottom-target side.

Implementation reuses the existing dock-position infrastructure (drag-to-dock from sessie 1, REST persistence at `/hud/position`) — no new datamodel, no new REST endpoint, no new user_meta key. JS reads the button's `data-target-side`, calls `applyDockPosition()` and `persistPosition()`, then updates the panel's `data-side` and the button's chevron in-place so the change is reflected without a page reload.

**Translation strings**: "Move HUD to the %s", "left", "right". 1322 nl_NL translated messages (was 1319).

**Files touched**: `src/HUD/Registry.php` (collapsible flags + comments detection helper), `src/HUD/HUD.php` (derive_side helper, data-side attribute, footer markup), `assets/css/components/hud.css` (panel side anchors, footer + side-switch styling), `assets/js/features/hud.js` (switchSide method + chevron updater). No schema changes, no REST changes, no breaking changes — purely additive.

---

## [1.4.3-dev] — unreleased

### HUD — Switch wrapper, fixed panel height, smooth section transitions

Three focused fixes after 1.4.2-dev live testing.

**Ghost-toggle switch — wrapper pattern, finally bullet-proof.** The 1.4.1 → 1.4.2-dev attempts to defeat WordPress's `wp-admin/css/forms.css` declarations with `!important` worked in some admin contexts but not others. WP's specificity for input-checkbox rules can stack higher than ours in admin themes that load custom forms.css overrides — the track kept collapsing intermittently and the thumb kept escaping.

This release switches to the wrapper pattern: the native `<input type="checkbox">` is now visually hidden (sr-only-style: absolute, opacity 0, width/height 0) but stays fully functional for state, keyboard accessibility, and the `data-cb-hud-ghost-toggle` JS handler. The track and thumb are real `<span>` elements with their own classes (`cb-hud__switch`, `cb-hud__switch-thumb`) which WordPress's forms.css can't reach via any selector. State syncs through the sibling-selector `input:checked ~ .cb-hud__switch`.

The thumb's translate distance is computed via `calc(30px - 13px - 2px - 2px)` — track-width minus thumb-width minus border (2px) minus inset (2px) — so the math stays correct if the track size is ever tweaked. No more magic number to keep in sync with the dimensions.

JS hookup unchanged: the input still carries `data-cb-hud-ghost-toggle`, the existing event handler still finds it via that selector. No JS changes.

**Fixed panel height — content scrolls inside, panel doesn't grow upward.** Previous `max-height: calc(100vh - 120px)` let the panel grow when sections expanded. Because the panel is anchored to its `bottom` position, growing meant the top edge moved upward — visually disorienting when expanding the Display section sent everything else above sliding up.

Replaced with `height: min(640px, calc(100vh - 120px))`. The 640px target comfortably fits an Operator's full panel (Status strip + Prefs + 3 collapsible sections + Content + Site + Core Blueprint sections + Hub items) without scroll, while the `min()` clamp prevents the panel from exceeding the viewport on shorter screens. Content beyond 640px scrolls inside the body via the thin scrollbar from 1.4.2-dev.

The panel now feels like a fixed surface with content moving inside, rather than a container that resizes around its content. Behaviour matches user expectations from desktop apps, IDE panels, browser DevTools — anywhere the chrome is stable and the contents shift.

**Smooth section collapse via grid-rows trick.** Previous collapse used `display: none` on the section content — instant snap, no animation. Replaced with the modern `grid-template-rows: 1fr ↔ 0fr` pattern: the section becomes a 2-row grid (header + content), collapsed sets the content row to 0fr, and the transition animates the row dimension cleanly without needing to know the content's intrinsic height (which is the classic problem with max-height-based collapse animations).

Browser support: Chrome 117+, Firefox 120+, Safari 17.4+ — all 2024+ baseline. On older browsers the section still works (grid declaration is valid CSS), just snaps without the smooth transition. Acceptable graceful degradation since collapse is a click-once interaction.

Transition timing: 280ms with the cubic-bezier(0.2, 0.7, 0.3, 1) easing curve already used elsewhere in the panel — slight over-acceleration at the start, decisive settle at the end.

`overflow: hidden` and `min-height: 0` on the content area allow it to collapse below its intrinsic size (the latter overrides the implicit `min-height: auto` that grid items get by default).

**Two files touched: `src/HUD/HUD.php` (switch markup) and `assets/css/components/hud.css` (~140 lines refactored across switch wrapper, panel height, section collapse). No JS, no schema, no REST.**

---

## [1.4.2-dev] — unreleased

### HUD — Two CSS fixes after 1.4.1-dev live testing

**Ghost toggle thumb stays inside the track.** WordPress's `wp-admin/css/forms.css` declares `input[type="checkbox"] { width: 16px !important; ... }` at high specificity. The 1.4.1-dev rule had `width: 30px` without `!important`, so on admin screens where `forms.css` cascaded later, the track collapsed back to 16px while the thumb still translated 13px — leaving the thumb hanging visibly outside the track on its checked state.

Fix: added `!important` to `width`, `height`, `min-width`, plus `box-sizing: border-box !important`. The thumb's translate distance now uses `calc(30px - 13px - 2px)` so the math stays robust if the track size is tweaked later — no separate magic-number to keep in sync.

**Modern thin scrollbar replaces the default OS scrollbar.** The default Windows/Chrome scrollbar (~16px wide, light grey thumb on a sunken track) clashed visually with HUD's dark surface — at admin pages with a long content section the panel scrolled and the chunky scrollbar dominated visually.

Replaced with token-aware thin scrollbar: 6px wide, transparent track, thumb in `--cb-border` (subtle grey-on-dark), thumb lifts to `--cb-text-muted` on hover. Both Firefox (`scrollbar-width: thin` + `scrollbar-color`) and WebKit/Chromium (`::-webkit-scrollbar*` pseudo-elements) covered. Safari supports both APIs so it works across all major browsers without JS.

Track stays transparent so the scrollbar disappears entirely when the content fits without scrolling — no visual weight when not needed.

**Two CSS rules touched in `assets/css/components/hud.css`. No PHP, no JS, no markup, no schema changes.**

---

## [1.4.1-dev] — unreleased

### HUD — UX polish: user link, switch toggle, distinguishable section states

Three observation-driven refinements after the 1.4.0-dev release was tested live on Hub + Beacon sites.

**Header user link replaces the static "Operator Layer" eyebrow.** A clickable mini-Gravatar (16px circular avatar) plus the user's display name now sits where the diegetic "OPERATOR LAYER" label was. Click navigates to `/wp-admin/profile.php` via `get_edit_profile_url()`. Brand title (e.g. "Core Blueprint") stays directly underneath as the panel identifier.

Mirrors the WP admin bar pattern (Howdy, X + avatar top-right) but lives in HUD where operators actually focus their attention. Two values delivered: identity confirmation ("which account am I in right now?") and quick profile-edit access without hunting for the admin bar. Defensive fallback to the eyebrow if user resolution fails — should never trigger in production since HUD only renders for logged-in users, but covers unusual render contexts.

Avatar rendered at 32px source-resolution but displayed at 16px so it stays crisp on retina. Display name has 180px max-width with overflow-ellipsis so unusually long names don't break the header layout.

New translatable string: "Edit profile of %s" for the link's aria-label.

**Ghost button — iOS-style switch replaces the native checkbox.** The previous WordPress checkbox styling fought visually with HUD's dark surface — checkmark glyph in light fill on a dark background, plus WP admin-bar's box-shadow + border treatments that didn't match the rest of the panel.

Replaced with a CSS-only switch: 30×17px pill-shape track, 13×13px circular thumb, 200ms ease-out transition on toggle. Track recolors to accent on checked; thumb slides 13px right on the same animation curve. Hover lifts the thumb's color from muted to full white as a touch-affordance hint.

`appearance: none !important` plus `box-shadow: none` zeroes out WordPress admin styles. The `:checked::after` glyph that WP injects is also nulled to prevent a stray checkmark inside our thumb.

**Section header states — three clearly distinguishable visual states.**

The Display section's collapsed/expanded states were too subtle on testing: a tiny rotating caret was the only signal. Operators couldn't see at a glance whether a section was open or closed, and the click-affordance was nearly invisible.

Three states now read distinctly:

- **Default**: muted caret + muted uppercase title, transparent background. Reads as "this is a label" with subtle hint of interactivity.
- **Hover**: light surface-tint background extending to the section edges (via negative horizontal margin), title + caret lift to full text color. Reads as "I'm clickable, click me".
- **Expanded**: accent-tinted background (8% accent over surface, 14% on hover), caret + title both render in the accent color. The caret rotation (90°) still happens but the colour change is the dominant signal — a section being open reads from across the panel.

Focus-visible adds an outlined ring + the hover background, so keyboard users get the same affordance signaling.

Negative horizontal margin on the header (`-8px`) pulls the hover/expanded background out to the section's outer edge, making the header feel like a full-width tappable surface rather than an indented label. Standard pattern for nested-padding layouts; CB Suite hadn't used it yet.

**No data changes. No REST changes. No item-shape changes.** Two files touched: `src/HUD/HUD.php` (header markup) and `assets/css/components/hud.css` (~80 lines added/changed across user-link, switch toggle, and section-header rules).

---

## [1.4.0-dev] — unreleased

### HUD subsystem — Sessie 5: role-natural panel layout

Major UX refactor of the HUD panel. Three role-natural canonical sections replace the older mixed structure (quick-actions / wordpress / integrations / status / display). The panel now visually mirrors the user's role at a glance: editors see only one section, admins see two, operators see three. Capability gating drives all visibility — no role-detection logic, just the right capability per item.

**The three canonical sections:**

- **`cb-content`** (CONTENT, order 10) — Editor's territory: Posts, Pages, Media, Comments. Visible to anyone with `edit_posts` and friends.
- **`cb-site`** (SITE, order 20) — Admin's territory: Plugins, Themes, Users, Tools, Settings. Items gated on admin caps (`activate_plugins`, `manage_options`, etc.).
- **`cb-core`** (CORE BLUEPRINT, order 30) — CB Operator's territory: CB Dashboard plus everything sibling plugins (Hub, future Invoice) layer in. Items gated on CB-specific caps (`cb_view_permissions`, etc.) so plain admins without those caps don't see operator surfaces. CB is "for operators, by operators" — admins who want CB access need the explicit toggle.

Legacy sections (`quick-actions`, `wordpress`, `integrations`, `status`, `display`) remain registered for backwards compat with items registered in sessies 1-4 and by sibling plugins that haven't migrated yet. They render in interleaved positions between the canonical sections — partners can migrate to canonical sections at their own pace.

### WordPress entry points — completed coverage

The WordPress section now ships all 9 standard entry points, split between `cb-content` (Posts/Pages/Media/Comments) and `cb-site` (Plugins/Themes/Users/Tools/Settings). Each item is capability-gated to its WordPress equivalent (e.g. Posts requires `edit_posts`, Plugins requires `activate_plugins`, Settings requires `manage_options`). An editor logged into a clean WP install now sees only Content items in HUD; an admin sees Content + Site; an operator sees the full panel.

### `[+]` quick-action shortcuts on items

Items can now declare an optional `quick_action_url` + `quick_action_label`. When present, a small `[+]` button renders inline at the right end of the row. The row's primary link goes to the section's index page (e.g. `/edit.php` for Posts); the `[+]` jumps directly to the create surface (e.g. `/post-new.php`). Operators who want to compose a new post don't navigate through the index page first — one click, straight to the editor.

Quick actions ship on:
- Posts → post-new.php
- Pages → post-new.php?post_type=page
- Media → media-new.php
- Plugins → plugin-install.php
- Themes → theme-install.php
- Users → user-new.php

Items without a natural "create" affordance (Comments, Tools, Settings) render without the `[+]`. Item shape extended with two new optional fields; backwards compat with items that don't declare them — the renderer skips the action button.

**Accessibility**: the `[+]` is a separate `<a>` element next to the primary link, not nested inside it. Two distinct interactive elements per row, both keyboard-reachable via Tab. Aria-label on the action defaults to "Add new {label}" when not explicitly set.

### Mode switcher — relocated to header

Plain/Technical/Sync segmented control now lives in the panel header (right of the title, left of the close button) at compact 84px width with single-letter labels (P/T/S) plus tooltips with full names. Previously occupied a full-width 60px row directly under the header — visually dominant for a control that should be present-but-not-loud.

The mode-toggle behavior is unchanged: click flips local `.cb-core-dual` blocks immediately + POSTs to `/hud/mode` to persist via `\CB\Core\UI::set_user_mode()`.

### Status strip — replaces the tile grid

System Status used to render as a 2-3 tile grid taking ~120px vertical real-estate with WP Version / PHP Version / Plugins Active. Now renders as a single horizontal strip directly under the header (between header and prefs row), ~30px tall, with thinner pills separated by hairline dividers. Same data, ~75% less space.

Strip renders only when at least one stat is visible to the current user — editors who can't see the updates count don't get an empty strip.

Stats now include:
- WP version (always-on)
- PHP version (always-on)
- **Updates count** — new! Pulled from `wp_get_update_data()`, gated on `update_core` capability. Most actionable signal in the strip — operators can see at a glance whether the site needs attention without opening the Updates page.

`Plugins Active` removed from default stats — interesting trivia but rarely actionable. Sibling plugins can register their own stats via the existing `cb_hud_register_items` hook (Hub registers Sites / Score / Alerts; future siblings layer their own).

### Display section — collapsible, default collapsed

Brand picker + theme picker stay in the panel for now (will move to a settings drawer in a later session) but the section is now collapsible and defaults to collapsed. Click the section header (with caret prefix) to expand. State persists in browser localStorage (`cb_core_hud_section_display_collapsed`) — per-browser rather than cross-device because section preferences are device-contextual.

A specialist would normally argue against persistence (it makes new content invisible to users who collapsed a section). Acceptable here because the Display section content is fixed at build-time — brand/theme registry doesn't gain new entries without a deliberate developer action.

### `cb_hud_register_sections` action — new public extension hook

Partners that need their own section (rare — most should use canonical sections) can hook the new `cb_hud_register_sections` action. Fires before `cb_hud_register_items` so partner items targeting partner sections register cleanly. Receives the Registry class name as the action arg, matching the existing `cb_hud_register_items` pattern.

The conventional path is still: register items into a canonical section. New section is for genuine extension cases (Hub's hypothetical future "Fleet" listing group, Invoice's "Clients" list, etc. — none of which exist yet but the door is open).

### Section-collapse infrastructure

`Registry::register_section()` extended with two new optional params:
- `$collapsible` (bool, default false) — whether the section can be collapsed by the user
- `$collapsed_default` (bool, default false) — initial state when no localStorage preference exists

Collapsible sections render with a clickable header containing a caret + label + invisible toggle button. JS layer wires the toggle to flip `.is-collapsed` class + `data-collapsed` attribute + `aria-expanded` on the button + persists state to localStorage. Visual change happens via CSS reading these signals (rotation of caret, hide content area).

Reduced-motion handling: caret rotation respects `prefers-reduced-motion: reduce` (no transition, instant flip).

### Typography & spacing pass

- 4px vertical grid throughout
- 4-level type hierarchy (section header / item title / item description / stat label-value pair) with distinct sizes (9px, 10px, 11px, 12px) and weights
- Item rows reduced from ~70px to ~36px (label + optional description), letting more content fit before scrolling
- Section headers reduced from 16px+8px padding to compact 8px, dividers softened from full borders to ~1px subtle separators
- Header reduced from ~80px to ~52px tall — eyebrow + title + close button + mode-switcher all fit in one tight row

Net effect: the panel that previously rendered ~600px of content for an admin user now renders ~380px in the same screen with more items visible.

### Item shape — backwards compat preserved

Existing items registered with the old shape (no `quick_action_url`, no canonical-section ids) keep working unchanged. Renderer skips the `[+]` button for items without `quick_action_url`. Sections like `quick-actions`, `wordpress`, `integrations` still exist — items targeting them still render. Sibling plugins can migrate to the new `cb-content` / `cb-site` / `cb-core` sections at their own pace; no forced migration.

**Bundle**: same 12 PHP files + 1 CSS + 1 JS module as sessie 4. CSS grew (~400 added lines for new layouts), JS grew slightly (~50 lines for section-collapse). PHP file count unchanged.

**No data migration. No schema changes. No REST changes.** All wiring purely additive.

---

## [1.3.40-dev] — unreleased

### HUD — Restore canonical Core Blueprint launcher logo

The CoreBlueprint brand's SVG ships the actual Core Blueprint icon — the gradient roundel (teal `#00FFDD` → blue `#0037FF`) with the stylised C-mark in brand-dark `#131648`. Earlier sessies shipped a placeholder "blueprint grid + anchor" mark that was constructed during the brand-abstraction sketch but never matched the real brand identity; this release replaces it with the canonical glyph from Core Blueprint's design system.

Both the static and animated variants now use the canonical mark. The animated variant adds a subtle 3.2-second `filter: brightness/saturate` shimmer on the gradient roundel — gives a "live" feel without being attention-grabbing. The C-letter stays still; animating it would distort the brand glyph.

**SVG-internal id collisions avoided.** The two variants use different ids for their gradient (`cb-brand-cb-grad` vs `cb-brand-cb-grad-anim`) and mask (`cb-brand-cb-mask` vs `cb-brand-cb-mask-anim`) defs. The original Figma export used short ids like `paint0_linear_72_2` which were hard to debug in dev tools and risked collision with other inline SVGs on the same page; the renamed ids are namespaced and explicit.

**Sanitiser verified lossless on canonical mark.** `HUD::sanitize_logo_svg()` strips `<script>`, `on*` handlers, and `href="https?:..."`/`xlink:href` external references — none of which the real CB logo uses. Smoke test confirms: 3463 bytes in, 3463 bytes out, byte-identical.

### Toggle button styling — let the logo carry the visual weight

When `[data-brand="core-blueprint"]` is the active brand, the launcher button drops its border and background. The brand glyph IS a gradient roundel — wrapping it in another ring would make the launcher look like "logo inside a button" rather than "logo as button". The CB icon now fills the full 46px launcher; the box-shadow stays (depth + drop-shadow ground the button on top of page content) but the redundant chrome is gone.

Other brands (Achterhood, white-label) keep the default chrome — their glyphs are simpler `currentColor`-driven shapes that need the button frame for separation from page content. The styling distinction is brand-scoped via `.cb-hud[data-brand="..."]` selectors so each brand can opt in to "glyph-as-button" or "glyph-in-button" without affecting the others.

**No new files, no schema changes, no REST changes.** Two files touched: `src/HUD/Brand/CoreBlueprint.php` (logo SVGs) and `assets/css/components/hud.css` (brand-specific toggle override).

---

## [1.3.39-dev] — unreleased

### HUD subsystem — Sessie 3: Plain/Technical mode toggle + status stats + keyboard shortcut

The "compensate WordPress shortcomings" philosophy gets its central affordance: a Plain/Technical/Sync mode toggle in HUD's panel header, alongside three baseline status stats and a global keyboard shortcut to open/close HUD from anywhere.

**Mode segmented control:**

Three-button segmented control directly under the HUD panel header — most prominent placement in the panel because mode is the central driver of CB Suite's progressive-disclosure philosophy. Buttons render as a `role="radiogroup"` with each button as a `role="radio"`; the active mode is highlighted with the accent token as background and `--cb-on-accent` as text colour.

Click handler proxies to the existing `\CB\Core\UI::set_user_mode()` (the same persistence path used by the Preferences › Language description-mode UI) via the new `/hud/mode` REST endpoint. No state divergence between "set via HUD" and "set via Preferences"; both paths write to user_meta `cb_core_description_mode`.

**On-page apply (no reload):**

When the mode changes via HUD, the JS layer immediately flips every `.cb-core-dual` block currently rendered on the page:

- Sets `data-active="{mode}"` on each block
- Toggles `hidden` on `.cb-core-desc-plain` and `.cb-core-desc-technical` children to show only the variant matching the new mode (`plain` or `technical`; `sync` defaults to `plain` server-side and the per-block peek toggle takes over)
- Sets `data-cb-desc-mode` on `<html>` so other consumers (CSS rules, sibling JS modules) can react

This mirrors what `features/description-toggle.js` does for per-block peek toggles, but applied across every dual block at once. Same optimistic-local-apply + revert-on-failure pattern as brand/theme switching.

**REST endpoint added:**

```
POST /core-blueprint/v1/hud/mode
Body: { mode: "plain" | "technical" | "sync" }
Response: { ok: true, mode: <slug> } or WP_Error
```

The `enum` constraint on the `mode` arg (declared at route-registration time) handles validation server-side; invalid values return HTTP 400 from the REST framework before the handler runs.

**Status section — three baseline stats:**

The previously-empty "System Status" section now renders three always-available environment stats:

- **WP Version** — `$wp_version` global, `—` fallback if unavailable
- **PHP Version** — `PHP_VERSION` constant
- **Plugins Active** — count of entries in `active_plugins` option, gated on the `activate_plugins` capability so non-cap users don't see the count

These are deliberately the simplest possible stats — values that always exist on every WordPress install, with no dependencies on subsystem availability. Hub / Invoice / future siblings layer richer status items (Beacon connection state, sync recency, fleet health) via the existing `cb_hud_register_items` hook in subsequent sessions.

Stats render in the System Status section's grid layout (`grid-template-columns: repeat(auto-fit, minmax(120px, 1fr))`) — three stats fit cleanly in a row on the 360px panel; sibling-registered additions wrap to a second row.

**Keyboard shortcut:**

`Ctrl+Shift+H` (Windows/Linux) or `Cmd+Shift+H` (macOS) opens or closes HUD from anywhere on the page. Modifier combination chosen to avoid conflict with browser-reserved chords (`Ctrl+H` is browser history, `Ctrl+Shift+H` is rarely bound).

`preventDefault()` runs only when the chord matches, so the shortcut doesn't interfere with any other keyboard handlers on the page. Esc-to-close (already in sessie 1) continues to work.

**Bundle:** still 12 PHP files + 1 CSS + 1 JS module + now 5 REST endpoints (was 4: position/ghost/brand/theme/mode).

**Translation:** 1297 nl_NL strings (was 1292). 8 new strings registered, 3 reused existing translations (Plain/Technical/PHP Version already in PO from earlier subsystems).

---

## [1.3.38-dev] — unreleased

### HUD subsystem — Sessie 2: brand picker + animated theme switcher

The "front door" launcher gets its first real operator-surface affordance: a Display section at the top of the HUD panel with two pickers — Brand and Theme. Both apply changes instantly client-side and sync to the server asynchronously, so switching feels immediate without waiting for a page reload.

**Brand picker:**

Renders every registered brand as a card with logo + label + up-to-three palette swatches. Two brands ship out of the box from sessie 1 (CoreBlueprint = available, Achterhood = coming-soon); the picker handles both states:

- **Available brands** render as `<button>` cards with `role="radio"`, `aria-checked` reflecting active state, and a click handler that POSTs to `/hud/brand` and flips `<html data-cb-brand="...">` to activate the brand's palette
- **Coming-soon brands** render as `<div>` cards with `aria-disabled="true"`, reduced opacity, and a "Coming Soon" badge. Click is a defensive no-op (the JS listener checks `data-brand-status` and returns early); the REST endpoint also rejects coming-soon brand ids with HTTP 409 as a server-side defence

The active brand card highlights with the accent border + soft accent-tinted background; hover/focus states use color-mix to layer accent over the card's surface for visual feedback without committing to a hardcoded hover colour.

**Theme picker:**

Renders every theme registered via `Themes::all()` as a card with a mode-flavoured swatch (3-band miniature: background + accent + text colour) and the theme label. Currently shows the two built-in CB themes (Core Blueprint Dark, Core Blueprint Light); third-party themes registered via the existing `cb_admin_themes` filter appear automatically without HUD-side changes.

Clicking a theme card POSTs to the new `/hud/theme` endpoint, which proxies to `Themes::set_user()` — the same persistence path used by the Preferences › Appearance page. HUD doesn't own theme state; it's a quick-access UI on top of the existing Themes subsystem, so there's no divergence between "theme set via HUD" and "theme set via Preferences".

**Animated transition (300ms colour cross-fade):**

Theme switches use a transient `cb-theme-transitioning` class on `<html>` that adds `transition: background-color 300ms, color 300ms, border-color 300ms, fill 300ms, stroke 300ms, box-shadow 300ms !important` to every element on the page (`html.cb-theme-transitioning *` selector). The class is added immediately, the `data-cb-theme` attribute is flipped, and the class is removed via setTimeout after 320ms (slightly longer than the transition to ensure cleanup runs after the cross-fade completes).

Removed via setTimeout rather than transitionend listener because the wildcard selector triggers transitions on countless elements simultaneously and waiting for "the last one" is unreliable. 320ms is the conservative ceiling.

`prefers-reduced-motion: reduce` removes the transition entirely — switches happen instantly for users who request reduced motion.

**Brand-palette pre-emission for instant client-side switching:**

`HUD::render()` now emits palette `<style>` blocks for **every registered brand**, not just the active one. Each block is scoped to `html[data-cb-brand="{id}"]`. At any given moment only one block "wins" via the cascade (because `html[data-cb-brand]` matches a single value); the inactive blocks sit dormant.

When the JS layer flips `<html data-cb-brand="x"> → <html data-cb-brand="y">` for a brand switch, the matching style block instantly activates without any runtime CSS injection. This means brand switching is a single DOM attribute mutation — no fetch, no parse, no FOUC.

CoreBlueprint (empty palette) still emits nothing — its "active" state is the absence of overrides, which `tokens.css` already provides.

**REST endpoints — added route:**

```
POST /core-blueprint/v1/hud/theme
Body: { theme: <slug> }
Response: { ok: true, theme: <slug> } or WP_Error
```

Validates against `Themes::is_valid()` (registered slug or `auto`); rejects with `cb_core_hud_unknown_theme` (HTTP 400) for unknown values and `cb_core_hud_save_failed` (HTTP 500) on persistence failure.

**Optimistic local apply with revert-on-failure:**

Both `selectBrand()` and `selectTheme()` follow the same pattern:

1. Capture the previous value
2. Apply locally (instant visual feedback — DOM attribute flip)
3. POST to REST asynchronously
4. On REST failure: revert local state to previous value + console.warn

The user sees the change immediately and only ever sees a revert if the server actively rejects (rare — the picker UI already gates coming-soon brands and unknown theme slugs). For network failures (offline, REST disabled), the local state stays applied and reasserts on next page load via the server-rendered initial state.

**Accessibility:**

Brand/theme pickers use `role="radiogroup"` + `role="radio"` + `aria-checked` for screen-reader compatibility. Coming-soon brand cards use `aria-disabled="true"` instead of `role="radio"` so assistive tech announces them as disabled, not as another selectable option. Cards are reachable via Tab navigation (`<button>` semantics for available, `<div>` is skipped — intentional).

Out of scope: keyboard arrow-key navigation between cards in a radiogroup. Mouse + Tab work; arrow-key cycling is a polish item for the production-hardening pass.

**No changes to existing user_meta / option keys.** Storage strategy from sessie 1 carries over unchanged. The new theme route reuses `Themes::USER_META_KEY` ('cb_core_theme') so any existing user theme preference shows up correctly in the picker on first load.

**Bundle**: 12 PHP files (unchanged from sessie 1), 1 CSS file (extended), 1 JS module (extended), 4 REST endpoints (was 3).

---

## [1.3.37-dev] — unreleased

### HUD subsystem — Sessie 1: foundation

The floating "front door" launcher arrives in CB Base as a first-class subsystem. Renders on every admin page AND frontend (for logged-in users with the cb_core_hud_use capability), exposes a panel of quick-actions / status / WordPress entry points, and lays the architectural groundwork for the brand-switching, theme-switching, and Plain/Technical mode-toggle features arriving in subsequent sessions.

This is the **foundation** release: HUD works identically to the standalone `core-blueprint-hud` plugin (drag-to-dock, ghost mode, panel open/close) but now lives inside CB Base with the right namespacing, the right hooks, the right storage layer, and the right kill-switch. No new user-facing capabilities yet — those land in sessies 2-4.

**Subsystem layout:**

```
src/HUD/
├── Bootstrap.php                  # Hooks + boot sequence + kill-switch gate
├── Settings.php                   # Defaults + is_enabled() resolution
├── Access.php                     # Capability gate + render-allowed checks
├── Storage.php                    # user_meta primary, localStorage cache
├── Registry.php                   # Sections + items
├── Assets.php                     # CSS + JS module enqueue
├── HUD.php                        # Chrome renderer
├── Brand/
│   ├── BrandInterface.php         # White-label contract
│   ├── BrandRegistry.php          # Resolution chain + fallback
│   ├── CoreBlueprint.php          # Built-in default brand
│   └── Achterhood.php             # Built-in coming-soon stub (preview only)
└── Rest/
    └── HUDController.php          # POST endpoints under core-blueprint/v1/hud/*
```

Namespace: `CB\Core\HUD\` — matches the established subsystem pattern (Beacon, Reports, Permissions, Integrity, Notes). Bootstrap call lands in `Core::init()` between the Notes bootstrap and the `cb_core_booted` signal.

**Kill-switch — non-negotiable safety valve:**

`cb_core_hud_enabled` filter + `cb_core_hud_disabled` site option. Either gate flipping makes `Settings::is_enabled()` return false and `Bootstrap::boot()` returns silently before registering any hooks, REST routes, or render handlers. Equivalent to the subsystem not being loaded at all. Surfaces as a developer-level mu-plugin override (`add_filter('cb_core_hud_enabled', '__return_false');`) and — in a later release — as a checkbox in Preferences › Appearance. State (position / ghost / brand) survives kill-switch use; flipping back on restores everything.

**Brand abstraction (white-label-ready):**

`BrandInterface` is the contract — id, label, status, logo SVG (static + animated variants), palette token overrides, plain/technical description pair. Two brands ship built-in:

- **CoreBlueprint** (id `core-blueprint`, status `available`, default) — neutral baseline, empty palette so CB Base default tokens drive the look. Logo is a blueprint-grid + anchor mark in inline SVG.
- **Achterhood** (id `achterhood`, status `coming-soon`, preview-only) — fan-made tribute brand for the Achterhoek region. Regio-evocative palette (deep river-blue accent, harvest-amber warning, landscape-green success, warm cream surfaces) without any literal flag iconography. Logo is an abstract river-curve glyph referencing the Berkel/Slinge waterways. Selectable in the brand picker as preview only — clicking the entry while status is `coming-soon` returns HTTP 409 from the REST endpoint.

White-label plugins extend by implementing BrandInterface + registering on the `cb_core_register_brands` action. The `BrandRegistry::current()` resolution chain is: per-user override (user_meta `cb_core_active_brand`) → site default (`cb_core_default_brand` option) → CoreBlueprint fallback. The fallback chain ensures brand lookup never returns null even if every site default points at a deactivated brand plugin.

**Storage strategy:**

User_meta is source of truth (cross-device, authoritative); browser localStorage is an immediate-write cache so position/ghost changes feel instant without waiting for the REST roundtrip. The JS layer writes to localStorage synchronously on every change, then POSTs to REST asynchronously. Failed REST calls are non-fatal — localStorage keeps driving the UI until next page load reasserts server truth.

Storage keys:
- `user_meta cb_core_hud_position` — one of 8 dock anchor strings, falls back to site default `bottom-right`
- `user_meta cb_core_hud_ghost` — `'1'`/`'0'`, falls back to site default `false`
- `user_meta cb_core_active_brand` — registered brand id, falls back to site default `core-blueprint`

**REST endpoints under `core-blueprint/v1/hud/*`:**

- `POST /position` — body `{ position }`, requires cb_core_hud_use capability. Validates against the 8-anchor allowlist; rejects unknown values with HTTP 400.
- `POST /ghost` — body `{ ghost: bool }`, requires cb_core_hud_use.
- `POST /brand` — body `{ brand_id }`, requires cb_core_hud_use. Rejects unregistered brand ids with HTTP 400 (defensive: brand plugin could deactivate between picker render and click). Rejects coming-soon brands with HTTP 409 (defends server-side even though JS picker should already gate).

All endpoints use the standard X-WP-Nonce header. Permission failures return 401/403; validation failures return 400 with machine-readable error codes (`cb_core_hud_invalid_position`, `cb_core_hud_unknown_brand`, `cb_core_hud_brand_unavailable`, etc.) so the JS layer can decide retry behaviour vs surfacing the error.

**CSS migration — token-based, theme-aware:**

The standalone `core-blueprint-hud` plugin's CSS used its own `--cb-hud-bg`, `--cb-hud-accent`, etc. variables defined at `:root`. That meant HUD looked visually disconnected from CB Base's themes (cyberpunk, dark, light). The migration replaces every `--cb-hud-*` reference with the corresponding CB Base token (`--cb-surface-1`, `--cb-accent`, `--cb-border`, `--cb-text`, etc.) so the launcher now adopts whatever theme + brand is active. Brand palettes apply via inline `<style id="cb-hud-brand-palette-{id}">` blocks emitted by `HUD::emit_brand_palette()`, scoped to `html[data-cb-brand="{id}"]` so previewing another brand in the picker doesn't flip the live palette.

`components/hud.css` joins `disclosure.css` in the foundation enqueue list — automatically loaded on every CB Base admin page. For frontend rendering and third-party admin pages where the central enqueue chain doesn't fire, `Assets::do_enqueue()` ensures the same stylesheet + JS module load via the dedicated admin/wp_enqueue_scripts hooks.

**JS modernisation — ES module, no wp_localize_script:**

The standalone HUD plugin used the legacy `wp_enqueue_script` + `wp_localize_script` API; this version uses `wp_enqueue_script_module` + the `script_module_data_@cb-core/hud` filter. JS reads server data from `<script type="application/json" id="wp-script-module-data-@cb-core/hud">`. Module id `@cb-core/hud`, dependency-free.

**Capability mapping:**

`cb_core_hud_use` is the new capability that gates HUD visibility. Auto-mapped to users who hold `manage_options` via the `user_has_cap` filter (`Access::grant_capability()`), so role administrators don't need to manually configure it. White-label plugins can lower the cap to a less-privileged value (e.g. `edit_posts`) via the `cb_core_hud_capability` filter to expose HUD to broader teams.

**Frontend rendering — gated:**

HUD renders on the public-facing site for logged-in users with the capability. Anonymous visitors see nothing — the render handler short-circuits before emitting any HTML. The `cb_core_hud_excluded_post_types` filter accepts an array of post-type slugs to suppress HUD on specific singular views (default empty — operators tend to want HUD reachable everywhere they're working).

**Bundled non-CB integrations: dropped from CB Base.**

The standalone HUD plugin shipped Bricks / ACF / Wordfence / FluentSMTP / AIO Migration / SeoPress integrations as PHP classes inside the plugin source. Those are out-of-scope for CB Base — Base doesn't need to know that Bricks exists. Sibling/third-party plugins that want quick-actions in HUD register them via the public `cb_hud_register_items` action. A separate "CB HUD Connect" companion plugin can ship the third-party integration suite later as opt-in install.

**Public APIs for siblings:**

```php
// Register a brand (white-label):
add_action( 'cb_core_register_brands', function ( string $registry ): void {
    $registry::register( new MyCustomBrand() );
} );

// Register HUD items (Hub, Invoice, etc.):
add_action( 'cb_hud_register_items', function ( string $registry ): void {
    $registry::add_item( [
        'id'         => 'cb-hub-add-site',
        'label'      => __( 'Add Website', 'core-blueprint-hub' ),
        'section'    => 'quick-actions',
        'url'        => admin_url( 'admin.php?page=core-blueprint-hub#add' ),
        'capability' => 'manage_options',
    ] );
} );
```

**Out of scope this release (lands in subsequent sessions):**

- Plain/Technical mode toggle widget inside HUD (sessie 3)
- Animated theme switcher (sessie 2)
- Brand picker UI (sessie 2)
- Live status widgets — Beacon connection, last sync, fleet health (sessie 3)
- Hub items registration (sessie 4)
- Hub module-description dual-variant migration (sessie 4)
- Preferences › Appearance kill-switch checkbox (sessie 2 or 3)
- Keyboard shortcut to open HUD (later polish)
- A11y audit + cross-browser testing (production-hardening pass)

**No data migration.** Fresh install creates user_meta keys lazily on first state change. Existing standalone HUD plugin users (limited audience) lose their localStorage state on switching to the CB Base subsystem — acceptable since HUD reverts to defaults gracefully.

---

## [1.3.36-dev] — unreleased

### Disclosure system: cross-suite consumer API

The dual-description block + Plain/Technical toggle was already a CB Base primitive — `\CB\Core\UI::current_mode()`, `\CB\Core\UI::render_description_block()`, the `@cb-core/description-toggle` script module, and the per-user / site-wide mode storage have been in place since 1.3.0. What was missing was a clean way for sibling plugins (CB Hub, future CB Invoice, CB Access Control, CB Protected Content) to consume that infrastructure on their own admin pages — the CSS lived in `pages/privacy.css` (page-scoped, not loaded on sibling screens) and the JS module was registered only by CB Base's own `enqueue_assets` hook (which short-circuits on non-CB-Base screens).

This release closes that gap so Hub can become the first cross-plugin consumer of the disclosure pattern without parallel infrastructure.

**Three changes, all infrastructure:**

1. **Disclosure CSS extracted to a foundation component.** The dual-description + toggle styling moved from `pages/privacy.css` to a new `components/disclosure.css`. `pages/privacy.css`'s top-level header was updated to point at the new home. `pages/security.css` still carries its own `.cb-core-wrap`-prefixed override copy — that's an intentional page-level fine-tune kept separate from the cross-page baseline.

2. **Foundation enqueue list updated.** `disclosure` was appended to the `$cb_core_css_components_foundation` array in `Admin\Admin::enqueue_assets()`. Every CB Base admin page now loads the disclosure styles globally — Privacy, Security, and any new page that uses `render_description_block()` get it for free without needing a per-page enqueue.

3. **Public sibling-consumer API.** New method `\CB\Core\UI::enqueue_for_sibling_screens()`. Sibling plugins call this from their own `admin_enqueue_scripts` callback (after their own screen detection) and it wires the full disclosure stack on the current admin screen:
   - `cb-core-css-tokens` if not already enqueued (defensive — some siblings load tokens themselves for their own theming)
   - `cb-core-css-disclosure` (the new component)
   - `@cb-core/dom` script module + its data filter (matches the payload CB Base's own enqueue would supply)
   - `@cb-core/description-toggle` script module + its data filter (`descMode.current` from `UI::current_mode()` plus the toggle's i18n labels)
   
   The method is idempotent — `wp_enqueue_*` calls dedupe on handle/id, and the `script_module_data_*` filters use anonymous closures that each apply the same `array_merge` with identical data so duplicate add_filter calls are harmless.

**API contract for siblings.** The method signature is stable from this release forward. Sibling plugins should guard their call with `class_exists( '\CB\Core\UI' )` since CB Base may not be active — they should already be doing this for `current_mode()` calls anyway.

```php
add_action( 'admin_enqueue_scripts', function ( string $hook ): void {
    if ( ! is_my_plugin_screen( $hook ) ) return;
    if ( class_exists( '\CB\Core\UI' ) ) {
        \CB\Core\UI::enqueue_for_sibling_screens();
    }
    // …rest of sibling's own enqueue work…
} );
```

After that, sibling code can render dual descriptions inline:

```php
$mode    = \CB\Core\UI::current_mode();
$variant = ( 'technical' === $mode ) ? 'technical' : 'plain';
echo \CB\Core\UI::render_description_block(
    [ 'plain' => 'Layperson sentence.', 'technical' => 'Operator-grade sentence.' ],
    $variant
);
```

**No translatable strings added.** All i18n labels passed to the JS modules already existed in the POT (used by CB Base's own enqueue), so no PO/MO regeneration was needed beyond the version-line bump.

**No data migration.** State-storage keys (`cb_core_description_mode` user_meta, `cb_core_description_mode_default` option) and shapes are unchanged. Existing user/site preferences continue to apply.

---

## [1.3.35-dev] — unreleased

### Suite-wide layout convention codified

Every logical section within a CB Base admin page now belongs in its own `cb-core-panel` with an h2/h3 as section heading. Page-level actions (Save changes that touches every panel) sit in a free-standing `cb-core-actions` row beneath the panels — not in their own decorative panel. Section-scoped actions (per-panel Save, like the operators-list save in Permissions) live inside the relevant panel's `cb-core-actions` row at the bottom. Going forward, naked sections without panel framing get caught and fixed in the patch they appear in.

### Fixed — Tables-inside-panels with content after them no longer overrun

The 1.3.34-dev rule that absorbed `widefat` tables into their parent panel used a negative bottom-margin to make the table flush with the panel's bottom edge. That worked for panels where the table is the last child (Reports Overview tables, Failsafe layers) but broke panels that have content after the table — most visibly the CB Operators panel in Permissions, where the Save operators button overlapped with the operators-list bottom edge.

The negative-bottom-margin behaviour is now gated behind `:last-child`. Tables that have siblings after them (an actions row, an info paragraph) get a regular bottom-margin instead, with their bottom border restored so the table doesn't visually bleed into whatever follows. Tables that *are* the last child keep the original 1.3.34-dev behaviour: negative bottom-margin, no bottom border, panel's `overflow: hidden` clips the corner. One rule, two contexts, deterministic.

The `cb-core-privacy-table` and `cb-core-operator-list` selectors are added to the table-inside-panel rule list so the new Privacy-tab tables (verbosity, retention) and the Permissions operators table get the theme-aware treatment too.

### Fixed — About page tables get rounded corners + Version column doesn't wrap

The About page's installed-plugins list renders inside a `Card` (variant=spacious), not a `cb-core-panel`. The 1.3.34-dev table-in-panel rule didn't reach it, so the table inside the rounded card kept sharp corners, and the narrow Version column wrapped the version string vertically across multiple lines.

A parallel set of rules in `components/cards.css` mirrors the panel treatment for `Card`s: theme-aware row separators, edge-to-edge fill via negative card-padding, panel-scope `overflow: hidden` via `:has()` so only cards containing tables get the corner-clip. Dedicated rule for `.cb-core-about-table` second column applies `white-space: nowrap` + `font-variant-numeric: tabular-nums` so version strings stay on one line and numerals align cleanly.

### Changed — Privacy tab — five sections wrapped in panels

Governance preset, IP Address Handling, What gets logged, Retention, Estimated storage — all five sections converted from naked `<section class="cb-core-section">` to `cb-core-panel` divs, matching the suite-wide convention from this patch's preamble.

The page-level Save changes button moved from a `<p class="submit">` to a free-standing `<div class="cb-core-actions cb-core-privacy-actions">` row below the panels — it isn't section-scoped, so giving it its own decorative panel would misrepresent its scope. Now it reads visually as "page-bottom action bar" matching every other multi-panel admin surface in CB Suite.

### Added — Governance Preset cards redesign — modern + lightly cyberpunk

The four preset cards (Minimal, Standard, Enhanced, Strict) get a redesign that keeps the option-card pattern but adds technical/futuristic detail without slipping into kitsch:

- **Top-right corner cut** — a 14×14 px diagonal-clipped triangle in the corner that sits in `--cb-surface-2` colour by default and lights up `--cb-accent` when the card is selected. Subtle in light mode, distinctly techy in dark mode where the accent colour reads stronger against the dark surface.
- **Vertical accent bar on selected** — a 2px accent-coloured bar slides in from the left edge of the card on selected-state, with smooth opacity + transform transitions. Reads as a terminal/IDE selection cursor.
- **Soft accent glow on selected** — `box-shadow` with a 12px spread of `--cb-accent` at 30% alpha (light mode) or 40% alpha (dark mode), plus a 0.5px inset accent border to thicken the edge. Bigger glow in dark mode by design — dark surface absorbs more light, so the same alpha looks timid against `#0e1116` and gets bumped.
- **Hover lift** — `transform: translateY(-1px)` + border shifts to `--cb-accent-soft`. Small movement, signals interactivity without theatrics.
- **Hidden radio input** — the entire card is the click target. Native `<input type="radio">` is `position: absolute; clip: rect(0 0 0 0)` so keyboard focus, screen reader semantics, and form submission all keep working — only the visual chrome is custom.
- **Header typography** — preset name in `--cb-fs-md` weight 600 with subtle `0.01em` letter-spacing. Body in muted text colour with relaxed line-height.

The treatment generalises to other card-shaped selectables in the suite: any "pick one of N" pattern where the cards are >120×60px gets the same look. Card-scale glow is reserved for surfaces big enough to absorb it; small controls (checkboxes, radios) get a much subtler glow handled separately below.

### Added — Uniform checkbox + radio styling — modern flat with subtle accent glow

Every native `<input type="checkbox">` and `<input type="radio">` inside `.cb-core-wrap` now picks up shared theme-aware styling from a new `components/form-controls.css`. Replaces three different appearance regimes that had drifted since 1.0:

- Browser default (most places)
- Custom severity-pill wrapper in Notifications
- Custom radio-card wrapper in Privacy

The styling is "modern, clean, lightly cyberpunk in dark mode": square 18×18 px box with `--cb-radius-sm` for checkboxes, perfect circle for radios. Idle state: `--cb-surface-2` background, 1px `--cb-border` border. Hover: border shifts to `--cb-accent` without filling. Checked: filled `--cb-accent`, white SVG check-mark (or accent center-dot for radios), 6px outer glow at 35% accent alpha (50% in dark mode).

Indeterminate state for checkboxes (used in some "select all" patterns) gets its own SVG dash glyph instead of the check-mark. Disabled state desaturates to 50% opacity with `not-allowed` cursor.

Native inputs are kept (rather than swapped for custom widgets) so keyboard accessibility, screen-reader announcements, and form submission all keep working with zero JS. `appearance: none` strips the platform default, then we redraw with CSS.

A `:has()` rule on labels containing checkboxes/radios switches them to `inline-flex; align-items: center` so input + label-text always vertically align — input baseline + text baseline don't always agree across font stacks.

### Internals

- New `components/form-controls.css` registered in the modern components enqueue list (loaded after foundation, before theme overrides). Selector scope is `.cb-core-wrap` so unrelated WP-admin chrome elsewhere stays untouched.
- New `:last-child` variant of the panel-table absorption rule. Two-state behaviour with the same selector list — last-child gets negative bottom-margin + no bottom border, non-last-child gets positive bottom-margin + bottom border restored. CSS specificity is identical between the two, source order resolves the conflict in favour of the more specific case.
- Card-scale glow pattern (used by Governance Preset cards) ready to be promoted to a shared utility once a second consumer needs it. Stays page-local in `pages/privacy.css` for now per rule of three. Token-driven so future callers inherit the same dark/light glow asymmetry automatically.
- About-page table treatment uses `var(--cb-card-pad-x, var(--cb-space-5))` with a fallback so future Card variants with different padding values can override without rewriting these rules.

## [1.3.34-dev] — unreleased

### Changed — Six-pass design coherence patch across CB Base admin

The legacy WP-admin styling that survived the master-switch migration is gone. Six pages adopt the same `cb-core-panel` convention every other CB Base surface already uses: every logical section becomes its own panel, with rounded corners, theme-aware tokens, and consistent spacing. One conventional vocabulary across the whole admin instead of WP-admin defaults bleeding through where the migration hadn't reached yet.

#### Reports → Overview — Available + Recently generated tables wrapped in panels

The two tables on the Overview tab were rendered as bare `widefat striped` tables under bold h2 headings — visually inconsistent with the Cleanup panel below them, which had been promoted in 1.3.33-dev. Both tables now sit inside their own `cb-core-panel`, with the h2 as the panel header, matching the Cleanup panel's shape.

A small helper-edit on the Cleanup panel: it was nested inside the Recently-generated section's `<?php else : ?>` branch, which made it conditional on having any reports — fine in practice but tangled in the markup. Lifted to a sibling `<div>` with its own visibility condition (`! empty( $recent_reports ) && current_user_can( 'cb_manage_reports' )`), so the structure now reads as three siblings: Available reports, Recently generated, Cleanup.

#### Tables-inside-panels CSS rule (suite-wide)

A new rule in `components/panels.css` makes any `widefat` (or CB-prefixed report/failsafe) table inside a `cb-core-panel` inherit the panel's surface, lose the WP-default border + box-shadow, and render with theme-aware row separators. Negative margins pull the table flush with the panel's inner edges so it visually fills the container; the panel's own border-radius clips the corners via `overflow: hidden` (gated behind `:has()` so non-table panels keep their default overflow behaviour).

This is the rule that fixes the "table inside panel has sharp corners while the panel has rounded corners" mismatch on Failsafe Layers, Reports Overview, and Permissions Operators in one go.

#### Failsafe → "Activate emergency bypass" gets a new --warning button variant

The panic button rendered as a default WP grey button — visually neutral for an action that disables every restrictive Core Blueprint feature on the site. Promoted to a new `cb-core-button--warning` class: filled amber via the existing `--cb-warning` token, hover transitions to a slightly darker amber.

The variant is distinct from `cb-core-button--danger` (outlined red, used for delete/clear). Warning signals "consequential, stop and read" without implying permanent removal of data — emergency bypass disables features but doesn't remove anything. Three semantic levels now coexist: primary blue for normal saves, warning amber for impactful-but-reversible operations, and danger red for destructive actions.

The Layer 3 "Rotate bypass token" button stays primary blue. Token rotation is a normal security operation, not a consequential one — the old token is replaced by a new one with no permanent effect, just like rotating an API key.

#### Notifications tab — three sub-sections each in their own panel + inline Save row

The Notifications tab already used three `<section class="cb-core-panel">` wrappers per group, but the inner Recipient row was pushed through `\CB\Core\UI\Field::render( [ 'variant' => 'inline' ] )` which placed the Save button on a new line below the input rather than inline with it. Replaced with explicit `<div class="cb-core-alert-recipient-row">` markup — a flexbox container with input, button, and FormStatus on one line, help-text below in normal flow.

Three places, same pattern: Audit events, Permissions events, Reports events. The `Field::render` calls and the `ob_start()` capture they wrapped are gone; the resulting markup is shorter and the layout is now deterministic instead of dependent on which `cb-core-field--inline` rules win the cascade.

#### Reports tab in Preferences — single Branding panel with sub-sections, Live Preview removed

Below the master switch, the entire branding form was restructured. The previous two-column grid (configuration on the left, live-preview mock on the right) and the WP `form-table` inside the configuration column are gone. Replaced with a single `cb-core-panel` titled "Branding", with two sub-sections (Logo and Identity) divided by a subtle rule, and Save / Reset actions at the bottom in a `cb-core-actions` row.

The sub-section headings (h3) use the small uppercase letter-spaced treatment from elsewhere in CB Base. Identity uses `cb-core-form-row` + `cb-core-form-label` + `cb-core-form-help` — the same form-row pattern introduced in 1.3.33-dev for the Maintenance Report tab. The accent-colour picker gets its own `cb-core-accent-row` flex layout: native colour swatch + hex text input on one line.

The live-preview mock is removed entirely — markup, CSS, and JS. The HTML mock didn't faithfully match Dompdf-rendered PDF output (different rendering pipeline, different typography, different colour rendering), so operators were misled about how a branding change would actually look in the final report. Verifying changes now goes through the Maintenance Report tab: generate an actual report and view it. The feedback loop is one click longer than a live preview, but the result is what the operator (and their client) actually sees.

Code removed: `cb-core-branding-grid`, `cb-core-branding-config`, `cb-core-branding-preview`, `cb-core-branding-mock`, `cb-core-mock-header`, `cb-core-mock-logo`, `cb-core-mock-id`, `cb-core-mock-title`, `cb-core-mock-contact`, `cb-core-mock-rule`, `cb-core-mock-section`, `cb-core-mock-period`, `cb-core-mock-fakebody` (CSS, ~196 lines). The matching JS handlers (`updateMockTitle`, `updateMockContact`, `updateMockColor`, `updateMockLogo`, the `mockRoot` / `mockLogo` / `mockTitle` / `mockContact` element refs, and the `mockTitle.dataset.fallback` initialiser) are also gone. Logo-picker CSS was preserved and re-extracted into its own block (the dead-code sweep almost took it out — caught in time and rewritten, now a sibling of the new accent-row rules).

Two strings are now orphan in the catalogue: the original Logo placeholder copy with the typographic `×` (300×300 → 300x300, since the em-dash sweep didn't cover Unicode multiplication signs in the same pass) and the previous contact-line example with the `·` separator. New variants added; orphans left for the next em-dash-style sweep.

#### Permissions tab — three independent panels per concern

Same treatment as Notifications: each of the three logical sections (CB Operators, Page visibility, Administrator capabilities) now sits in its own `cb-core-panel`. Each panel keeps its own Save button in a `cb-core-actions` row at the bottom — the existing per-section save semantics are preserved, only the markup framing changes.

Bonus fix: the Operator-checkbox column in the operators table was set to `width: 40px` in `table-cols.css`, which was narrow enough that the column header "Operator" wrapped vertically into "Op | era | tor" at the default font size. Bumped to 80px so the header fits on a single line. The cell still only contains a checkbox; the extra horizontal space is harmless.

### Internals

- New `cb-core-button--warning` button variant in `components/buttons.css`, with both default and `.cb-core-wrap` / `dialog.cb-core-modal` higher-specificity rules to win against WP-core `.button` defaults
- New table-inside-panel rules in `components/panels.css` covering `widefat`, `cb-core-failsafe-layers`, `cb-core-report-types`, `cb-core-recent-reports`. Uses `:has()` for the panel's `overflow: hidden` so only panels containing tables get the corner-clip
- New `cb-core-branding-section` (border-divided sub-sections), `cb-core-branding-subhead` (uppercase h3 treatment), `cb-core-branding-actions` (top-bordered actions row), `cb-core-accent-row` (color + hex flex row), and `cb-core-alert-recipient-row` (notifications recipient + save flex row) in their respective page CSS files
- Five new translatable strings ("Branding", branding intro, logo recommendation copy, contact-line placeholder, plus the implicit pickup of preserved strings); two orphan from the live-preview removal
- `Field::render( [ 'variant' => 'inline' ] )` no longer used in `templates/notifications.php`; the variant itself is still defined for any other future callers

## [1.3.33-dev] — unreleased

### Changed — Reports overview row-actions converted to inline links

The Recently-generated table on Reports → Overview previously rendered three small buttons per row ("View PDF", "Download PDF", "Delete"), which on narrow viewports overflowed the actions column with the Delete button wrapping to a second line. The buttons are replaced by WordPress-style row-actions: pipe-separated inline links ("View | Download | Delete") that fit on one line, signal "secondary actions scoped to this row" instead of "primary page actions", and match the convention WP core uses on its own list-tables (Edit | Quick Edit | Trash).

The "PDF" suffix is dropped from each label — the page intro and table heading already make the report-format obvious; repeating it three times per row added length without information. Drops about 35% off the actions cell width on the typical row.

The Delete action is rendered as a `<button>` styled as a link rather than a real `<a>` because it triggers a confirm-modal and an AJAX call rather than a navigation. Visual treatment matches the View/Download links; only the colour shifts to the suite's danger token.

### Fixed — Duplicate `class=` attribute on Recently-generated Delete button

A leftover duplicate `class=""` attribute on the Recently-generated table's Delete button (template line 162-164) meant browsers picked up only the first occurrence and silently dropped the spacing utility class on the second. Visual fallout was minor (Delete sat flush against the preceding button) but the underlying HTML was malformed. Cleaned up incidentally during the row-actions refactor — the new markup builds the actions array via `sprintf` with single class declarations, so this regression class is structurally prevented.

### Changed — "Delete all reports" promoted to its own Cleanup panel

The Delete-all-reports button previously sat directly under the table in a thin `cb-core-bulk-actions` div with no visual separation, reading at a glance as if it were another row-action. Promoted to its own `cb-core-panel` titled "Cleanup", with a body paragraph explaining when this destructive bulk operation is appropriate and that the existing type-to-confirm gate prevents accidental clicks.

The panel sits below the table with a generous top-margin so it reads as "list-level operation" rather than "row-level". Future bulk operations (export-all, archive-old) would extend this same panel rather than spawning siblings.

### Changed — Maintenance Report tab redesigned around the CB panel layout

The Generate-form was the last template in CB Base still rendered as a WordPress `<table class="form-table">`. With form-table comes WP-admin default styling that ignores CB's theme tokens — light mode shipped fine, dark mode rendered with WP's grey-on-grey field defaults that don't acknowledge `--cb-surface-2` or `--cb-text`.

Replaced with a panel-based layout matching every other CB Suite form (Notes Preferences, Reports branding, Login Shield config). The single Generate panel stacks: page-h1 + intro at the top, panel heading "Generate report", a labelled period-select with help text below it, the optional custom-date row that shows when "Custom period" is chosen, and the action buttons in a `cb-core-actions` row at the bottom.

Theme-aware now: `--cb-surface-2`, `--cb-border`, `--cb-text`, `--cb-accent` for focus rings, etc. Light/dark/native all render coherently.

### Changed — Generate flow no longer auto-downloads

The previous "Generate & Download" button triggered a browser download immediately after the PDF was persisted. That suited the case "I want this PDF on my disk now" but forced a download-and-discard cycle for the more common case: "I want to inspect the report" or "I want to share this with a colleague who can also access this admin". Operators reported repeatedly downloading PDFs only to delete them from their Downloads folder afterwards.

The button is now simply "Generate report". On success the JS shows a status message "Report generated." with an inline CTA "View on Overview →" linking to the Overview tab. From there, the operator can View (open in browser tab), Download (same as before), or Delete (per-row) using the row-actions cluster from this same patch. The Generate-then-share path now matches the Generate-and-download path: both pass through the Overview list, the operator chooses what to do next.

Server-side semantics are unchanged — the report is generated, persisted, and indexed exactly as before. Only the JS post-success behaviour differs: no `triggerDownload()` call, success message + CTA instead. The `triggerDownload()` helper itself is removed from `reports.js` since no other code path used it.

### Removed — "Generate & Send" button (UI only, feature pending)

The disabled "Generate & Send" button was a placeholder for the email-delivery feature scheduled for v1.2. Disabled-with-tooltip is dead-state clutter on every page load — operators see a thing they can't use and have to remember why. The button is dropped from the markup entirely; when v1.2 ships its email-delivery feature, the affordance reappears in a release that actually delivers it.

### Added — "What is in the report" info panel on the Maintenance Report tab

Below the Generate panel, a second info panel lists the five sections of the PDF: KPI overview, theme/plugin updates, WordPress core updates, security events, and the branding snapshot frozen at generation time. Each item gets a one-sentence body that explains the contents in plain language so a non-technical reader can decide whether the report is what they need before clicking Generate.

This follows the same plain-language-first principle Peter formulated for the rest of CB Suite: the operator (or the council member they hand the page to) should understand what's about to happen without reading source code or asking IT.

### Internals

- New `.cb-core-row-actions` component in `assets/css/pages/reports.css` (kept page-local until a second consumer needs it — rule of three holds)
- `.cb-core-form-row`, `.cb-core-form-label`, `.cb-core-form-help`, `.cb-core-select`, `.cb-core-input`, `.cb-core-mr-date-range` — Maintenance Report panel form classes, also page-local for now
- `.cb-core-form-status__link` — link styling inside the form-status text element for the Overview-CTA on success
- `setStatus()` in `reports.js` gains an optional `allowHtml` flag for the buildSuccessHtml path; default still uses `textContent`
- `triggerDownload()` helper removed (dead after the generate-flow refactor)
- Three new i18n keys (`reportsGenerated`, `reportsViewOnOverview`, plus inline template strings); `reportsReady` and `reportsNoUrl` keys retired

## [1.3.32-dev] — unreleased

### Changed — Em-dash sweep across UI strings

The hyphen-cleanup patch trailed by 1.3.28-dev lands. Every operator-visible string in the suite is now hyphen-only — em-dashes (—) and en-dashes (–) replaced by regular hyphens with surrounding spaces (` - `). Comments, docblocks, and the CHANGELOG itself were intentionally not touched, so internal documentation and code prose retain their typographic punctuation; only what a council member, healthcare admin, or operator actually reads is sweep-clean.

The motivation was direct: typographic dashes are uncommon in everyday Dutch and English business writing, and their presence is one of the patterns automated-content detectors lean on. CB Suite is governance-focused — operators in healthcare, education, and municipal contexts need to trust that the plain-language explanations originated from real human authoring. Hyphen-only output removes that one signal cheaply.

#### Scope

- All `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()`, `esc_attr_e()`, `_n()`, `_x()`, `_ex()`, `_nx()`, `esc_html_x()` calls in PHP and templates
- Every `msgid` and `msgstr` line in `core-blueprint.pot` and `core-blueprint-nl_NL.po`
- Inline string literals in JS modules where i18n strings live (none in practice — the CB Suite JS modules consume i18n via PHP-side `wp_script_module_data`, so JS literal strings rarely contain UI text)

#### Numbers

- 505 replacements across 39 files
- 127 in PHP / templates (UI-visible only — comments and docblocks left intact)
- 126 POT msgid lines, 251 PO msgid+msgstr lines, including the previously-orphan strings carried forward from 1.3.28-dev (Core Shield's old single-mode page-intro and the redundant `<h2>Core Shield</h2>` description), 1.3.30-dev (twelve Beacon table-row strings), and 1.3.31-dev (two Beacon URL-param notice strings)
- All NL translations preserved — the sweep updates `msgid` and `msgstr` in lockstep so existing translations stay attached to their (now hyphen-clean) source strings

#### What's deliberately not changed

PHP docblocks, inline comments, and code prose throughout the suite still use em-dashes where the original author wrote them. These are developer-visible only; readers of `src/Beacon/Errors.php`'s docblock are fellow developers, not the council members the suite is positioning for. The CHANGELOG markdown also retains its dashes — it's a release-notes document for technical audiences and reads naturally with typographic punctuation.

The sweep tool (`em_dash_sweep.py`, kept outside the plugin tree) targets only i18n function-call argument strings and PO/POT quoted lines. Comments and free prose were never in scope.

#### Compatibility

Existing translations remain valid because POT and PO were swept together: every `msgid` change has a matching `msgstr` change in the same line block, so no msgstrs got orphaned. `msgfmt --check` passed on the swept PO with no warnings.

If you have a custom translation file outside `core-blueprint-nl_NL.po`, its em-dash msgids will no longer match the (now hyphen-clean) source strings. Re-extract the POT after this update and merge into your translation file (`msgmerge --backup=off -U your-locale.po languages/core-blueprint.pot`) to recover the matches.

## [1.3.31-dev] — unreleased

### Removed — Beacon admin-post toggle handler

The legacy `cb_core_beacon_toggle` admin-post handler is gone. With the master switch on Safeguards → Beacon being the only UI surface that flips Beacon's enabled state, and no Dashboard tile or other admin-post caller, the second handler had no users left.

`Beacon\Admin\Toggle::handle()` and its `admin_post_cb_core_beacon_toggle` hook registration are removed; only `ajax_set_enabled()` remains. The accompanying URL-param notice flow on the Beacon tab (the `cb_core_beacon_notice=enabled|disabled` round-trip) is also gone — the master switch's reload-on-success delivers state feedback by itself, no notice banner needed.

The `key_cleared` notice variant remains intact: it comes from the Clear Secret Key form, which still POSTs to `admin.php` (separate code path from the master switch). Operators clearing the key continue to see the success banner on return.

Two translated strings became orphan in this patch — the Beacon-enabled and Beacon-disabled URL-param notice messages. They're left in the catalogue for the em-dash sweep to pick up.

### Added — Notes tile on the Dashboard's Preferences section

The Dashboard's Preferences section had a Reports tile but no matching Notes tile, even though both subsystems gained master switches in 1.3.25 / 1.3.26-dev. Notes added now, gated on `cb_manage_notes`, sitting between the Reports tile and About — same order as the Preferences tabs after the 1.3.29-dev reorder.

Tile copy is the parallel of Reports': "Master switch and defaults for site-bound notes". Click target is `?tab=notes` on the Preferences page.

### Changed — Tile dot glow

State dots on the Dashboard tiles now carry a soft state-coloured glow under the existing surface ring. The ring (3px solid against the tile background) keeps doing its job — visual separation from the tile surface — and a second box-shadow layer adds a 10px coloured halo at 55% alpha through `color-mix`. Idle and inactive states get a gentler 8px halo at 35% alpha so a deliberately-off tile reads as dim but not dead.

Hover state preserves the glow while swapping the surface-ring colour to `--cb-surface-2` (matching the tile's hover background). All five state variants (active, warning, error, idle, inactive) get their glow; the change is purely additive — no existing rule was overridden, so the dots in non-glow contexts (status surfaces elsewhere) keep their previous appearance.

## [1.3.30-dev] — unreleased

### Changed — Beacon retrofitted to MasterSwitch pattern

Beacon joins Login Shield, Core Shield, Core Scanner, Notes, and Reports as a MasterSwitch consumer. The Safeguards → Beacon tab now opens with the standard binary master-switch card-pair directly under the page-h1 + intro, identical in shape to the rest of the suite.

The previous interaction (a `<table class="form-table">` with three rows: Current status, Toggle Beacon, Stored secret key) is replaced by three logical panels stacked vertically: master switch up top, then the Connection-status sub-block (only when master is on), then the Stored secret key panel (only when a key exists). Same information, suite-consistent layout.

#### Decomposing the three-state model

Beacon previously had three top-level observable states: Active, Ready, Deactivated. With the master-switch retrofit, these are split along two orthogonal axes:

- **Master axis (enabled / disabled)** is owned by the MasterSwitch itself. Off → "Beacon is disabled" banner explains the consequences. On → the configuration sub-block renders.
- **Configuration-completeness axis (has key / no key)** becomes a sub-status under the master, only visible when the master is on. The previous "Ready" state is now this sub-status's "no key yet" branch; "Active" is its "key present" branch. The previous "Deactivated" state is no longer a separate concept — it's just "master is off".

This split makes the master/config separation explicit: the master toggles the subsystem (REST endpoints, scheduled tasks, .htaccess rule, Hub Pairing menu visibility), and the config-completeness sub-status reflects whether the operator has finished pairing. An operator looking at "Off — Beacon dormant" now reads it the same way they'd read "Off — Notes hidden" or "Off — Core Scanner stood down".

#### New — `\CB\Core\Beacon\State`

Beacon predates the suite-wide State helper convention by several versions. `Pairing` already serves as the low-level option getter/setter, and `Lifecycle` owns the .htaccess rule. State sits on top of both, providing the same shape every other CB subsystem now exposes — `is_enabled()` + `set_enabled()` with idempotent no-ops on unchanged calls and audit emission at notice severity on transitions.

`set_enabled()` performs in order: idempotence check, `Pairing::set_enabled()` (option update), `Lifecycle::insert_htaccess()` or `remove_htaccess()` (.htaccess sync), `AuditLog::log()`. Both the legacy admin-post handler and the new AJAX master-switch handler route through here, so the admin-UI flip and the master-switch flip emit identical audit signatures and have the same idempotence semantics. Previously the admin-post flow flipped the option without an audit row; that gap is now closed retroactively for the legacy path too.

#### Added — `wp_ajax_cb_core_beacon_set_enabled`

Atomic AJAX endpoint registered alongside the legacy admin-post handler in `Beacon\Admin\Toggle::init()`. Same authorisation contract: `manage_options` cap, `cb_core_beacon_toggle` nonce. Reuses the action name so an existing token can serve both flows during the transition. Beacon stays admin-level (not cb_manage_*) to avoid breaking the historical capability mapping in this patch — that's a separate cleanup.

The legacy admin-post handler is retained for backward compatibility and for the Dashboard tile's "re-enable from tile" link, which still POSTs against `admin-post.php` directly.

#### Added — JS handler `features/beacon-master-switch.js`

New tiny JS module dedicated to the master-switch toggle. Optimistic UI flip on click, revert on AJAX rejection, page reload on success. Reload is required because Beacon's enabled state controls server-rendered chrome — the Hub Pairing top-level admin menu item appears or disappears with the master state, and the Connection-status sub-block on this same page conditionally renders. The .htaccess passthrough rule is also mutated server-side; reloading reconciles all of it.

The existing `features/beacon-confirm.js` module (clear-key confirmation dialog) is unaffected and keeps doing its narrow job.

#### Added — Audit-log event labels

`beacon_subsystem_enabled` and `beacon_subsystem_disabled` registered via the `cb_core_event_labels` filter, both at notice severity. Same pattern as Notes (1.3.25-dev), Reports (1.3.26-dev), and Integrity (1.3.23-dev).

#### What's removed from the template

The old form-table layout is gone. Specifically: the "Current status" row (now the Connection-status sub-panel only when master is on), the "Toggle Beacon" row (replaced by the master switch), and the inline Disable/Enable submit buttons (the master switch is the toggle now). Twelve translated strings became orphan in this patch — they're left in the catalogue for the 1.3.31-dev em-dash sweep to pick up alongside the broader cleanup.

#### Suite status — MasterSwitch rollout complete

With Beacon retrofitted, every CB subsystem that should be deactivatable now uses the MasterSwitch component. The two documented exceptions remain — Failsafe (lockout-recovery, gets multi-step confirmation if disabled, not a one-tap card-pair) and Access Mode (mode-picker, not on/off). The plain↔technical philosophy is consistently applied across all five master-switch surfaces, the page-h1 + intro framing convention from 1.3.28-dev holds, and the audit-event labels follow the `{subsystem}_subsystem_{enabled|disabled}` shape across the board.

## [1.3.29-dev] — unreleased

### Changed — Master switch top-margin removed

Follow-up to 1.3.28-dev. Now that no master switch in the suite is preceded by a caption inside its own block (page-h1 + intro frames the switch instead), the `margin-top: var(--cb-space-4)` on `.cb-core-master-switch` produced a redundant gap between the panel's top padding and the switch grid. Removed. The dead override rule that zeroed the margin when a caption-block wrapper was present went with it — there's no scenario left where that override fires.

### Changed — Preferences tab order

Notes moved from before About to directly after Reports. Permissions and About now sit at the end of the tab strip, in that order.

Old order:  Overview, Privacy, Notifications, Language, Appearance, Reports, Permissions, Notes, About
New order:  Overview, Privacy, Notifications, Language, Appearance, Reports, Notes, Permissions, About

The two related Preferences-tabs that own a feature subsystem master switch (Reports and Notes) now sit next to each other, separated from the configuration-only tabs (Permissions, About). The tile cards on the Preferences overview page were reordered to match.

## [1.3.28-dev] — unreleased

### Changed — Page-h1 + intro is the canonical frame for master switches

Core Shield previously rendered a redundant heading inside the master-switch panel: the page-level `<h1 class="cb-core-title">Core Shield</h1>` was followed, several lines below, by a `<h2>Core Shield</h2>` plus description duplicating the same framing role. Login Shield, Core Scanner, Notes, and Reports never had this duplication; they used only the page-level heading and intro.

Core Shield now matches the rest of the suite. The redundant `<h2>` and `<p class="description">` block inside `cb-core-shield-panel` is removed; the master switch sits directly under the page-h1 + intro like every other master-switch page.

The plain↔technical text variants that previously powered the inner h2's description have been moved up to the page-intro paragraph itself, so the suite's plain↔technical philosophy stays visible at the very first paragraph non-technical readers see (the intent Peter formulated for council members and healthcare admins navigating the suite without IT specialists). The mode-resolver block (`$_sh_pick`) is hoisted above the page-intro to support this; the rest of the template (state-aware on-description, Access Mode-driven copy, detector notice, master-switch invocation) is unchanged.

#### Notes — `MasterSwitch::caption` prop

The `caption` prop on `MasterSwitch::render()` remains supported but is now documented as the rare path. The component docblock makes the suite convention explicit: every CB Suite page or tab hosting a master switch already carries a page-level `<h1 class="cb-core-title">` plus a `<p class="cb-core-intro">`, and a caption inside the switch block would duplicate that framing. Compose the mode-aware explanation in the page-intro instead. New pages should follow this pattern by default; the prop is reserved for the (currently nonexistent) case where a master switch is embedded in a wider dashboard surface that lacks its own heading.

This formalises what's been the de-facto convention since Notes 1.3.25-dev — Notes and Reports never used the caption prop, and Login Shield and Core Scanner never used it either. Core Shield was the lone outlier; that's now resolved.

#### No string churn

Both plain and technical text variants for the new page-intro position were already in the language catalogue (they previously powered the inner-panel description). The catalogue is unchanged; existing nl_NL translations apply automatically. The two strings that became unused in this patch — the old single-mode page-intro and the redundant inner `<h2>Core Shield</h2>` — are left in the catalogue as orphans for the 1.3.30-dev em-dash sweep to pick up alongside the broader cleanup.

## [1.3.27-dev] — unreleased

### Changed — State-aware Operations tiles on the Dashboard

The Operations section on the Core Blueprint Dashboard previously rendered Notes and Reports as dumb navigation tiles — same shape regardless of master-switch state. With both subsystems now disable-able (1.3.25-dev, 1.3.26-dev), an inactive tile pointed at a dead URL: clicking it after disabling led to WP's "you are not allowed" page because the top-level menu item no longer existed.

Tiles now reflect the master-switch state and reroute themselves when off, mirroring the Safeguards section's existing dot-on-tile pattern (consistency over invention — same vocabulary, same dot CSS, same `Status::dot_class()` helper).

When a subsystem is enabled, nothing changes: the tile shows the active dot, the meta describes what the feature does, the URL points to the top-level admin page. When disabled, the tile keeps its title (discoverability — operators still see what features the suite offers) but the dot turns idle, the meta switches to a single word "Disabled", and the URL reroutes to the Preferences > {feature} tab where the master switch lives. Clicking a disabled tile takes you straight to the place to re-enable it — self-healing, no dead ends.

Logs is intentionally unchanged. It has no master switch and stays state-less alongside the state-aware Notes and Reports tiles in the same section.

#### Implementation notes

`Dashboard::render()` now reads `Notes\State::is_enabled()` and `Reports\State::is_enabled()` to compute per-tile state, URL, and meta. Class-existence guards keep the dashboard render defensive against partial-deploy states. The "Disabled" string already existed in the language catalogue (used by RetentionTab and privacy.php) — translation reused, no new POT entries.

The dashboard template's Operations loop now renders the dot conditionally: `if ! empty( $card['state'] )`. This keeps Logs tiles dot-less while letting state-aware tiles opt in by simply including a `state` key. Future MasterSwitch consumers (Beacon, Access Mode when retrofitted) can adopt the pattern by adding tile entries with `state` set — no template changes needed.

The state vocabulary is the Safeguards `ok|warn|err|off` set rather than a parallel Operations vocabulary. One mental model across the suite. `'ok'` for enabled, `'off'` for disabled — that's all the Operations section needs at this point. If a tile ever needs a `warn` or `err` state (e.g. enabled but misconfigured), the vocabulary already supports it without further work.

## [1.3.26-dev] — unreleased

### Added — Reports master switch in Preferences > Reports

Reports joins the MasterSwitch rollout. Same model as Notes 1.3.25-dev: master lives in Preferences (where the operator configures), feature page (the top-level Reports menu item) disappears entirely when off, and the configuration on the Preferences tab stays editable so operators can prepare branding before re-enabling.

#### Changed — Tab rename: Report Branding → Reports

The Preferences tab previously called "Report Branding" is now "Reports" (plural, matching the top-level Reports menu item — same convention as Notes/Beacon, where tab name follows feature name). Slug, label, dispatcher case, method, template path, and JS module ID all renamed for consistency:

- Slug: `?tab=report-branding` → `?tab=reports`
- Label: "Report Branding" → "Reports"
- Method: `Preferences::render_report_branding_tab()` → `render_reports_tab()`
- Template: `templates/report-branding.php` → `templates/preferences-reports.php`
- JS module: `@cb-core/report-branding` → `@cb-core/reports-preferences` (file `features/report-branding.js` → `features/reports-preferences.js`)

Bookmarked links to the old tab slug stop working — they'll land on the Preferences overview instead, which is graceful enough since CB Suite isn't shipped to clients yet. The h1 and intro paragraph on the renamed tab now describe both responsibilities (master switch + branding).

#### Changed — Tab visibility broadened to either-cap

Previously the tab was hidden from anyone without `cb_manage_branding` (operator-only, branding-focused). With the master switch now living on this tab, the visibility check is `cb_manage_branding` OR `cb_manage_reports` — so a split-cap user (rare in practice, since the operator role grants both) doesn't get locked out of the master switch they need. The same OR-check is applied to the overview tile in Preferences and the Reports tile on the Dashboard.

#### New — `\CB\Core\Reports\State`

Mirrors the shape of `\CB\Core\Notes\State` from 1.3.25-dev. `is_enabled()` reads `cb_core_settings['reports']['enabled']` (default `true`, populated through the existing array fallback in callers — no migration). `set_enabled()` writes via `Settings::set_key( 'reports', ... )` and emits a notice-severity audit event on transitions only. Reports doesn't have a SettingsRepository wrapper (Branding writes go straight through `Settings::set_key()`), so State follows that convention.

#### Changed — Conditional admin-menu registration

`Bootstrap::register_admin_page()` now bails when `State::is_enabled()` returns false. Same conditional pattern as Notes — toggling state and reloading is enough to add or remove the Reports menu item.

#### Added — AJAX guard on `cb_core_generate_maintenance_report`

When Reports is disabled the generate endpoint returns a 403 with stable error code `cb_reports_subsystem_disabled` and a plain-language message pointing operators to the master switch. Other Reports endpoints (download, delete, delete_all) intentionally stay unguarded — they're cleanup operations that shouldn't be coupled to the enabled state. The new toggle endpoint is also unguarded so operators can always re-enable.

#### Added — `wp_ajax_cb_core_set_reports_enabled`

Atomic master-switch toggle. Cap-gated on `cb_manage_reports` (the narrower of the two tab caps). Calls `State::set_enabled()` and returns the resolved state plus a plain-language success message. Validates the `cb_core_admin` nonce, same as the branding form on the same tab.

#### Changed — Retention pruner short-circuits when disabled

`Storage::cleanup_expired_default()` returns 0 deletions when `State::is_enabled()` is false. The intent is that "off" should freeze time for the subsystem — re-enabling later brings the historical archive back exactly as it was when disabled, rather than letting routine retention silently erode the report archive during the disable window. Stored report rows and PDF files on disk are not touched.

#### Added — Master switch + status banner on Preferences > Reports

MasterSwitch panel sits at the top of the renamed tab. When disabled, an info banner directly underneath explains the consequences (menu hidden, generate endpoint rejecting, retention paused, branding still editable). The branding form below the banner is unchanged — it continues to use the existing AJAX flow against `cb_core_save_report_branding` and `cb_core_reset_report_branding`.

#### Added — JS handler in `features/reports-preferences.js`

The renamed JS module gains the master-switch click-handler at the bottom of the file. Optimistic UI flip on click, revert on AJAX rejection, page reload on success so the admin menu re-renders. Shares the `cb_core_admin` nonce read from the branding form's `data-nonce` attribute (both pieces of UI live on the same tab, single nonce surface).

Lives alongside the branding-form wiring in the same module rather than as a separate file like Notes — Reports already had a feature-module on this tab, adding the master switch to the same module is a smaller delta than introducing a new module would have been.

#### Added — Audit-log event labels

`reports_subsystem_enabled` and `reports_subsystem_disabled` registered via the `cb_core_event_labels` filter, both at notice severity through `AuditLog::log()` with the actor (admin login or `admin:unknown`) attached.

## [1.3.25-dev] — unreleased

### Added — Notes master switch in Preferences > Notes

Continuing the MasterSwitch rollout: Notes can now be deactivated entirely via Preferences > Notes. When off, the Notes top-level admin menu item disappears from the Core Blueprint sidebar; the REST write paths return 403; stored notes and preferences are preserved untouched. The Preferences > Notes tab itself stays accessible — that's where the master switch lives, so re-enabling is always one click away.

This is the first MasterSwitch consumer where the master lives on a different page than the feature it controls. Core Shield, Login Shield, and Core Scanner all had the master on the same page as the rest of the feature UI; for Notes (and Reports next), the operator lives in Preferences when configuring, in the feature page when working — and the menu visibility is the canonical signal of whether the feature is in their stack at all.

#### New — `\CB\Core\Notes\State`

Mirrors the shape of `\CB\Core\Integrity\State` from 1.3.23-dev: `is_enabled()` reads the merged settings via `SettingsRepository::all()`, `set_enabled()` persists with audit-event emission on transitions only, idempotent calls return early without logging. Notice severity for the audit event because turning a whole subsystem on or off is operationally meaningful.

#### Changed — `enabled` flag in Notes settings

Added to `Defaults::values()` and `Defaults::sanitize()`. Default `true` so installations predating 1.3.25 silently inherit prior behaviour through the existing `array_merge` in `SettingsRepository::all()`. No data migration required.

`SettingsRepository::update()` now merges incoming settings over the current stored state before sanitising. Without this merge, the form-POST handler in `PreferencesPage::maybe_handle_post()` — which submits only its own five fields — would silently flip `enabled` back to the default on every save, undoing any prior master-switch toggle. Same gotcha as Login Shield 1.3.22-dev's `save()`, fixed defensively here so any future caller doing a partial update is foolproof by construction.

#### Changed — conditional admin-menu registration

`Bootstrap::boot()` only calls `PageRegistry::register( new Page() )` when `State::is_enabled()` returns true. The hook fires every request, so toggling state and reloading is enough to add/remove the menu item — no activation/deactivation cycle needed.

#### Added — REST guards on Notes routes

A private `subsystem_disabled_response()` helper on `NotesController` returns HTTP 403 with the stable error code `cb_notes_subsystem_disabled`. Applied to `list()` (read) and `action()` (multi-action write — create, update, archive, delete, bulk delete, export, import). The new `enable()` endpoint is intentionally NOT guarded — operators must always be able to flip the master back on.

State check happens before the nonce check in `action()`. Order: capability → state → nonce → action.

#### Added — `POST /notes/enable`

Atomic master-switch toggle. Takes `{ enabled: bool }`, calls `State::set_enabled()`, returns the resolved state plus a plain-language success message. The endpoint validates its own nonce (X-WP-Nonce) since it's separate from the action() handler.

#### Added — Master switch + status banner in `PreferencesPage::render_body()`

MasterSwitch panel sits at the top of the Preferences > Notes tab. When disabled, an info banner directly underneath explains the consequences (menu item hidden, write endpoints reject, existing notes preserved, preferences stay editable). The form below is unchanged — it continues to use the classic POST self-submit flow rather than the master's REST/AJAX flow. Two interaction patterns coexist on the same page; the master-switch's reload-on-success refreshes the form alongside the menu.

The form's POST handler doesn't touch the `enabled` flag (it submits only the five existing form fields), so master-switch toggles are not undone by routine preference saves — guaranteed by the `update()` merge described above.

#### Added — JS handler `features/notes-preferences.js`

New tiny JS module dedicated to the master-switch toggle. Loads globally like the other feature modules, no-ops on every page that doesn't render the master switch (the tag-name lookup short-circuits). Optimistic UI flip on click, revert on REST rejection, page reload on success so the admin menu re-renders.

Lives next to the existing `features/notes.js` rather than being merged into it: separate concerns (top-level page UI vs Preferences tab toggle) and separate page contexts. The existing notes.js already loads on every CB Base page and bails out internally — adding the master-switch handler there would have worked but coupled two unrelated code paths in the same file.

#### Added — Audit-log event labels

`notes_subsystem_enabled` and `notes_subsystem_disabled` registered via the `cb_core_event_labels` filter. Both go through `AuditLog::log()` at notice severity with the actor (admin login or `admin:unknown`) attached.

## [1.3.24-dev] — unreleased

### Fixed — Core Scanner master switch hit a 404 ("No route was found matching the URL")

The new master-switch handler in `assets/js/features/core-scanner.js` posted to `/integrity/admin/enable`, but `restUrl` (set in the script-module data payload) is already `…/wp-json/core-blueprint/v1/integrity/admin` — the prefix was being doubled, so the actual request URL was `…/integrity/admin/integrity/admin/enable` and WordPress correctly returned 404. Every other `request()` call in the file uses paths relative to `restUrl` (`/scan`, `/baseline`, `/settings`, `/clear`, `/locale/redetect`); the master-switch path now matches that convention with `/enable`.

The 1.3.23-dev release ZIP shipped with this bug — anyone who installed it could not toggle Core Scanner off. No data risk: the request never reached the server, so no settings or audit-log entries were touched.

## [1.3.23-dev] — unreleased

### Added — Core Scanner master switch + suite-wide deactivation philosophy

Continuing the MasterSwitch rollout from 1.3.22-dev: Core Scanner now has a master switch at the top of its Safeguards tab, and the operator can stand the scanner down entirely. This is the third concrete consumer of the shared `\CB\Core\UI\MasterSwitch` component (after Core Shield and Login Shield).

The driving principle, articulated explicitly during the spar and now written into the MasterSwitch docblock as a permanent suite convention: **every CB subsystem must be deactivatable.** Operators may already cover a given concern with another tool — Wordfence for integrity scanning, a custom login-hardening plugin, an external monitoring stack — and CB Suite must never force itself on a site whose owner has chosen otherwise. The MasterSwitch component is the canonical UI for this everywhere it applies.

One deliberate exception, also captured in the MasterSwitch docblock: **Failsafe stays without a MasterSwitch.** Failsafe is the lockout-recovery — the literal "if everything else fails, you can still get in" path. A one-tap on/off card-pair is the wrong affordance for that; Failsafe gets its own multi-step confirmation flow when an operator really wants to disable it. Worth fixing in code-comments now so future passes through the suite don't blindly apply the philosophy to every subsystem.

#### New — `\CB\Core\Integrity\State`

Single source of truth for the Core Scanner subsystem master state. Three responsibilities:

1. `is_enabled()` reads the `enabled` flag from the existing `cb_core_settings['integrity']` settings array. Defaults to `true` so installations predating 1.3.23 silently inherit the prior behaviour without any migration code — `array_merge` over the `settingsDefaults()` does the work.
2. `set_enabled( bool, string $actor )` persists the flag, emits an audit-log event on transitions only (idempotent calls return early without logging), and synchronises the cron schedule: clearing it on disable so cron doesn't fire and immediately no-op every day, re-syncing from the stored `schedule` preference on enable.
3. Defends against the leak that would otherwise occur if every caller did the bool-default-and-audit-and-cron dance themselves.

#### Changed — `enabled` flag in Core Scanner settings

`ResultRepository::settingsDefaults()` now includes `'enabled' => true`. `saveSettings()` normalises it as a bool. The shape annotation on `settings()` reflects the new key. No data migration required — `array_merge` over defaults silently fills in `true` for stored settings predating the change.

#### Added — REST guards on scan-starting endpoints

A private `subsystem_disabled_response()` helper on `ScanController` returns HTTP 403 with the stable error code `cb_integrity_subsystem_disabled` so JS clients can recognise it. Applied to the four endpoints that start a scan or run scanner-internal disk operations:

- `POST /integrity/admin/scan` (manual scan)
- `POST /integrity/admin/baseline` (approve baseline → re-scans)
- `POST /integrity/admin/baseline/component` (approve component → re-scans)
- `POST /integrity/scan` (Hub-bound mirror)
- `POST /integrity/admin/locale/redetect` (locale detection on-disk)

Read endpoints, settings PUT, the new `enable` PUT, and all baseline/locale-mode cleanup endpoints stay accessible — operators must always be able to flip the master back on and view existing data without losing access.

#### Added — `POST /integrity/admin/enable`

Atomic master-switch toggle. Takes `{ enabled: bool }`, calls `State::set_enabled()`, returns the resolved state plus the full settings array. Other settings are preserved as-is — callers wanting to update `enabled` together with other fields use the existing `settings` PUT.

#### Added — Cron + async-scan handler short-circuits

`Cron::run_scheduled_scan()` returns early when the subsystem is disabled. `State::set_enabled(false)` already clears the schedule via `Cron::clear_schedule()`, so the cron hook shouldn't normally fire at all in disabled state — the short-circuit covers the edge case of a stale event in the cron queue from before disable.

`Bootstrap::run_async_scan()` (the manual-scan-via-cron handler scheduled by `wp_schedule_single_event`) similarly fails the job gracefully via `$reporter->fail()` if the master is flipped off between when the REST endpoint queued the event and when cron fired. The progress-poll UI surfaces a meaningful state instead of hanging.

#### Added — Master switch + status banner in `Page::render_panel()`

Master switch panel sits at the top of the Core Scanner tab. When disabled, an info banner directly underneath explains the consequences ("Scheduled scans will not run; manual scan is unavailable. Existing scan history and baseline below remain visible. Settings can still be edited and will apply when the scanner is re-enabled."). The "Run Core Scanner" and "Approve Baseline" buttons are hidden in both `render_idle_state()` and `render_result_state()` when the subsystem is off; the "Clear Approved Baseline" button stays available because it's a delete operation, not a scan.

The state-renderer signatures gained a `bool $is_enabled = true` parameter, defaulted so the change is backward-compatible if any extension code happens to call the renderers directly.

#### Added — JS master-switch handler

`bindMasterSwitch()` in `core-scanner.js` mirrors the pattern established in `login-shield.js`: scoped to `[data-cb-core-master-switch="core-scanner"]`, optimistic-UI flip on click, revert on REST rejection, page reload on success. Errors surface through the existing `cbCore.toast.error` channel using a new `scannerMasterToggleFailed` i18n key.

#### Added — Audit-log event labels

`integrity_subsystem_enabled` and `integrity_subsystem_disabled` registered via the `cb_core_event_labels` filter. Both go through `Audit::log()` at `notice` severity with the actor (admin login or `admin:unknown`) attached.

### Documented — suite-wide deactivation philosophy in `MasterSwitch` docblock

The convention ("every CB subsystem must be deactivatable; Failsafe is the documented exception") is now part of the MasterSwitch component's docblock. Future passes through the suite — Reports, Notes, Permissions, Beacon retrofit — will see this guidance directly when reading the component they need to use.

## [1.3.22-dev] — unreleased

### Added — MasterSwitch UI primitive + Login Shield retrofit

Three Safeguards pages independently invented their own card-pair-with-toggle markup for the binary on/off gate at the top of each page: Access Mode (`cb-core-access-option` / `cb-core-access-toggle`), Core Shield (`cb-core-shield-option` / `cb-core-shield-toggle`), and Login Shield (a checkbox in `Field::render()` with variant `enable`, structurally different from the other two). The first two consumers agreed on the pattern; the third was the odd one out, with the operator-visible UX issue that the enable checkbox sat tight against the next field with no breathing room.

This release introduces a shared component and brings Login Shield in line. Access Mode is left on its bespoke implementation for now — its confirmation-modal flow and warning system are non-trivial to fold into a first-cut shared component, and a separate retrofit pass keeps the diff focused.

#### New — `\CB\Core\UI\MasterSwitch::render()`

Pure visual primitive following the established `\CB\Core\UI\Status` shape: render-only, semantic-tone API (`success` / `warning` / `idle`), BEM markup, escape-clean output. The component has no knowledge of forms, AJAX, confirmation modals, or persistence — callers wire up their own behaviour via the documented `[data-cb-core-master-switch-toggle]` and `[data-cb-core-master-switch-state]` attributes. Behaviour extraction is deferred until a second concrete need surfaces (rule of three, applied to wiring as well as visuals).

The docblock additionally captures the UX convention for secondary settings on a page that uses MasterSwitch: when the master is off, secondary configuration fields stay editable (treat them as *config*, not *state*) and the not-yet-active state is signalled via a status banner. This preserves the natural setup flow of "configure first, flip master last" and avoids the trap of `aria-disabled` lying about whether a field accepts input.

#### New — `assets/css/components/master-switch.css`

The card-pair styling moved out of `pages/safeguards-core-shield.css` into a shared component file, registered in the modern-components enqueue array in `Admin.php`. Selectors renamed to BEM (`cb-core-master-switch__option--success` etc.) — no back-compat shim, the JS that targeted the old class names was updated in the same patch.

#### Changed — Core Shield retrofitted

`templates/core-shield.php` now calls `MasterSwitch::render()` instead of hand-rolling its own grid/option/toggle markup. The page-specific status line below (`cb-core-shield-status`) is unchanged; that's a Core Shield concern, not a MasterSwitch one. `assets/js/features/core-shield.js` updated to scope its click handler to `[data-cb-core-master-switch="core-shield"]` and read state via the new data-attributes — same `cb_core_set_shield` AJAX endpoint, same optimistic-UI flow, same reload-on-success.

#### Changed — Login Shield: master switch up top, secondary fields stay editable

`templates/login-shield.php` now renders the MasterSwitch component as its own panel above the configuration form. The previous in-form `Field::render()` enable section is gone. Secondary fields (URL slug, mode, redirect, advanced response code) remain editable when the master is off — operators can prepare a configuration before flipping the switch, and an explicit "Login Shield is disabled" status banner signals that saved settings are not currently enforced.

The `Field::render()` variant `'enable'` is now orphan; flagged for cleanup in a later patch (one concern per release).

#### Changed — Login Shield AJAX split into two endpoints

The master-switch needs to flip immediately, like Core Shield's does — not as part of a batched form save. The existing `cb_core_login_shield_save` would have set `enabled` to false on every batch save that didn't include the field, which is exactly the silent-toggle-off failure mode we want to avoid. Two changes:

1. **New endpoint `cb_core_login_shield_set_enabled`**: atomic master-switch toggle. Persists only the `enabled` flag, preserving every other setting. Toggling on without a saved slug is rejected with HTTP 400 + a clear message — same rule `save()` enforces, surfaced earlier so the optimistic-UI flow can revert cleanly.
2. **`save()` reworked**: when `enabled` is absent from `$_POST`, the current master state is preserved. When present, behaves as before. Decouples the form-batch save from the master switch without breaking back-compat for any caller that still sends the field.

#### Changed — Login Shield JS

`enabled` removed from `serializeConfig()`. The slug-required pre-save check is gone (no longer relevant in this code path; the master-switch endpoint enforces it server-side). New master-switch click handler scoped to `[data-cb-core-master-switch="login-shield"]`, posts to `cb_core_login_shield_set_enabled`, optimistic-UI with revert on rejection, reloads on success so banners refresh from server-derived state.

New i18n key `lsMasterToggleFailed` for the error path.

### Removed — orphan CSS in `pages/safeguards-login-shield.css`

The `.cb-core-ls-toggle-label` / `.cb-core-ls-toggle-text` rules styled the now-removed enable section. Pure removal — no other consumers.

## [1.3.21-dev] — unreleased

### Fixed — Disable Beacon button hidden in Ready state

Safeguards → Beacon hid the **Disable Beacon** affordance whenever Beacon was enabled but no secret key existed yet (the "Ready to activate" state, including the moment immediately after clearing a key). The original gate condition (`$is_active || ! $is_enabled`) treated the Ready state as having "nothing to disable" — but this is misleading: REST endpoints, scheduled tasks, and the `.htaccess` Authorization-passthrough rule are all live in the Ready state.

Operators who cleared their secret key could plausibly conclude that Beacon was off, when in fact only the credential was gone. The infrastructure remained active.

**Resolution:** `templates/beacon.php` now always renders the Toggle Beacon row. The Disable form is shown whenever `$is_enabled` is true (covering both Active and Ready), and the Enable form when Beacon is off. The "secret key remains in storage" sentence in the description is conditional on `$has_key` so the wording stays accurate in the Ready state where no key exists.

The toggle handler (`Beacon\Admin\Toggle`) is unchanged — it already disables cleanly regardless of key presence; only the UI gate was wrong.

## [1.3.20-dev] — unreleased

Dead-code cleanup pass following 1.3.19-dev. With all baseline operations now using the page-reload pattern, the legacy `updateSummary()` JS rendering pipeline is fully orphaned: 16 JS functions, 37 PHP i18n entries, and 3 POT/PO msgids together accounting for ~375 lines of code that no longer runs.

This is one concern per release as agreed with the audit-discipline established in 1.3.18-dev — pure removal, no behavioural changes, no risk of regression beyond accidental over-removal (caught by the verification step below).

### Removed — 16 dead JS functions

The legacy `updateSummary()` pipeline rebuilt result-state DOM in-place after baseline operations, with markup that pre-dated the 1.3.13-dev state-based UI refactor. Since 1.3.19-dev moved every caller to page-reload, this entire ecosystem became unreachable:

- `updateSummary()` — orchestrator, no callers.
- `renderComponents()` — built the old `<div>`-based components grid (the cause of the filter bug fixed in 1.3.19-dev).
- `renderGroups()` — rebuilt findings groups with old markup. Used twice by `updateSummary()` for both findings and verified blocks.
- `renderComponentGroup()` — single-group renderer, called only by `renderGroups()`.
- `renderFinding()` — per-finding renderer, called only by `renderComponentGroup()`.
- `renderChildren()` — child-finding renderer, called only by `renderFinding()`.
- `renderDiff()` — rebuilt the changes-since-last-scan panel.
- `renderDiffComponent()` — called only by `renderDiff()`.
- `renderDiffList()` — called only by `renderDiff()` and `renderDiffComponent()`.
- `updateBaseline()` — synced the baseline metric tile.
- `renderHistory()` — rebuilt the scan-history list.
- `syncBaselineActionBar()` — added/removed the Clear Approved Baseline button without reload. Obsolete now that baseline operations always reload.
- `setText()` — only used by `updateSummary()` for summary-tile text updates.
- `escapeHtml()` — only used by the dead renderers (live code uses `escapeHtmlText()` for `injectProgressMarkup()`).
- `escapeAttr()` — only used by the dead renderers.
- `capitalize()` — only used by the dead renderers.

Net effect: `assets/js/features/core-scanner.js` shrinks from 1231 lines to 856 lines (−30%, 44 → 28 functions).

### Removed — 37 dead PHP i18n entries

Each entry in `src/Admin/Admin.php`'s `i18n` localized array was the JS-side string for one of the dead functions above. With the JS gone, the keys had no consumers. Removed:

`approvedOn`, `baselineApprove`, `baselineLabel`, `changeMayBeExpected`, `componentDefault`, `diffChanged`, `diffMissing`, `diffNew`, `diffUnchanged`, `entriesCreated`, `findingMany`, `findingOne`, `noBaseline`, `noChangesSincePrevious`, `noComponentResults`, `noIssuesDetected`, `noPreviousScan`, `noPreviousScansStored`, `noScanActive`, `noVerifiedYet`, `notSet`, `passedCheckMany`, `passedCheckOne`, `pathLabel`, `scannedFileMany`, `scannedFileOne`, `showingFindings`, `showingLatestScans`, `showingVerified`, `statusApproved`, `statusNone`, `summaryCritical`, `summaryOk`, `summaryWarnings`, `tagBaseline`, `tagCurrent`, `verificationLabel`.

### Removed — 3 dead POT/PO msgids

Most of the 37 i18n strings above are shared with server-rendered templates (Page.php uses the same words for "OK", "APPROVED", "Component", etc.). Only msgids exclusive to the JS-only payload were removed:

- "Showing {count} verified component groups."
- "{n} scanned file" / "{n} scanned files" (parametrised count, plural form pair).

The remaining 34 strings stay in the POT/PO because they appear in templates as well — those `__()` calls are still alive.

MO recompiled: 1173 translated messages (was 1175 in 1.3.19-dev).

### Verification

Each removal was preceded by a dependency-graph trace:

1. Identified all functions in `core-scanner.js` and tallied call-sites per function.
2. Traced indirect usage: a function whose only callers are themselves dead is itself dead. Walked the chain until stable.
3. Listed all i18n keys referenced inside the dead-function block versus the live block.
4. After the JS removal, verified every remaining `i18n.{key}` reference in live JS still has a corresponding entry in Admin.php (zero orphans found).

PHP-lint clean. JS syntax-clean (`node --check`). MO compiles with no fuzzy/error.

### Internal changes

- Modified: `assets/js/features/core-scanner.js` — removed `setText()`, `renderComponents()`, `renderGroups()`, `renderComponentGroup()`, `renderFinding()`, `renderChildren()`, `renderDiff()`, `renderDiffComponent()`, `renderDiffList()`, `updateBaseline()`, `renderHistory()`, `updateSummary()`, `syncBaselineActionBar()`, `escapeHtml()`, `escapeAttr()`, `capitalize()`. Removed empty `// ─── Renderers ───` and `// ─── Action handlers ───` section banners. File: 1231 → 856 lines.
- Modified: `src/Admin/Admin.php` — removed 37 dead i18n entries from the `i18n` array passed to `wp_add_inline_script()`. The array still contains all live keys; nothing JS depends on is missing.
- Modified: `languages/core-blueprint.pot` — removed 3 msgids that were only referenced by the deleted JS code. POT-Creation-Date refreshed.
- Modified: `languages/core-blueprint-nl_NL.po` — same 3 msgid entries removed. MO recompiled (1173 translated).

### Audit findings still parked

Two findings from the 1.3.18-dev audit remain on the roadmap:

- DRY refactor of `ScannerEngine::run()` phase blocks — own release.
- Trivial double `ResultRepository::settings()` call in `update_settings` — picked up at next refactor of that endpoint.

## [1.3.19-dev] — unreleased

Bug-fix: Components filter buttons stopped working after any baseline operation (Approve Baseline, Clear Approved Baseline, Approve component baseline, Remove from baseline). Fixed by reloading the page after success — same pattern as Clear Results since 1.3.15-dev.



### Why this was happening

The Core Scanner has two JS rendering paths:

1. **State-based server rendering** (1.3.13-dev onwards) — Page.php detects state, renders idle/scanning/result with the right DOM structure. Components are `<button>` elements with `data-cb-integrity-component-filter` attributes; `bindComponentFilter()` attaches click listeners on page-init.
2. **Legacy `updateSummary()` in-place updates** (pre-1.3.13-dev) — `renderComponents()` rebuilds the Components grid using the old `<div class="cb-core-integrity-component">` markup, with no filter attributes and no listeners.

Four baseline operations were still using path #2 after success:
- `approveBaseline()` — full baseline approval
- `clearBaseline()` — clear baseline
- `approveComponentBaseline()` — single component approval (the case Chris reported)
- `removeComponentBaseline()` — remove a component from baseline

When any of these ran, the response payload triggered `updateSummary()`, which called `renderComponents()`, which replaced the new `<button>` markup with old `<div>` markup. Filter buttons silently stopped being filter buttons — they became inert divs that looked the same. Click did nothing.

This was technical debt from the 1.3.13-dev state-based UI refactor: the new pattern was applied to scan-completion (page-reload after async scan) and Clear Results (page-reload in 1.3.15-dev), but the four baseline operations were missed.

### Fix

All four functions now follow the same pattern as `clearResults()`: 800ms toast → reload. Server re-renders the result-state with the correct DOM, filter buttons work again, all summary tiles / component states / verified counts come through correctly without any in-place patching.

### Side effect — `updateSummary()` and helpers are now dead code

With the four baseline operations switched to page-reload, `updateSummary()` has zero callers. Its helper functions (`renderComponents`, `renderGroups`, `renderDiff`, `renderHistory`, `updateBaseline`) are equally orphaned. Plus `syncBaselineActionBar()` which only existed to keep the action-bar buttons in sync after baseline approve/clear without a reload — which now always reloads.

Together these are roughly 200 lines of legacy JS that no longer runs. Per the audit-discipline established in 1.3.18-dev (one concern per release), this is parked for a dedicated cleanup pass — easier to review when scoped to "remove dead JS" rather than mixed with a bug fix.

### Internal changes

- Modified: `assets/js/features/core-scanner.js::approveBaseline()` — replaces `updateSummary(payload) + syncBaselineActionBar(true)` with toast + 800ms `window.location.reload()`.
- Modified: `assets/js/features/core-scanner.js::clearBaseline()` — same pattern.
- Modified: `assets/js/features/core-scanner.js::approveComponentBaseline()` — same pattern.
- Modified: `assets/js/features/core-scanner.js::removeComponentBaseline()` — same pattern.
- Documented: `updateSummary()`, `renderComponents()`, `renderGroups()`, `renderDiff()`, `renderHistory()`, `updateBaseline()`, `syncBaselineActionBar()` are now dead code. Cleanup deferred to dedicated pass.

## [1.3.18-dev] — unreleased

Quality-audit pass on the Core Scanner subsystem. Three findings addressed: dead code from the 1.3.13-dev async refactor, an inline `style` attribute that crept into a template, and a per-flush DB write that should have been a one-time event at construction.



### Removed — dead `setPageBusy()` JS function and orphan CSS

`setPageBusy(isBusy)` in `assets/js/features/core-scanner.js` toggled a `cb-core-integrity-is-busy` class on the wrap during synchronous scans (1.3.12-dev and earlier). The 1.3.13-dev async refactor replaced this with the sticky-progress-block + dimmed-siblings pattern, but `setPageBusy()` itself stayed in the file with zero callers. The matching `.cb-core-integrity-is-busy` CSS rules in `assets/css/pages/safeguards-core-scanner.css` were equally orphaned.

Both removed. The function and its three CSS rules contributed nothing to current behaviour and only added confusion for future readers.

### Fixed — inline `style` attribute in `Page.php` template

`render_result_state()` rendered the Verified / Passed Checks `<details>` summary with `<h2 style="display:inline; margin:0;">`. Inline CSS in templates violates the CB-suite styling convention (all visual concerns through tokens in `assets/css/`) and produces theme-blind markup — the inline `display: inline` doesn't respect any cascade.

Replaced with a class-based selector. The `<details>` element gains a `cb-core-integrity-verified-panel` class, and the new CSS rule `.cb-core-integrity-verified-panel > summary > h2 { display: inline; margin: 0; }` lives in the stylesheet.

### Fixed — `register_in_index()` called on every flush instead of once at construction

`TransientProgressReporter::flush()` (called on every tick + every phase boundary) ended with a call to `register_in_index()`. That helper does:

- `get_transient()` — DB read of the active-job index
- `in_array()` check — almost always returns true after the first call
- `set_transient()` — DB write of the unchanged index

A scan with ~10 flushes triggered ~10 unnecessary `wp_options` reads and writes, all confirming what the first call already established (the job is in the index). Not catastrophic — `set_transient()` is fast — but pure waste at the database layer.

The fix moves the `register_in_index()` call into the constructor, where it belongs: a job is registered in the active-index exactly once, at the moment the reporter is created. Subsequent flushes only update the per-job transient with progress data, not the index.

Net effect on a typical scan: ~10 fewer DB roundtrips per scan, no behavioural change. `find_active()` continues to work exactly as before — it walks the index, calls `read()` on each entry, and any TTL-expired entries return null without affecting correctness.

### Internal changes

- Modified: `assets/js/features/core-scanner.js` — removed `setPageBusy()` function (lines 111–117 in the previous version). No callers remained after 1.3.13-dev.
- Modified: `assets/css/pages/safeguards-core-scanner.css` — removed three orphan `.cb-core-integrity-is-busy` rules. Added `.cb-core-integrity-verified-panel > summary > h2` rule replacing the inline style.
- Modified: `src/Integrity/Admin/Page.php::render_result_state()` — replaced inline `style="display:inline; margin:0;"` on the verified-panel `<h2>` with class-based styling. Added `cb-core-integrity-verified-panel` class to the `<details>` element.
- Modified: `src/Integrity/Scanner/TransientProgressReporter.php` — moved `register_in_index()` call from `flush()` to constructor. Added comment documenting the pre-1.3.18 behaviour and why the change is correct.

### Audit findings parked for future releases

Two additional findings from the audit were left untouched in this release:

- **DRY refactor opportunity in `ScannerEngine::run()`** — the four phase blocks (core / plugins / themes / uploads) are 95% structurally identical. A private `run_phase()` helper could remove ~50 lines and reduce regression surface for future scanner additions. Deferred because the scanner is currently stable; refactor risk is real, and dedicated test cycle warranted.
- **Trivial double `ResultRepository::settings()` call in `update_settings` endpoint** (regel 615 + 617). Pre-existing, not introduced in 1.3.x. Will be picked up in the next refactor pass on that endpoint.

## [1.3.17-dev] — unreleased

Three fixes from 1.3.16-dev testing: scan duration regression rolled back, Components filter button readability and click-on-OK-component empty state.



### Fixed — scan duration regression (14s → ~1.5s)

`precount_phases()` from 1.3.13-dev called `get_core_checksums()` once before the scan to compute item counts for progress weighting. `CoreScanner::scan()` calls the same function during the actual scan. WP caches the response in a transient, but on cache miss (first scan, transient expiry) two sequential WP.org HTTP round-trips occurred. Measured cost: ~12s additional per scan on a fast server, projected to 30–60s on shared hosting where outbound HTTP is slower and `max_execution_time = 30s` is default.

The cost bought a smoother percentage curve between phase boundaries — visual polish only, no impact on what the scan actually verifies.

### Fix

`precount_phases()` now returns `[ 'core' => 0, 'plugins' => 0, 'themes' => 0, 'uploads' => 0 ]` directly. The JS progress UI detects "no item counts" and falls back to equal-weight phase progress: each completed phase advances the bar by 25%, with phase labels and the elapsed timer giving operators concrete progress feedback. Honest feedback, no duplicate API calls, no host-compatibility risk.

If real per-item progress ever becomes a requirement (operator-facing reason, not visual polish), the right place is inside the scanner engines themselves — `CoreScanner`, `PluginScanner`, etc. would call `$reporter->tick()` per file during their own iteration loop. That is a refactor for a later release; out of scope here.

### Fixed — Components filter button text unreadable

The filter buttons (Core / Plugins / Themes / Uploads in the Components panel) had `background: transparent` which overrode the inherited `var(--cb-surface-0)` background from `.cb-core-integrity-component`. Combined with the `<button>` element's user-agent `color: ButtonText` blocking inherited text colour, the labels rendered nearly invisible against the dark panel.

Fix: explicit `background: var(--cb-surface-0)` and `color: var(--cb-text)` on `.cb-core-integrity-component-filter`. Active-state background uses `color-mix()` with `--cb-surface-0` as the base instead of `transparent` — keeps the card visible regardless of theme. Added `:focus-visible` outline using `--cb-accent` for keyboard navigation.

### Fixed — Clicking OK-status component showed empty Findings (looked broken)

Components panel exposes all four components as filter buttons, including those with status OK. Clicking "Core OK" filtered Findings to `core` — but Findings only contains non-approved checks (warnings/critical). OK-status components have nothing in Findings; their checks live in the Verified / Passed Checks block. Result: clicking an OK component made Findings look empty, with no explanation.

Fix: added a `<p id="cb-core-integrity-filter-empty">` placeholder to the Findings section, hidden by default. The `applyFilter()` JS now counts visible groups after the filter pass — when the filter is active and zero groups are visible, the placeholder unhides with the message "No issues for this component. All checks passed — see Verified / Passed Checks below." Operator gets immediate informative feedback instead of a silent empty state.

### Internal changes

- Modified: `src/Integrity/Scanner/ScannerEngine.php::precount_phases()` — body stripped to a constant zero-counts return. Method signature retained for forward-compat with future per-item-tick refactors. Unused imports (`array_sum`, `count`, `function_exists`, `ABSPATH` const) removed.
- Modified: `assets/css/pages/safeguards-core-scanner.css` — explicit `color`, `background`, `box-sizing`, `:focus-visible` outline on `.cb-core-integrity-component-filter`. Active-state background uses surface-0 base.
- Modified: `src/Integrity/Admin/Page.php::render_result_state()` — added empty-state placeholder paragraph after the findings container.
- Modified: `assets/js/features/core-scanner.js::bindComponentFilter()` — `applyFilter()` counts visible groups, toggles `#cb-core-integrity-filter-empty` visibility based on filter state.
- POT/PO updated: 1 new string ("No issues for this component…"), MO recompiled (1176 translated).

## [1.3.16-dev] — unreleased

Hotfix: `runScan()` function declaration was stripped during the 1.3.15-dev patch (`str_replace` consumed the signature when injecting `injectProgressMarkup()` before it). Caused `SyntaxError: Illegal return statement` on page load — Run Core Scanner button broken entirely. Restored the signature; function body had remained intact throughout.

### Internal changes

- Modified: `assets/js/features/core-scanner.js` — restored the `async function runScan( button ) {` declaration that got dropped during the 1.3.15-dev patch when `injectProgressMarkup()` was inserted before it. The function body remained intact; only the signature was missing.

### Lesson logged

JS syntax-check before zipping (`node --check`) — would have caught this. Adding to the release checklist for future JS-touching releases.

## [1.3.15-dev] — unreleased

Bug-fix patch addressing three issues discovered during 1.3.14-dev testing:
the progress UI never appeared during a scan, the underlying content
wasn't dimmed, the idle-state after Clear Results showed stale result
blocks, and the components-as-filter buttons did nothing on click.

### Fixed — Progress block invisible during scan

The progress markup is server-rendered only in the *scanning* page-state
(by `Page::render_scanning_state()`). When the operator clicked Run from
*result* or *idle*, the page-state on the server was whatever it had
been — the progress DOM didn't exist yet. `runScan()` POSTed and started
polling, but `renderProgress()` did `if (!fill) return;` on every poll
because there was no fill element to update. Result: button showed
"Running Core Scanner" but no progressbar / timer / phase label.

Fix: `injectProgressMarkup()` JS function builds the same DOM the server
would have rendered and inserts it at the top of the wrap *before*
polling starts, then sets `data-cb-integrity-state="scanning"` and the
`cb-core-integrity-state-scanning` class on the wrap so the CSS sibling
selector `.cb-core-integrity-state-scanning .cb-core-integrity-progress-panel ~ *`
activates and dims everything below. Idempotent: a stale progress block
from a previous scan is reused rather than duplicated.

This also fixes the "underlying content not dimmed" symptom — that was
a downstream consequence of the scanning-state class never being set.

### Fixed — Idle state after Clear Results showed stale result blocks

`Clear Results` ran a DELETE, then JS `updateSummary(payload)` updated
the summary tiles in-place to 0/0/0/none. But the Components, Findings,
Verified, About-this-scan blocks all stayed visible — those are
server-rendered and no JS removed them. Operator saw an idle status
but a result-state UI.

Fix: `clearResults()` now reloads the page after the success toast
(800ms delay, same pattern as the baseline-clear flow). The server
re-renders to idle-state because `getLatest()` returns null after
clear. Only Run-CTA + empty-state messaging + history + locale +
settings remain visible.

### Fixed — Components-as-filter buttons did nothing on click

Pluralisation mismatch between filter-button source and finding-group
attribute:

- Filter buttons (Components panel) use plural keys from
  `$summary['components']` — `core` / `plugins` / `themes` / `uploads`.
- Finding groups (Findings panel) use the singular `component` field
  on each finding — `core` / `plugin` / `theme` / `uploads`.

Click on `[data-cb-integrity-component-filter="plugins"]` looked for
`[data-cb-integrity-component-group="plugins"]`, found nothing
(the section was tagged `="plugin"`), hid all groups silently.

Fix: in `Page::render_grouped_findings()` we map singular → plural
for the data attribute via a `match` expression: `'plugin' => 'plugins'`,
`'theme' => 'themes'`, others pass through. Filter button + finding
group now both use plural canonical keys.

### Internal changes

- Modified: `assets/js/features/core-scanner.js` — new `injectProgressMarkup()`
  function called from `runScan()` (both 202 path and 409 resume path);
  `escapeHtmlText()` defence-in-depth helper for innerHTML interpolation;
  `clearResults()` now reloads after success instead of in-place summary
  update.
- Modified: `src/Integrity/Admin/Page.php::render_grouped_findings()` —
  singular→plural mapping for `data-cb-integrity-component-group`
  attribute via `match` expression. Filter-button click now correctly
  targets finding-group sections.
- Modified: `src/Admin/Admin.php` — added `runningScan` and
  `scanProgressAria` i18n keys (server already had the equivalent
  strings from 1.3.13-dev for the server-rendered scanning state; this
  exposes them to the JS injection path too).



## [1.3.14-dev] — unreleased

Bug-fix patch: `Clear Approved Baseline` no longer fatals when no scan result is on file.

### Fixed — `response_payload()` TypeError when result is null

`Clear Approved Baseline` triggered an uncaught `TypeError` in PHP 8: *"Argument #1 ($result) must be of type array, null given"*. The toast surfaced the raw error message; a second click would succeed because by then the baseline had been cleared and no further code path tried to format a null result.

### Root cause

`ScanController::response_payload()` had a strict `array` type-hint. `clear_baseline()` calls it with `ResultRepository::getLatest()` directly — which returns `null` when no scan result is on file. The legitimate "no result" case (cleared results, fresh install, scan progress polling immediately after a clear) hit the type-hint and threw.

Several other endpoints have the same call pattern. Centralising the null-handling in `response_payload()` itself fixes them all in one place — robust by default rather than every call-site defending against null.

### Fix

`response_payload()` now accepts `?array` and substitutes `[]` when null. The downstream formatters (`ResultFormatter::summary()`, `limited_findings()`, etc.) all already accept null/empty input and fall back to idle-state defaults, so the empty-array path produces the correct empty-state response payload.

### Internal changes

- Modified: `src/Integrity/Rest/ScanController.php::response_payload()` — type-hint relaxed from `array` to `?array`, null normalised to `[]` at the top of the method. Docblock documents which legitimate call paths produce null and why centralising the fallback is the right architecture.

## [1.3.13-dev] — unreleased

State-based UI refactor combined with async scanning, live progress feedback, and persisted scan-duration anomaly detection. The Core Scanner page is rebuilt around three discrete states (idle / scanning / result) with the scan itself moved off the synchronous REST request path onto WP-cron-driven background execution. Operator now sees real-time progress, a live timer, and an anomaly indicator when a scan takes substantially longer than the previous one — with full forward-compat for the side-by-side scan comparison and configurable thresholds planned for later releases.

### Why this release exists

Before 1.3.13-dev the page was one continuous vertical layout that mixed three different operator contexts: empty/idle (no scan result on file), in-progress (a scan running), and post-result (analysis + actions). Every block stayed in the DOM regardless of state, with CSS classes (`cb-core-integrity-no-result-only`, `cb-core-integrity-result-only`) toggling visibility. Result: idle-state showed empty placeholders ("No previous scan available for comparison yet"), scanning-state had no obvious place to surface progress, and screen-readers walked through hidden DOM. Beneath that, `POST /scan` ran the entire scan synchronously in the request thread — meaning no progress feedback was even possible (no channel for the server to stream updates back), browser timeouts on slower hosts, and an architecturally problematic foundation for any future Hub-fleet scan view.

This release fixes both layers in one coherent unit: state-based rendering server-side + async scan execution + transient-backed progress polling + a duration-tracking anomaly detector that surfaces when a scan suddenly takes 3× longer than its predecessor (early signal for server load issues, lock contention, slow WP.org API, etc.).

### Architecture

#### Async scan execution

The synchronous REST scan handler is gone. `POST /integrity/admin/scan` now schedules a single-shot WP-cron event (`cb_core_integrity_run_scan_async`) and returns 202 with a `job_id` and `started_at` within milliseconds. `spawn_cron()` is invoked immediately to fire the queued event in a loopback request without waiting for the next page-load. The scan runs in the cron handler with a `TransientProgressReporter` that writes phase-level progress to a transient (`cb_core_integrity_scan_progress_{job_id}`, 15-minute TTL).

The cron handler is wrapped in try/catch so an uncaught exception during the scan flips status to `error` and writes an `integrity_scan_failed` audit-log entry — the UI stops polling within one tick and surfaces the failure rather than spinning indefinitely.

A new `ProgressReporter` interface (`src/Integrity/Scanner/ProgressReporter.php`) decouples the engine from the transport layer. Two implementations ship in this release: `NullProgressReporter` (cron-runs, hub-bound scans, tests) and `TransientProgressReporter` (admin UI). The engine itself is reporter-agnostic — it calls `start_phase()`, `tick()`, `complete_phase()`, `complete_scan()` at well-defined lifecycle points and the reporter decides what to do with those signals.

`TransientProgressReporter` debounces per-tick writes to the transient: an in-memory state snapshot is updated on every tick but the actual `set_transient()` call only fires when the phase changes, on the first/last tick of a phase, or after 500ms have passed. For a 665-check scan that means ~10 transient writes instead of 665 — meaningful at WP-options-table scale on shared hosting.

#### Conflict handling

`POST /scan` checks for an active job before scheduling a new one. If found, returns `409 Conflict` with the `existing_job_id` so the UI can resume polling against that running job rather than starting a duplicate. The active-job lookup uses a small index option (`cb_core_integrity_scan_progress_active_index`) capped at 20 entries, walked once per POST.

#### Synchronous fallback

On hosts where `DISABLE_WP_CRON = true` (typical for installs with real cron via Plesk/cPanel), `wp_schedule_single_event()` returns false. The handler detects this and falls back to a synchronous run within the REST request — preserving 1.3.12 behaviour for those hosts. The response carries `async: false` so the JS shows a one-time notice ("Async scanning unavailable on this host — scan ran inline") and reloads to render the result-state UI. Operators on cron-enabled hosts get the full async experience; operators on cron-disabled hosts get the legacy synchronous experience without breaking.

#### Page-state resolution (server-side)

Page.php now opens with a `resolve_page_state()` switch that returns `'idle'` / `'scanning'` / `'result'` based on the active progress transient + stored latest result. Each state has its own dedicated render method:

- **`render_idle_state()`**: Run-CTA, empty-state messaging, About-this-scan accordion, plus the always-visible blocks (history, distribution-locale, settings).
- **`render_scanning_state()`**: sticky progress block at the top with live timer, percentage bar, phase label. Underlying content dimmed via CSS sibling selector — non-interactive but not body-locked (would create scrollbar layout shifts in WP admin per CP-10).
- **`render_result_state()`**: summary tiles + duration + (anomaly indicator if applicable) + components-as-filter + findings + verified (collapsed by default) + diff (only if has-changes).

The `cb-core-integrity-no-result-only` / `cb-core-integrity-result-only` CSS-toggle pattern is removed entirely.

#### Components as findings filter

The Components block (Core / Plugins / Themes / Uploads status pills) is now clickable. Each card is a `<button>` with `data-cb-integrity-component-filter` and `aria-pressed`. Clicking filters the findings below to that component only; a "Show all" reset button appears next to it. Per CP-9, intentionally simple: no URL state, no saved filter preference, no multi-select. JS hides finding-group sections by their `data-cb-integrity-component-group` attribute. Refresh page = filter cleared.

#### Verified / Passed checks default-collapsed

The Verified block was previously expanded by default, requiring operators to scroll past 8+ rows of "all good, all good, all good" before seeing the actual findings. Operators want to know what's *broken*, not what's verified. Verified is now a `<details>` element without `open`, with the section count in the summary. One click to expand for audit purposes.

#### Diff panel — only when there are changes

The "Changes Since Last Scan" section now only renders when there are actual changes. Empty diff with placeholder text ("No previous scan available for comparison yet") was visual noise on idle and first-scan flows. Per CP-9.

#### "Carefully designed" relocation

Removed as a top-of-page section. Equivalent content (best-effort framing, what-this-does-not-do bullets) lives inside the About-this-scan accordion which is now reachable from both idle and result states.

#### Distribution Locale panel — UI vs Distribution surfaced separately

Per CP-10, the panel now shows three explicit lines:
- UI locale (what `get_locale()` returns)
- Distribution (what the scanner actually verifies against — override / detected / fallback to UI locale, with suffix label)
- Detection status (Auto / Manual override / Not detected yet)

Plus the existing Last-detection / Matched-file / Cross-check details. Tried-locales remains in the inline collapsible.

#### Anomaly detection v1

On scan completion, `ScannerEngine::detect_duration_anomaly()` compares `current.duration_seconds` to `previous.duration_seconds`. If `ratio > 3.0`, the result payload carries `meta.anomaly = { type: 'slower', ratio, previous_seconds, current_seconds }`. The UI renders an inline orange notice in result-state: "This scan took longer than usual — Previous: 4.2s · this scan: 12.4s · 3.0× slower". Skipped when previous lacks `duration_seconds` (pre-1.3.13 entries) or when fewer than two entries exist.

Hardcoded threshold per CP-7 — configurable threshold + σ-based detection over rolling window are forward-compatible (`meta.anomaly` schema is additive) but not in v1.

### Forward-compat hooks

Future releases can build on this without breaking changes:
- Cancel button — markup is in place but disabled in v1; cooperative cancellation in the engine is a follow-up.
- Side-by-side scan comparison — history entries now carry `started_at`, `duration_seconds`, `phase_durations`, `total_files_scanned`. Compare button on Logs-tab is its own scope.
- Configurable threshold — `meta.anomaly` schema is forward-compatible; settings UI is a 1.3.15-dev addition.
- Hub-fleet progress aggregation — async dispatch + transient-backed state make multi-site progress feasible later. Out-of-scope for v1.

### Internal changes

- New: `src/Integrity/Scanner/ProgressReporter.php` — interface, lifecycle contract.
- New: `src/Integrity/Scanner/NullProgressReporter.php` — no-op for cron/hub.
- New: `src/Integrity/Scanner/TransientProgressReporter.php` — debounced transient writes, active-job index, find_active() static helper.
- Modified: `src/Integrity/Scanner/ScannerEngine.php` — `run()` now accepts an optional `ProgressReporter`, defaults to `NullProgressReporter`. Per-phase timing captured. New `precount_phases()` for progress weighting (uploads approximated from previous run, never recursive walk). New `detect_duration_anomaly()`. Result payload carries `duration_seconds`, `phase_durations`, `total_files_scanned`, `anomaly`.
- Modified: `src/Integrity/Rest/ScanController.php` — `scan()` returns 202 / 409 / 200(sync-fallback). New `scan_progress()` polling endpoint at `GET /integrity/admin/scan-progress`. New REST route registration.
- Modified: `src/Integrity/Bootstrap.php` — registers `cb_core_integrity_run_scan_async` cron-action handler with try/catch wrapper. Constant `ASYNC_SCAN_HOOK` exported for REST use.
- Modified: `src/Integrity/Admin/Page.php` — full refactor. `resolve_page_state()`, `render_idle_state()`, `render_scanning_state()`, `render_result_state()`, `render_about_scan_content()` shared fragment. Distribution Locale panel split into UI/Distribution/Detection-status lines. Findings group headers gain `(N issues)` / `(N verified)` count. Verified block becomes default-collapsed `<details>`.
- Modified: `assets/js/features/core-scanner.js` — new module-level state (`activeJob`, `pollHandle`, `timerHandle`), localStorage persistence for resume-across-reload, async scan flow with 202/409/sync handling, `pollProgress()` + `updateLiveTimer()` loops, `computePercentage()` weighted across phases, `phaseLabel()` localised, `bindComponentFilter()` simple filter, `resumeActiveJobIfAny()` page-init resume. `request()` helper now attaches `status` + `payload` to thrown errors so 409 callers can recover.
- Modified: `assets/css/pages/safeguards-core-scanner.css` — sticky progress block (`top: 32px` / `46px` mobile), progress-bar layout, dimmed scanning state via `~ *` sibling selector (no body-lock per CP-10), components-as-filter button styling, anomaly indicator styling using `--cb-warning` token.
- New action hook: `cb_core_integrity_run_scan_async` (cron-event hook, internal).
- New audit-event entry: `integrity_scan_failed` already existed in 1.3.5-dev label registry; reused for cron-handler exception path.
- POT/PO updated: 28 new strings, MO recompiled (1175 translated).

### Test coverage (manual verification path)

| Scenario | Expected |
|---|---|
| Run scan on async-enabled host | Progressbar appears < 200ms, timer ticks, phase label updates per phase, page reloads to result-state on completion |
| Run scan, close tab mid-scan, reopen | Server scan completes; reopening the page shows result-state (or scanning-state if still running, with resume polling) |
| Run scan, click Run again before completion | 409 returned; UI shows "scan already running, progress restored" toast; existing job's progressbar resumes |
| Run scan on `DISABLE_WP_CRON=true` host | Sync fallback runs inline; UI shows notice + reloads to result-state |
| Scan crashes (simulate exception in cron handler) | UI polling detects `status=error` within 1s; toast surfaces error; page reloads |
| Run scan after first install (no previous) | No anomaly check; clean result-state |
| Run scan that is 3× slower than previous | Anomaly indicator renders in orange with "Previous: X · this scan: Y · 3.0× slower" |
| Idle page (cleared results) | Run-CTA + empty-state messaging + history + locale + settings only — no findings/components/verified blocks |
| Click Components → Plugins | Findings filter to plugins; "Show all" appears; click again deselects |
| Verified block in result-state | Collapsed by default; click to expand |
| Diff panel | Only renders when has-changes; hidden for first scan |
| Distribution Locale panel | Three explicit lines: UI locale + Distribution (with suffix) + Detection status |
| Cron-triggered scheduled scan | Runs with NullProgressReporter; no UI state visible; audit-log entries written normally |
| Hub-bound scan endpoint | Unchanged behaviour, NullProgressReporter, sync result return |



### The problem

`get_locale()` returns the UI-rendering locale of a WordPress installation. That is NOT the same as "which official WordPress distribution was downloaded onto this disk". The two diverge when a site is installed as one locale (typically en_US) and later switched to another (e.g. nl_NL or de_DE) via Settings → General → Site Language. WordPress does NOT re-download core files on a UI-locale switch — it only changes the active language pack. Result: core files are still the en_US distribution while `get_locale()` returns 'nl_NL'.

The WP.org checksums API returns a different expected hash for `wp-includes/version.php` per official locale distribution (because non-en_US distributions historically include a `$wp_local_package` line that en_US does not). Naively asking the API for the UI-locale's checksums then produces a false-positive critical finding on `version.php` — exactly what was happening on operator's Beacon site (NL UI on an en_US distribution).

WP-CLI's `wp core verify-checksums` quietly handles this; our scanner did not. As CB Suite is positioned for healthcare, government, and educational organisations where a security-product producing false-positives directly undermines its credibility, "patch the symptom" was insufficient. This release is the structural fix.

### Architecture (Layer 1 + minimal Layer 2 of a 4-layer plan)

The scanner now resolves an "effective locale" before fetching checksums:

1. **Operator override** (`mode === 'override'`) — explicit operator choice via Settings.
2. **Auto-detected pin** (`mode === 'auto'`) — last successful detection result, persisted.
3. **UI-locale fallback** (`mode === 'fallback'`) — legacy `get_locale()` behaviour.

Detection runs *lazily*: only when a checksum mismatch occurs on the discriminator file (`wp-includes/version.php`) during a fallback-path scan. Clean en_US installs incur zero detection cost; sites with drift get auto-corrected on the first scan that surfaces it.

Detection-locale candidates are tried in order: `get_locale()`, `en_US`, `WPLANG` constant (legacy), `WPLANG` site-option (multisite), then all entries from `get_available_languages()`. First hash match wins.

### Drift vs tampering

A new `LocaleDetector::cross_check()` step verifies the locale-agnostic core files (`wp-includes/load.php`, `wp-settings.php`) against the matched payload after the discriminator matches. These files have the same hash across all official distributions for a given WordPress version, so a mismatch on them is not explainable by locale-drift — it signals tampering at a deeper level. When cross-check fails, detection is marked inconclusive and not pinned, so the discriminator mismatch surfaces as `tampering` rather than a misleading `distribution_drift`.

The `Finding` model gains a `category` field with three values:

- `tampering` (default, backwards compatible) — security-relevant, escalates scan status.
- `distribution_drift` — file matches a different official distribution. Severity `info`, no scan-status escalation.
- `informational` — context observations.

`CoreScanner::aggregate_status()` skips drift-only findings when computing the overall scan status, so a clean site with confirmed drift reports `ok` rather than `info` or warning.

### UI: Distribution Locale panel

Always visible above the Settings panel in the Core Scanner page. Compact: a status line, the UI-locale for comparison, last-detection timestamp, matched file, cross-check outcome, and a collapsible "Tried locales" diagnostic. An inline **Re-detect** button triggers `LocaleDetector` on demand — useful after a manual core re-install or when the auto-detection produced an unexpected result.

After a successful Re-detect the page reloads (same pattern as the Login Shield slug change in 1.3.4-dev) so the panel reflects the new state without manual refresh.

The panel surfaces all of: configured mode, detected value, override value, last-detected-at, tried-locales list, cross-check outcome. Per CB transparency principle — operators that need to verify the scanner's assumptions can do so without diving into options or logs.

### What is intentionally NOT in this release

- **Layer 3 (install-snapshot fingerprint):** scheduled for 1.3.13-dev. CB Base activate-hook will pick up an immutable fingerprint of the install state to triangulate against on top of the WP.org API + disk state.
- **Layer 4 (verification-inconclusive finding type + confidence score):** scheduled for 1.4.0-dev. Findings that cannot be hard-classified will get an explicit "review needed" status rather than being forced into tampering or drift.
- **Local checksum cache:** scheduled for 1.3.13-dev. WP's own `get_core_checksums()` already uses an internal transient, which is sufficient for now. A dedicated cache layer with explicit TTL + fallback handling is a separate scope.
- **Automatic re-detection on locale switch:** out of scope. Re-detection is operator-driven (manual button) — auto-running on every UI-locale change would produce continuous API calls and noise. Operators decide when their disk state actually changed.
- **Retroactive recategorisation of pre-1.3.12 findings:** clean-path only. The first scan after upgrade detects fresh and produces correctly categorised findings. No migration of stored result data.

### Internal changes

- New: `src/Integrity/Scanner/LocaleDetector.php` — stateless detector with `detect( string $wp_version ): array`. Returns detected locale, tried list, matched file, cross-check outcome, reason. Pure logic, no option writes — caller decides persistence.
- New constants: `LocaleDetector::DISCRIMINATOR_FILE` (`wp-includes/version.php`) and `LocaleDetector::CROSS_CHECK_FILES` (`load.php`, `wp-settings.php`).
- Rewritten: `src/Integrity/Scanner/CoreScanner.php` — uses `resolve_effective_locale()` instead of `get_locale()` directly. Lazy detection trigger on fallback-path discriminator mismatch. New `classify_mismatch()` distinguishes drift from tampering. New `aggregate_status()` ignores drift-only findings.
- Modified: `src/Integrity/Support/Finding.php` — adds `category` field with `tampering` / `distribution_drift` / `informational` values; default `tampering` for backwards compat. `normalise_category()` falls back to safe default on unknown values.
- Modified: `src/Settings.php` — four new keys under `integrity`: `distribution_locale_mode`, `distribution_locale_detected`, `distribution_locale_override`, `distribution_locale_meta` (with sub-keys for last-detected-at, tried locales, matched file, cross-check outcome).
- Modified: `src/Integrity/Rest/ScanController.php` — two new endpoints: `POST /integrity/admin/locale/redetect` (runs LocaleDetector + persists), `PUT /integrity/admin/locale/mode` (operator-controlled mode/override switch with validation).
- Modified: `src/Integrity/Bootstrap.php` — registers two new audit-event labels: `integrity_distribution_locale_detected`, `integrity_distribution_locale_changed`.
- Modified: `src/Integrity/Admin/Page.php` — new `render_distribution_locale_panel()` method renders the always-visible status panel + Re-detect button.
- Modified: `assets/js/features/core-scanner.js` — new `redetectLocale()` handler dispatched via the existing action switch, page-reload pattern after success.
- Modified: `assets/css/pages/safeguards-core-scanner.css` — Distribution Locale panel layout (definition-list grid), drift-finding info styling using existing `--cb-accent` token via `color-mix()`. No hardcoded colours.
- New action hooks: `cb_core_integrity_locale_detected` (locale string + detection array), `cb_core_integrity_locale_detection_inconclusive` (detection array). Available for downstream consumers (Hub fleet view, custom alert routing).
- POT/PO updated: 26 new strings appended, MO recompiled (1147 translated). Resolved a duplicate-msgid clash by renaming the panel's "Status" label to "Detection status" — the existing "Status" string is used elsewhere for permissions.

### Test coverage (manual verification path)

| Scenario | Expected outcome |
|---|---|
| Operator's Beacon site (NL UI, en_US distribution) | First scan triggers lazy detection, pins en_US, version.php finding becomes `distribution_drift` info. |
| Operator's Hub site (NL UI, nl_NL distribution) | No mismatch, no detection runs, panel shows "Not detected yet — runs automatically on first checksum mismatch". |
| Peter's German client (DE UI, de_DE distribution) | Either no mismatch (clean install), or detection picks de_DE on first mismatch. de_DE pinned, future scans clean. |
| Operator manual override to wrong locale | Critical mismatch returns (correctly — operator told scanner to verify against a non-matching distribution). |
| Real tampering on `load.php` | Cross-check fails during detection. Finding stays as `tampering`/critical. Detection not pinned. |
| Re-detect after manual `wp core download --force` | Detection runs fresh, correctly identifies whichever distribution was downloaded. |
| Clean en_US install, no locale switching | Zero overhead — no detection ever runs, panel shows fallback status, behaviour identical to pre-1.3.12. |



### Fixed — `cb_core_integrity_history` exceeded MySQL `max_allowed_packet` (critical)

Symptom on operator's site: every scan run produced an 18MB single-line debug log entry. Plesk's file viewer choked at the size. Worse: scan results often weren't visible in the UI without a manual page reload, even though the scan itself completed successfully.

### Root cause

`saveToHistory()` stored the *complete scan result* in each history entry under a `result` key — alongside the already-extracted `summary` and `components` fields, making the data redundant. With ~665 checks per scan and a `HISTORY_LIMIT` of 10, the serialised history option ballooned to ~4MB. On shared hosts where MySQL's `max_allowed_packet` is set to 1MB or 4MB, the `update_option` call failed silently (returning false) and `wpdb` logged the entire failed UPDATE query — including the 4MB serialised payload — into debug.log on every scan.

The UI-without-reload issue is a downstream effect: with `WP_DEBUG_DISPLAY` enabled (default when `WP_DEBUG=true`), the `wpdb` error gets printed into the response stream alongside the JSON body, corrupting the JSON. The browser's `await response.json()` then fails, the JS catch-block fires a "scan failed" toast, and the rendered UI never updates from the new payload — even though server-side both `cb_core_integrity_latest` and the HTTP response itself contained the correct result.

### Fix

History entries are now summary-only: `id`, `plugin_version`, `timestamp`, `source`, `status`, `summary`, `components`. Total per-entry size dropped from ~400KB to ~200 bytes — three orders of magnitude. Ten entries combined fits comfortably under any reasonable `max_allowed_packet`. The `result` key is gone.

History is for the timeline / Logs UI to surface past scan summaries. A full replay of every check from a historical scan was never an exposed feature anywhere; the operator-facing flow has always been "run a fresh scan" when current state matters.

`getHistory()` does defensive read-time compaction: any pre-1.3.11 entry that still carries a `result` key has it stripped on load. The next `saveToHistory` call persists the cleaned shape, so existing oversized options shrink themselves on the first scan after upgrade. No separate migration step required.

Side benefit: the JSON-corruption-on-error symptom is gone, which restores the in-place UI update after a scan. No reload needed any more.

### Changed — Core Shield page no longer surfaces the Integrity Scanner module card

Previously the Core Shield page registered `IntegrityModule` as a Core Shield module alongside Fingerprint and Headers. The card came with a feature toggle that operators could turn on or off — but the toggle had no functional effect. `IntegrityModule::boot()` is intentionally empty (the scanner is driven by Admin / REST / Cron / Hub endpoints, not by a Core Shield boot path), so toggling did nothing observable. Operators reasonably wondered whether the toggle was off the cause of UI bugs they were seeing on the Core Scanner page.

`IntegrityModule` is no longer registered in Core Shield's module list. The Core Scanner has its own Safeguards page with its own functional feature toggles (Core checksum verification, WordPress.org plugin verification, Uploads executable scan), where the toggles actually drive behaviour. The Core Shield card duplicated these without driving anything; cleaner to remove than to either leave it confusingly inert or to plumb actual master-switch behaviour through it (which would also duplicate the per-feature controls already on the Core Scanner page).

### Changed — UI rename: "Integrity scan" → "Core scan"

The Core Scanner page was the primary product surface, but a few legacy strings still referred to "Integrity Scanner" or "integrity scan" in user-facing text — inconsistent with the rest of the page. Renamed:

- Run-scan button busy label: "Running integrity scan…" → "Running Core Scanner…"
- Toast (success): "Integrity scan completed." → "Core scan completed."
- Toast (failure): "Integrity scan failed." → "Core scan failed."
- `IntegrityModule::label()`: "Integrity Scanner" → "Core Scanner" (still relevant for module API consumers; not surfaced in Core Shield UI any more, but other consumers may render it)

### Added — Spinning dashicon during scan run

The Run Core Scanner button now displays a spinning `dashicons-update` glyph next to the busy label while a scan is in flight. WP-admin uses the same idiom for "Reload" affordances, so the visual is familiar.

`setBusy()` was extended with an optional `icon` parameter; passing it renders the icon as a DOM child rather than overriding `textContent`, so the original button content restores cleanly when the busy state ends. Other callers that don't pass an icon get the same string-only behaviour as before.

### Fixed — Approve / Clear Approved Baseline now syncs the action bar without a page reload

After clicking "Approve Baseline", the new "Clear Approved Baseline" button (added in 1.3.8-dev) only appeared after a manual page refresh. Same in reverse: after Clear, the button stayed in the DOM until reload. The button visibility is server-rendered from `$baseline['exists']`, so the in-place client state diverged from the next-render server state.

A new `syncBaselineActionBar( baselineExists )` JS helper now runs after both successful approve and successful clear: it inserts/removes the Clear button, and switches the Approve button label between "Approve Baseline" and "Update Approved Baseline" — mirroring exactly what the server template would render on next load. Page reloads no longer needed for either flow.

### Internal changes

- `src/Integrity/Storage/ResultRepository.php`
  - `saveToHistory()` no longer stores the full scan result inside each history entry; only `id`, `plugin_version`, `timestamp`, `source`, `status`, `summary`, `components` are persisted
  - `getHistory()` strips any stale `result` key from pre-1.3.11 entries on read (defensive lazy migration)
  - Both methods carry expanded docblocks documenting the change and its rationale
- `src/Core.php` — `IntegrityModule` removed from `register_builtin_modules()`; import statement dropped
- `src/Integrity/Security/IntegrityModule.php` — `label()` returns "Core Scanner"
- `src/Admin/Admin.php` — `running` / `complete` / `failed` i18n strings updated to Core Scanner wording
- `assets/js/features/core-scanner.js`
  - `setBusy()` extended with optional `icon` parameter; preserves and restores `innerHTML` rather than `textContent` so structured content survives the busy-cycle
  - `runScan()` passes `'update'` as the icon for the spinning dashicon
  - New helper `syncBaselineActionBar( baselineExists )` adds/removes the Clear button and updates the Approve label
  - `approveBaseline()` and `clearBaseline()` both call the helper after success
  - Toast fallback strings updated to match new Core Scanner wording
- `assets/css/pages/safeguards-core-scanner.css` — `@keyframes cb-core-spin` + `.cb-core-spin` rule for the busy-state dashicon
- POT/PO updated: 3 new strings appended (the renamed labels), MO recompiled (1120 translated)



### Fixed — "Array to string conversion" warning + literal "Array" rendered as Integrity Scanner description

The Core Shield page rendered a PHP warning and showed the word "Array" in place of the Integrity Scanner module's description. Visual mess in production and a clear sign of a contract being violated somewhere.

### Root cause

The `Module::description()` interface explicitly allows two return shapes:

- `string` — technical description only
- `array` with `plain` and `technical` keys — preferred dual form

The interface comment even calls the array form "preferred". `IntegrityModule` correctly uses the array form. The other modules (`Fingerprint`, `Headers`) use the string form. The Core Shield template, however, did `(string) $module->description()` unconditionally — which on the array path produced a PHP `Array to string conversion` warning and rendered the literal word "Array" into the description slot.

### Fix

The template now normalises both return shapes into a single `$module_desc` value with the same final structure the rest of the template expects. `description_plain()` (separate accessor) keeps priority over an array-shape `plain` key — modules that opted into the separate accessor expressed an explicit override intent. Modules can now use either shape per the interface contract without surfacing render bugs.

### Internal changes

- Modified: `templates/core-shield.php` — rewrote the description-collection block at line ~258 to handle both string and array return shapes from `Module::description()`. Comment in-line documents the contract and the precedence rule between `description_plain()` and array-shape `plain`.

### Note: `wp-includes/version.php` checksum mismatch (still under investigation)

This release does not address the `wp-includes/version.php` checksum mismatch finding. Investigation so far:

- Confirmed: Core Shield's Fingerprint module does not modify `version.php` (output filters only — meta tag, asset query strings, feeds, headers; no filesystem writes).
- Confirmed: the file content provided shows a clean stock `version.php` for WordPress 6.9.4 with no obvious tampering.
- Hypothesis pending verification: WordPress occasionally rolls security patches under the same minor version string, in which case the local file may predate the current `api.wordpress.org` checksum manifest for that exact version. A `wp core verify-checksums` run from WP-CLI (or comparing the file's `md5sum` to the checksum API output) would confirm whether the mismatch is upstream-real or a Core Scanner bug.
- Wordfence Free with default settings does not modify `version.php` either, so it is unlikely to be the source.

Picking up the diagnosis after operator-side checksum verification produces a clear answer.



### Fixed — False-positive missing core findings for bundled-theme translations

On sites that don't have the bundled default themes installed (Bricks-only, Astra-only, etc.), the Core Scanner reported critical findings for every translation file under `wp-content/languages/themes/twenty*-{locale}.{po,mo}` and `wp-content/languages/plugins/{slug}-{locale}.{po,mo}`. Same pattern for plugin-translation files when bundled default plugins aren't present.

### Root cause

`get_core_checksums()` returns the full file list for a given WordPress version + locale, which includes translation files for the bundled themes (Twenty Twenty-Three, Twenty Twenty-Four, Twenty Twenty-Five, etc.) and for bundled plugins like Akismet. These files ride along with the core checksum payload but conceptually belong to the themes and plugins they translate, not to WordPress core itself. On a site where those themes aren't installed, the translation files don't exist either — which is correct, not an integrity issue.

`CoreScanner::is_wp_content_component_path()` already filtered `wp-content/themes/` and `wp-content/plugins/` for the same reason, but did not extend that exclusion to the corresponding language subfolders.

### Fix

Extended the skip-path check to also exclude:

- `wp-content/languages/themes/` (theme translation files for the bundled defaults)
- `wp-content/languages/plugins/` (plugin translation files for bundled defaults)

Files directly in `wp-content/languages/` (like `nl_NL.po` for WordPress itself) are still scanned — those are real core translations and an absence there would be a real integrity concern. The leading-component check handles that naturally because core translations don't sit under `themes/` or `plugins/` subfolders.

### Internal changes

- Modified: `src/Integrity/Scanner/CoreScanner.php::is_wp_content_component_path()` — two additional `str_starts_with` clauses; expanded docblock explains what each clause covers and what is deliberately not skipped



### Added — Clear Approved Baseline

A new **Clear Approved Baseline** button appears in the Core Scanner action bar, next to "Update Approved Baseline" and "Clear Results", whenever a baseline currently exists. The button uses the existing danger styling (red) to match suite-wide convention for destructive actions.

Clicking opens a danger-variant confirmation modal with a red Confirm button. The modal copy explains both what happens (every approved entry dropped) and the next step the operator needs to take (run a new scan manually). On confirmation, the baseline option is deleted, an audit-log entry is written (`integrity_baseline_cleared` with the pre-clear entry count), and a success toast appears.

### Why no automatic re-scan

`approve_baseline` and `approve_component_baseline` both run a fresh scan automatically after persisting their changes — that's appropriate when the operator's intent is "approve this state". For a clear, the operator's intent is the opposite: explicitly start fresh. Auto-running a scan immediately would reflag every previously approved component as `baseline_required` in the same UI cycle, mixing the reset with implicit follow-up work the operator hasn't yet decided to do.

The modal copy ("run a new scan afterwards to start fresh") makes the manual second step unambiguous.

### Visibility

The Clear button only renders when `$baseline['exists']` is true. With no baseline on file there's nothing to clear; hiding the button keeps the action bar from offering meaningless options. This mirrors the conditional rendering of the per-component Remove button added in 1.3.5/1.3.6/1.3.7-dev.

### Internal changes

- New: `ScanController::clear_baseline()` — REST handler that returns 404 when no baseline exists, deletes the baseline option otherwise, writes the audit entry, and returns the current findings payload unchanged
- New: REST route `DELETE /core-blueprint/v1/integrity/admin/baseline` (alongside the existing `POST` for approve)
- New: `clearBaseline( button )` JS handler in `assets/js/features/core-scanner.js`, dispatched via the existing `handleAction` switch, opens a danger-variant confirmation modal
- Reused: `ResultRepository::clearBaseline()` already existed as a public method — no storage-layer changes needed
- New: audit-event label `integrity_baseline_cleared` in `Integrity\Bootstrap::register_event_labels`
- New: 6 i18n strings on the localised payload (`clearingBaseline`, `baselineCleared`, `baselineClearFailed`, `baselineClearTitle`, `confirmBaselineClear`, `baselineClear`) plus the per-button label and the audit-event label
- POT/PO updated: 7 new strings appended, MO recompiled (1117 translated)



### Fixed — Confirm button colour on Remove-from-baseline modal

The confirm button in the Remove-from-baseline modal rendered in the default (cyan) styling rather than the danger (red) styling used everywhere else in the suite for delete actions. Visual mismatch — same intent (destructive, irreversible-ish), different colour.

### Root cause

`confirmModal()` in `features/core-scanner.js` accepts a higher-level `variant` parameter and was passing it through to the underlying `cbCore.modal.show()` API verbatim. The shared modal API accepts a `danger` boolean, not a `variant` string — so the value was silently ignored. Result: every confirm button rendered in the default style regardless of what callers asked for.

### Fix

Two minimal changes:

- `confirmModal()` now translates `variant === 'danger'` to the modal's `danger: true` flag, so the higher-level `variant` API actually does something. Future destructive callers can pass `variant: 'danger'` and get the right styling.
- `removeComponentBaseline()` now passes `variant: 'danger'` in its modal options.

### Internal changes

- Modified: `assets/js/features/core-scanner.js::confirmModal()` — variant→danger translation
- Modified: `assets/js/features/core-scanner.js::removeComponentBaseline()` — `variant: 'danger'` added to modal options



### Fixed — Remove-from-baseline button never appeared

1.3.5-dev gated the Remove button on `$status === 'missing'` at the per-component summary row. The status read at that point comes from the group-level rollup, not the per-finding status — and `ResultFormatter::status_for_finding()` did not pass the `missing` value through. For findings with `status='missing'` and `severity='critical'`, the rollup landed on `'critical'` (severity-based fallback), so the visibility condition never matched. The button was registered, the JS handler was wired, the REST endpoint was live — but the button was never rendered.

### Root cause

`status_for_finding()` had explicit pass-through for `changed` and `new` finding statuses, but no entry for `missing`. The "missing" semantics fell through to the severity-based fallback, which clobbered the status into the severity name. Status and severity are orthogonal axes (the screenshot from production shows both — the right-side `CRITICAL` badge is severity, the left-side pill is status), and conflating them at the rollup step lost information that the UI needs to make per-status decisions.

### Fix

Added `missing` to the explicit-pass-through arm in `status_for_finding()` alongside `changed` and `new`. The group-level status now preserves the `missing` signal, the Remove button's visibility condition matches as designed, and as a bonus the per-component pill renders "missing" instead of the less-informative "critical" — matching the existing `changed` / `new` pill behaviour.

### Internal changes

- Modified: `src/Integrity/Support/ResultFormatter.php::status_for_finding()` — single line, added `'missing' === $type` to the pass-through arm



### Added — Remove from baseline

When a scan reports `missing` for a component that was previously in the approved baseline, the per-component summary row now offers a second action button next to "Approve component baseline" — **Remove from baseline**. Confirming the action removes the entry from the approved baseline option, runs a fresh scan to refresh the findings list, and writes an audit-log entry of type `integrity_baseline_entry_removed` so the change is auditable after the fact.

The button appears only when the action is meaningful: the finding's status must be `missing`, the baseline must exist, and the component must have a resolvable type+slug. For all other statuses (`changed`, `new`, `verification_failed`, etc.) the existing Approve flow remains the right tool — Remove is specifically for cleaning up entries whose underlying component no longer exists.

### Why this matters

Before this release, an absorbed or retired component left a permanent "missing — critical" line in every scan. The only way to clear it was to approve the entire baseline anew, which would also overwrite every other approved entry — a sledgehammer for what should be a per-line edit. The Core Scanner is meant to keep a faithful picture of the operator's intent; the operator needs an in-product affordance to express "this component is gone, on purpose, stop reporting it". This release adds that affordance.

The severity of `missing` is unchanged — it stays at `critical`, which is correct for a security-oriented scanner: an unexpectedly absent component is a strong signal of tampering. The fix is not to soften the severity, but to give the operator a way to bring the baseline back in sync with reality when the absence is intentional.

### Implementation

REST: a second handler is registered on `/integrity/admin/baseline/component` — same path as the existing approve endpoint, but bound to `DELETE` rather than `POST`. Same shape (type + slug in the request body), same response payload (a re-run scan result so the UI can update without an extra round-trip).

Storage: a new `ResultRepository::removeBaselineComponent( type, slug )` method walks the baseline's entries, drops every entry whose type+slug match, and persists the trimmed baseline. Returns the updated baseline, the unchanged baseline if no entries matched, or null when no baseline option exists.

UI: the per-component summary row in the Core Scanner page renders a second button when status is `missing`, alongside the existing Approve button. JS handler mirrors the approve-component flow — confirm modal, busy state, REST call, summary update, success/error toast.

Audit: the new event type `integrity_baseline_entry_removed` is registered in `Integrity\Bootstrap::register_event_labels` so the Logs UI shows it as "Core Scanner: baseline entry removed" rather than the raw key.

### Internal changes

- New: `ResultRepository::removeBaselineComponent( string $type, string $slug ): ?array`
- New: `ScanController::remove_component_baseline( WP_REST_Request $request ): WP_REST_Response|WP_Error`
- New: REST route `DELETE /core-blueprint/v1/integrity/admin/baseline/component`
- New: `removeComponentBaseline( button )` JS handler in `assets/js/features/core-scanner.js`, dispatched via the existing `handleAction` switch
- New: `cb-core-integrity-summary-action-danger` CSS class — subtle red border + text styling, themed via `--cb-error` token with a hardcoded fallback for older themes
- New: 6 i18n strings on the Admin\Admin localised payload (`removingBaseline`, `baselineRemoved`, `baselineRemoveFailed`, `baselineRemoveTitle`, `confirmBaselineRemove`, `baselineRemove`) plus the per-button label and the audit-event label
- POT/PO updated: 9 new strings appended, MO recompiled (1110 translated)

### Note for future work

Two existing audit events surfaced during the review of `Integrity\Bootstrap::register_event_labels` — `integrity_baseline_approved` and `integrity_component_baseline_approved` — do not currently have human-readable label entries in that filter, so they probably display in the Logs UI as raw keys. Out of scope for this release (purely a UI polish issue, not a functional defect), worth picking up alongside another Core Scanner change.



### Fixed — Logout link broken after changing the Login Shield slug

The Safeguards → Login Shield save handler persists the new configuration via AJAX without reloading the page. After a slug change (or a toggle of the enabled flag) every URL on the page that was rendered server-side against the *old* configuration kept pointing at the old slug — most visibly the admin-bar logout link, which would POST to the no-longer-valid old slug and land on a 404. The same applied to any rendered lostpassword link or other surfaces that bake the slug into URLs at render time.

DOM-patching individual stale references would only have covered the URLs we knew about; future renderers (a widget, a Beacon tile, third-party admin-bar entries) would silently keep a stale link until someone discovered the bug. The reliable fix is a full page reload after a successful save — Login Shield save is infrequent enough that the brief flash is acceptable, and a reload is the only mechanism that guarantees no stale URL survives anywhere on the page.

### Implementation

- `assets/js/features/login-shield.js` — after a successful save, set status to "Saved — reloading…", schedule `window.location.reload()` with an 800 ms delay so the operator briefly sees the success state before the page refreshes
- The save and test buttons stay disabled during the reload window via a local `reloadPending` flag, so a second click cannot trigger a second save while the page is on its way out
- The previous post-save slug-rehydration (`slug.value = response.data.config.slug`) is now redundant and removed — the reload regenerates the field from the server's authoritative state along with everything else

### Internal changes

- Modified: `assets/js/features/login-shield.js` — save handler restructured around the reload-pending flag; same shape, simpler control flow
- Modified: `src/Admin/Admin.php` — added `lsSavedReloading` to the Login Shield module's `i18n` payload
- POT/PO updated: 1 new string ("Saved — reloading…"), MO recompiled (1101 translated)



### Fixed — Post-logout 404 in Strict mode

When Login Shield was active in Strict mode and a user logged out, the browser landed on `/wp-login.php?loggedout=true` instead of the configured custom slug. Strict mode blocks `/wp-login.php` for guests, so the logged-out user got a 404. The 1.3.2-dev release attempted to fix this by adding page-relative URL detection but did not actually address the underlying cause — see the Root cause section below.

### Fixed — Slug leak via failed-login form re-render

Separately surfaced during the post-mortem of the logout bug: when a user submitted incorrect credentials at `/cb-login/`, the login form re-rendered (no redirect — wp-login.php just re-displays the form with errors) but the form's action attribute pointed at `https://site.com/wp-login.php`. The browser stayed on the URL the form posted to, so the URL bar shifted from `/cb-login/` to `/wp-login.php`. The custom slug stayed intact in configuration but became briefly visible to the user (and any over-the-shoulder observer) during the failed-login round-trip — defeating the central premise of Login Shield.

### Fixed — Slug leak via lost-password roundtrip

Same mechanism. The lost-password form's action and confirmation-email redirect both went through the same suppressed-rewrite path, surfacing `/wp-login.php` in the URL bar partway through the flow.

### Root cause

`maybe_rewrite_login_url()` (the central URL-rewriter that swaps `wp-login.php` for the configured slug) carried a request-scoped guard `$serving_alias` that suppressed all rewrites while wp-login.php was being rendered as the alias content. The guard's stated intent was to keep URLs produced *inside* the wp-login.php render from being rewritten back to the slug — apparently for "internal consistency". On review, the rationale didn't hold: every URL produced inside the wp-login.php render that contains `wp-login.php` is exactly a URL that the user will eventually see (form actions, redirect destinations, link hrefs in the rendered HTML), and surfacing `/wp-login.php` in any of them defeats the feature.

The guard was applied uniformly across the six URL-construction filters Login Shield hooks: `site_url`, `network_site_url`, `wp_redirect`, `login_url`, `logout_url`, `lostpassword_url`. For every one of those, suppression while alias-serving was the wrong behaviour:

| Filter | What was suppressed | Visible failure |
|---|---|---|
| `wp_redirect` | Outgoing Location header | Logout 404 (the original bug report) |
| `site_url` | Form action in rendered login form | Slug leak after failed login |
| `site_url` | Form action in lost-password form | Slug leak during password reset |
| `login_url`, `logout_url`, `lostpassword_url` | Other anchor/link constructions | Various smaller leaks during alias-render |

### The fix

Removed the `$serving_alias` property entirely (was: regel 91), removed its setter call inside `serve_alias()` (was: regel 331), and removed the early-return at the top of `maybe_rewrite_login_url()` (was: regel 437). The rewrite is now unconditional when Login Shield is enforcing and the URL contains `wp-login.php`.

No infinite-loop risk: `maybe_rewrite_login_url()` is a pure string transformation that does not call itself, and the filters that invoke it are not part of any rendering pipeline that could feed back. Verified by tracing every code path that ends in a wp-login.php URL construction during alias serving.

The page-relative URL form detection added in 1.3.2-dev is kept — it is correct on its own merits as defensive coverage for any WP-core-emit path that ships a relative URL without going through `wp_validate_redirect` first (registration-disabled emissions, certain lostpassword variants).

### Verification matrix

The fix was verified against all eight URL forms `maybe_rewrite_login_url()` can encounter (absolute, site-relative, page-relative, with and without query strings, plus a no-match passthrough). All eight produce the expected output. The matrix is in the test fixtures.

User-facing verification scenarios that should be retested in production:

| # | Scenario | Expected result |
|---|---|---|
| 1 | Login at `/cb-login/` with correct credentials | Lands on wp-admin |
| 2 | Login at `/cb-login/` with wrong credentials | Stays on `/cb-login/`, error visible, URL bar = `/cb-login/` (no leak) |
| 3 | Logout via wp-admin Howdy menu | Lands on `/cb-login/?loggedout=true` |
| 4 | Lost-password flow | Form submit → `/cb-login/?checkemail=confirm` |
| 5 | Direct GET `/wp-login.php` as guest in Strict | 404 (unchanged) |
| 6 | Direct GET `/wp-login.php` as logged-in admin | wp-login.php content normal (unchanged — guard_init bypasses for logged-in users) |

### Internal changes

- Modified: `src/Security/LoginShield.php` — removed `$serving_alias` property declaration, removed its setter call in `serve_alias()`, removed the corresponding early-return in `maybe_rewrite_login_url()`, expanded the docblock to document why the rewrite is now unconditional and what the previous guard suppressed
- No filter additions, no new hooks, no behavioural changes outside of the three fixed scenarios above

### Why this supersedes 1.3.2-dev

1.3.2-dev added page-relative URL form handling under the assumption that the logout default `'wp-login.php?loggedout=true'` was reaching `filter_wp_redirect` in its raw relative form. In practice, `wp_safe_redirect`'s upstream call to `wp_validate_redirect` absolutises that URL before the redirect filter sees it — so the page-relative branch added in 1.3.2-dev was never exercised by the logout path. The bug remained because the *real* gate was the `$serving_alias` guard further up. 1.3.3-dev removes that guard. The page-relative branch is kept anyway — it remains correct as defensive coverage for paths that could emit a raw relative URL outside `wp_safe_redirect`.



### Fixed — Logout landed on a 404 in Strict mode

When Login Shield was active in Strict mode and a user clicked Logout, the browser was redirected to `/wp-login.php?loggedout=true` rather than the configured custom slug. Strict mode blocks `/wp-login.php` for guests (the entire point of the mode), so the logged-out user landed on a 404 — confusing UX and an obvious sign that Login Shield was in use even when its slug stays secret.

**Root cause.** WordPress core's logout handler in `wp-login.php` hardcodes the default `logout_redirect` value as the relative string `'wp-login.php?loggedout=true&wp_lang=...'` — without a leading slash. Login Shield's `filter_wp_redirect` callback caught the redirect and tried to rewrite it via `str_replace('/wp-login.php', '/cb-login/', ...)`, but the replacement looked for `/wp-login.php` (with leading slash) and the URL didn't have one. The `strpos` allowlist check was looser than the actual replacement: it accepted any URL containing `wp-login.php`, but the replace step only worked for the absolute and site-relative forms. Page-relative URLs slipped through unchanged.

The bug existed in every Login Shield release since the filter was introduced. It only became visible with Strict mode + actual logout — the most-used path (login URL filter) goes through `wp_login_url()` which builds an absolute URL, so the existing replacement covered it. Logout is the one place WP core emits the relative form directly.

**Fix.** `maybe_rewrite_login_url()` now handles all three URL forms WordPress can emit:

```
1. Absolute       https://site.com/wp-login.php?...
2. Site-relative  /wp-login.php?...
3. Page-relative  wp-login.php?...
```

The page-relative form is detected by checking whether the URL starts with `wp-login.php`, and rewritten to start with `{slug}/` so the browser's relative-resolution lands on the same root path. Test cases covering all three forms (plus a no-match passthrough) are in the commit.

This is also a defensive improvement for any future WP core path that emits a relative URL — for example, lost-password and registration-disabled emissions sometimes use the same shape. They share the same fix without further change.

### Internal changes

- Modified: `src/Security/LoginShield.php::maybe_rewrite_login_url()` — adds the page-relative-form detection branch, expanded docblock explains all three URL shapes
- Acceptance verified by isolated rewrite tests covering the eight URL shapes the function can encounter



### Fixed — Hub Pairing menu position

Hub Pairing previously slipped between Reports and Safeguards (or wherever WordPress decided to slot it) because it bypassed `PageRegistry` and registered itself directly via `add_submenu_page` on `admin_menu` priority 999. PageRegistry never saw it, so the careful `position()` ordering applied to every other Core Blueprint base page didn't apply to Hub Pairing.

Hub Pairing now registers through PageRegistry like every other base page. New page class at `src/Admin/Pages/HubPairing.php` implements the `Page` interface with position 95 — last item in the Base cluster, just after Preferences (90). The page's render still delegates to `Beacon\Admin\SettingsPage::render()`; only the menu plumbing changed.

### Removed — Dead `SettingsPage::register_menu`

The previous `admin_menu` hook (`Beacon\Admin\SettingsPage::register_menu`) is now unreachable and removed. It included a fallback branch that registered Hub Pairing under WordPress' Settings menu when `CB_CORE_PARENT_MENU` wasn't defined — a defensive path from an era when Beacon could conceivably ship without Core Blueprint base. That era ended; Beacon is now part of Core Blueprint base, and the constant is unconditionally defined in `core-blueprint.php`. Following the no-legacy stance, the dead branch and its enclosing method are removed entirely.

### Added — Position convention in the Page interface

`src/Admin/Page.php` now documents the position convention used across the Core Blueprint suite:

```
10–99   reserved for Core Blueprint base pages
100+    reserved for extension plugins (CB Hub, CB Invoice, …)
```

This is a contract between base and extensions, kept by convention rather than enforced in code. It keeps the menu predictable: base pages always cluster at the top in their documented order; extension pages always come after, ordered by their own position values. Current base positions are listed in the docblock so a future contributor knows where new base pages fit and which numbers extension pages should avoid.

### Coordination note

The base cluster will now appear in the correct order on its own. CB Hub still uses positions 30 and 31 for its Dashboard and Settings, which collide with Safeguards (30) and tiebreak alphabetically — that fix lands in the next CB Hub release. After both releases are deployed, the menu will read: Dashboard, Logs, Notes, Reports, Safeguards, Preferences, Hub Pairing, Hub, Hub Settings.

### Internal changes

- New: `src/Admin/Pages/HubPairing.php` (Page wrapper, 70 lines)
- Modified: `src/Beacon/Bootstrap.php` — replaces `add_action('admin_menu', ...)` with `add_action('cb_core_register_pages', ...)`, adds the wrapper-registration callback, imports `PageRegistry` and `HubPairing`
- Modified: `src/Beacon/Admin/SettingsPage.php` — `register_menu` method removed
- Modified: `src/Admin/Page.php` — position docblock expanded with convention and current base positions



### Added — Thirteen new redirect targets

`TargetResolver` now resolves sixteen targets in a single registry. Three special targets (admin, login, frontend) plus six WordPress core admin pages plus seven Core Blueprint admin pages:

| Range | Family | Members |
|---|---|---|
| `0x01-0x03` | Special | admin, login, frontend |
| `0x04-0x09` | WP core | plugins, themes, users, options, updates, tools |
| `0x0A-0x10` | CB Base | cb-dashboard, cb-safeguards, cb-logs, cb-notes, cb-reports, cb-preferences, cb-pairing |

The REST mint endpoint accepts the kebab-case label for any of these as the `target` value. The token format is unchanged — the existing 1-byte `target_enum` field has 256 possible values and we now use sixteen of them. No new fields, no variable-length tokens, no version bump on the binary layout. Adding a future target is a three-line change: a constant, a registry entry, a switch arm — all in `TargetResolver.php`.

URL resolution per target: special targets use the matching WP helper (`admin_url('/')`, `wp_login_url()`, `home_url('/')`); WP core pages use `admin_url('plugins.php')` and similar; CB Base pages use `admin_url('admin.php?page=core-blueprint-...')`. The `wp_login_url()` call still routes through Login Shield's `login_url` filter, so the custom slug is honoured automatically — no Hub-side knowledge required for that target either.

Single source of truth: `TargetResolver::all_labels()` powers the REST endpoint's `enum` arg validation, so adding a target only touches one file. The REST controller no longer hardcodes `[ 'admin', 'login', 'frontend' ]`.

### Removed — Capabilities advertisement scaffolding

Earlier internal versions injected a `capabilities` block into Beacon's `/status` poll response, gated by a `cb_core_beacon_status_response` filter. The intent was to let Hubs feature-detect per satellite which redirect features they could rely on. Removed in 1.3.0-dev:

- `cb_core_beacon_status_response` filter (was applied around StatusResponse output)
- `Beacon\Redirect\Bootstrap::add_capabilities()` filter callback
- The `capabilities` block from the poll response

Rationale: this is a deployed-as-a-set product. Every CB Hub talks to satellites running matching Core Blueprint releases that the same operator administers. Capability negotiation between client and server is a pattern from open ecosystems where you cannot upgrade everyone in lockstep. Keeping it here was anticipatory complexity for a problem that does not exist in this product line. If a true third-party Hub scenario ever appears, capabilities can be reintroduced at that moment as a focused addition rather than as scaffolding maintained without users.

### Removed — Feature-toggle scaffolding for the redirect subsystem

`is_feature_enabled()` in both `RedirectController` and `RedirectHandler` read a `cb_core_settings[beacon][redirect_enabled]` setting that was never exposed in any UI and never actually disabled the feature meaningfully — the Beacon pairing state is the real on/off switch. Pairing the satellite enables the feature; clearing the secret key disables it. A second toggle was configuration creep.

Removed:

- `is_feature_enabled()` static methods in both classes
- The 503 `feature_disabled` response path from `RedirectController`
- The `feature_disabled` reject reason from `RedirectHandler`
- `use CB\Core\Settings;` statements that were only there for these toggles

Net code change: simpler. The validation chain in both files lost its first step and the others renumbered up.

### Removed — `redirect_ip_binding` setting reference

Earlier versions read a `redirect_ip_binding` setting and wired it through the consume handler as a no-op placeholder pending a v1.1 token-format extension. Following the same logic as the feature toggle: settings that are read but do nothing should not exist. The reference is removed entirely. If real IP-binding lands in a future release with a proper token-format extension, the setting can be added at that moment.

### Internal changes

- `TargetResolver` rewritten with explicit constants for all sixteen targets plus a private `TARGETS` registry that drives every lookup direction
- `RedirectController::register_route()` now reads the REST `enum` arg from `TargetResolver::all_labels()` — no duplicated allowlist
- `RedirectHandler::label_for_enum()` is now a one-line delegate to `TargetResolver::label_from_enum()` — DRY against the registry
- `Beacon\Rest\StatusResponse::handle()` returns its array directly without filter wrapping
- Constants table in `core-blueprint.php` unchanged — none of the redirect constants needed touching for this release

### Migration notes

None. There is no data migration, no settings migration, no DB schema change. Outstanding redirect tokens minted on 1.2.0-dev installations remain valid against 1.3.0-dev installations because the binary token format is identical. (As before, satellite-side and Hub-side are deployed together; this note exists for clarity, not because asymmetric upgrade is supported.)

 Hub-initiated redirects to admin / login / frontend on satellite sites without the Hub ever learning Login Shield's custom slug. Short-lived (60 sec) Ed25519-signed tokens, single-use, atomic rate-limited, audit-logged.

### Added — Beacon Signed Redirect subsystem

A dedicated `src/Beacon/Redirect/` namespace with eleven classes covering token mint/verify, replay protection, rate limiting, target resolution, key management, and the redirect handler. Plus a REST controller at `src/Beacon/Rest/RedirectController.php`. Wired into Beacon's paired-hooks bootstrap so it activates only when the satellite is paired with a Hub.

**Why it matters.** The naive solution to "open admin from Hub" — letting Hub know the actual admin or login URL — would force satellite sites to ship their custom Login Shield slugs in every poll response. That centralises slug knowledge in the Hub DB, which is exactly what Login Shield's obscurity-protection is designed to prevent. A compromised Hub-DB or a leaked Hub backup would then expose login URLs across an entire client portfolio. The signed-redirect pattern keeps slugs on the satellite where they belong.

**Token format.** 106 bytes raw, base64url-encoded to ~142 chars in the URL. Layout: `[1 byte version][16 bytes nonce][8 bytes expires_unix BE][1 byte target_enum][16 bytes hub_id_hash (BLAKE2b-128)][64 bytes Ed25519 signature]`. Version byte enables forward-compatible token-format evolution.

**Cryptography.** Ed25519 via libsodium (already a hard plugin requirement; no Composer dependency added). Dedicated keypair stored in autoload-disabled option, separate from the Beacon HMAC poll secret — compromise of one key does not compromise the other. Signing key never leaves the satellite. Lazy keypair generation in `KeyManager::ensure_keypair()` covers fresh installs and missed activation hooks. Manual rotation via the new `wp core-blueprint beacon-rotate-key` WP-CLI subcommand (auto-rotation explicitly rejected — adds operational complexity for no security benefit given the 60-second TTL).

**Validation chain.** Cheap-checks-first ordering for asymmetric-DoS resistance: length → version → TTL → target allowlist → Ed25519 signature verify (the only expensive step) → Hub identity match → atomic single-use claim. An attacker spamming forged tokens forces only cheap deterministic work until they manage to craft something that passes the cheaper checks — which they cannot without seeing valid samples.

**Storage.** Two dedicated tables, not transients:

- `cb_core_beacon_redirect_nonces` — single-use claims via `INSERT IGNORE`. Atomic across object-cache configurations; race-condition-free. Cleanup-cron prunes rows older than 5 minutes (60-second TTL plus margin for clock skew).
- `cb_core_beacon_redirect_rate_limits` — per-Hub atomic counters. Three buckets: 30 mints/min, 200 mints/hour, 60 consumes/min. Increment uses MySQL's `LAST_INSERT_ID(expr)` trick on both INSERT and ON DUPLICATE KEY UPDATE branches — `$wpdb->insert_id` returns the post-increment counter value in a single round-trip. The naive two-query "increment, then SELECT counter" pattern has a race-window at boundary: requests 30 and 31 can both read counter=29 and both think they're within the limit. The atomic pattern eliminates that. Cleanup-cron prunes rows older than 1 hour.

Both tables register with the central `DB::register_table()` registry so `dbDelta` migrations run on `plugins_loaded` priority 5 alongside every other CB table. Cleanup-cron callbacks register via the unified `Retention` mechanism.

**Audit events.** Five events with human-readable labels via the existing `cb_core_event_labels` filter:

- `beacon.redirect.minted` (info) — token issued
- `beacon.redirect.consumed` (info) — token used, redirect performed
- `beacon.redirect.rejected` (warning) — validation failed
- `beacon.redirect.rate_limited` (warning) — mint or consume rate hit
- `beacon.redirect.key_rotated` (notice) — operator rotated keypair

Each event's details array carries a top-level `result` field (`success` / `expired` / `invalid_signature` / `replay` / `unknown_hub` / `feature_disabled` / `rate_limited`). Filtering the Logs tab on `result = 'rejected'` is now efficient — no string-pattern-matching on event messages required. Crucially: full tokens are NEVER logged. Only the nonce (16 bytes of the 106-byte token) is included for forensic correlation; the signature, expires_unix, and hub_id_hash never appear in logs.

**Open-redirect prevention — three layers.**

1. The token contains an enum byte (admin / login / frontend), not a URL string. The Hub cannot smuggle an arbitrary path.
2. `TargetResolver` is allowlist-driven. Unknown enum values are rejected, never substituted with a default.
3. The handler uses `wp_safe_redirect()` (not `wp_redirect`), which enforces same-origin. Even if the signing key were somehow compromised and a malicious token crafted with `target=0x01`, the attacker still cannot redirect to an external domain.

**Plug-point hook.** `do_action( 'cb_core_beacon_redirect_validated', $context )` fires after a successful redirect. Reserved for future monitoring, anomaly-detection, or AI-assisted abuse-detection layers — those can be added later without refactoring this subsystem. Fires only on real successes; rejected and rate-limited tokens do NOT fire the hook.

**Capabilities advertisement.** Beacon's `/status` poll-response now carries a `capabilities` block, populated via the new `cb_core_beacon_status_response` filter. v1 advertises one capability: `beacon_signed_redirect: true`. Future versions add capability flags without breaking the contract — the Hub can feature-detect per satellite and decide which buttons to render.

**Failure response policy.** On any validation failure the handler emits `status_header(403); exit;` with no body — no `wp_die()`, no pretty error page, no WP-stack in the response. Specific reasons live in the audit log (server-side observable), never in the HTTP response. This denies attackers the differential information they would need to probe which check fails when.

**Client-IP detection.** Defaults to `REMOTE_ADDR` only — no automatic trust of `X-Forwarded-For` or `CF-Connecting-IP` (header-spoofing is a known attack vector for bypassing IP-based rate limits). Sites genuinely behind a trusted proxy override via the `cb_core_beacon_redirect_client_ip` filter; the result is then re-validated via `filter_var()` so a misbehaving filter callback cannot inject garbage downstream.

### Added — REST endpoint

```
POST /wp-json/core-blueprint/v1/beacon/redirect/mint
```

Authenticated via the existing `Beacon\Rest\Auth` middleware. Body: `{ "target": "admin" | "login" | "frontend" }`. Success: `{ url, expires_at }`. Errors: 400 (invalid target), 401/403 (auth), 429 (rate limited, with `Retry-After`), 503 (feature disabled).

### Added — WP-CLI subcommand

```
wp core-blueprint beacon-rotate-key [--yes]
```

Generates a fresh Ed25519 signing keypair and replaces the stored one. All outstanding tokens become invalid immediately (the new public key fails to verify any signature made with the old secret). User-visible impact is at most one retry per Hub-operator currently mid-redirect.

### Added — Constants

Seven constants in `core-blueprint.php`:

- `CB_CORE_BEACON_REDIRECT_QUERY_PARAM` — `'cb_beacon_redirect'`
- `CB_CORE_BEACON_REDIRECT_TTL` — 60 (seconds)
- `CB_CORE_BEACON_REDIRECT_RATE_MINT_MIN` — 30
- `CB_CORE_BEACON_REDIRECT_RATE_MINT_HOUR` — 200
- `CB_CORE_BEACON_REDIRECT_RATE_CONSUME_MIN` — 60
- `CB_CORE_BEACON_REDIRECT_NONCE_RETENTION` — 300 (seconds)
- `CB_CORE_BEACON_REDIRECT_RL_RETENTION` — 3600 (seconds)

### Spec-vs-implementation reconciliations

Two corrections caught during implementation, recorded here for traceability:

1. **Ed25519 signature length.** Spec §3.2 stated 32-byte signatures. Ed25519 detached signatures per RFC 8032 are always 64 bytes (R ‖ S). Implementation uses the canonical 64-byte form; total token length is therefore 106 bytes raw, not 74. Roundtrip + tamper test verified the corrected layout cryptographically.
2. **`LAST_INSERT_ID(1)` on the INSERT side.** Spec §4.3 SQL example missed the explicit `LAST_INSERT_ID(1)` in the INSERT branch; without it, `$wpdb->insert_id` is 0 on the first request in a window rather than 1. Implementation uses `VALUES (..., LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE counter = LAST_INSERT_ID(counter + 1)` — both branches deliberate.

### Deferred to 1.2.1-dev

**Settings UI toggles.** Spec §10.1 calls for two checkbox toggles in Safeguards → Beacon: `redirect_enabled` (default ON) and `redirect_ip_binding` (default OFF). The feature works correctly without UI — `RedirectController` and `RedirectHandler` read the settings via `Settings::get()` with safe `!isset(...) || (bool)...` fallbacks, so the defaults are sensible. During this dev cycle the toggles can be flipped via direct option update (`wp option patch update cb_core_settings beacon redirect_enabled false`) if testing requires. The visible UI ships in 1.2.1-dev so 1.2.0-dev stays focused on the cryptographic core.

**IP-binding enforcement.** Setting is read but enforcement is currently a no-op pending v1.1 token-format expansion (the 106-byte token has no field for an IP-bound hash; adding one prematurely would force an awkward variable-length token). Documented in `RedirectHandler` line-comments. Default OFF means no operator-visible behaviour change.

**Page-target redirects.** Spec §15 — admin-page-specific redirects (e.g. `plugins.php`, `themes.php`) wait for v1.1 with a properly-designed token-format extension.

### Internal changes

- `src/Beacon/Bootstrap.php` calls `Beacon\Redirect\Bootstrap::boot()` from `boot_paired_hooks()`
- `src/Beacon/Rest/StatusResponse.php` wraps its response in `apply_filters( 'cb_core_beacon_status_response', ... )` so subsystems can inject capability flags
- `src/CLI/Command.php` gains the `beacon-rotate-key` subcommand
- POT updated; `nl_NL.po` gains 5 new translations; `.mo` regenerated (1100 translated)



### Fixed — Inactive Extensions tile linked to a non-existent admin page

When a sibling CB plugin is installed but deactivated, its top-level admin page is not registered by WordPress, so any URL pointing at it returns a 404. The Extensions section of the Dashboard rendered every tile with `$ext['menu_url']` regardless of `active` state, which meant clicking an inactive plugin's tile led to a dead admin URL.

`templates/dashboard.php` now routes inactive extensions to `admin.php?page=plugins.php` instead. Active extensions still link to their own admin page as before. The fix matches the operator's intent: clicking an inactive tile means "I want to do something with this plugin", and the WP Plugins page is the only place where re-activation is possible.

The tile remains visually marked inactive via the existing `data-state="inactive"` attribute (faint dot, dimmed opacity). No new CSS, no new strings.

## [1.1.7-dev] — unreleased

Two-part release: dead-code cleanup pass for the removed Native theme, plus a structural Dashboard refactor.

### Removed — Dead Native theme references

The Native admin theme was removed from the registry in an earlier release; only `core_blueprint_dark` and `core_blueprint_light` (plus the `auto` mode) ship today. A grep sweep surfaced one user-visible bug and a number of stale references in CSS comments and one template docblock. None of the dead references produced wrong CSS — there are no `[data-cb-theme="native"]` selectors anywhere in the codebase — but they described features that no longer exist.

**Visible bug fix:**

- `src/Admin/Pages/Preferences.php:178` — Appearance card description listed "Dark, Light, Native, or Auto" while the Appearance tab itself only offers Dark / Light / Auto. Corrected to "Dark, Light, or Auto" in source string, POT and `nl_NL.po`. `.mo` regenerated.

**Comment / docblock cleanup** (no functional change, no selector changes, no token changes):

- `templates/access-mode.php` — docblock theme list updated.
- `assets/css/themes/canvas.css` — removed the empty "M3a: Native mode — reset / minimize CB styling" section and its two trailing "Native — no canvas overrides" markers; renumbered the two remaining canvas blocks.
- `assets/css/components/nav-tabs.css` — removed "cb-native theme renders WP's plain admin tabs unchanged" sentence.
- `assets/css/components/buttons.css` — removed the cb-native variant comment on `.cb-core-button--danger` default rule, simplified docblock.
- `assets/css/components/policy-table.css` — dropped cb-native fallback note.
- `assets/css/pages/safeguards-site-mode.css` — replaced misleading "Native theme — WP-admin-friendly toggle" header with accurate "Loading state" comment (the rule below is a generic loading state, not theme-scoped).
- `assets/css/pages/language.css` — removed "native mode reset" from docblock summary and the orphan "Native mode" comment between two section headers.
- `assets/css/pages/appearance.css` — removed orphan "Native preview" comment that had no following rule.
- `assets/css/tokens.css` — "Light/native theme overrides" comment → "Light theme overrides".

False positives intentionally retained: comments referring to "WP's native admin", "native HTML `<option>`", "native browser dialog", "native ES module" etc. point to web/WordPress standards, not the removed CB theme.

**Migration**

None required for the Native cleanup. `Themes::is_known()` already filters against the registry and `Themes::current()` falls through to `core_blueprint_dark` for any unknown stored value, so installations carrying a stale 'native' preference were already self-healing.

The Notes page slug change (see below) is also migration-free — the slug is request-time-only routing; no DB or settings storage references it. Existing bookmarks pointing at `?page=cb-notes` will 404; the operator is expected to reach Notes via the CB submenu or the new Dashboard card.

### Changed — Dashboard refactor

Full structural refactor of the Core Blueprint Dashboard. Replaces the previous Status / Quick Links / Core / Extensions layout with a tighter four-section structure plus a footer About card:

1. **Safeguards** — six health cards (Access Mode, Login Shield, Core Shield, Core Scanner, Beacon, Failsafe). Each card shows live state via a coloured indicator dot plus one factual detail line, and deeplinks to the relevant Safeguards tab. Replaces both the old Status section (which hosted a single Beacon tile) and the duplicate Failsafe / Configuration-hardening tiles in the Core section.
2. **Operations** — three nav cards (Logs, Notes, Reports). Daily-use forensic and reporting tools, grouped explicitly.
3. **Preferences** — six nav cards mirroring the Preferences tabs (Privacy, Notifications, Permissions, Appearance, Language, Report Branding). Report Branding is gated on `cb_manage_branding`.
4. **Extensions** — sibling CB plugins detected on the site, with active/version status. Unchanged behaviour.

The About card moves to a full-width footer beneath Extensions, marking it as suite-meta rather than a regular preference.

**Removed from the dashboard:**

- Status section + the `cb_core_status_tiles` filter consumption. The filter infrastructure stays alive (Beacon's StatusTile contribution is still registered) but the dashboard no longer renders it. Sibling-plugin tiles are no longer surfaced on the Dashboard; consider migrating contributors to the new `cb_core_safeguard_status_*` filters if they represent safeguard-equivalent state.
- Quick Links section — duplicated Audit log, Hub Pairing, and Maintenance Report which are now reachable via Operations / Safeguards / About.
- Core section — replaced by Safeguards + Preferences. "Configuration hardening · 3 modules" hardcoded count is gone; Core Shield's card now reports the actual `{enabled} of {total} hardening modules active` from `ModuleRegistry`.
- Marketing copy (`Core Blueprint secured`, `we've got your back`, `Set your secret key so your administrator can monitor this site`) replaced by factual one-line details.
- Tile eyebrow labels (`FORENSICS`, `INTEGRATION`, `SECURITY`, `AUDIT LOG`, `FAILSAFE`, `APPEARANCE`, `LANGUAGE`, `ABOUT`, `CORE-BLUEPRINT-AUDIT-SCANNER`).

**New — Safeguards Status helper** (`\CB\Core\Safeguards\Status`)

Centralised, defensive consumer of per-module safeguard status. Six WordPress filters (`cb_core_safeguard_status_access_mode`, `_login_shield`, `_core_shield`, `_core_scanner`, `_beacon`, `_failsafe`) each return `[ 'state' => 'ok|warn|err|off', 'detail' => '...', 'url' => '...' ]`. The helper validates the shape, whitelists the `state` value, sanitises `detail` and `url`, catches `\Throwable` from filter callbacks (logged via `error_log`, never the audit log), and falls back to `off` or `warn` rather than letting the dashboard crash.

State vocabulary maps to existing tile-grid CSS classes via `Status::dot_class()`: `ok → active`, `warn → warning`, `err → error`, `off → idle`. No new tokens or selectors.

**New — Per-module contributors** (`\CB\Core\Safeguards\Contributors`)

Default callbacks for the six filters, registered at default priority from `Core::init_hooks()`. Sibling plugins may override any module by registering their own callback at higher priority. Contributors are pure (read-only) and safe to call on every dashboard pageload. Beacon's logic mirrors the existing `Beacon\Dashboard\StatusTile` state machine — operator-disabled, no-key, no-poll-yet, healthy (<2h), faltering (<24h), no-connection (≥24h), with a 24h failed-auth-pattern bump to warn.

**Files**

- New: `src/Safeguards/Status.php`, `src/Safeguards/Contributors.php`.
- Refactored: `src/Admin/Pages/Dashboard.php` (now a pure consumer; passes precomputed deeplinks and `Status::all()` output to the template), `templates/dashboard.php` (full rewrite), `assets/css/pages/dashboard.css` (minimal page-CSS leaning on shared `tile-grid`).
- Modified: `src/Core.php` (wires `Contributors::boot()`).
- POT/PO: 32 new translatable strings, all NL translations included.

### Fixed — Notes page slug followed `cb-notes` instead of suite convention

While wiring the new Operations-section Notes card, surfaced that the Notes top-level admin page uses `cb-notes` as its menu slug — the only top-level CB Base page deviating from the suite-wide `core-blueprint-{name}` convention (Logs, Safeguards, Preferences, Reports, Hub Pairing all conform). Renamed to `core-blueprint-notes`.

Touched all nine references:

- `src/Notes/Admin/Page.php` — slug return value, hidden form input, two URL constructions.
- `src/Notes/Admin/Renderer.php` — three URL constructions (reset filters, tag link, pagination).
- `assets/js/features/notes.js` — four references (loadList URL param, defaults object, ajax-link page guard at two spots).
- `src/Admin/Pages/Dashboard.php` — Operations card link.

Existing bookmarks at `?page=cb-notes` will 404; operators reach Notes via the CB submenu or the new Dashboard card. No DB or settings storage references the slug, so no migration required.

**Bonus fix** — `src/Notes/Admin/Page.php:66` linked to `?page=cb-notes-preferences`, a page that no longer exists (per `PreferencesPage.php` docblock: *"No longer a standalone PageBase implementation — there is no separate cb-notes-preferences admin page"*). The "Notes preferences" button now points at `?page=core-blueprint-preferences&tab=notes` where the settings actually live.

## [1.1.6-dev] — unreleased

Bug fix release. Addresses a regression in the confirm-modal flow for Beacon admin actions (Hub Pairing → Clear secret key, Safeguards → Beacon → Clear secret key, Safeguards → Beacon → Disable Beacon). All three threw `TypeError: form.submit is not a function` after the operator confirmed in the modal, leaving the action unexecuted.

### Fixed — Beacon confirm-modal form submission

**Root cause.** WordPress's `submit_button()` helper takes the input's `name` attribute as its third parameter and defaults to `'submit'`. All five `submit_button()` calls in the Beacon admin templates passed `'submit'` explicitly, generating `<input type="submit" name="submit">`. Per the HTML spec, named form controls become accessible as properties on `HTMLFormElement` — so `form.submit` resolved to the input element rather than the native `submit()` method. The confirm flow's `form.submit()` call then crashed with `is not a function`.

This was a latent bug present since the confirm-modal pattern was introduced in 1.0.27 — surfaced now that Chris exercised the three flows in production testing.

**Two-layer fix:**

1. **JS — defensive submission** (`assets/js/features/beacon-confirm.js`). Submit via `HTMLFormElement.prototype.submit.call( form )` instead of `form.submit()`. The prototype method is unaffected by name-collisions on the form element. Future addons that use the `data-cb-core-confirm` pattern are now safe even if their templates inadvertently include a `name="submit"` control.
2. **Templates — remove the footgun.** Five `submit_button()` calls in `src/Beacon/Admin/SettingsPage.php` and `templates/beacon.php` updated from `submit_button( ..., 'submit', false )` to `submit_button( ..., '', false )`. With an empty name the button is no longer included in POST data, but the routing has always relied on hidden inputs (`cb_core_beacon_action`, `cb_core_beacon_toggle_action`) — so server behaviour is unchanged.

Defense in depth: JS protects the generic `data-cb-core-confirm` infrastructure against any caller's markup; templates remove the WP-specific quirk from our own code so future contributors don't have to know it.

## [1.1.5-dev] — unreleased

QC pass — addresses the consistency and theme-awareness observations from the audit after Notes (1.1.4-dev) integration. No new features, no schema changes, no public API changes.

### Changed — JS feature modules use `qs`/`qsa` consistently

`assets/js/features/notes.js` and `assets/js/features/log-exports.js` now import `qs`/`qsa` from `../core/dom.js` instead of using `document.querySelector(All)` directly. Brings them in line with the other 16 feature modules that already followed this convention. Element-scoped queries (e.g. `bar.querySelector('x')`) become `qs('x', bar)` — the second argument is the scope. No behavioural change.

### Changed — CSS theme-awareness

Eight token-based fixes across components and pages. The CB Standards rule "all styling via --cb-* tokens, never hardcoded colours" was followed in 1.1.3-dev and 1.1.4-dev for new code, but a sweep of pre-existing CSS surfaced eight legitimate violations (separate from intentional exceptions like the modal backdrop, theme-preview tiles, and the canonical canvas theme).

**`assets/css/tokens.css` — eight new tokens:**

- `--cb-on-{accent,success,danger,warning}` — text colour for content placed on top of a coloured fill (white in both built-in themes; centralised so future themes with lighter fills can override). Replaces `#fff /* always white */` patterns across five files.
- `--cb-badge-{cwe,compliance,cb,restrictive}-{bg,border,text}` — semantic badge palettes (vulnerability classification, compliance pass marker, CB Standard requirement, module risk classifier) with full dark/light theme overrides. Replaces 15 hardcoded `#hex` values in `components/badges.css` and `pages/safeguards-modules.css`.

**Theme-aware fixes:**

- `themes/canvas.css` notice tints (`.notice-success/error/warning/info`): `rgba(53, 224, 161, 0.08)` etc → `color-mix(in srgb, var(--cb-success) 8%, transparent)` etc. Used to render a single colour regardless of theme; now uses the theme's actual semantic tokens.
- `pages/safeguards-site-mode.css` access toggle thumb: hardcoded gradient `linear-gradient(135deg, #00ffdd 0%, #00b4ff 50%, #0037ff 100%)` → `var(--cb-grad)` token (which already carried this exact value).
- `pages/safeguards-site-mode.css` icon colours: `#00ffdd` / `#ff5c7a` → `var(--cb-accent-strong)` / `var(--cb-danger)`.
- `pages/appearance.css` bypass banner: hardcoded `#2a1515` / `#ffcfd5` → `color-mix(in srgb, var(--cb-danger) 18%, var(--cb-surface-1))` / `color-mix(in srgb, var(--cb-danger) 35%, var(--cb-text-strong))`. Banner now follows accent shifts in custom themes.
- `pages/appearance.css` light-mode badges and module cards: `#2c3338` / `#ffffff` → `var(--cb-text-strong)` / `var(--cb-surface-0)`.

**Documented intentional exceptions** (commented in source so future audits don't flag them):

- `pages/appearance.css` theme-preview tiles — block comment added explaining preview tiles deliberately use literal colour values; theme-aware would defeat the preview's purpose ("dark theme tile shows light colours when you're in light mode").
- `components/modals.css:47` modal backdrop — `rgb(0 0 0 / 0.45)`, theme-independent by design.
- `pages/safeguards-site-mode.css:229` admin bar notice — `#ffcc55`, WP admin bar is always dark regardless of CB theme.
- `pages/security.css` severity pills — Tailwind-derived dark-only palette, kept as theme-specific design choice.
- `themes/canvas.css` — canonical theme source, defines literal colour values by definition.

After this pass, a CSS hardcoded-colour grep returns zero unintentional violations.

### Notes — Memory correction

Internal note: prior memory referred to theme slugs as "cb-dark" / "cb-light" / "cb-native". Actual slugs in `Themes.php` are `core_blueprint_dark` and `core_blueprint_light`. Token scope is on both `<html>` (set early for first-paint via inline script) and `<body>` (mirrored for legacy CSS using body-scoped selectors). No code change — terminology only.

## [1.1.4-dev] — unreleased

Patch release. Integrates the standalone Core Blueprint Notes plugin (≤ v0.5.12) into Core Blueprint Base as a top-level submenu page, with settings as a Preferences tab. Drops the legacy plugin without a recovery window — pre-1.0 dev builds were never publicly shipped.

### Added — Notes

A new top-level page under Core Blueprint, positioned between Logs (20) and Reports (25). Site-specific notes for maintenance, security context, and operational handover. Free-text title + content (markdown or plain) + type (General/Maintenance/Security) + status (Backlog/Open/Important/Archived) + tags + assignee. Bulk operations (delete, archive). Export/import as JSON with a per-row decision flow (skip / overwrite / copy / create) for collisions.

**Architecture.** Subsystem under `src/Notes/` with namespace `CB\Core\Notes\` (PSR-4 via the existing autoloader). Bootstrap is wired from `Core::init()` alongside Beacon, Reports, Permissions, and Integrity.

- **`CB\Core\Notes\Bootstrap::boot()`** — registers REST routes, schema install/upgrade, audit-event-label filter, and the top-level Page via `cb_core_register_pages`.
- **`CB\Core\Notes\Repository`** — query/create/update/delete/archive/duplicate, plus export/import helpers. Persistence in `{prefix}cb_core_notes` (renamed from `{prefix}cb_notes`).
- **`CB\Core\Notes\Admin\Page`** — top-level admin page (slug `cb-notes`, position 22, capability `cb_manage_notes`). Filter form, results list, modal-based editor.
- **`CB\Core\Notes\Admin\PreferencesPage`** — drop-in fragment for the Preferences tab; static `maybe_handle_post()` and `render_body()`. No longer a standalone admin page.
- **`CB\Core\Notes\DB\Install`** — schema installer using dbDelta. Idempotent on every boot via the `cb_core_notes_db_version` option (separate from CB Base's audit-log version).

**REST contract:**
- `POST /core-blueprint/v1/notes/list` — read with filters/pagination
- `POST /core-blueprint/v1/notes/action` — write actions (create, update, delete, archive, bulk_delete, export, import_preview, import_commit)
- Both gated by `cb_manage_notes`.

**Capability.** New `cb_manage_notes` cap added to BOTH the cb_operator role and the administrator role on activation. Notes intentionally has no view/manage split — anyone who can reach the page can create, edit, delete, and bulk-act. The audit log records who did what for accountability.

**Audit-log events.** Nine event labels contributed via `cb_core_event_labels`: `note_created`, `note_updated`, `note_duplicated`, `note_status_changed`, `note_archived`, `note_deleted`, `notes_bulk_deleted`, `notes_exported`, `notes_imported`. Naming convention follows CB Base — no `cb_` prefix.

### Added — Preferences tab

New "Notes" tab in Preferences, positioned between Permissions and About. Renders the existing `PreferencesPage::render_body()` fragment that Luna had already prepared for this exact integration. Five settings: default type, default status, default assignment, default list layout, details-section initial state. Cap-gated on `cb_manage_notes`.

### Removed — Polymorphic context association

The standalone plugin's `context_type` and `context_id` columns and their associated public methods (`Repository::add_context`, `find_by_context`, `exists_context`; `NotesApi` wrappers) were dropped. Rationale:

- Reference-integrity problem: scan-finding IDs (the most natural target) are vluchtig — `ResultRepository::clear()` invalidates them, a new scan generates new IDs. A note "linked" to a since-cleared finding becomes a note about something no longer findable.
- Speculative infrastructure: shipping the plumbing without a UI defers the design decision while paying the schema/code-complexity cost. If the need surfaces concretely later, the columns can be re-added.
- Drift risk: polymorphic associations tend to grow into ad-hoc ticketing systems (status flow, due dates, comments, notifications). That's a different product.

### Migrated — standalone Notes plugin

`\CB\Core\Notes\Migration` detects the standalone `core-blueprint-notes` plugin on every admin request and silently deactivates it. One-shot admin notice, no recovery window. Existing `{prefix}cb_notes` rows from the standalone are left in the database — CB Base reads from a new `{prefix}cb_core_notes` table; the old one can be dropped manually if desired.

**No automatic data migration.** The standalone plugin (≤ v0.5.12) was a pre-1.0 development build, never publicly released. If you stored notes in the standalone during dev and want to retain them, dump-and-restore from `{prefix}cb_notes` to `{prefix}cb_core_notes` before running cleanup. Schema is identical except for the dropped `context_type`/`context_id` columns.

### Frontend

- **JS port** — `assets/js/notes.js` (748 lines IIFE) ported to native ES module `assets/js/features/notes.js`. Loaded via `wp_enqueue_script_module()` with module ID `@cb-core/notes` and dependency on `@cb-core/dom`. Server data delivered via `script_module_data_@cb-core/notes` filter. Modal/toast already used `window.cbCore.modal.show()` and `window.cbCore.toast.*` in the standalone — no factories to remove.
- **CSS** — new file `assets/css/pages/notes.css`. Three undefined tokens used by the standalone (`--cb-border-soft`, `--cb-surface-footer`, `--cb-surface-info`) mapped to existing CB Base tokens (`--cb-border`, `--cb-surface-2`, `--cb-accent-soft`). Twelve hardcoded `rgba()` values replaced with theme-aware tokens — the standalone's `rgba(255,255,255,x)` overlays would have rendered invisibly in light mode; now correctly themed across all three CB Base modes (cb-dark, cb-light, cb-native).

### Changed — Settings schema

`cb_core_settings['notes']` subkey added (was a separate `cb_notes_preferences` option in the standalone):

```
'notes' => [
    'default_type'          => 'General',
    'default_status'        => 'Backlog',
    'default_assigned_to'   => 0,
    'details_initial_state' => 'remember',  // 'remember' | 'closed' | 'open'
    'default_layout'        => 'list',      // 'list' | 'grid-2' | 'grid-3'
],
```

`SettingsRepository::update()` now delegates to `Settings::set_key('notes', ..., 'notes')`, so changes participate in the central audit-log entry that `Settings::set_key()` emits.

### Removed

- Standalone `cb-notes-preferences` admin page slug.
- `core-blueprint-notes` text-domain (consolidated into `core-blueprint`).
- Standalone constants `CB_NOTES_VERSION`, `CB_NOTES_FILE`, `CB_NOTES_DIR`, `CB_NOTES_URL` (replaced by Core's `CB_CORE_*`).
- `Defaults::OPTION_KEY` constant — no separate option, settings live in central `cb_core_settings`.
- The awkward double-namespace `CB\Notes\Notes\` (e.g. `Repository`, `MarkdownRenderer`) flattened to `CB\Core\Notes\` directly.

## [1.1.3-dev] — unreleased

Patch release. Integrates the standalone Core Blueprint Integrity Scanner (≤ v0.6.3) into Core Blueprint Base as a Safeguards tab, renamed **Core Scanner** in the UI. Drops the legacy plugin without a recovery window — pre-1.0 dev builds were never publicly shipped.

### Added — Core Scanner

A new Safeguards tab providing file integrity verification: WordPress core checksum verification, supported plugin/theme checksum verification, uploads directory executable scan. Detects unexpected changes; never modifies files. Run on demand or schedule daily/weekly.

**Architecture.** The scanner subsystem lives under `src/Integrity/` with namespace `CB\Core\Integrity\` (PSR-4 via the existing autoloader). Bootstrap is wired from `Core::init()` alongside Beacon, Reports, and Permissions.

- **`CB\Core\Integrity\Security\IntegrityModule`** — implements the `Module` interface with Plain/Technical descriptions per feature; registered directly in `Core::register_builtin_modules()` alongside Fingerprint and Headers. Three features: core checksum verification, WP.org plugin/theme checksum verification, uploads executable scan.
- **`CB\Core\Integrity\Bootstrap::boot()`** — registers REST routes, cron handler, and event-label filter. No top-level page (the scanner renders as a Safeguards tab).
- **`CB\Core\Integrity\Storage\ResultRepository`** — three options autoloaded false: `cb_core_integrity_latest`, `cb_core_integrity_history`, `cb_core_integrity_baseline`. Configuration delegates to the central `cb_core_settings['integrity']` subkey via `Settings::set_key()`. No separate settings option.
- **`CB\Core\Integrity\Scheduler\Cron`** — single hook `cb_core_integrity_scan_run`, scheduled via `wp_schedule_event` based on the user's chosen daily/weekly cadence. Cleared on plugin deactivation.
- **`CB\Core\Integrity\Admin\Page::render_panel()`** — static panel renderer called from Safeguards. Owns the rich UI (status, metrics, findings groups, baseline diff, history). Page chrome (h1, intro, tabnav) is owned upstream by Safeguards.

**REST contract:**
- Admin endpoints under `core-blueprint/v1/integrity/admin/*` (scan, summary, findings, clear, baseline, baseline/component, settings) — gated by `cb_manage_integrity`.
- Hub-bound mirror endpoints under `core-blueprint/v1/integrity/{summary,findings,scan}` — unchanged from the standalone, using the existing Beacon auth fallback.

**Capability + admin-toggle.** New `cb_manage_integrity` cap added to `cb_operator` role. New checkbox in Permissions tab → Administrator capabilities: "Administrators may run Core Scanner" (default off) — when enabled, all administrators virtually inherit `cb_manage_integrity` via the existing user_has_cap filter mechanism. Same pattern as the existing "Administrators may generate Maintenance Reports" toggle.

**Audit-log events.** Six event labels contributed via `cb_core_event_labels`: `integrity_scan_started`, `integrity_scan_completed`, `integrity_scan_failed`, `integrity_settings_changed`, `integrity_results_cleared`, `integrity_api_scan_requested`.

### Changed — Permissions Caps refactor

`\CB\Core\Permissions\Caps::filter_user_has_cap()` was hard-coded for a single cap (`cb_manage_reports`). Refactored to a data-driven `ADMIN_TOGGLE_MAP` so future admin-toggles register with one entry. Adding a new toggle is now: register the cap in `Roles::OPERATOR_CAPS`, add a settings entry under the matching path, and add a single mapping line to `ADMIN_TOGGLE_MAP`. No other code changes needed.

The two current toggles:
- `cb_manage_reports`   → `reports.admin_can_generate.maintenance`
- `cb_manage_integrity` → `integrity.admin_can_run`

Audit-log event renamed `permissions.admin_can_generate_toggled` → `permissions.admin_caps_changed` to reflect that the save handler now writes both toggles in one transaction.

### Changed — Safeguards tab order

Login Shield and Core Shield swapped. New order:

`Overview | Access Mode | Login Shield | Core Shield | Core Scanner | Beacon | Failsafe`

Threat-model flow reads as: access → login → configuration → files → monitoring → recovery. Login Shield sits before Core Shield because protecting the login endpoint is the more specific outer-perimeter concern; Core Shield is the broader baseline hardening once an authenticated user is past the login. Tab cards on the Safeguards Overview reflect the same order.

### Changed — Settings schema

`cb_core_settings['integrity']` now matches the keys actually used by the scanner:

```
'integrity' => [
    'schedule'             => 'disabled',  // 'disabled' | 'daily' | 'weekly'
    'plugin_checksums'     => true,
    'theme_checksums'      => true,
    'uploads_scan'         => true,
    'max_visible_findings' => 50,
    'admin_can_run'        => false,
],
```

Previously the defaults shape carried `scan_core` and `scan_cb_plugins` placeholders that the scanner never actually read. Synchronised to reality.

### Migrated — standalone Integrity Scanner plugin

`\CB\Core\Integrity\Migration` detects the standalone `core-blueprint-integrity-scanner` plugin on every admin request and silently deactivates it. One-shot admin notice ("Core Scanner is now built into Core Blueprint…") and recommendation to delete the deactivated plugin.

**No data migration.** The standalone plugin (≤ v0.6.3) was a pre-1.0 development build, never publicly released. No installed base whose data needs preserving. Option keys, REST namespaces, cron hooks, and capabilities are all renamed to the `cb_core_*` / `core-blueprint/v1/*` / `cb_manage_*` conventions without legacy fallback chains.

If you ran the standalone plugin during dev and want to retain its scan history/baseline, manually copy the option values from `cb_integrity_*` (legacy) or `cb_integrity_scanner_*` (older legacy) to `cb_core_integrity_*` before activating CB Base 1.1.3-dev. No automated migration ships.

### Frontend

- **JS port** — the standalone plugin's 588-line IIFE in `assets/js/admin.js` ported to native ES module `assets/js/features/core-scanner.js`. Loaded via `wp_enqueue_script_module()` with module ID `@cb-core/core-scanner` and dependency on `@cb-core/dom`. Server data delivered via `script_module_data_@cb-core/core-scanner` filter.
- **Modal + toast consolidated** — Luna's local `toast()` factory (DOM region + auto-hide timer) and `confirmModal()` factory (backdrop + dialog + focus management) both removed. The module now consumes `window.cbCore.toast.success/error/info` and `window.cbCore.modal.show()` from the shared `@cb-core/dom` runtime API. ~17 CSS selectors for `.cbis-modal*` and `.cbis-toast*` dropped from the stylesheet alongside the JS factories.
- **Plain/Technical** — local "Show technical details" toggle and `cbis-show-technical` page-state class removed. Plain/Technical state now follows the centralised `UI::current_mode()` and the existing `features/description-toggle.js` peek-toggle on `.cb-core-dual` blocks (from CB Base 1.0.23).
- **CSS** — new file `assets/css/pages/safeguards-core-scanner.css`. All `cbis-*` selectors renamed to `cb-core-integrity-*`. Token redefinitions (`.cbis-page { --cb-* }` blocks the standalone shipped) dropped — the scanner now consumes the central `html[data-cb-theme="..."]` tokens unchanged. `--cb-radius-lg` (which doesn't exist in CB Base) replaced by `--cb-radius-md`. Six hardcoded `rgba()`/`#fff` values inherited from the standalone removed; remaining values use `var(--cb-*)` tokens with semantic fallbacks only.

### Removed

- Top-level `core-blueprint-integrity-scanner` admin page slug. Operators landing on the old page from a bookmark are redirected to Safeguards.
- Standalone constants `CBIS_VERSION`, `CBIS_FILE`, `CBIS_DIR`, `CBIS_URL` (replaced by Core's `CB_CORE_*`).
- Text-domain `core-blueprint-integrity-scanner` (consolidated into `core-blueprint`).
- `ResultRepository::save()`, `latest()`, `clear_latest()`, `save_settings()` deprecated aliases — no installed base to call them.
- Legacy option fallback chain (`cb_integrity_scanner_*` → `cb_integrity_*`) — pre-1.0, no installed data.

## [1.1.2-dev] — unreleased

Patch release. Permissions tab moved from Safeguards to Preferences as part of a cleaner separation between hardening configuration and meta-governance.

### Changed — Permissions location

The Permissions tab — operator assignment, Permissions-page visibility, and per-administrator capability toggles — has moved from `Safeguards` to `Preferences`. The Permissions tab itself is unchanged; only its parent page differs.

**Why.** Safeguards is hardening configuration: Access Mode, Core Shield, Login Shield, Beacon, Failsafe — every tab is a configurable safeguard layer in the threat model. Permissions is meta-governance: who may configure CB, who sees status, who may run reports. Conceptually a personal/site preference, not a hardening layer. Grouping it under Preferences (alongside Privacy, Notifications, Language, Appearance, Report Branding) puts governance with the other "who/what/how" choices.

**Affected URLs.** The Permissions tab URL changes:

- Old: `admin.php?page=core-blueprint-safeguards&tab=permissions`
- New: `admin.php?page=core-blueprint-preferences&tab=permissions`

No internal links in CB Base point to the old URL, so this change is transparent for the suite. External bookmarks pointing at the old URL will land on the Safeguards Overview tab instead — operators can re-bookmark from Preferences.

**Tab order in Preferences:**
`Overview | Privacy | Notifications | Language | Appearance | Report Branding | Permissions | About`

Permissions sits between Report Branding and About — both are configuration of *how* CB presents itself outwards, distinct from About (which is naslag).

### Changed — Preferences Overview

The Preferences Overview tab now shows two additional tab-cards alongside the existing Privacy / Notifications / Language / Appearance / About cards:

- **Report Branding** — operator-only (mirrors the tab visibility); previously absent from the Overview despite being a tab. Inconsistency fixed.
- **Permissions** — view-cap required, hide-toggle respected; same visibility logic as the tab itself.

The Overview intro string is updated to mention governance ("…and who may configure Core Blueprint…").

### Changed — Safeguards tab order

Safeguards loses its Permissions tab. New tab order:
`Overview | Access Mode | Core Shield | Login Shield | Beacon | Failsafe`

The order itself is unchanged — only the trailing Permissions entry is removed.

### Migration notes

No data migration required. The Permissions settings (`cb_core_settings['permissions']`), capabilities (`cb_view_permissions`, `cb_manage_permissions`), operator role assignments, and AJAX/REST endpoints are unchanged. Only the page that renders the tab moves.

## [1.1.1] — 2026-04-27

Patch release. Two coordinated cleanups: tab/title consistency across all admin pages, and a migration from a workaround pattern to the official WordPress 6.7 script-module data API.

### Changed — admin tab/title alignment

Tab labels and `<h1>` page titles now match exactly on every CB admin page. Previously several tabs used a short label (e.g. `Audit`) that mismatched the longer page title (`Audit Log`), causing visual jitter when clicking between tabs.

- **Logs page tabs** — `Logs Overview` → `Overview`; `Audit` → `Audit Log`; `System` → `System Log`. Page titles unchanged; only tab labels adjusted.
- **Beacon Connection log** — `Connection` tab → `Connection Log` to align with sibling Audit/System Log conventions.
- **Beacon page** — `<h1>External Monitoring (Beacon)</h1>` → `<h1>Beacon</h1>`. The intro paragraph already explains the function in plain language.
- **Reports → Overview** — `<h1>Reports</h1>` → `<h1>Overview</h1>` to match the tab label.
- **Preferences → Privacy** — `<h1>Privacy & Logging</h1>` → `<h1>Privacy</h1>` to match the tab label. The intro paragraph still surfaces the logging scope.

### Changed — script-module data delivery

CB Base 1.1.0 used a workaround for a WordPress limitation: `wp_localize_script()` doesn't work with script modules (introduced in WP 6.5). The workaround registered a no-src classic script handle (`core-blueprint-data`) purely as a vehicle for an inline `<script>window.cbCore = { … }</script>` tag printed before the modules evaluated. Each feature module then read its server-delivered nonces and i18n strings from that single global blob.

WordPress 6.7 introduced `script_module_data_{$module_id}` — the official mechanism for passing server-side data into script modules. Each module gets its own `<script type="application/json" id="wp-script-module-data-@cb-core/{name}">…</script>` tag, which the module reads via `getElementById` at evaluation time.

This release migrates CB Base to that official API:

- **Module IDs renamed** — handles like `cb-core-js-login-shield` are now `@cb-core/login-shield`. The `@cb-core/` namespace mirrors WP core's own `@wordpress/…` convention.
- **Per-module data** — each module has its own data tag containing exactly the nonces and i18n strings it needs. No more shared global blob.
- **`window.cbCore` runtime API preserved** — the cross-plugin runtime surface (`modal`, `toast`, `qs`, `qsa`, `apiPost`, `setTheme`, `setLocale`, `copyToClipboard`, `apiVersion`) still attaches to `window.cbCore` from inside `core/*` modules. Downstream CB plugins (Hub, Access Control, Protected Content, Invoice) that read these helpers off the global continue to work without changes.
- **Dropped** — the no-src `core-blueprint-data` script handle and its `wp_add_inline_script()` workaround. The dummy handle is gone from the page head; data tags appear in its place.

### Removed — dead code

- `currentTheme`, `currentLocale` data keys (server-side, dead since 1.0.x — never read by JS)
- `reportsConfirmDelete`, `reportsDeleting` i18n keys (replaced by full title/body/confirm modal triplets in 1.1.0)

### Requires

- **WordPress 6.7+** — bumped from 6.0 because `script_module_data_{$id}` is a 6.7 API
- PHP 8.0+ unchanged

### Migration notes for extension developers

Extension plugins that use `wp_localize_script()` — already broken with script modules — can now use `script_module_data_{$module_id}` instead. See `cb-extension-development-guide.md` § 9 for the full pattern.

Extensions that read `window.cbCore.modal`, `window.cbCore.toast`, `window.cbCore.apiPost`, `window.cbCore.qs`, `window.cbCore.qsa`, `window.cbCore.setTheme`, `window.cbCore.setLocale`, `window.cbCore.copyToClipboard`, or `window.cbCore.apiVersion` — **no changes required.** That runtime API surface is unchanged.

Extensions that read `window.cbCore.nonceAdmin`, `window.cbCore.i18n`, or `window.cbCore.descMode` directly — these were never documented stable surface, but if you depend on them, switch to your own per-module data tag.

## [1.1.0] — 2026-04-27

First minor release after 1.0. A complete UI/CSS/markup modernisation pass plus four new PHP UI helpers that abstract markup-emission for the most-repeated component patterns. Zero new features for end-users; substantial improvements in maintainability, consistency, and performance under the hood.

### Background — what was wrong

The 1.0.x line accumulated organic markup growth: every page invented its own save-status class (`cb-core-ls-save-status`, `cb-core-privacy-save-status`, `cb-core-alert-recipient-status`, etc.), three different radio-card patterns existed in parallel (`cb-core-ls-radio`, `cb-core-privacy-radio`, `cb-core-desc-radio`), three field-wrapper variants (`cb-core-ls-field`, `cb-core-pref-row`, `cb-core-recipient-row`), 73 inline `style="..."` attributes, 19 `<th style="width:...">` declarations, and `<table class="wp-list-table">` markup with column widths hard-coded per row. CSS pages had ~50 redundant theme-scope overrides and per-page hex colours instead of token references.

This release replaces all of that with one canonical pattern per concern, enforced by PHP helpers where applicable and by component CSS where helpers don't apply.

### Added — PHP UI helpers

Four new helpers in `\CB\Core\UI\` namespace, joining the existing `Tile`, `Card`, `Spinner`, and `Status` helpers from M2:

- **`FormStatus::render( $args )`** — canonical save-feedback element. 12 instances across 8 templates now go through this single helper. Always emits `class="cb-core-form-status"` + `role="status"` + `aria-live="polite"`. Optional args: `block` (div vs span), `target` (data-target attribute for JS hooks), `id`, `tight` modifier, `data` array for additional `data-*` attributes.

- **`RadioCard::render( $args )`** — individual radio-card with three variants: `default`, `compact`, `checkable`. Variants map to BEM modifier classes; the `checkable` variant emits an animated checkmark indicator and supports `is-selected-user` / `is-selected-site` state classes. Two-level data-attribute support: `data` for the wrapper `<label>`, `input_data` for the inner `<input>`.

- **`RadioGroup::render( $args )`** — single-select radio groups, delegates to `RadioCard::render()` per option. Data-driven API: caller provides options array, helper derives checked-state from a single `value`. Layout choice: `stack` (default) or `grid` (auto-fill grid wrapper). Runtime-rejects the `checkable` variant via `_doing_it_wrong()` because dual-state selection cannot be derived from a single value — checkable radios use manual foreach + `RadioCard::render()` instead.

- **`Field::render( $args )`** — form-field wrapper with four variants: `default` (vertical stack), `inline` (horizontal flex row), `separated` (sibling-divider above), `enable` (first-row toggle). Slot-based: `label` is plain text (helper escaped + wrapped), `control` is HTML-string (caller-built), optional `hint` rendered as `<p class="description">`. Native support for label-with-sublabel patterns via `label_sub` argument. Optional `label_for` decides between `<label for>` vs `<span>` wrapper.

All four helpers follow the existing CB UI conventions:
- `final class` with `public static render()`
- Caller-localised i18n strings (helper only escapes for output)
- Slot-content not escaped (caller responsibility, expressly documented)
- Defensive guards against malformed `data` keys
- Runtime `_doing_it_wrong()` rejection for invalid variants and missing required arguments

### Added — CSS components

Six new component stylesheets in `assets/css/components/`:

- `form-status.css` — canonical save-status styling, driven by `data-kind` attribute (`pending` | `success` | `error`)
- `field.css` — unified form-field wrapper with four variants
- `radio-card.css` — unified radio-card with three variants and full BEM tree
- `log-table.css` — Hub-pattern semantic table layout with three responsive tiers (≥1024px tabular grid → 640-1023px 2-col card → <640px stacked)
- `table-cols.css` — column-width utility classes for `<col>` elements
- `actions.css` — unified `cb-core-actions` action-row component (flex with token gap)

### Changed — markup standardisation

- **Save-status consolidation:** 6 page-specific status classes (`cb-core-ls-save-status`, `cb-core-privacy-save-status`, `cb-core-alert-recipient-status`, `cb-core-appearance-status`, `cb-core-lang-status`) collapsed into a single `cb-core-form-status` component. State now driven by `data-kind` attribute instead of mixed `is-ok` / `is-success` classList variants. JS modules updated to a single canonical `setStatus()` pattern.
- **Field-wrapper consolidation:** 3 page-specific patterns (`cb-core-ls-field`, `cb-core-pref-row`, `cb-core-recipient-row`) replaced by `cb-core-field` with variants `--default` / `--inline` / `--separated` / `--enable`.
- **Radio-card consolidation:** 3 page-specific patterns (`cb-core-ls-radio`, `cb-core-privacy-radio`, `cb-core-desc-radio`) replaced by `cb-core-radio-card` with variants `--compact` / `--checkable` and BEM children `__body` / `__label` / `__desc` / `__check`.
- **Log tables:** `connection-log`, `partials/log-events-page`, `maintenance-report` admin-table converted from `<table class="wp-list-table">` to Hub-pattern `<section role="table">` with full ARIA chain (table → rowgroup → row → cell → columnheader). Adds `<time datetime="">` for timestamps and `data-label` on cells for responsive card-fallback rendering.
- **Table widths:** 19 `<th style="width:Npx">` declarations replaced by semantic `<colgroup>` with `cb-core-col-*` utility classes across 6 non-log tables (notifications, permissions, reports/overview, failsafe).
- **Section vs panel:** explicit semantic definitions in `layout.css` — `cb-core-section` for logical groupings without frame, `cb-core-panel` for framed config cards. Page-specific `cb-core-pref-section` migrated to `cb-core-section` with BEM modifiers.
- **H2 baseline:** auto-targeted via `.cb-core-wrap h2` rule. No required class on standard heading elements; page-specific h2 modifiers (`cb-core-notification-group__title`, `cb-core-access-option__title`) layer on top via specificity.

### Changed — CSS modernisation

- **Token migration complete:** all page CSS now references `--cb-*` design tokens. Legacy WP-greys, hardcoded font-sizes, and rgba inline overrides eliminated. Beacon admin went from 74 hex references to 2 (only legit dynamic CSS properties remain).
- **Theme-scope consolidation:** 41 redundant `body[data-cb-theme="..."]` overrides removed (M5). Beacon-admin theme scopes fully eliminated (M6) — works automatically via tokens across all three themes (cb-dark, cb-light, cb-native).
- **Component architecture:** 13 dedicated component CSS files vs the original 8, all token-based and theme-aware without page-specific overrides.

### Changed — JavaScript

- Save-status JS modules refactored to single `setStatus(text, kind)` pattern using `el.dataset.kind = '...'`. Replaces mixed `classList.add('is-ok')` / `classList.add('is-success')` legacy patterns. Affected modules: `login-shield.js`, `alert-recipients.js`, `language.js`, `appearance.js`, `privacy.js`.
- `language.js` selector updated from `.cb-core-desc-radio` to `.cb-core-radio-card--checkable`.

### Removed

- ~155 lines of dead radio-card CSS across `safeguards-login-shield.css`, `privacy.css`, `language.css` (replaced by single `radio-card.css` component)
- ~58 lines of dead save-status CSS across 5 page files (replaced by single `form-status.css` component)
- ~47 lines of dead field-wrapper CSS across 3 page files (replaced by single `field.css` component)
- ~22 lines of dead `cb-core-ls-label` CSS (replaced by `cb-core-field__label` baseline in field component)
- Dead `wp-list-table` responsive media query (no markup using it any more)
- 73 inline `style="..."` attributes from admin templates (1 remains: legitimate dynamic CSS property `--cb-mock-accent` for runtime color preview)
- All 19 `<th style="width:Npx">` declarations
- Page-specific classes: `cb-core-ls-save-status`, `cb-core-privacy-save-status`, `cb-core-alert-recipient-status`, `cb-core-appearance-status`, `cb-core-lang-status`, `cb-core-ls-field`, `cb-core-pref-row`, `cb-core-recipient-row`, `cb-core-pref-section`, `cb-core-pref-label`, `cb-core-pref-meta`, `cb-core-ls-radio`, `cb-core-privacy-radio`, `cb-core-desc-radio` (and BEM children), `cb-core-ls-label` (and BEM children)

### Fixed

- `privacy.js` `form is not defined` ReferenceError when saving privacy settings — `const form` declaration ordering bug introduced during save-status consolidation
- CSS specificity collision between `[data-col="X"]` and card-reset selectors in responsive log-table layouts
- Stale `cb-core-pref-label` and `cb-core-pref-meta` template references that survived a CSS drop in an earlier pass

### Public API

The CB Base public API surface is unchanged from 1.0.31-dev. Hub continues to consume the same namespaces, hooks, and contracts:

- `\CB\Core\Admin\PageBase`, `\CB\Core\Admin\PageRegistry`
- `\CB\Core\Beacon\Crypto`, `\CB\Core\Beacon\Rest\StatusResponse`
- `\CB\Core\DB`, `\CB\Core\DB\DeleteBuilder`, `\CB\Core\DB\InsertBuilder`, `\CB\Core\DB\QueryBuilder`, `\CB\Core\DB\UpdateBuilder`
- `cb_core_register_pages` (action hook)

The eight UI helpers (`Tile`, `Card`, `Spinner`, `Status`, `FormStatus`, `RadioCard`, `RadioGroup`, `Field`) are available under `\CB\Core\UI\` for downstream plugins (Hub, future Access Control, Protected Content) but are not part of the cross-plugin contract; they are CB Base internals exposed for use within the suite.

### Migration notes

No action required for users. CSS class names that have been removed (listed above) are not part of any documented public surface — they were internal styling hooks. If you have custom CSS that references any of those classes, update it to use the unified components:

| Old (removed) | New |
|---|---|
| `.cb-core-ls-save-status`, `.cb-core-privacy-save-status` etc. | `.cb-core-form-status` |
| `.cb-core-ls-field`, `.cb-core-pref-row`, `.cb-core-recipient-row` | `.cb-core-field` (+ variant modifier) |
| `.cb-core-ls-radio`, `.cb-core-privacy-radio`, `.cb-core-desc-radio` | `.cb-core-radio-card` (+ variant modifier) |

## [1.0.31-dev] — 2026-04-25

Pure restructure release — zero functional changes. Closes the chapter on migration-history artefacts in source code: numbered JS prefixes (`01-dom.js` … `15-beacon-confirm.js`) reflected migration order rather than logical grouping, and source-code comments documented "how it got here" instead of "what it does." Following the precedent set by Hub 1.0.63-dev's restructure, this release moves Base to the same `core/` + `features/` layout with path-mirror script handles.

### Public API surface — onveranderd

The contract Hub depends on is **explicitly preserved**. Hub 1.0.63-dev consumes the following from CB Base, all unchanged in name and signature:

**Namespaces:**
- `\CB\Core\Admin\PageBase`
- `\CB\Core\Admin\PageRegistry`
- `\CB\Core\Beacon\Crypto`
- `\CB\Core\Beacon\Rest\StatusResponse`
- `\CB\Core\DB`
- `\CB\Core\DB\DeleteBuilder`
- `\CB\Core\DB\InsertBuilder`
- `\CB\Core\DB\QueryBuilder`
- `\CB\Core\DB\UpdateBuilder`

**Hooks:**
- `cb_core_register_pages` (action)

Hub does not need to update anything in 1.0.64-dev. Internal-only renames listed below do not affect Hub's import statements or hook registrations.

### Changed — JS-asset structure

Numbered-prefix layout replaced by `core/` + `features/` mirroring Hub's convention. The `core/` segment holds shared infrastructure (DOM helpers, public API surface), `features/` holds per-domain modules (one file per page or feature). Module imports use native filesystem-relative paths — no bundler, no build step, same runtime as before.

**File renames** (15 files):

| Old path | New path |
|----------|----------|
| `assets/js/01-dom.js` | `assets/js/core/dom.js` |
| `assets/js/02-public-api.js` | `assets/js/core/public-api.js` |
| `assets/js/03-appearance.js` | `assets/js/features/appearance.js` |
| `assets/js/04-language.js` | `assets/js/features/language.js` |
| `assets/js/05-failsafe.js` | `assets/js/features/failsafe.js` |
| `assets/js/06-core-shield.js` | `assets/js/features/core-shield.js` |
| `assets/js/07-description-toggle.js` | `assets/js/features/description-toggle.js` |
| `assets/js/08-log-exports.js` | `assets/js/features/log-exports.js` |
| `assets/js/09-notifications.js` | `assets/js/features/notifications.js` |
| `assets/js/10-alert-recipients.js` | `assets/js/features/alert-recipients.js` |
| `assets/js/11-site-mode.js` | `assets/js/features/site-mode.js` |
| `assets/js/12-login-shield.js` | `assets/js/features/login-shield.js` |
| `assets/js/13-privacy.js` | `assets/js/features/privacy.js` |
| `assets/js/14-logs-toggle.js` | `assets/js/features/logs-toggle.js` |
| `assets/js/15-beacon-confirm.js` | `assets/js/features/beacon-confirm.js` |

**Script-handle renames** — path-mirror convention (the `features/` prefix is implicit and dropped from handles since `features/` is the default; `core/` is included to mark shared infrastructure):

| Old handle | New handle |
|-----------|-----------|
| `cb-core-js-01-dom` | `cb-core-js-core-dom` |
| `cb-core-js-02-public-api` | `cb-core-js-core-public-api` |
| `cb-core-js-03-appearance` | `cb-core-js-appearance` |
| `cb-core-js-04-language` | `cb-core-js-language` |
| `cb-core-js-05-failsafe` | `cb-core-js-failsafe` |
| `cb-core-js-06-core-shield` | `cb-core-js-core-shield` |
| `cb-core-js-07-description-toggle` | `cb-core-js-description-toggle` |
| `cb-core-js-08-log-exports` | `cb-core-js-log-exports` |
| `cb-core-js-09-notifications` | `cb-core-js-notifications` |
| `cb-core-js-10-alert-recipients` | `cb-core-js-alert-recipients` |
| `cb-core-js-11-site-mode` | `cb-core-js-site-mode` |
| `cb-core-js-12-login-shield` | `cb-core-js-login-shield` |
| `cb-core-js-13-privacy` | `cb-core-js-privacy` |
| `cb-core-js-14-logs-toggle` | `cb-core-js-logs-toggle` |
| `cb-core-js-15-beacon-confirm` | `cb-core-js-beacon-confirm` |

**Internal imports updated** — all 14 cross-module imports rewritten to use the new structure. Within `core/`: `from './dom.js'` (siblings). From `features/` to `core/`: `from '../core/dom.js'` and `from '../core/public-api.js'`.

### Changed — CSS-asset structure (N/A)

CB Base ships a single `assets/admin.css`. No numbered files, no restructure needed. The Hub precedent (numbered CSS where cascade order is functional) does not apply.

### Changed — PHP source-comment scrub

Source-code comments documented migration history alongside what the code does. Migration history belongs in the CHANGELOG, not in source files — over time it drifts from accurate ("Phase 2 v1.0.22-dev") to misleading ("v1.0.30-dev with comments still saying Phase 2"). Comments scrubbed across 16 JS files and 2 PHP files:

**Removed patterns:**
- `Phase X (vY.Y.Y):` markers in titles and inline comments
- `(POC, vY.Y.Y-dev)` tags from header titles
- Bare `(vY.Y.Y)` version suffixes from header titles
- `Migrated from the legacy big-block in admin.js`
- `Direct port of the legacy IIFE N from admin.js` (multiple variants)
- `**Migration note (1.0.21):**` / `**Migration note (1.0.23):**` multi-line blocks
- `Behaviour is preserved 1:1 from the legacy IIFE`
- `matching the legacy IIFE's early return` / `matching the legacy behaviour`
- `The classic IIFE in admin.js previously...` paragraph in `core/public-api.js`
- `Migrated to {@see Request} in 1.0.13` in `Ajax/Handlers/Settings.php`
- `Phase 2+` reference in `Settings.php::defaults()` docblock

**Preserved:**
- All `@since` directives — these are structured metadata that document API introduction versions, not migration history. Tools and consumers rely on them.
- Code-behaviour comments using "legacy" in functional context (e.g., `Falls back to the legacy textarea-select trick` in `core/dom.js` — describes what the function does, not how it got here).
- The `Phase 1-5 (v1.0.21–1.0.27)` migration block in `Admin.php::enqueue_assets()` removed entirely; that information lives in the CHANGELOG.

### Changed — `Admin.php` enqueue stack rewritten

The `$cb_core_js_modules` array now uses explicit `id` (path) + `handle` per entry, replacing the old `id` (numbered name) + computed `cb-core-js-{id}` handle convention. The new structure is:

```php
[ 'id' => 'core/dom', 'handle' => 'cb-core-js-core-dom', 'deps' => [] ],
[ 'id' => 'features/appearance', 'handle' => 'cb-core-js-appearance', 'deps' => [...] ],
```

Layout convention documented inline; the multi-line "Phase 1–5" historical comment block is gone.

### Changed — Stale filename references in doc-comments + templates

Doc-comments and template comments referencing old filenames updated to match the new paths:

- `core/public-api.js` references to `01-dom.js`, `03-appearance.js`, `04-language.js` updated
- `features/alert-recipients.js` reference to `01-dom.js` updated
- `features/description-toggle.js` reference to `04-language.js` updated
- `templates/partials/log-events-page.php` reference to `14-logs-toggle.js` updated
- `templates/beacon.php` reference to `15-beacon-confirm.js` updated

### Database, hooks, options, slugs — unchanged

Per the established stability rule, none of the following were touched in this restructure:

- Table names (`cb_audit_log`, `cb_connection_log`, etc.)
- Hook/filter names (`cb_core_status_tiles`, `cb_core_register_pages`, `cb_core_beacon_*`, etc.)
- Option keys (`cb_core_beacon_secret_key`, `cb_core_beacon_enabled`, etc.)
- AJAX action names (`cb_core_beacon_toggle`, etc.)
- Event slugs in audit log
- Text domain `core-blueprint`
- Admin menu slugs (`core-blueprint-safeguards`, `core-blueprint-hub-pairing`, etc.)

Only PHP class names + file organization change — and in this release, no class-name changes either, only JS file paths and source-comment cleanup.

### Migration

- **Hub 1.0.63-dev consumers**: no action required. The nine namespaces + one action Hub depends on are explicitly preserved in name and signature.
- **Customer modules contributing JS**: if any third-party module enqueues a script with one of the old `cb-core-js-NN-name` handles as a dependency, update to the new handle (see table above). The `cb_core_status_tiles` filter and other PHP-side hooks are unaffected.
- **No data migration**: zero database changes, zero option-key changes, zero AJAX-action renames.

## [1.0.30-dev] — 2026-04-25

Single-feature release that closes a long-standing roadmap item: Beacon error codes now have a documented friendly-label catalog. The plumbing was queued for "wanneer Hub het concreet nodig heeft", and rather than revisit it during every Hub session, this release bakes the catalog into CB Base so Hub (and any other consumer) can render human-readable error messages with a single helper call.

### Added — `\CB\Core\Beacon\Errors` friendly-label catalog

Hub and other consumers receive Beacon error responses as `{ success: false, error: { code, message } }` envelopes. The wire-level `code` is machine-readable (`BACKUP_ALREADY_RUNNING`, `cb_core_beacon_no_ssl`, etc.) — fine for branching logic, awful for end-user display. Until now, every consumer that wanted to show a friendly message either rolled its own mapping or shipped the raw code into the operator UI. This release establishes a single source of truth.

- **New class `\CB\Core\Beacon\Errors`** in `src/Beacon/Errors.php`. Two public methods:
  - `Errors::label( string $code ): string` — returns the catalogued friendly label, falls back to the raw code if unknown
  - `Errors::all(): array` — returns the entire catalog as a code → label associative array, useful for documentation pages or debug glossaries
- **22 codes catalogued**, covering all Beacon-emitted error codes across three subsystems:
  - **Auth** (3 codes): `cb_core_beacon_no_ssl`, `cb_core_beacon_unauthorized`, `cb_core_beacon_forbidden`
  - **Backup** (13 codes): all `CB_BACKUP_ERR_*` constants
  - **Updates** (6 codes): `INVALID_RUN_ID`, `INVALID_ITEMS`, `NO_VALID_ITEMS`, `RUN_EXISTS`, `WORKER_LOOPBACK_FAILED`, `RUN_NOT_FOUND`
- **Plain-language tone** in line with the suite-wide promise: a non-technical operator (zorgmedewerker, gemeenteraadslid) should be able to read the label and either understand what's wrong or know who to ask. Example: `cb_core_beacon_unauthorized` is "Hub did not send an Authorization header. The server may be stripping it before PHP sees it — check the Authorization-passthrough .htaccess rule." Not "401 Unauthorized."
- **Two extension filters:**
  - `cb_core_beacon_error_label` — fires on every `Errors::label()` call, lets consumers override or extend per-code. Hub will use this to add Hub-emitted codes (`BEACON_UNREACHABLE`, `BACKUP_TIMEOUT`, etc.) without touching CB Base.
  - `cb_core_beacon_error_catalog` — fires on `Errors::all()` calls, lets consumers replace the entire catalog wholesale. Useful for deployment-specific glossaries.
- **Translation-ready.** Every default label is wrapped in `__()` so `.po`/`.mo` files ship non-English variants through the standard WordPress flow. Defaults are English; translators add locales as needed.
- **Pass-through fallback.** Unknown codes return unchanged — no generic "unknown error" placeholder, no exception. Raw codes are more informative for developers reading logs than vague placeholders.

### Migration

- **No user action required.** The class is purely additive; nothing in CB Base reads from it yet. Hub and other consumers opt in by calling `\CB\Core\Beacon\Errors::label( $code )` where they currently render raw error codes.

### Roadmap parking lot updated

- ~~`error_code` lokalisatie — mapping NL/EN voor Beacon foutcodes~~ — **shipped in 1.0.30-dev.** Hub-side adoption queued for whenever Hub renders error codes in its log/notification UI.
- **Hub-emitted error codes** (`BEACON_UNREACHABLE`, `BACKUP_TIMEOUT`, `BACKUP_FAILED`) — Hub adds these to its own catalog or extends CB Base's via the `cb_core_beacon_error_label` filter when Hub touches its log-rendering code.

## [1.0.29-dev] — 2026-04-25

Three issues found in 1.0.28 testing — two visual regressions plus one copy-bug in the Beacon tab — fixed alongside the introduction of a small but strategically-placed new framework primitive: the CB UI status indicator. Today it ships in CB Base only, replacing the bespoke pill on the Beacon tab. Future Hub adoption is queued as its own ticket so the primitive can prove itself in production before being rolled across the suite.

### Fixed — Tile dot colour + ambient gradient regression

- **Removed `.cb-core-status-tile { color: inherit; }`** rule that 1.0.28 introduced near the bottom of admin.css. The rule was meant to neutralise the link-colour cascade from the old `<a>` tile-root, but in the new `<div>` markup it overrode the state-class colours (`--active` → `--cb-success`, etc.). Result: the dot's `background: currentColor` and the `::before` ambient gradient both fell back to body-text grey instead of state colour. Net effect — green/amber/red dots all rendered grey, the subtle gradient glow disappeared.
- **State-class colour cascade restored.** State-class rules (`.cb-core-status-tile--active { color: var(--cb-success); }` etc.) higher up in the file now win the cascade as originally designed. Inner text spans (`title`, `subtitle`, `meta`) keep their explicit colour overrides because those need to stay readable independent of the state-coloured currentColor.

### Fixed — Toggle-button copy bug in Ready state

- **Beacon tab toggle row gated on `$is_active` instead of `$is_enabled`.** In the Ready state — the operator has not yet generated a secret key — `is_enabled` is true (operator hasn't disabled Beacon) but `is_active` is false (without a key Beacon doesn't actually run). The 1.0.28 button-logic chose copy on `is_enabled`, which produced a misleading "Disable Beacon" button on a Beacon that wasn't running yet.
- **Toggle row hidden entirely in Ready state.** There's nothing meaningful to disable when no key exists; the row is omitted to keep the page focused on the actual next step (generate a key via Hub Pairing). Operators wanting to disable Beacon without ever generating a key reach the same outcome by ignoring the page; the option to disable becomes available the moment a key is generated and Beacon transitions to Active.

### Added — `\CB\Core\UI\Status` framework primitive

The first inhabitant of a new `src/UI/` namespace, intended to grow into a small set of design-system primitives that all CB plugins can lean on. Status is the simplest possible primitive — a colour-coded dot plus a label, rendered inline. Five variants mapped to existing CB tokens:

| Variant | Token | Use case |
|---------|-------|----------|
| `active` | `--cb-success` | Working, no attention needed |
| `ready` | `--cb-warning` | Waiting for operator action |
| `warning` | `--cb-warning` | Attention soon, not urgent |
| `error` | `--cb-danger` | Something is wrong |
| `idle` | `--cb-text-muted` | Deactivated or not relevant |

API:

```php
echo \CB\Core\UI\Status::render( 'active', __( 'Active', 'core-blueprint' ) );
```

The class is final, the method is static, and the variant is allowlist-validated (anything outside the five known values falls back to `idle`). The label is `esc_html`-escaped before output. Zero dependencies; no allocation beyond a single `sprintf`. Safe to call dozens of times per page render.

CSS lives under a clearly-marked `/* ─── CB UI: Status indicator ─── */` block in admin.css, using `--cb-*` tokens throughout so cb-dark and cb-light render correctly without separate theme rules. The dot is `.cb-core-status-mark` (renamed from the working-name `cb-core-status-indicator-dot` for brevity), distinct from `.cb-core-status-dot` inside dashboard tiles to avoid CSS-selector confusion despite no actual collision.

**Visual rule of thumb captured in the class docblock:** a status indicator is a *statement*, not a call-to-action. If the operator needs to do something, render an action link or button next to or beneath the indicator — never style the indicator itself like a button. This rule is what motivated replacing the 1.0.28 pill (which read as a button on the Beacon tab) with the new dot+label primitive.

### Changed — Beacon tab uses the new Status primitive

- **`templates/beacon.php`** now calls `\CB\Core\UI\Status::render( $variant, $label )` instead of rendering the bespoke `.cb-core-beacon-state-pill`. Three variants in active use: `active`, `ready`, `idle` (for deactivated). `warning` and `error` are reserved for future use.
- **All `.cb-core-beacon-state-pill*` CSS rules removed.** The bespoke pill styling is replaced entirely by the framework primitive — no orphan CSS left over.

### Added — Meta-bar on Hub Pairing page

Operator UX gap closed: until now, the path from Hub Pairing back to the Beacon enable/disable toggle existed only via the dashboard tile. Operators on the Ready state never saw a Manage link on their dashboard tile (its CTA pointed to Hub Pairing instead), and operators landing on Hub Pairing in any state had no way back to Safeguards without bouncing through other pages.

- **New `.cb-core-meta-bar` component** added to admin.css — a small horizontal strip that sits below `<h1>` and surfaces page-level state plus a navigation handle. Generic enough for reuse on future Hub or module pages where status-at-a-glance plus secondary navigation belong above main content.
- **Hub Pairing page (`SettingsPage::render()`) now opens with this meta-bar** in every state: `● Beacon active` / `● Beacon ready to activate` / `● Beacon deactivated`, with a "Manage on Safeguards →" link on the right. Status indicator uses the new `\CB\Core\UI\Status` primitive — same colour tokens, same dot semantic as everywhere else in the suite.
- **Deactivated-state notice simplified.** The 1.0.27 "Manage Beacon state on Safeguards" button is removed from the inline notice because the meta-bar now provides that navigation. The notice keeps its informational copy about the stored secret key being preserved and the option to clear it.

### Added — Clear secret key on Safeguards → Beacon

Operator UX gap closed for sites that never had — and never will have — a Hub. Until now, clearing the encrypted secret key required navigating to Hub Pairing, but that menu item is hidden when Beacon is disabled. Operators landed on a "go to Hub Pairing" instruction with no menu item to follow. The cleanest fix is making the Clear-key form available where the operator already is: on Safeguards → Beacon.

- **Clear-key form now also rendered in the "Stored secret key" row** of `templates/beacon.php`. Visible in any state where `has_key === true` (active and deactivated), hidden in ready-state where there's no key to clear. Same nonce (`cb_core_beacon_clear_key`), same handler — single code path, two UI surfaces.
- **`SettingsPage::handle_clear_key()` extended** with optional `redirect_to` POST field. Default destination remains Hub Pairing (backwards-compatible with the existing form there); the new Safeguards-side form supplies the Beacon-tab URL so operators land back on the page they were on. `wp_safe_redirect()` validates the target as a same-site admin URL.
- **`templates/beacon.php` notice handler** picks up the `key_cleared` notice query param and shows the success message inline. Same copy as the Hub Pairing version of the notice — single source of truth for the message text.
- **Inline guidance for the deactivated state.** When Beacon is disabled, the "Open Hub Pairing →" link can't be followed (menu hidden) but the destination still matters for operators who *will* re-enable later. Render the placeholder as inert greyed-out text via the new `.cb-core-text-disabled` helper class plus a goal-oriented helper line — "To regenerate or copy the secret key, enable Beacon first." — that tells operators what they need to *achieve*, not where some menu item will reappear.

### Added — `.cb-core-text-disabled` CSS helper

Small but reused-already utility class for inert text that visually mirrors a link without being clickable. Used on the deactivated-state Beacon tab for the "Open Hub Pairing →" placeholder. Lives next to the Status primitive in admin.css since it's part of the same UI-primitives layer. Uses `--cb-text-muted` so cb-dark and cb-light render correctly without separate theme rules.

### Changed — Safeguards tab order

- **Beacon tab moved to position 5**, between Login Shield and Failsafe. Original 1.0.27 placement (between Access Mode and Core Shield) framed Beacon as a peer of core security, but it isn't — Access Mode and Core Shield are baseline security every site needs, while Beacon is an optional feature operators can disable entirely. New ordering groups core security first, optional features in the middle, emergency exit last. Failsafe stays at the end where operators expect to find it under stress.

### Changed — `\CB\Core\UI\Status` primitive un-scoped from `.cb-core-wrap`

- **CSS selectors changed** from `.cb-core-wrap .cb-core-status` to plain `.cb-core-status`. Reason: the primitive is meant to be parent-agnostic so it works in any container — Hub Pairing's wrap class is `cb-core-beacon-wrap`, not `cb-core-wrap`, and Hub-side pages will use yet other wrap classes. Scoping a framework primitive under a specific parent class limits reuse without adding any value.

### Migration

- **No user action required.** Existing 1.0.28 installs upgrade transparently — the visual regression is fixed automatically, the Beacon tab renders with the new primitive on next pageload, the Hub Pairing page gets the meta-bar.
- **Third-party modules contributing tiles** continue to work unchanged; the primitive is purely additive.

### Roadmap parking lot

- **Hub adoption of Status primitive** (Hub 1.0.63-dev) — start with site-overview status indicators as the pilot since Hub renders dozens per page. Other Hub indicators migrate in Hub 1.0.64-dev once the pilot validates the primitive against Hub's context.
- **Future CB UI primitives** — `\CB\Core\UI\` is reserved as the namespace; candidates for next primitives include button-group, action-bar, and confirmation-dialog patterns. Not built until concrete Hub or module needs surface them via the rule of three.

## [1.0.28-dev] — 2026-04-25

Bugfix-and-polish release for the 1.0.27 Beacon-toggle feature, plus a generic tile-API expansion that other modules can build on.

The headline issue: in 1.0.27 the Disable Beacon button **fired the handler** (notice rendered, `.htaccess` rule removed, Hub backups failed with auth errors) but the page kept reading `is_enabled() === true` afterwards — the Hub Pairing menu stayed visible and the Beacon tab kept showing "Active". Root cause was a textbook WordPress edge case: `update_option()` early-bails when the new value is `===` to the cached old value, and a non-existing option returns `false` from `get_option()`. Writing boolean-`false` on an option that didn't exist yet therefore silently no-op'd. Fixed by storing the flag as a stringy `'0'`/`'1'` (which never matches the missing-option `false` sentinel) and seeding the row with `add_option()` on activation so it exists from day one with autoload on.

The tile-API expansion is a follow-on from operator UX feedback on the Beacon dashboard tile: the tile was a single big click target leading only to the Connection Log, with no quick way to reach the Manage page. The fix is a generic `actions` array contract on the `cb_core_status_tiles` filter — any module's tile can now declare up to three secondary footer links alongside its primary CTA. Beacon uses it to add a "Manage" link beside "Details"; future modules can build on the same shape.

### Fixed — Beacon enable-flag silently dropped on first-write

- **`Pairing::is_enabled()`** now reads the option as a string and tests `!== '0'`. The default `'1'` is returned when the option doesn't exist, preserving the "default-on" behaviour.
- **`Pairing::set_enabled()`** writes `'1'` or `'0'` and changes the autoload flag from `false` to `'yes'` — autoload makes sense here because the option is read on every admin page load via the Bootstrap gate and `register_menu()` check, and the small payload doesn't hurt the autoload bundle.
- **`Lifecycle::activate()`** now calls `add_option( CB_CORE_BEACON_ENABLED_OPT, '1', '', 'yes' )` to seed the row with the correct autoload flag from the very first activation. `add_option()` is idempotent (no-ops if the row exists), so the upgrade path stays safe — existing 1.0.27 installs that already wrote `'0'` keep their value.

### Added — Generic `actions` array on the tile-filter contract

- **`cb_core_status_tiles` filter contract extended.** Each tile may now include an `actions` array of `{ url, label }` items. Renderer caps at 3 with a `_doing_it_wrong()` notice on overflow. External URLs (host doesn't match `home_url()`) automatically open in a new tab with `rel="noopener noreferrer"`. Documentation lives in the filter's docblock at the apply_filters callsite in `templates/dashboard.php`.
- **No styling-variants, icons, JS-callbacks, or per-action capability checks** in the contract. Actions are tappable text links to URLs, period — sensitive mutations belong on dedicated pages with their own nonce + capability checks. Keeps the contract tight enough that customer modules can adopt it without ambiguity.

### Changed — Tile rendering moved to Pattern 3 (split body + footer)

- **Tile root is now always a `<div>`.** The previous markup wrapped the whole tile in an `<a>` when `cta_url` was set, which made nested-anchor markup impossible for footer secondary links.
- **Body click target is the `.cb-core-status-tile-bodylink` anchor** — wraps title, subtitle, and note. Footer is a sibling, never inside the body link. HTML-valid, accessibility-clean.
- **Footer strip** now renders with a top divider (`border-top: 1px solid var(--cb-border)`) and contains the meta on the left, primary CTA + actions on the right. Each action is its own anchor with its own click target.
- **Hover/focus lift** moved from `a.cb-core-status-tile:hover` to `.cb-core-status-tile:has(.cb-core-status-tile-bodylink:hover)`, so the tile lifts only when the body click target is hovered, not when a footer action is hovered.

### Changed — Beacon dashboard tile uses the new actions API

- **Active / Faltering / No-connection / Waiting states** now declare `actions: [{ url: <Safeguards → Beacon URL>, label: 'Manage' }]` alongside their existing `cta: Details`. Operators can now reach the Beacon settings tab directly from the dashboard without wandering through the sidebar.
- **Ready-to-activate state** unchanged — its primary CTA already points at Hub Pairing (the meaningful next step).
- **Deactivated state** unchanged — its primary CTA already points at Safeguards → Beacon for re-enable.

### Added — Beacon-tab styling tokens

- **State-pill styling** for the active/ready/deactivated indicator on Safeguards → Beacon. Uses `--cb-success`/`--cb-warning`/`--cb-surface-3` so cb-dark and cb-light both render correctly without a separate theme block.
- **Form-table label contrast bump** inside `.cb-core-beacon-tab` — the default WP form-table `th` text was hard to read on cb-dark (near-disabled grey). Tab-scoped override to `--cb-text-strong` with weight 500.
- **Description text contrast bump** in the same scope — security-relevant copy gets `--cb-text` instead of `--cb-text-muted` so the operator actually reads it.
- **Notice tweak** for the warning notice on the Hub Pairing page when accessed while Beacon is disabled — `notice-warning p` now uses `--cb-text` for body copy in the cb themes.

### Migration

- **No user action required.** Existing 1.0.27 installs benefit from the bugfix on first admin pageload after upgrade — `Pairing::is_enabled()` re-reads the option and returns the correct value regardless of the storage shape (`'0'`, `'1'`, `false`, `true`, integer 0/1 — all map correctly).
- **Tiles in third-party modules** continue to render exactly as before; the `actions` array is purely additive and defaults to empty if not supplied.

## [1.0.27-dev] — 2026-04-25

Beacon gains an explicit operator on/off switch. Until this release, sites that did not pair with a Hub still had Beacon's code resident, the Hub Pairing menu visible, and the Authorization-passthrough rule sitting in `.htaccess` — three small but real attack-surface contributions to a feature the operator was not using. The feature was already dormant in terms of REST routes and crons (the paired-only branch in `Bootstrap` already gated on key presence), but "dormant" is not the same as "off". This release adds the third axis — a deliberate enable flag — and treats the result as a first-class state across the admin UI: dashboard tile, Safeguards tab, Hub Pairing page, and a synchronous `.htaccess` toggle so the change takes effect immediately.

The feature also gives operators a Clear secret key path, so the key can be removed from storage without uninstalling the plugin — useful when a site is being transferred, retired, or the operator wants a clean slate without re-Hub-pairing.

### Added — `cb_core_beacon_enabled` operator switch

- **New option** `cb_core_beacon_enabled`, default `true`. Existing installs and fresh activations behave exactly as before — no breaking change. Disabling the option:
  - Stops `Bootstrap::boot_paired_hooks()` from registering REST routes, the connection-log retention pruner, the REST recorder, and the update-worker AJAX endpoints.
  - Removes the `.htaccess` Authorization passthrough rule synchronously (via the toggle handler — change applies before the next request, no plugin deactivate/reactivate cycle needed).
  - Hides the Hub Pairing submenu from the Core Blueprint parent.
  - Switches the dashboard tile to a "Deactivated" state with a re-enable CTA.
- **New `Pairing` facade methods.** `Pairing::is_enabled()` reports the operator switch; `Pairing::has_key()` reports the secret-key axis; `Pairing::is_active()` is now true only when both are. The Bootstrap gate continues to use `is_active()` — semantics tightened, callsite unchanged.
- **`Lifecycle::activate()`** respects the enabled flag. Reactivating a plugin where the operator previously disabled Beacon no longer re-inserts the `.htaccess` rule.

### Added — Safeguards → Beacon tab

- **New tab in `Admin/Pages/Safeguards.php`**, slotted between Access Mode and Core Shield in the authority hierarchy (both are "external reachability" concerns at different layers).
- **New template** `templates/beacon.php` renders the three observable states (Active / Ready / Deactivated) with state-appropriate copy, the toggle form, and a contextual link to Hub Pairing where relevant.
- **New `Beacon\Admin\Toggle` handler** binds to the `admin_post_cb_core_beacon_toggle` hook. Validates nonce + capability, persists via `Pairing::set_enabled()`, calls `Lifecycle::insert_htaccess()` or `remove_htaccess()` synchronously, redirects with a `cb_core_beacon_notice` query parameter that drives the success notice on the Safeguards tab. Handler is registered always-on (independent of pairing state) so the re-enable path works even when Beacon is disabled.

### Added — Clear secret key

- **New POST handler** `SettingsPage::handle_clear_key()` on the Hub Pairing page. Independent action from regenerate — separate nonce (`cb_core_beacon_clear_key`), separate POST flag, separate success notice. Permanently deletes the `cb_core_beacon_secret_key` option.
- **Clear secret key button** added to the Hub Pairing UI next to Generate new key, only visible when a key is currently stored. Carries `data-cb-core-confirm="clear-key"` so the new confirm-dialog wrapper enforces operator intent before submit.

### Added — Dashboard tile "Deactivated" state

- **`StatusTile::contribute()`** picks up `Pairing::is_enabled()` and adds the deactivated branch as the very first check — operator-disabled trumps every key/poll-based state. Tile shows "Beacon deactivated" with a re-enable CTA pointing at the new Safeguards tab.

### Added — `assets/js/15-beacon-confirm.js`

- **New ES module** that intercepts any form carrying a `data-cb-core-confirm` attribute and shows a `window.confirm()` dialog with a contextual message before letting the POST through. Two known keys today (`beacon-disable` and `clear-key`); extending to future sensitive admin-post forms only requires adding an entry to the `CONFIRM_MESSAGES` map. Falls back to English literals if the localized i18n string is missing — same pattern as the rest of the suite.
- **Two new i18n keys** `confirmBeaconDisable` and `confirmBeaconClearKey` added to the Admin enqueue's localized data block.

### Changed — Hub Pairing page rendering

- **Direct-URL safety net.** The Hub Pairing menu disappears when Beacon is disabled, but bookmarks and direct typing still resolve the page. When loaded in that state, the page now shows a clear "Beacon is currently deactivated" warning with a link to Safeguards → Beacon for the toggle. The form fields remain functional — the operator can still clear or regenerate the key while Beacon is off.

### Migration

- **No user action required.** The new option defaults to `true` so existing behaviour is preserved on upgrade.
- **Operators wanting to turn Beacon off** open Safeguards → Beacon → Disable Beacon, confirm the dialog, and the change takes effect immediately. No Hub-side action needed (Hub will detect the site as offline via its existing offline-detection logic).
- **Operators wanting to clear the key** open Hub Pairing → Clear secret key, confirm the dialog. The encrypted blob is deleted from `wp_options`. Beacon falls back to "Ready to activate" or "Deactivated" depending on whether the operator switch is on.
- **No schema changes.** No table migrations.

## [1.0.26-dev] — 2026-04-25

Production-readiness pass on the Beacon side, paired with Hub 1.0.62-dev. Three concurrent threads: dropping every `@deprecated` shim now that there is no third-party Beacon code to protect, turning the previously-documented-but-never-fired `cb_core_beacon_register_backup_routes` extension hook into an actual extension point, and minor surface tidying. Together with the Hub release this puts the Base ↔ Hub contract on a footing where every documented integration point matches what the code actually does.

### Removed — `src/Beacon/Bootstrap.php`

- **`Bootstrap::is_paired()` shim deleted.** The method had been a one-line delegate to `Pairing::is_active()` since 1.0.8, kept solely as a back-compat hatch for hypothetical third-party Beacon-side code. There is no such code in the suite, the rule of three is not met, and shims-as-policy ends here. Internal callers were already using `Pairing::is_active()`. The class docblock note on `Pairing` referencing the historical Bootstrap method is also updated.
- **Legacy cron-binding dropped.** The pre-1.0.8 `cb_core_beacon_connection_log_prune` cron hook binding in `boot_paired_hooks()` is gone. Sites that activated pre-1.0.8 had this event scheduled separately; the unified Retention cron has handled connection-log pruning for many releases. Lifecycle::deactivate() unschedules any leftover event on next deactivate cycle, which is the intended migration path.

### Removed — `src/Beacon/Log/ConnectionLog.php`

- **`ConnectionLog::schedule_cron()` no-op deleted.** The method had been intentionally empty since 1.0.8 — pruning is registered via `register_prune_with_retention()`. Calling it was a no-op; the method existed only as a back-compat surface for old installer tooling that may have invoked it. Same rule-of-three reasoning: no current caller, no shim.

### Added — `src/Beacon/Backup/Routes.php`

- **`cb_core_beacon_register_backup_routes` action hook now actually fires.** Two docblocks (in `Backup\Routes` and `Backup\Manager`) had been advertising this hook as an extension point for third-party backup providers since the backup system was introduced, but no `do_action()` call ever existed. Customer modules that followed the documentation got silent no-ops. The action now fires inside `register_providers()` after the first-party Database and AI1WM providers register, so customer modules using default priority can register their own providers with `Manager::register( new MyProvider() )` from the hook callback. First-party providers get registered first; customer modules can shadow by deliberately re-registering with the same slug after the hook fires.
- **Documented usage pattern** included in the `do_action` docblock so future module authors have a copy-paste starting point.

### Removed — `src/Beacon/Backup/Providers/Database.php` + `Ai1wm.php`

- **`cb_backup_download=1` query parameter dropped from minted download URLs.** Both providers were appending this flag to the public download URL, but `Routes::handle_serve_download()` only ever read `token`. The flag was vestigial — possibly a leftover from an earlier server-rule scheme. Removed for cleaner public URLs and to eliminate semantic noise.

### Changed — `src/Beacon/Log/RestRecorder.php`

- **Stale Hub version markers in inline comments swept.** Comments referenced "Hub v3.0.8" and "Hub v3.0.14+" which never matched any real version of Core Blueprint Hub (currently 1.0.61). The markers are dropped — better silence than a wrong version number — and the comments now describe what the headers do without claiming when they were introduced. Code logic unchanged.

### Migration

- **No user action required.** No option keys, table schemas, hook names (other than the new one above), AJAX action names, nonce keys, or admin menu slugs change.
- **Customer modules** that were waiting on `cb_core_beacon_register_backup_routes` to fire can now register providers from a hook callback. Modules that hardcoded `Manager::register()` directly (the previous-only working approach) continue to work — calling `register()` outside the hook is still legal, just not the recommended pattern.

## [1.0.25-dev] — 2026-04-25

PHP-side cleanup pass following the four-phase JS modernisation arc (1.0.21–1.0.24). During the QC of that pass, three concrete PHP orphans surfaced that fell outside the JS-migration scope. This release picks them up in a single patch with no behavioural change.

### Removed — `src/Ajax/Handlers/Settings.php`

- **`cb_core_set_site_mode` AJAX handler dropped.** The action registration and the `set_site_mode()` method are gone. The legacy `.cb-core-mode-option` selector that drove this handler was removed in Phase 3 (1.0.23) as dead code, and the Core Blueprint Theme's "Site Mode" submenu is actively suppressed via `remove_submenu_page` in `Admin::register_menu()`. With no UI surface and no JS module wiring it up, the handler was unreachable.

### Removed — `src/Settings.php`

- **`Settings::set_site_mode()` model setter dropped.** Once the AJAX wrapper went, the setter on the model layer had zero call-sites — not from CLI, not from migrations, not from templates. Trivial to reintroduce (five lines) if the UI ever returns. Keeping orphan write-paths violates the rule of three and adds drift risk.
- **Read-side preserved.** `SITE_MODES` constant, `Settings::site_mode()` getter, and the `'site_mode'` key in default seed remain untouched — `templates/core-shield.php` and `src/Admin/Pages/Safeguards.php` still read the value to drive the Plain/Technical default for the Shield page.

### Removed — `src/Admin/Admin.php`

- **Four orphan i18n keys dropped from the localized data block:** `featureDelegated`, `headerTestTitle`, `runHeaderTest`, and `tokenHideWarning`. None had a JS consumer after the Phase 3/4 module migrations — the admin.js IIFEs that referenced them were either restructured to inline labels (header test panel) or dropped entirely (token-display rendering moved server-side).

### Added — `src/Admin/Admin.php`

- **Two i18n keys added for the privacy module:** `confirmPreset` and `saving`. Both are consumed by `assets/js/13-privacy.js` and were previously falling back to hardcoded English literals — out of step with the rest of the privacy and login-shield i18n which all route through the localized array. Translatability matters here specifically because the privacy panel is one of the surfaces a non-IT user is most likely to land on, and a confirm dialog mid-flow in untranslated English breaks the Plain-language posture that the rest of the page commits to.

### Migration

- **No user action required.** No option keys, table schemas, hook names, AJAX action names, nonce keys, settings shape, or admin menu slugs change. The only observable difference is that two previously English-only strings in the privacy panel now follow the user's WP locale.
- **No JS changes.** The four removed PHP-side i18n keys had no JS readers; removing them reduces the inline `window.cbCore.i18n` payload by four entries and nothing else. The two added keys were already being read (with English fallbacks); now they're populated.

## [1.0.24-dev] — 2026-04-25

Phase 4 — finale of the JS modernisation pass. CB Base is now fully on native ES modules. The two remaining IIFEs (Privacy & Logging form, Logs Plain/Technical pill) migrate, `assets/admin.js` is deleted, and the `'jquery'` dependency leaves the enqueue stack. From this release forward, CB Base ships zero jQuery in its admin assets — a four-phase, four-version arc that started at 1.0.21-dev with a single proof-of-concept module and ends here with the entire suite-foundation plugin running on native browser APIs.

The mechanical effort matters less than the architectural shift: every CB plugin from this point forward — Hub (already migrated), Access Control, Protected Content, Invoice, future modules — can `import { qs, apiPost } from './01-dom.js'` from inside its own module tree, or read the public API off `window.cbCore` from any script context. No bundler, no build step, no jQuery anywhere.

### Added — `assets/js/13-privacy.js`

- **Privacy & Logging form migrated off jQuery.** Two actions: save settings (collects `ip_mode` + `verbosity[*]` + `retention[*]`, posts the lot to `cb_core_save_privacy`, reloads on success) and apply preset (destructive — guarded by `window.confirm`, reloads on success). The verbosity and retention groups are dynamic (rendered server-side from registered event categories) so the module collects them by matching the `name` attribute against `verbosity[<key>]` / `retention[<key>]` rather than maintaining a hardcoded key list. Adding a new event category requires zero JS changes.
- **Wire-format compatibility preserved.** The legacy IIFE built nested JS objects which jQuery flattened into `verbosity[audit]=detailed` form on the wire; the migrated module sends the same flat keys directly via `URLSearchParams.append('verbosity[audit]', value)`. PHP parses both into the same `$_POST['verbosity']['audit']` structure that `src/Ajax/Handlers/Privacy.php` reads. No server-side change needed.

### Added — `assets/js/14-logs-toggle.js`

- **Logs Plain/Technical pill migrated off jQuery.** Click handler delegates from `.cb-core-logs-page` to `.cb-core-mode-toggle__btn`, fires `cb_core_set_description_mode` with scope `user`, reloads on success. Both buttons in the toggle group are disabled while in flight to prevent double-clicks from racing reloads. `window.alert` is preserved as the failure surface — the original code chose it deliberately (toggle is a secondary control, modal disproportionate, alert is WP-native admin styling); migration keeps that judgement.

### Removed — `assets/admin.js`

- **The classic admin.js is gone.** Both remaining IIFEs migrated to modules, then the file was deleted. After three releases of incremental migration, `assets/admin.js` no longer exists in the plugin tree.
- **`'jquery'` dependency removed** from the enqueue stack. CB Base loads no jQuery, declares no jQuery dependency, and runs no jQuery code in its admin pages. WordPress core may still load jQuery for other reasons (other plugins, the Customizer, Heartbeat) — that is independent of CB Base.

### Changed — `src/Admin/Admin.php`

- **Classic enqueue replaced with inline-data + module-only loading.** `wp_enqueue_script( 'core-blueprint-admin', ..., [ 'jquery' ], ... )` is gone. In its place: a no-src `core-blueprint-data` handle whose only job is to carry `window.cbCore = {...}` as an inline `<script>` printed before the module graph evaluates. This is necessary because [script modules can't be localized via `wp_localize_script`](https://make.wordpress.org/core/2024/03/04/script-modules-in-6-5/) — the WordPress 6.5 release note documents this explicitly. A classic `<script>` (no `type="module"`) parses synchronously at its insertion point, so by the time the async module graph runs, the global is set. This is the same pattern WP core uses for legacy data alongside script modules.
- **Module list at 14 entries.** All page modules (`05-failsafe` through `14-logs-toggle`) plus the foundation (`01-dom`, `02-public-api`) and the special-case `08-log-exports` (which has no deps because it builds URLs and navigates rather than importing helpers).

### Public API surface — final state at v1.0.24

What downstream Core Blueprint plugins (Access Control, Protected Content, Invoice, future modules) can rely on as documented contract on `window.cbCore`:

- `apiVersion` — currently `'1.0'`. Bumps on breaking changes.
- `qs(selector, root?)` — querySelector wrapper.
- `qsa(selector, root?)` — querySelectorAll → Array.
- `apiPost(action, nonce, data?)` — fetch wrapper, returns Promise resolving to JSON envelope.
- `copyToClipboard(text, feedbackEl?)` — clipboard with optional inline-feedback flash.
- `confirmPassword({ title, prompt, confirmLabel? })` — password re-confirm modal, returns Promise.
- `setTheme(value, scope?)` — wraps `cb_core_set_theme`.
- `setLocale(value, scope?)` — wraps `cb_core_set_locale`.

Plus the localized data fields that have always been there: `ajaxUrl`, `nonceTheme`, `nonceLocale`, `nonceAdmin`, `currentTheme`, `currentLocale`, `descMode`, `i18n`. These remain populated the same way (`$localized` array → JSON-encoded inline script).

### Migration

- **No user action required.** All AJAX endpoints, nonces, settings keys, option storage, and localStorage keys (`cbCoreExpandedModules`) unchanged. Server-side handlers are untouched in this release.
- **Behavioural parity** across every page in the plugin: Failsafe, Core Shield, Site Mode, Login Shield, Appearance, Language, Notifications, Logs (with Plain/Technical toggle), Privacy & Logging — each behaves exactly as it did under jQuery. The migration is observable only as cleaner network traffic in dev-tools (native `fetch` calls instead of `$.post`-style XHR) and a smaller asset footprint (no admin.js, no jQuery dep declared).
- **Downstream plugins** can start consuming the public API surface immediately via `window.cbCore`. Feature-detect with `typeof window.cbCore.apiPost === 'function'` if you need to be defensive against older versions of Base.

### Footprint comparison — start of pass to here

- **v1.0.20** (pre-Phase-1): one 1964-line `assets/admin.js`, 10 IIFEs, ~389 jQuery calls, `'jquery'` enqueue dep.
- **v1.0.24** (now): zero `admin.js`, 14 ES modules totalling ~2050 lines (including documentation banners and migration markers), zero jQuery calls, zero jQuery enqueue dep. Roughly the same total LOC, but spread across focused per-page modules with explicit imports, async/await, and a documented public API.

## [1.0.23-dev] — 2026-04-25

Phase 3 of the JS modernisation pass: the largest single chunk of legacy jQuery code in the suite. The "Security big-block" — 885 lines of mixed-concern handlers covering Failsafe, Core Shield, log exports, notifications, and a per-feature description toggle — is split into five focused page modules. Site Mode and Login Shield, two well-scoped IIFEs that lived alongside the big-block, also migrate in this release. After this drop, `assets/admin.js` is down from 5 IIFEs to 2 (Privacy & Logging, Logs Plain/Technical toggle), and shrinks from ~1418 lines to 246 — most of which is now header docblock and migration markers.

Significant dead-code removal during the audit: four sets of handlers in the big-block targeted DOM that no current template renders, having been silently obsolete for at least one prior release. Carrying them into the modular layer would have meant maintaining handlers for a UI that no longer exists. Dropped rather than ported. See "Removed" below.

### Added — `assets/js/05-failsafe.js`

- **Failsafe page actions migrated off jQuery.** Rotate bypass token (with WP password re-auth via `confirmPassword`), panic activate (with re-auth + optional reason prompt), panic deactivate (no re-auth — re-enabling enforcement is a safe operation), close active bypass window, and click-to-copy on `.cb-core-token-display`. Same AJAX actions, same nonce, same UX. The legacy dual-class clipboard listener (`.cb-core-inline-copy, .cb-core-token-display`) is reduced to just the live half — see "Removed".

### Added — `assets/js/06-core-shield.js`

- **Core Shield page migrated off jQuery.** Apply recommended defaults, master shield toggle (with optimistic UI + server-driven reload), per-module master toggle with feature-dot resync, per-feature toggle, module body collapse/expand chevron with localStorage-persisted state (key: `cbCoreExpandedModules`), atomic "all modules" master toggle that uses indeterminate state for partial selection, and the security header test (with the renderer that builds the result table inline). The escapeHtml helper used by the renderer is module-private — every other module that needs HTML safety should reach for native APIs (`textContent`, attribute setters) rather than building strings.
- **Race-safety preserved on bulk operations.** The "all modules" handler explicitly mutates checkbox `.checked` directly during bulk-flip rather than dispatching `change` events, so the per-module handler is intentionally bypassed during bulk operations. This matches the legacy behaviour and keeps a single AJAX call rather than N parallel ones racing on the shared option.

### Added — `assets/js/07-description-toggle.js`

- **Per-feature plain/technical "peek" toggle migrated off jQuery.** Click the `.cb-core-desc-toggle` button on any `.cb-core-dual` block to flip between plain and technical descriptions. In Sync mode the entire page flips together (not persisted server-side — refreshing returns to the seed); in Plain or Technical mode only the clicked block flips (peek-only, not propagated). The behaviour exactly mirrors the legacy implementation; what's gone is the dashboard-level radio-card UI that *set* the description-mode (see "Removed").

### Added — `assets/js/08-log-exports.js`

- **Audit / System / Maintenance log exports unified into one dispatcher.** Single click handler matches the button against a config map keyed by CSS class, builds a GET URL with the right action + nonce + format + forwarded filter params, and navigates the browser to it (the server emits Content-Disposition for the actual file download). Adding a new format like PDF requires zero JS changes — the format dropdown carries the value, the server handles the rest. This is why the module doesn't use `apiPost` or any other JSON helper: there's no JSON envelope to inspect on a download request.

### Added — `assets/js/09-notifications.js`

- **Email alert severity toggles migrated off jQuery.** Per-severity checkboxes (CRITICAL / WARNING / NOTICE / INFO) on Preferences > Notifications, wired to `cb_core_toggle_alert`. Optimistic UI: the checkbox flips immediately, reverting only if the server says no. Sibling module to `10-alert-recipients.js` on the same page; kept separate because they target distinct elements with distinct lifecycles.

### Added — `assets/js/11-site-mode.js`

- **Public ↔ Admin-Only toggle migrated off jQuery.** Direct port of the legacy IIFE 2 — main toggle button + two option cards (Public / Admin-Only) both flip the mode via `cb_core_set_access_mode`. Optimistic UI with revert on server failure, status bar i18n, warning panel reveal when Admin-Only is active. Same DOM contract as before.

### Added — `assets/js/12-login-shield.js`

- **Login Shield form migrated off jQuery.** Direct port of the legacy IIFE 5 — live slug sanitisation mirroring the server-side `sanitize_slug()` (lowercase, `[a-z0-9-]` only, collapse hyphens, trim), URL preview as the user types with normalisation on blur, conditional reveal of the "Custom URL" field when redirect-after-login is set to "custom", Strict-mode confirmation prompt with revert-on-cancel, AJAX save (`cb_core_login_shield_save`), and server-side URL test (`cb_core_login_shield_test`). The slug-sanitiser stays in sync with PHP — keeping these two parallel implementations matches is essential because the live preview otherwise misleads operators about what will actually save.

### Changed — `assets/admin.js`

- **Big-block removed.** All ~885 lines of the Security IIFE migrated to the five new modules. The in-file Modal, cbSecPost, and copyToClipboard helpers go away with it — modules import the equivalents from `01-dom.js`.
- **Site Mode IIFE removed.** Migrated to `11-site-mode.js`.
- **Login Shield IIFE removed.** Migrated to `12-login-shield.js`.
- **File header docblock rewritten** to track per-version which IIFEs migrated where and to flag the two remaining IIFEs (Privacy & Logging, Logs Plain/Technical toggle) as Phase 4 work.
- **Down from 5 IIFEs to 2.** File size shrinks from ~1418 lines to 246, most of which is now header docblock and migration markers.

### Changed — `src/Admin/Admin.php`

- **`$cb_core_js_modules` array extended to 12 entries.** Each Phase 3 module gets its own row with explicit `$deps` chain. `08-log-exports` declares no deps because it doesn't import any helpers — it builds URLs and navigates. The other six all depend on `01-dom`. The dependency graph mirrors actual `import` relationships in the source; the browser handles the imports themselves; `$deps` only controls load-order in the rendered HTML.

### Removed — dead handlers in the legacy big-block

Four selector groups in the big-block targeted DOM that no current template renders. Verified by grepping `templates/` and `src/` for each selector: zero matches in either tree. Carrying them into the modular layer would have meant maintaining handlers for a UI that no longer exists. Dropped rather than ported.

- **`.cb-core-mode-option`** — legacy "site mode selection" handler in the big-block (separate from the actual Site Mode toggle in IIFE 2). The handler called `cb_core_set_site_mode`. Selector absent from every template.
- **`.cb-core-inline-copy`** half of the dual-class clipboard listener — only `.cb-core-token-display` is rendered (on the Failsafe page). The new failsafe module listens on the live class only.
- **Dashboard description-mode UI**: the radio-input handlers for `cb_core_desc_mode` and `cb_core_desc_scope`, plus the `.cb-core-desc-mode-option`, `.cb-core-desc-scope-option`, and `.cb-core-desc-reset` click handlers. This whole UI was replaced by the equivalent on Preferences > Language (handled by `04-language.js` since v1.0.22).
- **Helpers `updateModeActiveClass` and `applyDescModeToPage`** — only ever called from the dead handlers above. Removed alongside.

These removals are pure dead-code cleanup. No functional change, no UI regression possible: the DOM these handlers needed already didn't exist before this release.

### Migration

- **No user action required.** Server-side AJAX endpoints, nonces, settings keys, option storage, and localStorage keys all unchanged. Module body expand-state continues to use the `cbCoreExpandedModules` localStorage key — operators won't see their per-module collapse preferences reset.
- **Behavioural parity** on every live page: Failsafe, Core Shield, Site Mode, Login Shield, and the per-feature description toggle should each behave exactly as they did under jQuery, observable only as `fetch` calls in dev-tools where there used to be `$.post`-style XHR.

## [1.0.22-dev] — 2026-04-25

Phase 2 of the JS modernisation pass: the public-API setters (`setTheme`, `setLocale`) and the two preference pages that consume them (Appearance, Language) move off jQuery in a single coherent step. Phase 1 deferred this pairing precisely because the helpers and their callers had to migrate together — the helpers' return type changes from jQuery `$.Deferred` to native `Promise`, which would have broken the `.done()/.fail()` chains on the caller side if split across phases.

Three out of the remaining nine IIFEs in `assets/admin.js` were live; the fourth (an older Language page implementation, IIFE 4 in the file) was dead code and is dropped rather than ported. After this release, `assets/admin.js` is down to 5 IIFEs covering the Security big-block, Site Mode, Privacy & Logging, Logs Plain/Technical toggle, and Login Shield. Phase 3 will tackle the security-related ones; Phase 4 wraps up.

### Added — `assets/js/02-public-api.js`

- **`setTheme( value, scope = 'user' )`** and **`setLocale( value, scope = 'user' )`**, both now backed by the shared `apiPost` helper from `01-dom.js`. They are thin wrappers that pass the right action name and nonce key, returning the parsed JSON envelope on success or rejecting with a server-provided message on HTTP error. Exposed on `window.cbCore` so downstream Core Blueprint plugins can call them as part of the public API surface.
- **`apiVersion` stays at `'1.0'`.** Adding methods to the contract is non-breaking; consumers can feature-detect with `typeof window.cbCore.setTheme === 'function'`. Removals or signature changes will bump the version.

### Added — `assets/js/03-appearance.js`

- **Preferences > Appearance theme picker migrated off jQuery.** Direct port of the legacy IIFE 3: scope toggle (user / site default), theme card click with live preview + persist via `setTheme`, reset link to clear the user preference. Behaviour preserved 1:1 — same DOM markup, same `data-cb-theme` / `data-cb-mode` attribute writes on `<html>` and `<body>`, same auto-mode resolution against `prefers-color-scheme`, same 3.5s status-message timeout. The only observable difference is in browser dev-tools: AJAX calls now appear as `fetch` instead of `XHR`, and the response promise carries native semantics.

### Added — `assets/js/04-language.js`

- **Preferences > Language page migrated off jQuery.** Port of the legacy IIFE 5: scope toggle that re-syncs both the locale dropdown *and* the description-mode radio cards to the chosen scope's saved value, locale change → `setLocale` → page reload (locale takes effect on next PHP render, not in-place), reset link, description-mode click → `cb_core_set_description_mode` AJAX with the admin nonce. The "inherit" radio option is dimmed and click-blocked on site scope, same as before.

### Removed — dead Language IIFE

- **IIFE 4 (the older Language page implementation) was not ported forward.** It gated on `.cb-core-language-row`, `.cb-core-locale-picker`, `.cb-core-desc-mode-row`, and `.cb-core-desc-mode-card` — none of which appear in any current template. The IIFE silently early-returned on every page load. Carrying it into the modular layer would have meant maintaining two parallel implementations of the same page, one of which never runs. Dropped during this migration.

### Changed — `assets/admin.js`

- **IIFE 1 (`setTheme` / `setLocale` jQuery setters) removed.** Replaced by the new module. Anything that still reaches for `window.cbCore.setTheme` gets the native-Promise version from `02-public-api.js`.
- **IIFE 3, 4, 5 removed** as described above. Down from 9 to 5 jQuery IIFEs.
- **File header docblock rewritten** to reflect the current legacy-only role of `admin.js` and to track per-version which IIFEs migrated where.

### Changed — `src/Admin/Admin.php`

- **`$cb_core_js_modules` array extended** to 5 entries with explicit `$deps` chains: `02-public-api` depends on `01-dom`, and the two page modules depend on both. The dependency graph mirrors actual `import` relationships in the source, but the browser's module resolver — not WordPress — handles the imports themselves; `$deps` only controls load-order in the rendered HTML.

### Breaking — for direct consumers of `cbCore.setTheme` / `cbCore.setLocale`

- **Return type changes from jQuery `$.Deferred` to native `Promise`.** Code calling `.done(callback)` and `.fail(callback)` must move to `.then(callback)` and `.catch(callback)`. CB Base's own callers (Appearance, Language pages) migrate in this release; no other CB plugin currently consumes these helpers, but if a downstream plugin started depending on the jQuery interface unofficially, it will need to update. Feature-detect against `apiVersion` if needed — though as of 1.0.22 it stays `'1.0'` because the helpers are net-new to the documented contract; their pre-1.0.21 form was undocumented and never part of the public API.

### Migration

- **No user action required.** Server-side AJAX endpoints, nonces, settings keys, and option storage all unchanged. Behaviour on Preferences > Appearance and Preferences > Language is preserved 1:1; the migration is observable only as `fetch` requests in dev-tools where there used to be `$.post`-style XHR.

## [1.0.21-dev] — 2026-04-25

Phase 1 of the JS modernisation pass: introduces the `assets/js/` ES module layer and the public-API surface on `window.cbCore` that downstream Core Blueprint plugins (Access Control, Protected Content, Invoice, future modules) can lean on. Minor version bump (not patch) because this adds a public contract — any plugin can now import these helpers as globals without cross-plugin path imports.

The migration is phased: this release ships the foundation (shared helpers) plus one proof-of-concept module (alert recipients), proving the wp_enqueue_script_module → import → fetch → window.cbCore pipeline end-to-end on the smallest IIFE in the file. The remaining nine IIFEs in `assets/admin.js` continue to run jQuery as-is and will migrate one phase at a time. Sites running 1.0.21 see no behavioural difference — the alert-recipient input on Preferences > Notifications works exactly as it did under jQuery, with the same `cb_core_set_alert_recipient` AJAX action, the same nonces, the same server-side validation contract.

### Added — `assets/js/01-dom.js`

- **DOM helpers**: `qs( selector, root? )` and `qsa( selector, root? )`. The latter returns a real Array (not a NodeList) so `.map` / `.filter` work without spread gymnastics at every call site.
- **AJAX helper**: `apiPost( action, nonce, data? )`. Native `fetch` wrapper around admin-ajax.php that posts the standard CB envelope (`action`, `nonce`, `_wpnonce`) plus caller-supplied fields. Returns a Promise resolving to parsed JSON. Network errors and non-OK HTTP statuses both reject — and the rejection carries the server-provided `data.message` when available, falling back to `HTTP <status>`. This replaces the eight-or-so per-IIFE `$.post` patterns scattered through admin.js.
- **Clipboard helper**: `copyToClipboard( text, feedbackEl? )`. Uses the modern `navigator.clipboard.writeText()` API with the legacy `execCommand( 'copy' )` textarea trick as fallback. The optional `feedbackEl` flashes the i18n "Copied to clipboard" string for 2 seconds — same UX the existing `.cb-core-inline-copy` handler offered, just jQuery-free.
- **Password re-confirm modal**: `confirmPassword( opts )` where `opts` is `{ title, prompt, confirmLabel? }`. Returns a Promise that resolves with the entered password on confirm, or rejects with `Error('cancelled')` on cancel / Escape / backdrop-click. Same DOM markup the existing `Modal` object built (`.cb-core-modal-backdrop`, `.cb-core-modal-error`, etc.) so the existing CSS selectors continue to apply unchanged.
- **`window.cbCore` exposure.** All five helpers above are also attached to `window.cbCore` (`cbCore.qs`, `cbCore.qsa`, `cbCore.apiPost`, `cbCore.copyToClipboard`, `cbCore.confirmPassword`), alongside `cbCore.apiVersion = '1.0'`. This is the public API contract for downstream CB plugins. The module file uses `import` for siblings inside CB Base; consumers in *other* plugins read globals — no cross-plugin path imports, no module-resolution headaches when directory layouts shift. The pattern mirrors `wp.element` and `wp.data` in WP core.

### Added — `assets/js/10-alert-recipients.js`

- **Alert recipient editor migrated off jQuery.** First IIFE migrated as proof-of-concept. Imports `qs` and `apiPost` from `01-dom.js`, uses `addEventListener` for click + Enter-key, and an `async/await` save flow with `try/catch/finally` for the disabled-state lifecycle. Server contract unchanged: same `cb_core_set_alert_recipient` action, same `nonceAdmin`, same group/recipient fields, same response shape, same partial-validity reflect-back behaviour (server returns the cleaned value, client writes it into the input).

### Changed — `assets/admin.js`

- **IIFE 10 (alert recipient editor) removed.** Replaced with a comment-marker pointing to `assets/js/10-alert-recipients.js`. The remaining nine IIFEs are untouched — they continue to run jQuery as before. Each subsequent phase moves one or more IIFEs out of this file.

### Changed — `src/Admin/Admin.php`

- **`wp_enqueue_script_module()` calls added** for `cb-core-js-01-dom` and `cb-core-js-10-alert-recipients`. The `$deps` argument controls load-order in the HTML; the browser handles actual module resolution. The classic `core-blueprint-admin` script (which `wp_localize_script()` writes `cbCore` onto) continues to load alongside the modules — by the time modules evaluate, `window.cbCore` is populated.

### Migration

- **No user action required.** Phase 1 is additive: the new module files load alongside the existing `admin.js`, the public-API surface on `window.cbCore` extends an already-localised global, and the only IIFE removed from `admin.js` (alert recipients) is replaced by a functionally equivalent ES module. The alert-recipient input on Preferences > Notifications behaves exactly as it did under 1.0.20.
- **Downstream plugins** that wish to consume the public API can read `window.cbCore.apiVersion` to gate against breaking changes. As of 1.0.21 the contract is: `qs`, `qsa`, `apiPost`, `copyToClipboard`, `confirmPassword`, `apiVersion`. Additions to this list are non-breaking; removals or signature changes will bump `apiVersion`.

### Deferred to Phase 2

- **`window.cbCore.setTheme` and `window.cbCore.setLocale`** still live in `assets/admin.js` (IIFE 1) using `$.post` and jQuery promises. Their six call-sites in the M3a Appearance and M3b Language IIFEs use `.done()/.fail()` chaining; migrating the helpers to native `fetch` without simultaneously migrating the callers would break those pages. Phase 2 (Appearance + Language pages) migrates the helpers and their callers in a single coherent move.

## [1.0.20-dev] — 2026-04-22

Converges two parallel 1.0.x development branches into a single release. Both branches started from 1.0.17-dev: one added Login Shield (shipped as 1.0.18-dev), the other added Extension tile enrichment (shipped as 1.0.19-dev). No code-level conflicts between the two — they touched disjoint files except for the plugin-header version string. This release is a straight union of both feature sets plus the Access Mode label-consistency fix that was originally part of the 1.0.18-dev work but got left out when the two branches diverged.

### Fixed

- **Access Mode page title now matches its tab label.** The Access Mode page rendered `<h1>Site Mode</h1>` (legacy wording ported from the old CB Theme `CBT_Admin::render_site_mode()`) while its Safeguards tab reads "Access Mode" and the URL parameter is `tab=access-mode`. Three names for the same thing was inconsistent — Peter's sales copy and the client-facing UI should not contradict each other. Fix: rewrote the `<h1>` to "Access Mode", rewrote the toggle's `aria-label` to "Toggle access mode", and updated the AccessMode class and template docblocks. **Also caught during the merge:** two Plain-mode copy strings in `templates/security.php` still said "Site Mode" while their Technical-mode counterparts (which were already correct) said "Access Mode" — the Plain / Technical pair is supposed to describe the same concept in two registers, and describing the same feature by two different names defeats the purpose. Both fixed. And the `Ajax\Handlers\Settings` docblock listing covered settings had "Site Mode" in the feature summary; updated. The internal class name (`CB\Core\Security\AccessMode`), template filename (`access-mode.php`), tab slug (`access-mode`), URL parameter, and the legacy-submenu suppression helper (which references the old CB Theme's `core-blueprint-site-mode` slug and must keep that name for the `remove_submenu_page()` call to work) all stay put. No behaviour change, no database migration.

### Migration

- **No user action required.** 1.0.18-dev and 1.0.19-dev were both internal dev builds that never shipped to production. Sites that ran either build pick up the combined feature set on next plugin load. The schema bump introduced in 1.0.18-dev (SCHEMA_VERSION → 2, drops the dormant `admin_only` subtree, seeds `login_shield` defaults) still applies here — sites upgrading directly from 1.0.17-dev to 1.0.20-dev get the migration the same way they would have via 1.0.18-dev.

### Changed — Safeguards tab reshuffle

- **Overview tab is now a read-only status snapshot.** Previously the Overview tab on the Safeguards page doubled as both a status summary *and* the full Core Shield configuration hub — one tab holding four panels of state-mutating controls plus status cards plus quick links, titled `<h1>Security</h1>` which didn't match any tab label. The split is now explicit: Overview holds the four-card status strip (Failsafe / Core Shield / Modules / Audit events), the bypass banner when active, and the quick-actions row. Nothing on this tab mutates state — any toggle or save lives one click away via the tab nav or a quick-action button. This matches how Peter's non-technical operators read the page: they want to answer "is my site safe right now?" without worrying about accidentally flipping a master switch.
- **New Core Shield tab** picks up the configuration that left the Overview tab. Contents: complementary-plugin detector notice (Wordfence etc. — affects which features Core Shield delegates), Core Shield master switch with its adaptive-preset binding, "Apply recommended defaults" button, security modules list with feature-toggles (Fingerprint, Headers), and the security header test diagnostic. The audit log & retention panel also lives here for now — it will move to the Logs page in a subsequent cleanup round, but moving it simultaneously would have required reworking the Logs page's tab structure and was deferred to keep the 1.0.20 change contained.
- **Tab order**: Overview / Core Shield / Access Mode / Failsafe / Login Shield. Four feature-tabs in a flat hierarchy with Overview as the landing status view, rather than Overview-doubles-as-Core-Shield + three feature tabs. Cleaner mental model for the multi-site operator: each feature has one tab, one scope, one h1 that matches its label.
- **Every Safeguards tab h1 now matches its tab label.** Overview h1 "Overview", Core Shield h1 "Core Shield", Access Mode h1 "Access Mode" (fixed in the earlier merge section), Failsafe h1 "Failsafe", Login Shield h1 "Login Shield". Dropped the old `<h1>Security</h1>` which never matched anything in the tab nav.
- **All Safeguards tabs use `cb-core-intro` for the intro paragraph** instead of WP's `description` class. The two were used interchangeably depending on which tab you landed on: Access Mode and Login Shield used `cb-core-intro`, Failsafe and the old Overview used `description`. Visually the drift showed up as different widths (the WP class has no `max-width`, so those intros ran edge-to-edge while the branded class capped at `68ch` for readability). Standardising on `cb-core-intro` fixes that — all intros now share the same 68-character cap, the same muted text colour, and the same vertical rhythm. No visual change on the two tabs that were already correct; Failsafe and Core Shield (new) come into line.

### Added — Reusable Overview framework

- **`CB\Core\Admin\Overview` helper class.** New rendering contract for every Core Blueprint admin-page Overview tab. Call `Overview::render()` with a config array (intro, status_cards, tab_cards, quick_actions, banner) and get the canonical Overview layout in return. Sibling plugins (CB Hub, CB Invoice, Access Control, Protected Content, future modules) can depend on CB Base and render their own Overview tabs by adopting this helper — the visual grammar stays consistent across the suite without each plugin reinventing its own Overview markup. API stability: field names in the config array are the stable contract; additive changes (new optional keys) are safe, renames/removals are breaking.
- **`templates/partials/overview.php`.** The shared HTML/ARIA/escaping partial that `Overview::render()` includes. Splits orchestration (the helper class) from markup (the partial), so UI tweaks land in one file without touching every caller.
- **Overview tab added to Logs.** First tab, default landing (`priority => 5`). Content: critical-events-24h card, total-events card, retention window + next-prune status cards, tab cards for Audit / System / Maintenance / Retention / Connection (when Beacon is paired — tab cards are built from `TabRegistry::visible()` so presence auto-adjusts), quick actions for "Open audit log" and "Retention settings". The Overview tab excludes itself from the card grid so operators are not pointed back at the page they are already on.
- **Overview tab added to Preferences.** First tab, default landing. Content: current-theme / language / description-style status cards (informational, no colour coding — Preferences has no critical states), tab cards for Privacy / Appearance / Language / About, no quick actions (nothing on Preferences that warrants a second-click shortcut). Current values read defensively with `class_exists` guards so a missing subsystem produces "—" rather than a fatal.
- **Overview tab on Safeguards migrated to the new framework.** Previously rendered its own markup via `overview.php`; now calls `Overview::render()` with the same four status cards plus four tab cards for Core Shield / Access Mode / Failsafe / Login Shield. Behavioural change: **tab cards appear below the status strip** (the explicit wayfinder that users asked for — "klanten kunnen lezen wat er te doen is zonder ieder tabje te openen"). Quick actions stay at the bottom of the page so they are reachable after the tab cards rather than requiring a scroll back to the top.
- **Dashicons on tab cards.** Each tab card picks a WordPress-native dashicon — `shield` for Core Shield, `lock` for Access Mode, `sos` for Failsafe, `admin-network` for Login Shield, `privacy` / `art` / `translation` / `info` on Preferences, `shield` / `admin-tools` / `clipboard` / `calendar-alt` / `admin-links` on Logs. Dashicons load with the WP admin core stylesheet — no extra assets, no custom SVG maintenance.

### Added — Logs retention tab

- **`Logs > Retention` tab.** New first-class tab on the Logs page (`priority => 60`), registered alongside Audit / System / Maintenance via `TabRegistry`. Houses the retention window, next-scheduled-prune indicator, email-alerts recipient, and per-severity alert toggles that previously lived under Safeguards > Core Shield. Moving it here removes a long-standing layering confusion: retention is a log-side concern (how long events live, when they expire, who gets alerted on what), not a security-module concern. Settings keys and AJAX endpoints are unchanged, so the existing alert-toggle handlers continue to work with no code adjustments.

### Changed — Safeguards `core-shield.php` reduced

- **Audit log & retention panel removed from the Core Shield tab.** The Core Shield tab now ends with the security header test, as it should — no retention bookkeeping in the middle of security-module config. Retention's new home is `Logs > Retention`. Existing Core Shield sections (detector notice, master switch, modules list, header test) are otherwise unchanged.
- **Old `templates/overview.php` deleted.** Safeguards' Overview is now rendered by `Overview::render()` from inside `Safeguards::render_overview_tab()`. Fewer bespoke templates to maintain; every Overview page now shares the same partial.

### Added — Configurable alert recipient

- **Email recipient is now user-configurable** from Logs > Retention. Previously the recipient for audit alerts was hardcoded to WordPress's `admin_email` option, filterable only through the `cb_core_alert_recipient` developer filter — fine for a systems integrator, useless for Peter's operators who can't touch code. The Retention tab now shows an editable text field: leave it empty and alerts keep flowing to `admin_email` (the previous behaviour exactly); fill it in and every alert goes to the override address instead. Multi-recipient supported via comma-separated input ("a@example.com, b@example.com") — each address validated on save through `is_email()`, duplicates collapsed case-insensitively, invalid entries silently dropped so a typo in one address does not block the whole save. The saved value is echoed back into the input so operators can see which addresses actually landed.
- **Three-layer recipient resolution** in `EmailAlerts::resolve_recipients()`: (1) admin_email as default, (2) UI override from `audit.email_recipient` wins when non-empty, (3) the pre-existing `cb_core_alert_recipient` filter runs last on the resolved string so site integrators retain the ability to override even the UI choice — the filter contract is unchanged, it just now operates on a more interesting input. Downstream `wp_mail()` calls are unchanged; the resolved string is comma-joined which `wp_mail()` already accepts.
- **New AJAX action** `cb_core_set_alert_recipient`. Hooked into the same `Settings` handler class that owns the rest of the audit settings, uses the shared `cb_core_admin` nonce, saves on blur (and on Enter). Server returns a 400 with an inline error if the user entered something non-empty that contains zero valid addresses.
- **Subtle indicator** appears below the input when a `cb_core_alert_recipient` filter is currently registered: "a developer filter is active and may override this value at send time." Keeps the UI honest for setups where integrators have taken over routing.
- **`EmailAlerts::sanitize_recipients()`** exposed publicly so the AJAX save handler uses exactly the same sanitisation path the sender eventually consumes — one validation routine, no drift.

### Changed — Retention & Notifications split

- **New `Notifications` tab on the Logs page** (priority 65). Houses the audit-alert recipient input and the per-severity checkboxes that used to sit inside `Retention`. The split was prompted by user feedback — putting email routing under a tab called "Retention" (which, by its own name, suggests data lifecycle) was confusing. Two concepts now live in two tabs: Retention for "how long do we keep events", Notifications for "who gets told when something happens". Icon: `email-alt`.
- **`Retention` tab slimmed** to exactly the two data-lifecycle rows it should have always been: retention window + next scheduled prune. Everything else moved out. The tab now has room to grow into things the user has already flagged as future additions — retention-days editor, manual-prune-now button, per-severity retention windows (an ISO 27001 best-practice) — without having to repaint the UI when those ship.
- **Input label changed** from "Email alerts recipient" to "Audit alert recipient". Explicit scope: this address receives audit event alerts, nothing else. Helper text below the input reinforces the scope. A dedicated "Scope note" paragraph at the bottom of the panel explicitly calls out that Failsafe emergency-bypass notifications **always** go to the site administrator and cannot be redirected through this field — the lockout-recovery channel is deliberately kept outside the admin UI so a compromised admin account cannot silently redirect its own bypass alerts.
- **Explicit Save button** replaces the previous blur-to-save UX. Blur-save was invisible to non-technical users — they would type an address, tab out, and not know whether anything had happened. A labelled primary button next to the input makes the save action obvious. Enter inside the input still submits for keyboard users, and the "Saved" / error status message is unchanged.
- **Logs Overview tab cards updated** — Notifications card added (`email-alt`), Retention card description rewritten to match the slimmed scope, tab-card ordering unchanged (cards are built from `TabRegistry::visible()` in priority order so Retention precedes Notifications automatically).

### Changed — Notifications moved to Preferences

- **Notifications tab moved from Logs to Preferences.** Email-routing configuration now lives at Preferences › Notifications instead of Logs › Notifications. Rationale: notifications are a governance/routing concern, not a log-viewer concern. As Core Blueprint grows, more subsystems will want to send notifications (Hub pairing events, License expirations, future modules) that have nothing to do with the audit log — so the page that owns email routing needs to be a home for all of them, not tucked inside "Logs". Preferences already bundles the user-level/site-level governance surfaces (Privacy, Appearance, Language, About); Notifications sits naturally alongside them.
- **Preferences tab order reorganised** to follow Chris's hierarchy principle (most fundamental → least): Overview / Privacy / Notifications / Language / Appearance / About. Reads as narrative: "what we record → who we tell about it → in what language → with what look → who built this". Previous order put Appearance before Language, which was inherited from shipping order rather than deliberate design.
- **Visual-group layout** on the new Notifications tab, ready for future multi-group expansion. One `<section class="cb-core-notification-group">` per notification source, each with an icon + title + short description header followed by its own form controls. Today there's one group ("Audit events"); when CB Hub adds pairing-event notifications, or CB License adds expiration warnings, they slot in as additional groups below the first — no layout rework, no cramming unrelated fields next to each other.
- **Page-level scope note** at the bottom of the Notifications tab (instead of per-group) states once, clearly, that Failsafe emergency-bypass notifications always go to the site administrator and cannot be redirected through this UI. The scope exclusion applies to every group equally, so it belongs at page level.
- **Logs Overview quick-action** "Email notifications" now links to Preferences › Notifications instead of the defunct Logs › Notifications tab. The Retention tab card description is unchanged; the Notifications tab card has been removed from the Logs Overview grid because Notifications no longer lives under Logs.

### Technical — multi-group groundwork

- **AJAX handler `cb_core_set_alert_recipient` parametrised with `group`.** Previously hardcoded to write `audit.email_recipient`; now accepts a `group` POST parameter with an allowlist (currently `['audit']`, expands as groups are added). Payload shape: `{ action, nonce, recipient, group? }`. Omitting `group` defaults to `audit` so this is a non-breaking change for any existing caller. The same handler will serve future groups without duplication — one handler per surface concept, not per subsystem.
- **`data-cb-core-alert-group` attribute** on the recipient input in `templates/notifications.php` carries the group identifier to the JS handler, which forwards it to the AJAX call. For the audit group the attribute value is `"audit"`. New groups just add new inputs with their own attribute value; the JS handler and AJAX endpoint work unchanged.
- **Storage schema convention documented** in the `EmailAlerts` class docblock: each notification group claims its own subtree under `CB_CORE_SETTINGS` (audit → `audit.*`, hub → `hub.*`, license → `license.*`). No migration needed for existing sites; the `audit.email_recipient` key is untouched.
- **No `NotificationRegistry` class, no filter hooks for sibling-plugin registration.** Deliberately not built — we have one concrete consumer, and abstracting before the second concrete use-case lands guarantees the wrong abstraction. The UI scales today through markup + settings keys + the handler allowlist; heavier infrastructure gets added when CB Hub or CB License bring a second concrete case that dictates its shape.

### Files

- **Added:** `templates/notifications.php`, `Preferences::render_notifications_tab()`.
- **Removed:** `src/Admin/Pages/Logs/Tabs/NotificationsTab.php` (logic moved to Preferences template + render method).
- **Removed:** `NotificationsTab` import and registration in `Logs.php`; all `NotificationsTab::SLUG` references in `Logs\Tabs\OverviewTab.php` (icon map, description map, quick-action URL target).

---

- **Tab order reorganised to follow the site-security hierarchy**, coarse → fine → emergency. New order: Overview / Access Mode / Core Shield / Login Shield / Failsafe. Rationale (Chris's): after the read-only Overview, Access Mode comes first because it is the most fundamental switch — is this site reachable at all? Core Shield is the next layer down (baseline hardening of a reachable site). Login Shield tightens a specific endpoint within that hardened site (the login form). Failsafe is last because it is the emergency escape hatch, the one operators touch only when the layers above have gone wrong. Previous order (Overview / Core Shield / Access Mode / Failsafe / Login Shield) followed roughly the order the features shipped in, which is an artefact of development history — not a mental model the user should be expected to carry. The new order reads from "biggest question" to "last resort" and makes every subsequent tab feel like a more specific version of the previous one.
- **Overview tab cards** reordered to match the tab nav. The grid follows the same hierarchy so the two navigation surfaces on the Overview page (top tabs + card grid below the status strip) are never out of sync. URLs, slugs, and internal class names are unchanged — only the visual order is different.

### Fixed — Retention tab scope

- **Retention tab is now a read-only monitoring view, not a configuration tab.** Earlier in the 1.0.20 cycle the Retention tab was built as a settings surface, showing a single `audit.retention_days` value with copy that claimed "retention is applied uniformly". That claim was wrong: Preferences › Privacy already owns a full per-category retention model (security / maintenance / logins / settings / connection, each with its own window) that has been the canonical retention system since m4.12.1. The `audit.retention_days` key is a pre-m4.12.1 legacy setting that `Retention::run()` only falls back to when the modern `cb_core_retention` option is empty. Displaying the legacy setting as if it were authoritative was misleading — any site that had opened Preferences › Privacy once was running per-category retention, and the Retention tab was describing a value that was no longer doing anything.
- **What the tab now shows.** A read-only table of the currently active retention rules — per category when the modern system is in use, or the legacy global value with an explicit "legacy" note when a site has not yet saved a preset in Preferences › Privacy. The "Next scheduled prune" indicator stays. A primary button at the bottom links straight to Preferences › Privacy for editing. No configuration controls on this tab; nothing to duplicate between Privacy and Logs.
- **Logs Overview retention card updated.** Shows the count of categories with a configured window (e.g. "5 categories") when per-category retention is active, or the legacy day count otherwise. The card's "Configure →" action is replaced with "View schedule →" and continues to point at the Retention tab. The Logs Overview's Quick actions now include a direct "Edit retention rules" shortcut to Preferences › Privacy alongside a new "Email notifications" shortcut to the Notifications tab.
- **Mental model clarified.** Preferences › Privacy is the single configuration surface for the governance model: IP handling, what gets logged, how long each category is kept. Logs › Retention is a monitoring panel answering "what are the current rules, when is the next prune, is the cron still scheduled" — a view over Privacy's configuration, not a second edit surface. This split makes the relationship explicit and stops the two pages from disagreeing with each other.

### Technical

- **`RetentionTab::render()` rewritten** to detect which retention system is active (`cb_core_retention` option non-empty → modern, else → legacy fallback) and render the matching table. Category labels mirror Preferences › Privacy verbatim so the two pages use the same language. `format_retention()` helper produces the same friendly labels Privacy offers ("6 months", "1 year", ...) with a raw-day fallback for arbitrary values.
- **Logs Overview's `status_cards()` updated** to compute the retention summary from whichever system is active, and to link the card's detail row to the Retention tab for monitoring rather than to a non-existent editor.
- **No schema changes.** The legacy `audit.retention_days` key remains in the settings defaults for now; removing it is a separate migration decision (some sites may still rely on the fallback behaviour and dropping the key without verification would change their prune behaviour silently). When we next touch the schema, that key is a candidate for deprecation.

### Fixed — `settings.changed` audit entries now name the changed subkey

- **The operator could not see which setting was actually changed.** A `settings.changed` row read `key=audit · before=array(3 keys) · after=array(3 keys)` — correct that *something* inside the `audit` subtree changed, but useless for forensics because the actual modified setting (retention window? recipient? a specific alert toggle?) was not disclosed. Root cause: `Settings::hint()` deliberately compresses arrays to a `array(N keys)` summary to avoid writing full payloads (which can be large and, for keys like `login_shield`, contain mildly sensitive configuration) into every audit row.
- **Fix: `settings.changed` rows now carry a `changed` context field** listing the dot-separated subkey paths that differ between before and after — e.g. `changed=email_recipient` or `changed=retention_days, email_alerts.warning`. Key names only; no values. Sub-key names in the CB settings schema are configuration identifiers, not secrets, so this is audit-safe without a redact-allowlist.
- **New private helper `Settings::diff_paths()`** walks old/new recursively and returns a flat list of changed paths. Scalar-valued `set_key()` calls (e.g. `shield_enabled`, `site_mode`) skip the diff since their before/after hints already fully describe the change.
- **Scope deliberately limited to keys, not values.** A follow-up release will extend audit entries with actual before/after values per changed path, gated behind a redact-allowlist for sensitive keys (bypass_token, future API keys). This is targeted at high-assurance deployments (zorg, gemeente) where full forensic disclosure is a contractual requirement. The current `diff_paths()` helper is the seam where that will land.

### Fixed — Audit log labels, Plain mode, and category filters were silently broken

- **`AuditLog::log()` strips dots from event_types at write time** via `sanitize_key()`. So `'settings.changed'` is stored as `settingschanged`, `'failsafe.token_rotated'` as `failsafetoken_rotated`, etc. This was always the case, but the downstream lookup paths all used dotted keys — so they matched nothing in the DB and fell back to raw slugs. The Plain-mode event description, the Technical mode's secondary muted label, and the category-based retention and filter queries had **never** actually worked for any of the ~40 events shipped so far. Verified against the production DB: every stored event_type is dotless.
- **`Language::describe_event()` now resolves catalog keys after sanitize_key() normalisation.** Catalog keys stay dotted (`'settings.changed'`) for readability; a lazily-built dotless index (`plain_lookup()`) is consulted on miss. Covers both DB-read flows and any direct in-memory callers that pass dotted keys.
- **`AuditLog::event_label()` does the same** for the Technical-mode labels array, via a sanitize_key-based fallback walk.
- **`AuditLog::category_sql_clause()` LIKE patterns rewritten to dotless form.** `LIKE 'settings.%'` → `LIKE 'settings%'`; `LIKE 'system.plugin_%'` → `LIKE 'systemplugin_%'`; same treatment for security, maintenance, logins, settings patterns. Specificity is preserved — prefix-matches remain as narrow as they were, they just match the actual stored shape.
- **Three call sites using `event_prefix => 'system.'` / `event_not_prefix => 'system.'` corrected** in `AuditTab`, `SystemTab`, and `Exports`. Behavioural impact: the System Log tab was showing **zero rows** regardless of what had been logged, and the Audit Log tab was showing **every** row including system events (because "not starting with 'system.'" was true for everything). Both now filter correctly.
- **Plain-mode context preview extended** with three new AVG-relevant fields alongside actor/reason/from-to: `changed` (renders as "changed: X"), `module` ("module: X"), `feature` ("feature: X"). So a `settingschanged` Plain row now reads e.g. "by admin@example · changed: email_recipient" instead of just "by admin@example".
- **No schema changes, no data migration.** All historical audit rows were already written in the dotless form — the fix is purely on the read/lookup side and takes effect retroactively against every existing row.

### Added — Format-agnostic log exporter (CSV + JSON; PDF seam for CB Report)

- **`CB\Core\Log\LogExporter` — new class** centralising serialisation of log-type exports. Built-in formats: CSV (streaming, header row from `columns()`, row-by-row via generator) and JSON (envelope structure with `export` metadata + `events` array, pretty-printed with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`). For any format CB Base doesn't handle natively, the dispatcher fires `do_action( "cb_core_export_{$format}", $handle, $rows, $columns, $meta, &$count )` — this is where a sibling **Core Blueprint Report** plugin will hook its PDF renderer. PDF itself stays entirely out of CB Base; the seam is ready and uses `class_exists` + filter-based discovery so CB Base never loads a PDF library.
- **`apply_filters( 'cb_core_export_formats', … )`** is the canonical format registry the UI reads to build its dropdown. Extensions add entries here AND register their `do_action` listener; the dropdown updates automatically when the extension is active, and doesn't show the format when it's not (no disabled state, no installation prompts — just absent). Companion filters `cb_core_export_mime_types` and `cb_core_export_extensions` let extensions register their Content-Type headers and file extensions.
- **Log classes expose a three-method contract**: `rows_iterator($args): \Generator` yields associative rows one at a time (DB-paged for AuditLog, in-memory for MaintenanceReport), `columns(): array` returns `field_key => human label` for the header row and field projection, `export_meta($args): array` returns the envelope metadata. Implemented for `AuditLog` and `MaintenanceReport`; new log sources drop in by implementing the same three methods.
- **JSON envelope schema**: `{"export": {"type", "generated_at" (ISO 8601 UTC), "generated_by" (login:email), "site_url", "plugin_version", "filters" (user's active filters), "total"}, "events": [...]}`. Contains the forensic fields auditors ask for (who exported, when, from which site, with which filters) in a single structured document — matches what PDF cover pages will need later.
- **`Exports::begin_stream()` replaces `begin_csv_stream()`** — format-aware, chooses the right Content-Type / Content-Disposition / file extension from the `format` query param (default `csv`). The three export AJAX endpoints stay separate per log-type because their filter-arg shapes differ; consolidating them into one generic endpoint is a later refactor.
- **Dropdown + button UI** on Audit, System, and Maintenance tabs. The old "Export CSV" button is now a `<select class="cb-core-export-format">` listing registered formats next to an "Export" button. Both templates render the dropdown via `LogExporter::formats()`, so the UI, the URL param, and the backend dispatcher all read from the same registry.
- **JavaScript export handlers consolidated** from three near-identical copy-paste blocks into one config-driven dispatcher (`.cb-core-log-filters__export` listener + `CB_EXPORT_MAP`). Adds format-awareness in the same change: the handler reads the selected format from the adjacent dropdown and appends it as `format=...` on the export URL. Adding PDF (via CB Report) requires zero JS changes — the new format appears in the registry, the UI dropdown picks it up, and the handler forwards it verbatim.
- **BC preserved**: `AuditLog::export_csv()` and `MaintenanceReport::export_csv()` are retained for external callers but now delegate to `LogExporter::to_csv()`. Any code that calls `export_csv()` directly keeps working unchanged.

### Added — Plain/Technical log descriptions + transparency meta strip

- **Every log viewer now explains itself at a glance.** Each of the four log tabs (Audit, System, Connection, Maintenance Report) renders a 2–3 sentence description above the filter bar that switches with the existing Plain/Technical toggle. Non-technical readers (zorg/gemeente buyers Peter talks to) see a plainly-worded sentence about what the log records and why; operators with the Technical toggle on see the implementation-level description (event families, source hooks, table names). Same toggle that was already in the sticky filter bar — no new UI affordance.
- **New `Language::LOG_DESCRIPTIONS` catalog** holds both variants for all four log kinds (`audit`, `system`, `connection`, `maintenance`). `Language::describe_log($type, $mode)` resolves the active variant with graceful fallback between modes and to an empty string for unknown types. `Language::describe_log_both($type)` returns both strings together — used in export envelopes so JSON / PDF documents are self-describing.
- **Transparency meta strip** next to the total-events count: "Retention: N days · Visible to administrators only · Exportable as CSV, JSON". Surfaces the AVG-relevant facts (how long does this stay, who can see it, what can leave the system) without requiring a click through to Preferences or Retention settings. Maintenance Report's strip substitutes "Read-only — no changes can be made here" for the retention line because MR has no own retention (it's a view over the underlying logs).
- **Export formats read from the live registry**, so the strip updates automatically when a sibling plugin like CB Report adds PDF via the `cb_core_export_formats` filter. "Exportable as CSV, JSON" becomes "Exportable as CSV, JSON, PDF" the moment CB Report activates — single source of truth.
- **JSON export envelope now ships the description pair** under `export.description.plain` / `export.description.technical`. Auditors receiving a JSON export can read what the document contains without access to the plugin UI; PDF rapportages will use the same fields for their cover page and intro paragraph.
- **Descriptions moved out of template `$page_description`** variables and into the Language catalog. The remaining `$page_description` becomes a short sprintf template just for the total-count line ("Total events: %s."). This keeps copy changes (inevitable — Peter's feedback, translation rounds) in one location instead of scattered across four template files.
- **CSS `.cb-core-log-description` + `.cb-core-log-meta`** styles the new block: description capped at 68ch for readability (same measure as `cb-core-intro`), meta strip rendered as flex-inline with `·` separators so it reads as a single prose line rather than a list of chips. Responsive wrap on narrow screens.
- **No schema changes, no data changes.** All four log tabs plus the JSON exporter pick up the new block on next page load; Plain/Technical toggle continues to work as before, just now affects one more element on the page.

---

### Changed — Maintenance Report now filters monitoring traffic

- **Hub status checks and heartbeat traffic no longer drown the Maintenance Report.** Before this change, every inbound REST request to the Beacon namespace landed in the Maintenance Report via `MaintenanceSource::collect()` — including `/ping`, `/status`, `/backup/list`, `/backup/status`, `/update/inventory` and `/update/status`. On an actively-polled site that meant hundreds to thousands of read-only monitoring rows per day, making the report unusable as a client-facing audit artefact.
- **Audit-grade filter policy applied.** `MaintenanceSource::should_include()` now decides per row: (1) include endpoints that represent actual maintenance work (allowlist: `/backup/start`, `/backup/download`, `/backup/delete`, `/update/apply`); (2) **also** include any row with severity `warning`/`critical` OR HTTP status ≥ 400 regardless of endpoint — a failed `/status` call is a real monitoring incident that must appear in an audit trail; (3) default-exclude everything else, including endpoints CB Base doesn't know about yet.
- **Default-exclude for unknown endpoints is deliberate.** Audit reports must be deliberately curated documents, not log dumps. Silent inclusion of an unfamiliar endpoint is a worse failure mode than temporary absence until a version bump explicitly maps it. Aligned with what NEN 7510 (zorg) and BIO (overheid) auditors expect of change-management documentation.
- **New filter hook `cb_core_mr_beacon_include`** lets extension plugins or site-specific overrides force-include or force-exclude a row. Receives `(bool $include, string $endpoint, string $severity, int $status, array $row_data)` and returns a bool.
- **Connection Log stays unfiltered.** The forensic source-of-truth remains complete: every inbound REST request continues to be recorded there with full metadata. Maintenance Report is explicitly positioned as the curated audit artefact; Connection Log as the evidence reservoir auditors can fall back to for detailed questions. Both log descriptions (Plain and Technical) now state this explicitly: *"Only actual maintenance work is listed here; pure status checks and monitoring traffic can be found in the Connection Log."*
- **Net behavioural change**: on next page load, Maintenance Report shrinks dramatically on Hub-paired sites. A typical polled site goes from thousands of rows per week to dozens. Failed operations, real backups, real updates, and any warning/critical row remain visible. No data deletion — filtering is purely read-path.
- **Severity values now flow through natively.** Previously the `severity` field was cast with a default of `'info'` after the row had already been emitted; now it's resolved once, before the filter decision, and used both for inclusion logic and the output row. Functionally equivalent on the happy path but makes the filter logic readable without rebuilding the same variable twice.

### Changed — Page-intro CSS consistency pass across admin templates

- **All admin page intros now use the same base class: `.cb-core-intro`.** Four templates were drifting: `privacy.php` used `.cb-core-privacy-intro` standalone (without the base class, so it missed the CB muted-colour / 68ch max-width treatment), and `log-events-page.php` + `connection-log.php` used WordPress's native `.description` class which didn't match the `.cb-core-intro` branding that `dashboard.php`, `login-shield.php`, `failsafe.php`, `core-shield.php`, `appearance.php`, `notifications.php`, `access-mode.php`, `language.php`, and `maintenance-report.php` all used. Peter's demo walkthroughs now read visually uniformly — every page opens with the same intro typography and spacing.
- **Form field helper texts intentionally keep WP's `.description` class.** Every `<span class="description">` or `<p class="description">` that sits directly under a form input (e.g. the three AVG-mode radio helpers in `privacy.php`: "Last octet zeroed", "Complete address stored", "No IP stored at all") is untouched. Rationale: these are a universal WP admin UX idiom — every WordPress admin recognises that grey text as "hint for this field". Replacing with a CB-specific class would win nothing (no better UX, no sales advantage) and lose screen-reader conventions that WP has built up over the years. The decision rule: WP standard for universal admin idioms (form helpers, notices, buttons), CB own class for CB-branded UX elements (page intros, log-meta strips, dashboard tiles).
- **Log meta strip (`.cb-core-log-meta`) is now fully self-styled.** Previously carried `class="description cb-core-log-meta"` and leaned on the WP grey cascade for its colour. The `.description` class is now removed; `.cb-core-log-meta` defines its own `font-size: 12px` and `color: #646970` (the WP admin muted grey) directly, with a CB-themed override that swaps to `var(--cb-text-muted)` under CB themes. Visually identical in both themes, but the styling is now explicit instead of relying on a cascade that was one markup change away from breaking.
- **`.cb-core-intro` base styling now works in native WP admin theme too.** Previously the rule was gated on `body[data-cb-theme^="core_blueprint"]`, so users on the native admin theme saw unstyled paragraphs. An ungated base rule now supplies the defaults (`#646970` colour, 68ch max-width, 14px/1.6); the CB-themed override upgrades the colour to the CB token. This was a latent bug across every admin page that only surfaced now during the consistency sweep.

---

### Fixed — Maintenance Report missed every local system event

- **The System Log data source in Maintenance Report matched on the dotted prefix `'system.'`** — same root cause as the Audit/System tab and retention-category bugs fixed earlier in this release. Because DB event_types are dotless (`systemplugin_activated`, not `system.plugin_activated`), the Maintenance Report's `collect_system_rows()` query returned **zero** local rows. The report was therefore only showing Beacon Connection Log rows (when the site is paired with a Hub); every plugin install, theme switch, user role change, and core update was invisible in the client-facing report. Fix: the `event_prefix` is now `'system'`, matching the stored shape.
- **Second bug on the same root cause: `system_category()` classifier.** Even if the events had been returned, its `strpos( $et, 'system.plugin_' )` checks would have failed against dotless DB values and every row would have fallen into the `'other'` bucket — making the category filter useless. Rewritten to match on the dotless prefix (`systemplugin_`, `systemtheme_`, `systemcore_`, `systemuser_`) with a dotted-form fallback for the edge case where the helper is called with an in-memory event_type that hasn't been through the write path yet.
- **Bonus during the classifier rewrite**: `systemfoundation_*` events (CB Base installs/upgrades itself — `system.foundation_installed`, `system.foundation_upgraded`) now classify as `'update'`. Before they fell through to `'other'` because the helper had no rule for that prefix at all, even in its intended dotted form.
- **Net behavioural change on existing sites**: the Maintenance Report populates with historical local activity on next page load. No migration or data change — this is pure read-path lookup that now matches the stored rows.

---

## [1.0.19-dev] — 2026-04-22

### Added — Extension tile enrichment

- **Extensions filter now supports `state` and `status_line` fields per entry.** The dashboard's Extensions grid gives every detected Core Blueprint sibling a tile showing slug, name, version, and a dot that used to be strictly active/inactive. Those tiles are now contribution points: an extension can hook `cb_core_extensions` and set `state` to `warning`, `idle`, or `error` on its own entry, and set `status_line` to a short contextual summary like `3 documents · 1 overdue`. The tile's dot picks up the state colour and the meta line replaces the default `active` label with the custom string. Extensions that don't hook in still render exactly like before — same slug kicker, same name, same `v{version} · active` meta — so this is a zero-regression addition.
  - **Motivation.** Avoids the two-tiles-per-plugin dashboard sprawl that would result from every extension both appearing in the Extensions grid *and* contributing a tile to the Status row. Live status for an extension now travels with that extension's own tile, one place per plugin.
  - **Safety.** A post-filter sanity pass normalises any entry still marked `active = false` back to `state = 'inactive'` / `status_line = 'inactive'`, so a partner plugin that accidentally leaves stale state on a deactivated sibling's entry can't make a tile lie about a plugin that isn't running.
  - **Migration.** First-party sibling plugins adopt this progressively. Core Blueprint Invoice & Quotes 1.2.0-dev is the first consumer (overdue-invoice count → warning state). Hub and other siblings can land similar hooks when they have live data worth surfacing.
- **CSS dot variants** — `.cb-core-tile-dot--warning` / `--idle` / `--error` map to the existing `--cb-warning` / `--cb-text-faint` / `--cb-danger` theme tokens, so the new states blend with whichever Core Blueprint theme is active without needing per-theme overrides.

### Changed — `Extensions::detected()` return shape

- Every entry now carries two additional keys: `state` (string, one of `active` / `inactive` / `warning` / `idle` / `error`, defaults to mirroring the `active` bool) and `status_line` (string, defaults to the translated `active` / `inactive` label). Consumers reading `['active']` directly are unaffected. Templates or partner code that emitted their own dot/label from just `['active']` will continue to work; adopting the new fields is optional.

## [1.0.18-dev] — 2026-04-22

New feature: **Login Shield** — passive hardening of the WordPress login routes. Hides `/wp-login.php` behind a user-chosen slug, 404s blind scanners that keep hitting the default endpoint, and (in Strict mode) locks down `/wp-admin` for guests so the custom URL is never revealed via redirect. Fully integrated with Failsafe and the Core Shield master switch — both gate enforcement, so Login Shield can never cause a lockout that Failsafe doesn't already recover from.

Honest framing throughout: this is an obscurity layer that reduces scan volume, not a defence against targeted attacks. UI copy, audit-log entries, and the "What Login Shield does not do" panel on the settings page are all aligned with that scope. Rate limiting, IP blocking, 2FA, and XML-RPC hardening are explicitly out of scope and documented as such.

### Fixed (post-initial)

- **Login Shield's post-login redirect now defers to upstream role-based plugins** (WooCommerce, membership plugins, LMS plugins). Symptom: on a webshop, a `customer` logging in through the custom slug could be redirected to Login Shield's configured target (Dashboard / Homepage / Custom URL) instead of WooCommerce's `/my-account/`, because both filters ran at priority 10 and registration order determined the winner. Fix: move `login_redirect` filter registration from priority 10 to priority 20 so Login Shield runs *after* role-based plugins have spoken, and add an `is_default_redirect_target()` check that only applies the Login Shield override when `$redirect_to` is still one of WP's default destinations (`admin_url()`, `admin_url('profile.php')`, `home_url()`, or `user_admin_url()`). If anything upstream set a non-default target, Login Shield stands down. Form-level redirects (Bricks, BricksForge, Elementor, WP's `?redirect_to=` query parameter) continue to win as they did before — `$requested_redirect_to` is checked first and short-circuits the filter. Net precedence, highest first: (1) form-level redirect, (2) plugin-level role redirect, (3) Login Shield's configured default. UI copy under the "Redirect after login" dropdown rewritten to make this precedence visible to non-technical users.
- **"Undefined variable $user_login / $error / $action" warnings in the rendered login form when served under the custom slug.** Symptom: with Login Shield enabled, visiting the configured URL (e.g. `/cb-admin/`) rendered the login page correctly but emitted PHP notices throughout the HTML — including one that landed *inside* the username `<input value="">` attribute, making the form ugly and unusable for anyone with display_errors on. Root cause: wp-login.php declares roughly a dozen variables (`$error`, `$errors`, `$action`, `$user`, `$user_login`, `$redirect_to`, `$secure_cookie`, `$interim_login`, `$customize_login`, `$switched_locale`, `$lang`, `$rememberme`) as implicit globals at the top of the file and references them again in form-rendering blocks hundreds of lines down. When the file is `require_once`'d from a direct hit on `/wp-login.php` those assignments land on global scope and everything works. When it's `require_once`'d from inside `LoginShield::serve_alias()` — a static method — the assignments land in method-local scope, and the later references come up empty. Fix: promote all of wp-login.php's expected variables to globals inside `serve_alias()` before the require, so inlined code mutates the globals consistently. Exhaustive list is better than missing one — a missed variable is a silent rendering bug that only shows up on specific action paths.

### Added (post-initial)

- **Dashboard status tile for Login Shield.** Sits next to the existing "Core Blueprint secured" tile at the top of the dashboard. Four distinct states mapped to the tile's dot color: `active` (green — Login Shield is enforcing, subtitle names the configured slug, meta shows Standard/Strict mode), `warning` (yellow — feature is enabled but no slug is configured, the silent-fail state that's easiest to overlook), `idle` with a dormant-reason note (feature enabled + configured but Core Shield is off or Failsafe bypass is open), and `idle` off (feature disabled, tile nudges toward Safeguards). Tile is a clickable link targeting the Login Shield tab directly — one click from the dashboard to the configuration. Contributed via the existing `cb_core_status_tiles` filter at priority 5, so Core Blueprint's tiles stay ahead of sibling plugins in the Status row. Implementation split the original `StatusTile::contribute()` into `foundation_tile()` + `login_shield_tile()` helpers to keep each state machine readable.
- **Third block-response option: Redirect to homepage (302).** Alongside the existing 404 and 403 responses for blocked `/wp-login.php` GETs and Strict-mode `/wp-admin` hits, the Advanced pane now offers a 302 redirect to `home_url('/')`. Use case: friendlier handling for legitimate visitors who bookmarked the old login URL — they land on the homepage instead of a bare 404. Default remains 404 (the obscurity-maximising choice); the redirect option is opt-in. Implementation uses `wp_redirect()` directly rather than `wp_safe_redirect()` to avoid a self-inflicted URL-filter detour (our own `site_url` / `wp_redirect` filters could re-rewrite the home URL in pathological configs).
- **`RESPONSE_CODE_302` constant** on `LoginShield` so callers referencing the value by name stay symmetric with `RESPONSE_CODE_404` / `RESPONSE_CODE_403`. Included in `RESPONSE_CODES` and honoured by `normalize_response_code()`; pre-existing stored values (404 / 403) are unaffected.

### Added

- **`CB\Core\Security\LoginShield`** — standalone class next to `AccessMode` and `Failsafe`, booted at `plugins_loaded` priority 9. Single responsibility: decide whether the current request is a blind scan of the default login endpoint, a legitimate POST that must pass through (2FA-plugin callbacks, password-protected posts, form submissions), a legitimate GET on a whitelisted recovery action (`postpass`, `lostpassword`, `rp`, `resetpass`), or a hit on the configured custom slug that should be aliased to `wp-login.php`. Every decision respects `Failsafe::is_bypassed()` and `Settings::shield_enabled()` before enforcing — the same lockout-safety contract every other restrictive CB feature follows.
- **Two-hook request interception.** `init` priority 0 blocks direct hits on `/wp-login.php` (fast path — pluggable.php has loaded so `is_user_logged_in()` works, but WP hasn't done meaningful init-time work yet, so blocked bots pay near-zero cost). `wp_loaded` serves the custom-slug alias by including `wp-login.php` inline — chosen deliberately over earlier hooks so every init-time filter has registered before `wp-login.php` runs. Critical for Wordfence 2FA and WP 2FA, both of which hook `wp_authenticate` during init.
- **URL-generation filters.** `site_url`, `network_site_url`, `login_url`, `logout_url`, `lostpassword_url`, `wp_redirect` — all rewrite `/wp-login.php` references to the custom slug. Any plugin that builds a login URL via the WP API ends up sending users to the right place; plugins that hardcode `site_url('wp-login.php')` are unaffected (documented, not fixed here — not Login Shield's concern).
- **`login_redirect` filter** for the "Redirect after login" setting. Explicit `redirect_to` requests always win — if the user clicked a protected-page link that carried its own redirect, that wins over the configured default. Dashboard (WP default) / Homepage / Custom URL options are wired.
- **Safeguards › Login Shield tab** — 4th tab on the Safeguards page, following the same render pattern as the existing Access Mode and Failsafe tabs. Enable toggle at the top, custom URL input with live slug sanitisation + preview, protection-mode radio (Standard / Strict), redirect-after-login dropdown, an Advanced `<details>` section exposing the 404-vs-403 block response code, save + test-URL buttons. UI copy is deliberately written at a level a non-technical operator can parse — no shell commands, no function names, no bare technical jargon outside the Advanced pane.
- **Strict-mode confirmation dialog** via `window.confirm` when the user flips to Strict. Reverts the radio to the previous selection if the user cancels, so cancelled confirmations don't leave Strict silently selected.
- **Server-side custom-URL self-test.** `cb_core_login_shield_test` AJAX endpoint issues a cookieless `wp_remote_get` against the configured slug and surfaces the HTTP status code back to the operator. Done server-side so the admin's own login cookie doesn't mask what a guest visitor would actually see. 5-second timeout caps worst-case UI latency; no hard dependency on the response code matching, since some setups legitimately 302 further.
- **Audit-log events.** `login.shield_enabled` / `login.shield_disabled` / `login.url_changed` are written synchronously via `AuditLog::log()` at `notice` severity — low-volume configuration changes that matter. `login.route_blocked` is written via `AuditLog::queue()` at `info` severity — high-volume (blind bot scans on a public site easily generate hundreds per day), so batched on shutdown to avoid a per-request DB write per dropped bot. `login.guest_redirected` is deliberately not logged by default — verified behaviour with no diagnostic value, would be pure noise.

### Changed

- **`Settings::SCHEMA_VERSION` → 2**, with a `SettingsMigrator::migrate_to_2` entry that drops the dormant `admin_only` subtree (`enabled`, `login_url_slug`, `allowed_ips` — reserved by an earlier design that put the login-URL slug under AccessMode; nothing ever read those keys) and seeds `login_shield` defaults on pre-1.0.18 installs. Migration is non-destructive: the keys being removed never carried production data.
- **`Settings::defaults()`** now exposes `login_shield` seeded from `LoginShield::default_config()` — single source of truth for the default shape, so the migrator, the defaults array, and the runtime config accessor never drift from each other.
- **`CB\Core\Ajax\SecurityRouter`** picks up the new `Handlers\LoginShield` module. `cb_core_login_shield_save` + `cb_core_login_shield_test` endpoints use the existing `Request::nonce` / `Guards::require_admin` preamble and the shared `cb_core_admin` nonce.
- **`assets/admin.js`** gains a dedicated IIFE for the Login Shield tab — slug sanitisation mirrors the PHP `sanitize_slug()` exactly so the live preview never shows something the server would refuse. The block gates itself on presence of the form element + `cbCore.nonceAdmin`, so every other admin screen pays no JS cost.
- **`assets/admin.css`** gains Login Shield styles using the existing `cb-core-*` class family + `--cb-*` token system. Dark-theme overrides mirror the light-theme defaults. No new tokens introduced — the brand-level contract stays intact.
- **`Admin::enqueue_assets`** localises six new i18n strings for the Login Shield UI (`lsConfirmStrict`, `lsSlugRequired`, `lsSaving`, `lsSaved`, `lsSaveFailed`, `lsTesting`, `lsTestFailed`).

### Technical

- **Hook-timing discipline.** The feature deliberately avoids `template_redirect`, which does not fire on `/wp-login.php` — it's a separate PHP entry point that never touches the template loader. Early versions of the spec listed `template_redirect` priority 1; that was corrected before implementation because AccessMode's use of `template_redirect` only works because AccessMode explicitly excludes `wp-login.php`. Login Shield's targets are exactly the endpoints AccessMode leaves alone, so a different hook stack was required.
- **Core Shield gating.** `LoginShield::is_enforcing()` returns false whenever `Settings::shield_enabled()` is false, matching the contract the rest of Core Blueprint follows — the master switch is one consistent global kill-switch, not a per-feature thing. The Login Shield tab surfaces a banner explaining this state so users who toggle the feature on and see nothing happen aren't left wondering why.
- **Naming discipline.** "Login Shield" is the UI name; the internal identifier is `login_shield` (settings key, class namespace, AJAX actions, audit-log event prefix). Deliberately distinct from the pre-existing `Settings::shield_enabled()` / Core Shield terminology — two different things, both branded "shield", one layered on the other.
- **Generic block response.** Blocked requests receive a static HTML 404 (or 403 if configured) with no Core Blueprint branding in the body or headers. Response reveals nothing about CB's presence on the site — indistinguishable from a stock server-level 404.
- **Audit-log context is minimal by design.** Block entries record path + method only. No cookies, no request body, no nonces, no user agents beyond what `AuditLog` stores itself. The `AuditLog` dedup window (60 seconds per event+context) collapses repetitive scan bursts into a single entry when the scanner keeps hitting the same URL.

### Migration

- **Schema version bump from 1 → 2 runs automatically on next plugin load.** The migrator drops `admin_only` from the settings array and seeds `login_shield` with the default (feature disabled, no slug configured). Nothing in the admin UI changes visually until the user opens the new Login Shield tab — the feature ships OFF by design so pre-existing installs are identical until the admin opts in.
- **No scheduled jobs, no new database tables, no new option keys.** Login Shield's entire state lives under `cb_core_settings['login_shield']`.
- **Failsafe token is unchanged.** The bypass-URL flow remains the primary recovery path if a user configures Login Shield with a slug they then forget. The Login Shield tab links to the Failsafe tab at the bottom as a reminder.

---



Extension auto-detection fix — Hub and future `core-blueprint-*` sibling plugins now show up correctly in the dashboard's Extensions section.

### Fixed

- **`Extensions::detected()` missed `core-blueprint-*` folders.** The prefix check only accepted slugs starting with `cb-`, which excluded Core Blueprint Hub (folder `core-blueprint-hub`) and every future Suite plugin that lands on the canonical naming convention. Hub was therefore never detected as an extension, so its tile never rendered in the dashboard's Extensions grid and the section stayed at "No Core Blueprint extensions detected" on every install. Fix: detection now accepts both `core-blueprint-*` (canonical, going forward) and `cb-*` (legacy, still shipping in existing deployments). The two conventions coexist — deployments with older `cb-*` folder names keep working, and new plugins can land on the canonical form without a naming mismatch.

### Changed

- **`known_slugs()` well-known list refactored into a dedicated method** and extended with both naming conventions. Previously inline as a `$known` variable in `detected()` with only legacy `cb-*` entries; now extracted to a private static method that lists both canonical (`core-blueprint-hub`, `core-blueprint-invoice`, `core-blueprint-access-control`, `core-blueprint-protected-content`, `core-blueprint-license`, `core-blueprint-hub-theme`) and legacy (`cb-hub`, `cb-invoice`, `cb-access-control`, `cb-protected-content`, `cb-license`, `cb-hub-theme`, `cb-control-hub`) slugs. A Suite plugin with a non-canonical Author header still gets picked up as long as its slug is on this list — useful for forks, partner builds, and in-development versions that haven't yet standardised their plugin header.
- **Class docblock updated** to reflect the new detection rules (both prefixes accepted, author requirement is softer when slug is well-known).

### Added

- **Extension tile status dot.** Each extension tile in the dashboard grid now carries a small colour-coded dot positioned top-right: green (`--cb-success`) when the plugin is active, faint grey (`--cb-text-faint`) when installed but inactive. Matches the visual grammar of the Status tiles at the top of the dashboard — which also use dot-based state indication — so the two sections now read as consistent visual vocabulary rather than different metaphors for similar information. The dot is static (no pulse animation), unlike the Status tile dot above: extension presence is a discrete deployment-state fact, not a live-service heartbeat that benefits from motion feedback. 3px "ring" shadow matches the surface behind it so the dot reads clearly against both dark and light themes. Hover-state updates the ring so the dot pops correctly on the hover background.

### Technical

- **CSS token discipline.** New `.cb-core-tile-dot` class uses `--cb-success` / `--cb-text-faint` / `--cb-surface-1` / `--cb-surface-2` / `--cb-space-4` throughout — no hardcoded colours, no magic-number positioning. Light and dark themes automatically apply because both theme scopes define the same token names.
- **Template diff is small.** `templates/dashboard.php` gains one `<span>` element per extension tile and a tiny preamble that computes `$state` and `$state_label` once per tile (instead of branching inside the meta line). Backwards-compatible with any theme or child plugin that overrides the dashboard template — the extra dot is additive and degrades gracefully if its CSS isn't loaded.

### Migration

- **No user action required.** Existing installs pick up the fix on next dashboard load: Hub appears automatically as an active extension tile, other Suite plugins show up as and when they're installed. No options change, no database migration, no re-activation needed.

---

## [1.0.16-dev] — 2026-04-21

QueryBuilder v1.1 release. Fills the three gaps that forced CB Hub's fase 7b to keep two native `$wpdb` queries: subquery-WHERE support inside `latest_per_group()`, aggregate-free projections (COALESCE, literal values, column aliasing). After installing this release, CB Hub's upcoming fase 7c migrates its final three native queries — zero `$wpdb->get_*()` / `$wpdb->insert/update/delete/query` calls remain in Hub for query work.

### Fixed (post-initial)

- **`latest_per_group()` outer WHERE ambiguity (reported during Hub 1.0.16-dev live testing).** The initial v1.1 cut compiled the outer WHERE with the same SQL as the inner subquery — unqualified column names. But the outer query has two tables in scope (`main` and `latest`), both of which share column names, so MySQL rejected the query with `Column 'site_id' in WHERE is ambiguous` on every Hub install with more than one site. Fix: WHERE clauses are now stored with column-identifier metadata (new `$where_columns` parallel array on QueryBuilder / UpdateBuilder / DeleteBuilder, populated by the new `WhereClauses::record_where()` helper that all 16 WHERE helpers route through). At SQL-assembly time, latest_per_group mode emits two rewritten variants: inner gets `main.` prefix stripped (if the caller or a previous rewrite added one), outer gets `main.` prefix added to unqualified columns. Qualified columns (`j.foo`, `other.bar`) are left alone in both directions.

### Added

- **`latest_per_group()` now carries WHERE clauses into the subquery.** In v1.0 the inner MAX was computed over *all* rows in the table, and WHERE clauses only filtered the outer result. That was semantically incorrect when the filter mattered to which rows qualified as "latest" — e.g. `status = 'done'` would miss the true latest successful backup if a later `failed` row existed. v1.1 applies every WHERE clause to both halves (inner and outer), so the inner MAX is computed over the same filtered row-set that the outer query returns. The SQL effectively becomes "latest X per group where Y" — the natural semantics for this pattern. Parameter doubling is handled automatically: the param list fed to `$wpdb->prepare()` now contains the WHERE values twice (inner placeholders, then outer placeholders), matching the compiled SQL's placeholder positions.
  - Breaking-change risk: none. v1.0's `latest_per_group()` had no production consumers in CB Base itself; Hub's `get_last_backups` that needed this pattern deliberately stayed on native `$wpdb` precisely because of this gap. So no existing call-site sees different behaviour.
- **`select_coalesce( array $columns, string $alias )`** — appends `COALESCE(col1, col2, …) AS alias` to the SELECT list. Used when a column should fall back to another when NULL (e.g. Hub's Activity feed falls back from `finished_at` to `started_at` so running-rows show up at their start time rather than NULL). At least two columns required; each column + the alias validated via the strict identifier rules.
- **`select_literal( ?string $value, string $alias )`** — appends a constant value as a named SELECT column. Used when discriminating rows coming from merged sources (Hub tags each Activity-feed row as either `'backup'` or `'notification'` via this pattern). Pass `null` to render `NULL AS alias` — useful for matching column counts across PHP-side-merged result sets. String values restricted to a conservative character whitelist (alphanumerics + underscore + dash + dot + space); anything outside the whitelist is rejected. This is fail-closed by design: the builder is not a SQL-injection playground, and every legitimate use inside the Suite involves short technical discriminators.
- **`select_as( string $column, string $alias )`** — appends `column AS alias` to the SELECT list. Used when a column should be exposed under a different name — typically to line up column shapes across PHP-merged result sets. Column validated as `col` or `alias.col`; alias validated as a bare identifier.

### Changed

- **`select()` now appends instead of replaces.** In v1.0, calling `->select([…])` overwrote any projection columns previously set (including aggregate helpers). That made fluent chaining brittle: `->select_literal('x', 'a')->select(['b.col'])` would silently drop the literal. v1.1 appends — consistent with how the aggregate-projection helpers (`select_count`, etc.) already behaved. Mixing regular + aggregate + literal + coalesce + aliased projections in any order now works as expected.
  - Breaking-change risk: very low. Calling `->select()` twice on the same builder is a pattern that has no legitimate use case — the v1.0 overwrite behaviour was never API the caller would intentionally rely on. Scanned all CB Base + CB Hub consumers before changing; no caller broke.

### Technical

- **Param-doubling tested.** Smoke-test verifies that `latest_per_group()` with WHERE clauses produces a param list of exactly `[inner_params, outer_params]` in that order, matching the compiled SQL's placeholder positions for `$wpdb->prepare()`.
- **All new helpers fail-closed under injection.** Ten new injection attempts added to the smoke test (bad column in aggregate helpers, bad alias in aggregate helpers, bad column in coalesce, bad value in literal, bad alias in literal, bad column in select_as, bad alias in select_as, bad column in group_by, bad args to latest_per_group). All ten rejected.
- **Three Hub consumers adopted.** `DB::get_last_backups`, `Activity::render` backup query, `Activity::render` notification query. After CB Hub fase 7c ships, CB Hub has zero native `$wpdb->get_*` / write calls outside of schema creation and table-name accessors.

### Notes

- **Transactions, raw-SQL escape, generic subqueries in WHERE clauses**: still out of scope. The two remaining gaps from v1.0 were specific patterns with clear Hub consumers. Both are filled. Future v1.2+ releases will wait for a second concrete Suite consumer to drive the design rather than pre-extending.

### Migration

- **No user action required.** All existing consumers (AuditLog, ConnectionLog, the Hub callers from fase 7b) continue working without changes.

---

## [1.0.15-dev] — 2026-04-21

QueryBuilder v1 release. The earlier single-table filter builder is declared beta; v1 is the first production-grade DB-access layer for the Core Blueprint Suite. Adds JOINs, IN-clauses, custom SELECT projections (including aggregate-as-column helpers), GROUP BY, aggregate terminals, a targeted "latest per group" subquery helper, batch INSERTs, and three new write-side builders (Insert/Update/Delete) with required-WHERE safeguards on the destructive ones. Consumers in CB Base (AuditLog, ConnectionLog) keep working without changes — all pre-v1 methods are preserved via a shared trait.

### Added

- **`CB\Core\DB\WhereClauses` trait** — the eight WHERE-clause builders (`equals_if_set`, `equals_enum_if_set`, `int_equals_if_set`, `like_if_set`, `starts_with_if_set`, `not_starts_with_if_set`, `gte_if_set`, `lte_if_set`) plus the new helpers below live in a shared trait now. Every fluent SQL builder (QueryBuilder, UpdateBuilder, DeleteBuilder) consumes it, so the WHERE API is identical across read and write paths.
- **`in_if_set( $column, array $values, 'int'|'string' )`** — typed IN-clause helper. Each value coerced to the declared type, parameterised via `$wpdb->prepare`, empty arrays skip the clause. Comes with a usage comment noting that `> 1000` values degrade MySQL's IN performance (temp-table JOIN recommended at that scale).
- **`not_equals_if_set( $column, $value, $sanitizer = null )`** — mirror of `equals_if_set()` for negated filters. Added during CB Hub's fase 7b migration when the backup notifier's threshold logic needed `status != 'started'`.
- **`gt_if_set` / `lt_if_set`** (string comparisons) and **`int_gt_if_set` / `int_lt_if_set`** (integer comparisons) — strict-inequality helpers for cases where `>=` / `<=` semantics (the existing `gte_if_set` / `lte_if_set`) don't match. Added during fase 7b when Notifier's "look back past the threshold window" needed `id < current_log_id`.
- **`where_not_null( $column )` / `where_null( $column )`** — unparameterised NULL predicates. No value to set or skip, so unlike the `*_if_set` helpers these always add the clause when called. Added during fase 7b for Reconciler's `filename IS NOT NULL` filter; the pattern also applies to future plugins (permissions without expiry, activations without deactivation).
- **JOIN support on `QueryBuilder`** — `join( $table, $alias, $on_left, $on_right )` for INNER JOINs, `left_join()` for LEFT JOINs. The ON condition is deliberately restricted to `{alias.col} = {alias.col}`; compound AND/OR conditions are rejected. Additional per-row filtering on joined tables goes through the WHERE helpers (`->int_equals_if_set('j.status', …)`). This is the "fail closed on JOIN syntax" security stance we chose during the design discussion — strict identifier validation over flexible SQL composition.
- **Custom SELECT projections** — `select( array $columns )` replaces the implicit `SELECT *` when you need aliased columns or joined-table columns. Each column validated against the same regex as WHERE columns (bare identifier or `alias.column`, one level of qualification).
- **Aggregate-as-column helpers** — `select_count( $column, $alias )` / `select_sum` / `select_avg` / `select_max` / `select_min`. These append `COUNT(col) AS alias` to the SELECT list, for queries that return aggregates alongside regular columns (e.g. "tags with their site-count"). Distinct from the aggregate *terminals* (`count()`, `sum()`, etc.) which execute a separate scalar query. Both column and alias validated via the strict identifier regex — no raw SQL in either slot.
- **Table aliases** — `new QueryBuilder( $table, $alias )` sets an alias for the primary table, automatically used in the FROM clause and implicit SELECT. Required when JOINing, because unqualified columns across multiple tables are ambiguous.
- **`group_by( ...$columns )`** — variadic GROUP BY. Each column validated; multiple calls extend rather than replace. Also usable as a DISTINCT workaround when paired with `get_col()` on the grouping column.
- **Aggregate terminals on `QueryBuilder`** — `sum()`, `avg()`, `max()`, `min()` alongside the existing `count()`. Each takes a column name; `max` and `min` return `?string` so callers can distinguish "no data" from "zero" in integer columns.
- **`latest_per_group( $max_column, array $group_columns )`** — a targeted helper for the classical greatest-n-per-group pattern. Generates the subquery-with-self-JOIN internally; the subquery is entirely builder-generated, so no raw SQL ever enters the query. NOTE: v1's generated subquery does not carry a WHERE filter — if the outer WHERE (e.g. `status = 'done'`) matters to which rows qualify as "latest", the helper is not yet semantically equivalent. Subquery-WHERE support is planned for v1.1.
- **`get_col( $column )`** — returns a single column as a flat array. Skips per-row object overhead when you only need one column's values.
- **`get_row( $output )`** — returns the first matching row or null. Convenience for the common "load by id" pattern without having to set `limit(1)` and pluck index 0 from `get_rows()`.

- **`CB\Core\DB\InsertBuilder`** — fluent INSERT construction with two modes:
  - Single-row via `->values( [ col => val, … ] )->execute()` returns the new row ID.
  - **Batch** via `->values_batch( [ [col=>val,…], [col=>val,…], … ] )->execute()` composes one multi-VALUES prepared statement, returns rows-inserted count. One round-trip instead of N — meaningful at scale (e.g. bulk-inserting 1000 invoice line items). All rows must share the same column set; mismatches rejected with `_doing_it_wrong`. Single and batch modes are mutually exclusive per builder instance; switching clears the other mode's state.
  - Each column key validated as a bare identifier (no aliases — INSERT writes into a single table). `$wpdb` handles format detection automatically, so callers don't hand-write the `%d`/`%s` formats array.
- **`CB\Core\DB\UpdateBuilder`** — fluent UPDATE with required WHERE. `->set( [ … ] )` adds columns, WHERE helpers via the shared trait. `execute()` refuses to run if no WHERE clause has been registered — the rock-solid safeguard against the classical "forgot the WHERE" category of bugs. Call `->match_all()` explicitly for the rare legitimate full-table update.
- **`CB\Core\DB\DeleteBuilder`** — fluent DELETE with the same required-WHERE safeguard. Same `match_all()` escape for explicit full-table purges.

### Changed

- **`CB\Core\DB\QueryBuilder` refactored to consume `WhereClauses`.** All existing WHERE methods (`equals_if_set`, `like_if_set`, etc.) now live in the trait. Consumers see no API change — same method names, same signatures, same return types. The only visible difference is that return types are now `static` (via the trait), allowing subclass-correct chaining in any future extensions.
- **Column validator regex updated** — `validate_column()` now accepts `alias.column` in addition to bare `column`. The alias half and the column half are both validated against the original identifier regex, so SQL injection surface doesn't widen; only one additional dot character is permitted, and only between two valid identifiers.
- **Existing consumers unchanged.** `AuditLog::query()` and `ConnectionLog::query()` continue to work byte-for-byte identically. No call-site changes needed.

### Technical

- **"Fail closed" as the consistent security principle.** Every design choice was made toward the more restrictive option: strict column validation, strict JOIN ON patterns, required WHERE on destructive operations, no raw SQL escape hatches. The builder prefers rejecting questionable inputs over attempting partial sanitization.
- **PHP 8.0+ features used**: `static` return types in traits (for correct late-static-binding on fluent chains), constructor-level strict typing via property declarations, `final class` markers on all builders. Matches the plugin's `Requires PHP: 8.0` minimum.
- **Smoke-tested SQL assembly.** Before shipping, the builders were exercised against a fake `$wpdb` stub to verify the compiled SQL for simple SELECT, JOIN (INNER + LEFT), IN, latest_per_group, COUNT, aggregate-as-column projections, INSERT (single + batch), UPDATE (with/without WHERE), and DELETE (with/without WHERE). Plus injection attempts via malicious column names and malicious aggregate aliases — all confirmed rejected. Tests live in the build journal, not shipped.
- **No breaking changes, by design.** The pre-v1 QueryBuilder is considered beta but its API is preserved because it was already adopted by two log pages. Future releases can extend; this release doesn't remove.
- **Shared trait architecture.** `WhereClauses` is the single place WHERE-logic lives. Bug fixes and new filter helpers benefit QueryBuilder + UpdateBuilder + DeleteBuilder simultaneously — no three-way code duplication.

### Notes

- **Subqueries: deliberately narrow in v1.** Only `latest_per_group()` exposes the subquery pattern. Generic subquery support (subquery-in-WHERE, scalar subquery, nested builders) is out of scope — the design consideration was that a generic subquery interface vastly expands attack surface for marginal additional utility, since JOINs + the targeted helper cover the real-world use cases. If a specific subquery pattern later recurs across the Suite, the path is a new named helper (like `exists_in()`) rather than a general subquery builder.
- **Transactions and raw-SQL escape: also out of scope for v1.** Transactions should stay at the `$wpdb` level for now — a Transaction wrapper is a larger design with cross-builder coordination concerns. Raw-SQL escape hatches are pointedly excluded: anyone who needs raw SQL should use `$wpdb` directly with a comment explaining the exception.
- **Upcoming consumers.** Core Blueprint Hub 1.0.15-dev+ will migrate its DB layer to v1 in its own fase-7b release. Other Suite plugins (Invoice, License, Access Control, Protected Content) will adopt when they come up for refactoring.

### Migration

- **No user action required.** The pre-v1 QueryBuilder consumers (AuditLog, ConnectionLog) remain fully backwards compatible. Install the updated ZIP; log pages continue to work identically.

---

## [1.0.15-dev] — 2026-04-21

Logs governance release. Brings the four log pages under one layout, and adds a first-class Plain/Technical toggle so the same audit trail reads two ways: technical for developers and auditors, plain for care-home administrators, municipal clerks, and anyone without the domain vocabulary.

### Added

- **`CB\Core\Log\Language` helper class** — single source of truth for plain-language rendering of log rows. Three public methods:
  - `describe_event( $event_type, $context, $mode )` — turns "system.plugin_activated" + `{plugin: "Yoast SEO"}` into "Plugin 'Yoast SEO' was activated"
  - `describe_endpoint( $method, $path, $mode )` — turns "GET /status" into "Hub status check"
  - `describe_status_code( $code, $mode )` — turns 200 into "Succeeded", 403 into "Blocked (not allowed)"
  - Ships with a 30+ event catalog covering plugin/theme/core lifecycle, settings, failsafe, login, privacy, diagnostic, audit, and access-mode events. A 10-endpoint catalog for Beacon's REST surface. An HTTP status catalog for the common 2xx/4xx/5xx codes with sensible family-level fallbacks for unknown codes.
  - Missing translations fall back gracefully to the technical form — a new event type that ships before its Plain label still renders, it just shows the slug until the catalog is extended.

- **Plain/Technical toggle on every log page** — Audit, System, Connection, and Maintenance Report each get a pill-switch at the left of the filter bar. One click flips the whole page between the two reading modes. The toggle writes to the existing site-wide/user-scoped description-mode preference (the same one that controls the Security pages), so switching on Logs also affects Security, and vice versa — one coherent "how do I want this explained to me" setting across the whole plugin.

- **Sticky filter bar** — `.cb-core-log-filters-wrap` is now `position: sticky` pinned 32px under the WordPress admin bar (46px on mobile viewports). When you scroll past the chart into a long events table, the filter bar — including the Plain/Technical toggle — stays visible. No more scrolling back to the top just to switch modes or change a filter. Works in all modern browsers, no JS required for the sticky behaviour.

### Changed

- **Canonical log page layout** — all four log pages now follow the same flow:
  ```
  Title → Description → (KPI strip, Maintenance Report only) → Chart → Sticky filters → Table
  ```
  Previously: Audit, System, and Connection had filters *above* the chart while Maintenance Report had them *below*. The new uniform flow puts the overview (chart) first, the interactive controls (filters + toggle) together as one sticky unit, and the detail (table) last. Matches scanning hierarchy — trend, decision, detail.

- **Section-wrapper spacing** — `.cb-core-section` wrappers around chart, filters, and table give consistent vertical rhythm. Fixes the "table slammed against the chart" visual bug across all four log pages.

- **`AuditLog::event_label( $event_type, $mode = 'technical' )`** — BC-safe extension. Default behaviour unchanged (returns the short technical label, "Plugin activated"). Passing `$mode = 'plain'` delegates to `Language::describe_event()` for the full sentence. External callers that omit the argument continue to work identically.

- **`MaintenanceReport::collect_local_source()`** and **`Beacon\Log\MaintenanceSource::collect()`** — both now emit three description keys per row:
  - `description` — stays as the technical form, BC-preserved for CSV exports and third-party listeners
  - `description_technical` — explicit technical variant (same value as `description`)
  - `description_plain` — rendered through the Language helper; falls back to the technical form when a row's event has no plain translation
  The Maintenance Report template picks the right variant at render time based on `UI::current_mode()`.

- **Connection Log Action + Status columns** — plain mode replaces `GET /status` with "Hub status check", `200` with "Succeeded", `403` with "Blocked (not allowed)". Technical mode is unchanged. Raw status code stays available as a tooltip in plain mode for anyone who wants to confirm the underlying value.

- **Audit + System Log Event column** — plain mode hides the raw `event_type` slug and shows the plain sentence directly. Technical mode shows both the slug and the short technical label underneath, unchanged from before.

- **Audit + System Log Context column** — plain mode replaces the `key=value · key=value` preview with a human attribution string: "by admin:digitaalbeheer · reason: routine maintenance". Technical mode keeps the raw pair-list preview. Full raw context remains available as a tooltip in both modes — AVG-compliant, nothing is hidden.

### Notes

- **No data is lost in Plain mode.** The toggle only changes jargon-to-prose packaging. Duration, source IP, actor, status, severity badges — every field of AVG audit value stays visible. Raw status codes and raw event_types are accessible via tooltip even in Plain mode. CSV export is unchanged — exports always use the technical description, because auditors downstream of the export want machine-grep-able values.

- **Mode preference is user-scoped, site-configurable.** Each user can pick their own preference via the Appearance → Language page; admins can set a site default there too. A care-home administrator's account keeps seeing Plain; the same site's IT contractor's account keeps seeing Technical. No fiddling per session.

- **Layout changes are view-layer only.** Zero changes to queries, DB schema, or how log rows are stored. Zero BC-breaking API changes. The only data change is additive: `MaintenanceReport` rows now include two new description keys alongside the original.

- **Security page unchanged.** The Plain/Technical infrastructure has been in `UI` since 1.0.0 and was already used by the Security modules. This release extends the same infrastructure to the Logs pages — no second system, no duplicated state.

- **Extensibility.** New event types added to `AuditLog` or `SystemLog::BUILTIN_TYPES` get Plain support by adding an entry to `Language::EVENTS_PLAIN`. New Beacon endpoints: add to `Language::ENDPOINTS_PLAIN`. New HTTP codes: add to `Language::STATUS_PLAIN`. The catalogs are ordinary class constants — no registration ceremony, no filters to remember, just edit one file. Third-party extensions that ship their own events will render as the technical slug in Plain mode until they add their own translations; we document this in the class docblock.

---

## [1.0.14-dev] — 2026-04-21

Hotfix release. Resolves a WordPress 6.7+ notice that appeared on paired sites: `_load_textdomain_just_in_time was called incorrectly — translation loading for the 'core-blueprint' domain was triggered too early`.

### Fixed

- **Translation timing in `Beacon\Bootstrap::boot_paired_hooks()`** — `TabRegistry::register( … 'label' => __( 'Connection', 'core-blueprint' ) … )` was resolved on `plugins_loaded` priority 4, before the `init` action where WordPress 6.7+ requires custom text-domain strings to be available. The Logs tab label (and the Maintenance Report source registration, grouped with it for symmetry) now move to a new `Bootstrap::register_admin_contributions()` callback hooked on `init`. The timing-critical pieces — schema registry, retention pruner, legacy cron binding — stay on `plugins_loaded` priority 4 because they run before `DB::maybe_upgrade()` at priority 5 and don't touch translations.

### Notes

- **Paired-site only.** Unpaired installs never triggered the notice because `boot_paired_hooks()` returns early when no secret key is configured. The fix still applies universally — next time the site pairs, registration goes through the init-bound path.
- **No other pre-init `__()` call-sites in Core Blueprint code paths.** Audited every `add_action( 'plugins_loaded', … )` target: `DB::maybe_upgrade`, `SettingsMigrator::maybe_migrate`, `Retention::init`, `EmailAlerts::init`, `AccessMode::boot`, `SystemLog::boot`, `ModuleRegistry::boot`, plus `Beacon\Bootstrap::boot_paired_hooks`. Only the last one touched translations. The requirement-error strings in `core-blueprint.php` are gated by PHP-version + Sodium-extension checks and never execute on correctly-configured servers. Module `boot()` methods for `Fingerprint` and `Headers` don't resolve translated strings at all.
- **Back-compat: nothing changes for callers.** `Bootstrap::boot_paired_hooks()` is an internal callback; nobody was calling it from outside. `register_admin_contributions()` is new and internal. The Connection tab registration and MaintenanceReport source registration happen at a slightly later tick than before, but neither subsystem is usable before `init` anyway — the Logs page renders on `admin_menu` (after init) and the Maintenance Report is a page-time query.

---

## [1.0.13-dev] — 2026-04-21

Leftover cleanup release. Sweeps the two loose ends that were flagged as optional during releases A–E and left in place because they were idempotent: redundant `CacheBypass::apply()` calls in Beacon's REST routes, and the AJAX handlers that hadn't yet adopted the `Request` helper from Release C.

### Removed

- **12 per-handler `CacheBypass::apply()` calls** across `src/Beacon/Backup/Routes.php` (9 handlers) and `src/Beacon/Updates/Routes.php` (3 handlers). Release A wired `CacheBypass::register()` into `rest_pre_dispatch` for the `core-blueprint/v1` namespace, making every per-handler call redundant. The calls were left in place as a belt-and-braces safety net; this release sweeps them out now that the auto-apply has been running in production through four releases. Orphan `use CB\Core\Beacon\CacheBypass;` imports cleared at the same time.

### Changed

- **`src/Ajax/Handlers/Settings.php`** — 7 handlers migrated to `Request`. `check_ajax_referer` + inline `isset/sanitize_key` + `in_array` → `Request::nonce` + `Request::sanitize_key('field', $allowlist)` + `Request::bool`. File size: 173 → 149 lines.
- **`src/Ajax/Handlers/Failsafe.php`** — 4 handlers migrated. `panic_activate` preserves the null-vs-empty-string semantics its audit-log entry expects: Request helper returns `''` for absent fields, handler normalises back to `null` inline.
- **`src/Ajax/Handlers/Privacy.php`** — `apply_preset` fully migrated with allowlist enum check. `save_privacy` keeps explicit `$_POST` access because it reads nested arrays (`$_POST['verbosity'][$category]`, `$_POST['retention'][$category]`) — Request helpers are designed for scalar fields, nested structures stay as caller responsibility. Nonce-check upgraded.
- **`src/Ajax/Handlers/Preferences.php`** — `header_test` and `set_description_mode` migrated. The description-mode handler has scope-dependent allowlists (`UI::MODES` for site, `UI::USER_MODES` for user) — two-stage pattern: first resolve `$scope`, then pick the right allowlist, then call `Request::sanitize_key('mode', $allowed_modes)`. Cuts the handler from 38 to 28 lines without losing any of the scope-aware error reporting.
- **`src/Ajax/Handlers/Exports.php`** — nonce calls upgraded to `Request::nonce`. Filter args stay with explicit `$_GET` reads because Exports serves `$_GET`-bearing download links, not POST requests. `Request::text/bool/sanitize_key` read `$_POST` by design.
- **`src/Security/AccessMode.php`** — `ajax_set_mode` migrated. Fully-qualified `\CB\Core\Ajax\Request::` calls rather than a `use` import because this class already has a generous import list and one-off AJAX usage didn't warrant another.

### Not changed

- **`src/Beacon/Log/Admin.php::handle_export`** — uses `check_admin_referer` + `$_GET` + `wp_die` for a CSV streaming download, not an AJAX endpoint. Out of scope for `Request`.
- **`src/Beacon/Updates/Worker.php`** — uses a single-use secret-token worker callback pattern, not a user-session AJAX handler. Out of scope.

### Notes

- **No functional changes** anywhere in this release. Every migrated handler accepts the exact same request shape and returns the exact same response shape it did pre-1.0.13. Error messages for invalid input become slightly more generic ("Invalid value for mode." instead of "Invalid site mode.") but remain clear and user-facing.
- **CacheBypass cleanup is observable** in one specific way: handlers that previously called `CacheBypass::apply()` multiple times per request (once at the top, implicitly once more via the rest_pre_dispatch filter) now call it once per request. Cache headers are unchanged — the duplicate calls were always idempotent. Performance impact is negligible but measurable if you were profiling.
- **Back-compat unchanged.** External code that calls `CacheBypass::apply()` directly (third-party extensions hooking into our REST routes) still works. External code that subscribed to AJAX actions still works. External code that inherited from any of the migrated handler classes — there aren't any, they're all `final` — wouldn't have been supported anyway.
- **Cleanup completeness.** With this release, all five items from the original architecture scan are closed: (A) Beacon integration-seams, (B) module base class, (C) scoped preferences + Ajax\Request, (D) tab registry + log partials, (E) query builder. The optional sweep-up items flagged during those releases (redundant CacheBypass calls, unmigrated Ajax handlers) are now also done. The suite enters 1.0 Milestone territory with a clean architectural slate.

---

## [1.0.12-dev] — 2026-04-21

Query builder release. Consolidates the WHERE-clause construction that was duplicated across log classes and gives new tables in Core Blueprint a reusable foundation for their own filtered queries.

### Added

- **`CB\Core\DB\QueryBuilder`** — fluent SELECT builder designed for Core Blueprint's log-style tables. Chainable WHERE helpers add a clause only when the input is meaningful:
  - `equals_if_set( $column, $value, $sanitizer = null )` — exact string match with optional sanitizer
  - `equals_enum_if_set( $column, $value, $allowed )` — exact match from allowlist, silently drops stale values
  - `like_if_set( $column, $value )` — `column LIKE '%value%'` with esc_like protection
  - `starts_with_if_set( $column, $value )` — `column LIKE 'value%'`
  - `not_starts_with_if_set( $column, $value )` — `column NOT LIKE 'value%'`
  - `int_equals_if_set( $column, $value )` — integer comparison, treats 0 as "not set"
  - `gte_if_set( $column, $value )` / `lte_if_set( $column, $value )` — range bounds
  - Terminal methods: `count()`, `get_rows( $output = OBJECT )`, `get_paginated( $page, $per_page, $output = OBJECT )`
- **Column validation** — every WHERE helper runs its column name through a strict identifier regex (`^[A-Za-z_][A-Za-z0-9_]*$`). Column names are interpolated into the SQL text (they can't be parameterized by `$wpdb->prepare`), so the builder rejects anything that isn't a plain identifier. Closes a class of potential SQL-injection bugs by design, not by convention.

### Changed

- **`AuditLog::query()` rewritten against QueryBuilder**. 100 lines of manual `$where = ['1=1']` / `$params = []` plumbing collapsed into a single fluent chain. Behaviour unchanged — same filter arguments, same return shape (`rows`, `total`, `page`, `per_page`), same context_decoded population.
- **`ConnectionLog::query()` + `ConnectionLog::count()` rewritten against QueryBuilder**. Their filter set is shared via a new private `apply_common_filters()` helper so adding a new filter field happens in one place instead of two. Total: ~130 lines of duplicated WHERE-building reduced to a six-line helper.

### Notes

- **Public API unchanged.** `AuditLog::query()`, `ConnectionLog::query()`, `ConnectionLog::count()` accept the exact same arguments and return the exact same structures they did in 1.0.11. External callers (templates, CLI commands, third-party extensions that peek at the log) continue to work without any modification.
- **Security hardening.** Pre-1.0.12 every log class assembled its own WHERE clause and every class had to remember to: (a) parameterise values through `$wpdb->prepare`, (b) `esc_like()` before LIKE wildcards, (c) never interpolate unvalidated column names into SQL. The builder centralises all three — new tables that adopt it get them for free, and the audit surface for SQL injection shrinks to a single file.
- **Tunable caps.** `QueryBuilder::paginate()` caps `per_page` at 500 by default (matches AuditLog's pre-existing behaviour); callers that need a different cap pass it as the third argument. `::limit()` provides uncapped access for cases that genuinely need it (bulk export, diagnostic CLI commands).
- **Reusable foundation for future CB plugins.** Access Control, Protected Content, Invoice-activity and future logs can adopt QueryBuilder directly. A `query()` method on a new CB log table is now typically 15-20 lines: wp_parse_args defaults, a fluent chain, optional JSON decoding. The pattern matches the rest of the Core Blueprint suite (schema registry, retention registry, tab registry, etc.): a small primitive that subsystems compose into their own features.

---

## [1.0.11-dev] — 2026-04-21

Tab registry + template partial release. Decouples the Logs page from the specific subsystems that contribute tabs, and folds the duplicated audit-style log template into a shared partial.

### Added

- **`CB\Core\Admin\Pages\Logs\TabRegistry`** — open registry for Logs page tabs. `register( $slug, $spec )` accepts a label, priority, optional `condition` callback, and a renderer callable. `visible()` returns tabs that pass their condition, sorted by priority. Any CB plugin or subsystem can contribute a Logs tab without the page itself needing to know about it.
- **`CB\Core\Admin\TabNav`** — static helpers (`inject()`, `build()`, `render_subsystem_missing()`) so tab renderers can compose the WP nav-tab-wrapper without instantiating a page class. The existing {@see Tabbed} trait remains in place for page-class consumers.
- **Built-in tab classes** in `CB\Core\Admin\Pages\Logs\Tabs`:
  - `AuditTab` (priority 10, always visible)
  - `SystemTab` (priority 20, requires `SystemLog` class)
  - `MaintenanceTab` (priority 40, requires `MaintenanceReport` class)
- **`CB\Core\Beacon\Admin\LogsTab\ConnectionTab`** — Beacon's Connection Log tab, registered from `boot_paired_hooks()` with priority 30. Only registered when paired, so the tab naturally disappears on unpair. Lives inside the Beacon namespace — the subsystem owns its own tab.
- **`CB\Core\Beacon\Log\MaintenanceSource`** — Beacon's contribution to the Maintenance Report's source registry. Also registered from `boot_paired_hooks()`, also paired-only. Extracted from what used to be a private method inside `MaintenanceReport` that hardcoded Beacon knowledge.
- **`templates/partials/log-events-page.php`** — shared partial for audit-style log viewers. Renders the event/severity/period filter bar, activity chart, event table, and pagination given a normalized result shape.

### Changed

- **`Logs.php` rewritten as a registry-driven dispatcher** — no longer hardcodes the list of tabs, no longer knows about Beacon, no longer contains inlined `render_*_tab()` methods. The page iterates `TabRegistry::visible()`, builds the tab label map, resolves `?tab=` against the visible set, and hands off to the active tab's renderer. Fires the new `cb_core_logs_register_tabs` filter before rendering so late-arriving extensions can still register.
- **`MaintenanceReport::ensure_sources_initialized()` no longer knows about Beacon** — the built-in `connection_log` source is gone. MaintenanceReport now only registers its own `system_log` source internally; Beacon registers `connection_log` externally through the existing `register_source()` API. File size: 717 → 639 lines.
- **`templates/audit-log.php`** — 216 lines → 27 lines. Now a thin wrapper that sets the parameters unique to the Audit tab (title, placeholder, tab slug, export class) and includes the shared partial.
- **`templates/system-log.php`** — 196 lines → 36 lines. Same pattern as audit-log.php; normalizes `$sys_result`/`$sys_args`/`$sys_chart_daily` to the canonical names before including the partial.

### Notes

- **Inversion of control.** Pre-1.0.11 the Logs page asked "is Beacon paired?" to decide what to render. Post-1.0.11 Beacon tells the Logs page "here's a tab for you, if you want it". Same logical behaviour, but the dependency arrow now points the right way: subsystems know about the platform, the platform doesn't know about subsystems.
- **Open for extension.** Future CB plugins (Invoice, Access Control, Protected Content) can contribute Logs tabs by calling `TabRegistry::register()` from their own bootstrap. Example (see the AuditTab source for the full shape): `TabRegistry::register( 'invoice-activity', [ 'label' => __( 'Invoice activity', 'cb-invoice' ), 'priority' => 50, 'renderer' => [ InvoiceLogTab::class, 'render' ] ] );`
- **Template consolidation.** Total lines across audit-log.php + system-log.php went from 412 to 289 (partial included). More importantly: changes to the filter UI now happen in one place. When the filter bar needs a date-range picker, a user-ID autocomplete, or a different severity control, we edit one file and both tabs pick it up.
- **No wire-protocol or data change.** URL shapes (`?tab=audit`, `?tab=system`, `?tab=connection`, `?tab=maintenance`) are unchanged. Filter parameters are unchanged. The partial reads exactly the same data structures the old templates did.
- **Back-compat preserved.** The `Tabbed` trait still exists — Safeguards page still uses it. The deprecated `Bootstrap::is_paired()` delegate still works. External code that filtered `cb_core_maintenance_sources` still sees the `system_log` built-in and whatever Beacon has added; the filter signature is unchanged.

---

## [1.0.10-dev] — 2026-04-21

Preferences + AJAX helpers release. Targets the persistent DRY debt in how Core Blueprint stores user-override + site-default preferences and how AJAX handlers open their requests.

### Added

- **`CB\Core\Preferences\ScopedPreference` trait** — consolidates the identical `set_user()` / `set_site_default()` / raw-accessor shape that Themes and Locale both implemented independently. Consumers declare three class constants (`USER_META_KEY`, `SITE_OPTION_KEY`, `AUDIT_EVENT`) plus two methods (`is_valid()`, `site_changed_action()`). The trait handles the write-path, the audit-log entry on change, and the action-hook fire. Future preferences adopt the trait instead of re-implementing the boilerplate.
- **`CB\Core\Ajax\Request` helper class** — typed input + nonce + capability helpers that terminate the request with `wp_send_json_error()` on failure. Handlers can open with `Request::nonce('cb_core_theme'); Request::cap('manage_options'); $mode = Request::sanitize_key('mode', $allowed)` and be guaranteed the rest of the handler runs on validated input. Four methods cover the common cases: `sanitize_key()`, `text()`, `bool()`, `int()`, plus preamble guards `nonce()` and `cap()`.

### Changed

- **`Themes` adopts `ScopedPreference`**. The 35 lines covering `set_user()` + `set_site_default()` are gone; the trait provides them via `static::USER_META_KEY` / `static::SITE_OPTION_KEY` / `static::AUDIT_EVENT` constants that `Themes` now declares. `is_valid()` delegates to `is_known()` (plus `AUTO_MODE`). `user_preference()` and `site_default()` kept as thin BC wrappers over the trait's raw accessors — external callers don't need to change. Class size: 373 → 341 lines.
- **`Locale` adopts `ScopedPreference`**. Same shape of migration as Themes. `is_valid()` delegates to `is_allowed()`. Class size: 223 → 198 lines.
- **`Ajax\Router` collapsed**. `set_theme()` and `set_locale()` were 40 lines each of duplicated scope-dispatch code. Replaced with thin delegates to a shared `dispatch_scoped_preference( $nonce_action, $target_class )` method that works for any `ScopedPreference` consumer. File size: 117 → 96 lines. Adding another scoped preference's AJAX endpoint in the future is now a one-line method + one-line `add_action` registration.

### Notes

- **`UI` description-mode preference intentionally NOT migrated.** It has meaningfully different semantics: a `sync` mode that behaves differently at user vs. site level, an `inherit` user-level keyword for fall-through, and no server-side validation against a dynamic registry. Wedging it into `ScopedPreference` would require escape hatches that would pollute the trait's contract. UI stays as-is; if a fourth preference comes along that fits the pattern, it adopts the trait directly.
- **No wire-protocol change.** The AJAX endpoints `cb_core_set_theme` and `cb_core_set_locale` accept exactly the same request shape (nonce, scope, value) and return exactly the same response shape (scope, value, current). The refactor is strictly internal — JavaScript callers work unchanged.
- **No data migration.** Option keys and user-meta keys are unchanged. The trait reads/writes the same keys the old explicit methods did.
- **Public API compatible.** `Themes::set_user()`, `Themes::set_site_default()`, `Locale::set_user()`, `Locale::set_site_default()` still exist — they're now provided by the trait but have identical signatures. External extension code continues to work.
- **The other 22 AJAX handlers** in `Ajax\Handlers\*` were not migrated in this release. They can adopt `Request` incrementally in a follow-up; the helper is designed to coexist with the older inline `check_ajax_referer` / `isset($_POST[…])` style.

---

## [1.0.9-dev] — 2026-04-21

Module base class release. Targeted cleanup of the slug-repetition pattern in security modules.

### Added

- **`CB\Core\Security\AbstractModule`** — optional convenience base class implementing the {@see Module} interface. Provides two pieces of housekeeping every module needs:
  - A `protected function feature( string $id ): bool` helper that checks enablement via `ModuleRegistry::is_feature_enabled()` using `$this->slug()` as the slug. Callers write `$this->feature('remove_wp_version_meta')` instead of `ModuleRegistry::is_feature_enabled( 'fingerprint', 'remove_wp_version_meta' )` — slug lives in one place (the `slug()` method) rather than repeated on every call-site.
  - A default `badges()` returning `[]` so modules without module-level badges don't need to implement an empty method.

### Changed

- **`Fingerprint` extends `AbstractModule`** (was: implements `Module`). All 8 hardcoded `ModuleRegistry::is_feature_enabled( 'fingerprint', 'xxx' )` call-sites migrated to `$this->feature( 'xxx' )`. Unused `Module` + `ModuleRegistry` imports removed.
- **`Headers` extends `AbstractModule`** (was: implements `Module` with a private `feature()` wrapper of its own). The 9 `$this->feature( 'xxx' )` call-sites continue to work identically — they now resolve to the inherited base-class method. Unused `Module` + `ModuleRegistry` imports removed, and the redundant private `feature()` method deleted.

### Notes

- **Extending `AbstractModule` is optional.** Third-party CB plugins that implement `Module` directly continue to work unchanged — the registry only cares about the interface. `AbstractModule` is purely a convenience for modules that want the `feature()` helper and badges default.
- **No functional change.** `Fingerprint` and `Headers` behave identically to 1.0.8-dev. Enablement checks route through the same `ModuleRegistry::is_feature_enabled()` — only the call-site syntax changed.
- **Slug lives in one place per module now.** Previously `Fingerprint` had the string `'fingerprint'` hardcoded 8 times; a rename would've required 8 coordinated edits. Same for `Headers`. The base class reads `$this->slug()` once per feature check, so the class's `slug()` method is the single source of truth.

---

## [1.0.8-dev] — 2026-04-21

Beacon integration-seams release. Beacon was originally a standalone plugin that was merged into Core Blueprint; this release closes out the architectural seams that remained from that merge. Purely structural — no user-visible behaviour change, no data migration required, full backwards-compatibility preserved.

### Added

- **`CB\Core\Beacon\Pairing` facade** — single, canonical home for "is this site paired with a Hub?". Previously this check lived on `Bootstrap::is_paired()`; now it's a dedicated class that the rest of the plugin talks to. Exposes `Pairing::is_active(): bool`.
- **Schema registry on `CB\Core\DB`** — subsystems with their own tables register via `DB::register_table( $key, $spec )` where `$spec` supplies a version string, option key, install callable, and exists-check callable. `DB::maybe_upgrade()` walks the registry on `plugins_loaded` priority 5 and migrates as needed. Audit log registers itself by default; Beacon's Connection Log registers via its own `register_schema()` method.
- **Pruner registry on `CB\Core\Log\Retention`** — subsystems register prune callbacks via `Retention::register_pruner( $key, $callback )` and the daily `cb_core_daily_prune` cron runs every registered pruner in sequence. Aggregate deletion counts land in a single audit-log entry keyed by pruner slug.
- **`CacheBypass::register()`** — binds `rest_pre_dispatch` once during Beacon bootstrap so every inbound `core-blueprint/v1` request automatically gets no-cache headers applied. Removes the need for per-handler `CacheBypass::apply()` calls. Idempotent — handlers that still call `apply()` explicitly remain safe.

### Changed

- **Beacon bootstrap wired through `Core::init_hooks()`** instead of being called directly from the main plugin file. The boot sequence is now fully owned by one place; `core-blueprint.php` is leaner.
- **Beacon's paired hooks register earlier** — `boot_paired_hooks()` moved from `plugins_loaded` priority 5 to priority 4, so Connection Log has registered its schema by the time the unified `DB::maybe_upgrade()` runs at priority 5. Same migration sweep now covers all Core Blueprint tables.
- **`Retention::run()` covers Connection Log** — the separate retention responsibility is gone. The daily cron prunes the audit log and every registered pruner (currently: Connection Log when paired). Removed the "this stays Beacon's responsibility" comment.
- **Connection Log's install split into two methods.** `install_schema()` is a pure dbDelta runner used by the DB registry; `install()` is kept as a back-compat wrapper that short-circuits on matching versions and writes its own version option — covers SettingsPage's pair-time install path unchanged.

### Deprecated

- **`CB\Core\Beacon\Bootstrap::is_paired()`** — now delegates to `Pairing::is_active()`. External extension code using the old call continues to work; internal call-sites (dashboard template, Logs page, MaintenanceReport, Bootstrap itself) have been migrated.
- **`ConnectionLog::schedule_cron()`** — a no-op in 1.0.8+. The unified `cb_core_daily_prune` cron handles Connection Log pruning via the pruner registry. The separate `cb_core_beacon_connection_log_prune` cron is no longer scheduled on new installs; legacy installs that still have it scheduled continue to work (the hook binding is kept) until Lifecycle deactivation clears it.

### Notes

- **No data migration needed.** Existing option keys (`cb_core_db_version`, `cb_core_beacon_connection_log_db_version`) are preserved via the schema registry's `option_key` field, so upgrading sites don't re-run installs unnecessarily.
- **Back-compat surface** — three deprecated entry points are kept as delegates for one release cycle: `Bootstrap::is_paired()`, `ConnectionLog::install()` (still short-circuits and bumps its own version option), `ConnectionLog::schedule_cron()` (no-op). Safe to call from external extensions that were written against 1.0.7 or earlier.
- **Idempotent `CacheBypass::apply()`** — documented as idempotent in the class docblock, verified by inspection. The per-handler calls in `Beacon/Backup/Routes.php` (7 call-sites) and `Beacon/Updates/Routes.php` (further call-sites) are now redundant but left in place. They cost a few nanoseconds each and remove one class of regression risk ("did someone forget to call apply() in a new handler?"). A future release may sweep them out.

---

## [1.0.7-dev] — 2026-04-21

Plugin-wide cleanup pass. Closes out residue from the prototyping phase. No functional regressions intended — every user-visible feature behaves identically. Several latent bugs fixed along the way.

### Fixed

- **Theme's Site Mode submenu was never actually suppressed.** `Admin::remove_duplicate_submenu()` guarded the `remove_submenu_page()` call with `class_exists( AccessMode::class )` but `AccessMode` wasn't imported — the unqualified reference resolved to `CB\Core\Admin\AccessMode` (non-existent), so the guard was always false and the submenu hiding never fired. The check is now unconditional; `AccessMode` is an owned class and always available.
- **`uninstall.php` left stale state behind on plugin delete.** Four option keys were missing (`cb_core_retention`, `cb_core_last_version`, `cb_core_beacon_connection_log_db_version`, `cb_core_beacon_connection_log_retention_days`) and one was a bogus key (`cb_core_beacon_db_version`) that nothing sets. Cleanup is now exhaustive.
- **Dead code in `Router::set_theme()`.** A `$cap` variable was computed via `apply_filters( 'cb_core_menu_capability', ... )` and then never used. Removed.
- **Unused imports in three classes.** `PageRegistry` imported `Core`, `Failsafe` imported `Admin` and `Command`, `Admin` imported `Anonymizer` and `Verbosity` — none were referenced. Removed.

### Changed

- **AccessMode fully decoupled from the Core Blueprint Theme.** Option key migrated from `cbt_site_mode` (shared with the theme) to the Core Blueprint–owned `cb_core_access_mode`. The legacy `cbt_site_mode_changed` action broadcast is gone; only `cb_core_access_mode_changed` fires now. A one-shot migration in `AccessMode::boot()` copies the value from the legacy key on first run and deletes the legacy key — existing Admin-Only sites keep their state automatically.
- **`Settings::effective_hardening_mode()` simplified.** The `site_mode === 'development'` legacy override is gone. Method is now pure AccessMode-driven: `admin_only` → `'hub'`, else `'production'`. Site Mode as a feature (the AJAX handler, CLI output, templates, `Settings::site_mode()`) is unchanged.
- **`cb_ch_admin_themes` compat shim removed.** Control Hub 2.x / 3.x partner-theme compatibility is dropped. Only the modern `cb_admin_themes` filter is honoured.
- **Beacon settings group renamed** from `cb_beacon` to `cb_core_beacon` for naming consistency. Settings API internal, no user-facing impact.

### Removed

- **All residue from earlier naming phases.** "CB Foundation" / "CB Security" / "CB Control Hub" strings in user-facing copy (email bodies, WP-CLI output, admin dialogs, template helptexts), internal comments/docblocks, section-markers in `admin.css` and `admin.js`, and stale identifiers (`cb-fnd`, `cb-control-hub`, `CB_FND_EXPANDED_KEY`, `CB_Fnd_Audit_Log`, `CB_Beacon_Connection_Log`, `FoundationSettings` alias). User-facing strings in admin screens drop the product prefix entirely (e.g. `All restrictive security features` instead of `All restrictive CB Foundation features`); emails and CLI output keep the `Core Blueprint` prefix since they live outside the admin UI context.
- **`HeaderTest` User-Agent** updated from `CB-Security/…` to `CoreBlueprint/…`.
- **Stale screen-ID checks** in `Themes::on_cb_admin_screen()`: dropped `cb-fnd` (no slug ever used it) and the duplicate `core-blueprint` check; renamed `cb-control-hub` to `cb-hub`.
- **Legacy label "legacy"** removed from UI description and Module docblocks. The `string | [plain, technical]` dual-format is documented as a coulant API, not a backwards-compat artifact.

### Notes

- Sites upgrading from earlier dev builds with `admin_only` access mode enabled will keep it enabled — the one-shot migration handles the key rename transparently on the next admin request.
- The Core Blueprint Theme has not been updated as part of this release. If both the plugin and the theme are active and both handled access mode in older versions, the plugin now owns it exclusively and the theme's Site Mode submenu is hidden.

---

## [1.0.6-dev] — 2026-04-20

### Fixed

- **"Privacy & Logging" page heading rendered in a different style** from the Appearance and Language tab headings. Cause: the `<h1>` in `templates/privacy.php` was missing the `cb-core-title` class that the other Preferences-tab templates use. Added. Headings across all Preferences tabs now share the same typographic treatment.

---

## [1.0.5-dev] — 2026-04-20

### Fixed

- **"Description style" setting was duplicated** between Safeguards → Overview and Preferences → Language. Both UIs wrote to the same backend option (`cb_core_description_mode_default`) via the same AJAX handler, so they weren't two independent settings — just two entry points to one value. The duplicate was legacy from before the 1.0.1-dev menu restructure, when Safeguards didn't yet exist as a separate page and this preference lived with the security/hardening UI. Description style is a user-preference for how UI copy is displayed, not a safeguard, so the Preferences → Language placement is the correct one. The Safeguards copy has been removed; the Preferences → Language tab is now the sole place to change it.
- The underlying `$active_variant` computation is preserved on the Safeguards Overview template — module and feature blocks still need to know whether to render their plain or technical descriptions. Only the UI controls to change the preference were removed.

---

## [1.0.4-dev] — 2026-04-20

Dashboard copy + layout cleanup.

### Changed

- **"No extensions detected" empty state shortened.** Was: _"No Core Blueprint extensions detected yet. Install Hub, Beacon, or any sibling plugin and it will show up here."_ Now: _"No Core Blueprint extensions detected."_ The instructional sentence was noise — the user either has extensions or doesn't, and the shorter line reads better in the layout.
- **Maintenance Report moved into Quick links.** It was previously rendered above Quick links as a large, featured tile with a different visual treatment. It now sits in Quick links as a regular extension tile, consistent with Audit log, Connection log, and Hub Pairing. Order within Quick links: Audit log → Connection log (if paired) → Maintenance Report → Hub Pairing (for admins). The "For clients" kicker is preserved. Featured tile section removed from the dashboard template.

### Internal / parked

- The CSS classes for the old featured tile (`.cb-core-tile-featured`, `.cb-core-tile-arrow`, and related theme overrides) remain in `assets/admin.css` but are no longer referenced by any template. Safe to keep — unmatched CSS is inert — but a future cleanup pass should remove them.

---

## [1.0.3-dev] — 2026-04-20

Governance preset rename — labels are now industry-agnostic.

### Changed

- **Governance presets renamed** from industry/vertical labels to intensity-scale labels. Old: `basis` / `webshop` / `zorg` / `minimaal`. New: `minimal` / `standard` / `enhanced` / `strict`. The labels no longer tie Core Blueprint to any specific industry or vertical; the scale now runs from light-touch to maximum auditability and applies to any organisation regardless of sector.
  - `PRESET_BASIS`    → `PRESET_STANDARD` (was the default, still is)
  - `PRESET_WEBSHOP`  → `PRESET_ENHANCED` (retains its settings: extended login retention)
  - `PRESET_ZORG`     → `PRESET_STRICT`   (retains its settings: full IP, multi-year retention, all events)
  - `PRESET_MINIMAAL` → `PRESET_MINIMAL`  (retains its settings: minimal logging)
- **Preset order on the Privacy tab** now runs intensity-ascending: Minimal → Standard → Enhanced → Strict. Matches the mental model of a slider.
- **Preset descriptions expanded** with clearer guidance on when to pick each one — use-case language instead of industry language, so any site owner can self-select the right intensity.
- Default preset for fresh installs: `PRESET_STANDARD` (was `PRESET_BASIS` — effectively the same preset, just renamed).

### Migration note

The preset slug values in `wp_options` change. Any existing site with `cb_core_privacy_active_preset = 'basis'` (etc.) will be treated as `custom` on the next settings read, and the user will need to explicitly pick the new preset to restore the labelled state. The underlying settings (ip_mode, verbosity levels, retention days) are untouched.

---

## [1.0.2-dev] — 2026-04-20

Hotfix — settings could not be saved after the Foundation→Core Blueprint rename.

### Fixed

- **Every AJAX save silently failed with "Could not save — try again".** `assets/admin.js` still sent the pre-rename action strings (`cb_fnd_set_theme`, `cb_fnd_save_privacy`, `cb_fnd_panic_activate`, etc. — 30 occurrences across 10 handlers). Server handlers listen on `cb_core_*` after the rename, so every WordPress `wp_ajax_*` hook missed, and WP returned the empty-response fallback that the JS then reports as the generic save error. Port-pass had covered PHP, CSS, and the localisation object (`cbFnd` → `cbCore`), but not the JS action strings. Fixed by rewriting all `cb_fnd_*` and `cb_beacon_*` references inside `admin.js`.
- Stale `CB_Fnd_Admin` docblock reference in `admin.js` updated to `CB\Core\Admin\Admin`.

### Verification

- Cross-check of every JS ajax action against registered PHP handlers: **21/21 matched**.
- All nonce-create / nonce-check pairs consistent on `cb_core_admin`, `cb_core_theme`, `cb_core_locale`.
- Localisation object name `cbCore` consistent between `wp_localize_script()` and JS access.

---

## [1.0.1-dev] — 2026-04-20

Menu restructure + bug fixes from production testing of `1.0.0-dev`.

### Changed

- **Admin menu restructured into four submenus + Hub Pairing.** The previous seven-submenu layout grew organically out of the Foundation + Beacon merge; this release reshapes it around what things *are*:
  - **Dashboard** — landing page, status tiles
  - **Logs** (slug `core-blueprint-logs`) — tabs: Audit · System · Connection · Maintenance. Consolidates the former Security-page log tabs and the standalone Maintenance Report page.
  - **Safeguards** (slug `core-blueprint-safeguards`) — tabs: Overview · Access Mode · Failsafe. Replaces the former "Security" submenu. The name change reflects a deliberate positioning choice: Core Blueprint does not replace antivirus/malware plugins like Wordfence, it implements hardening and compliance safeguards. "Safeguards" is GDPR/NIST/ISO 27001-native vocabulary that reads naturally in both English and Dutch (NL: "Maatregelen").
  - **Preferences** (slug `core-blueprint-preferences`) — tabs: Privacy · Appearance · Language · About. Absorbs the former standalone Privacy page.
  - **Hub Pairing** (slug `core-blueprint-hub-pairing`) — unchanged name; the Beacon secret-key management page. Visible to all `manage_options`-capable users.
- **Dashboard Hub Pairing tile** added to the Quick links section. Visible to `manage_options` users; state reflects whether the site is currently paired.
- `Admin::SECURITY_SLUG` → `Admin::SAFEGUARDS_SLUG`. The old constant is gone.
- `Admin::ACCESS_MODE_SLUG` and `Admin::MAINTENANCE_REPORT_SLUG` — both removed. Access Mode is now a tab under Safeguards, Maintenance Report is a tab under Logs.
- `Admin::LOGS_SLUG` — new constant for the Logs submenu.
- Every internal URL reference (email alerts, status tiles, ajax handlers, template deep-links, pagination base URLs) migrated to the new slug + tab combinations.
- `Pages\Privacy` refactored from a Page class to a stateless helper. It exposes `Privacy::render_body()` which Preferences invokes from its Privacy tab. The `estimate_storage_kb_per_year()` + `retention_category_for_verbosity()` helpers stay co-located with the rendering code.

### Fixed

- **Fatal error on Dashboard Hub-Pairing tile click** — `Beacon\Admin\Assets::enqueue()` referenced the non-existent constants `CB_CORE_BEACON_FILE` and `CB_CORE_BEACON_VERSION`, and pointed at a CSS filename that didn't match the actual asset. Fixed to use `CB_CORE_FILE` / `CB_CORE_VERSION` / `core-blueprint-beacon-admin.css`.
- **DB error when opening Maintenance Report / Security → Connection** — both pages called into `ConnectionLog::query()` whenever the class was loadable (always true inside the merged plugin), which then hit a non-existent table on sites that hadn't paired with a Hub yet. Now gated on `Beacon\Bootstrap::is_paired()`. Connection log tab is hidden until pairing; Maintenance Report silently skips connection-log rows until then.
- **StatusResponse** reported a non-existent `CB_CORE_BEACON_VERSION` over REST; the `client_version` field now reports `CB_CORE_VERSION` as intended. This would have become a fatal the moment a Hub polled.
- **Dashboard template referenced the non-existent legacy class `CB_Beacon_Connection_Log`** in a `class_exists()` check, so the Connection-log tile would never render even on paired sites. Replaced with `Bootstrap::is_paired()`.
- **Failsafe bypass constants cleaned up.** The wp-config.php constant for Layer 1 bypass is now `CB_CORE_BYPASS`. The legacy `CB_FOUNDATION_BYPASS` and `CB_SECURITY_BYPASS` names are no longer honoured — single canonical name. Admin UI text and WP-CLI warnings updated accordingly.

### Removed

- `src/Admin/Pages/Security.php` — replaced by Safeguards with a narrower scope.
- `src/Admin/Pages/AccessMode.php` — now a tab under Safeguards.
- `src/Admin/Pages/MaintenanceReport.php` — now a tab under Logs.
- All `class_exists('\CB\Core\Beacon\...')` soft-dependency checks. Within a single plugin these always return true — inappropriate for a pairing-state check. Replaced throughout with `Beacon\Bootstrap::is_paired()` where the intent was "is Beacon actually active".

### Verification

- Namespace-trap sweep: **0 suspect references across 75 files**.
- Cross-reference check: **clean across 75 classes**.
- Autoload harness: **75/75 classes load without fatals**.
- PHP lint clean across all 90 PHP files.

### Parked

- Ai1wm i18n notice. The `_load_textdomain_just_in_time` warning on WP 6.7+ originates in the All-in-One WP Migration plugin's own bootstrap, not in ours. Our Ai1wm backup-provider only touches their code at request time, and does so behind a pairing gate. Not a blocker; not our bug to fix.

---

## [1.0.0-dev] — 2026-04-20

Initial development release of **Core Blueprint**, the unified foundation plugin. Merges the former standalone **CB Foundation 1.0.7** and **CB Beacon 1.0.1** plugins into a single coherent plugin. Replaces both predecessors — neither is shipped separately going forward.

This is a `-dev` tag: the plugin is feature-complete and clean, but will only be promoted to a final `1.0.0` once it has been verified in production alongside Core Blueprint Hub `1.0.4-dev`.

### Added

- **Beacon as a dormant subsystem.** The satellite layer (REST endpoint, backup providers, remote updates worker, connection log) now lives under `CB\Core\Beacon\*` inside Core Blueprint. The runtime stays dormant — no REST routes, no cron jobs, no request recorder — until the site owner generates a secret key on the Hub Pairing screen. This means a fresh Core Blueprint install exposes zero outward-facing surface area by default.
- **Hub Pairing admin page** (submenu under Core Blueprint). Replaces the former standalone "Beacon" settings page. Generating a key here activates the Beacon layer in one step: installs the Connection Log table, schedules the daily prune cron, and starts accepting authenticated requests from a Hub.
- Single PSR-4 autoloader for the entire `CB\Core\*` namespace tree — Foundation layer and Beacon layer share one autoload mechanism.

### Changed

- **Plugin folder**: `cb-foundation` / `cb-beacon` → `core-blueprint`.
- **Plugin display name**: "Core Blueprint — Foundation" / "Core Blueprint — Beacon" → "Core Blueprint".
- **Text domain**: `cb-foundation` / `cb-beacon` → `core-blueprint`.
- **Root namespace**: `CB\Foundation\*` → `CB\Core\*`; `CB\Beacon\*` → `CB\Core\Beacon\*`.
- **REST namespace**: `cb-beacon/v1` → `core-blueprint/v1`. Requires a matching Core Blueprint Hub that speaks the new endpoint.
- **Constants**: `CB_FND_*` → `CB_CORE_*`; `CB_BEACON_*` → `CB_CORE_BEACON_*`. Backup error codes (`CB_BACKUP_ERR_*`) retain their string values as they form a public contract with the Hub.
- **Option keys**: `cb_fnd_*` → `cb_core_*`; `cb_beacon_*` → `cb_core_beacon_*`.
- **Cron hooks**: all renamed under the same prefix rules above.
- **DB tables**: `{prefix}cb_fnd_audit_log` → `{prefix}cb_core_audit_log`; `{prefix}cb_beacon_connection_log` → `{prefix}cb_core_beacon_connection_log`.
- **Admin page slugs**: `cb-foundation-*` → `core-blueprint-*`. Parent menu slug unchanged (`core-blueprint`). Beacon settings page slug: `cb-beacon` → `core-blueprint-hub-pairing`.
- **CSS classes**: `cb-fnd-*` → `cb-core-*`; `cb-beacon-*` → `cb-core-beacon-*`.
- **Asset handles**: `cb-foundation-admin` → `core-blueprint-admin`. JavaScript localisation object: `cbFnd` → `cbCore`.
- **WP-CLI command**: `wp cb-foundation ...` → `wp core-blueprint ...`.
- **`.htaccess` marker**: `CB Beacon` → `Core Blueprint`.
- **Status-tile filter**: `cb_fnd_status_tiles` → `cb_core_status_tiles`. Beacon's own status-tile contributor now renders an unambiguous "Not paired" idle state before any pairing has occurred.
- **Email alerts** link directly to the Security page's audit tab via a class constant reference, instead of hardcoding a slug string.
- **Plugin activation no longer auto-generates a Beacon secret key.** Pairing is an explicit, user-initiated action from the Hub Pairing screen.

### Removed

- **All legacy code paths.** The plugin carries no compatibility shims, no fallbacks for older data shapes, and no one-shot migration code. Internal-only dev posture: upgrades are coordinated, installations are fresh, and every code path represents current intended behaviour.
  - `LegacyMigrator` (CB Security 1.3.2 → Foundation data copy) — removed entirely.
  - `Themes::maybe_migrate_native()` + `SLUG_NATIVE` constant — removed. Native theme is no longer a registered option.
  - `Admin::register_legacy_redirects()` + `redirect_to_security_tab()` + `redirect_to_preferences_tab()` — removed. Five legacy slug constants (`APPEARANCE_SLUG`, `LANGUAGE_SLUG`, `AUDIT_SLUG`, `FAILSAFE_SLUG`, `ABOUT_SLUG`) and their `remove_submenu_page` calls — removed.
  - `enqueue_assets()` `$legacy_hooks` array — removed. Screen detection now uses registry + parent hook + two prefix-match branches.
  - `ConnectionLog::backfill_categories_v2()` — the one-shot v3.0.12 reclassification pass is gone. `derive_category()` no longer treats empty HTTP method as "read-only-for-legacy-rows".
  - `Crypto::decrypt()` legacy plaintext-fallback — unprefixed input now returns `false`. All stored values are expected to be `enc:`-prefixed.
  - `SecretKey::get()` legacy plaintext re-encrypt path — stored values are always encrypted.
  - `Ai1wm::lock_started_at()` legacy string-lock compatibility — lock values are always arrays.
  - `CB_FND_LEGACY_MIGRATED_OPT` constant and `cb_fnd_legacy_cleanup` cron — removed.

### Fixed

- **Three `WP_Error` namespace-trap bugs** caught during the port sweep: union-return types like `): true|WP_Error {` and `): bool|WP_Error {` were resolving to `CB\Core\Beacon\...\WP_Error` instead of the global `\WP_Error`. Corrected to `\WP_Error` in `Beacon\Backup\Providers\Database` (two locations) and `Beacon\Rest\Auth`.
- **Admin slug collision** between Foundation's Dashboard (`core-blueprint`) and the former Beacon settings page (also registered under `core-blueprint`). The Beacon-layer page is now `core-blueprint-hub-pairing`.
- Stale `CB_Fnd_Ajax_*` docblock reference in `Ajax\Guards.php` updated to reflect the new namespace.
- `Beacon\Lifecycle` no longer references the non-existent `CB_CORE_BEACON_FILE` constant (it referenced Beacon's former own plugin file).

### Verification

Build quality-gates that passed before release:

- Namespace-trap sweep (every form of unqualified global class reference inside namespaced files): **0 suspect references across 76 files**.
- Cross-reference check (every `ClassName::method()` call within the `CB\Core\*` namespace resolves to an existing method on an existing class): **clean across 76 classes**.
- Autoload harness (every class file is loadable via the PSR-4 autoloader under a stubbed WordPress): **76/76 classes load without fatals**.
- One class/interface/trait per file: confirmed, 76/76.
- Zero `cb_fnd_`, `cb_beacon_`, `CB_FND_`, `CB_BEACON_`, `cb-foundation`, or `cb-beacon` string residue in `src/`, `templates/`, `includes/`.

---

## Prior history

This plugin subsumes two predecessors. Their release histories are preserved in their respective repositories but are not repeated here — from `1.0.0-dev` onward, this changelog is the single source of truth.

- CB Foundation last shipped as `1.0.7`.
- CB Beacon last shipped as `1.0.1`.
