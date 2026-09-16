# Base launch closure — 2026-09-16

Status: **HOLD — native validation and release closure outstanding**.

## Native follow-up (supersedes the earlier unchecked states below)

Chris executed the native checks on candidate
`bab5e19278f715df07b250220eb59b4211a15e32` with PHP 8.4 and WordPress 7.1:

- PHP syntax and localization: PASS (3382 messages, six locales).
- SVG/CSV security regression subset: PASS (4 tests, 76 assertions).
- Full integration: 359 tests, 3241 assertions, 1 error, 9 failures.
- The PDF error explicitly reports missing PHP GD in the test environment.
- The nine failures assert superseded Designer/Reports contracts: exact HTML
  opening tag, Mail-owned context CSS, direct toolbar-to-composition dependency,
  the old document surface wrapper, direct HtmlRenderer consumption, inline
  paper styling, JS-created context marker, and direct consumer srcdoc writes.

The test-only reconciliation checks the current public Flow facade, canonical
preview host, Base-owned context presentation and transitive style dependency.
The preview safety check now requires exactly one static CSP-hashed bridge,
instead of forbidding the bridge introduced by the merged Flow host contract.
No Designer production behavior is changed. Native rerun with GD remains required.

## Source authority

- Main inspected: `eac35872003402797e71c870c1af96210c27da65`.
- Existing security candidate: `609bcbe447ad6df88cd4b828bd65b4e1f54b6cb9`.
- Working branch: `base-golden-security-closure-svg-csv`.
- Plugin header and `CB_CORE_VERSION` remain `1.0.0-rc1`.
- Canonical installed root remains `core-blueprint/`.

## Existing candidate

The candidate adds SVG sanitization before Media Replace staging, revalidates
the resulting MIME/extension, and neutralizes spreadsheet formula prefixes in
CSV log exports without changing JSON exports. It includes PHP regression tests.
These tests have not been executed in this takeover environment.

## Takeover checks

- JavaScript regression suite initially: 106 passed, 2 failed.
- Both failures expected one postMessage after iframe load. The merged semantic
  selection contract sends one measure message and one selection message.
- Updated assertions verify both message types, generation, initial null
  selection, and no extra messages from a second load event.
- Added execution coverage for selection persistence across rerenders, clearing
  selection without navigation, and rejection after host destruction.
- Full JavaScript regression suite after test correction: **109 passed, 0 failed**.
- JavaScript syntax: **85 asset files passed**.
- No Designer production code changed during this takeover.
- No GitHub Actions started; no merge performed.

## Remaining gates

1. Execute PHP validation in a supported native PHP 8.4+ environment. This
   environment has no PHP/WordPress runtime; package-manager installation failed
   because the environment does not permit its required user/group operations.
2. Reconcile localization from actual source-check output. The two new SVG
   replacement error messages are absent from all bundled catalogs. The existing
   checker also hard-codes a source cardinality of 3376; do not change that number
   blindly or treat old locale evidence as proof for this candidate.
3. Run the PHP/WordPress integration suite, including the CSV/SVG security tests.
   The current SVG test combines source-contract assertions and sanitizer
   execution; it does not prove the complete HTTP-upload replacement path.
4. Prove real PNG/JPEG replacement and safe SVG replacement; prove hostile SVG
   active/remote content is removed, invalid SVG is rejected, and failures retain
   the original attachment. Verify CSV export and unchanged JSON behavior.
5. Reconcile release tooling with the approved handbook contract. The current
   `tools/build-release` checks identity/allowlist/symlinks and writes a
   deterministic ZIP/checksum, but does not run localization, syntax, or runtime
   regression gates or verify ZIP integrity after writing. The existing package
   scenario supplies additional checks outside that entrypoint.
6. Correct `docs/release-process.md`, which references the absent
   `tools/package-release.py`. Keep `tools/build-release` canonical. No alternate
   release builder or bypass should be introduced.
7. Build and verify the exact final candidate, preserving version and plugin root;
   record checksum and fresh-install/same-version-replacement evidence, plus any
   remaining field gates. A source archive is not a customer release ZIP.

Next native diagnostic, from this branch checkout:

```bash
php tools/check-translations.php
```

This document records bounded verification, not a full Golden PASS or release
approval. Other repositories' native execution results are not Base evidence.
