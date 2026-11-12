# Base Level 2 — isolated rootless Podman database

**Status:** SOURCE candidate, pending operator Level 1 and isolated Level 2 evidence.  
**Scope:** Base integration tooling only, not the Base runtime, customer plugin or Bookings.

## Why

The historical Base runner referenced Docker container `cb-base-test-db` on port 3307.
Its mounted data volume was named `cb-bookings-qc102-v26-docker-20261112`.
Only one container was listed as using it, but storage ownership was not proven.
The new runner must never adopt that container, volume, database or port.

## Canonical fixed identity

| Resource | Required |
| --- | --- |
| Container runtime | rootless Podman only |
| Image | `docker.io/library/mariadb:10.11.19` |
| New container | `cb-base-l2-db` |
| New persistent volume | `cb-base-l2-db-data` |
| Ownership label on BOTH resources | `io.coreblueprint.level2.owner=base-isolated` |
| Published endpoint | `127.0.0.1:3308` (never `0.0.0.0`) |
| Internal database port | `3306/tcp` |
| Canonical DB | `core_blueprint_base_test` (or safe named slot) |
| WordPress fixture | 7.0 default, 7.1 allowlisted |
| Filesystem | fresh /tmp/core-blueprint-base (or safe slot), 0700 |
| SQL policy | CREATE a previously absent schema only; no DROP/RESET |
| Execution evidence | exact Git SHA, owned workspace schema 2 and full gate receipts |

The initial MariaDB local-test root password is `root`, retained for compatibility
with the existing integration fixture. The server must be rootless, dedicated,
labeled and bound to loopback. **Not for production or shared networks.**

## Separate approval gates

These are intentionally separate operations:

1. `./tools/check-integration --preflight` and `--dry-run` are read-only.
   They refuse a missing/unowned/ambiguous Podman database. Neither creates it.
2. `CB_BASE_DB_PROVISION_APPROVAL=I_APPROVE_NEW_BASE_PODMAN_RESOURCES ./tools/check-integration --provision-db`
   creates **only** a new, labeled Base volume and container, then verifies
   mount, image, sole volume consumer, rootless runtime, binding, and MariaDB
   readiness. It does **not** create WordPress files or a test schema.
   This requires separate explicit operator approval and must not run until
   Level 1 SOURCE acceptance.
3. Read-only `--preflight` and `--dry-run` must then PASS.
4. `CB_BASE_INTEGRATION_APPROVAL=I_APPROVE_FRESH_BASE_TEST_DATABASE ./tools/check-integration --execute`
   requires a **different** explicit operator approval. It creates the NEW
   dedicated test schema and workspace and executes the full existing WordPress
   integration matrix with immutable receipts.
5. `--inspect` is read-only, including after failed tests, and is not a
   substitute for the matrix.

## Security controls

- Rootful Podman and Docker are not accepted as Base Level 2 authority.
- Neither an existing container nor existing volume may be adopted in provisioning,
  regardless of its name or labels; an uncertain `podman ... exists` result blocks.
- Every later inspection requires exactly one matching labeled Base container
  and volume, one volume mount to /var/lib/mysql, matching volume mountpoint,
  one volume consumer (the Base container), pinned MariaDB image, exact local
  3308 binding and a running container.
- Provisioning checks port availability and canonical workspace/lock/source state
  before the first create-only operation.
- Failed provisioning never auto-replaces, resets, starts, removes or unlinks
  an existing resource. Partially created resources require manual review.
- The existing non-Base Docker container and its Bookings-named volume are
  intentionally never inspected for mutation and never changed.
- The entire integration matrix, release parity and output gates remain in place.

## Operator validation

On the exact feature SHA:

```bash
bash tools/check
python3 tools/build-dev
python3 -B -m unittest discover -s tests/python -p 'test_integration*.py' -v
./tools/check-integration --inspect
```

Before provisioning, `--preflight` and `--dry-run` should fail closed if the
dedicated Podman DB is missing; this is expected and must not be reported as a
Level 2 PASS. Do not run `--provision-db` or `--execute` without the separate
approval for the respective resource mutations.

The output ZIP is internal-only. No merge, CI/CD, deployment or release is implied.
