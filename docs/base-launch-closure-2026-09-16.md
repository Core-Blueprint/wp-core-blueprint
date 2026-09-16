# Base launch closure — 2026-09-16

Status: **Previous candidate native/runtime/package and Media Replace checks PASS; PDF cache correction awaits native validation.**

## Proven candidate

- Source: `d68601bf3c3850922ba657c47ab87d118292b856`.
- Branch: `base-golden-security-closure-svg-csv`.
- Main baseline: `eac35872003402797e71c870c1af96210c27da65`.
- Plugin header and `CB_CORE_VERSION`: `1.0.0-rc1` (unchanged).
- Canonical installed root: `core-blueprint/`.
- ZIP: `core-blueprint-1.0.0-rc1.zip`, 1023 files.
- SHA-256: `970562d63df7ffdbb74b0bfe2dbc9f421d929e9a6f0f190dc34e6b947a76fe32`.

Chris executed and supplied the native results below. No GitHub Actions were
used, and no merge is implied by these results. Chris subsequently installed the
verified artifact on coreblueprint.io and used sandbox for the SVG field checks.

## Evidence

| Gate | Result |
| --- | --- |
| JavaScript regression suite | PASS: 109 tests |
| JavaScript asset syntax | PASS: 85 files |
| PHP source syntax | PASS on PHP 8.4 |
| PHP translation catalogs | PASS: 3382 source messages, six locales |
| SVG/CSV security subset | PASS: 4 tests, 76 assertions |
| Full WordPress 7.1 integration suite | PASS: 359 tests, 3316 assertions |
| ZIP integrity | PASS: `unzip -tq` |
| Canonical root, version and required runtime payload | PASS |
| Existing development-file exclusion scenario | PASS |
| Packaged PHP syntax | PASS: 741 files |
| WordPress 7.0 fresh ZIP install and activation | PASS |
| WordPress 7.0 replacement, `1.0.0-rc1` to `1.0.0-rc1` | PASS |
| Activation/runtime version and empty debug log | PASS through install/update scenario |

The replacement baseline was built from the main commit above. Its ZIP hash was
`1f26a55a4624fb6492d6dbbd56c5e20e76ccdbae8ec4defd86878c2ac0c16153`.
The native database was isolated in a Podman MariaDB 10.11.19 container. The
release smoke used checksum-verified WP-CLI 2.12.0.

## Changes and resolved failures

- Media Replace sanitizes SVG before staging and rechecks the resulting type.
- CSV log export neutralizes formula prefixes without changing JSON export.
- A runtime regression exposed that the vendored sanitizer allowed direct HTTP(S)
  hrefs even with remote references disabled. A documented local patch now applies
  that policy to direct href/xlink:href references, including root-relative and
  protocol-relative references; local fragments and supported raster data remain.
- Six translation catalogs were reconciled: seven missing keys and one obsolete
  key per locale; net source cardinality changed from 3376 to 3382.
- Preview JavaScript and Designer/Reports PHP tests were reconciled with the
  previously merged canonical host/context contracts. Designer production behavior
  was not reverted or changed to satisfy obsolete tests.
- The initial full native run reported missing PHP GD plus nine stale contract
  assertions. GD was installed, assertions were reconciled, and the full rerun
  passed with zero failures/errors.

## Media Replace field evidence

Chris confirmed normal PNG/JPEG replacement on coreblueprint.io and safe SVG
replacement on sandbox. An intentionally malformed SVG was rejected on sandbox
with "The SVG replacement could not be sanitized safely." The original test
image remained present afterward. These checks are PASS. Hostile script/remote
reference removal has native sanitizer regression evidence, not a separate
hostile HTTP-upload field test; do not conflate those evidence scopes.

## Strict release entrypoint follow-up

The builder now runs required preflight, PHP syntax/localization, JavaScript
syntax/regressions, full WordPress integration and package checks before naming
an accepted ZIP and checksum. It checks source/test-copy byte parity, rejects
skipped/incomplete integration tests, validates exact ZIP manifest/bytes/CRC and
preserves earlier accepted output on gate failure. No bypass flag was added.

- Python syntax: PASS.
- Builder failure-path/artifact unit tests: PASS, 11 tests (native gates mocked
  only in those unit fixtures, not in production code).
- Actual invocation with PHP absent: fails nonzero before any release output.
- Runtime serialization remains byte-identical to the verified ZIP SHA above.
- Full native execution of the updated entrypoint is still required.

## Remaining launch gates

1. Execute the strict builder with the PDF cache correction in a refreshed native
   WordPress environment. Record its new checksum and repeat PDF field smoke;
   the previous artifact checksum does not apply to this runtime correction.
2. Preserve the explicit limitation on hostile HTTP-upload field coverage above;
   do not report that path as manually tested.
3. Final review and explicit merge/release GO. This evidence is not a full
   security guarantee or a claim that every launch gate has passed.

## Native strict-builder failure and PDF cache correction

The first native strict-builder run passed all 359 integration tests (3316
assertions), then correctly stopped at post-test runtime parity. Chris's file
comparison found exactly one addition in the WordPress test copy:
`src/PDF/lib/dompdf/vendor/dompdf/dompdf/lib/fonts/Helvetica.afm.json`.
Dompdf defaults its derived font cache to the bundled font directory; its
`Cpdf::openFont()` writes this JSON during real rendering.

The Base PDF wrapper now supplies a random per-render temporary cache directory
with mode 0700 and removes its files/directory in `finally`. The vendor library
and release parity gate remain unchanged. A real-PDF integration regression
checks repeated Helvetica rendering, unchanged bundled font bytes and cache
cleanup. PHP/native execution of this correction remains pending; the local
environment has no PHP. Version remains `1.0.0-rc1`, but runtime bytes and the
resulting ZIP checksum change. Earlier field evidence applies to the earlier
artifact, not automatically to this correction.
