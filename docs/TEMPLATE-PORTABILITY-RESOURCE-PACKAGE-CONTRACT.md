# FRG-T4-01B — Minimal Resource Package Contract Candidate

**Status:** Contract + test-only planning proof; archive transport, resource storage and public API **NOT IMPLEMENTED**.
**Baseline:** Base Data Exchange Foundation v1 after merge `6f82a7f4a2eba30f9257785aa94cdc85cbce0214`.
**Related:** [FRG-T4-01B Consumer Audit](TEMPLATE-PORTABILITY-RESOURCE-RECONCILIATION.md) and [Asset-Free JSON Contract](TEMPLATE-PORTABILITY-JSON-CONTRACT.md).

## Architectural decision

**One engine:** the canonical `CoreBlueprint\Core\DataExchange\Engine::preview_json()/apply_json()` continues to own the only import plan, fingerprint, apply and partial-status orchestration. An eventual resource package adapter is a *bounded, offline, read-only verifier and resource staging interface*, **not** a second import engine, alternate record schema, archive-driven mutator or provider capability.

**Two transports:** an asset-free single-template export remains the existing `core-blueprint-data-exchange` JSON envelope with `resources: []`. Resource-dependent templates from Reports/Certificates require a separate, optional, deterministic package transport containing that **same** JSON envelope plus immutable asset bytes. Do not manufacture binary dependencies for Automations/Evaluator/Invoice & Quotes or bundle issued Contracts evidence.

**No executable package format is frozen here.** The following closed-shape manifest is the minimal candidate grammar for a future verified adapter. It is intentionally not an accepted export suffix, public API, ZIP parser or final acceptance claim.

## Candidate logical layout and manifest

A resource package contains exactly one unmodified canonical Data Exchange JSON envelope, one manifest, and zero or more asset payloads. Candidate archive names are fixed by the format, not by an upload filename, site path or external URL:

```text
manifest.json
data-exchange.json
resources/sha256/<64-lowercase-hex-digest>
```

Manifest **candidate version 1** (single resource example; values are illustrative):

```json
{
  "package_format": "core-blueprint-template-resources",
  "package_version": 1,
  "extension_id": "core-blueprint-reports",
  "entity": "template.report",
  "record_count": 1,
  "document": {
    "path": "data-exchange.json",
    "size": 2048,
    "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
  },
  "resources": [
    {
      "key": "brand-logo",
      "path": "resources/sha256/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
      "media_type": "image/png",
      "size": 4096,
      "sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
    }
  ]
}
```

Every key is required; unknown fields, unsupported versions, repeated keys, unsupported paths, unsorted or ambiguous resources and digest mismatches fail closed. The manifest must exactly match the parsed Data Exchange envelope's `extension_id`, `entity`, one-record count, and declared domain document schema. The source template record contains only package-local resource references, **not** WordPress `attachment_id`/`asset_id` or the original source URLs. Each reference resolves to precisely one validated manifest resource and each declared resource must be referenced or explicitly excluded; orphaned data must not be silently accepted.

The manifest's `document.sha256` is a digest of the **exact UTF-8 bytes** of `data-exchange.json` (no JSON re-serialization before integrity verification). Each resource `sha256` is a digest of the exact extracted bytes. The file name is derived from the digest; an archive member name alone is never an integrity check. SHA-256 here confirms content integrity, not source authenticity, signatures, licensing or authorization.

## Proposed bound limits (to be benchmarked before freeze)

| Boundary | Candidate maximum / rule |
| --- | --- |
| Canonical JSON | Retain existing `Foundation::MAX_INPUT_BYTES` (10 MiB) |
| Resources per package | 16 unique canonical keys; no path aliases |
| Individual resource | 5 MiB uncompressed |
| Total resource bytes | 20 MiB uncompressed |
| Raw uploaded package | 24 MiB |
| Resource MIME | `image/png`, `image/jpeg`, `image/webp` initially; no SVG, GIF, remote URLs, XML or fonts |
| Archive structure | Fixed entries; no nested archives, symlinks, absolute/traversal paths, duplicate/case-colliding members, encrypted entries, unsupported compression, directories with payload, or unlisted members |
| Storage/runtime | Private owned staging outside public URL paths, bounded memory/CPU/time, exclusive creation and deterministic cleanup |

These are **proposed safety ceilings**, not approved media capabilities. A strict content sniffer and image decoder must agree with the declared MIME; file extension alone proves nothing. The adapter must validate *all archive central directory and local header constraints* prior to extraction, compare actual uncompressed counts against declared bytes, and reject decompression bombs and conflicting metadata. No file may be extracted to a path derived from untrusted archive names.

## Critical preview/fingerprint binding

**Verified-package provenance must originate in a trusted verifier, never from a caller-supplied `array $context` value.** Passing a digest string from HTTP directly into the provider does not make the file verified.

