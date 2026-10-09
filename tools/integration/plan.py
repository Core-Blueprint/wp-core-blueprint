"""B2b read-only execution planner. No database/file/container mutations."""
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import sys
from tools.integration import preflight

SOURCE = Path(__file__).resolve().parents[2]
TEST_ROOT = preflight.CANONICAL_TEST_ROOT
DATABASE = "core_blueprint_base_test"
DATABASE_HOST = "127.0.0.1:3307"
QUERY = (
    "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA "
    "WHERE SCHEMA_NAME = 'core_blueprint_base_test';"
)
REQUIRED = (
    "core-blueprint.php", "composer.lock", "vendor/bin/phpunit",
    "vendor/autoload.php", "phpunit.xml.dist",
    "tests/bin/install-wp-tests.sh", "tests/bin/run-lifecycle-scenario.sh",
    "tests/bin/install-pinned-starter.sh",
    "tests/bin/run-consumer-scenario.sh",
    "tests/bin/run-module-conformance-scenario.sh",
)


def read_only(command: list[str], label: str, timeout: int = 12) -> str:
    try:
        result = subprocess.run(
            command, capture_output=True, text=True, timeout=timeout,
            check=False, env={**os.environ, "GIT_OPTIONAL_LOCKS": "0"},
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise preflight.PreflightError(f"{label}: command unavailable or timed out.") from error
    if result.returncode != 0:
        raise preflight.PreflightError(f"{label}: read-only inspection failed.")
    return result.stdout


def validate_version(version: str) -> str:
    if version not in ("7.0", "7.1"):
        raise preflight.PreflightError("WordPress version must be 7.0 or 7.1.")
    return version


def verify_source(source: Path) -> None:
    missing = [name for name in REQUIRED if not (source / name).is_file()]
    if missing:
        raise preflight.PreflightError("Missing release/integration dependencies: " + ", ".join(missing))
    read_only(
        ["php", "-r", "exit(PHP_VERSION_ID >= 80400 ? 0 : 1);"], "PHP 8.4 baseline"
    )
    status = read_only(
        ["git", "-C", str(source), "status", "--porcelain", "--untracked-files=all",
         "--", ".", ":!dist", ":!build"],
        "Exact-head source cleanliness",
    )
    if status.strip():
        raise preflight.PreflightError("Base has uncommitted source changes.")


def database_exists() -> bool:
    """Only inspect INFORMATION_SCHEMA. No mutating SQL and no user-controlled name."""
    output = read_only(
        ["docker", "exec", preflight.CONTAINER, "mariadb",
         "-uroot", "-proot", "--batch", "--skip-column-names", "--raw", "-e", QUERY],
        "MariaDB schema inventory",
    )
    names = [row.strip() for row in output.splitlines() if row.strip()]
    if names == []:
        return False
    if names == [DATABASE]:
        return True
    raise preflight.PreflightError("Unrecognised database-inventory response.")


def plan_steps(version: str, sha: str) -> tuple[str, ...]:
    """Human-review plan only; B2b has no executor for these steps."""
    return (
        "Recheck exclusive Base test-root ownership, database name, lock and exact SHA",
        f"Create only NEW dedicated database {DATABASE} after separate explicit approval",
        f"Provision pinned WordPress {version} and matching wp-phpunit under {TEST_ROOT}/",
        f"Stage Base commit {sha} into canonical core-blueprint/ plugin test copy",
        "Verify staged files equal exact committed Base runtime",
        "Run the FULL vendor/bin/phpunit --testsuite integration "
        "--do-not-cache-result --fail-on-skipped --fail-on-incomplete",
        "Run lifecycle, pinned Starter consumer and module-conformance scenarios "
        "under the isolated test configuration",
        "Record exact-head evidence and every gate; do NOT include destructive uninstall",
        "Keep customer ZIP, CI, field tests, database cleanup and destructive uninstall independently gated",
    )


def dry_run() -> int:
    try:
        version = validate_version(os.environ.get("CB_TEST_WP_VERSION", "7.0"))
        target = os.environ.get("CB_TEST_ROOT", str(TEST_ROOT))
        state = preflight.validate_test_root(target)
        verify_source(SOURCE)
        sha = preflight.read_source_commit(SOURCE)
        warnings, description = preflight.validate_docker_metadata(
            preflight.read_docker_metadata()
        )
        occupied = database_exists()
    except preflight.PreflightError as error:
        print(f"Base Level 2 dry-run: BLOCKED: {error}", file=sys.stderr)
        print("No filesystem, Docker or database changes were attempted.", file=sys.stderr)
        return 1

    print("Base Level 2 dry-run: " + (
        "BLOCKED (existing test database)" if occupied
        else "PASS (read-only inspection, not integration)"
    ))
    print(f"Exact Base HEAD: {sha}")
    print(f"WordPress target: {version}; PHP baseline: 8.4+")
    print(f"Canonical workspace: {target} ({state}; untouched)")
    print(f"Test database: {DATABASE} at {DATABASE_HOST}")
    print(f"Schema collision: {'YES, existing database must be protected' if occupied else 'NO'}")
    print(f"MariaDB: {description}")
    for warning in warnings:
        print(f"WARNING: {warning}", file=sys.stderr)
    print("Execution: BLOCKED until separate approval and ownership/port/mount review")
    print("Proposed future actions (NOT executed):")
    for number, step in enumerate(plan_steps(version, sha), 1):
        print(f"  {number}. {step}")
    print("WordPress installation: NOT RUN; PHPUnit: NOT RUN; database writes: NONE.")
    return 1 if occupied else 0


if __name__ == "__main__":
    if sys.argv[1:] != ["--dry-run"]:
        print("Usage: python3 -B -m tools.integration.plan --dry-run", file=sys.stderr)
        raise SystemExit(64)
    raise SystemExit(dry_run())
