# MC-02C — Unified Saved Mail Template Management (Base v1)

**Owner:** Core Blueprint Base. **Track:** MAIL-CENTER-01 / Overseer #92.  
**Feature:** feature/mail-center-mc02c-unified-template-management-20261115.

## One shared Browser and Template Library

Core Blueprint → Mail → Templates retains one “Open with Core Blueprint” launcher. The redundant Saved Mail landing manager is gone. The shared Template Browser and global Template Library retain Rename, Duplicate, Export JSON, and Delete via the existing provider-owned Template Actions menu, with actor ACL and revision CAS. The Mail Designer Browser retains Save as template via its existing Host document capture. Apply never automatically Saves a live operational Mail design.

MC-02C introduces **Import JSON** through one optional provider-admitted Library interface. It does not use the ZIP ResourcePackage import endpoint for JSON.

## Provider-owned import v1

The TemplateJsonImportSourceInterface is optional for already-registered, already-admitted sources. It declares live actor authorization, a bounded stable create-only destination prefix, read-only JSON preview and a single fingerprint-fenced create operation. A Source registration alone never provides authority; Base validates both actor and extension/designer scope on each request.

The shared POST /core-blueprint/v1/template-library/import-json endpoint accepts exactly:
- preview: phase, source, destinationId, file;
- apply: phase, source, destinationId, file, fingerprint.

JSON file size is limited to 1,150,000 bytes; the complete request is capped at 2,500,000 bytes. The source ID and destination ID must match strict namespaced identifiers and the provider-owned prefix; apply requires a 64-character lowercase hexadecimal fingerprint obtained from one valid preview. No arbitrary URL, path, uploaded server file, user/owner ID, operational Mail resource ID, partial update or document override is accepted.

Base’s Saved Mail source is the first opt-in provider. It reuses the existing Mail SavedTemplateController and DataExchange Engine in MODE_CREATE_ONLY. The result is a **new personal saved asset**; the active operational notification design stays unchanged. The server returns only bounded verification metadata (one record, one create, fingerprint, source/destination), never an imported document or raw customer content.

## Browser lifecycle and safety

The shared Browser toolbar shows Import JSON only for a source advertising JSON import v1 in a fresh scoped /sources response. Multiple admitted providers use a source chooser. The Designs-only initial picker is unchanged; after selecting an actual Mail design, Templates is available. Import is also available globally in Template Library.

Workflow: select one small JSON file → server preview → explicit confirmation → one apply → refresh. A transport failure after apply is an **unknown write**; there is never an automatic retry. The same-page uncertain-source fence survives closing and reopening the Browser until an explicit page reload, permitting manual inspection first.

The existing ZIP importer, global bulk actions, per-card actions, synthetic preview, Save as, Browser-first start, Mail Save Authority, context menu, reports page navigation, and source/actor scoping remain separately owned.

## Qualification and acceptance

Level 1, full isolated disposable WordPress Level 2, fresh dev ZIP integrity and SHA-256 all require the eventual exact feature HEAD. The new PHP/Node tests exercise valid and invalid imports, source admission, input schema, nonce, scope, create-only semantics and uncertain-write handling.

Owner sandbox acceptance should verify: one Mail launch button; Browser opens on Designs; open a design and navigate to Templates; Save as; import a JSON personal saved template; Rename, Duplicate, Export JSON, Delete and Apply from the central Browser or global Library, with the operational Mail design unchanged until explicit Save. Check focus, modal behavior, 320px/desktop, dark/light and no regressions to the right-click context menu.

**Out of scope:** Bookings/LMS provider changes (MC-03/MC-04), direct rich-text caret token insertion, provider-owned optional synthetic preview expansions, and document A4 pagination. No merge, CI/CD, publication, deploy, live mail dispatch or customer site changes without distinct owner GO.
