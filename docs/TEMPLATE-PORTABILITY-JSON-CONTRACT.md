# Canonical Template Portability — JSON Contract (FRG-T4-01A)

**FC-T4-01 SOURCE candidate — 12 November 2026:** This document continues to define the existing `core-blueprint-data-exchange` envelope without a second import engine. Native Mail has a targeted, unmerged implementation on `feature/fc-t4-01-mail-json-portability-20261112` (exact SHA and qualification evidence must be recorded after commit). A Base-trusted `template.mail` Data Exchange entity delegates to the Mail-owned SavedTemplateStore; WordPress REST `POST /core-blueprint/v1/mail-templates/export`, `/preview` and `/import` verify `X-WP-Nonce`, actor capability, file size, one-record create-only intent and a destination-bound preview fingerprint. Browser UI in the native Mail Templates tab downloads an asset-free JSON copy and imports into a new personal saved template with a unique ID. Existing live Designer document and other saved templates are never overwritten. Media-bearing `mail.image` URLs and rich-text media embeddings are refused for JSON (ZIP/media separate). This is **SOURCE CANDIDATE**, not yet Level 1/WordPress FIELD/site A→B accepted; do not call FRG-04 frozen or update the public v1.0.0 release.

**FC-T4-01 field acceptance:** On site A, save a normal asset-free personal Mail template, choose **Export JSON** from the native Mail Templates management list, verify the downloaded seven-key `core-blueprint-data-exchange` envelope and exactly one record with `resources: []`. On a separate WordPress site B with the same Base candidate, choose **Import JSON**, inspect the preview, confirm, and verify a new **personal** Mail saved asset (independent ID, correct title/content). Use the shared Template Library Browser to Apply the copy to a Mail resource, Save and Reopen. Verify denied roles, wrong nonce, unsupported media, malformed/oversized/tampered JSON, source schema mismatch, and attempted duplicate import; no site or live-resource changes. Tests/build/artifact evidence and owner acceptance remain separate gates.

**FRG-04 reconciled status (6 November 2026):** Base asset-free JSON v1 contract is implemented on `main@18023119473f27a95eac3215b25fcc30d115641f`, backed by the existing Data Exchange Engine and test-only portability provider. FRG-T4-01E real-resource ZIP → staging → Data Exchange integration was operator-reported 8/8 PASS at the accepted feature HEAD and is now merged; that does not prove a real asset-free or resource-bearing consumer site-to-site import. Consumer endpoints, ownership/ACL, export selection, actual persistence and FIELD acceptance remain pending. **Historical checkpoint:** SOURCE-only implementation and integration were pending when this contract was originally authored.  
**Foundation:** `CoreBlueprint\Core\DataExchange\Engine` v1.  
**Scope:** offline **asset-free, single-template JSON** exchange; no consumer endpoint is implemented by this document.

## Ownership and non-negotiable boundaries

Base owns the **existing** Data Exchange envelope, interoperability discovery, bounded parsing, preflight, preview/fingerprint/apply orchestration and sequential fail-stop reporting. Template Library owns discovery metadata only. Each extension registers an `EntityInterface` implementation as a canonical Data Exchange entity, and owns its own template schema, per-item ACL, redaction, import/export UI and persistence. Neither Template Library source registration nor a valid Designer scope grants export/import authority. Consumers adapt to the finalized Base contract after FRG-04; no legacy APIs, bridges or pre-release test-data migrations.

The JSON format is `core-blueprint-data-exchange` with `format_version: 1`. It is **not** a new `core-blueprint-template` envelope, and Base has no separate template import engine or global portable-identity registry. The existing Data Exchange entity registration uses the canonical Extension Registry provider id; the entity id is its consumer-owned template domain (e.g. `template.workflow`). A Template Library `source_id` is **site-local discovery metadata**, not a transport identity. Do not infer an entity id from `source_id` or accept a client-supplied source as authority.

## Canonical single-template envelope and record

For an asset-free template, `Engine::export_json($extension_id, $entity_id, $context)` produces exactly the seven Base envelope keys shown below. No additional top-level `manifest`, `source_id`, `site_id` or `resources` keys are allowed. The provider exports **exactly one** record for a selected, authorized template; it must reject or fail closed when that selection cannot be proven.

```json
{
  "format": "core-blueprint-data-exchange",
  "format_version": 1,
  "extension_id": "core-blueprint-example",
  "entity": "template.workflow",
  "schema_version": 1,
  "exported_at": "2026-11-05T00:00:00Z",
  "records": [
    {
      "portable_id": "example-workflow",
      "designer_type": "workflow",
      "document_schema_version": 1,
      "template_version": "1",
      "title": "Example workflow",
      "description": "Portable demonstration",
      "locale": "en",
      "category": "operations",
      "resources": [],
      "document": {
        "steps": [],
        "layout": { "columns": 1 }
      }
    }
  ]
}
```

The record uses these **canonical Base-to-consumer field names**, with domain-specific validation delegated to the provider:

| Field | Requirement |
| --- | --- |
| `portable_id` | Required, nonempty, stable, site-independent source identity; string, canonical ID, not a WordPress post/attachment/user ID. Scoped to `extension_id` + `entity`; not globally unique by itself. |
| `designer_type` | Required canonical Designer document type, checked against the provider's actual domain; never inferred from untrusted UI metadata. |
| `document_schema_version` | Required positive integer; separately validated against the **current** consumer-owned document schema (no pre-stable compatibility requirement). |
| `template_version` | Required nonempty version string for the exported logical template, distinct from Base `format_version` and provider `schema_version`. |
| `title`, `description` | Safe display strings; source-provided text is not trusted HTML. |
| `locale`, `category` | Provider-validated portable metadata, not local taxonomy-term IDs. |
| `resources` | For FRG-T4-01A **exactly `[]`**. A nonempty list is unsupported until FRG-T4-01B determines a real resource format and integrity binding. |
| `document` | Required structured, sanitized, schema-validated consumer document object; never executable code, credentials or installation-specific authority. |

