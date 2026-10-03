#!/usr/bin/env python3
"""Finalize the remaining audited OutputNotEscaped callsite boundaries.

This patch is intentionally scan-driven and exact-source guarded. It handles
only the four callsite groups whose escaping contracts have been manually
audited after E1A/E1B1/E1B2a:

- Login Shield Field + RadioGroup slot composition.
- Settings Hub Card calls with structured/escaped body content.
- Core Shield escape-clean UI helper output, while repairing PHPCS comments
  left awkwardly nested by the earlier mechanical annotation pass.
- Partner theme preview SVG after the bounded wp_kses() sanitizer.

SecureActionScreen is deliberately NOT patched here. Card's body slot is raw by
contract, so its callers must be audited separately before those two findings
can be documented as a trusted boundary.

Usage:
  python3 tools/finalize-output-escaping-boundaries.py --scan-json <export.json>
  python3 tools/finalize-output-escaping-boundaries.py --scan-json <export.json> --apply
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SNIFF = "WordPress.Security.EscapeOutput.OutputNotEscaped"

EXPECTED = {
    "src/Admin/Pages/Settings.php": 5,
    "src/Admin/SecureActionScreen.php": 2,
    "templates/core-shield.php": 5,
    "templates/login-shield.php": 11,
    "templates/appearance.php": 1,
}

PATCHABLE = {
    "src/Admin/Pages/Settings.php": 5,
    "templates/core-shield.php": 5,
    "templates/login-shield.php": 11,
    "templates/appearance.php": 1,
}

DISABLE = (
    "// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- "
)
ENABLE = "// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped"


def load_scan(path: Path) -> dict[str, list[dict]]:
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


def verify_scan(found: dict[str, list[dict]]) -> None:
    counts = {name: len(messages) for name, messages in found.items()}
    if counts != EXPECTED:
        raise RuntimeError(
            "Current OutputNotEscaped scan does not match the audited 24-finding "
            f"baseline. Expected {EXPECTED!r}, got {counts!r}."
        )


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if 1 != count:
        raise RuntimeError(f"{label}: expected exact source fragment once, found {count}.")
    return text.replace(old, new, 1)


def patch_login_shield(text: str) -> str:
    lines = text.splitlines()

    labels = [
        i
        for i, line in enumerate(lines)
        if "'label'     => __( 'Block response', 'core-blueprint' )" in line
    ]
    if 1 != len(labels):
        raise RuntimeError(
            f"Login Shield: expected one Block response label, found {len(labels)}."
        )
    label_index = labels[0]

    start = next(
        (
            i
            for i in range(label_index, max(-1, label_index - 12), -1)
            if "Field::render( [" in lines[i] and "echo " in lines[i]
        ),
        None,
    )
    if start is None:
        raise RuntimeError("Login Shield: Field::render() start not found.")

    end = next(
        (
            i
            for i in range(label_index, min(len(lines), label_index + 40))
            if "] );" in lines[i]
            and "phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped" in lines[i]
        ),
        None,
    )
    if end is None:
        raise RuntimeError("Login Shield: documented Field::render() end not found.")

    indent = re.match(r"^\s*", lines[start]).group(0)
    clean_end = lines[end].split("// phpcs:ignore", 1)[0].rstrip()

    lines[start:end + 1] = [
        indent + DISABLE
        + "Field escapes its text fields; RadioGroup owns context-specific escaping for the complete raw control slot.",
        *lines[start:end],
        clean_end,
        indent + ENABLE,
    ]

    updated = "\n".join(lines)
    if text.endswith("\n"):
        updated += "\n"
    return updated


def patch_settings(text: str) -> str:
    lines = text.splitlines()

    def unique_index(needle: str, label: str) -> int:
        matches = [i for i, line in enumerate(lines) if needle in line]
        if 1 != len(matches):
            raise RuntimeError(
                f"{label}: expected one matching source line, found {len(matches)}."
            )
        return matches[0]

    # Structured empty state: body is empty and Card escapes title/description.
    empty_anchor = unique_index(
        "No extension settings registered yet",
        "Settings empty-state anchor",
    )
    empty_start = next(
        (
            i
            for i in range(empty_anchor, max(-1, empty_anchor - 12), -1)
            if "Card::render( [" in lines[i] and "echo " in lines[i]
        ),
        None,
    )
    if empty_start is None:
        raise RuntimeError("Settings empty-state Card start not found.")
    empty_end = next(
        (
            i
            for i in range(empty_anchor, min(len(lines), empty_anchor + 12))
            if "] );" in lines[i]
        ),
        None,
    )
    if empty_end is None:
        raise RuntimeError("Settings empty-state Card end not found.")

    empty_indent = re.match(r"^\s*", lines[empty_start]).group(0)
    empty_first = lines[empty_start].split("// phpcs:ignore", 1)[0].rstrip()
    lines[empty_start:empty_end + 1] = [
        empty_indent + DISABLE
        + "Card receives no raw HTML slot here and escapes the complete structured empty-state payload.",
        empty_first,
        *lines[empty_start + 1:empty_end + 1],
        empty_indent + ENABLE,
    ]

    # Re-resolve after the first rewrite changed line numbers.
    identity_anchor = unique_index(
        "'body'  => $identity_html",
        "Settings identity Card anchor",
    )
    identity_start = next(
        (
            i
            for i in range(identity_anchor, max(-1, identity_anchor - 8), -1)
            if "Card::render( [" in lines[i] and "echo " in lines[i]
        ),
        None,
    )
    if identity_start is None:
        raise RuntimeError("Settings identity Card start not found.")
    identity_end = next(
        (
            i
            for i in range(identity_anchor, min(len(lines), identity_anchor + 8))
            if "] ); ?>" in lines[i]
        ),
        None,
    )
    if identity_end is None:
        raise RuntimeError("Settings identity Card end not found.")

    identity_indent = re.match(r"^\s*", lines[identity_start]).group(0)
    identity_first = lines[identity_start].split("// phpcs:ignore", 1)[0].rstrip()
    lines[identity_start:identity_end + 1] = [
        identity_indent + "<?php " + DISABLE
        + "identity_html is composed locally from esc_html/esc_url output before entering Card's raw body slot. ?>",
        identity_first,
        *lines[identity_start + 1:identity_end + 1],
        identity_indent + "<?php " + ENABLE + " ?>",
    ]

    updated = "\n".join(lines)
    if text.endswith("\n"):
        updated += "\n"
    return updated


def patch_core_shield(text: str) -> str:
    lines = text.splitlines()

    def unique_index(needle: str, label: str) -> int:
        matches = [i for i, line in enumerate(lines) if needle in line]
        if 1 != len(matches):
            raise RuntimeError(
                f"{label}: expected one matching source line, found {len(matches)}."
            )
        return matches[0]

    def canonicalize_icon(needle: str, label: str) -> None:
        index = unique_index(needle, label)
        line = lines[index]
        indent = re.match(r"^\\s*", line).group(0)
        echo_only = line.split("?>", 1)[0].rstrip() + " ?>"

        # E1B2a may have left both a preceding legacy ignore and an inline
        # same-line ignore. Remove only those tool-owned PHPCS lines.
        start = index
        if index > 0 and "phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped" in lines[index - 1]:
            start = index - 1

        lines[start:index + 1] = [
            indent + "<?php " + DISABLE
            + "Icon::render() owns context-specific escaping for its complete SVG payload. ?>",
            indent + echo_only.lstrip(),
            indent + "<?php " + ENABLE + " ?>",
        ]

    canonicalize_icon(
        "UI\\Icon::render( 'expand', [ 'class' => 'cb-core-chevron'",
        "Core Shield module icon boundary",
    )
    canonicalize_icon(
        "UI\\Icon::render( 'expand', [ 'class' => 'cb-core-feature-chevron'",
        "Core Shield feature icon boundary",
    )

    # Re-resolve indexes after the icon rewrites changed physical line numbers.
    badge_index = unique_index(
        "UI::render_badges( $feature_badges )",
        "Core Shield feature badges boundary",
    )
    state_index = unique_index(
        "UI\\StateBadge::render( __( 'Delegated'",
        "Core Shield delegated StateBadge boundary",
    )

    if state_index <= badge_index:
        raise RuntimeError("Core Shield badge/state boundary order is unexpected.")

    # The earlier mechanical passes left a recognizable PHPCS-only tangle
    # around these two calls. Assert that every extra PHP-only line in the
    # replacement window is one of our PHPCS markers or an empty PHP tag.
    start = badge_index
    while start > 0 and (
        "phpcs:" in lines[start - 1]
        or lines[start - 1].strip() in {"<?php ?>", "<?php", "?>"}
    ):
        start -= 1

    end = state_index
    if "phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped" not in lines[state_index]:
        raise RuntimeError("Core Shield StateBadge line is not the audited pre-patch form.")

    between = lines[start:state_index]
    if_line = next(
        (line for line in between if "if ( $delegated_label )" in line),
        None,
    )
    div_line = next(
        (line for line in between if 'class="cb-core-feature-delegated"' in line),
        None,
    )
    if if_line is None or div_line is None:
        raise RuntimeError("Core Shield delegated wrapper was not found in the audited window.")

    badge_indent = re.match(r"^\\s*", lines[badge_index]).group(0)
    state_indent = re.match(r"^\\s*", lines[state_index]).group(0)
    badge_echo = lines[badge_index].split("// phpcs:ignore", 1)[0].rstrip()
    state_echo = lines[state_index].split("?>", 1)[0].rstrip() + " ?>"

    lines[start:end + 1] = [
        badge_indent + "<?php " + DISABLE
        + "UI::render_badges() escapes URLs, attributes, and visible labels for every supported badge type. ?>",
        badge_indent + badge_echo.lstrip(),
        badge_indent + "<?php " + ENABLE + " ?>",
        "",
        if_line,
        div_line,
        state_indent + "<?php " + DISABLE
        + "StateBadge::render() owns context-specific escaping for its complete structured payload. ?>",
        state_indent + state_echo.lstrip(),
        state_indent + "<?php " + ENABLE + " ?>",
    ]

    updated = "\\n".join(lines)
    if text.endswith("\\n"):
        updated += "\\n"
    return updated

def patch_appearance(text: str) -> str:
    lines = text.splitlines()
    matches = [
        i for i, line in enumerate(lines)
        if "Themes::sanitize_preview_svg(" in line and "echo " in line
    ]
    if 1 != len(matches):
        raise RuntimeError(
            f"Appearance SVG boundary: expected one sanitizer output, found {len(matches)}."
        )

    index = matches[0]
    indent = re.match(r"^\s*", lines[index]).group(0)
    lines[index:index + 1] = [
        indent + "<?php " + DISABLE
        + "sanitize_preview_svg() applies wp_kses() with Core Blueprint's bounded SVG allowlist immediately before output. ?>",
        lines[index],
        indent + "<?php " + ENABLE + " ?>",
    ]

    updated = "\n".join(lines)
    if text.endswith("\n"):
        updated += "\n"
    return updated


def secure_action_callers() -> list[tuple[str, int, str]]:
    callers: list[tuple[str, int, str]] = []
    needle = "SecureActionScreen::render"
    excluded = {".git", "vendor", "node_modules", "dist", "build"}

    for path in ROOT.rglob("*.php"):
        rel = path.relative_to(ROOT)
        if any(part in excluded for part in rel.parts):
            continue
        try:
            lines = path.read_text(encoding="utf-8").splitlines()
        except UnicodeDecodeError:
            continue
        for line_no, line in enumerate(lines, 1):
            if needle in line:
                callers.append((rel.as_posix(), line_no, line.strip()))
    return callers


def prepare() -> dict[str, str]:
    updated: dict[str, str] = {}
    for rel, patcher in PATCHERS.items():
        path = ROOT / rel
        source = path.read_text(encoding="utf-8")
        updated[rel] = patcher(source)
    return updated


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--scan-json", required=True)
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    scan_path = Path(args.scan_json).expanduser().resolve()
    if not scan_path.is_file():
        parser.error(f"scan JSON not found: {scan_path}")

    found = load_scan(scan_path)
    verify_scan(found)
    callers = secure_action_callers()

    print("AUDITED BASELINE: 24 OutputNotEscaped findings in 5 files")
    print("PATCHABLE FILES: 4")
    print("PATCHABLE BOUNDARIES: 8")
    print("PATCHABLE FINDINGS: 22")
    print("DEFERRED SECURE-ACTION FINDINGS: 2")
    print("SECURE-ACTION CALLERS:")
    if callers:
        for path, line_no, line in callers:
            print(f"  {path}:{line_no}: {line}")
    else:
        print("  NONE FOUND")

    updated = prepare()

    if not args.apply:
        print("DRY RUN: no files changed.")
        return 0

    for rel, content in updated.items():
        (ROOT / rel).write_text(content, encoding="utf-8")

    print("APPLIED FILES: 4")
    print("APPLIED BOUNDARIES: 8")
    print("COVERED FINDINGS: 22")
    print("SecureActionScreen remains intentionally unchanged pending caller audit.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
