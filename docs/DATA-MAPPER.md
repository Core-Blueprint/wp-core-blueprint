# Core Blueprint Data Mapper v1

Status: **draft public Base Foundation contract**.

The Data Mapper is the shared schema-transformation layer above the Core Blueprint Data Exchange Foundation. It gives imports and exports the same mapping vocabulary and the same Core Blueprint Designer workspace without moving extension business semantics into Base.

## Ownership boundary

Base owns:

- mapping field-schema normalization;
- deterministic alias/id/label matching;
- direct, constant and ignore mapping primitives;
- bounded generic CSV header/record inspection;
- bounded mapping definitions and mapped canonical output;
- mapping validation and fingerprints;
- source-shaped → target-shaped record transformation;
- the shared Data Mapper workspace and interaction contract;
- request-local source-file intake presentation;
- integration with the public shared Designer Shell.

Extensions own:

- entity field meaning and schema evolution;
- which fields are readable/exportable and writable/importable;
- authorization;
- canonical record validation;
- portable identity/reference resolution;
- create/update/skip planning;
- canonical mutations;
- audit meaning and retention;
- the authorized server transport used to inspect, preview and apply an uploaded file;
- download transport and target serialization around export workflows.

External platform-specific semantics do not belong in Base. A Brevo, Mailchimp or other platform mapping profile can declare a schema/mapping outside Base and use the same Mapper primitives.

## Canonical flow

Import:

```text
external CSV / JSON
    ↓
source field inspection
    ↓
Data Mapper
    ↓
canonical extension-shaped records
    ↓
Data Exchange envelope
    ↓
Data Exchange preview
    ↓
extension plan_import()
    ↓
explicit apply
    ↓
extension canonical actions/domain
```

Export:

```text
extension canonical records
    ↓
Data Exchange export
    ↓
Data Mapper
    ↓
target/profile-shaped records
    ↓
target serializer/download
```

The Mapper never writes extension data itself.

## Optional entity mapping schema

An entity that exposes mapping metadata implements:

```php
CB\Core\DataExchange\MappingEntityInterface
```

in addition to the normal Data Exchange entity contract and declares the `mapping` support token through Generic Interoperability.

`mapping_fields()` returns a bounded list of fields. Each field supports:

```php
[
    'id'          => 'email',
    'label'       => 'Email',
    'type'        => 'string',
    'required'    => true,
    'readable'    => true,
    'writable'    => true,
    'aliases'     => [ 'EMAIL', 'email address', 'e-mail' ],
    'description' => 'Primary contact email address.',
]
```

Supported v1 types are:

- `string`
- `integer`
- `number`
- `boolean`
- `date`
- `datetime`
- `enum`
- `reference`
- `json`

`required`, `readable` and `writable` use actual boolean values. Base does not accept truthy strings or silently coerce schema metadata.

Type metadata is descriptive in Mapper v1. Domain validation and business coercion remain provider-owned. Base does not silently convert ambiguous values.

## Deterministic auto-match

`Mapper::suggest()` compares normalized field ids, labels and aliases. It does not perform fuzzy or semantic guessing.

A source field is auto-mapped only when its normalized tokens identify exactly one unclaimed target field. Ambiguous or unknown fields become `ignore` suggestions so the operator must choose deliberately.

Example:

```text
EMAIL       → email
FNAME       → first_name
COMPANY     → company
LEGACY_NOTE → Ignore
```

If two target fields both advertise `NAME` as an alias, Base does not guess which one should receive `NAME`.

## Mapping primitives

V1 defines three transport-neutral transforms:

### `direct`

Copies one source field into one target field.

```php
[
    'source'    => 'EMAIL',
    'target'    => 'email',
    'transform' => 'direct',
    'value'     => null,
]
```

### `ignore`

Explicitly omits one source field.

```php
[
    'source'    => 'LEGACY_NOTE',
    'target'    => null,
    'transform' => 'ignore',
    'value'     => null,
]
```

### `constant`

Supplies a fixed transport-safe value to a target field.

```php
[
    'source'    => null,
    'target'    => 'country',
    'transform' => 'constant',
    'value'     => 'NL',
]
```

Mapping transform identifiers are canonical exact tokens; Base does not trim a malformed token into validity.

The complete normalized mapping definition is bounded by the shared 10 MiB Data Exchange transport limit. `Mapper::map_records()` also applies that limit cumulatively to canonical mapped output, so a reusable `constant` cannot multiply a small source into an unbounded in-memory recordset before the final exchange envelope is encoded.

The headless engine supports all three. The first shared UI focuses on the common direct/ignore field-mapping workflow; richer constant/profile authoring can evolve without changing the canonical mapping shape.

V1 intentionally does not include formulas, arbitrary callbacks, expressions, split/join logic or conditional execution. Those would turn Data Mapper into an orchestration/runtime language and require a separate proven need.

## CSV inspection

`Mapper::inspect_csv()` supports bounded generic CSV inspection for mapping workflows.

V1 rules:

- maximum input size follows the Data Exchange transport limit;
- maximum records and columns follow Data Exchange limits;
- UTF-8 BOM is accepted and removed;
- NUL bytes fail closed;
- comma, semicolon and tab delimiters are supported;
- `auto` chooses the delimiter producing the widest first-row structure;
- headers must be non-empty and unique;
- every data row must have exactly the header column count.

Generic external CSV is not treated as a canonical Data Exchange file. It must first be mapped into the extension schema.

## Canonical import handoff

After mapping, call:

```php
$mapped = Mapper::map_records(
    $inspection['records'],
    $inspection['fields'],
    $target_fields,
    $mapping
);

$json = Mapper::exchange_json(
    'vendor-extension',
    'contacts',
    1,
    $mapped
);
```

