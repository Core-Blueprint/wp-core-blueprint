"""Prepared Base Level 2 integration engine. Not exposed by the CLI before approval.

Safety contract:
- immutable Git HEAD source, clean working tree and exact runtime copy parity;
- own new workspace only, exclusive owner-only lock, never remove existing files;
- NEW dedicated database only (CREATE without IF NOT EXISTS; never DROP);
- refuse Docker bindings outside loopback, never start/replace containers;
- no automatic cleanup on failure, preserve evidence for manual review;
- current CLI --execute remains BLOCKED until separate operator authorization.
"""
from __future__ import annotations

from contextlib import contextmanager
import fcntl
import hashlib
import importlib.machinery
import importlib.util
import io
import json
import os
from pathlib import Path, PurePosixPath
import stat
import subprocess
import tarfile
import urllib.request

from tools.integration import plan, preflight

SOURCE = plan.SOURCE
TEST_ROOT = preflight.CANONICAL_TEST_ROOT
LOCK_FILE = TEST_ROOT.parent / ".core-blueprint-base-integration.lock"
OWNER_FILE = TEST_ROOT / ".base-integration-owner.json"
DB = plan.DATABASE
WP = TEST_ROOT / "wp"
WP_TESTS = TEST_ROOT / "wp-tests"
PLUGIN_DIR = WP / "wp-content/plugins/core-blueprint"
WP_CLI = TEST_ROOT / "tools/wp-cli-2.12.0.phar"
MAX_DOWNLOAD_BYTES = 90 * 1024 * 1024
MAX_UNPACK_BYTES = 450 * 1024 * 1024


def verify_execution_scope() -> tuple[str, str]:
    """All checks read-only. Called again immediately before any future mutations."""
    version = plan.validate_version(os.environ.get("CB_TEST_WP_VERSION", "7.0"))
    state = preflight.validate_test_root(
        os.environ.get("CB_TEST_ROOT", str(TEST_ROOT))
    )
    if state != "absent":
        raise preflight.PreflightError("A fresh Base workspace must be absent before execution.")
    plan.verify_source(SOURCE)
    sha = preflight.read_source_commit(SOURCE)
    warnings, _ = preflight.validate_docker_metadata(
        preflight.read_docker_metadata()
    )
    if any("beyond loopback" in warning for warning in warnings):
        raise preflight.PreflightError(
            "MariaDB is published beyond loopback; execution requires a reviewed "
            "local-only binding before any database write."
        )
    if plan.database_exists():
        raise preflight.PreflightError(
            "Dedicated Base database exists. Existing databases are never reused or reset."
        )
    return version, sha


def require_owned_parent(path: Path) -> None:
    """Prevent lock-file manipulation through other users' or symlinked directories."""
    target = path.parent
    if target.is_symlink() or not target.is_dir():
        raise preflight.PreflightError("Test parent directory must exist and not be a symlink.")
    info = target.stat()
    if info.st_uid != os.getuid() or info.st_mode & (stat.S_IWOTH | stat.S_IWGRP):
        raise preflight.PreflightError(
            "Test parent directory must be owned by the operator and not world-writable."
        )


@contextmanager
def exclusive_lock():
    """Exclusive lock file, no symlinks/reuse. Leave stale locks for manual review."""
    preflight.validate_test_root(str(TEST_ROOT))
    # /tmp/core-blueprint-tests is the established shared parent across products.
    if not TEST_ROOT.parent.exists():
        TEST_ROOT.parent.mkdir(mode=0o700)
    require_owned_parent(TEST_ROOT)
    fd = None
    try:
        fd = os.open(
            LOCK_FILE,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0),
            0o600,
        )
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        os.write(fd, f"pid={os.getpid()}\n".encode("ascii"))
        yield
    except FileExistsError as error:
        raise preflight.PreflightError(
            "Another Base integration lock exists. Inspect it manually; never auto-remove."
        ) from error
    finally:
        if fd is not None:
            os.close(fd)
        # Deliberately no unlink. The lock persists to prevent accidental reruns
        # against a database or workspace whose ownership has not been reconciled.


def create_new_workspace(sha: str, version: str) -> None:
    """Create exact new root and owner marker. Never overwrite existing state."""
    preflight.validate_test_root(str(TEST_ROOT))
    if TEST_ROOT.exists():
        raise preflight.PreflightError("Workspace already exists; refusing reuse.")
    TEST_ROOT.mkdir(mode=0o700)
    owner = {
        "schema": 1,
        "product": "core-blueprint",
        "git_sha": sha,
        "wordpress": version,
        "database": DB,
        "policy": "new-only-no-cleanup",
    }
    with OWNER_FILE.open("x", encoding="utf-8") as handle:
        json.dump(owner, handle, sort_keys=True)
        handle.write("\n")


