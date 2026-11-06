#!/usr/bin/env python3
"""Install the exact canonical Base dev artifact in an owned, ephemeral CI WP site.

Only GitHub-hosted CI fixtures with the pinned Base test directory layout are
eligible. This never touches a developer's local WordPress/Docker environment.
The canonical builder remains authoritative for package bytes and manifest.
"""
from __future__ import annotations

import hashlib
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

SOURCE = Path(__file__).resolve().parents[2]
CI_WP_PATH = re.compile(
    r"/tmp/(?:core-blueprint-base-[a-z0-9_]+/wordpress|core-blueprint-release-wp)\Z"
)


def stop(reason: str) -> "NoReturn":
    raise SystemExit("[ci-stage] BLOCKED: " + reason)


def ci_paths(env: dict[str, str]) -> tuple[Path, Path]:
    """Validate exact CI target paths before even looking at the filesystem."""
    if env.get("GITHUB_ACTIONS") != "true" or not env.get("RUNNER_TEMP"):
        stop("GitHub Actions ephemeral runner required.")
    core_name = env.get("WP_CORE_DIR", "")
    if not CI_WP_PATH.fullmatch(core_name):
        stop("Unrecognized ephemeral WordPress root.")
    core = Path(core_name)
    expected_file = core / "wp-content/plugins/core-blueprint/core-blueprint.php"
    if env.get("CB_PLUGIN_FILE") != str(expected_file):
        stop("CB_PLUGIN_FILE does not name the pinned CI plugin.")
    return core, expected_file.parent


def owned_ci_copy(core: Path, plugin_dir: Path) -> None:
    for directory in (
        core, core / "wp-content", core / "wp-content/plugins", plugin_dir
    ):
        if directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.getuid():
            stop("WordPress CI directory is missing, linked or not owned.")
    entry = plugin_dir / "core-blueprint.php"
    if entry.is_symlink() or not entry.is_file() or entry.stat().st_uid != os.getuid():
        stop("Canonical test plugin entrypoint is missing or not owned.")


def canonical_builder():
    path = SOURCE / "tools/build-release"
    loader = importlib.machinery.SourceFileLoader("cb_stage_canonical_builder", str(path))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        stop("Canonical packaging module unavailable.")
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


def verified_archive(source_sha: str):
    canonical = canonical_builder()
    version = canonical.read_version(SOURCE / "core-blueprint.php")
    name = "core-blueprint-" + version + "-dev-" + source_sha[:12] + ".zip"
    archive = SOURCE / "dist/dev" / name
    checksum = SOURCE / "dist/dev" / (name + ".sha256")
    receipt = SOURCE / "dist/dev" / (name + ".dev.json")
    if any(path.is_symlink() or not path.is_file() for path in (archive, checksum, receipt)):
        stop("Expected canonical exact-head ZIP, checksum and receipt are missing.")
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    if checksum.read_text(encoding="utf-8") != digest + "  " + name + "\n":
        stop("Dev package checksum mismatch.")
    metadata = json.loads(receipt.read_text(encoding="utf-8"))
    if (metadata.get("source_sha") != source_sha
            or metadata.get("artifact_sha256") != digest
            or "NOT PUBLIC RELEASE VALIDATED" not in metadata.get("status", "")):
        stop("Dev artifact receipt does not identify this exact source.")
    files = canonical.collect_files(SOURCE)
    payloads = canonical.package_payloads(
        SOURCE, files, canonical.design_module_revision(SOURCE)
    )
    expected = {
        name: hashlib.sha256(data).hexdigest()
        for name, data in payloads.items()
    }
    canonical.verify_archive(archive, expected)
    return canonical, archive, expected


def main() -> int:
    core, plugin_dir = ci_paths(os.environ)
    owned_ci_copy(core, plugin_dir)
    source_sha = subprocess.check_output(
        ["git", "rev-parse", "HEAD"], cwd=SOURCE, text=True
    ).strip()
    if not re.fullmatch(r"[a-f0-9]{40}", source_sha):
        stop("Unable to identify exact CI source commit.")
    canonical, archive, expected = verified_archive(source_sha)
    with tempfile.TemporaryDirectory(
        prefix=".cb-stage-", dir=plugin_dir.parent
    ) as directory:
        staging = Path(directory)
        incoming = staging / "core-blueprint"
        incoming.mkdir()
        with zipfile.ZipFile(archive) as bundle:
            # Verify canonical entries again before writing only allowed paths.
            for relative in sorted(expected):
                target = incoming / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(bundle.read("core-blueprint/" + relative))
        if not canonical.packaged_runtime_matches(SOURCE, incoming):
            stop("Staged artifact does not match canonical package bytes.")
        backup = staging / "original-ci-copy"
        plugin_dir.rename(backup)
        try:
            incoming.rename(plugin_dir)
            if not canonical.packaged_runtime_matches(SOURCE, plugin_dir):
                raise RuntimeError("Installed CI copy differs from canonical artifact.")
        except Exception:
            if plugin_dir.exists():
                plugin_dir.rename(staging / "invalid-ci-copy")
            backup.rename(plugin_dir)
            raise
    print("[ci-stage] exact canonical internal artifact PASS: " + source_sha)
    print("[ci-stage] CI-only fixture; not a customer release.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
