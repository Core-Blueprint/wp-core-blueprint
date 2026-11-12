"""One-command Base Level 2 qualification using fresh, disposable Podman.

The existing legacy --execute runner is left independently protected.
This supervisor creates only new labelled resources, runs its full existing
WordPress matrix, preserves immutable receipts, then disposes of only proven
owned Podman resources. No WordPress customer/release work is performed.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import secrets
import signal
import stat
import subprocess
import sys
import time

from tools.integration import disposable_resources as resources
from tools.integration import preflight

SOURCE = Path(__file__).resolve().parents[2]
REPORTS = SOURCE / "dist/qualification/base-disposable"
APPROVAL = "I_APPROVE_FRESH_DISPOSABLE_BASE_LEVEL2"
EXACT_SHA = re.compile(r"[0-9a-f]{40}\Z")


def isolated_environment() -> dict[str, str]:
    """Never inherit a DB/site target, test slot or another product's test root."""
    scrub = ("WP_", "CB_BASE_", "CB_TEST_", "CB_PLUGIN_", "CB_WP_",
             "DB_", "MYSQL_", "PHPUNIT_", "TEST_DB_")
    base = {key: value for key, value in os.environ.items()
            if not key.startswith(scrub) and key not in (
                "DATABASE_URL", "RUNNER_TEMP", "TMPDIR", "PYTHONPATH")}
    base["PYTHONDONTWRITEBYTECODE"] = "1"
    return base


def command(args: list[str], env: dict[str, str], *, timeout: int = 60) -> str:
    try:
        completed = subprocess.run(args, cwd=SOURCE, env=env, text=True,
                                   capture_output=True, check=False, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise preflight.PreflightError("Qualification prerequisite unavailable.") from error
    if completed.returncode:
        raise preflight.PreflightError(
            "Qualification source/runtime prerequisite failed; no resources changed."
        )
    return completed.stdout.strip()


def verify_source(base: dict[str, str]) -> str:
    if os.geteuid() == 0:
        raise preflight.PreflightError("Rootful qualification is forbidden.")
    sha = command(["git", "rev-parse", "--verify", "HEAD"], base)
    if EXACT_SHA.fullmatch(sha) is None:
        raise preflight.PreflightError("Invalid Base Git source identity.")
    command(["python3", "-B", "-c",
             "from tools.integration import plan; plan.verify_source(plan.SOURCE)"],
            base)
    resources.require_rootless()
    preflight.verify_parent_security(Path("/tmp/core-blueprint-base-probe"))
    return sha


def receipt_path(token: str) -> Path:
    if resources.RUN_PATTERN.fullmatch(token) is None:
        raise preflight.PreflightError("Invalid qualification receipt identity.")
    return REPORTS / token


def make_receipt_directory(token: str) -> Path:
    for parent in (SOURCE / "dist", SOURCE / "dist/qualification", REPORTS):
        if parent.is_symlink() or (parent.exists() and not parent.is_dir()):
            raise preflight.PreflightError("Unsafe qualification receipt parent.")
        if parent.exists() and parent.stat().st_uid != os.getuid():
            raise preflight.PreflightError("Qualification receipt parent has wrong owner.")
        parent.mkdir(mode=0o700, exist_ok=True)
    destination = receipt_path(token)
    if destination.exists() or destination.is_symlink():
        raise preflight.PreflightError("Qualification receipt identity collision.")
    destination.mkdir(mode=0o700)
    return destination


def write_once(destination: Path, name: str, payload: dict) -> None:
    if not re.fullmatch(r"[a-z0-9-]+\.json", name):
        raise preflight.PreflightError("Invalid evidence filename.")
    path = destination / name
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL
                 | getattr(os, "O_NOFOLLOW", 0), 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as output:
        json.dump(payload, output, sort_keys=True)
        output.write("\n")
        output.flush()
        os.fsync(output.fileno())


def copy_gate_receipts(workspace: Path, destination: Path) -> int:
    """Copy only small owner-owned regular JSON evidence, not untrusted WP code."""
    source = workspace / "evidence"
    if not source.is_dir() or source.is_symlink() or source.stat().st_uid != os.getuid():
        return 0
    target = destination / "gates"
    target.mkdir(mode=0o700)
    count = total = 0
    for path in sorted(source.iterdir()):
        if not path.name.endswith(".json"):
            continue
        info = path.lstat()
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid()
                or info.st_nlink != 1 or info.st_size > 65536
                or not re.fullmatch(r"[0-9]{2}-[a-z0-9-]+\.json", path.name)):
            raise preflight.PreflightError("Untrusted Level 2 evidence file.")
        total += info.st_size
        if total > 1024 * 1024:
            raise preflight.PreflightError("Level 2 evidence exceeds safe bounds.")
        with path.open("rb") as original:
            data = original.read(65537)
        if len(data) != info.st_size:
            raise preflight.PreflightError("Level 2 evidence changed while archiving.")
        fd = os.open(target / path.name,
                     os.O_WRONLY | os.O_CREAT | os.O_EXCL
                     | getattr(os, "O_NOFOLLOW", 0), 0o600)
        with os.fdopen(fd, "wb") as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        count += 1
    return count


