# Base launch closure — 2026-09-16

Status: **Native execution and package checks PASS; field and release-tooling HOLD.**

## Proven candidate

- Source: `d68601bf3c3850922ba657c47ab87d118292b856`.
- Branch: `base-golden-security-closure-svg-csv`.
- Main baseline: `eac35872003402797e71c870c1af96210c27da65`.
- Plugin header and `CB_CORE_VERSION`: `1.0.0-rc1` (unchanged).
- Canonical installed root: `core-blueprint/`.
- ZIP: `core-blueprint-1.0.0-rc1.zip`, 1023 files.
- SHA-256: `970562d63df7ffdbb74b0bfe2dbc9f421d929e9a6f0f190dc34e6b947a76fe32`.

Chris executed and supplied the native results below. No GitHub Actions were
used, and no merge or production deployment is implied by these results.

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

## Remaining launch gates

1. Real HTTP-upload Media Replace field proof: normal PNG/JPEG and safe SVG;
   hostile SVG sanitized; invalid SVG rejected with original attachment intact.
   The sanitizer tests and source-contract assertions do not prove this full path.
2. Reconcile `tools/build-release` with the approved fail-closed handbook contract.
   It currently constructs an allowlisted archive and checksum, while localization,
   syntax, regressions and package verification were executed separately in this
   native run. Successful external checks do not make the builder itself compliant.
3. Final review and explicit merge/release GO. This evidence is not a full
   security guarantee or a claim that every launch gate has passed.

The evidence/documentation follow-up does not change packaged runtime inputs;
retain the exact verified ZIP above for field testing.
