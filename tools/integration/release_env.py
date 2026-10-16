"""Bind the canonical Base release builder to a proven, owned Level 2 workspace.

This is not a setup or repair tool. No filesystem, Docker, or database mutation
occurs while resolving the environment. A historical/manual WordPress test copy
is never selected automatically.
"""
from __future__ import annotations

import os
from pathlib import Path
import stat

from tools.integration import execution, inspect, plan, preflight

REQUIRED = (
    "WP_CORE_DIR", "WP_TESTS_DIR", "CB_PLUGIN_FILE",
    "WP_DB_NAME", "WP_DB_USER", "WP_DB_PASSWORD", "WP_DB_HOST",
)


def resolve(source: Path) -> dict[str, str]:
    """Return settings only for an exact-head, fully evidenced workspace."""
    if source.resolve() != plan.SOURCE.resolve():
        raise preflight.PreflightError(
            "Proven Level 2 workspace belongs to another Base checkout."
        )
    root = execution.TEST_ROOT
    if os.environ.get("CB_TEST_ROOT", str(root)) != str(root):
        raise preflight.PreflightError("Only the canonical Base test slot is eligible.")
    preflight.verify_parent_security(root)
    owner = inspect.owner_record(root, execution.OWNER_FILE)
    version = plan.validate_version(os.environ.get("CB_TEST_WP_VERSION") or owner["wordpress"])
    plan.verify_source(source)
    sha = preflight.read_source_commit(source)
    if sha != owner["git_sha"] or version != owner["wordpress"]:
        raise preflight.PreflightError(
            "Level 2 evidence belongs to a different source SHA or WordPress version. "
            "A new approved isolated run is required; never repoint an older workspace."
        )
    if execution.LOCK_FILE.is_symlink():
        raise preflight.PreflightError("Base integration lock is a symlink.")
    inspect.require_file(execution.LOCK_FILE)
    if stat.S_IMODE(execution.LOCK_FILE.stat().st_mode) != 0o600:
        raise preflight.PreflightError("Base integration lock permissions are unsafe.")
    warnings, _ = preflight.validate_docker_metadata(preflight.read_docker_metadata())
    if any("beyond loopback" in warning for warning in warnings):
        raise preflight.PreflightError("Unsafe MariaDB publication.")
    if not plan.database_exists():
        raise preflight.PreflightError("Owned Base test database is missing.")
    if not inspect.final_evidence(root, owner).startswith("PASS ("):
        raise preflight.PreflightError(
            "Full exact-head Level 2 PASS receipts are missing or incomplete."
        )
    # Validate each staged fixture component, not just its leaf. Reject
    # symlinks anywhere in the WordPress plugin ancestor path; a symlinked
    # wp-content/ or plugins/ could otherwise redirect the release runtime.
    directories = (
        execution.WP,
        execution.WP / "wp-content",
        execution.WP / "wp-content/plugins",
        execution.WP_TESTS,
        execution.WP_TESTS / "includes",
        execution.PLUGIN_DIR,
    )
    files = (
        execution.WP / "wp-load.php",
        execution.WP_TESTS / "includes/bootstrap.php",
        execution.PLUGIN_DIR / "core-blueprint.php",
    )
    for path, expected_kind in (
        *((directory, "directory") for directory in directories),
        *((file, "file") for file in files),
    ):
        if path.is_symlink() or not path.exists():
            raise preflight.PreflightError(
                "Required Level 2 fixture missing or replaced by a symlink."
            )
        info = path.stat()
        if info.st_uid != os.getuid() or not (
            stat.S_ISDIR(info.st_mode) if expected_kind == "directory"
            else stat.S_ISREG(info.st_mode)
        ):
            raise preflight.PreflightError(
                "Required Level 2 fixture has untrusted owner or file type."
            )
    # The builder rechecks runtime manifest, PHP and all mandatory gates.
    # Check parity here too, before providing any connection settings.
    execution.verify_runtime_parity(sha)
    return execution.test_environment(version)


def bind(source: Path) -> bool:
    """Bind only when *all* manual database/runtime settings are absent.

    A partial explicit configuration fails closed in the builder's own
    preflight rather than silently mixing two database authorities.
    CB_BASE_USE_PROVEN_LEVEL2=1 explicitly selects proof instead of stale
    shell exports, but never bypasses proof checks.
    """
    populated = [key for key in REQUIRED if os.environ.get(key)]
    force_proven = os.environ.get("CB_BASE_USE_PROVEN_LEVEL2") == "1"
    if populated and not force_proven:
        if len(populated) != len(REQUIRED):
            raise preflight.PreflightError(
                "Partial WordPress/database environment: unset all WP_* and "
                "CB_PLUGIN_FILE values to use proven Level 2 evidence, or "
                "supply a complete separately reviewed explicit environment."
            )
        return False
    env = resolve(source)
    for key in REQUIRED + ("WP_PHPUNIT__TABLE_PREFIX",):
        os.environ[key] = env[key]
    print(
        "[release] Proven Level 2 workspace selected: "
        f"{execution.TEST_ROOT} (exact SHA, full gate receipts, owned database)",
        flush=True,
    )
    return True
