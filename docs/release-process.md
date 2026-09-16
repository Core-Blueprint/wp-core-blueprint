# Core Blueprint Base release process

Base has a stable WordPress plugin identity:

- directory: `core-blueprint/`;
- main file and basename: `core-blueprint/core-blueprint.php`.

The canonical builder is `tools/build-release`. Run it from the source checkout:

```bash
python3 tools/build-release
```

The plugin header and `CB_CORE_VERSION` must agree. The version remains
`1.0.0-rc1` for this launch candidate. Output is written to
`dist/core-blueprint-1.0.0-rc1.zip` and its `.sha256` sidecar.
A GitHub source archive is not an installable customer release archive.

## Current verification boundary

The builder checks identity, the runtime allowlist and symlink exclusion, then
writes the deterministic archive and SHA-256. It does not yet execute every gate
required by the Engineering Handbook Packaging & Release Standard. This is an
explicit tooling hold, not permission to bypass checks or a claim of compliance.

Until tooling closure is complete, record the separately executed source and
runtime evidence for the exact candidate, including PHP syntax, localization,
JavaScript tests/syntax and WordPress integration. After constructing the ZIP,
validate integrity and the existing package scenario:

```bash
unzip -tq dist/core-blueprint-1.0.0-rc1.zip
bash tests/bin/run-release-package-scenario.sh dist/core-blueprint-1.0.0-rc1.zip 1.0.0-rc1
```

The scenario checks canonical root, listed development-path exclusions, required
runtime payload, version consistency and packaged PHP syntax. Fresh installation
and same-version replacement are checked separately by
`tests/bin/run-release-install-scenario.sh` against an isolated test database and
a deliberately selected previous ZIP. See `tests/README.md` for test isolation.

## Release approval

Record source SHA, ZIP checksum and all outstanding field/tooling gates.
Generating a ZIP or passing package validation alone does not authorize release.
See `docs/base-launch-closure-2026-09-16.md` for this candidate's execution evidence.
