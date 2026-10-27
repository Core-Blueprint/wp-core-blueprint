"""Prepared Base Level 2 integration engine. Not exposed by the CLI before approval.

Safety contract:
- immutable Git HEAD source, clean working tree and exact runtime copy parity;
- own new workspace only, exclusive owner-only lock, never remove existing files;
- NEW dedicated database only (CREATE without IF NOT EXISTS; never DROP);
- refuse Docker bindings outside loopback, never start/replace containers;
- no automatic cleanup on failure, preserve evidence for manual review;
- CLI execution requires an explicit operator opt-in and safe environment preflight.
"""
from __future__ import annotations

from contextlib import contextmanager
import fcntl
import importlib.machinery
import importlib.util
import io
import json
import os
import selectors
import signal
import time
from pathlib import Path
import subprocess
import sys
import tarfile
import urllib.request
from urllib.parse import urlparse

from tools.integration import plan, preflight

SOURCE = plan.SOURCE
TEST_ROOT = preflight.CANONICAL_TEST_ROOT
LOCK_FILE = TEST_ROOT.parent / (
    f".core-blueprint-base-integration-{preflight.RUN_SLOT}.lock"
    if preflight.RUN_SLOT else ".core-blueprint-base-integration.lock"
)
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
    preflight.verify_parent_security(TEST_ROOT)
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
    """Use the same parent safety contract as read-only preflight/dry-run."""
    preflight.verify_parent_security(path)


