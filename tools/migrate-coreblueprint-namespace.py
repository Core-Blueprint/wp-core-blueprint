#!/usr/bin/env python3
r"""One-time pre-v1 namespace migration for Core Blueprint Base.

Migrates the first-party Base namespace root:
    CB\Core -> CoreBlueprint\Core

The command is intentionally deterministic and repository-local. It does not
touch dependencies, generated release output, or composed translation catalogs.

Usage:
    python3 tools/migrate-coreblueprint-namespace.py
    python3 tools/migrate-coreblueprint-namespace.py --apply
    python3 tools/migrate-coreblueprint-namespace.py --check
"""

from __future__ import annotations

import argparse
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
SELF = Path(__file__).resolve()

EXCLUDED_DIRS = {
    ".git",
    "vendor",
    "node_modules",
    "dist",
    "build",
    "languages",
    "licenses",
}

EXCLUDED_FILES = {
    # Historical milestone record: preserve namespace references exactly as they
    # appeared at the time instead of rewriting history during the pre-v1 cutover.
    Path("CHANGELOG-HISTORY.md"),
}

TEXT_SUFFIXES = {
    ".php",
    ".js",
    ".mjs",
    ".cjs",
    ".ts",
    ".json",
    ".md",
    ".txt",
    ".xml",
    ".yml",
    ".yaml",
    ".sh",
    ".py",
}

# Match the namespace root with any level of textual escaping. This covers
# normal PHP namespaces (CB\Core), escaped string literals (CB\\Core), and
# deeper escaped contract fixtures without hard-coding every representation.
LEGACY_NAMESPACE_PATTERN = re.compile(r"CB(?P<slashes>\\+)Core")


def candidate_files() -> list[Path]:
    files: list[Path] = []
    for path in ROOT.rglob("*"):
        if not path.is_file() or path.resolve() == SELF:
            continue

        relative = path.relative_to(ROOT)
        if any(part in EXCLUDED_DIRS for part in relative.parts):
            continue

        if relative in EXCLUDED_FILES:
            continue

        if path.suffix.lower() not in TEXT_SUFFIXES:
            continue

        files.append(path)

    return sorted(files)


def transform(text: str) -> tuple[str, int]:
    matches = list(LEGACY_NAMESPACE_PATTERN.finditer(text))
    if not matches:
        return text, 0

    updated = LEGACY_NAMESPACE_PATTERN.sub(
        lambda match: f"CoreBlueprint{match.group('slashes')}Core",
        text,
    )
    return updated, len(matches)


def inspect() -> tuple[list[tuple[Path, int]], int]:
    affected: list[tuple[Path, int]] = []
    total = 0

    for path in candidate_files():
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue

        _updated, count = transform(text)
        if count:
            affected.append((path, count))
            total += count

    return affected, total


def apply() -> tuple[int, int]:
    affected, _total = inspect()
    replacements = 0

    for path, _count in affected:
        text = path.read_text(encoding="utf-8")
        updated, count = transform(text)
        if not count:
            continue

        path.write_text(updated, encoding="utf-8")
        replacements += count

    return len(affected), replacements


def print_report(affected: list[tuple[Path, int]], total: int) -> None:
    for path, count in affected:
        print(f"{path.relative_to(ROOT)}: {count}")

    print(f"FILES: {len(affected)}")
    print(f"REPLACEMENTS: {total}")


def main() -> int:
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--apply", action="store_true")
    mode.add_argument("--check", action="store_true")
    args = parser.parse_args()

    if args.apply:
        files, replacements = apply()
        print(f"APPLIED FILES: {files}")
        print(f"APPLIED REPLACEMENTS: {replacements}")

        remaining, total = inspect()
        if remaining:
            print("FAIL: legacy Base namespace references remain after apply.", file=sys.stderr)
            print_report(remaining, total)
            return 1

        print("PASS: no legacy CB\\Core namespace references remain in owned text files.")
        return 0

    affected, total = inspect()

    if args.check:
        if affected:
            print("FAIL: legacy Base namespace references remain.", file=sys.stderr)
            print_report(affected, total)
            return 1

        print("PASS: no legacy CB\\Core namespace references remain in owned text files.")
        return 0

    print_report(affected, total)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
