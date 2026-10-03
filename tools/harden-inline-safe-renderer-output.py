#!/usr/bin/env python3
"""Annotate scan-confirmed inline safe-renderer output for WordPressCS.

This tool reuses the already regression-tested Core Blueprint UI renderer trust
contract. It is intentionally narrower than harden-safe-renderer-output.py:

- a Plugin Check JSON export is mandatory;
- only current OutputNotEscaped lines are considered;
- only the nine proven-safe UI renderers are eligible;
- template lines are accepted only when every echo expression on that line is
  either one of those renderers or an explicit WordPress escaping function;
- pure-PHP concatenation is accepted only for static wrapper strings around one
  safe renderer call;
- the PHPCS directive is added on the same physical line so scan line numbers
  remain stable for --check.

No new renderer trust is introduced here. Field and Card remain excluded.
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SNIFF = "WordPress.Security.EscapeOutput.OutputNotEscaped"
MARKER = (
    "phpcs:ignore "
    + SNIFF
    + " -- Proven-safe Core Blueprint UI renderer owns context-specific "
      "escaping for its complete public payload."
)

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

ECHO_TAG_RE = re.compile(r"<\?php\s+echo\s+(.*?)\s*;?\s*\?>")
ESCAPED_ECHO_RE = re.compile(
    r"^(?:"
    r"esc_(?:html|attr|url|textarea)(?:__|_e)?"
    r"|wp_kses(?:_post)?"
    r")\s*\("
)


def load_scan(path: Path) -> dict[str, dict[int, list[dict]]]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    results = payload.get("results", {})
    if not isinstance(results, dict):
        raise ValueError("Plugin Check export does not contain a results object.")

    reported: dict[str, dict[int, list[dict]]] = {}
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
                reported.setdefault(file_name, {}).setdefault(
                    int(message["line"]), []
                ).append(message)
    return reported


def renderer_pattern(renderer: str) -> re.Pattern[str]:
    return re.compile(
        rf"(?:(?:\\CoreBlueprint\\Core\\UI\\)?{re.escape(renderer)})"
        rf"::render\s*\("
    )


def short_renderer_is_ui_owned(text: str, renderer: str, line: str) -> bool:
    if f"\\CoreBlueprint\\Core\\UI\\{renderer}" in line:
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


def safe_renderers_on_line(text: str, line: str) -> list[str]:
    found: list[str] = []
    for renderer in SAFE_RENDERERS:
        if renderer_pattern(renderer).search(line) and short_renderer_is_ui_owned(
            text, renderer, line
        ):
            found.append(renderer)
    return found


def expression_is_safe(text: str, line: str, expression: str) -> bool:
    expression = expression.strip()
    if ESCAPED_ECHO_RE.match(expression):
        return True
    for renderer in SAFE_RENDERERS:
        if renderer_pattern(renderer).search(expression) and short_renderer_is_ui_owned(
            text, renderer, line
        ):
            return True
    return False


def template_line_is_safe(text: str, line: str) -> bool:
    echoes = ECHO_TAG_RE.findall(line)
    if not echoes:
        return False
    return all(expression_is_safe(text, line, expr) for expr in echoes)


def find_matching_paren(line: str, open_index: int) -> int | None:
    depth = 0
    quote: str | None = None
    escaped = False

    for index in range(open_index, len(line)):
        ch = line[index]

        if quote is not None:
            if escaped:
                escaped = False
            elif ch == "\\":
                escaped = True
            elif ch == quote:
                quote = None
            continue

        if ch in ("'", '"'):
            quote = ch
            continue
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0:
                return index

    return None


def pure_php_line_is_safe(text: str, line: str, renderers: list[str]) -> bool:
    stripped = line.strip()
    if not stripped.startswith("echo "):
        return False
    if len(renderers) != 1:
        return False

    renderer = renderers[0]
    match = renderer_pattern(renderer).search(line)
    if not match:
        return False

    open_index = line.find("(", match.start())
    close_index = find_matching_paren(line, open_index)
    if close_index is None:
        return False

    prefix = line[: match.start()].strip()
    suffix = line[close_index + 1 :].strip()

    static_prefix = re.compile(
        r"^echo\s+(?:(?:'[^']*'|\"[^\"]*\")\s*\.\s*)?$"
    )
    static_suffix = re.compile(
        r"^(?:\.\s*(?:'[^']*'|\"[^\"]*\"))?\s*;\s*$"
    )
    return bool(static_prefix.match(prefix) and static_suffix.match(suffix))


def line_is_eligible(text: str, line: str) -> bool:
    if MARKER in line:
        return False

    renderers = safe_renderers_on_line(text, line)
    if not renderers:
        return False

    if "<?php" in line:
        return template_line_is_safe(text, line)

    return pure_php_line_is_safe(text, line, renderers)


def candidate_files() -> list[Path]:
    out: list[Path] = []
    for path in ROOT.rglob("*.php"):
        rel = path.relative_to(ROOT)
        if any(part in EXCLUDED_DIRS for part in rel.parts):
            continue
        out.append(path)
    return sorted(out)


def inspect(
    reported: dict[str, dict[int, list[dict]]]
) -> list[tuple[Path, int, list[str], int]]:
    candidates: list[tuple[Path, int, list[str], int]] = []

    for path in candidate_files():
        rel = path.relative_to(ROOT).as_posix()
        file_findings = reported.get(rel)
        if not file_findings:
            continue

        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue

        lines = text.splitlines()
        for line_no, findings in sorted(file_findings.items()):
            if line_no < 1 or line_no > len(lines):
                raise RuntimeError(
                    f"Scan line outside current source: {rel}:{line_no}. "
                    "Use the Plugin Check export from this exact source tree."
                )

            line = lines[line_no - 1]
            if not line_is_eligible(text, line):
                continue

            renderers = safe_renderers_on_line(text, line)
            candidates.append((path, line_no, renderers, len(findings)))

    return candidates


def annotate_line(line: str) -> str:
    if MARKER in line:
        return line

    if "<?php" in line:
        return line + " <?php // " + MARKER + " ?>"

    return line.rstrip() + " // " + MARKER


def apply_candidates(
    candidates: list[tuple[Path, int, list[str], int]]
) -> tuple[int, int, int]:
    by_file: dict[Path, list[int]] = {}
    finding_count = 0
    for path, line_no, _renderers, findings in candidates:
        by_file.setdefault(path, []).append(line_no)
        finding_count += findings

    for path, line_numbers in by_file.items():
        text = path.read_text(encoding="utf-8")
        lines = text.splitlines()

        for line_no in line_numbers:
            lines[line_no - 1] = annotate_line(lines[line_no - 1])

        updated = "\n".join(lines)
        if text.endswith("\n"):
            updated += "\n"
        path.write_text(updated, encoding="utf-8")

    return len(by_file), len(candidates), finding_count


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
    candidates = inspect(reported)

    if args.check:
        if candidates:
            print("FAIL: scan-confirmed inline safe-renderer boundaries remain.")
            for path, line_no, renderers, findings in candidates:
                print(
                    f"{path.relative_to(ROOT)}:{line_no}: "
                    f"{','.join(renderers)} findings={findings}"
                )
            print(f"FILES: {len({path for path, *_ in candidates})}")
            print(f"BOUNDARIES: {len(candidates)}")
            print(f"FINDINGS: {sum(item[3] for item in candidates)}")
            return 1
        print("PASS: scan-confirmed inline safe-renderer boundaries are annotated.")
        return 0

    if not args.apply:
        for path, line_no, renderers, findings in candidates:
            print(
                f"{path.relative_to(ROOT)}:{line_no}: "
                f"{','.join(renderers)} findings={findings}"
            )
        print(f"FILES: {len({path for path, *_ in candidates})}")
        print(f"BOUNDARIES: {len(candidates)}")
        print(f"FINDINGS: {sum(item[3] for item in candidates)}")
        return 0

    files, boundaries, findings = apply_candidates(candidates)
    print(f"APPLIED FILES: {files}")
    print(f"APPLIED BOUNDARIES: {boundaries}")
    print(f"COVERED FINDINGS: {findings}")

    remaining = inspect(reported)
    if remaining:
        print("FAIL: scan-confirmed inline safe-renderer boundaries remain after apply.")
        return 1

    print("PASS: scan-confirmed inline safe-renderer boundaries are annotated.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
