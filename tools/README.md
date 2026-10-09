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

## B2 Safety Gate (read-only preview, not integration)

This temporary B2 candidate implements only the **preflight safety boundary**.
Inspect the existing MariaDB helper, canonical isolated path, and exact Base
Git source identity without creating files, resetting databases, starting or
stopping containers, or provisioning WordPress:

~~~bash
./tools/check-integration --preflight
~~~

The preflight checks:

- The exact product-owned root `/tmp/core-blueprint-tests/core-blueprint`,
  forbidding path traversal, symlink components, wrong paths, non-directories
  and non-empty roots of unproven ownership.
- The existing `cb-base-test-db` Docker container (image
  `mariadb:10.11.19`, running state, container port 3306 published
  through host port 3307); it does not change Docker state.
- Exact Base source Git HEAD via read-only `git rev-parse`.
- Potential externally exposed MariaDB port bindings and persistent
  container mounts as warnings, **not** evidence of disposable database ownership.

Only `docker inspect` and `git rev-parse` are launched by this preflight.
No database is selected, queried, created, dropped, or reset.
A preflight PASS never authorizes destructive actions and does not establish
actual WordPress integration.

Running `./tools/check-integration` **without** `--preflight` deliberately
returns exit code **2 (BLOCKED)**. Unknown options return **64**. There is
no opt-in or environment override that enables integration in B2 Safety Gate.
Existing Base WordPress test tooling and Python customer-release builder are
unchanged. A future independently reviewed and approved B2 integration
implementation must introduce owner-verified disposable database semantics and
re-check paths immediately before any mutation.

The 18 safety fixture tests are included automatically in `./tools/check`.
They do not access the real Docker daemon or WordPress/database state.

---

## B2b plan-only dry-run candidate

This review branch adds **no integration executor**. Run:

~~~bash
./tools/check-integration --preflight
./tools/check-integration --dry-run
~~~

`--dry-run` is read-only. It verifies the canonical (empty/absent) isolated
root, exact clean Base Git HEAD, locked PHPUnit dependencies, PHP 8.4+, the
existing Docker container and WordPress target 7.0 or 7.1. It also makes one
fixed SQL **SELECT** against `INFORMATION_SCHEMA.SCHEMATA` through the
existing helper to check whether the dedicated `core_blueprint_base_test`
database already exists. No arbitrary SQL/name/host override is accepted and
nothing is created, reset, downloaded or removed. This new dedicated candidate
is **intentionally not** the legacy `wordpress_test` database; reconcile
that operator convention with the Base runbook before enabling execution.

- If `core_blueprint_base_test` exists, the dry-run exits nonzero. A matching
  name is NOT proof that the database is disposable.
- Existing filesystem content or symlink components are rejected.
- Network exposure and persistent container mounts remain warnings visible to
  the operator, not claims that database ownership has been established. They
  remain explicit blockers for future execution authorization.
- The dry-run prints the planned full integration suite and request-boundary
  scenarios with the exact Base source commit. It does not run them.
- `./tools/check-integration` and `--execute` both return exit 2 (BLOCKED).
  Unknown modes return 64. There is **no** environment override that activates
  WordPress provisioning or database mutations in this branch.
- The independent uninstall scenario, CI, customer ZIP and field acceptance
  remain outside B2b. Base's existing Python builder is unchanged.

Review the new tests under `tests/python/test_integration_plan.py` through
`./tools/check` before approving any future integration-execution patch.
Real execution will require a separate GO, exclusive ownership and concurrent
run protection, mutation-time revalidation, database provenance safeguards,
and network exposure assessment.

---

## B2c prepared integration engine, EXECUTION NOT AUTHORIZED

The B2c feature branch introduces `tools/integration/execution.py` and isolated
safety regressions. The engine contains the future WordPress integration stages,
but **no CLI execution entrypoint has been enabled**. The publicly available
`./tools/check-integration --execute` continues to return exit code 2.

Important differences from the legacy Base installer:

- Never run `tests/bin/install-wp-tests.sh` from this engine: it contains
  directory deletion. Prepare only **new** `/tmp/core-blueprint-tests/core-blueprint/`
  paths and use verified archive extraction that rejects links and traversal.
- Acquire an exclusive, owner-only lock. Existing locks, workspaces and test
  databases are hard failures. The lock is not automatically removed after
  either success or failure. Manual reconciliation is mandatory before retry.
- Record the exact Git HEAD, product name, WordPress version and new-only
  database in an owner marker. Stage exactly the committed Base source and
  check runtime parity against the Python release-builder's manifest before
  and after the suite.
- The only planned SQL mutation is CREATE DATABASE
  `core_blueprint_base_test` without IF NOT EXISTS. Reusing, dropping and
  cleaning an existing database are forbidden. The established
  `wordpress_test` database and shared MariaDB volume remain untouched.
- Reuse existing `cb-base-test-db` only. **Execution refuses the currently
  detected non-loopback 3307 binding**. Fixing Docker's port publication
  requires a separate, reviewed operator action. Persistent volumes alone
  are not proof of database ownership, and no volume reset is performed.
- Provision WordPress 7.0 or 7.1 and matching wp-phpunit into the new
  Base-owned directory. The staged source and WordPress test copy must match.
  The full PHPUnit, lifecycle, Starter, modules, performance, WP-CLI,
  provenance and Media Replace conformance matrix is present. Destructive
  uninstall is explicitly outside this runner.
- Override the default `TMPDIR`, `RUNNER_TEMP`, WP-CLI download path and
  performance result directory into the dedicated workspace. Audit any
  remaining historical hard-coded paths before authorizing live execution.
- Keep the workspace, database and lock for operator review on failure.
  There is **no automatic rollback**, reset, deletion, CI or customer build.

B2c operator acceptance remains strictly read-only:

~~~bash
./tools/check
./tools/check-integration --preflight
./tools/check-integration --dry-run
./tools/check-integration --execute  # expected BLOCKED, exit 2
~~~

The newly added `tests/python/test_integration_execution.py` suite exercises
workspace ownership, lock collision, new-only SQL, pinned archive extraction,
runtime suite selection and blocked CLI behaviour using disposable Python
fixtures and mocks. It does not call Docker or download WordPress.

**This is development evidence, not a WordPress integration PASS.** Before
activating B2c in a separate reviewed patch, resolve the non-loopback Docker
publication, audit older downstream request-boundary scripts for hardcoded
filesystem paths/HTTP ports, and locally accept all fail-closed regressions.
Retain B3 customer builder harmonization as a separate future gate.

---

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
