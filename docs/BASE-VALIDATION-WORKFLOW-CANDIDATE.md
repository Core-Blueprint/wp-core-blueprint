# Base validation workflow reconciliation (candidate)

**Source:** `Core-Blueprint/wp-core-blueprint main@caa8daf1e677ed688d2d253108b6021895e1df33`.
**Branch:** `feature/base-validation-workflow-reconciliation-v1`.
**Status:** source candidate, NOT local WordPress Level 2 PASS, NOT release-ready.

## Boundaries

- Level 1: executable read-only `./tools/check`, source and standalone regressions.
- Level 2: `./tools/check-integration --preflight` and `--dry-run` are diagnostic. `--execute` is separately approved and creates a NEW isolated database/workspace only. No database reset/drop, Docker start/replace, or automatic cleanup is implemented.
- Level 3: current strict Python `python3 tools/build-release` still requires verified `WP_CORE_DIR`, `WP_TESTS_DIR`, `CB_PLUGIN_FILE` and dedicated database environment. It reruns Level 1 and the existing independent release/WordPress gates. Existing release packaging, checksum and Design module graph stamping remain intact.
- Level 4: WordPress sandbox Designer compatibility (Base/Automations/Evaluator) requires separate operator acceptance.
- Other Base ongoing branches remain untouched. No CI, PR, merge or deploy is implied.

## Safety reconciliation and current workstation context

The historical `wordpress_test` and `core_blueprint_base_test` schemas are **PROTECTED** and already contain tables. The shared `cb-base-test-db` container has a persistent volume; do not replace or reset it. The current loopback port is `127.0.0.1:3307`.

The candidate supports `CB_BASE_TEST_SLOT` as a strictly allowlisted lowercase identifier (max 24 characters; letter first; separated underscores allowed). A slot is used consistently to derive a separate **new-only** test schema, workspace and lock. It cannot override an arbitrary database or filesystem path.

**Workspace security reconciliation:** On 2026-10-13 the real Level 2 run stopped before database creation because the suite-shared `/tmp/core-blueprint-tests` directory was operator-owned but mode `775` and already contained Work test data. The runner must **not** chmod/chown it or weaken lock ownership requirements. Instead Base now uses an exclusive `/tmp/core-blueprint-base-<slot>` directory directly beneath the root-owned sticky `/tmp` (mode `1777`); its 0700 workspace and 0600 new-only lock are protected by Linux sticky-directory semantics. Preflight and dry-run enforce the identical parent-security rule used during execution. Any unexpected ownership, symlink, mode, workspace or lock collision blocks. The previous operator GO for the old workspace does not authorize this changed path; obtain a renewed scoped GO before Level 2 execute. Other Base, Work and extension test resources remain protected.

Example **read-only** candidate inspection for the specifically proposed slot, not an approval to create it:

```bash
cd ~/Downloads/wp-core-blueprint
git status --short
git fetch origin --prune
git switch --track origin/feature/base-validation-workflow-reconciliation-v1
git pull --ff-only
git rev-parse HEAD

bash tools/check

export CB_BASE_TEST_SLOT=e2_20261013
bash tools/check-integration --preflight
bash tools/check-integration --dry-run
```

Do not use `--execute` unless the operator first grants **separate explicit GO for this exact schema and workspace** after clean dry-run, container ownership, persistent mounts and exact source checks. A slot collision or leftover lock/workspace is a hard blocker requiring manual review. The candidate does not perform a reset to make repeated runs green.

If, later, the operator explicitly authorizes fresh resources and Level 2 reports true PASS, the build environment for the **same slot and exact HEAD** is:

```bash
export CB_BASE_TEST_SLOT=e2_20261013
export WP_CORE_DIR=/tmp/core-blueprint-base-e2_20261013/wp
export WP_TESTS_DIR=/tmp/core-blueprint-base-e2_20261013/wp-tests
export CB_PLUGIN_FILE=/tmp/core-blueprint-base-e2_20261013/wp/wp-content/plugins/core-blueprint/core-blueprint.php
export WP_DB_NAME=core_blueprint_base_e2_20261013_test
export WP_DB_USER=root
export WP_DB_PASSWORD=root
export WP_DB_HOST=127.0.0.1:3307

python3 tools/build-release
unzip -tqq dist/core-blueprint-1.0.0.zip
sha256sum dist/core-blueprint-1.0.0.zip
cat dist/core-blueprint-1.0.0.zip.sha256
```

Do not execute this final block before the exact Level 2 prerequisites are accepted. `CB_BASE_TEST_SLOT` is an opt-in identifier; passing it alone does not authorize database creation. The CLI refuses `--execute` without `CB_BASE_INTEGRATION_APPROVAL=I_APPROVE_FRESH_BASE_TEST_DATABASE`, and the operator approval boundary is separate from that technical check.

## Test matrix

1. `bash tools/check` including Python mocked runner safety failures, PHP syntax/public foundation, JS source and JS regressions.
2. `bash tools/check-integration --preflight` confirms the actual Docker metadata is **localhost-only** and reports persistent mounts without claiming they are disposable.
3. `bash tools/check-integration --dry-run` checks the new-only slot against real schema/workspace collisions without mutating them.
4. Following separately authorized Level 2 execute, verify full WordPress/PHPUnit, lifecycle, pinned Starter, performance, WP-CLI and Media Replace tests. No destructive uninstall inside normal runner.
5. Execute fail-closed Level 3 builder on the *same* exact-head WP runtime copy; independently verify canonical ZIP and sidecar.
6. Validate Base Designer Navigation public API in installed sandbox; then consume it in Evaluator E2 and Automations separately.

**Not done:** direct WordPress integration, release build, field acceptance, CI matrix, merge. No customer artifact has been produced by this patch.

## Owned historical workspace diagnosis (2026-10-13)

The first approved fresh execution had already created
`/tmp/core-blueprint-base-e2_20261013`, its matching dedicated DB and lock.
The workspace owner marker identifies `a81ebd3606b6ccd0fa5d2c01537c124bfcf933bd`
and WordPress 7.0. Local evidence includes PHPUnit fixtures, a complete
performance report, pinned WP-CLI, HTTP logs through the last Media Replace
gate, and 34 dedicated schema tables. These artifacts show progress but **do
not prove** that every gate exited 0 or that final parity passed.

The historical runner had no persistent per-gate terminal summary. **Never
infer Level 2 PASS from file presence.** Never run `--execute` on this slot
again, clear the lock, or reset its schema/workspace.

`CB_BASE_TEST_SLOT=e2_20261013 bash tools/check-integration --inspect`
is explicitly read-only. It validates the Base-owned workspace, owner marker,
dedicated schema presence, and absence/presence of durable end evidence.
It works even when the current source HEAD has advanced, without silently
relabeling the older result as current-HEAD PASS.

Future fresh `--execute` runs create append-only 0600 JSON gate receipts
under the owned 0700 `evidence/` directory. Only a fully completed run with
verified parity writes `99-final-pass.json`; inspections validate the
recorded source/database/version and every required gate receipt. There is
**no retroactive repair** for a run performed before evidence recording existed.

A controlled retest of the already-owned historical schema requires a separately
reviewed rerun/recovery design and operator approval; it is not authorized
by the new-only executor or the read-only inspect command. Do not rerun
destructive or non-idempotent scenarios against an existing schema. The
release builder can later use a valid fixture, but it is **not** proof of the
complete Level 2 extended matrix.
