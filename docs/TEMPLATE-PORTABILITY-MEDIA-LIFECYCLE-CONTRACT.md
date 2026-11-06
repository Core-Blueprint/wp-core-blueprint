# FRG-T4-01D-3 — Consumer-Owned Media Apply and Cleanup Contract

**Status:** Contract + isolated test-only fixture. **No production media write path implemented.**
**Baseline:** Core Blueprint Base `main@40ebdf161afe008d6a4e6ed497e8680bd98b27a1`.
**Dependency:** FRG-T4-01C (ZIP verification) and FRG-T4-01D-1 (private stage) are merged; FRG-T4-01D-2 (scoped preview/apply binding) is independently accepted in PR #155 but **not merged** at the time of this document.
**Consumer scope:** Reports logos/images and Certificates artwork/image assets; do not force package media on asset-free consumers.

## Single-authority lifecycle

Base continues to use the **existing Data Exchange Engine** for import preview, canonical fingerprint, stale-plan detection and apply sequencing. Base's verified staging supplies read-only resource integrity evidence, not destination media identity or a license to store or display the bytes. The individual consumer owns its document schema, rights/capability decisions, media decoding policy, draft record, asset identity mapping, and cleanup.

`preview_json()` and consumer `plan_import()` must be **read-only**. They must refuse missing resources, unexpected resource keys, unknown MIME, digest mismatches, source attachment IDs, remote source URLs, unresolved destination dependencies, unauthorized destinations and collisions. A successful preview never materializes a WordPress attachment or writes a partial draft.

Before every `apply_json()` mutation, the trusted stage, current actor, current permission, destination and canonical digest projection must be recomputed and compared with the expected Engine fingerprint. The consumer's scoped plan must contain the verified resource projection, not a caller-submitted projection. The consumer must recheck its domain authorization and destination availability inside the own canonical create operation.

## Media materialization belongs to the consumer

The initial resource allowlist is PNG, JPEG and WebP only, with the candidate package's 16-resource, 5-MiB individual and 20-MiB total limits. `getimagesizefromstring()` and Fileinfo are **not sufficient for final media persistence**. A consumer's actual media adapter must validate pixel/dimension limits, fully decode and safely re-encode the selected image type, strip or control metadata, reject unsupported payloads, obey WordPress/upload rules, enforce consumer quotas and check provenance/license/rights where appropriate. It cannot trust external filenames, remote URLs, source `attachment_id`/`asset_id`, foreign public IDs or preview paths. Only the **new destination-owned identity** may be written into the new draft. This document does **not** select a single image library or prescribe a universal Base media repository.

The consumer shall record each *newly created* asset in a bounded creation journal **immediately after successful creation and before writing its draft**. Pre-existing or shared assets are not journal-owned and **must never be deleted** by an import rollback. There is no requirement to add a new Base-wide journaling class; a small per-consumer journal suffices.

## Failure and recovery semantics

| Moment | Required behavior |
| --- | --- |
| Any preflight or permission failure | No media or draft writes; fail closed. |
| Media creation failure | Do not create the draft; remove only assets created by this attempt. |
| Draft creation/collision failure after media writes | Reverse-order best-effort cleanup of only journal-owned assets. |
| All media created and draft safely persisted | Return `complete`; draft remains disabled/unpublished until separately authorized by consumer. |
| Cleanup error | Report `failed` (or `partial` for a true multi-record Engine apply), explicit `cleanup_incomplete` diagnostics and retained owned-asset references for operator recovery; **never report `complete`**. |
| Unexpected termination or host crash | In-request journal cannot guarantee cleanup; future consumer implementation must define durable bounded recovery/reconciliation before production use. |

**No cross-filesystem/database atomicity guarantee.** The Base Engine returns accurate `complete`, `failed` or `partial` only as far as the consumer's callback accurately communicates its own outcome. A consumer must never turn an incomplete cleanup into a success response. A rollback must not delete any asset it cannot prove was created and owned by the failing attempt.

## Executable planning proof

`tests/python/test_resource_media_lifecycle_contract.py` invokes an isolated PHP **fixture**, not a production media service. The fixture tests:
- Read-only preview and one draft with newly-created assets on success.
- Nine rejected variants (permissions, actor, destination, source IDs/URLs, tampered digest, forbidden MIME, duplicate keys, excessive resources) with zero writes.
- Resource-write failure without draft creation.
- Failed draft creation with cleanup of newly created assets only.
- Reverse-order cleanup after multiple resource creations; non-owned/shared asset preserved.
- Cleanup failure recorded as `failed` with explicit pending created asset identity.

Run directly:

```bash
python3 -B -m unittest discover -s tests/python -p 'test_resource_media_lifecycle_contract.py' -v
```

**Evidence boundary:** These tests enforce a **candidate consumer behavior contract**, not actual Reports/Certificates media persistence, native WordPress media decoding, real package bytes, a server crash, two-site portability, or Base Engine end-to-end package transport. They are not sufficient to declare import/export working.

## Next integration gates (do not expand Base prematurely)

1. **FRG-T4-01E:** exercise the real ZIP verifier, private staging and canonical Engine together with a bounded read-only/draft test consumer; audit security/availability and failure paths. Neither PR #155 nor this contract is merged without a separate owner GO.
2. **FRG-04 Foundation Freeze:** freeze only the established Base transport/Designer/Template Library contracts once Base-specific evidence passes. Do not represent consumer media delivery as part of this freeze.
3. **Post-freeze consumer adoption:** Reports and Certificates implement their own media adapter, actual image decode/re-encode, draft persistence, journal/recovery and cross-site acceptance. No legacy/backwards compatibility or data migration is needed in these pre-release consumers.

**Deliberate exclusions:** no generic Base media repository, universal asset database, new ZIP parser, separate import engine, REST upload route, unsolicited consumer source changes, or speculative media format expansion.
