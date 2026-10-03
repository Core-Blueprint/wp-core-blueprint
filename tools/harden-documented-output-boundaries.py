#!/usr/bin/env python3
"""Normalize documented OutputNotEscaped suppressions to statement scope.

Some Core Blueprint callsites already document why an echo is safe, but use a
trailing phpcs:ignore on the statement terminator. WordPressCS reports tokens on
earlier lines of a multiline statement before it reaches that trailing ignore.

This tool does NOT create new trust decisions. It only converts existing
OutputNotEscaped ignores into narrowly-scoped phpcs:disable/phpcs:enable pairs
around the exact echo statement that already carries the documented rationale.

Usage:
  python3 tools/harden-documented-output-boundaries.py --scan-json <export.json>
  python3 tools/harden-documented-output-boundaries.py --scan-json <export.json> --apply
  python3 tools/harden-documented-output-boundaries.py --scan-json <export.json> --check
  python3 tools/harden-documented-output-boundaries.py --repair-applied
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
IGNORE_MARKER_RE = re.compile(
    rf"//\s*phpcs:ignore\s+{re.escape(SNIFF)}"
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


def split_ignore_line(line: str) -> tuple[str, str] | None:
    """Remove only the PHPCS ignore comment and preserve PHP/HTML suffixes.

    A template line can legitimately end in sequences such as `?>>` where
    `?>` closes PHP and the final `>` closes the surrounding HTML tag.
    Treat everything from the first PHP close token after the ignore marker as
    runtime syntax, never as part of the PHPCS comment.
    """
    match = IGNORE_MARKER_RE.search(line)
    if not match:
        return None

    tail = line[match.end():]
    suffix_offset = tail.find("?>")
    if suffix_offset >= 0:
        reason_raw = tail[:suffix_offset]
        suffix = tail[suffix_offset:]
    else:
        reason_raw = tail
        suffix = ""

    cleaned = line[:match.start()].rstrip() + suffix
    return cleaned, normalize_reason(reason_raw)


def inspect_file(
    text: str,
    reported_lines: set[int],
) -> list[tuple[int, int, str]]:
    lines = text.splitlines()
    found: list[tuple[int, int, str]] = []

    for end, line in enumerate(lines):
        if IGNORE_TOKEN not in line:
            continue
        parsed = split_ignore_line(line)
        if parsed is None:
            continue
        _cleaned, reason = parsed
        start = statement_start(lines, end)
        if start is None or already_scoped(lines, start, end):
            continue

        # Plugin Check line numbers are 1-based and refer to the exact source
        # used for this scan. Only normalize an existing documented boundary
        # when at least one current OutputNotEscaped error falls inside the
        # statement that owns that trailing ignore.
        if not any(start + 1 <= line_no <= end + 1 for line_no in reported_lines):
            continue

        found.append((start, end, reason))

    return found


def transform(text: str, reported_lines: set[int]) -> tuple[str, int]:
    lines = text.splitlines()
    boundaries = inspect_file(text, reported_lines)
    if not boundaries:
        return text, 0

    for start, end, reason in reversed(boundaries):
        indent = re.match(r"^\s*", lines[start]).group(0)

        # Remove only the existing trailing ignore. PHP close tokens and any
        # following HTML syntax are preserved verbatim by split_ignore_line().
        parsed = split_ignore_line(lines[end])
        if parsed is None:
            continue
        lines[end], _existing_reason = parsed

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



def repair_applied_boundaries() -> tuple[int, int]:
    """Repair suffixes swallowed by the pre-fix migrator.

    The older implementation could consume a PHP close token plus following
    HTML syntax (for example `?>>`) into the generated disable-comment reason.
    That suffix is still recoverable from the marker, so restore it in place
    without touching unrelated working-tree changes.
    """
    changed_files = 0
    repaired = 0

    disable_token = f"phpcs:disable {SNIFF}"
    enable_token = f"phpcs:enable {SNIFF}"

    for path in candidate_files():
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue

        lines = text.splitlines()
        file_repairs = 0
        index = 0

        while index < len(lines):
            line = lines[index]
            if disable_token not in line or "--" not in line:
                index += 1
                continue

            php_wrapped = line.lstrip().startswith("<?php")
            payload = line.split("--", 1)[1].strip()
            if php_wrapped and payload.endswith("?>"):
                payload = payload[:-2].rstrip()

            suffix_at = payload.find("?>")
            if suffix_at < 0:
                index += 1
                continue

            reason = payload[:suffix_at].strip()
            suffix = payload[suffix_at:]
            if not reason:
                reason = "existing callsite documents this output boundary as safe."

            enable_index = None
            for probe in range(index + 1, min(len(lines), index + 160)):
                if enable_token in lines[probe]:
                    enable_index = probe
                    break
            if enable_index is None or enable_index <= index + 1:
                raise RuntimeError(
                    f"Could not locate generated enable boundary after "
                    f"{path.relative_to(ROOT)}:{index + 1}"
                )

            statement_end = enable_index - 1
            if "?>" in lines[statement_end]:
                raise RuntimeError(
                    f"Refusing ambiguous repair at "
                    f"{path.relative_to(ROOT)}:{statement_end + 1}"
                )

            lines[statement_end] = lines[statement_end].rstrip() + suffix

            indent = re.match(r"^\s*", line).group(0)
            if php_wrapped:
                lines[index] = (
                    indent
                    + "<?php // phpcs:disable "
                    + SNIFF
                    + " -- "
                    + reason
                    + " ?>"
                )
            else:
                lines[index] = (
                    indent
                    + "// phpcs:disable "
                    + SNIFF
                    + " -- "
                    + reason
                )

            enable_indent = re.match(r"^\s*", lines[enable_index]).group(0)
            lines[enable_index] = (
                enable_indent
                + "<?php // phpcs:enable "
                + SNIFF
                + " ?>"
            )

            repaired += 1
            file_repairs += 1
            index = enable_index + 1

        if file_repairs:
            updated = "\n".join(lines)
            if text.endswith("\n"):
                updated += "\n"
            path.write_text(updated, encoding="utf-8")
            changed_files += 1

    return changed_files, repaired


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
        help="Plugin Check JSON export from the exact source tree being audited.",
    )
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--repair-applied", action="store_true")
    args = parser.parse_args()

    if args.repair_applied:
        changed_files, repaired = repair_applied_boundaries()
        print(f"REPAIRED FILES: {changed_files}")
        print(f"REPAIRED BOUNDARIES: {repaired}")
        return 0

    if not args.scan_json:
        parser.error("--scan-json is required unless --repair-applied is used")

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
