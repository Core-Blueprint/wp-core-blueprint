# Core Blueprint release tooling

## Canonical Base Level 1 check (B1 candidate)

The independent, **read-only source/product** entrypoint is:

~~~bash
./tools/check
~~~

It requires PHP 8.4+, Composer, Node.js, Python 3 and Bash and executes:

1. Strict Composer metadata validation (existing system Composer versions may print PHP deprecation notices while returning 0).
2. Existing PHP syntax and read-only canonical translation check via `tests/bin/php-lint.sh`.
3. The two standalone public Admin Navigation and Tile Foundation regressions.
4. Syntax checks for all shipped JavaScript files in `assets/`.
5. All `tests/js/*.test.mjs` runtime regressions.
6. The isolated Python release-builder/localization failure-path suite, including Level 1 runner orchestration tests.

No Docker commands, WordPress test setup, database access, customer ZIP construction or `dist/` writes are performed by this entrypoint. The Python tests use disposable temporary fixtures outside the source tree. B1 does not replace the full WordPress integration, release or field gates; it does not modify the existing Python release builder.

The future canonical Level 2 integration runner is a **separately gated B2 task**. Until it is implemented and accepted, the existing Base test/bootstrap procedure and the fail-closed production builder below remain the actual Level 2/3 authority. Never report `./tools/check` PASS as WordPress integration or customer-release PASS.

---

`tools/build-release` is the canonical fail-closed Base customer-release entrypoint.
It packages the existing runtime allowlist beneath `core-blueprint/`. The current
plugin version is `1.0.0`.

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
