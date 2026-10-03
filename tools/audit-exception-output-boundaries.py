#!/usr/bin/env python3
"""Classify Plugin Check ExceptionNotEscaped findings without changing source.

The purpose of this audit tool is to separate true exception/error-message
boundaries from unrelated output before any PHPCS annotation is considered.
It intentionally performs no writes.

Usage:
  python3 tools/audit-exception-output-boundaries.py \
    --scan-json /path/to/plugin-check.json
"""

from __future__ import annotations

import argparse
import collections
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SNIFF = "WordPress.Security.EscapeOutput.ExceptionNotEscaped"

ANCHOR_PATTERNS = (
    ("throw_new", re.compile(r"\bthrow\s+new\s+")),
    ("trigger_error", re.compile(r"\btrigger_error\s*\(")),
    ("wp_die", re.compile(r"\bwp_die\s*\(")),
    ("exception_ctor", re.compile(r"\bnew\s+\\?[A-Za-z_][A-Za-z0-9_\\]*Exception\s*\(")),
)


def load_findings(path: Path) -> list[dict]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    results = payload.get("results", {})
    if not isinstance(results, dict):
        raise RuntimeError("Plugin Check export has no results object.")

    findings: list[dict] = []
    for file_name, messages in results.items():
        if not isinstance(messages, list):
            continue
        for message in messages:
            if (
                isinstance(message, dict)
                and message.get("type") == "ERROR"
                and message.get("code") == SNIFF
            ):
                findings.append(
                    {
                        "file": file_name,
                        "line": int(message.get("line") or 0),
                        "column": int(message.get("column") or 0),
                        "message": str(message.get("message") or ""),
                    }
                )
    return findings


def found_token(message: str) -> str:
    match = re.search(r"found '(.+?)'\.$", message)
    return match.group(1) if match else message


def source_lines(rel: str) -> list[str]:
    path = ROOT / rel
    if not path.is_file():
        raise RuntimeError(f"Source file missing from working tree: {rel}")
    return path.read_text(encoding="utf-8").splitlines()


def classify_anchor(lines: list[str], line_no: int) -> tuple[str, int, str]:
    if line_no < 1 or line_no > len(lines):
        return ("out_of_range", line_no, "")

    index = line_no - 1

    # Search backwards within the current short statement/block. Most scanner
    # findings point at arguments nested a few lines below throw new ...(...).
    for i in range(index, max(-1, index - 14), -1):
        line = lines[i]
        for category, pattern in ANCHOR_PATTERNS:
            if pattern.search(line):
                # A plain exception construction is only considered the throw
                # anchor when "throw new" is actually present.
                if category == "exception_ctor" and "throw" in line:
                    continue
                return (category, i + 1, line.strip())

        # Stop walking into an earlier completed statement unless the current
        # line is only punctuation/continuation syntax.
        stripped = line.strip()
        if i < index and stripped.endswith(";") and stripped not in {");", "];"}:
            break

    # Detect single-line throw statements directly.
    current = lines[index].strip()
    if current.startswith("throw "):
        return ("throw_other", line_no, current)

    return ("other", line_no, current)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--scan-json", required=True)
    args = parser.parse_args()

    scan_path = Path(args.scan_json).expanduser().resolve()
    if not scan_path.is_file():
        parser.error(f"scan JSON not found: {scan_path}")

    findings = load_findings(scan_path)
    if not findings:
        raise RuntimeError("No ExceptionNotEscaped ERROR findings found.")

    file_cache: dict[str, list[str]] = {}
    token_counts: collections.Counter[str] = collections.Counter()
    category_findings: collections.Counter[str] = collections.Counter()
    category_boundaries: collections.Counter[str] = collections.Counter()
    file_findings: collections.Counter[str] = collections.Counter()
    boundary_keys: set[tuple[str, int, str]] = set()
    third_party = 0
    unresolved: list[tuple[str, int, str]] = []

    for finding in findings:
        rel = finding["file"]
        if rel not in file_cache:
            file_cache[rel] = source_lines(rel)

        token_counts[found_token(finding["message"])] += 1
        file_findings[rel] += 1

        category, anchor_line, anchor_text = classify_anchor(
            file_cache[rel], finding["line"]
        )
        category_findings[category] += 1
        boundary_keys.add((rel, anchor_line, category))

        if "/lib/" in rel or rel.startswith("vendor/"):
            third_party += 1

        if category in {"other", "out_of_range"}:
            unresolved.append((rel, finding["line"], anchor_text))

    for _rel, _line, category in boundary_keys:
        category_boundaries[category] += 1

    print(f"EXCEPTION FINDINGS: {len(findings)}")
    print(f"FILES: {len(file_findings)}")
    print(f"UNIQUE BOUNDARIES: {len(boundary_keys)}")
    print(f"THIRD-PARTY FINDINGS: {third_party}")
    print("CATEGORIES:")
    for category, count in category_findings.most_common():
        print(
            f"  {category}: findings={count} "
            f"boundaries={category_boundaries[category]}"
        )

    print("TOKENS:")
    for token, count in token_counts.most_common(20):
        print(f"  {token}: {count}")

    print("TOP FILES:")
    for rel, count in file_findings.most_common(15):
        print(f"  {rel}: {count}")

    print(f"UNRESOLVED FINDINGS: {len(unresolved)}")
    for rel, line_no, text in unresolved[:30]:
        print(f"  {rel}:{line_no}: {text}")

    print("DRY AUDIT: no files changed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
