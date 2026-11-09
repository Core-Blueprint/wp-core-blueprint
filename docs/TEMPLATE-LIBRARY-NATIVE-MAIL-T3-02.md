# T3-02 — Native Mail Saved Template Lifecycle (WP Base)

Status: **SOURCE IMPLEMENTED / OPERATOR QUALIFICATION PENDING**. Parent: T3-01 exact `83139756519671b8ecfd24a71a7452f7d556f6eb`. **FRG-04 HOLD; external consumers BLOCKED.** This is WordPress Base, not Webapp v2.

## Ownership and persistence

- Active native WordPress/extension notification documents remain in **Base Designer Platform Save Authority**, `PlatformTransport → TransactionalSave → Design\Document\Repository`, under registered runtime resource keys. Only that Save/History owns the active design, revision receipts, dirty status, Undo/Redo and UNKNOWN.
- Mail provider `TemplateRegistry` continues to register **immutable native default documents**; it is not a personal/site saved template collection. Never turn an arbitrary library ID into a provider resource key.
- The Mail subsystem now owns a separate per-blog `cb_core_mail_saved_templates` **reusable asset table** (registered through `SchemaRegistry`). Rows contain random `mail.saved.<hex>` IDs, scope, title, revision and a complete validated `{project,fields}` snapshot. This table is not a second live-document persistence engine; it is not a Base-wide generic template store. No migration/backward-compatibility layer.
- **My templates**: user-scoped personal rows; another administrator cannot read, list, Apply or manage them. **Site templates**: deliberately shared with current site administrators. `manage_options` and Mail Designer enabled required. Site privacy follows the actual WordPress blog/site.
- Reusable snapshot actions never write the active Designer document. Applying a snapshot returns an authorized document **read** and delegates to T3-01's single canonical Domain History + persisted fields transaction; subsequent live Save uses the **existing** Platform Save Authority.

## Browser and operations

- `core-blueprint-mail-saved` is a separate first-party `TemplateSourceInterface` + `TemplateApplySourceInterface` provider. Existing `core-blueprint-mail` retains the read-only registered default catalog.
- Server-approved contextual browser token: `DesignerScope::issue('core-blueprint-mail', Repository::MAIL, $template_id)` for the actual Mail Designer target only. Reusable asset IDs are **not** valid editor target resources.
- Static Mail-only module `@cb-core/mail-saved-controls` registers a guarded READY-Host binding into the shared `@cb-core/base-platform-v1` entry. It supports both before/after async mount order, introduces no dynamic `import()` in `assets/js/design/**`, and does **not** load Mail-specific libraries for Reports.
- Native Mail Host toolbar uses a compact accessible **Templates** disclosure (Use template / Save as template). It imports the actual shared Template Browser / Base modal and consumes the **published Host editor facade** only. No second Header Picker, canvas, Editor instance, history or SaveCoordinator.
- `Use template`: Host `captureApplyIntent()` before authenticated `TemplateBrowser.loadForApply(...)`; Host `applyTemplate(reply,intent)` validates unchanged target/context/document state, then makes one undoable dirty project+fields change. Save/discard remain native.
- `Save as template`: captures the in-memory project+fields snapshot from the live Host, chooses personal/site visibility, sends one authenticated Mail-owned create. It does **not** imply the active Mail document has been saved or change its original document revision. Save UNKNOWN/pending blocks template capture.
- Landing `Mail → Templates` shows a personal/site saved-template management list (Rename, Duplicate, Delete and keyset Load more). Manager mutations are actor-gated, revision-fenced and strictly respond to confirmed outcomes. Rename/Delete are single-row CAS updates/deletes; Duplicate uses atomic revision-fenced `INSERT ... SELECT`. No GET mutation.
- REST routes `/core-blueprint/v1/mail-templates/{list,create,rename,duplicate,delete}` use current WP REST nonce, session, capability, strict body shapes, domain authorization and SQL scopes. Create additionally verifies actor/session/site-bound DesignerScope token for exact active target. Data stays local; no marketplace calls.
- Unverified transport or storage outcomes **fail closed** and require reload before retry. **Do not claim exactly-once delivery/idempotency across an ambiguous browser disconnect**: the reusable asset endpoint does not participate in the operational `TransactionalSave` operation ledger. This limitation is intentionally isolated from live document Save.

## Qualification plan and limitations

- Source regression: `node --test tests/js/mail-saved-library.test.mjs tests/js/designer-native-discard-integration.test.mjs tests/js/template-browser-boundary.test.mjs`.
- Canonical Base Level 1: `bash tools/check` (no DB/test WordPress setup); development artifact: `python3 tools/build-dev`, `unzip -tqq`, SHA sidecar verification.
- Joint Foundation Level 2: `tests/integration/MailSavedTemplateLifecycleTest.php` runs with the approved isolated WP/MariaDB test environment; do not provision another DB for this intermediate patch.
- Installed FIELD (owner acceptance): Mail enabled, actual registered third-party template, personal/site isolation using separate admins, built-in and saved Use, project+fields Undo/Redo, Save/Reopen, Dirty Discard/Exit Guard, keyboard/focus, light/dark and responsive menu, Rename/Duplicate/Delete conflict safety, invalid/expired scope and UNKNOWN refusal.
- The DesignerScope token has an existing **15-minute TTL**. A long-lived editor can require a page refresh to regain library actions; FIELD should treat this as a UX acceptance risk to resolve prior to Foundation Freeze if the user requires uninterrupted long sessions.
- No public release validation, merge, CI/CD or deployment is implied by source changes. T4 Data Exchange offline import/export follows T3 lifecycle qualification. Consumer adaptation remains BLOCKED until explicit FRG-04 Freeze GO.
