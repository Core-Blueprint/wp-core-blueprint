# Core Blueprint Base release process

Base retains the canonical `core-blueprint/` directory and
`core-blueprint/core-blueprint.php` basename. The launch version remains
`1.0.0-rc1` in both the plugin header and `CB_CORE_VERSION`.

## Canonical build

Prepare the isolated test environment documented in `tests/README.md`, install
locked development dependencies, and refresh its canonical plugin copy from the
candidate. Then run:

```bash
python3 tools/build-release
```

This command now owns required tool/dependency preflight, PHP syntax/localization,
JavaScript syntax/regressions, WordPress integration, source/test-copy parity,
archive integrity and package verification. It refuses to publish a new release
ZIP or checksum when any required gate fails. See `tools/README.md` for the exact
requirements and gate order. No alternate builder or skip-gates path exists.

Output is `dist/core-blueprint-1.0.0-rc1.zip` and its `.sha256` sidecar. A GitHub
source ZIP is not an installable customer release archive. The source/runtime
inputs are read-only during packaging.

## Installation, field proof and approval

Run `tests/bin/run-release-install-scenario.sh` against the exact candidate ZIP,
a deliberately selected previous ZIP, checksum-verified WP-CLI and an isolated
test database. This checks fresh activation and same-version replacement.

Media Replace HTTP-upload field checks, source SHA, artifact hash and final
review remain explicit release evidence. See
`docs/base-launch-closure-2026-09-16.md` for the current candidate's evidence and
remaining holds. A successful build alone does not authorize merge or release.
