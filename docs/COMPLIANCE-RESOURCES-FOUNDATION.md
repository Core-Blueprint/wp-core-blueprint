# Compliance Resources Foundation - public v1 contract

Core Blueprint Base owns one central **Compliance** surface for the pages and documents an organisation uses for legal, privacy, security and governance information.

The Foundation is deliberately an availability and reference system. It does not decide which resources are legally required, does not generate legal advice, and does not claim that a configured site is compliant.

## Resource roles and ownership

A compliance resource role is a stable, owner-qualified identifier such as:

```text
core-blueprint:privacy-policy
core-blueprint:disclaimer
core-blueprint:terms-and-conditions
core-blueprint-bookings:cancellation-policy
```

There are two ownership classes:

- **Software-defined roles** are registered by Base or an active Core Blueprint extension. Users may assign or change the backing resource, but cannot delete the role itself.
- **Custom roles** are created by the site administrator under Base or an extension. The administrator owns the role and may edit its name, description and assignment or delete that custom role. Deleting a custom role never deletes the referenced WordPress Page or Media Library document.

The `custom-*` owner-local ID namespace is reserved for site-owned roles. Software-defined resources cannot register IDs in that namespace, and a stored custom role can never override a software-defined role with the same owner-qualified key.

Base registers these roles in v1:

- `core-blueprint:privacy-policy`
- `core-blueprint:disclaimer`
- `core-blueprint:terms-and-conditions`

The existing WordPress Privacy Policy setting is respected as a non-destructive fallback for `core-blueprint:privacy-policy` until an explicit Core Blueprint assignment is made.

## Extension registration

Extensions register roles during the controlled collection action:

```php
use CoreBlueprint\Core\Compliance\ResourceRegistry;

add_action( 'cb_core_register_compliance_resources', static function (): void {
    ResourceRegistry::register(
        'core-blueprint-bookings',
        'cancellation-policy',
        [
            'label'       => __( 'Cancellation Policy', 'core-blueprint-bookings' ),
            'description' => __( 'Booking cancellation information used by this site.', 'core-blueprint-bookings' ),
        ]
    );
} );
```

The owner must already be a valid `ExtensionRegistry` identity. Registration outside `cb_core_register_compliance_resources` is refused. Duplicate roles and unknown metadata are refused rather than overwritten or repaired.

Extensions own the business meaning of their roles. Base owns the registry, central UI, storage and resource resolution.

## Page or document assignments

Every role may reference one of two WordPress-native resource types:

- a **published WordPress Page**;
- a **Media Library document**.

The default v1 document allow-list contains PDF, DOC, DOCX, ODT, RTF and plain-text documents. It can be extended with the `cb_core_compliance_document_mime_types` filter.

Assignments store WordPress object IDs rather than copied URLs. Resolution therefore follows the current permalink or attachment URL and detects when a Page is unpublished or a document is removed.

The Compliance screen reports only technical availability: configured and available, configured but unavailable, or not configured.

## Multilingual sites

The Foundation does not depend on WPML, Polylang, TranslatePress or another multilingual plugin and ships no plugin-specific language adapters.

A normal Page or Media Library document can always be selected directly, including language-specific objects created by a multilingual solution. For sites that need one stable resource role to resolve differently by locale, an assignment may also define optional locale variants such as `nl_NL`, `en_GB` or `de_DE`.

Resolution uses WordPress' current `determine_locale()` value. Exact locale variants are preferred, followed by an explicitly configured language-only variant and then the default assignment. The selected content remains ordinary WordPress content; the multilingual plugin, if present, remains authoritative for its own content model and locale switching.

## Runtime resolution

PHP consumers can resolve a resource through:

```php
use CoreBlueprint\Core\Compliance\Resolver;

$url = Resolver::url( 'core-blueprint:privacy-policy' );
```

An explicit locale may be passed as the second argument when a caller intentionally needs a specific variant.

Frontend content can use the stable shortcode:

```text
[cb_compliance_resource id="core-blueprint:privacy-policy"]
```

The default format renders a link using the registered role label. The raw resolved URL is available with:

```text
[cb_compliance_resource id="core-blueprint:privacy-policy" format="url"]
```

A custom link label may be supplied with `label="..."`, and `locale="de_DE"` may be used for an explicit locale override.

If a configured resource is no longer available, resolution fails closed and the shortcode renders no broken destination.

## Central Compliance surface

Base exposes **Core Blueprint → Compliance** as the canonical overview. Resources are grouped by Base or extension owner. Each group may also contain site-owned custom roles.

The screen uses the Core Admin Design Foundation and the shared Object Picker Foundation. Page and document searches remain WordPress-native and do not create a parallel content store.

The screen intentionally avoids legal verdicts. Copy such as "required", "compliant" or "GDPR compliant" must not be inferred from a technical assignment alone.

## Persistence

Base owns two non-autoloaded options:

```text
cb_core_compliance_resource_assignments
cb_core_compliance_custom_resources
```

Referenced Pages and Media Library documents remain ordinary site content and are never deleted by Compliance Resources.
