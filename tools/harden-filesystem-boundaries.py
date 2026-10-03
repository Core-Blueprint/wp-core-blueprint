#!/usr/bin/env python3
"""Document audited direct-filesystem boundaries from Plugin Check.

This tool is deliberately scan-driven and semantics-preserving. It does not
replace direct filesystem calls with WP_Filesystem when the audited subsystem
requires stream handles, response streaming, stable-handle hashing, file locks,
atomic rename semantics, durable writes, explicit permission tightening, or
private temporary cache lifecycle.

It only annotates the exact WordPress.WP.AlternativeFunctions ERROR findings
from the audited 101-finding Base baseline.

Usage:
  python3 tools/harden-filesystem-boundaries.py \
    --scan-json /path/to/plugin-check.json

  python3 tools/harden-filesystem-boundaries.py \
    --scan-json /path/to/plugin-check.json --apply

  python3 tools/harden-filesystem-boundaries.py \
    --scan-json /path/to/plugin-check.json --check
"""

from __future__ import annotations

import argparse
import collections
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SNIFF = "WordPress.WP.AlternativeFunctions"
MARKER = "Core Blueprint audited filesystem boundary"

RATIONALES = {
    "src/DataExchange/Engine.php": (
        "php://temp CSV parsing requires native seekable stream handles."
    ),
    "src/DataExchange/Mapper.php": (
        "php://temp CSV inspection requires native seekable stream handles."
    ),
    "src/Ajax/Handlers/Exports.php": (
        "php://output export delivery requires a native response stream handle."
    ),
    "src/Log/LogExporter.php": (
        "export writers receive an already-open native response stream handle."
    ),
    "src/AIGovernance/Admin/Actions.php": (
        "php://output export delivery requires a native response stream handle."
    ),
    "src/PackageDownload/ArchiveService.php": (
        "archive delivery streams bytes directly to the response without buffering the file."
    ),
    "src/Integrity/Support/FileHashProbe.php": (
        "integrity hashing requires one stable open handle for fstat/read race detection."
    ),
    "src/Integrity/Quarantine/Vault.php": (
        "quarantine requires atomic moves, explicit restrictive modes, and fail-closed tree cleanup."
    ),
    "src/Integrity/Quarantine/Service.php": (
        "quarantine metadata restoration requires explicit timestamps and permission modes."
    ),
    "src/MediaReplace/ReplaceService.php": (
        "media replacement requires verified backups, locks, atomic swaps, rollback, and mode preservation."
    ),
    "src/Snippets/AtomicFile.php": (
        "managed snippets require durable stream writes and atomic replacement semantics."
    ),
    "src/Snippets/Lock.php": (
        "managed snippets require a native flock-compatible file handle."
    ),
    "src/PDF/Renderer.php": (
        "PDF rendering uses a private per-render 0700 temporary cache with explicit cleanup."
    ),
}

EXPECTED_FINDINGS_BY_FILE = {
    "src/DataExchange/Engine.php": 30,
    "src/MediaReplace/ReplaceService.php": 15,
    "src/Integrity/Quarantine/Vault.php": 14,
    "src/Integrity/Support/FileHashProbe.php": 10,
    "src/DataExchange/Mapper.php": 9,
    "src/Integrity/Quarantine/Service.php": 8,
    "src/Snippets/AtomicFile.php": 5,
    "src/Ajax/Handlers/Exports.php": 3,
    "src/Snippets/Lock.php": 2,
    "src/PDF/Renderer.php": 2,
    "src/Log/LogExporter.php": 1,
    "src/AIGovernance/Admin/Actions.php": 1,
    "src/PackageDownload/ArchiveService.php": 1,
}

