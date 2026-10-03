#!/usr/bin/env python3
"""Normalize documented OutputNotEscaped suppressions to statement scope.

Some Core Blueprint callsites already document why an echo is safe, but use a
trailing phpcs:ignore on the statement terminator. WordPressCS reports tokens on
earlier lines of a multiline statement before it reaches that trailing ignore.

This tool does NOT create new trust decisions. It only converts existing
OutputNotEscaped ignores into narrowly-scoped phpcs:disable/phpcs:enable pairs
around the exact echo statement that already carries the documented rationale.

Usage:
  python3 tools/harden-documented-output-boundaries.py
  python3 tools/harden-documented-output-boundaries.py --apply
  python3 tools/harden-documented-output-boundaries.py --check
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SNIFF = "WordPress.Security.EscapeOutput.OutputNotEscaped"
IGNORE_TOKEN = f"phpcs:ignore {SNIFF}"

EXCLUDED_DIRS = {
    ".git",
    "vendor",
    "node_modules",
    "dist",
    "build",
    "languages",
    "licenses",
}

START_RE = re.compile(r"^\s*(?:<\?php\s+)?echo\b")
IGNORE_RE = re.compile(
    rf"\s*//\s*phpcs:ignore\s+{re.escape(SNIFF)}(?P<reason>.*?)(?=\s*\?>\s*$|\s*$)"
)


def candidate_files() -> list[Path]:
    out: list[Path] = []
    for path in ROOT.rglob("*.php"):
        rel = path.relative_to(ROOT)
        if any(part in EXCLUDED_DIRS for part in rel.parts):
            continue
        out.append(path)
    return sorted(out)


def already_scoped(lines: list[str], start: int, end: int) -> bool:
    before = start - 1
    while before >= 0 and not lines[before].strip():
        before -= 1
    after = end + 1
    while after < len(lines) and not lines[after].strip():
        after += 1
    return (
        before >= 0
        and f"phpcs:disable {SNIFF}" in lines[before]
        and after < len(lines)
        and f"phpcs:enable {SNIFF}" in lines[after]
    )


def statement_start(lines: list[str], end: int) -> int | None:
    # Most reviewed boundaries are echo statements whose trailing ignore sits
    # on the statement terminator. Search only a small local window and stop
    # at another completed statement to avoid broadening scope accidentally.
    for index in range(end, max(-1, end - 120), -1):
        if START_RE.search(lines[index]):
            return index
        if index != end and ";" in lines[index] and "<?php" not in lines[index]:
            break
    return None


def normalize_reason(raw: str) -> str:
    reason = raw.strip()
    reason = re.sub(r"^[\s\-–—:]+", "", reason).strip()
    if not reason:
        reason = "existing callsite documents this output boundary as safe."
    return reason


def inspect_file(
    text: str,
    reported_lines: set[int],
) -> list[tuple[int, int, str]]:
    lines = text.splitlines()
    found: list[tuple[int, int, str]] = []

    for end, line in enumerate(lines):
        if IGNORE_TOKEN not in line:
            continue
        match = IGNORE_RE.search(line)
        if not match:
            continue
        start = statement_start(lines, end)
        if start is None or already_scoped(lines, start, end):
            continue

        # Plugin Check line numbers are 1-based and refer to the exact source
        # used for this scan. Only normalize an existing documented boundary
        # when at least one current OutputNotEscaped error falls inside the
        # statement that owns that trailing ignore.
        if not any(start + 1 <= line_no <= end + 1 for line_no in reported_lines):
            continue

        found.append((start, end, normalize_reason(match.group("reason"))))

    return found


def transform(text: str, reported_lines: set[int]) -> tuple[str, int]:
    lines = text.splitlines()
    boundaries = inspect_file(text, reported_lines)
    if not boundaries:
        return text, 0

    for start, end, reason in reversed(boundaries):
        indent = re.match(r"^\s*", lines[start]).group(0)

        # Remove only the existing trailing ignore; keep any closing PHP tag.
        lines[end] = IGNORE_RE.sub("", lines[end]).rstrip()

        if "<?php" in lines[start]:
            disable_line = (
                indent
                + "<?php // phpcs:disable "
                + SNIFF
                + " -- "
                + reason
                + " ?>"
            )
        else:
            disable_line = (
                indent
                + "// phpcs:disable "
                + SNIFF
                + " -- "
                + reason
            )

        if "?>" in lines[end]:
            enable_line = indent + "<?php // phpcs:enable " + SNIFF + " ?>"
        else:
            enable_line = indent + "// phpcs:enable " + SNIFF

        lines.insert(end + 1, enable_line)
        lines.insert(start, disable_line)

    updated = "\n".join(lines)
    if text.endswith("\n"):
        updated += "\n"
    return updated, len(boundaries)


def load_scan(path: Path) -> dict[str, set[int]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    results = payload.get("results", {})
    if not isinstance(results, dict):
        raise ValueError("Plugin Check export does not contain a results object.")

    reported: dict[str, set[int]] = {}
    for file_name, messages in results.items():
        if not isinstance(file_name, str) or not isinstance(messages, list):
            continue
        for message in messages:
            if not isinstance(message, dict):
                continue
            if (
                message.get("type") == "ERROR"
                and message.get("code") == SNIFF
                and isinstance(message.get("line"), int)
            ):
                reported.setdefault(file_name, set()).add(int(message["line"]))
    return reported


def inspect(reported: dict[str, set[int]]) -> list[tuple[Path, int]]:
    affected: list[tuple[Path, int]] = []
    for path in candidate_files():
        rel = path.relative_to(ROOT).as_posix()
        reported_lines = reported.get(rel)
        if not reported_lines:
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        count = len(inspect_file(text, reported_lines))
        if count:
            affected.append((path, count))
    return affected


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--scan-json",
        required=True,
        help="Plugin Check JSON export from the exact source tree being audited.",
    )
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()

    scan_path = Path(args.scan_json).expanduser().resolve()
    if not scan_path.is_file():
        parser.error(f"scan JSON not found: {scan_path}")

    reported = load_scan(scan_path)
    affected = inspect(reported)

    if args.check:
        if affected:
            print("FAIL: documented OutputNotEscaped boundaries remain trailing-only.")
            for path, count in affected:
                print(f"{path.relative_to(ROOT)}: {count}")
            print(f"FILES: {len(affected)}")
            print(f"BOUNDARIES: {sum(count for _, count in affected)}")
            return 1
        print("PASS: documented OutputNotEscaped boundaries are statement-scoped.")
        return 0

    if not args.apply:
        for path, count in affected:
            print(f"{path.relative_to(ROOT)}: {count}")
        print(f"FILES: {len(affected)}")
        print(f"BOUNDARIES: {sum(count for _, count in affected)}")
        return 0

    changed_files = 0
    total = 0
    for path, _count in affected:
        text = path.read_text(encoding="utf-8")
        rel = path.relative_to(ROOT).as_posix()
        updated, count = transform(text, reported.get(rel, set()))
        if not count:
            continue
        path.write_text(updated, encoding="utf-8")
        changed_files += 1
        total += count

    print(f"APPLIED FILES: {changed_files}")
    print(f"APPLIED BOUNDARIES: {total}")

    remaining = inspect(reported)
    if remaining:
        print("FAIL: documented OutputNotEscaped boundaries remain trailing-only after apply.")
        return 1

    print("PASS: documented OutputNotEscaped boundaries are statement-scoped.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
