# Forms Foundation — Core API v1

Core Blueprint Base exposes a small, builder-neutral Forms Foundation for normalized form-submission interoperability.

It allows an admitted WordPress extension to identify itself as a forms provider through the Generic Interoperability Foundation and emit one bounded, immutable submission event after its native form runtime has successfully accepted a submission.

The architectural boundary is:

> **Base owns the normalized Forms interoperability contract. Providers own their native form integration and validation. Consumers own what they deliberately do with submitted data.**

Forms Foundation is not a form builder, submission database, inbox, workflow engine or builder-specific adapter.

## Contract identity

The Base-owned v1 contract identity is:

```text
owner:    core-blueprint
contract: forms.provider
version:  1
```

The canonical constants are:

```php
CoreBlueprint\Core\Forms\Foundation::CONTRACT_OWNER
CoreBlueprint\Core\Forms\Foundation::CONTRACT_ID
CoreBlueprint\Core\Forms\Foundation::CONTRACT_VERSION
CoreBlueprint\Core\Forms\Foundation::SUPPORT_SUBMISSION_EMIT
```

`Foundation::contract_definition()` is an internal read-only definition consumed by Base's private interoperability contract catalog. There is no public Base-owned contract registration API.

Base loads this specialized contract from its internal catalog before dispatching the public Interoperability contract-registration lifecycle. Provider implementations still use the normal public Interoperability implementation path and a normal `ExtensionRegistry` identity. There is no private first-party provider path.

## Public v1 surface

The Forms Foundation public v1 surface is:

```php
CoreBlueprint\Core\Forms\ProviderInterface
CoreBlueprint\Core\Forms\SubmissionEmitter
CoreBlueprint\Core\Forms\SubmissionEvent
```

and the in-request event hook:

```text
cb_core_forms_submission_emitted
```

The only Forms v1 support token is:

```text
submission.emit
```

Providers must not rely on undocumented support-token meanings. New optional Forms features require an explicit Forms contract addition rather than inference from provider internals.

## Provider admission and implementation registration

A forms adapter is an ordinary Core Blueprint extension. It first registers its canonical platform identity through `CoreBlueprint\Core\ExtensionRegistry`, then registers an implementation during the standard Interoperability implementation lifecycle.

```php
use CoreBlueprint\Core\ExtensionRegistry;
use CoreBlueprint\Core\Forms\Foundation;
use CoreBlueprint\Core\Forms\ProviderInterface;
use CoreBlueprint\Core\Interoperability\Registry;

add_action( 'cb_core_register_extensions', static function (): void {
    ExtensionRegistry::register( [
        'id'            => 'acme-forms',
        'plugin_file'   => plugin_basename( ACME_FORMS_FILE ),
        'requires_api'  => '1.0',
        'requires_base' => '1.0.0-rc1',
        'menu_url'      => '',
        'status_id'     => '',
    ] );
} );

add_action( 'cb_core_register_interoperability_implementations', static function (): void {
    Registry::register_implementation( [
        'provider'         => 'acme-forms',
        'id'               => 'default',
        'label'            => __( 'Acme Forms', 'acme-forms' ),
        'description'      => __( 'Connects Acme form submissions to Core Blueprint.', 'acme-forms' ),
        'contract_owner'   => Foundation::CONTRACT_OWNER,
        'contract'         => Foundation::CONTRACT_ID,
        'contract_version' => Foundation::CONTRACT_VERSION,
        'supports'         => [ Foundation::SUPPORT_SUBMISSION_EMIT ],
        'factory'          => static fn (): ProviderInterface => new Acme\Forms\CoreBlueprintProvider(),
    ] );
} );
```

Multiple extensions and multiple implementation IDs may implement the contract simultaneously. Consumers must not assume a single global forms provider.

A provider cannot claim the reserved `core-blueprint` owner identity through the public contract-registration path. Base-owned platform contracts come only from Base's private read-only catalog.

## Runtime availability

A resolved implementation must satisfy:

```php
interface ProviderInterface {
    public function is_available(): bool;
}
```

`is_available()` distinguishes a registered adapter from a runtime that is actually usable, for example when the upstream form/builder plugin is absent or inactive.

Availability is not authorization and does not replace the upstream form system's validation, nonce/CSRF handling, spam protection, rate limiting or business rules. The adapter must call Forms Foundation only after the native provider has accepted the submission according to its own contract.

## Emit a normalized submission

A provider emits through:

```php
$result = CoreBlueprint\Core\Forms\SubmissionEmitter::emit(
    'acme-forms',
    'default',
    'contact-main',
    [
        [ 'id' => 'name',    'value' => 'Ada Lovelace' ],
        [ 'id' => 'message', 'value' => 'Please contact me.' ],
        [ 'id' => 'topics',  'value' => [ 'support', 'billing' ] ],
    ],
    'submission-42', // optional provider submission id
    'acme:form:42'   // optional event id
);

if ( is_wp_error( $result ) ) {
    // Handle the rejected emission in the adapter according to its own workflow.
}
```

The method signature is:

```php
SubmissionEmitter::emit(
    string $provider,
    string $implementation,
    string $form_id,
    array $fields,
    ?string $submission_id = null,
    ?string $event_id = null
): SubmissionEvent|WP_Error
```

The provider and implementation must identify an admitted, registered implementation of `core-blueprint::forms.provider@1` that declares `submission.emit`. The resolved provider must satisfy `ProviderInterface` and report itself available.

## Identifier contract

