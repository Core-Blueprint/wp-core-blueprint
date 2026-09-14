# Core Blueprint Data Exchange Foundation v1

## Status

Base-owned public interoperability contract. The Foundation is transport and orchestration infrastructure; it does not own extension data or business semantics.

## Ownership boundary

**Base owns:**

- canonical Data Exchange format/version identity;
- provider/entity discovery through the Generic Interoperability Registry;
- bounded JSON and CSV transport;
- strict envelope validation;
- import modes;
- full preflight before mutation;
- preview summaries;
- deterministic plan fingerprints;
- bounded provider-plan and provider-error projection;
- sequential fail-stop apply semantics;
- CSV spreadsheet-formula protection;
- the provider-neutral Data Mapper primitives and shared Mapper workspace described in `DATA-MAPPER.md`.

**Provider extensions own:**

- entity schemas and schema evolution;
- authorization decisions for import/export;
- portable entity identities and reference resolution;
- record validation and normalization;
- create/update/skip planning;
- canonical domain mutations;
- domain audit events and data-retention meaning;
- authorized upload/download transport and operational routing around the shared Mapper workspace.

Base never writes extension records directly. Providers must delegate `apply_import()` to their canonical domain service/action rather than duplicating business logic inside the Data Exchange adapter.

## Registration

Data Exchange is the Base-owned interoperability contract:

- owner: `core-blueprint`
- id: `data-exchange.entity`
- contract version: `1`

Each importable/exportable entity is one interoperability implementation. `provider` MUST be the extension's canonical `ExtensionRegistry` id. The implementation `id` is the provider-owned entity id.

Supported feature tokens are:

- `export`
- `import`
- `mapping`
- `format.json`
- `format.csv`

Declaring CSV support additionally requires `CB\Core\DataExchange\CsvEntityInterface`. Declaring mapping support additionally requires `CB\Core\DataExchange\MappingEntityInterface`; see `DATA-MAPPER.md`.

Example registration:

```php
add_action( 'cb_core_register_interoperability_implementations', static function (): void {
    \CB\Core\Interoperability\Registry::register_implementation( [
        'provider'         => 'core-blueprint-example',
        'id'               => 'thing',
        'label'            => __( 'Things', 'core-blueprint-example' ),
        'description'      => __( 'Portable example records.', 'core-blueprint-example' ),
        'contract_owner'   => \CB\Core\DataExchange\Foundation::CONTRACT_OWNER,
        'contract'         => \CB\Core\DataExchange\Foundation::CONTRACT_ID,
        'contract_version' => \CB\Core\DataExchange\Foundation::CONTRACT_VERSION,
        'supports'         => [
            \CB\Core\DataExchange\Foundation::SUPPORT_EXPORT,
            \CB\Core\DataExchange\Foundation::SUPPORT_IMPORT,
            \CB\Core\DataExchange\Foundation::SUPPORT_MAPPING,
            \CB\Core\DataExchange\Foundation::SUPPORT_JSON,
        ],
        'factory'          => static fn() => new ExampleDataExchangeEntity(),
    ] );
} );
```

Discovery does not authorize a mutation. The resolved entity's `can_import()` / `can_export()` decision is checked for every operation.

## JSON transport

JSON is the full-fidelity machine transport. The v1 envelope is exact and self-describing:

```json
{
  "format": "core-blueprint-data-exchange",
  "format_version": 1,
  "extension_id": "core-blueprint-example",
  "entity": "thing",
  "schema_version": 1,
  "exported_at": "2026-09-12T18:30:00Z",
  "records": []
}
```

`format_version` belongs to Base. `schema_version` belongs to the provider entity. Unknown Base format versions fail closed. Providers explicitly declare which of their own schema versions remain importable.

## CSV transport

CSV is the human/spreadsheet transport. It remains self-describing instead of relying on a filename or UI selection.

The first columns are Base-reserved:

```text
cb_row_type,cb_format,cb_format_version,cb_extension_id,cb_entity,cb_schema_version
```