1. The future adapter validates the full archive, every exact document/resource digest, the current actor, and a private immutable staging identity before invoking Data Exchange. It then supplies the consumer a trusted *bounded projection* of the verified package: package-content fingerprint, canonical document digest, ordered resource keys/digests/sizes/media types and destination rebinding decisions. No raw media bytes enter the JSON record or opaque import plan.
2. Consumer `plan_import()` validates that *every* document resource reference resolves to this verified projection and that destination mappings are authorized. Its opaque `payload` includes a canonical digest/rebind projection, actor identity, destination and normalized document, so Base's existing fingerprint includes these values. The public preview reveals only bounded summaries and warnings, not opaque plan payload or staging paths.
3. At `apply_json()`, the consumer revalidates current permissions, reads the **same pinned private package** and re-verifies the exact resource bytes and digest set. A changed verified projection or destination yields a different Base plan fingerprint and `cb_core_data_exchange_stale_plan`. Missing or invalid resources fail preflight. Base does **not** fingerprint arbitrary external bytes unless the consumer includes their trustworthy digest projection in the recomputed plan.
4. The consumer alone maps staged assets to **new** local media identities, validates its own report/certificate document grammar, creates the draft copy and maintains an explicit resource-creation journal for cleanup on failure. No source WordPress ID or access principal transfers. A truthful `failed` or `partial` result must never be represented as complete. No cross-filesystem/DB atomicity promise.

The existing Data Exchange Engine fingerprints normalized `plans` plus envelope identity and `mode`; it does **not** automatically include arbitrary package bytes or context. This distinction is an explicit precondition of this contract.

## Test-first acceptance matrix

A **test-only provider**, `tests/integration/TemplatePortabilityResourceBindingContractTest.php`, represents Reports' `logo_attachment_id` and Certificates' `asset_id` as package-local resource references. Its in-memory trusted projection stands in for a future verifier; it does not validate ZIPs or media bytes.

- Positive: each domain can preview one `create_only` copy and apply as an independent draft; no foreign attachment ID or source preview URL in a destination record.
- Integrity: tampering document `resources[].sha256`, omitting a verified resource, unknown package identifier or changing a trusted package fingerprint fails before mutation.
- Temporal binding: after preview, changing the verified package fingerprint, destination or current actor yields a stale plan; revoked privileges fail closed at apply.
- Local conflict: destination collision fails without modifying the existing draft.
- Isolation: preview is read-only; opaque resource digest projection does not appear in user-visible preview items.

**Evidence boundary:** these tests prove Base's existing *plan-binding invariant* under a correctly supplied verified-resource projection. They **do not** prove package archive validation, media authorization, content hashing, filesystem cleanup, licensing, or cross-site restore. Those remain mandatory tests **when an actual adapter exists**, and must use representative Reports logo and Certificates artwork bytes on two independent WordPress sites.

## Implementation and governance boundaries

- No change to `Engine`, `EntityInterface`, Data Exchange envelope, WordPress persistence or external consumer repositories for this candidate.
- No legacy/backward-compatibility path and no migration of historical test data.
- No automatic package activation, remote asset fetching, WordPress attachment transfer or SaaS dependency.
- Final ZIP/package format and limits require executable verifier evidence, source security review and product decision before FRG-04 freeze; this candidate must not be advertised as working offline media import/export.

## FRG-T4-01C — Read-only verifier SOURCE (candidate, not transport acceptance)

`CoreBlueprint\Core\DataExchange\ResourcePackageVerifier::verify_file($local_path)` is a strictly read-only inspection API. It parses the candidate ZIP **in memory**, never extracts files, never reads remote URLs, never creates media or draft records, and never invokes `Engine::preview_json()/apply_json()`. It returns only the existing canonical JSON bytes, a package SHA-256, a document SHA-256, the extension/entity and ordered resource digests/sizes/MIME. **A caller cannot submit its own verified projection as trusted authority**. The verifier result is an observation of bytes read during that invocation; it is not an immutable staging handle, signed document, saved import plan or rebind authorization.

The present implementation accepts only fixed-name, ordinary ZIP entries (store/deflate) with exact central/local metadata parity, strict no-gaps/no-overlap layout, no ZIP64/data descriptors, no encryption, extra fields, comments, directories, links, unlisted entries, duplicate names or trailing material. The proposed size ceilings above are enforced before and during bounded decompression. Canonical manifest and Data Exchange document JSON are encoded as `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`; this deliberate exact-byte rule also excludes duplicate JSON object keys and ambiguous encodings. This remains a **candidate package format**, not a public exported format or an accepted release feature.

Image validation presently compares PHP Fileinfo's MIME classification with PHP's `getimagesizefromstring()` image type against the declared PNG/JPEG/WebP MIME. This establishes file-signature/header agreement, **not exhaustive image decoding or safety for display**. Before media persistence, FRG-T4-01D/01E must define full decode/re-encode policy or a verified safe decoder for the installed server capability, actor-bound private immutable staging and package revalidation across preview/apply. A mutable filesystem path or the returned digest alone cannot carry authority across requests. No resource bytes enter the Data Exchange plans or preview responses.

Tests in `tests/python/test_resource_package_verifier.py` construct real ZIP bytes, run the actual PHP verifier under a minimal WordPress-error shim and assert fail-closed behavior without creating WordPress test databases. FRG-T4-01C also requires the project's normal Level 1 gate, development package, and an independent security review before acceptance. **No existing Data Exchange API, consumer, endpoint or storage schema has changed.**
