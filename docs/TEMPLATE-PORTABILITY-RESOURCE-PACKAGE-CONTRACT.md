# FRG-T4-01B — Minimal Resource Package Contract Candidate

**FRG-T4-01F source checkpoint (14 November 2026; unmerged):** Base Foundation-completion branch adds `ResourcePackageExport::create($extension_id, $entity_id, $context, $read_resource, $private_root)`. It invokes the canonical, permission-gated `Engine::export_json()`, requires exactly one record and 1–16 sorted, SHA-256-bound local image resources, re-exports after the provider reads bytes to detect revoked actor/permission or changed records, writes a random 0600 `.export.zip` only inside a previously admitted 0700 private root, and validates it against the existing `ResourcePackageVerifier` before returning the private path and digests. The producer exposes explicit `discard()` plus bounded `prune_expired()` recovery for its own export filename namespace; it never prunes `.zip` tickets held by import staging. The provider callback is responsible for local resource access and domain authorization. The caller must protect transport/delivery, revalidate request privileges and delete the file; neither private paths nor raw media may be exposed in browser metadata. The export method does **not** add an HTTP route, media writer, image re-encoder, persistent ticket or site-to-site product integration. It is not a public-release exporter or proof of consumer-complete import. Local producer regression: 4 source-level methods PASS with a ZIP/verifier testdouble, 2 real-ZipArchive tests SKIPPED locally (optional extension absent); full Base Level 1, exact-head WordPress L2/Level 3, real package ZIP and FIELD remain outstanding. The source candidate must not be called FRG-04 Freeze-ready without those gates.\n\n**FRG-04 reconciled status (6 November 2026):** Internal Base resource package verification (`ResourcePackageVerifier`), private staging (`ResourcePackageStage`), scoped canonical Data Exchange preview/apply binding (`ResourcePackageImport`) and real-ZIP-to-test-consumer integration regression are **implemented and merged** at `main@18023119473f27a95eac3215b25fcc30d115641f`. Chris reported 8/8 ZIP-enabled end-to-end tests, Level 1 and dev ZIP/checksum PASS at the accepted FRG-T4-01E feature HEAD `4a01194ee097cc54310b0a859f1d5d896bb3cac6`; exact terminal logs were not independently supplied. **Not implemented:** consumer HTTP upload/export routes, actual Reports/Certificates asset import, image decode/re-encode, storage/recovery, and two-site FIELD transfer. This candidate manifest is a supported internal verifier grammar, **not** a frozen public consumer package API or product-complete offline import. Historical development-status paragraphs below describe their individual implementation checkpoints.
**Baseline:** Base Data Exchange Foundation v1 after merge `6f82a7f4a2eba30f9257785aa94cdc85cbce0214`.
**Related:** [FRG-T4-01B Consumer Audit](TEMPLATE-PORTABILITY-RESOURCE-RECONCILIATION.md) and [Asset-Free JSON Contract](TEMPLATE-PORTABILITY-JSON-CONTRACT.md).

## FRG-T4-01G — Verified resource-byte handoff (14 November 2026; source candidate)

The internal Base resource package bridge now provides an **apply-only, plan-bound** read capability, not a second import engine or media repository:

1. During `ResourcePackageImport::preview()`, `bind_plan()` can attach the verified projection, but `read_resource($plan, $context, $key)` refuses before opening media bytes.
2. During `ResourcePackageImport::apply()`, after matching the preview fingerprint, the owning `EntityInterface::apply_import()` must call `assert_bound_plan()` and may call `read_resource()` for required keys. Only a present key from the currently active signed, actor-/destination-bound package is readable. Missing keys, wrong actor/context, invalid plan, expired or revoked ticket and modified package fail closed.
3. `ResourcePackageStage::read_resource()` verifies the whole private staged package again, reads the exact SHA-256-addressed entry with a 5 MiB bound, checks length/digest and hashes the package after the read. No private path, external URL, attachment ID or media-write authority is returned.
4. The consumer **must independently** enforce domain document schema, current ACL, destination identity, image decode/re-encode, media ownership, journal/rollback, draft creation and cleanup. Byte integrity alone does not satisfy those responsibilities.

