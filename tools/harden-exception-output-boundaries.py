#!/usr/bin/env python3
"""Annotate audited exception-message output boundaries from Plugin Check.

This tool is intentionally scan-driven. It only handles ERROR findings with
WordPress.Security.EscapeOutput.ExceptionNotEscaped that have already been
classified as throw-new exception boundaries.

Why:
- Exception messages are data, not HTML output.
- Escaping at construction time can corrupt CLI/log/non-HTML consumers.
- Escaping remains the responsibility of the eventual presentation boundary.

Bundled third-party library code is deliberately excluded and reported
separately.

Usage:
  python3 tools/harden-exception-output-boundaries.py \
    --scan-json /path/to/plugin-check.json

  python3 tools/harden-exception-output-boundaries.py \
    --scan-json /path/to/plugin-check.json --apply

  python3 tools/harden-exception-output-boundaries.py \
    --scan-json /path/to/plugin-check.json --check
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SNIFF = "WordPress.Security.EscapeOutput.ExceptionNotEscaped"
DISABLE = (
    "// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- "
    "Exception messages are not HTML output; escape only at the eventual "
    "presentation boundary."
)
ENABLE = "// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped"


def load_findings(path: Path) -> dict[str, list[dict]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    results = payload.get("results", {})
    if not isinstance(results, dict):
        raise RuntimeError("Plugin Check export has no results object.")

    found: dict[str, list[dict]] = {}
    for file_name, messages in results.items():
        if not isinstance(messages, list):
            continue
        selected = [
            message
            for message in messages
            if isinstance(message, dict)
            and message.get("type") == "ERROR"
            and message.get("code") == SNIFF
        ]
        if selected:
            found[file_name] = selected
    return found


def is_third_party(rel: str) -> bool:
    return "/lib/" in rel or rel.startswith("vendor/")


def find_throw_anchor(lines: list[str], finding_line: int) -> int:
    index = finding_line - 1
    if index < 0 or index >= len(lines):
        raise RuntimeError(f"Finding line out of range: {finding_line}")

    for i in range(index, max(-1, index - 14), -1):
        line = lines[i]
        if re.search(r"\bthrow\s+new\s+", line):
            return i

        stripped = line.strip()
        if i < index and stripped.endswith(";") and stripped not in {");", "];"}:
            break

    raise RuntimeError(
        f"No throw-new anchor found for finding at source line {finding_line}."
    )


def find_throw_end(lines: list[str], start: int) -> int:
    # The audited throw-new statements in this codebase terminate on a source
    # line ending in ";" (commonly ");"). Limit the search so an unexpected
    # source shape fails closed rather than spanning unrelated code.
    for i in range(start, min(len(lines), start + 60)):
        stripped = lines[i].strip()
        if re.search(r";\s*(?://.*)?$", stripped):
            return i
    raise RuntimeError(
        f"Could not find end of throw-new statement starting at line {start + 1}."
    )


def boundaries_for_file(
    rel: str, findings: list[dict]
) -> tuple[list[str], list[tuple[int, int, int]]]:
    path = ROOT / rel
    if not path.is_file():
        raise RuntimeError(f"Source file missing: {rel}")

    lines = path.read_text(encoding="utf-8").splitlines()
    grouped: dict[tuple[int, int], int] = {}

    for finding in findings:
        line_no = int(finding.get("line") or 0)
        start = find_throw_anchor(lines, line_no)
        end = find_throw_end(lines, start)
        if not (start <= line_no - 1 <= end):
            raise RuntimeError(
                f"{rel}:{line_no}: finding lies outside resolved throw boundary "
                f"{start + 1}-{end + 1}."
            )
        grouped[(start, end)] = grouped.get((start, end), 0) + 1

    boundaries = [(start, end, count) for (start, end), count in grouped.items()]
    boundaries.sort()
    return lines, boundaries


def annotate_lines(
    rel: str, lines: list[str], boundaries: list[tuple[int, int, int]]
) -> list[str]:
    updated = list(lines)

    # Apply from bottom to top so original line numbers remain valid.
    for start, end, _count in reversed(boundaries):
        indent = re.match(r"^\s*", updated[start]).group(0)

        before = updated[start - 1].strip() if start > 0 else ""
        after = updated[end + 1].strip() if end + 1 < len(updated) else ""

        if "phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped" in before:
            if "phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped" not in after:
                raise RuntimeError(
                    f"{rel}:{start + 1}: existing disable is missing its adjacent enable."
                )
            continue

        translator_index = None
        if start > 0 and "translators:" in updated[start - 1]:
            translator_index = start - 1

        if translator_index is not None:
            translator_line = updated[translator_index]
            updated[translator_index:end + 1] = [
                indent + DISABLE,
                translator_line,
                *updated[start:end + 1],
                indent + ENABLE,
            ]
        else:
            updated[start:end + 1] = [
                indent + DISABLE,
                *updated[start:end + 1],
                indent + ENABLE,
            ]

    return updated


def count_current_annotations(lines: list[str]) -> int:
    return sum(
        1
        for line in lines
        if "phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped"
        in line
    )


def repair_applied_translator_boundaries() -> tuple[int, int]:
    """Move E2 PHPCS disables before existing translators comments.

    The first E2 apply inserted the exception-boundary disable immediately
    before each throw statement. For sprintf(__()) calls with an existing
    translators comment, this separated the comment from its translation call.
    Repair only the exact adjacent comment/disable pattern and require the
    audited count of ten.
    """
    matches: list[tuple[Path, int]] = []
    excluded = {".git", "vendor", "node_modules", "dist", "build"}

    for path in ROOT.rglob("*.php"):
        rel = path.relative_to(ROOT)
        if any(part in excluded for part in rel.parts):
            continue
        lines = path.read_text(encoding="utf-8").splitlines()
        for index in range(len(lines) - 1):
            if (
                "translators:" in lines[index]
                and "phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped"
                in lines[index + 1]
            ):
                matches.append((path, index))

    if 10 != len(matches):
        details = [
            f"{path.relative_to(ROOT).as_posix()}:{index + 1}"
            for path, index in matches
        ]
        raise RuntimeError(
            "Expected exactly 10 applied translator/exception boundary pairs, "
            f"found {len(matches)}: {details!r}"
        )

    by_path: dict[Path, list[int]] = {}
    for path, index in matches:
        by_path.setdefault(path, []).append(index)

    for path, indexes in by_path.items():
        text = path.read_text(encoding="utf-8")
        lines = text.splitlines()
        for index in sorted(indexes, reverse=True):
            translator_line = lines[index]
            disable_line = lines[index + 1]
            lines[index:index + 2] = [disable_line, translator_line]
        suffix = "\n" if text.endswith("\n") else ""
        path.write_text("\n".join(lines) + suffix, encoding="utf-8")

    print(f"REPAIRED FILES: {len(by_path)}")
    print(f"REPAIRED TRANSLATOR BOUNDARIES: {len(matches)}")
    print(
        "PASS: exception PHPCS disables now precede translators comments "
        "without changing runtime statements."
    )
    return (len(by_path), len(matches))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--scan-json")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--apply", action="store_true")
    mode.add_argument("--check", action="store_true")
    mode.add_argument("--repair-translators", action="store_true")
    args = parser.parse_args()

    if args.repair_translators:
        repair_applied_translator_boundaries()
        return 0

    if not args.scan_json:
        parser.error("--scan-json is required unless --repair-translators is used")

    scan_path = Path(args.scan_json).expanduser().resolve()
    if not scan_path.is_file():
        parser.error(f"scan JSON not found: {scan_path}")

    findings_by_file = load_findings(scan_path)
    total_findings = sum(len(items) for items in findings_by_file.values())

    if total_findings != 398:
        raise RuntimeError(
            f"Expected audited 398 ExceptionNotEscaped findings, found {total_findings}."
        )

    third_party_files = {
        rel: items for rel, items in findings_by_file.items() if is_third_party(rel)
    }
    first_party_files = {
        rel: items for rel, items in findings_by_file.items() if not is_third_party(rel)
    }

    third_party_findings = sum(len(items) for items in third_party_files.values())
    first_party_findings = sum(len(items) for items in first_party_files.values())

    if third_party_findings != 1:
        raise RuntimeError(
            f"Expected exactly 1 third-party finding, found {third_party_findings}."
        )

    if args.check:
        disable_total = 0
        enable_total = 0
        annotated_files = 0

        for rel in first_party_files:
            path = ROOT / rel
            if not path.is_file():
                raise RuntimeError(f"Source file missing: {rel}")
            lines = path.read_text(encoding="utf-8").splitlines()
            disables = sum(
                1
                for line in lines
                if "phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped"
                in line
            )
            enables = sum(
                1
                for line in lines
                if "phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped"
                in line
            )
            if disables or enables:
                annotated_files += 1
            if disables != enables:
                raise RuntimeError(
                    f"{rel}: unbalanced exception annotations "
                    f"(disable={disables}, enable={enables})."
                )
            disable_total += disables
            enable_total += enables

        print(f"SCAN FINDINGS: {total_findings}")
        print(f"FIRST-PARTY FILES: {len(first_party_files)}")
        print(f"FIRST-PARTY BOUNDARIES: {disable_total}")
        print(f"FIRST-PARTY FINDINGS COVERED: {first_party_findings}")
        print(f"THIRD-PARTY FILES DEFERRED: {len(third_party_files)}")
        print(f"THIRD-PARTY FINDINGS DEFERRED: {third_party_findings}")

        if annotated_files != 70:
            raise RuntimeError(
                f"Expected annotations in 70 first-party files, found {annotated_files}."
            )
        if disable_total != 365 or enable_total != 365:
            raise RuntimeError(
                "Expected 365 balanced first-party exception boundaries, "
                f"found disable={disable_total}, enable={enable_total}."
            )

        print(
            "PASS: all audited first-party exception boundaries are explicitly annotated."
        )
        return 0

    prepared: dict[str, tuple[list[str], list[str], list[tuple[int, int, int]]]] = {}
    boundary_total = 0
    covered_findings = 0

    for rel, findings in first_party_files.items():
        lines, boundaries = boundaries_for_file(rel, findings)
        boundary_total += len(boundaries)
        covered_findings += sum(count for _start, _end, count in boundaries)
        updated = annotate_lines(rel, lines, boundaries)
        prepared[rel] = (lines, updated, boundaries)

    if covered_findings != first_party_findings:
        raise RuntimeError(
            f"Coverage mismatch: expected {first_party_findings}, covered {covered_findings}."
        )

    if boundary_total != 365:
        raise RuntimeError(
            f"Expected audited 365 first-party throw boundaries, found {boundary_total}."
        )

    print(f"SCAN FINDINGS: {total_findings}")
    print(f"FIRST-PARTY FILES: {len(first_party_files)}")
    print(f"FIRST-PARTY BOUNDARIES: {boundary_total}")
    print(f"FIRST-PARTY FINDINGS COVERED: {covered_findings}")
    print(f"THIRD-PARTY FILES DEFERRED: {len(third_party_files)}")
    print(f"THIRD-PARTY FINDINGS DEFERRED: {third_party_findings}")

    for rel, findings in third_party_files.items():
        lines = (ROOT / rel).read_text(encoding="utf-8").splitlines()
        for finding in findings:
            line_no = int(finding.get("line") or 0)
            source = lines[line_no - 1].strip() if 0 < line_no <= len(lines) else ""
            print(f"THIRD-PARTY: {rel}:{line_no}: {source}")

    if not args.apply:
        print("DRY RUN: no files changed.")
        return 0

    changed_files = 0
    for rel, (before, after, _boundaries) in prepared.items():
        if before == after:
            continue
        path = ROOT / rel
        suffix = "\n" if path.read_text(encoding="utf-8").endswith("\n") else ""
        path.write_text("\n".join(after) + suffix, encoding="utf-8")
        changed_files += 1

    print(f"APPLIED FILES: {changed_files}")
    print(f"APPLIED BOUNDARIES: {boundary_total}")
    print(f"COVERED FINDINGS: {covered_findings}")
    print("Third-party source remains unchanged.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
