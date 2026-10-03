#!/usr/bin/env python3
"""Audit request-handling warnings from a Plugin Check JSON export.

Read-only. Validates the current 466-request-warning baseline:
- 307 NonceVerification.Missing
- 63 NonceVerification.Recommended
- 96 InputNotSanitized

For each finding it maps the scan line to the enclosing PHP function/method and
records whether that function contains a nonce/capability guard before the
finding, whether the line sanitizes locally, and whether the file uses a known
Core Blueprint request abstraction.

This is inventory only. It does not suppress or modify source warnings.

Usage:
  python3 tools/audit-request-warnings.py --scan-json /path/to/plugin-check.json
"""

from __future__ import annotations

import argparse
import collections
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

CODES = {
    "WordPress.Security.NonceVerification.Missing": 307,
    "WordPress.Security.NonceVerification.Recommended": 63,
    "WordPress.Security.ValidatedSanitizedInput.InputNotSanitized": 96,
}

GUARD_PATTERNS = (
    "check_admin_referer(",
    "check_ajax_referer(",
    "wp_verify_nonce(",
    "Request::nonce(",
    "self::guard(",
    "static::guard(",
    "Guards::",
)

CAP_PATTERNS = (
    "current_user_can(",
    "Request::cap(",
    "Guards::require_admin(",
)

SANITIZE_PATTERNS = (
    "sanitize_",
    "wp_unslash(",
    "absint(",
    "intval(",
    "(int)",
    "filter_var(",
)

ABSTRACTION_MARKERS = (
    "CoreBlueprint\\Core\\Ajax\\Request",
    "Request::nonce(",
    "self::guard(",
    "check_admin_referer(",
    "check_ajax_referer(",
)

FUNCTION_RE = re.compile(
    r"^(?P<indent>\s*)(?:public|protected|private)?\s*(?:static\s+)?function\s+(?P<name>[A-Za-z0-9_]+)\s*\("
)


def load(path: Path) -> list[dict]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    results = payload.get("results", {})
    if not isinstance(results, dict):
        raise RuntimeError("Plugin Check export has no results object.")

    findings: list[dict] = []
    for rel, messages in results.items():
        if not isinstance(messages, list):
            continue
        for message in messages:
            if not isinstance(message, dict):
                continue
            code = str(message.get("code") or "")
            if message.get("type") != "WARNING" or code not in CODES:
                continue
            findings.append(
                {
                    "file": rel,
                    "line": int(message.get("line") or 0),
                    "column": int(message.get("column") or 0),
                    "code": code,
                    "message": str(message.get("message") or ""),
                }
            )
    return findings


def function_ranges(lines: list[str]) -> list[tuple[str, int, int]]:
    starts: list[tuple[str, int]] = []
    for idx, line in enumerate(lines, start=1):
        match = FUNCTION_RE.match(line)
        if match:
            starts.append((match.group("name"), idx))

    ranges: list[tuple[str, int, int]] = []
    for i, (name, start) in enumerate(starts):
        end = starts[i + 1][1] - 1 if i + 1 < len(starts) else len(lines)
        ranges.append((name, start, end))
    return ranges


def enclosing_function(
    ranges: list[tuple[str, int, int]], line_no: int
) -> tuple[str, int, int] | None:
    candidate = None
    for item in ranges:
        if item[1] <= line_no <= item[2]:
            candidate = item
    return candidate


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--scan-json", required=True)
    args = parser.parse_args()

    scan = Path(args.scan_json).expanduser().resolve()
    if not scan.is_file():
        parser.error(f"scan JSON not found: {scan}")

    findings = load(scan)
    by_code = collections.Counter(item["code"] for item in findings)
    if by_code != collections.Counter(CODES):
        raise RuntimeError(
            f"Request-warning baseline changed. Expected {CODES!r}, found {dict(by_code)!r}."
        )

    total = len(findings)
    if total != 466:
        raise RuntimeError(f"Expected 466 request warnings, found {total}.")

    file_counts = collections.Counter(item["file"] for item in findings)
    function_counts: collections.Counter[tuple[str, str]] = collections.Counter()
    guard_before = 0
    capability_before = 0
    local_sanitizer = 0
    abstraction_files: set[str] = set()
    unresolved_function = 0

    per_file_summary: dict[str, dict[str, int]] = collections.defaultdict(
        lambda: collections.Counter()
    )

    cache: dict[str, tuple[list[str], list[tuple[str, int, int]]]] = {}

    for item in findings:
        rel = item["file"]
        path = ROOT / rel
        if not path.is_file():
            raise RuntimeError(f"Source file missing: {rel}")

        if rel not in cache:
            lines = path.read_text(encoding="utf-8").splitlines()
            cache[rel] = (lines, function_ranges(lines))
            text = "\n".join(lines)
            if any(marker in text for marker in ABSTRACTION_MARKERS):
                abstraction_files.add(rel)

        lines, ranges = cache[rel]
        line_no = item["line"]
        if line_no < 1 or line_no > len(lines):
            raise RuntimeError(f"{rel}:{line_no}: scan line outside source.")

        function = enclosing_function(ranges, line_no)
        if function is None:
            unresolved_function += 1
            fn_name = "<file-scope>"
            fn_start = 1
        else:
            fn_name, fn_start, _fn_end = function

        before = "\n".join(lines[fn_start - 1 : line_no])
        source = lines[line_no - 1]

        has_guard = any(pattern in before for pattern in GUARD_PATTERNS)
        has_cap = any(pattern in before for pattern in CAP_PATTERNS)
        has_local_sanitizer = any(pattern in source for pattern in SANITIZE_PATTERNS)

        if has_guard:
            guard_before += 1
            per_file_summary[rel]["guard_before"] += 1
        if has_cap:
            capability_before += 1
            per_file_summary[rel]["cap_before"] += 1
        if has_local_sanitizer:
            local_sanitizer += 1
            per_file_summary[rel]["local_sanitizer"] += 1

        per_file_summary[rel][item["code"]] += 1
        function_counts[(rel, fn_name)] += 1

    print(f"REQUEST WARNINGS: {total}")
    print(f"FILES: {len(file_counts)}")
    print(f"FUNCTION/FILE-SCOPE BOUNDARIES: {len(function_counts)}")
    print(f"FINDINGS WITH NONCE/GUARD SIGNAL BEFORE LINE: {guard_before}")
    print(f"FINDINGS WITH CAPABILITY SIGNAL BEFORE LINE: {capability_before}")
    print(f"FINDINGS WITH LOCAL SANITIZER/UNSLASH SIGNAL: {local_sanitizer}")
    print(f"FILES USING REQUEST/GUARD ABSTRACTION: {len(abstraction_files)}")
    print(f"UNRESOLVED FUNCTION FINDINGS: {unresolved_function}")

    print("BY CODE:")
    for code in CODES:
        files = {item["file"] for item in findings if item["code"] == code}
        print(f"  {code}: {by_code[code]} findings / {len(files)} files")

    print("TOP FILES:")
    for rel, count in file_counts.most_common():
        summary = per_file_summary[rel]
        missing = summary["WordPress.Security.NonceVerification.Missing"]
        recommended = summary["WordPress.Security.NonceVerification.Recommended"]
        unsanitized = summary[
            "WordPress.Security.ValidatedSanitizedInput.InputNotSanitized"
        ]
        print(
            f"  {rel}: total={count} missing={missing} recommended={recommended} "
            f"unsanitized={unsanitized} guard_before={summary['guard_before']} "
            f"cap_before={summary['cap_before']} local_sanitizer={summary['local_sanitizer']}"
        )

    print("DRY AUDIT: no files changed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