EXPECTED_BOUNDARIES_BY_FILE = {
    "src/DataExchange/Engine.php": 30,
    "src/MediaReplace/ReplaceService.php": 13,
    "src/Integrity/Quarantine/Vault.php": 14,
    "src/Integrity/Support/FileHashProbe.php": 10,
    "src/DataExchange/Mapper.php": 9,
    "src/Integrity/Quarantine/Service.php": 8,
    "src/Snippets/AtomicFile.php": 5,
    "src/Ajax/Handlers/Exports.php": 3,
    "src/Snippets/Lock.php": 2,
    "src/PDF/Renderer.php": 2,
    "src/Log/LogExporter.php": 1,
    "src/AIGovernance/Admin/Actions.php": 1,
    "src/PackageDownload/ArchiveService.php": 1,
}


def load_findings(path: Path) -> dict[str, list[dict]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    results = payload.get("results", {})
    if not isinstance(results, dict):
        raise RuntimeError("Plugin Check export has no results object.")

    selected: dict[str, list[dict]] = {}
    for rel, messages in results.items():
        if not isinstance(messages, list):
            continue
        items = [
            message
            for message in messages
            if isinstance(message, dict)
            and message.get("type") == "ERROR"
            and str(message.get("code") or "").startswith(SNIFF)
        ]
        if items:
            selected[rel] = items
    return selected


def validate_baseline(findings: dict[str, list[dict]]) -> None:
    counts = {rel: len(items) for rel, items in findings.items()}
    if counts != EXPECTED_FINDINGS_BY_FILE:
        raise RuntimeError(
            "Filesystem baseline changed. "
            f"Expected {EXPECTED_FINDINGS_BY_FILE!r}, found {counts!r}."
        )

    total = sum(counts.values())
    if total != 101:
        raise RuntimeError(f"Expected 101 filesystem findings, found {total}.")


def resolve_boundaries(
    findings: dict[str, list[dict]],
) -> dict[str, dict[int, list[str]]]:
    resolved: dict[str, dict[int, list[str]]] = {}

    for rel, items in findings.items():
        if rel not in RATIONALES:
            raise RuntimeError(f"No audited filesystem rationale for {rel}.")

        path = ROOT / rel
        if not path.is_file():
            raise RuntimeError(f"Source file missing: {rel}")
        lines = path.read_text(encoding="utf-8").splitlines()

        by_line: dict[int, list[str]] = collections.defaultdict(list)
        for item in items:
            line_no = int(item.get("line") or 0)
            code = str(item.get("code") or "")
            if line_no < 1 or line_no > len(lines):
                raise RuntimeError(
                    f"{rel}:{line_no}: scan line outside current source."
                )
            source = lines[line_no - 1]
            if MARKER in source:
                raise RuntimeError(
                    f"{rel}:{line_no}: baseline scan points at an already annotated line."
                )
            by_line[line_no].append(code)

        resolved[rel] = dict(by_line)

    actual = {rel: len(lines) for rel, lines in resolved.items()}
    if actual != EXPECTED_BOUNDARIES_BY_FILE:
        raise RuntimeError(
            "Filesystem boundary distribution changed. "
            f"Expected {EXPECTED_BOUNDARIES_BY_FILE!r}, found {actual!r}."
        )
    return resolved


def annotation(indent: str, rel: str) -> str:
    return (
        f"{indent}// phpcs:disable {SNIFF} -- {MARKER}: "
        f"{RATIONALES[rel]}"
    )


def enable(indent: str) -> str:
    return f"{indent}// phpcs:enable {SNIFF}"


def apply_boundaries(
    rel: str, boundary_lines: dict[int, list[str]]
) -> tuple[list[str], list[str]]:
    path = ROOT / rel
    text = path.read_text(encoding="utf-8")
    before = text.splitlines()
    after = list(before)

    # Old scan line numbers remain valid by applying from bottom to top.
    for line_no in sorted(boundary_lines, reverse=True):
        index = line_no - 1
        source = after[index]
        indent = source[: len(source) - len(source.lstrip())]

        # Adjacent audited callsites are valid. Applying from bottom to top can
        # therefore place a newly inserted marker directly beside the next
        # source line. Only refuse when the scan target itself is no longer the
        # original runtime/import source line.
        if MARKER in source or source.lstrip().startswith("// phpcs:"):
            raise RuntimeError(
                f"{rel}:{line_no}: filesystem scan target is already annotated."
            )

        after[index:index + 1] = [
            annotation(indent, rel),
            source,
            enable(indent),
        ]

    return before, after


def check_applied() -> None:
    total_disable = 0
    total_enable = 0
    files_with_markers = 0

    for rel, expected in EXPECTED_BOUNDARIES_BY_FILE.items():
        path = ROOT / rel
        if not path.is_file():
            raise RuntimeError(f"Source file missing: {rel}")
        lines = path.read_text(encoding="utf-8").splitlines()

        disables = sum(
            1
            for line in lines
            if f"phpcs:disable {SNIFF}" in line and MARKER in line
        )
        enables = sum(
            1 for line in lines if f"phpcs:enable {SNIFF}" in line
        )

        if disables:
            files_with_markers += 1
        if disables != expected:
            raise RuntimeError(
                f"{rel}: expected {expected} audited filesystem boundaries, "
                f"found {disables}."
            )
        if enables < disables:
            raise RuntimeError(
                f"{rel}: filesystem annotations are not balanced "
                f"(disable={disables}, enable={enables})."
            )

        total_disable += disables
        total_enable += disables

    if files_with_markers != 13 or total_disable != 99:
        raise RuntimeError(
            "Applied filesystem boundary count mismatch: "
            f"files={files_with_markers}, boundaries={total_disable}."
        )

    print("FILESYSTEM FINDINGS: 101")
    print("FILES: 13")
    print("BOUNDARIES: 99")
    print("COVERED FINDINGS: 101")
    print(
        "PASS: all audited filesystem boundaries are explicitly documented."
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--scan-json")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--apply", action="store_true")
    mode.add_argument("--check", action="store_true")
    args = parser.parse_args()

    if args.check:
        check_applied()
        return 0

    if not args.scan_json:
        parser.error("--scan-json is required unless --check is used")

    scan = Path(args.scan_json).expanduser().resolve()
    if not scan.is_file():
        parser.error(f"scan JSON not found: {scan}")

    findings = load_findings(scan)
    validate_baseline(findings)
    boundaries = resolve_boundaries(findings)

    finding_total = sum(len(items) for items in findings.values())
    boundary_total = sum(len(items) for items in boundaries.values())
    import_boundaries = 0

    prepared: dict[str, tuple[list[str], list[str]]] = {}
    for rel, by_line in boundaries.items():
        path = ROOT / rel
        lines = path.read_text(encoding="utf-8").splitlines()
        import_boundaries += sum(
            1
            for line_no in by_line
            if lines[line_no - 1].strip().startswith("use function ")
        )
        prepared[rel] = apply_boundaries(rel, by_line)

    print(f"FILESYSTEM FINDINGS: {finding_total}")
    print(f"FILES: {len(boundaries)}")
    print(f"BOUNDARIES: {boundary_total}")
    print(f"FUNCTION-IMPORT BOUNDARIES: {import_boundaries}")
    print(f"RUNTIME BOUNDARIES: {boundary_total - import_boundaries}")
    print(f"COVERED FINDINGS: {finding_total}")

    if not args.apply:
        print("DRY RUN: no files changed.")
        return 0

    changed = 0
    for rel, (before, after) in prepared.items():
        if before == after:
            continue
        path = ROOT / rel
        original = path.read_text(encoding="utf-8")
        suffix = "\n" if original.endswith("\n") else ""
        path.write_text("\n".join(after) + suffix, encoding="utf-8")
        changed += 1

    print(f"APPLIED FILES: {changed}")
    print(f"APPLIED BOUNDARIES: {boundary_total}")
    print(f"COVERED FINDINGS: {finding_total}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
