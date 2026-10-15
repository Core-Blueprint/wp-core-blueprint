# Core Blueprint release tooling

`tools/build-release` is the canonical fail-closed Base customer-release entrypoint.
It packages the existing runtime allowlist beneath `core-blueprint/`. The current
plugin version is `1.0.0`.


## Engineering DX: proven local Level 2 handoff (candidate)

For an exact-head Base checkout use the repository-owned gates, not a manually
constructed PHPUnit command or a guessed `wordpress_test` database:

```sh
./tools/check
./tools/check-integration --preflight
./tools/check-integration --dry-run
```

**Provisioning is never implicit.** On a genuinely fresh, inspected and
separately approved Base test slot, the operator can authorize:

```sh
CB_BASE_INTEGRATION_APPROVAL=I_APPROVE_FRESH_BASE_TEST_DATABASE \
  ./tools/check-integration --execute
```

This creates only a new dedicated Base test schema and workspace, runs the full
Level 2 matrix and records immutable exact-head receipts. Do not run
`--execute` on an occupied slot; use `--inspect` to review an existing one.
Existing test schemas/workspaces/locks remain protected. The historical
`wordpress_test` database is **not** a canonical automatic selection.

After a full Level 2 PASS, the **canonical** Python release builder can resolve
the matching owned workspace itself:

```sh
python3 tools/build-release --output-dir dist/dx-candidate
```

It verifies exact source HEAD, WordPress version, owner record and lock,
localhost-only database binding, dedicated schema presence, immutable per-gate
and final PASS receipts, fixture ownership and runtime manifest parity.
It then runs the usual complete release gates, including PHPUnit, before
writing the customer ZIP and SHA-256 sidecar. It does **not** accept an old
receipt for a newer commit, create a database, install WordPress or reset data.

If the shell contains stale `WP_*` / `CB_PLUGIN_FILE` exports, explicitly
select the proven environment instead of mixing configurations:

```sh
CB_BASE_USE_PROVEN_LEVEL2=1 python3 tools/build-release --output-dir dist/dx-candidate
```

A fully specified manual environment still follows the historical explicit
path; a partial one fails closed. Never override the proof failure or force
a source mismatch. The builder does not run the entire extended Level 2 matrix
again; that matrix must already have a verified PASS receipt, and the builder
reruns PHPUnit as part of Level 3.

**Repeatability boundary:** proven workspace reuse here is for Level 3
validation of the **same exact source SHA**. Safe cross-HEAD staging/replay
is a separate migration requiring an explicit ownership/table-prefix contract;
it is *not* implemented by this candidate. For a different SHA use a reviewed
new-only slot, never reset/relabel an old schema merely to proceed.

Status remains PRE-FIELD / MERGE PENDING until real workstation gates,
customer artifact verification, and runtime acceptance are recorded.

## Required environment

- Python 3.10+, PHP 8.4+, Node.js with `node --test`, Bash and unzip;
- PHP DOM, XML, XMLWriter, mbstring, mysqli, GD and sodium extensions;
- locked development dependencies installed with `composer install`;
- an isolated WordPress test runtime/database prepared using `tests/README.md`;
- explicit `WP_CORE_DIR`, `WP_TESTS_DIR`, `CB_PLUGIN_FILE`, `WP_DB_NAME`,
  `WP_DB_USER`, `WP_DB_PASSWORD` and `WP_DB_HOST` environment variables.

Never point the integration suite at a real site database. The builder neither
installs dependencies nor downloads WordPress nor chooses a database for you.
`CB_PLUGIN_FILE` must identify a canonical `core-blueprint/core-blueprint.php`
test copy whose runtime manifest and bytes match the source being packaged.
Refresh that copy with the existing test installer when source changes.

## Build

```bash
python3 tools/build-release
```

Optional paths (all gates still run):

```bash
python3 tools/build-release --source /path/to/verified/source --output-dir /tmp/core-blueprint-release
```

A different source must contain the same required gate entrypoints/dependencies
and have a matching configured WordPress test copy. `--source` is not a bypass for
historical, incomplete or unvalidated source. Output must stay outside packaged
runtime directories.

## Gate order

1. Identity, runtime allowlist, regular-file/symlink checks.
2. Required tools, dependency files, explicit WordPress environment and test-copy parity.
3. PHP baseline/extensions and release-builder failure-path regressions.
4. Existing PHP syntax and read-only PHP-catalog localization check.
5. Shipped JavaScript syntax and the JavaScript regression suite.
6. Full WordPress integration suite; failures, skipped or incomplete tests block.
7. Source/test-copy runtime parity after execution.
8. Deterministic temporary ZIP construction, exact manifest/byte and CRC checks.
9. Existing packaged root/version/payload/development-path/PHP-syntax scenario.
10. Final integrity/source checks, then publication of the ZIP and SHA-256 sidecar.

The builder does not update catalogs or repair source. There is no skip-gates or
customer-build fallback mode. A missing required tool is a failure.

## Outputs and failure behavior

- `dist/core-blueprint-1.0.0.zip`
- `dist/core-blueprint-1.0.0.zip.sha256`

Paths, timestamps, permissions and compression settings retain the previous
builder's deterministic format. Only a successfully checked temporary archive is
moved to the final release filename. A failed validation removes temporary output
and preserves any earlier accepted release files; those older files must not be
mistaken for a new successful build. Always use exit status and checksum evidence.

Full HTTP-upload field checks and release approval remain separate. Installation
and same-version replacement smoke evidence is recorded for the exact artifact;
build success does not itself authorize a merge or customer delivery.

## Maintenance

Update the runtime allowlist and package scenario together when a runtime-owned
top-level path changes. Bundled runtime dependencies under `src/` remain included;
top-level developer dependencies, tests, tools and repository metadata do not ship.

Run builder failure-path tests with:

```bash
python3 -m unittest discover -s tests/python -v
```

Those tests use temporary fixtures and controlled gate seams; they do not replace
native PHP/WordPress execution of the builder itself.