This remains an **internal candidate**, not a public frozen package API or end-user ZIP import workflow. Exact GitHub-blob-parity local read-scope regression: 3/3 PASS (including wrong actor, destination, resource key and tampered plan) on `ResourcePackageImport.php` and `test_resource_package_reader.py`. Real-ZIP integration fixture now includes preview-denial, SHA-matched resource consumption and unknown-key negative cases, but its ZIP-enabled run and full WordPress Level 2/FIELD remain pending. No consumer patch, domain media write or release.

## FRG-T4-01H/01I — Canonical apply-phase fence and authenticated HTTP intake (14 November 2026; source candidate)

**01H — No media reads during re-planning.** The canonical `DataExchange\Engine` now exposes a read-only internal witness that is true only while an actual provider `apply_import()` callback is executing. `ResourcePackageImport::read_resource()` requires that witness in addition to the active signed stage, plan, actor, destination and key. The second plan/fingerprint comparison inside `Engine::apply_json()` is therefore **never** a byte-read authority, even if the Bridge has entered the broader apply transaction. The witness is scoped by `try/finally` and rejects nested import apply callbacks. The real-ZIP and isolated reader regression fixtures both include a forbidden re-plan byte-read probe.

**01I — Base-owned authenticated multipart transport.** `ResourcePackageController` is registered during Base boot and owns four WordPress REST POST actions under `core-blueprint/v1/template-resource-package/`: `stage`, `preview`, `apply`, `discard`. Every endpoint requires a logged-in actor, `manage_options`, the verified `X-WP-Nonce`/ `wp_rest` pair and current POST authority. Multipart stage admits exactly one real PHP-uploaded `package` file plus one strict `destinationId`; it does not trust the source filename/MIME. Stage copies and verifies that ZIP in a 0700 site-scoped directory derived from OS temporary storage, strictly outside WordPress/web document roots, then returns only a signed actor-/destination-bound 15-minute ticket. Invalid storage or missing ZipArchive fails closed. Canonical `ResourcePackageImport::preview()` and `apply()` continue to replan through the **existing** Data Exchange Engine and provider-controlled `EntityInterface`. Exact payloads, one-record create-only semantics, approved fingerprint and confirmed reference are enforced. Apply errors or revocation after an attempted write are reported as `unknown`, never as a safe automatic retry. Responses use `Cache-Control: private, no-store`; discard deletes only the signed current actor's stage. A bounded hourly WordPress-cron job prunes expired stage and export files separately, and Base clears that schedule on deactivation; stage also performs opportunistic cleanup. No public filename, host filesystem path, document HTML, raw bytes, opaque plan or direct media authority is returned to the browser.

**Important remaining limits:** this closes the **Base HTTP intake boundary**, not the complete offline cross-site product journey. The Base native Mail JSON flow remains unchanged. Media-bearing providers still need their own export callbacks, image decode/re-encode and domain media mapping/journal; the provider-gated ZIP download is now a separate SOURCE candidate (01J), but there are no user-visible ZIP import controls, no Reports/Certificates consumer changes, and no Site A/B FIELD acceptance. These are explicitly separate completion gates. This is not an approved public stable resource-package API.

Regression sources added/updated: `tests/python/test_resource_package_http.py` (WordPress REST/API shims: route registration, nonce, capability, strict multipart, private directory, read-only preview, confirmed/unknown apply, cleanup, no-store); `test_resource_package_reader.py` and `test_resource_package_e2e.py` (strict callback-phase access and second-plan denial). **These new exact-head tests and L1/L2 have not yet been executed by the operator or independently in a real WordPress runtime.** The prior Level 1 PASS at `cf4ca179e67d0b22079e34099f1700a8ab0424b8` and its verified dev ZIP apply only to that older source commit. Continue development-first; defer combined qualification and operator FIELD to a complete Base candidate.