`form_id` and a non-null `submission_id` are opaque provider identifiers. Base does not convert them into slugs or infer domain meaning.

Each must:

- be non-empty after trimming;
- be at most 191 bytes;
- contain no ASCII control characters or DEL.

`null` is the only representation of an omitted `submission_id`. Supplying an explicit empty string is invalid.

If `event_id` is `null`, Base generates a WordPress UUID. A supplied event ID, including an explicitly supplied empty string, must match:

```text
[A-Za-z0-9][A-Za-z0-9._:-]{0,127}
```

Base does not persist or deduplicate Forms events. Consumers that need their own deduplication must treat provider + implementation + event ID as the event identity rather than assuming an event ID is globally unique across unrelated providers.

## Field transport contract

`fields` is an ordered PHP list. Each item contains exactly:

```php
[
    'id'    => 'field-id',
    'value' => $value,
]
```

The v1 boundary accepts at most 256 fields.

Field IDs use the same opaque identifier bounds as `form_id`, must be unique within one event, and are not converted into labels or schema definitions.

A field value may be:

- `null`;
- `bool`;
- `int`;
- a finite `float`;
- `string`;
- a flat list containing only those scalar/null value kinds.

The transport limits are:

- maximum 65,535 bytes per string value;
- maximum 1 MiB of string data across all field values in one event;
- maximum 100 items in one flat list.

Nested maps, nested lists, objects, resources, non-finite floats, duplicate field IDs and unknown field-entry keys are rejected.

Forms v1 defines no attachment semantics, field-schema discovery, form catalog/discovery or configuration-change contract. A string that happens to contain a URL or filesystem-looking value does not become a stable attachment contract by inference.

## Submission event

A successful emission creates one immutable `CoreBlueprint\Core\Forms\SubmissionEvent` and dispatches it on:

```text
cb_core_forms_submission_emitted
```

The event exposes:

```text
event_id()
provider()
implementation()
form_id()
submission_id()
fields()
occurred_at()
```

`occurred_at()` is generated by Base at emission time in UTC. The event is an in-request delivery boundary only.

Base does not guarantee persistence, queueing, retries or delivery after the originating WordPress request ends.

## Privacy and data ownership

Submitted values may contain personal or sensitive data. Forms Foundation deliberately does not persist or automatically copy those values elsewhere.

Base does **not** automatically:

- save a submission record;
- write field values to the Audit/Governance store;
- write field values to options, posts or custom tables;
- email submitted values;
- forward the raw field map into Automation Foundation;
- classify a submission as medical, sensitive or special-category data;
- copy uploads into another storage system.

Listeners receive raw validated transport values in-process and become responsible for any persistence, logging, classification, retention, authorization and redaction they deliberately introduce.

Provider adapters remain responsible for not smuggling secrets or unsupported provider objects through scalar strings merely to bypass this contract.

## Relationship to Generic Interoperability

Generic Interoperability owns provider admission, exact contract-version discovery, `supports`, private factory storage and runtime interface enforcement.

Forms Foundation owns the platform-level meaning of `core-blueprint::forms.provider@1`, `submission.emit`, the normalized submission transport and the emitted Forms event.

Do not create a second Forms provider registry.

## Relationship to Automation Foundation

Forms Foundation does not create a second event bus and does not automatically emit an Automation trigger.

Automation Foundation has its own bounded trigger schemas. Arbitrary dynamic form-field maps are therefore not silently flattened, JSON-encoded or copied into Automation merely to make them fit that transport.

A future Automation bridge must define deliberate bounded semantics and privacy behavior. Core Blueprint Automations remains responsible for workflows, conditions, persistence, retries and run history.

## Relationship to Governance / Audit

Forms Foundation does not automatically audit submitted field values.

A consumer may record privacy-safe governance metadata through the normal public Governance facade when that is part of its own product contract. Raw form bodies, free-text answers and attachment contents must not be copied into Audit by default.

## Failure contract

`SubmissionEmitter::emit()` fails closed with these stable v1 error codes:

```text
cb_core_forms_not_ready
cb_core_forms_invalid_form_id
cb_core_forms_invalid_submission_id
cb_core_forms_invalid_fields
cb_core_forms_invalid_event_id
cb_core_forms_unknown_provider
cb_core_forms_unsupported
cb_core_forms_provider_unavailable
```

A rejected emission does not dispatch `cb_core_forms_submission_emitted`.

Provider-resolution failures and exceptions from provider availability checks are normalized to `cb_core_forms_provider_unavailable`; internal exception details are not part of the Forms v1 return contract.

## First-party and third-party parity

Official Core Blueprint adapters and third-party adapters use the same `ExtensionRegistry`, Generic Interoperability implementation lifecycle, Forms contract and submission emitter.

Base contains no builder-specific path. If a future first-party adapter needs shared functionality that the public Forms contract cannot express, the contract must be deliberately evolved rather than adding a private adapter-only escape hatch.

## Non-goals

Forms Foundation v1 is not:

- a visual form builder;
- a form-submission inbox;
- a submission database;
- a form-schema/catalog API;
- an attachment-storage API;
- a form-configuration revision system;
- a workflow/orchestration engine;
- an audit log for raw form values;
- a builder-specific adapter;
- an automatic security or legal-basis decision engine.

## Stable v1 boundary

Only the Forms classes, constants, transport rules, error codes and event hook documented here are intended as the Forms Foundation v1 public contract.

The internal Base contract catalog, `Foundation::contract_definition()`, private normalization details and testing helpers are not third-party API merely because PHP can technically reach them.