The resulting JSON is a normal `core-blueprint-data-exchange` envelope and therefore goes through the existing Data Exchange preview/apply contract. The Mapper cannot bypass provider `plan_import()` or canonical mutations.

## Shared Data Mapper workspace

The shared PHP renderer is:

```php
CB\Core\DataExchange\Mapper\Renderer::render()
```

It consumes the public Designer Shell through:

```php
CB\Core\DataExchange\Mapper\Assets::enqueue()
```

The Mapper does **not** introduce a new document editor profile. It uses the generic Designer Shell directly because a mapping workspace is not a document/canvas domain.

The shared workspace contains:

```text
Designer toolbar
├── history
├── status
├── fullscreen/focus mode
└── primary action

Workspace
├── Source fields / source-file intake
├── Mapping canvas
└── Mapping / Preview details
```

Consumers may choose manual or direct Designer Mode. Direct mode is appropriate when an extension opens a dedicated import/export mapping route and wants closing the workspace to return directly to its operational page.

## Import file intake

For an import route that does not know the source schema until the operator chooses a file, render with:

```php
Renderer::render( [
    'direction'     => Foundation::DIRECTION_IMPORT,
    'intake'        => true,
    'target_fields' => $target_fields,
] );
```

Import intake may start with an empty source schema. Export workflows may not use intake as a substitute for a real canonical source schema.

The selected browser `File` remains request-local/browser-local. Base does not copy it into an option, transient, session, IndexedDB, local storage or a Base-owned temporary-file store.

The browser emits:

```text
cb:data-mapper:file-selected
```

with the selected `File` plus display-safe name/size/type metadata. The owning extension then sends that same file through its own authorized server endpoint. That endpoint is responsible for capability checks, nonce/CSRF protection, file-size/type/content checks and calling the appropriate Base inspection method such as `Mapper::inspect_csv()`.

After successful server-side inspection, the consumer supplies the normalized source schema to the existing workspace:

```js
controller.setSourceFields(sourceFields);
```

The Mapper then performs deterministic suggestions and enables manual mapping.

When the operator chooses Validate/Preview, `cb:data-mapper:submit` contains the current `File` and mapping. The extension must send both through its authorized server transport again. The server maps the records, builds the canonical Data Exchange envelope and calls the Data Exchange provider preview. Browser-side validation never replaces server-side provider validation.

On final apply the consumer must use the Data Exchange preview fingerprint/re-plan contract. The browser mapping itself is not an authorization or integrity token.

## Browser contract

Base exposes:

```js
window.cbCore.dataMapper.create(root, configuration)
window.cbCore.dataMapper.get(root)
```

The controller exposes:

- `file()`
- `sourceFields()`
- `targetFields()`
- `mapping()`
- `validation()`
- `setSourceFields()`
- `setTargetFields()`
- `replaceMapping()`
- `autoMatch()`
- `setValidation()`
- `setBusy()`

Events:

```text
cb:data-mapper:ready
cb:data-mapper:change
cb:data-mapper:file-selected
cb:data-mapper:submit
```

`cb:data-mapper:submit` is deliberately a request for the consumer to perform its server-side preview/export workflow. Base does not turn a browser click into an extension mutation by itself and the shared browser runtime owns no generic fetch/AJAX route.

A consumer can return canonical preview information through `controller.setValidation()` so the shared Preview panel can show create/update/skip/error counts or other safe summaries.

`controller.setBusy( true, message )` is the v1 in-flight gate for consumer-owned server inspection/preview/apply work. While busy, the shared workspace disables semantic mutations: primary submit, file replacement, auto-match, mapping controls and Undo/Redo. Re-rendering does not clear that gate. `setSourceFields()` remains available while busy because it is the expected completion handoff from source-file inspection; the consumer should call `setBusy( false )` after it has applied the inspected schema/result.

The v1 controller lifetime is tied to the rendered root/page. There is deliberately no public `destroy()`/recreate contract because the shared Designer Shell does not currently expose a teardown lifecycle; Base does not promise a partial teardown that would leave Shell listeners/session state attached.

## Mapping profiles

A Mapping Profile is a reusable combination of:

- direction (`import` or `export`);
- source schema identity;
- target schema identity;
- mapping definition;
- optional serializer/platform metadata owned by the profile provider.

Examples could include `Brevo Contacts v1`, `Mailchimp Contacts v1` or a customer-specific accounting CSV, but Base does not ship those platform semantics in the Data Mapper Foundation itself.

Profile persistence/marketplace distribution is intentionally outside this first Foundation patch. The canonical mapping shape is designed so those capabilities can be added without changing entity schemas.

## Security and governance

- Mapping UI validation is convenience only; server-side Data Exchange/provider validation remains authoritative.
- Browser mutations still require the consumer transport to enforce nonce/CSRF and provider authorization.
- Selected files remain browser/request local unless the owning extension deliberately submits them to its secured endpoint.
- Mapping definitions and mapped canonical output are transport-bounded before the Data Exchange handoff.
- Mapper source/target records are not logged by Base.
- Preview/apply remains protected by the Data Exchange plan fingerprint.
- Ambiguous matches fail safe to manual mapping.
- In-flight server work uses the shared busy gate so client-side semantic mapping state cannot drift underneath a pending preview/apply response.
- No arbitrary PHP callbacks, serialized objects or executable mapping expressions are accepted.
- Mapper state is request/browser state unless a future explicit Mapping Profile persistence contract is used.

## Consumer rule

First-party consumers must use the same public Mapper/Data Exchange contracts available to third-party extensions. A first-party extension must not bypass the Foundation with private Base data structures or direct persistence.