@contextmanager
def exclusive_lock():
    """Exclusive lock file, no symlinks/reuse. Leave stale locks for manual review."""
    preflight.validate_test_root(str(TEST_ROOT))
    # Locks are new-only in the OS sticky /tmp; shared Work directories
    # and their permissions are never modified.
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
    # Recheck the container immediately before the sole permitted SQL mutation.
    warnings, _ = preflight.validate_docker_metadata(
        preflight.read_docker_metadata()
    )
    if any("beyond loopback" in warning for warning in warnings):
        raise preflight.PreflightError("MariaDB network exposure changed before database creation.")
    if preflight.read_source_commit(SOURCE) != sha:
        raise preflight.PreflightError("Source changed before database creation.")
    try:
        result = subprocess.run(
            ["docker", "exec", preflight.CONTAINER, "mariadb",
             "-uroot", "-proot", "-e", sql],
            capture_output=True, text=True, check=False, timeout=20,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise preflight.PreflightError("Creating the new isolated schema failed.") from error
    if result.returncode != 0:
        raise preflight.PreflightError(
            "CREATE DATABASE did not succeed; existing schemas were not modified."
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
            url_parts = urlparse(response.url)
            if (url_parts.scheme != "https" or url_parts.hostname not in (
                "wordpress.org", "downloads.wordpress.org", "codeload.github.com",
            )):
                raise preflight.PreflightError("Pinned archive redirected outside approved HTTPS hosts.")
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


def canonical_packager():
    """Reuse the single canonical builder; do not mint synthetic test manifests."""
    builder_path = SOURCE / "tools/build-release"
    loader = importlib.machinery.SourceFileLoader("cb_base_release_parity", str(builder_path))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    builder = importlib.util.module_from_spec(spec)
    loader.exec_module(builder)
    return builder


def stage_packaged_runtime(sha: str) -> None:
    """Transform only the newly owned test copy into the genuine Base package."""
    assert_owner(sha)
    if preflight.read_source_commit(SOURCE) != sha:
        raise preflight.PreflightError("Base source moved before packaging.")
    builder = canonical_packager()
    if builder.runtime_hashes(SOURCE) != builder.runtime_hashes(PLUGIN_DIR):
        raise preflight.PreflightError("Staged source is not the exact committed runtime.")
    payloads = builder.package_payloads(
        SOURCE, builder.collect_files(SOURCE), builder.design_module_revision(SOURCE)
    )
    for relative, data in payloads.items():
        staged = PLUGIN_DIR / relative
        if staged.is_symlink() or (staged.exists() and not staged.is_file()):
            raise preflight.PreflightError("Packaged runtime path is not an ordinary file.")
        if relative == builder.PLATFORM_MANIFEST:
            with staged.open("xb") as output:
                output.write(data)
        else:
            if not staged.is_file():
                raise preflight.PreflightError("Packaged runtime file missing from exact Git stage.")
            staged.write_bytes(data)
    verify_runtime_parity(sha)


def verify_runtime_parity(sha: str) -> None:
    assert_owner(sha)
    if preflight.read_source_commit(SOURCE) != sha:
        raise preflight.PreflightError("Base source SHA changed during staging.")
    builder = canonical_packager()
    if not builder.packaged_runtime_matches(SOURCE, PLUGIN_DIR):
        raise preflight.PreflightError(
            "Staged WordPress plugin differs from exact canonical package; refusing tests."
        )


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
         "--fail-on-skipped", "--fail-on-incomplete", "--debug"),
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


def record_receipt(name: str, sha: str, version: str, status: str,
                   gate: str = "", exit_code: int | None = None,
                   gate_count: int | None = None,
                   timeout_reason: str | None = None) -> None:
    """Write immutable gate evidence inside the strictly owned test workspace.

    Files are new-only; no existing record can be overwritten or post-edited.
    The final PASS record is written *only* after all gates and parity succeed.
    """
    assert_owner(sha)
    if not name or any(char not in "abcdefghijklmnopqrstuvwxyz0123456789-" for char in name):
        raise preflight.PreflightError("Invalid immutable evidence filename.")
    record = {
        "schema": 1, "product": "core-blueprint", "git_sha": sha,
        "wordpress": version, "database": DB, "status": status,
        "gate": gate, "exit_code": exit_code, "gate_count": gate_count,
    }
    if timeout_reason is not None:
        record["timeout_reason"] = timeout_reason
    path = TEST_ROOT / "evidence" / f"{name}.json"
    fd = None
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                     getattr(os, "O_NOFOLLOW", 0), 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as output:
            fd = None
            json.dump(record, output, sort_keys=True)
            output.write("\n")
            output.flush()
            os.fsync(output.fileno())
    except OSError as error:
        raise preflight.PreflightError("Unable to persist immutable Level 2 evidence.") from error
    finally:
        if fd is not None:
            os.close(fd)



GATE_INACTIVITY_SECONDS = 180
GATE_MAX_SECONDS = 1800


def terminate_gate_group(process: subprocess.Popen) -> None:
    """Stop the entire isolated gate process group, including descendants."""
    for sig, grace in ((signal.SIGTERM, 5), (signal.SIGKILL, 3)):
        try:
            os.killpg(process.pid, sig)
        except ProcessLookupError:
            pass
        try:
            process.wait(timeout=grace)
        except subprocess.TimeoutExpired:
            pass


def run_gate(command: tuple[str, ...], env: dict[str, str],
             stdout, stderr, *,
             inactivity_seconds: float = GATE_INACTIVITY_SECONDS,
             max_seconds: float = GATE_MAX_SECONDS) -> tuple[int, str | None]:
    """Tee live stdout/stderr into immutable logs; timeout is never success."""
    process = subprocess.Popen(
        command, cwd=SOURCE, env=env, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, start_new_session=True, bufsize=0,
    )
    started = last_activity = time.monotonic()
    timeout_reason = None
    streams = ((process.stdout, stdout, sys.stdout),
               (process.stderr, stderr, sys.stderr))
    try:
        with selectors.DefaultSelector() as selector:
            for pipe, log, console in streams:
                selector.register(pipe, selectors.EVENT_READ, (log, console))
            while selector.get_map() or process.poll() is None:
                now = time.monotonic()
                if now - started >= max_seconds:
                    timeout_reason = "total"
                    break
                if now - last_activity >= inactivity_seconds:
                    timeout_reason = "inactivity"
                    break
                for key, _ in selector.select(timeout=min(
                    0.5, max_seconds - (now - started),
                    inactivity_seconds - (now - last_activity),
                )):
                    chunk = os.read(key.fd, 65536)
                    if not chunk:
                        selector.unregister(key.fileobj)
                        key.fileobj.close()
                        continue
                    log, console = key.data
                    log.write(chunk)
                    log.flush()
                    live_binary = getattr(console, "buffer", None)
                    if live_binary is not None:
                        live_binary.write(chunk)
                        live_binary.flush()
                    else:
                        console.write(chunk.decode("utf-8", errors="replace"))
                        console.flush()
                    last_activity = time.monotonic()
        if timeout_reason is not None:
            terminate_gate_group(process)
            return 124, timeout_reason
        return process.wait(), None
    finally:
        if process.poll() is None:
            terminate_gate_group(process)
        for pipe, _, _ in streams:
            if not pipe.closed:
                pipe.close()


def run_full_suite(version: str, sha: str) -> None:
    """Full local suite with persistent fail-closed per-gate evidence."""
    verify_runtime_parity(sha)
    env = test_environment(version)
    (TEST_ROOT / "tmp").mkdir(exist_ok=False)
    (TEST_ROOT / "evidence").mkdir(mode=0o700, exist_ok=False)
    commands = integration_commands(version)
    record_receipt("00-start", sha, version, "running", gate_count=len(commands))
    for index, command in enumerate(commands, start=1):
        assert_owner(sha)
        gate = command[1]
        record_receipt(f"{index:02d}-start", sha, version, "running", gate=gate)
        # Persist the full unfiltered gate output before judging its exit status.
        # Both files are new-only, private and confined to the verified workspace.
        def output_file(suffix: str):
            location = TEST_ROOT / "evidence" / f"{index:02d}-{suffix}.log"
            descriptor = os.open(
                location, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                getattr(os, "O_NOFOLLOW", 0), 0o600
            )
            return os.fdopen(descriptor, "wb")

        with output_file("stdout") as stdout, output_file("stderr") as stderr:
            try:
                exit_code, timeout_reason = run_gate(command, env, stdout, stderr)
            except OSError as error:
                record_receipt(f"{index:02d}-fail", sha, version, "fail", gate=gate)
                raise preflight.PreflightError(
                    f"Required Base integration gate could not start or stream: {gate}"
                ) from error
            finally:
                stdout.flush()
                stderr.flush()
                os.fsync(stdout.fileno())
                os.fsync(stderr.fileno())
        if timeout_reason is not None:
            record_receipt(f"{index:02d}-timeout", sha, version, "timeout",
                           gate=gate, exit_code=124, timeout_reason=timeout_reason)
            raise preflight.PreflightError(
                f"Required Base integration gate timed out ({timeout_reason}): {gate}"
            )
        if exit_code:
            record_receipt(f"{index:02d}-fail", sha, version, "fail",
                           gate=gate, exit_code=exit_code)
            raise preflight.PreflightError(
                f"Required Base integration gate failed (exit {exit_code}): {gate}"
            )
        record_receipt(f"{index:02d}-pass", sha, version, "pass",
                       gate=gate, exit_code=0)
    verify_runtime_parity(sha)
    record_receipt("99-final-pass", sha, version, "pass",
                   gate="final-runtime-parity", exit_code=0,
                   gate_count=len(commands))


def prepared_pipeline() -> None:
    """Run only after CLI opt-in and the complete fail-closed safety preflight.

    Existing workstation configurations with non-loopback MariaDB bindings
    remain blocked. No Docker reconfiguration or database reset is attempted.
    """
    version, sha = verify_execution_scope()
    with exclusive_lock():
        version2, sha2 = verify_execution_scope()
        if (version2, sha2) != (version, sha):
            raise preflight.PreflightError("Source or environment changed before staging.")
        create_new_workspace(sha, version)
        create_fresh_database(sha)
        stage_pinned_environment(version, sha)
        stage_packaged_runtime(sha)
        run_full_suite(version, sha)


def main(args: list[str] | None = None) -> int:
    """Opt-in entrypoint; no filesystem, Docker or SQL work before authorization."""
    args = sys.argv[1:] if args is None else args
    if args != ["--execute"]:
        print("Usage: python3 -B -m tools.integration.execution --execute", file=sys.stderr)
        return 64
    if os.environ.get("CB_BASE_INTEGRATION_APPROVAL") != "I_APPROVE_FRESH_BASE_TEST_DATABASE":
        print("BLOCKED: explicit fresh Base test-database approval is missing.", file=sys.stderr)
        return 2
    try:
        prepared_pipeline()
    except preflight.PreflightError as error:
        print(f"Base Level 2: BLOCKED: {error}", file=sys.stderr)
        print("No automatic cleanup; inspect any owned test workspace/database before retrying.", file=sys.stderr)
        return 1
    print("Base Level 2: PASS (isolated WordPress integration and full matrix).")
    print(f"Exact Base HEAD: {preflight.read_source_commit(SOURCE)}")
    print("Release builder requires the matching isolated environment variables.")
    print("Never point tools/build-release at a production or staging database.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