## FRG-T4-01J — Provider-authorized offline ZIP delivery (14 November 2026; SOURCE candidate)

**Export is opt-in, not generic media access.** `TemplateResourceExportSourceInterface` extends the existing registered Template Library source boundary and requires a domain-owned Data Exchange entity ID, exact template/version/actor authorization, immutable export context (`templateId`, `version`, `actorId`) and a callback returning already authorized local resource bytes. `TemplateSourceRegistry::export_resource_package()` binds these server-owned values to `ResourcePackageExport::create()` and rechecks the current actor, browse/view and resource export admission before, during and after resource reads. Provider callbacks may throw or revoke; Base refuses and cleans the created export in that case. The ZIP is still verified by the existing production verifier; the source cannot hand Base an arbitrary downloadable file.

**Delivery has no public link.** The Base `admin_post_cb_core_template_resource_export` handler accepts only a logged-in, `manage_options` WordPress POST with a valid `wp_rest` nonce and the exact `action`, `source`, `templateId`, `version` fields. No client-provided paths, URLs, entity IDs, media bytes, export contexts or callbacks are accepted. The handler creates the export in the existing 0700 private temp root, verifies 0600 file mode, scoped name, size and SHA-256 before streaming bounded chunks with `Content-Type: application/zip`, `Content-Disposition: attachment`, `Cache-Control: private, no-store`, `nosniff` and no referrer. `finally` closes and removes the export file. The browser sees no server path or signed token; a request is **not** represented as a confirmed download if the browser or network fails.

**Library behavior.** Global Template Library listings expose `resourcePackageExport` only when the currently authorized source implements and admits this optional contract for that exact record/version. The shared Actions menu then offers a translated `Export ZIP` action via a strict same-origin `admin-post.php` POST form; the contextual Designer Browser does **not** advertise this global export route. Existing JSON/management operations, preview, Designer Save/Exit and consumer-owned Apply remain independent. All six shipped locale catalogs include the new label. **No first-party Base Mail resource-bearing source currently opts in**, so normal asset-free Mail templates correctly keep JSON and show no fictitious ZIP action. An external media consumer must not be modified before separate owner GO and Foundation acceptance.

**New qualification requirements:** the real ZIP exporter/verifier fixtures; `tests/python/test_resource_package_source.py` (provider identity/context/revocation); `tests/python/test_resource_package_download.py` (POST authority, strict payload, digest, stream/dispose); `tests/js/template-resource-export-ui.test.mjs` (global capability, safe form submission and CSS-neutral accessible action); full Level 1 and disposable WordPress Level 2. **These newest tests have not been executed on this new HEAD; source implementation is not yet runtime-qualified.** Last owner-verified Level 1 PASS was `887f61ce3406315efd2becec223bdf6eac875147` with internal dev ZIP SHA-256 `09f816807a84b5613f70a6f84eba8f676a7389c330c63cb41366303e5e311e94`. That evidence does not transfer to this implementation.

**Still open:** user-visible ZIP upload/preview/apply workflow in the Template Library (currently server REST only), consumer-owned resource ingest/rebinding and cleanup, Web/WordPress HTTP and browser qualification, first-party Mail/Reports lifecycle polish, complete Foundation freeze. A Site A→B media template claim stays HOLD until an actual consented consumer demonstrates it. No release, merge, CI/CD or deployment is authorized.

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

`CoreBlueprint\Core\DataExchange\ResourcePackageVerifier::verify_file($local_path)` uses **PHP's optional `ZipArchive` extension**, not a custom archive parser. Without ZipArchive, the package-only operation refuses with `cb_core_resource_package_unavailable`; Base itself does not require the extension. The verifier never extracts files, creates media, writes a record or invokes `Engine::preview_json()/apply_json()`.

