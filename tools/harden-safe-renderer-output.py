#!/usr/bin/env python3
"""Annotate proven-safe Core Blueprint UI renderer echo boundaries for WPCS.

This tool does not add escaping. It documents the trust boundary where a
renderer has already escaped its complete public payload. Slot-based renderers
such as Field and Card are intentionally excluded because they accept caller-
provided HTML and therefore require per-callsite auditing.

Usage:
  python3 tools/harden-safe-renderer-output.py
  python3 tools/harden-safe-renderer-output.py --apply
  python3 tools/harden-safe-renderer-output.py --check
"""

from __future__ import annotations

import argparse
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

SAFE_RENDERERS = (
    "Notice",
    "Icon",
    "StateBadge",
    "ChoiceGroup",
    "RadioCard",
    "RadioGroup",
    "Status",
    "FormStatus",
    "ObjectPicker",
)

EXCLUDED_DIRS = {
    ".git",
    "vendor",
    "node_modules",
    "dist",
    "build",
    "languages",
    "licenses",
}

SNIFF = "WordPress.Security.EscapeOutput.OutputNotEscaped"
DISABLE = f"// phpcs:disable {SNIFF} -- Core Blueprint UI renderer owns context-specific escaping for its complete public payload."
ENABLE = f"// phpcs:enable {SNIFF}"

START_RE = re.compile(
    r"^(?P<indent>\s*)echo\s+"
    r"(?:(?:\\CoreBlueprint\\Core\\UI\\)?"
    r"(?P<renderer>" + "|".join(SAFE_RENDERERS) + r"))"
    r"::render\s*\("
)

INLINE_IGNORE_RE = re.compile(
    r"\s*//\s*phpcs:ignore\s+WordPress\.Security\.EscapeOutput\.OutputNotEscaped\b.*$"
)


def candidate_files() -> list[Path]:
    out: list[Path] = []
    for path in ROOT.rglob("*.php"):
        relative = path.relative_to(ROOT)
        if any(part in EXCLUDED_DIRS for part in relative.parts):
            continue
        out.append(path)
    return sorted(out)


def short_renderer_is_ui_owned(text: str, renderer: str, matched_line: str) -> bool:
    if "\\CoreBlueprint\\Core\\UI\\" in matched_line:
        return True
    if re.search(
        rf"^\s*use\s+CoreBlueprint\\Core\\UI\\{re.escape(renderer)}\s*;",
        text,
        flags=re.MULTILINE,
    ):
        return True
    if re.search(
        r"^\s*namespace\s+CoreBlueprint\\Core\\UI\s*;",
        text,
        flags=re.MULTILINE,
    ):
        return True
    return False


def statement_end(lines: list[str], start: int) -> int | None:
    """Return 0-based line containing the echo statement terminator."""
    in_single = False
    in_double = False
    in_block_comment = False
    escaped = False

    for line_index in range(start, min(len(lines), start + 160)):
        line = lines[line_index]
        i = 0
        while i < len(line):
            ch = line[i]
            nxt = line[i + 1] if i + 1 < len(line) else ""

            if in_block_comment:
                if ch == "*" and nxt == "/":
                    in_block_comment = False
                    i += 2
                    continue
                i += 1
                continue

            if in_single:
                if escaped:
                    escaped = False
                elif ch == "\\":
                    escaped = True
                elif ch == "'":
                    in_single = False
                i += 1
                continue

            if in_double:
                if escaped:
                    escaped = False
                elif ch == "\\":
                    escaped = True
                elif ch == '"':
                    in_double = False
                i += 1
                continue

            if ch == "/" and nxt == "*":
                in_block_comment = True
                i += 2
                continue
            if ch == "/" and nxt == "/":
                break
            if ch == "#":
                break
            if ch == "'":
                in_single = True
                i += 1
                continue
            if ch == '"':
                in_double = True
                i += 1
                continue
            if ch == ";":
                return line_index
            i += 1

    return None


def bounded(lines: list[str], start: int, end: int) -> bool:
    before = start - 1
    while before >= 0 and not lines[before].strip():
        before -= 1
    after = end + 1
    while after < len(lines) and not lines[after].strip():
        after += 1
    return (
        before >= 0
        and lines[before].strip().startswith(f"// phpcs:disable {SNIFF}")
        and after < len(lines)
        and lines[after].strip() == ENABLE
    )


def eligible_statements(text: str) -> list[tuple[int, int, str, str]]:
    lines = text.splitlines()
    out: list[tuple[int, int, str, str]] = []

    for index, line in enumerate(lines):
        match = START_RE.match(line)
        if not match:
            continue

        renderer = match.group("renderer")
        if not short_renderer_is_ui_owned(text, renderer, line):
            continue

        end = statement_end(lines, index)
        if end is None:
            raise RuntimeError(
                f"Could not find statement terminator for {renderer}::render() "
                f"starting at line {index + 1}"
            )

        if bounded(lines, index, end):
            continue

        out.append((index, end, renderer, match.group("indent")))

    return out


def transform(text: str) -> tuple[str, int]:
    lines = text.splitlines()
    statements = eligible_statements(text)
    if not statements:
        return text, 0

    # Work bottom-up so line indexes remain stable.
    for start, end, _renderer, indent in reversed(statements):
        for i in range(start, end + 1):
            lines[i] = INLINE_IGNORE_RE.sub("", lines[i])

        lines.insert(end + 1, indent + ENABLE)
        lines.insert(start, indent + DISABLE)

    updated = "\n".join(lines)
    if text.endswith("\n"):
        updated += "\n"
    return updated, len(statements)


def inspect() -> list[tuple[Path, int]]:
    affected: list[tuple[Path, int]] = []
    for path in candidate_files():
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        count = len(eligible_statements(text))
        if count:
            affected.append((path, count))
    return affected


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()

    affected = inspect()

    if args.check:
        if affected:
            print("FAIL: proven-safe renderer echo boundaries remain unannotated.")
            for path, count in affected:
                print(f"{path.relative_to(ROOT)}: {count}")
            print(f"FILES: {len(affected)}")
            print(f"BOUNDARIES: {sum(count for _, count in affected)}")
            return 1
        print("PASS: all proven-safe renderer echo boundaries are explicitly annotated.")
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
        updated, count = transform(text)
        if not count:
            continue
        path.write_text(updated, encoding="utf-8")
        changed_files += 1
        total += count

    print(f"APPLIED FILES: {changed_files}")
    print(f"APPLIED BOUNDARIES: {total}")

    remaining = inspect()
    if remaining:
        print("FAIL: proven-safe renderer echo boundaries remain unannotated after apply.")
        return 1

    print("PASS: all proven-safe renderer echo boundaries are explicitly annotated.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
