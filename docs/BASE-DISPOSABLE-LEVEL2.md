# Base Level 2 — disposable rootless Podman qualification

**Scope:** local development-only qualification infrastructure for
`Core-Blueprint/wp-core-blueprint`. This is **not** a WordPress runtime
feature, public release builder, staging/production database migration or a
customer-site command.

## Why this exists

The earlier `tools/check-integration --execute` intentionally keeps a
**persistent** Base-only MariaDB container `cb-base-l2-db` and persistent
volume `cb-base-l2-db-data`, hosted on loopback port 3308. A fresh test slot
creates a separate SQL schema, workspace and lock, **not** a fresh Podman
container. Its read-only preflight fails when that container is stopped. Do
not manually start/replace/drop the old container to qualify a new change.

The new **`--disposable`** path is additive and invokes the existing,
complete Level 2 WordPress/PHPUnit/scenario matrix, with fresh rootless
container identity, random loopback port and a fresh dedicated volume on
**each** run. The old `--preflight`/`--dry-run`/`--execute` paths retain
their original strict safety contract.

## Operator workflow

After a clean exact-HEAD checkout and normal Level 1 qualification, run:

```bash
cd ~/Downloads/wp-core-blueprint
./tools/check-integration --disposable-preflight

CB_BASE_DISPOSABLE_APPROVAL=I_APPROVE_FRESH_DISPOSABLE_BASE_LEVEL2 \
  ./tools/check-integration --disposable
```

The first command is optional read-only source/Podman availability screening,
**not** a requirement to manually locate, start or inspect containers.
The second single command explicitly authorizes a new **Base-owned** temporary
container/volume, one fresh test schema, the full WordPress matrix, and
automatic cleanup of those newly created resources once their identity has
been verified. No other database/resource is authorized for cleanup.

Do **not** run this against production/staging or use `tools/build-release`
on a disposed database. Development ZIP construction remains independent:
`python3 tools/build-dev`, followed by ZIP and SHA256 verification.

## Ownership and safety

- Rootless Podman only; pinned MariaDB `10.11.19`. Podman publishes
  `127.0.0.1::3306`, choosing a free local host port. No static
  `3308` dependency. No rootful/network-host mode.
- Every invocation generates a new `d` + 12 lowercase hex run identity,
  such as `dabcdef012345`. The run owns **only** its matching
  `cb-base-l2-<run>` container, `cb-base-l2-<run>-data` volume and
  `core_blueprint_base_<run>_test` schema.
- Both Podman resources are created new-only with the exact immutable
  `io.coreblueprint.level2.owner=base-disposable-<run>` label. Pre-existing
  names and any ambiguous inventory fail closed. The historical
  `cb-base-l2-db`, its volume, schemas and other projects are not modified.
- A fresh random MariaDB root credential is passed through the process
  environment, never a command-line `podman run --env NAME=value` argument.
  Inherited `WP_*`, `DB_*`, `MYSQL_*`, `CB_TEST_*`, `CB_BASE_*`
  and other known external database/site settings are excluded. The existing
  test engine receives **only** the generated disposable connection.
- Source is verified clean at the start; existing Level 2 execution repeats
  source/owner/lock checks and compares the packaged runtime with the exact
  Git commit. A final independent inspect must verify the **same SHA** and
  full per-gate receipts before reporting PASS.
- Cleanup is automatic on both PASS and ordinary failure **only** if exact
  container ID, container/volume labels, exclusive mount, network binding
  and volume consumers are proven. It removes the container by **ID**, then
  verifies an unused labelled volume and removes that specific volume.
  Missing/changed provenance blocks deletion and produces a FAIL.
- Each invocation uses an exclusive private workspace under
  `/tmp/core-blueprint-base-<run>` with the existing new-only lock and
  immutable gate receipts. The workspace and full test logs are intentionally
  **retained for diagnosis**; neither historical workspaces nor arbitrary
  `/tmp` directories are deleted.
- Small per-gate JSON receipts are also copied into a fresh private
  `dist/qualification/base-disposable/<run>/gates/` directory. New-only
  `00-start.json` and `99-outcome.json` record the run, exact SHA,
  qualification result and cleanup result. Failed cleanup cannot become
  PASS. No secret is written to these receipts.

## Failure modes

| Condition | Runner behavior |
|---|---|
| Dirty/unsupported source, missing rootless Podman | Stop before resource creation |
| Pre-existing name/volume collision | Fail before adoption or modification |
| Unexpected published IP, wrong label/mount, shared volume | Fail closed |
| Database readiness, WordPress/PHPUnit or conformance gate fails | Record failure, attempt verified own-resource cleanup |
| External interruption | Terminate test process group, attempt verified cleanup |
| Unknown ownership, partial resource creation or unsupported inspect output | **Never** guess/delete; retain evidence and report resources for reviewed recovery |
| Full matrix PASS but final receipt/parity or cleanup fails | **FAIL**, never promote to Level 2 PASS |

`SIGKILL` and host power loss cannot be intercepted. An orphan after such
a failure is **not** silently adopted or cleared by the next run; the next
invocation uses a fresh identity. A separately reviewed recovery tool and
explicit approval are required to destroy leftovers.

## Qualification and handover

```bash
python3 -B -m unittest tests.python.test_integration_disposable -v
bash tools/check
python3 tools/build-dev
```

Follow normal Base acceptance with a separate disposable Level 2 execution
and browser FIELD testing. This infrastructure change **does not** authorize
merges, public release builds, CI/CD, deployment, customer-site changes or
Foundation Freeze. Retained `/tmp` evidence must never be treated as a
reusable database fixture after its disposable Podman resource has been
removed.
