# TL-M1 — Template Library Management Actions v1

**Owner:** Core Blueprint Base (transport, discovery, shared UX contract) and each registered provider (domain operations).  
**Status:** source candidate, WordPress Level 2 and UI acceptance still required.  
**Parent:** T4 Mail JSON portability commit `91e6bd446ff33cb55f6f85f4b67516bacda71e0a`.

## Contract

Providers may optionally implement `TemplateActionSourceInterface` on their existing registered `TemplateManagementSourceInterface` source. Providers without it expose no actions. Base discovers action identifiers for each authorized saved template and exposes them as an `actions` string array alongside metadata. Base never receives a template's editable document through discovery.

Allowed action identifiers in v1:

- `rename` — expects a validated title; metadata-only update with CAS revision increment.
- `duplicate` — expects a validated title; creates a distinct asset.
- `export-json` — read-only Data Exchange export; may reject embedded media.
- `delete` — revision-fenced deletion.

`preview`, `edit` and `export-zip` are not advertised until the owning provider has a complete audited lifecycle. A contextual Designer's **Use template** remains independent of all management actions. The Browser's existing safe `navigation` field is also independent.

## REST transport

- `GET /core-blueprint/v1/template-library/templates` returns metadata, navigation and `actions`; capabilities are hints and are not persisted as authority.
- `POST /core-blueprint/v1/template-library/manage` accepts exactly:
  `{"source":"...","templateId":"...","action":"rename|duplicate|export-json|delete","revision":1,"input":{"title":"..."}}`.
  `input` must be `{}` for delete and export-json, and exactly `{"title":"..."}` for rename and duplicate. No document payload or target Designer resource is accepted.
- Both REST cookie nonce `X-WP-Nonce` and live global admin authority or a valid `X-CB-Designer-Scope` are mandatory. A scoped request is confined to the source's extension and Designer type. Providers recheck the current user, template visibility, supported action and expected revision on execution.
- On success statuses: `renamed` (revised revision), `created` (new ID and revision), `deleted`, `ready` (export data). `conflict` maps to HTTP 409; `unknown` and unverified writes to 503. An unknown outcome is never treated as a success, and the client must refresh before retrying.
- Export returns a canonical JSON string only from the provider's Data Exchange adapter. No new generic importer or writer is introduced.

## Scope and next gates

TL-M1 does not change live Designer Save/Apply, context epoch, Exit Guard or Template Library card DOM. TL-M2 must add the shared Actions dropdown with separate accessible controls, nonce-bound requests, destructive confirmation, refresh and focus restoration. TL-M3 makes the Admin Library browser-led and validates all viewports and themes.

Before release: run targeted PHP/WP integration tests, full Base Level 1, development ZIP/hash checks, WordPress integration and Site-B portability acceptance. This candidate is not a release or FRG-04 freeze acceptance.