The ZIP is opened read-only with `ZipArchive::CHECKCONS`. The adapter enumerates every entry by index, accepts only `manifest.json`, `data-exchange.json` and the exact digest-derived `resources/sha256/<digest>` paths, validates ZIP entry size/compression/CRC, Unix non-symlink mode and allowlisted image data. Uncompressed entry reads are explicitly length-bounded. A fixed maximum archive size and per-entry/total resource ceilings prevent unconstrained parsing or decompression; unknown files, path aliases, duplicate names and extra resource members are refused. Unsupported compression and entries that libzip identifies as encrypted fail closed (an explicit `statIndex()['encryption_method'] === ZipArchive::EM_NONE` check). The verifier relies on libzip for ZIP structural integrity rather than reimplementing central/local directory parsing. Note that libzip's `CHECKCONS` can ignore a general-purpose encryption flag changed **only** in the local header while the central directory declares an unencrypted file; this malformed header-only variant is not covered by the encryption guarantee, and its raw archive must not be handed to another ZIP parser as trusted content. **Do not claim a blanket prohibition on ZIP64 metadata, comments/trailing bytes or every malformed ZIP variant unless libzip and targeted fixtures prove it.** The manifest and JSON must re-encode exactly using the existing Data Exchange JSON encoder flags, excluding duplicate JSON object keys and ambiguous serializations.

Results include a package SHA-256 observed before and after reading, canonical document bytes, a document digest, source extension/entity and ordered resource digests/MIME/sizes. These are **observations, not authorization or an immutable staging token**. FRG-T4-01D must pin bytes in private actor-bound storage and revalidate at apply; a mutable path or caller-submitted resource projection cannot confer trust.

Image validation checks Fileinfo MIME and `getimagesizefromstring()` type against declared PNG/JPEG/WebP. This is header/type agreement, **not a full safe image decode**. Before media writes, FRG-T4-01D/01E must implement an accepted decode/re-encode policy, resource mapping authority, staging ownership and deterministic cleanup. No resource bytes enter Data Exchange plans or preview responses.

`tests/python/test_resource_package_verifier.py` contains real generated ZIP fixtures. Positive archive regressions run only when the optional ZipArchive extension is installed; a separate test executes PHP without extensions and verifies fail-closed unavailability. The targeted run, Level 1 gate, development build and security review remain required before FRG-T4-01C acceptance. **No Data Exchange Engine, consumer, API route, media store or database schema is changed.**

## FRG-T4-01D-1 — Private staging boundary (SOURCE; integration pending)

`CoreBlueprint\Core\DataExchange\ResourcePackageStage` adds a **narrow internal staging service**, not an HTTP endpoint, consumer adapter, template import engine or media writer. The caller supplies a **server-configured** private directory that already exists with mode `0700`, outside the WordPress installation, content directory and public document root. If that boundary cannot be verified, staging refuses; it never silently falls back to web-accessible uploads or creates shared directories. A future entrypoint MUST authenticate requests, validate nonce/CSRF, require `is_uploaded_file()` on real web uploads, and derive capability/destination/root exclusively from trusted server-side decisions, not arbitrary HTTP input.

`stage($source, $root, $destination, $capability)` requires the current actor and explicit capability, uses a random exclusive `0600` file, copies at most 24 MiB, invokes the real read-only verifier on the copied bytes and returns only a signed 15-minute ticket. The ticket binds actor, destination, capability, package SHA-256 and expiry using WordPress auth salts; no stage path appears in the ticket. `inspect($ticket, $root, $destination)` checks the current actor/capability and signed context and **re-runs** the verifier against the same staged package. A changed digest, revoked capability, changed destination, missing or linked package fails closed. `discard` removes only that actor's signed package file; `prune_expired` opportunistically removes older known-name staging files with bounded scanning, leaving unrelated files untouched.

