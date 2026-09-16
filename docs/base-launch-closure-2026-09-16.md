# Base launch closure — 2026-09-16

Status: **Final native build PASS; Gutenberg field stability confirmed; merge authorized by Chris.**

## Final accepted artifact

- Tested source: `7e20370eafbe7abe5c404f06db5db61f3e4fca97`.
- Version: `1.0.0-rc1`; canonical root: `core-blueprint/`.
- ZIP: `core-blueprint-1.0.0-rc1.zip` (1023 files).
- SHA-256: `51fd4f34d654fafc29af65e639a1dc3c524418d55e2178437db0d3c001d0b78b`.
- Strict native build: PASS, 360 integration tests / 3323 assertions, runtime
  parity, all package gates and 741 packaged PHP syntax checks.
- Chris subsequently reported “Stabiel!” after the Gutenberg field-test request
  and explicitly authorized merging. This is an overall field confirmation;
  no separate per-control or per-browser results were supplied.
- This closing evidence update changes documentation only, outside the release
  allowlist. The tested artifact remains the delivery artifact.

## Earlier proven candidate

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
- The first native run stopped on the PDF cache mutation described below;
  the corrected candidate subsequently passed the complete entrypoint.

## Release scope and evidence limits

Chris authorized merging the Base release branch after the final build and
field confirmation. Earlier fresh-install/same-version-update script evidence
belongs to the earlier candidate, not a fresh scripted rerun on the final ZIP.
Hostile SVG script/remote-reference removal has automated regression evidence;
that specific hostile HTTP-upload path was not separately field-tested.
These results are scoped release evidence, not a comprehensive security guarantee.

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
cleanup. Chris's native run of candidate
`66a64ec879481130234bdf35a471d4267b0fb23f` passed 360 tests / 3323 assertions,
post-test runtime parity and all package gates (1023 files, 741 PHP files).
The resulting `1.0.0-rc1` ZIP SHA-256 is
`9b99a802b1479d79e93d6a64974c78b58e5899fde72cb28b0a85b0c552364cb1`.
Chris downloaded a real Maintenance Report after installation. Review of both
A4 pages confirmed intact text/tables/pagination and embedded regular/bold
DejaVu Sans fonts. This sample contains no images; image rendering is outside
that field evidence. PDF rendering field check: PASS.

## Gutenberg dark-mode polish

Screenshots show light inserter/search/toolbar/breadcrumb surfaces, dark block
icons/descriptions and a light Advanced-panel hover. The adapter was compared
against WordPress 7.1 `components`, `block-editor` and `editor` styles plus the
compiled Components JavaScript from `core.svn.wordpress.org/tags/7.1/`.
The title wrapper's hardcoded hover and explicit block-card/icon colours were
confirmed in that source. Only `assets/css/admin-theme/gutenberg.css` changes
runtime behaviour: component surfaces/colours and WPDS neutral tokens are mapped
to existing Base tokens. Canvas styles, layout and plugin version are unchanged.

The final native build passed and Chris confirmed field stability as recorded
above. Individual dark/light, hover, selection and keyboard-focus results were
not separately reported. CSS declaration and selector parsing passed (39 rules),
and `git diff --check` passed. A local browser check could not execute because Chromium
was absent and its download timed out. Source inspection alone was not treated as a live-editor PASS. Use the final
artifact checksum at the top of this document for delivery.
