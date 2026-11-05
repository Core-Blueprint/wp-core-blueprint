# Template Library — Offline Portability Requirement (T4)

**Owner decision:** 5 November 2026  
**Status:** Required product capability; FRG-T4-01A asset-free JSON contract candidate; consumer implementation PENDING  
**Scope:** Core Blueprint WordPress Suite Template Library and first-party Designer consumers.

## Outcome

A user with the appropriate consumer-specific permissions **must be able to
export a template to a local file and import that template on another compatible
WordPress site**. This enables deliberate user backups, offline restoration
and site-to-site portability, without any vendor account, Marketplace connection,
online distribution service or outbound network request.

The ability to export/import is required before each relevant first-party
consumer is declared product-complete or receives stable-release acceptance.
It is **not yet implemented** by the current read-only Template Library.
T7 online distribution remains out of scope and must not delay offline use.

## Ownership and architectural boundary

- **Base** owns the shared template discovery/browser and reuses the existing
  Data Exchange JSON envelope for asset-free template portability; see
  [TEMPLATE-PORTABILITY-JSON-CONTRACT.md](TEMPLATE-PORTABILITY-JSON-CONTRACT.md).
  A binary resource-package format remains subject to FRG-T4-01B evidence.
  Base does not gain domain mutation authority.
- **Consumers** own their template schemas, document payload serialization,
  per-item access checks, export/import UI entrypoints, server-side schema
  validation, conflict decisions and authorized storage. Consumers must never
  treat downloaded bytes or metadata returned by Base as authorization.
- **T3B** owns user-saved personal/site template management in each consumer.
  **T4** owns the offline portability format and import/export acceptance.
  They can be developed in coordinated but independent consumer workstreams.
- Final format and capability/API details are **not approved by this
  requirement alone**. Define and review the smallest interoperable format
  before consumers are modernized, to avoid divergent vendor-specific packs.

## Mandatory design and security acceptance

1. Export must be a complete, explicitly initiated local artifact that can
   restore the template on a fresh compatible WordPress site; no hidden remote
   dependencies.
2. Canonical asset-free JSON declares the owner extension, entity, exact
   Designer/document type, current schema/format identity and an empty resource
   list within its single template record. A future package specification must
   additionally bind any necessary resources to the authorized import preview.
3. Never export credentials, access tokens, privileged principals, personal
   secrets, environment-specific authorization state or executable code.
   Domain-specific sanitization and safe resource handling are mandatory.
4. Import performs a bounded preflight **before any write**: permissions,
   type/schema compatibility, payload limits, integrity, archive safety,
   referenced resources, collision handling and explicit user review.
5. Import creates a safe draft/personal copy by default where the domain
   supports it; Automations workflows must remain **disabled** until separately
   approved. It cannot silently overwrite site templates or activate actions.
6. Both export and import must have negative tests for malformed packages,
   path traversal, unauthorized scope, hidden templates, incompatible schema,
   conflicting identities and partial failure/recovery.
7. Deliver browser/keyboard acceptance, controlled WordPress integration,
   an exact-artifact test and a user-visible portable round trip:
   export on site A, import on site B, then inspect the restored template.

## Freeze and release gates

Before **FRG-04 Foundation Freeze**, resolve the **Base-to-consumer offline
portability ownership and format boundary**, or record an explicit owner-approved
separate contract gate. Do not label import/export implemented until the
consumer endpoints and real cross-site round trip have passed acceptance.
Consumer implementation may follow Foundation Freeze, but it is mandatory
before that consumer's stable product release.

This is a portability and backup feature for user-owned templates, **not**
backwards compatibility for pre-stable APIs and **not** historical test-data
migration. The approved No Legacy policy still applies.