The header is followed by exactly one `meta` row. Entity-owned columns follow the Base columns. Data rows use `cb_row_type=data` and leave all other Base metadata cells empty.

Entity column ids are stable machine ids. Base owns CSV quoting/parsing and protects cells whose first effective spreadsheet token could be interpreted as a formula, including trigger characters preceded by whitespace. The provider maps its canonical records to/from flat CSV rows.

JSON remains the fidelity format when a domain cannot be represented safely as a flat human-oriented row.

## Import lifecycle

The public lifecycle is:

```text
input
  -> bounded parse
  -> envelope + schema validation
  -> provider authorization
  -> provider record planning (no mutation)
  -> bounded complete preflight
  -> preview + fingerprint
  -> explicit apply using original input + fingerprint
  -> re-parse + re-plan
  -> fingerprint comparison
  -> sequential canonical domain mutation
```

Preview never returns provider plan payloads. It exposes only operation, portable reference, bounded warnings and bounded validation errors.

Apply does not trust a plan sent back by a browser or caller. It recomputes the plan from the original source and requires the preview fingerprint to match. If current domain state changes in a way that changes the provider's canonical plan, apply fails with `cb_core_data_exchange_stale_plan` and a new preview is required.

Provider planning must be deterministic for the same source, mode and relevant domain state. Non-deterministic provider plans intentionally fail the preview/apply fingerprint boundary instead of being silently accepted.

## Import modes

v1 supports:

- `create_only`
- `update_existing`
- `create_update`

There is deliberately no `delete_missing` or destructive synchronization mode in v1.

Providers return one operation per record:

- `create`
- `update`
- `skip`

Base rejects an update plan in `create_only` mode and a create plan in `update_existing` mode.

## Apply and failure semantics

All records must preflight successfully before Base starts mutation.

Within one import, every planned record must expose a unique portable `reference`; duplicate references fail preflight before any mutation. Portable references and extension/entity identities are exact contract identities; surrounding whitespace is not silently normalized into validity.

Apply is sequential and fail-stop. Core Blueprint does **not** pretend that unrelated WordPress/domain writes can be wrapped in one universal cross-extension transaction. If an apply callback fails after earlier records have succeeded, the result is explicitly `partial` and includes only safe record indexes/references plus a bounded provider error code/message.

If a provider explicitly returns an apply `reference`, that reference must satisfy the portable-reference contract. An invalid explicit reference is an apply-contract failure; Base never silently falls back to the planned reference.

Providers should use stable portable identity and idempotent canonical domain actions where their domain permits safe retry/recovery.

## Transport limits

v1 is intentionally bounded:

- maximum input/output transport: 10 MiB;
- maximum aggregate normalized provider import-plan payload: 10 MiB;
- maximum records: 5,000;
- maximum provider CSV columns: 256;
- provider warning count/length and exposed provider error text are bounded;
- nested transport values are depth/container bounded;
- non-finite floats, objects and resources are rejected.

These are Base transport limits, not domain batching guarantees. Future streaming/background import is a separate Foundation evolution rather than an implicit unbounded request-time path.

## Governance and privacy

The Base engine does not persist import payloads and does not copy record content into AuditLog.

The owning extension should register and record meaningful domain events such as a completed import/export using the public Governance/Audit contract. Audit context should contain only necessary identifiers and counts, never the exchanged payload itself.

## UI boundary

The Data Exchange engine itself remains headless: it provides canonical transport, preview and apply authority without owning an extension's operational route or browser mutation transport.

Base additionally provides the shared **Data Mapper** workspace documented in `DATA-MAPPER.md`. The Mapper is a consumer of the public Designer Shell and standardizes file intake, field mapping and preview presentation. It does not authorize or apply extension data by itself.

Owning extensions remain responsible for their operational import/export route, nonce/CSRF and capability enforcement, upload/download transport, provider selection and final invocation of the canonical Data Exchange preview/apply methods. A first-party extension must use the same public Data Exchange/Mapper contracts available to third-party providers.
