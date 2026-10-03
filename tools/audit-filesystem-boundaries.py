#!/usr/bin/env python3
"""Audit Plugin Check filesystem alternative-function findings.

Read-only classifier for the audited Base filesystem phase. It validates the
current 101-finding Plugin Check baseline and groups each finding by the
runtime semantics of the owning subsystem. No source files are modified.

Usage:
  python3 tools/audit-filesystem-boundaries.py \
    --scan-json /path/to/plugin-check.json
"""

from __future__ import annotations

import argparse
import collections
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PREFIX = "WordPress.WP.AlternativeFunctions"

FILE_CLASSES = {
    "src/DataExchange/Engine.php": "memory_csv_stream",
    "src/DataExchange/Mapper.php": "memory_csv_stream",
    "src/Ajax/Handlers/Exports.php": "response_stream",
    "src/Log/LogExporter.php": "response_stream",
    "src/AIGovernance/Admin/Actions.php": "response_stream",
    "src/PackageDownload/ArchiveService.php": "binary_response_stream",
    "src/Integrity/Support/FileHashProbe.php": "stable_hash_stream",
    "src/Integrity/Quarantine/Vault.php": "quarantine_atomic_filesystem",
    "src/Integrity/Quarantine/Service.php": "quarantine_metadata_restore",
    "src/MediaReplace/ReplaceService.php": "media_atomic_replace",
    "src/Snippets/AtomicFile.php": "snippet_atomic_write",
    "src/Snippets/Lock.php": "snippet_file_lock",
    "src/PDF/Renderer.php": "private_pdf_temp_cache",
}

EXPECTED_BY_FILE = {
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


def load(path: Path) -> list[dict]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    results = payload.get("results", {})
    findings: list[dict] = []
    if not isinstance(results, dict):
        raise RuntimeError("Plugin Check export has no results object.")

    for rel, messages in results.items():
        if not isinstance(messages, list):
            continue
        for message in messages:
            if (
                isinstance(message, dict)
                and message.get("type") == "ERROR"
                and str(message.get("code") or "").startswith(PREFIX)
            ):
                findings.append(
                    {
                        "file": rel,
                        "line": int(message.get("line") or 0),
                        "column": int(message.get("column") or 0),
                        "code": str(message.get("code") or ""),
                    }
                )
    return findings


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--scan-json", required=True)
    args = parser.parse_args()

    scan = Path(args.scan_json).expanduser().resolve()
    if not scan.is_file():
        parser.error(f"scan JSON not found: {scan}")

    findings = load(scan)
    if len(findings) != 101:
        raise RuntimeError(
            f"Expected audited 101 filesystem findings, found {len(findings)}."
        )

    file_counts = collections.Counter(item["file"] for item in findings)
    if dict(file_counts) != EXPECTED_BY_FILE:
        raise RuntimeError(
            "Filesystem file distribution changed. "
            f"Expected {EXPECTED_BY_FILE!r}, found {dict(file_counts)!r}."
        )

    categories: collections.Counter[str] = collections.Counter()
    operations: collections.Counter[str] = collections.Counter()
    imports: list[tuple[str, int, str]] = []
    callsites: list[tuple[str, int, str, str]] = []
    unique_lines: set[tuple[str, int]] = set()

    for item in findings:
        rel = item["file"]
        if rel not in FILE_CLASSES:
            raise RuntimeError(f"Unclassified filesystem finding file: {rel}")

        path = ROOT / rel
        if not path.is_file():
            raise RuntimeError(f"Source file missing: {rel}")
        lines = path.read_text(encoding="utf-8").splitlines()
        line_no = item["line"]
        if line_no < 1 or line_no > len(lines):
            raise RuntimeError(f"{rel}:{line_no}: scan line outside current source.")

        source = lines[line_no - 1].strip()
        category = FILE_CLASSES[rel]
        categories[category] += 1
        operations[item["code"].rsplit("_", 1)[-1]] += 1
        unique_lines.add((rel, line_no))

        if source.startswith("use function "):
            imports.append((rel, line_no, source))
        else:
            callsites.append((rel, line_no, item["code"], source))

    print(f"FILESYSTEM FINDINGS: {len(findings)}")
    print(f"FILES: {len(file_counts)}")
    print(f"UNIQUE SOURCE LINES: {len(unique_lines)}")
    print(f"FUNCTION-IMPORT FINDINGS: {len(imports)}")
    print(f"RUNTIME CALL FINDINGS: {len(callsites)}")

    print("CATEGORIES:")
    for category, count in categories.most_common():
        print(f"  {category}: {count}")

    print("OPERATIONS:")
    for operation, count in operations.most_common():
        print(f"  {operation}: {count}")

    print("FUNCTION IMPORTS:")
    for rel, line_no, source in imports:
        print(f"  {rel}:{line_no}: {source}")

    print("FILES:")
    for rel, count in file_counts.most_common():
        print(f"  {rel}: {count}")

    print("DRY AUDIT: no files changed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
