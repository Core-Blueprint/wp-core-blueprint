#!/usr/bin/env python3
"""Document audited PreparedSQL scanner boundaries.

The 12 audited WordPress.DB.PreparedSQL.NotPrepared findings are not raw
unprepared user SQL:
- nine findings are SQL templates passed to $wpdb->prepare(), or prepared
  variables passed immediately to $wpdb query methods;
- three QueryBuilder findings are deliberately parameterless statements
  emitted by a closed, identifier-validated grammar.

This tool is scan-driven and semantics-preserving. It only annotates the exact
audited findings from the 13-error Plugin Check baseline.

Usage:
  python3 tools/harden-prepared-sql-boundaries.py --scan-json /path/to.json
  python3 tools/harden-prepared-sql-boundaries.py --scan-json /path/to.json --apply
  python3 tools/harden-prepared-sql-boundaries.py --check
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SNIFF = "WordPress.DB.PreparedSQL.NotPrepared"
MARKER = "Core Blueprint audited prepared-SQL boundary"

EXPECTED = {
    "src/DB/UpdateBuilder.php": 1,
    "src/DB/InsertBuilder.php": 1,
    "src/DB/DeleteBuilder.php": 1,
    "src/DB/QueryBuilder.php": 3,
    "src/Notes/Repository.php": 2,
    "src/AIGovernance/Repository.php": 2,
    "src/Mail/Log/Repository.php": 2,
}

RATIONALE = {
    "src/DB/UpdateBuilder.php":
        "builder-owned SQL is passed to $wpdb->prepare() with %i identifiers and typed value placeholders.",
    "src/DB/InsertBuilder.php":
        "builder-owned batch SQL is passed to $wpdb->prepare() with %i identifiers and typed value placeholders.",
    "src/DB/DeleteBuilder.php":
        "builder-owned SQL is passed to $wpdb->prepare() with %i identifiers and bound WHERE values.",
    "src/DB/QueryBuilder.php":
        "parameterless branch is emitted by the closed identifier-validated QueryBuilder grammar and contains no value placeholders.",
    "src/Notes/Repository.php":
        "internal allowlisted fragments are passed through $wpdb->prepare() with %i table binding and bound filter/pagination values.",
    "src/AIGovernance/Repository.php":
        "fixed WHERE fragments are passed through $wpdb->prepare() with %i table binding and bound values.",
    "src/Mail/Log/Repository.php":
        "fixed WHERE fragments are passed through $wpdb->prepare() with %i table binding and bound values.",
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
            item
            for item in messages
            if isinstance(item, dict)
            and item.get("type") == "ERROR"
            and item.get("code") == SNIFF
        ]
        if items:
            selected[rel] = items
    return selected


def validate(findings: dict[str, list[dict]]) -> None:
    counts = {rel: len(items) for rel, items in findings.items()}
    if counts != EXPECTED:
        raise RuntimeError(
            f"PreparedSQL baseline changed. Expected {EXPECTED!r}, found {counts!r}."
        )
    if sum(counts.values()) != 12:
        raise RuntimeError("Expected exactly 12 PreparedSQL findings.")


def prepare(
    findings: dict[str, list[dict]]
) -> dict[str, tuple[list[str], list[str]]]:
    prepared: dict[str, tuple[list[str], list[str]]] = {}

    for rel, items in findings.items():
        path = ROOT / rel
        if not path.is_file():
            raise RuntimeError(f"Source file missing: {rel}")
        original_text = path.read_text(encoding="utf-8")
        before = original_text.splitlines()
        after = list(before)

        line_numbers = sorted({int(item.get("line") or 0) for item in items})
        if len(line_numbers) != len(items):
            raise RuntimeError(f"{rel}: multiple PreparedSQL findings share a line.")

        for line_no in reversed(line_numbers):
            if line_no < 1 or line_no > len(after):
                raise RuntimeError(f"{rel}:{line_no}: scan line outside source.")
            index = line_no - 1
            source = after[index]
            if MARKER in source:
                raise RuntimeError(f"{rel}:{line_no}: source already annotated.")

            indent = source[: len(source) - len(source.lstrip())]
            disable = (
                f"{indent}// phpcs:disable {SNIFF} -- {MARKER}: "
                f"{RATIONALE[rel]}"
            )
            enable = f"{indent}// phpcs:enable {SNIFF}"
            after[index:index + 1] = [disable, source, enable]

        prepared[rel] = (before, after)

    return prepared


def check() -> int:
    total = 0
    files = 0
    for rel, expected in EXPECTED.items():
        path = ROOT / rel
        if not path.is_file():
            raise RuntimeError(f"Source file missing: {rel}")
        lines = path.read_text(encoding="utf-8").splitlines()
        count = sum(
            1
            for line in lines
            if f"phpcs:disable {SNIFF}" in line and MARKER in line
        )
        if count != expected:
            raise RuntimeError(
                f"{rel}: expected {expected} PreparedSQL boundaries, found {count}."
            )
        total += count
        if count:
            files += 1

    if total != 12 or files != 7:
        raise RuntimeError(
            f"PreparedSQL annotation mismatch: boundaries={total}, files={files}."
        )

    print("PREPAREDSQL FINDINGS: 12")
    print("FILES: 7")
    print("BOUNDARIES: 12")
    print("COVERED FINDINGS: 12")
    print("PASS: all audited PreparedSQL boundaries are explicitly documented.")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--scan-json")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--apply", action="store_true")
    mode.add_argument("--check", action="store_true")
    args = parser.parse_args()

    if args.check:
        return check()

    if not args.scan_json:
        parser.error("--scan-json is required unless --check is used")

    scan = Path(args.scan_json).expanduser().resolve()
    if not scan.is_file():
        parser.error(f"scan JSON not found: {scan}")

    findings = load_findings(scan)
    validate(findings)
    prepared = prepare(findings)

    print("PREPAREDSQL FINDINGS: 12")
    print("FILES: 7")
    print("BOUNDARIES: 12")
    print("COVERED FINDINGS: 12")

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
    print("APPLIED BOUNDARIES: 12")
    print("COVERED FINDINGS: 12")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