The provider must validate *all* required fields, types, bounds and its own document grammar, and reject unexpected privileged/local identifiers instead of copying them into persistence. Base validates the exact envelope and bounded transport, **not** the consumer document semantics. A consumer may refine its document schema; it may not redefine these common record fields. The exported `schema_version` is the Data Exchange entity schema and is not synonymous with `document_schema_version`. The selected provider supports only deliberately accepted versions; no implicit legacy conversion.

`exported_at` is transport metadata, not a signature or authorization proof. Downloaded files and Template Library metadata are untrusted inputs.

## Identity and safe import as a new copy

**Portable identity:** `(extension_id, entity, portable_id)` identifies the source template *within the export format*. It does not address a local row on the receiving site. A `source_id` and an originating site's WP post ID, attachment ID, user ID, tenant ID or local path are **never** portable identity. Export must replace portable resources with safe self-contained data or refuse when the template cannot be restored.

**Local identity:** each importing consumer creates its own new local record/document key and ownership under current permissions. The foreign `portable_id` may be retained as non-authoritative provenance only. It never becomes an update key or capability.

**Default:** explicit `create_only` + **create new personal/draft copy**. The receiving user confirms a safe destination/owner and any local rebinding during preview. Identity collision is evaluated against the **target local identity**, not merely equal foreign portable IDs. Re-importing an identical file can create a second copy only when an independently authorized, distinct local identity is chosen. Collision with an existing local identifier must fail the preview with a bounded error; do **not** silently overwrite, upsert or disguise a collision as a successful skip. No updates to site/built-in templates, and no activation of imported workflows. Consumers whose domain lacks a safe personal/draft state must fail closed pending their own design.

**Preview/application invariants:** consumer `plan_import()` is side-effect-free and includes the chosen destination, current actor/authorization-relevant decisions, portable source provenance, normalized document data and any rebinding choices in the opaque `payload` returned to Base. The stable `reference` must identify the concrete *planned destination*, not just the foreign `portable_id`. Base fingerprints the plan; apply re-parses/re-plans and refuses a stale fingerprint. `apply_import()` **rechecks** actor, authorization, local target availability and domain invariants immediately before writing through the consumer's canonical persistence service. The fingerprint is **not** a signature, nonce, authorization substitute or lock; concurrent writes may still race. Consumers must enforce uniqueness at their canonical persistence boundary and use explicit recovery where necessary.

Required negative behavior: malformed/extra envelope fields, mismatched extension/entity/type, unknown schema, missing fields, nonportable identifiers, resource references, wrong user, hidden templates, changed destination, duplicate references, tampered file, existing destination and revoked permissions must not mutate any template. Preview itself must not mutate state or expose private document plan data.

## Import lifecycle

1. Owning consumer verifies current actor, nonce/CSRF, exact source file selection, size, destination and domain capabilities on its own route.
2. Call `Engine::preview_json($original_json, Foundation::MODE_CREATE_ONLY, $authorized_context)`. A valid preview shows the single-record create plan and bounded warnings; show explicit confirmation.
3. Preserve the original input, intended destination and fingerprint across the user confirmation. Recheck permission, context and input before apply.
4. Call `Engine::apply_json($original_json, Foundation::MODE_CREATE_ONLY, $expected_fingerprint, $authorized_context)`; the provider rechecks authorization and local uniqueness. Only a complete, one-record create is a successful import.
5. Handle failed/partial results explicitly. Base uses sequential fail-stop semantics, **not** a universal transaction across databases/files.

The Data Exchange size and depth bounds apply; this contract does not introduce a second transport parser or relax them. No implicit outbound requests, Marketplace dependency or server-to-server communication.

## Resource boundary — FRG-T4-01B

The six-consumer source audit is documented in [TEMPLATE-PORTABILITY-RESOURCE-RECONCILIATION.md](TEMPLATE-PORTABILITY-RESOURCE-RECONCILIATION.md). A narrow resource package candidate and test-only fingerprint proof are in [TEMPLATE-PORTABILITY-RESOURCE-PACKAGE-CONTRACT.md](TEMPLATE-PORTABILITY-RESOURCE-PACKAGE-CONTRACT.md). Reports and Certificates have verified source-level media dependencies; this does not approve a ZIP API, add resource ingestion, or change `resources: []` for FRG-T4-01A.

Before designing a ZIP transport, classify **portable embedded resources**, **installation-bound dependencies requiring user rebinding**, and **temporary preview data** for each of the six consumers. The current JSON record supports no dependent binary resources. A package is warranted only by a verified resource requirement. If needed, document bytes, required resource digests and selected import decisions must be bound to the *same* validated preview/fingerprint; distinguish integrity, consistency and failure recovery. Do not promise universal atomicity or implement another transaction engine.

## Verification and release boundary

`tests/integration/TemplatePortabilityContractTest.php` is a Base-owned **test-only provider** exercising the existing Engine with a strict asset-free record and independent destination identity. It does not create a public template provider or endpoint. FRG-T4-01A can be source-reviewed independently, but functional portability is not complete until the real consumers are modernized **after FRG-04 Foundation Freeze** and per-product file round trips are accepted.

**No consumer code changes, no stable-release claims, no second import engine, no migration of historical test data.**