**Explicit remaining boundary:** `inspect` returns verified envelope/resource metadata, not an authorization grant to write media. There is no connection to `Engine::preview_json()/apply_json()`, no durable immutable OS/filesystem handle or copy guarantee against a writer sharing the PHP account, no per-consumer domain policy, no image re-encoding, and no attached-media creation journal yet. The subsequent FRG-T4-01D slice must ensure the consumer re-inspects the stage at apply, embeds the trusted resource projection in its opaque canonical Data Exchange plan, rechecks the consumer's own permissions and destination, provisions the private storage root, and wires deterministic finalization plus scheduled orphan cleanup. **Do not represent D-1 as completed template import/export.**

`tests/python/test_resource_package_stage.py` covers the staging boundary in isolation with a **test-only verifier fixture**, not real ZIP decoding, WordPress DB integration or cross-site acceptance. Actual `ResourcePackageVerifier` ZIP coverage remains in `tests/python/test_resource_package_verifier.py`. No changes were made to any existing Data Exchange Engine API, Template Library endpoint, consumer, database schema, media storage or import/export format.

## FRG-T4-01D-2 — Scoped preview/apply binding (SOURCE; consumers pending)

`CoreBlueprint\Core\DataExchange\ResourcePackageImport` is a narrow internal adapter to the **existing** `Engine::preview_json()/apply_json()`. It is not a new import engine, endpoint, media writer or persistent resource identity. It accepts a trusted private-stage ticket, server-selected private root and selected destination. It calls `ResourcePackageStage::inspect()` before each preview **and** apply invocation, then runs only `Foundation::MODE_CREATE_ONLY` under an in-process, random, non-reusable scope. User-supplied context is not permitted to provide the scope, intent, package binding or destination; the adapter itself supplies `intent=create_personal_copy` and the server-selected `destination_id`.

**Required consumer contract:** An entity implementation wishing to handle resource packages must call `ResourcePackageImport::bind_plan($plan, $context)` as the final step of `plan_import()`, after checking its own document schema, destination, local ownership and matching **every source resource declaration against the verified binding's ordered digest/MIME/size projection**. This adds the verified binding to its **opaque plan payload**. The binding contains actor, destination, exact staged package/document SHA-256, canonical `extension_id`/`entity` and ordered resource digests/sizes/media types. The **existing Engine** therefore includes those values in its canonical fingerprint without altering its schema or algorithm. Inside `apply_import()`, the consumer must call `ResourcePackageImport::assert_bound_plan($plan, $context)` immediately before its own canonical write and independently recheck all domain permissions and destination uniqueness. Bypassing the scoped adapter with arbitrary context does not create a verified binding.

The scoped adapter requires exactly one bound plan. At apply, it first runs a **read-only Engine preview**, verifies opt-in and compares the expected existing Engine fingerprint **before** invoking `Engine::apply_json()`. Engine then performs its usual independent re-plan and stale fingerprint check before executing the consumer's apply callback. Missing opt-in, changed actor/destination, revoked permissions, changed staged package bytes, invalid resource projection or stale fingerprint must never reach a consumer mutation. The binding is intentionally not exposed in the public preview items. There is no second fingerprint or new Data Exchange envelope.

`tests/python/test_resource_package_import.py` exercises the real Base Engine through a test-only `Registry` entity and a deterministic **stub** of `ResourcePackageStage`; tests cover happy path draft, stale package digest, mismatched source resource digest, changed actor, revocation, changed destination, missing plan binding, missing second-plan binding, context spoofing and invalid fingerprints. The real ZIP verifier and stage boundaries are tested separately by the existing 01C and 01D-1 regression suites. **Acceptance remains pending Level 1, dev build and owner review**.

**Explicitly not implemented:** no public upload route/nonce handler, consumer-specific Reports/Certificates binding, media extraction/re-encoding, asset writes/rollback, private-root provisioning or scheduled cleanup. These are **separate 01D-3/01E steps**, after the individual consumers and their document/media models are reconciled. Do not advertise functional end-to-end template package import/export until those consumer-level tests pass.