def assert_owner(sha: str) -> None:
    if TEST_ROOT.is_symlink() or OWNER_FILE.is_symlink():
        raise preflight.PreflightError("Owned workspace contains a symlink.")
    if not TEST_ROOT.is_dir() or not OWNER_FILE.is_file():
        raise preflight.PreflightError("Missing owned Base test workspace marker.")
    if TEST_ROOT.stat().st_uid != os.getuid() or OWNER_FILE.stat().st_uid != os.getuid():
        raise preflight.PreflightError("Base test workspace ownership changed.")
    try:
        record = json.loads(OWNER_FILE.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise preflight.PreflightError("Invalid owned workspace marker.") from error
    if record != {
        "schema": 1, "product": "core-blueprint", "git_sha": sha,
        "wordpress": record.get("wordpress"), "database": DB,
        "policy": "new-only-no-cleanup",
    } or record.get("wordpress") not in ("7.0", "7.1"):
        raise preflight.PreflightError("Base test workspace marker does not match the source.")


def create_fresh_database(sha: str) -> None:
    """Never drop, truncate or reuse. CREATE DATABASE fails if schema appeared meanwhile."""
    assert_owner(sha)
    if plan.database_exists():
        raise preflight.PreflightError("Database name collision; refusing to modify existing schema.")
    sql = (
        f"CREATE DATABASE `{DB}` CHARACTER SET utf8mb4 "
        "COLLATE utf8mb4_unicode_ci;"
    )
    plan.read_only(
        ["docker", "exec", preflight.CONTAINER, "mariadb", "-uroot", "-proot", "-e", sql],
        "Creation of a strictly new, isolated Base test database",
    )


def safe_extract(data: bytes, destination: Path, archive_root: str) -> None:
    """Only ordinary regular files and directories with a single exact archive root."""
    if destination.exists() or destination.is_symlink():
        raise preflight.PreflightError("Archive destination already exists.")
    validated: list[tuple[tarfile.TarInfo, Path]] = []
    expanded = 0
    try:
        with tarfile.open(fileobj=io.BytesIO(data), mode="r:*") as archive:
            for member in archive:
                name = member.name.rstrip("/")
                if name.startswith("/") or not name or "//" in name:
                    raise preflight.PreflightError("Archive entry has an unsafe path.")
                parts = name.split("/")
                if any(segment in ("", ".", "..") for segment in parts):
                    raise preflight.PreflightError("Archive entry escapes the pinned root.")
                if archive_root:
                    if parts[0] != archive_root:
                        raise preflight.PreflightError("Archive entry escapes the pinned root.")
                    parts = parts[1:]
                if not (member.isdir() or member.isfile()):
                    raise preflight.PreflightError("Archive contains link or unsupported entry.")
                expanded += member.size if member.isfile() else 0
                if expanded > MAX_UNPACK_BYTES:
                    raise preflight.PreflightError("Archive expansion size limit exceeded.")
                if parts:
                    validated.append((member, Path(*parts)))
            destination.mkdir(mode=0o700)
            for member, relative in validated:
                path = destination / relative
                if member.isdir():
                    path.mkdir(parents=True, exist_ok=True)
                else:
                    path.parent.mkdir(parents=True, exist_ok=True)
                    stream = archive.extractfile(member)
                    if stream is None:
                        raise preflight.PreflightError("Archive file entry cannot be read.")
                    with path.open("xb") as output:
                        remaining = member.size
                        while remaining:
                            chunk = stream.read(min(65536, remaining))
                            if not chunk:
                                raise preflight.PreflightError("Incomplete archive entry.")
                            output.write(chunk)
                            remaining -= len(chunk)
    except (tarfile.TarError, OSError) as error:
        raise preflight.PreflightError("Pinned archive unpack failed.") from error


def download_pinned_archive(url: str) -> bytes:
    """Bounded HTTPS-only downloads of exact WordPress and wp-phpunit versions."""
    if url not in (
        "https://wordpress.org/wordpress-7.0.tar.gz",
        "https://wordpress.org/wordpress-7.1.tar.gz",
        "https://codeload.github.com/wp-phpunit/wp-phpunit/tar.gz/refs/tags/7.0.0",
        "https://codeload.github.com/wp-phpunit/wp-phpunit/tar.gz/refs/tags/7.1.0",
    ):
        raise preflight.PreflightError("Unapproved external fixture URL.")
    try:
        with urllib.request.urlopen(url, timeout=60) as response:
            if not response.url.startswith("https://"):
                raise preflight.PreflightError("Pinned archive redirected to an insecure URL.")
            data = response.read(MAX_DOWNLOAD_BYTES + 1)
    except OSError as error:
        raise preflight.PreflightError("Pinned WordPress fixture download failed.") from error
    if len(data) > MAX_DOWNLOAD_BYTES:
        raise preflight.PreflightError("Fixture download exceeds allowed size.")
    return data


def stage_pinned_environment(version: str, sha: str) -> None:
    assert_owner(sha)
    word = download_pinned_archive(f"https://wordpress.org/wordpress-{version}.tar.gz")
    safe_extract(word, WP, "wordpress")
    assert_owner(sha)
    test_lib = download_pinned_archive(
        "https://codeload.github.com/wp-phpunit/wp-phpunit/tar.gz/refs/tags/"
        f"{version}.0"
    )
    safe_extract(test_lib, WP_TESTS, f"wp-phpunit-{version}.0")
    assert_owner(sha)
    result = subprocess.run(
        ["git", "-C", str(SOURCE), "archive", "--format=tar", sha],
        capture_output=True, check=False, env={**os.environ, "GIT_OPTIONAL_LOCKS": "0"},
    )
    if result.returncode != 0:
        raise preflight.PreflightError("Could not stage exact Base Git commit.")
    safe_extract(result.stdout, PLUGIN_DIR, "")
    # Exact source-copy parity is verified separately, before any PHP/WordPress code executes.


def verify_runtime_parity(sha: str) -> None:
    assert_owner(sha)
    builder_path = SOURCE / "tools/build-release"
    loader = importlib.machinery.SourceFileLoader("cb_base_release_parity", str(builder_path))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    builder = importlib.util.module_from_spec(spec)
    loader.exec_module(builder)
    if builder.runtime_hashes(SOURCE) != builder.runtime_hashes(PLUGIN_DIR):
        raise preflight.PreflightError(
            "Staged WordPress plugin differs from exact release source; refusing tests."
        )
    if preflight.read_source_commit(SOURCE) != sha:
        raise preflight.PreflightError("Base source SHA changed during staging.")


def test_environment(version: str) -> dict[str, str]:
    env = {**os.environ}
    env.update({
        "WP_CORE_DIR": str(WP),
        "WP_TESTS_DIR": str(WP_TESTS),
        "CB_PLUGIN_FILE": str(PLUGIN_DIR / "core-blueprint.php"),
        "WP_DB_NAME": DB,
        "WP_DB_USER": "root",
        "WP_DB_PASSWORD": "root",
        "WP_DB_HOST": plan.DATABASE_HOST,
        "WP_PHPUNIT__TABLE_PREFIX": "cbtests_",
        "CB_LIFECYCLE_TABLE_PREFIX": "cblifecycle_",
        "CB_CONSUMER_TABLE_PREFIX": "cbconsumer_",
        "CB_B3_TABLE_PREFIX": "cbb3_",
        "CB_CLI_TABLE_PREFIX": "cbcli_",
        "CB_PERFORMANCE_TABLE_PREFIX": "cbperf_",
        "CB_WP_CLI_PHAR": str(WP_CLI),
        "CB_PERFORMANCE_RESULTS_DIR": str(TEST_ROOT / "results/performance"),
        "RUNNER_TEMP": str(TEST_ROOT / "tmp"),
        "TMPDIR": str(TEST_ROOT / "tmp"),
    })
    return env


def integration_commands(version: str) -> tuple[tuple[str, ...], ...]:
    return (
        ("php", "vendor/bin/phpunit", "--testsuite", "integration", "--do-not-cache-result",
         "--fail-on-skipped", "--fail-on-incomplete"),
        ("bash", "tests/bin/run-performance-baseline.sh"),
        ("bash", "tests/bin/run-lifecycle-scenario.sh"),
        ("bash", "tests/bin/install-pinned-starter.sh"),
        ("php", str(WP / "wp-content/plugins/core-blueprint-starter-plugin/tools/conformance.php")),
        ("bash", "tests/bin/run-consumer-scenario.sh"),
        ("bash", "tests/bin/run-module-conformance-scenario.sh"),
        ("bash", "tests/bin/install-wp-cli.sh"),
        ("bash", "tests/bin/run-cli-smoke-scenario.sh", version),
        ("bash", "tests/bin/run-cli-role-policy-failsafe-conformance.sh", version),
        ("bash", "tests/bin/run-cli-provenance-conformance.sh", version),
        ("bash", "tests/bin/run-media-replace-persistence-conformance.sh", version),
    )


def run_full_suite(version: str, sha: str) -> None:
    """Full local suite. Never invoked by any public B2c CLI mode."""
    verify_runtime_parity(sha)
    env = test_environment(version)
    (TEST_ROOT / "tmp").mkdir(exist_ok=False)
    for command in integration_commands(version):
        assert_owner(sha)
        result = subprocess.run(command, cwd=SOURCE, env=env, check=False)
        if result.returncode:
            raise preflight.PreflightError(
                f"Required Base integration gate failed (exit {result.returncode}): {command[1]}"
            )
    verify_runtime_parity(sha)


def prepared_pipeline() -> None:
    """Implemented pipeline, intentionally inaccessible from CLI until separate GO.

    Real execution is blocked on the current operator's non-loopback MariaDB
    binding and the missing post-review activation path.
    """
    version, sha = verify_execution_scope()
    with exclusive_lock():
        version2, sha2 = verify_execution_scope()
        if (version2, sha2) != (version, sha):
            raise preflight.PreflightError("Source or environment changed before staging.")
        create_new_workspace(sha, version)
        create_fresh_database(sha)
        stage_pinned_environment(version, sha)
        run_full_suite(version, sha)


if __name__ == "__main__":
    raise SystemExit(
        "BLOCKED: Base B2c integration engine is prepared, but no execution CLI is "
        "authorized. Review safety tests and request a separate execution GO."
    )
