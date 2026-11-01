# Native Mail Template Provider Contract v1

**Owner:** Core Blueprint Base Mail Designer  
**Status:** MAIL-EXT-01A SOURCE implementation (sandbox/release acceptance pending)  
**Scope:** Provider-owned defaults, discovery and read-only document retrieval. Editor resource authorization is a separate MAIL-EXT-01B change.

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

Base reserves the `wordpress.*` namespace for built-in templates (provider `core`, group `wordpress`). A duplicate ID or malformed registration is refused with `false`; the first valid registration is not replaced.

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

For MAIL-EXT-01A, extension resources remain excluded by the existing Base admin picker and transport identity policy until MAIL-EXT-01B. No migration or change to existing saved documents is performed.
