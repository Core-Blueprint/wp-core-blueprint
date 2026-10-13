"""Read-only inspection of an owned Base Level 2 workspace.

This is NOT a replay/resume tool, nor evidence of a historical suite PASS.
It never creates or resets a database, starts Docker, or changes files.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import stat
import sys

from tools.integration import execution, plan, preflight


def require_file(path: Path) -> None:
    if path.is_symlink() or not path.is_file() or path.stat().st_uid != os.getuid():
        raise preflight.PreflightError(f"Untrusted or missing owned file: {path.name}")


def owner_record(root: Path, marker: Path) -> dict:
    if root.is_symlink() or not root.is_dir():
        raise preflight.PreflightError("Owned Base workspace is not a regular directory.")
    meta = root.stat()
    if meta.st_uid != os.getuid() or stat.S_IMODE(meta.st_mode) != 0o700:
        raise preflight.PreflightError("Workspace must be operator-owned mode 0700.")
    require_file(marker)
    try:
        record = json.loads(marker.read_text(encoding="utf-8"))
    except (ValueError, OSError) as error:
        raise preflight.PreflightError("Invalid Base workspace owner marker.") from error
    if not isinstance(record, dict):
        raise preflight.PreflightError("Owner marker must be a JSON object.")
    sha = record.get("git_sha")
    if not isinstance(sha, str) or re.fullmatch("[a-f0-9]{40}", sha) is None:
        raise preflight.PreflightError("Invalid recorded Git SHA.")
    if record != {
        "schema": 1, "product": "core-blueprint", "git_sha": sha,
        "wordpress": record.get("wordpress"), "database": plan.DATABASE,
        "policy": "new-only-no-cleanup",
    } or record["wordpress"] not in ("7.0", "7.1"):
        raise preflight.PreflightError("Workspace marker does not match requested Base slot.")
    return record


def final_evidence(root: Path, owner: dict) -> str:
    directory = root / "evidence"
    if not directory.exists() and not directory.is_symlink():
        return "UNVERIFIED (historical runner created no durable gate receipts)"
    if directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.getuid():
        raise preflight.PreflightError("Evidence directory is untrusted.")
    final = directory / "99-final-pass.json"
    if not final.exists() and not final.is_symlink():
        return "INCOMPLETE (no recorded final PASS)"
    require_file(final)
    try:
        receipt = json.loads(final.read_text(encoding="utf-8"))
    except (ValueError, OSError) as error:
        raise preflight.PreflightError("Invalid final evidence receipt.") from error
    commands = execution.integration_commands(owner["wordpress"])
    if (not isinstance(receipt, dict) or receipt.get("schema") != 1
            or receipt.get("product") != "core-blueprint"
            or receipt.get("git_sha") != owner["git_sha"]
            or receipt.get("database") != owner["database"]
            or receipt.get("wordpress") != owner["wordpress"]
            or receipt.get("status") != "pass"
            or receipt.get("gate") != "final-runtime-parity"
            or receipt.get("exit_code") != 0
            or receipt.get("gate_count") != len(commands)):
        raise preflight.PreflightError("Final receipt fails exact-scope validation.")
    for index, command in enumerate(commands, start=1):
        gate_path = directory / f"{index:02d}-pass.json"
        require_file(gate_path)
        try:
            gate_receipt = json.loads(gate_path.read_text(encoding="utf-8"))
        except (ValueError, OSError) as error:
            raise preflight.PreflightError("Invalid per-gate evidence receipt.") from error
        if (gate_receipt.get("git_sha") != owner["git_sha"]
                or gate_receipt.get("database") != owner["database"]
                or gate_receipt.get("wordpress") != owner["wordpress"]
                or gate_receipt.get("status") != "pass"
                or gate_receipt.get("gate") != command[1]
                or gate_receipt.get("exit_code") != 0):
            raise preflight.PreflightError("Per-gate receipt fails exact-scope validation.")
    return "PASS (recorded full Level 2 matrix and final runtime parity)"


def inspect() -> int:
    try:
        root = execution.TEST_ROOT
        if os.environ.get("CB_TEST_ROOT", str(root)) != str(root):
            raise preflight.PreflightError("Inspection accepts only the canonical slot workspace.")
        preflight.verify_parent_security(root)
        # Canonical root has one direct /tmp child. Refuse symlinks before opening it.
        if root.is_symlink():
            raise preflight.PreflightError("Workspace is a symlink.")
        if not root.exists():
            print(f"Base Level 2 inspection: ABSENT (no workspace: {root})")
            return 1
        owner = owner_record(root, execution.OWNER_FILE)
        if execution.LOCK_FILE.is_symlink():
            raise preflight.PreflightError("Base lock is a symlink.")
        if execution.LOCK_FILE.exists():
            require_file(execution.LOCK_FILE)
            lock_meta = execution.LOCK_FILE.stat()
            if stat.S_IMODE(lock_meta.st_mode) != 0o600:
                raise preflight.PreflightError("Base lock must have mode 0600.")
        database_exists = plan.database_exists()  # read-only INFORMATION_SCHEMA
        current_sha = preflight.read_source_commit(plan.SOURCE)
        status = final_evidence(root, owner)
    except preflight.PreflightError as error:
        print(f"Base Level 2 inspection: BLOCKED: {error}", file=sys.stderr)
        return 1

    print("Base Level 2 inspection: PASS (read-only; no resources modified)")
    print(f"Workspace: {root} (owned, 0700)")
    print(f"Recorded source: {owner['git_sha']}")
    print(f"Current source: {current_sha}")
    print("Source moved since original execution: " + ("YES" if current_sha != owner["git_sha"] else "NO"))
    print(f"WordPress target: {owner['wordpress']}")
    print(f"Dedicated database: {owner['database']} ({'present' if database_exists else 'absent'})")
    print(f"Exclusive lock: {'present' if execution.LOCK_FILE.exists() else 'absent'} (not proof of an active process)")
    for label, relative in (
        ("WordPress runtime", "wp/wp-load.php"),
        ("wp-phpunit fixture", "wp-tests/includes/bootstrap.php"),
        ("Staged Base plugin", "wp/wp-content/plugins/core-blueprint/core-blueprint.php"),
        ("Pinned WP-CLI", "tools/wp-cli-2.12.0.phar"),
        ("Performance report", "results/performance/baseline.json"),
        ("Final-stage HTTP log", "tmp/cb-c2c-http-" + owner["wordpress"] + ".log"),
    ):
        path = root / relative
        print(f"{label}: {'present (not PASS evidence)' if path.is_file() and not path.is_symlink() else 'missing'}")
    print(f"Level 2 outcome: {status}")
    print("Inspection does not run PHP/WordPress tests or authorize reuse/reset.")
    return 0


if __name__ == "__main__":
    if sys.argv[1:] != ["--inspect"]:
        print("Usage: python3 -B -m tools.integration.inspect --inspect", file=sys.stderr)
        raise SystemExit(64)
    raise SystemExit(inspect())
