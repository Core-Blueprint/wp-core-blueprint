# FRG-T4-01E — Real Base Package Pipeline Integration Acceptance

**FRG-04 current status (6 November 2026):** Integration test and document **MERGED** at `main@18023119473f27a95eac3215b25fcc30d115641f` via PR #157. Chris reported all 8 real ZIP-dependent/fail-closed cases with zero skips, Base Level 1 and dev build ZIP/checksum PASS at feature HEAD `4a01194ee097cc54310b0a859f1d5d896bb3cac6`; raw logs/checksum are not independently recorded here. The historical assistant-only 7 skips below predate owner acceptance and were due to its missing ZipArchive. No new WordPress DB/consumer FIELD, post-merge Level 2/3 or public release claim follows.
**Baseline:** `main@ee77ad59230570098bc87cbe3070783018ee4719`.
**Scope:** The merged `ResourcePackageVerifier`, `ResourcePackageStage`, `ResourcePackageImport` and canonical `Engine` are loaded unchanged in a single PHP subprocess. A test-only Registry/EntityInterface consumer substitutes for real Reports/Certificates storage; no other PHP service is replaced for the ZIP-enabled test cases.

## Purpose

FRG-T4-01C, D-1 and D-2 each covered their own boundaries. FRG-T4-01E exercises their **composition** with a genuinely constructed ZIP and image bytes: offline archive admission, private `0700` stage / `0600` package, actor/destination-bound ticket, read-only preview, the same Engine fingerprint, reinspection at apply and one draft-only fixture creation. Consumer media writes and the WordPress runtime are **not** part of this test.

`tests/python/test_resource_package_e2e.py` creates an actual ZIP with:
- Canonical `manifest.json` / `data-exchange.json` carrying a single `template.report` record;
- One PNG resource named by its SHA-256 with a matching resource declaration and local key;
- Deflate compression and ordinary ZIP file metadata.

The PHP test driver uses **real production classes** for `Foundation`, `EntityInterface`, `Engine`, `ResourcePackageVerifier`, `ResourcePackageStage`, and `ResourcePackageImport`. WordPress functions and interoperable registry are narrowly stubbed in the PHP subprocess; the provider creates only an in-memory draft and records callback invocations. The test fixture is not registered as a real extension.

## Acceptance cases

| Case | Expected |
| --- | --- |
| Valid real ZIP → stage → preview → apply → discard | Preview read-only, opaque package binding not exposed, precisely one draft fixture write, staged file removed |
| Invalid manifest document digest, mismatched record resource digest, unexpected member | Reject at staging; no fixture writes, no retained stage file |
| Staged file tampered or deliberately discarded between preview and apply | Reinspection rejects before provider apply |
| Actor changed, staging capability revoked, consumer-domain authorization revoked, destination changed | Refuse before provider apply |
| Expired *correctly signed* staging ticket | Refuse at apply despite valid authentication signature |
| Consumer canonical plan changes after preview | Existing Engine fingerprint rejects stale plan |
| Consumer omits mandatory binding at apply re-plan | Reject; no provider apply |
| ZIP extension absent under PHP `-n` | Fail closed; no stage file retained |

The Python suite contains **8 test methods**. Seven use PHP ZipArchive and therefore skip only when the optional extension is not installed; the eighth runs PHP `-n` specifically to prove fail-closed behavior. **FRG-T4-01E cannot be accepted when any of the seven ZIP-dependent tests are skipped.** The owner workstation has previously reported ZipArchive installed, and must confirm a final **`Ran 8 tests ... OK` with zero skips**.

## Exact commands

```bash
php -r 'exit(class_exists("ZipArchive", false) ? 0 : 1);'
python3 -B -m unittest discover -s tests/python -p 'test_resource_package_e2e.py' -v
bash tools/check
python3 tools/build-dev
ZIP="dist/dev/core-blueprint-1.0.0-dev-$(git rev-parse --short=12 HEAD).zip"
unzip -tqq "$ZIP"
(cd dist/dev && sha256sum -c "$(basename "$ZIP").sha256")
sha256sum "$ZIP"
```

**Evidence at SOURCE creation:** Python syntax and embedded PHP-driver syntax PASS; without ZipArchive in the assistant container, local suite ran 8 with 1 PASS (no-extension fail-closed) and 7 skipped. The GitHub Python blob was checked against the exact local source. **No ZIP-enabled E2E, WordPress DB integration, full Level 1 or dev build was run by the assistant.** No test outcome is inferred from the presence of the code.

## Remaining non-goals and release gates

This proves a **Base composition contract**, not full offline template portability, and does not authorize production import. A fake entity is not evidence for real Reports/Certificates consumers or image decoding/re-encoding. There is no public upload endpoint, site-wide private-root provisioning, durable crash-safe staging journal, asset media persistence, cleanup after host failure, automatic activation or cross-site WordPress acceptance. Confirm those separately in the consumer implementation phases, following the accepted FRG-T4-01D-3 contract.

After ZIP-enabled operator PASS and code review, decide whether to merge this integration fixture (separate owner MERGE GO). Only then review FRG-04 Foundation Freeze as a **Base contract** freeze, not a promise that resource-bearing consumer import is feature-complete. No legacy/backwards-compatibility layer, data migration, new media repository or second Data Exchange engine is introduced.