def run_wordpress(args: list[str], env: dict[str, str]) -> int:
    """Allow the existing runner to stream logs, but terminate on interruption."""
    process = subprocess.Popen(
        args, cwd=SOURCE, env=env, start_new_session=True)
    try:
        return process.wait()
    except KeyboardInterrupt:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()
        raise


def qualify() -> int:
    base = isolated_environment()
    sha = verify_source(base)
    token = "d" + secrets.token_hex(6)
    password = secrets.token_urlsafe(32)
    db = resources.Resources(token, password)
    report = make_receipt_directory(token)
    write_once(report, "00-start.json", {
        "schema": 1, "product": "core-blueprint", "mode": "disposable-level2",
        "git_sha": sha, "run": token, "database": f"core_blueprint_base_{token}_test",
        "status": "running",
    })
    env = None
    error = ""
    passed = False
    cleaned = False
    gate_count = 0
    print(f"Base disposable Level 2: run {token}; exact HEAD {sha}", flush=True)
    try:
        db.create(base)
        env = db.child_env(base)
        db.wait_ready()
        for module, argument in (("preflight", "--preflight"), ("plan", "--dry-run")):
            command(["python3", "-B", "-m", f"tools.integration.{module}", argument],
                    env)
        env["CB_BASE_INTEGRATION_APPROVAL"] = "I_APPROVE_FRESH_BASE_TEST_DATABASE"
        completed = run_wordpress(["python3", "-B", "-m",
                                   "tools.integration.execution", "--execute"], env)
        if completed:
            raise preflight.PreflightError("Full Base Level 2 matrix failed.")
        if command(["git", "rev-parse", "--verify", "HEAD"], env) != sha:
            raise preflight.PreflightError("Base HEAD changed during qualification.")
        proof = command(["python3", "-B", "-m",
                         "tools.integration.inspect", "--inspect"], env)
        if (f"Recorded source: {sha}" not in proof
                or f"Current source: {sha}" not in proof
                or "Level 2 outcome: PASS (recorded full Level 2 matrix" not in proof):
            raise preflight.PreflightError("Level 2 final receipt cannot be proven.")
        passed = True
    except (preflight.PreflightError, KeyboardInterrupt, OSError) as exception:
        error = ("Qualification interrupted." if isinstance(exception, KeyboardInterrupt)
                 else str(exception))
    finally:
        try:
            gate_count = copy_gate_receipts(
                Path("/tmp") / f"core-blueprint-base-{token}", report)
        except (preflight.PreflightError, OSError) as exception:
            error = error or f"Evidence archiving failed: {exception}"
            passed = False
        try:
            db.cleanup(env if env is not None else base)
            cleaned = True
        except (preflight.PreflightError, OSError) as exception:
            error = error or f"Disposable cleanup blocked: {exception}"
            cleaned = False
        write_once(report, "99-outcome.json", {
            "schema": 1, "product": "core-blueprint",
            "mode": "disposable-level2", "run": token, "git_sha": sha,
            "status": "pass" if passed and cleaned and not error else "fail",
            "resources_removed": cleaned, "archived_gate_receipts": gate_count,
            "workspace": f"/tmp/core-blueprint-base-{token}",
            "error": error,
        })
    if passed and cleaned and not error:
        print(f"Base disposable Level 2: PASS, resources removed; receipts: {report}",
              flush=True)
        return 0
    print(f"Base disposable Level 2: BLOCKED: {error or 'Unverified outcome'}",
          file=sys.stderr)
    print(f"Preserved qualification evidence: {report}", file=sys.stderr)
    if not cleaned:
        print(f"Owned resources may remain: {db.container} / {db.volume}; "
              "do not delete without verifying ownership.", file=sys.stderr)
    return 1


def main(args: list[str] | None = None) -> int:
    args = sys.argv[1:] if args is None else args
    if args == ["--preflight"]:
        try:
            sha = verify_source(isolated_environment())
        except preflight.PreflightError as error:
            print(f"Base disposable preflight: BLOCKED: {error}", file=sys.stderr)
            return 1
        print(f"Base disposable preflight: PASS (read-only, exact HEAD {sha})")
        print("No containers, volumes, databases or workspaces were changed.")
        return 0
    if args != ["--execute"]:
        print("Usage: ./tools/check-integration --disposable-preflight|--disposable", file=sys.stderr)
        return 64
    if os.environ.get("CB_BASE_DISPOSABLE_APPROVAL") != APPROVAL:
        print("BLOCKED: explicit fresh disposable Level 2 approval required.",
              file=sys.stderr)
        return 2
    try:
        return qualify()
    except preflight.PreflightError as error:
        print(f"Base disposable Level 2: BLOCKED: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
