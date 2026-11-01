# Native Mail Template Provider Contract v1

**Owner:** Core Blueprint Base Mail Designer  
**Status:** MAIL-EXT-01A + 01B SOURCE implementation (local runtime validation and sandbox acceptance pending)  
**Scope:** Provider-owned native defaults, discovery, shared runtime/editor retrieval, native selector and REST resource admission.

## Registration

An installed provider registers once through the existing WordPress action `core_blueprint_register_mail_templates` using `CoreBlueprint\Core\Mail\Designer\TemplateRegistry::register()`. Registration is PHP/server-side and never a browser authorization grant.

Required properties of a template definition:

| Property | Contract |
| --- | --- |
| `id` | Unique, stable, lowercase resource ID, maximum 191 bytes, format `provider.template-name`. Dots and hyphens are supported. |
| `provider` | Canonical lowercase provider namespace; must match the prefix of the ID. |
| `group` | Lowercase group identifier for catalog presentation. |
| `label` | Non-empty human-facing label. |
| `description` | Optional human-facing description. |
| `document` | Complete native v1 Mail document with exactly `project` and `fields`. |

Base reserves the `wordpress.*` namespace for its exact built-in IDs and canonical defaults (provider `core`, group `wordpress`); third parties cannot register alternate WordPress templates. The `core` provider namespace is likewise reserved for built-in Base resources. A duplicate ID or malformed registration is refused with `false`; the first valid registration is not replaced.

The `document` envelope is exact, not a legacy conversion:

```php
[
    'project' => [
        'schema_version' => 1,
        'design_type' => 'core.blueprint.mail',
        'root' => Contract::node( Contract::ROOT_TYPE, [
            Contract::node( 'mail.section', [
                Contract::node( 'mail.heading', [], [ 'text' => 'Booking confirmed' ] ),
                Contract::node( 'mail.text', [], [ 'text' => 'Hello {{booking.customer_name}}' ] ),
            ] ),
        ] ),
    ],
    'fields' => [
        'subject' => 'Your booking at {{site.name}}',
        'preheader' => 'Your appointment details',
    ],
]
```

`Contract` refers to `CoreBlueprint\Core\Design\Profile\Mail\Contract`; all node providers are `core.blueprint`. Every node must satisfy the native PHP Mail validator and canonical schema; field names, value types, bounds and document shape are validated by the same Base admission entrypoint used for native stored documents. No `schema_version=0`, `mail-template` design type, legacy node providers or partial field envelopes are accepted.

## Shared default authority

- `TemplateRegistry::default_document( $template_id )` returns the registered `{project, fields}` default or `null` if absent.
- `PlatformAdapter::default_for_resource()` uses this registry method for missing editor documents.
- `TemplateRepository::get()` uses the same method for missing runtime documents. Persisted native documents take priority, and corrupt/unsupported/unavailable documents **never** silently fall back to defaults.
- Defaults are **read-only**. Registration, lookup and rendering do not create, update or delete a document. Native Designer Platform Save Authority remains the only writer.
- Deactivating a provider stops active registration; it does not delete its saved Base-owned design documents. Reactivation with the same stable ID can recover the saved document, subject to normal document integrity and authorization gates.
- `TemplateRegistry::get()` / `all()` retain read-only `project`, `fields`, `subject` and `preheader` compatibility fields derived from the canonical document. These are not a second registration or persistence format.

## Boundaries

The provider owns template wording, event-to-template mapping, safe binding previews and notification context. Base owns validation, native nodes, editor context, rendering, document persistence, revisions and Save authorization. Registering a definition does **not** itself authorize a user to open or save it: authenticated REST nonce checks, WordPress capabilities, registered resource identity, active provider policy and native document gates are separately enforced by the Designer Platform transport.

## Native Designer admission (MAIL-EXT-01B)

The existing Core Blueprint → Mail → Templates landing selector and fullscreen Header Picker use the **same active** registered-template catalog. Both render native `<optgroup>` labels per provider (WordPress first, other providers alphabetically). Neither creates a new toolbar or editor. The canonical native JavaScript context host, Save/Discard flow and Exit Guard are unchanged.

The existing `base/mail` REST Designer profile permits only resource keys with a current, validated `TemplateRegistry::get( $key )` entry and a valid native document. **Both** `manage_options` authorization and the existing Mail Designer enabled state remain mandatory; REST CSRF nonce/session checks, native Save Authority, fingerprint, revision, identity and existing-document gates are unchanged. An unregistered, deactivated or malformed provider resource is denied; a disabled provider's **saved Base document row is not deleted**. Reactivation with the same ID resumes access to that row without restoring from a default or migrating it.

This shared contract is intentionally provider-neutral: official first-party integrations dogfood the same public registry rather than a private Bookings-only allowlist. Registration happens from executable WordPress plugin PHP through the documented hook; it is not a user permission grant. Trusted installed plugin code is part of the host's WordPress trust boundary, so provider registration is not a sandbox for arbitrary untrusted PHP.

No migration or change to existing persisted documents is performed. Browser and installed WordPress acceptance still must verify native Save, re-open, revision integrity, recovery, Save/Discard, Exit Guard and cross-product rendering; source-only tests are insufficient.
