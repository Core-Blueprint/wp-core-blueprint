"""Explicit new-only Base Podman database provisioning. Never cleans up."""
from __future__ import annotations

import os
from pathlib import Path
import socket
import subprocess
import sys
import time

from tools.integration import database, execution, plan, preflight


APPROVAL = "I_APPROVE_NEW_BASE_PODMAN_RESOURCES"


def port_available() -> None:
    """Short-lived local bind probe, with a separate Podman start-time check."""
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
            probe.bind(("127.0.0.1", int(database.PORT)))
    except OSError as error:
        raise preflight.PreflightError(
            f"Base-only 127.0.0.1:{database.PORT} is already occupied."
        ) from error


def run_new(args: list[str], label: str, timeout: int = 120) -> None:
    try:
        result = subprocess.run(
            args, capture_output=True, text=True, check=False, timeout=timeout,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise preflight.PreflightError(
            f"Creating new Base {label} failed or timed out; inspect manually."
        ) from error
    if result.returncode != 0:
        raise preflight.PreflightError(
            f"Creating new Base {label} failed; existing resources were not removed. "
            "Inspect the new-only Podman resources manually."
        )


def provision() -> None:
    """Provision only a fresh dedicated rootless volume and container."""
    preflight.verify_parent_security(execution.TEST_ROOT)
    if preflight.validate_test_root(str(execution.TEST_ROOT)) != "absent":
        raise preflight.PreflightError("Base Level 2 workspace exists; provisioning blocked.")
    if execution.LOCK_FILE.exists() or execution.LOCK_FILE.is_symlink():
        raise preflight.PreflightError("Base Level 2 lock exists; provisioning blocked.")
    plan.verify_source(plan.SOURCE)
    try:
        database.rootless()
        database.absent("container", database.CONTAINER)
        database.absent("volume", database.VOLUME)
    except database.IsolationError as error:
        raise preflight.PreflightError(str(error)) from error
    port_available()

    # Create-only operations. An error leaves evidence for explicit review;
    # never replace, restart, remove or auto-clean a previously existing resource.
    run_new(["podman", "volume", "create", "--label", database.LABEL,
             database.VOLUME], "volume", timeout=30)
    run_new([
        "podman", "run", "--detach", "--name", database.CONTAINER,
        "--label", database.LABEL,
        "--publish", f"127.0.0.1:{database.PORT}:3306",
        "--volume", f"{database.VOLUME}:/var/lib/mysql:Z",
        "--env", "MARIADB_ROOT_PASSWORD=root",
        database.IMAGE,
    ], "container", timeout=180)

    preflight.validate_podman_metadata(preflight.read_podman_metadata())
    # MariaDB initialises its empty datadir on the first run. Test only
    # SELECT 1 and never create or alter a schema during provisioning.
    for _ in range(30):
        try:
            result = subprocess.run(
                ["podman", "exec", database.CONTAINER, "mariadb",
                 "-uroot", "-proot", "--batch", "--skip-column-names",
                 "-e", "SELECT 1;"],
                capture_output=True, text=True, check=False, timeout=8,
            )
        except (OSError, subprocess.TimeoutExpired):
            result = None
        if result is not None and result.returncode == 0 and result.stdout.strip() == "1":
            print("Base Level 2 database: PROVISIONED (rootless Podman; isolated).")
            print(f"Container: {database.CONTAINER}; volume: {database.VOLUME}")
            print(f"Endpoint: {database.HOST}; no test schema created.")
            print("Next: read-only --preflight / --dry-run; --execute remains separately gated.")
            return
        time.sleep(2)
    raise preflight.PreflightError(
        "New Base Podman container exists, but MariaDB readiness was not proven; "
        "inspect manually, do not rerun provisioning."
    )


def main(args: list[str] | None = None) -> int:
    args = sys.argv[1:] if args is None else args
    if args != ["--provision-db"]:
        print("Usage: python3 -B -m tools.integration.provision --provision-db", file=sys.stderr)
        return 64
    if os.environ.get("CB_BASE_DB_PROVISION_APPROVAL") != APPROVAL:
        print("BLOCKED: dedicated Base Podman resource approval is missing.", file=sys.stderr)
        return 2
    try:
        provision()
    except preflight.PreflightError as error:
        print(f"Base Level 2 Podman provision: BLOCKED: {error}", file=sys.stderr)
        print("No automatic resource deletion or reset.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
