#!/usr/bin/env python3
"""One-time pre-v1 public contract prefix migration for Core Blueprint Base.

Migrates Base-owned public procedural contracts to the canonical suite prefix:
    cb_core_* -> core_blueprint_*
    selected legacy cb_* hooks -> core_blueprint_*

This tool intentionally uses an explicit allowlist derived from the canonical
Plugin Check audit. It does not rewrite generic option keys, meta keys, CSS/JS
identifiers, database identifiers, or arbitrary cb_core_* strings.

Usage:
    python3 tools/migrate-coreblueprint-public-contracts.py
    python3 tools/migrate-coreblueprint-public-contracts.py --apply
    python3 tools/migrate-coreblueprint-public-contracts.py --check
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

HOOK_RENAMES = {
    'cb_core_register_interoperability_implementations': 'core_blueprint_register_interoperability_implementations',
    'cb_core_integrity_locale_detection_inconclusive': 'core_blueprint_integrity_locale_detection_inconclusive',
    'cb_core_two_factor_interactive_login_request': 'core_blueprint_two_factor_interactive_login_request',
    'cb_core_register_interoperability_contracts': 'core_blueprint_register_interoperability_contracts',
    'cb_core_register_automation_capabilities': 'core_blueprint_register_automation_capabilities',
    'cb_core_register_mail_sender_identities': 'core_blueprint_register_mail_sender_identities',
    'cb_permissions_operator_guard_triggered': 'core_blueprint_permissions_operator_guard_triggered',
    'cb_core_compliance_document_mime_types': 'core_blueprint_compliance_document_mime_types',
    'cb_core_register_user_profile_sections': 'core_blueprint_register_user_profile_sections',
    'cb_core_module_activation_definitions': 'core_blueprint_module_activation_definitions',
    'cb_core_register_compliance_resources': 'core_blueprint_register_compliance_resources',
    'cb_core_access_mode_settings_changed': 'core_blueprint_access_mode_settings_changed',
    'cb_core_snippets_conflicting_plugins': 'core_blueprint_snippets_conflicting_plugins',
    'cb_core_content_models_field_groups': 'core_blueprint_content_models_field_groups',
    'cb_core_content_models_option_pages': 'core_blueprint_content_models_option_pages',
    'cb_core_login_shield_test_sslverify': 'core_blueprint_login_shield_test_sslverify',
    'cb_core_media_formats_svg_max_bytes': 'core_blueprint_media_formats_svg_max_bytes',
    'cb_core_access_mode_bypass_request': 'core_blueprint_access_mode_bypass_request',
    'cb_core_automation_trigger_emitted': 'core_blueprint_automation_trigger_emitted',
    'cb_core_hud_menu_manage_capability': 'core_blueprint_hud_menu_manage_capability',
    'cb_core_content_models_post_types': 'core_blueprint_content_models_post_types',
    'cb_core_content_models_taxonomies': 'core_blueprint_content_models_taxonomies',
    'cb_core_dashboard_card_shortcuts_': 'core_blueprint_dashboard_card_shortcuts_',
    'cb_core_integrity_locale_detected': 'core_blueprint_integrity_locale_detected',
    'cb_core_module_status_definitions': 'core_blueprint_module_status_definitions',
    'cb_core_register_profile_sections': 'core_blueprint_register_profile_sections',
    'cb_core_snippets_conditions_match': 'core_blueprint_snippets_conditions_match',
    'cb_admin_theme_screen_registered': 'core_blueprint_admin_theme_screen_registered',
    'cb_core_dashboard_card_shortcuts': 'core_blueprint_dashboard_card_shortcuts',
    'cb_core_dashboard_register_cards': 'core_blueprint_dashboard_register_cards',
    'cb_core_forms_submission_emitted': 'core_blueprint_forms_submission_emitted',
    'cb_core_register_mail_components': 'core_blueprint_register_mail_components',
    'cb_core_two_factor_authenticated': 'core_blueprint_two_factor_authenticated',
    'cb_core_content_models_register': 'core_blueprint_content_models_register',
    'cb_core_design_mail_render_node': 'core_blueprint_design_mail_render_node',
    'cb_core_hud_excluded_post_types': 'core_blueprint_hud_excluded_post_types',
    'cb_core_quarantine_vault_parent': 'core_blueprint_quarantine_vault_parent',
    'cb_core_register_mail_templates': 'core_blueprint_register_mail_templates',
    'cb_core_reports_available_types': 'core_blueprint_reports_available_types',
    'cb_core_snippets_condition_rule': 'core_blueprint_snippets_condition_rule',
    'cb_maintenance_report_generated': 'core_blueprint_maintenance_report_generated',
    'cb_permissions_operator_removed': 'core_blueprint_permissions_operator_removed',
    'cb_core_cli_version_components': 'core_blueprint_cli_version_components',
    'cb_core_design_mail_node_types': 'core_blueprint_design_mail_node_types',
    'cb_core_locale_default_changed': 'core_blueprint_locale_default_changed',
    'cb_core_register_mail_bindings': 'core_blueprint_register_mail_bindings',
    'cb_core_reports_backup_sources': 'core_blueprint_reports_backup_sources',
    'cb_core_system_log_event_types': 'core_blueprint_system_log_event_types',
    'cb_core_cli_register_commands': 'core_blueprint_cli_register_commands',
    'cb_core_header_test_sslverify': 'core_blueprint_header_test_sslverify',
    'cb_hud_register_section_types': 'core_blueprint_hud_register_section_types',
    'cb_permissions_operator_added': 'core_blueprint_permissions_operator_added',
    'cb_console_register_commands': 'core_blueprint_console_register_commands',
    'cb_core_failsafe_is_bypassed': 'core_blueprint_failsafe_is_bypassed',
    'cb_core_hud_default_position': 'core_blueprint_hud_default_position',
    'cb_core_snippets_storage_dir': 'core_blueprint_snippets_storage_dir',
    'cb_maintenance_report_failed': 'core_blueprint_maintenance_report_failed',
    'cb_admin_theme_body_classes': 'core_blueprint_admin_theme_body_classes',
    'cb_core_admin_theme_changed': 'core_blueprint_admin_theme_changed',
    'cb_core_alert_recipient': 'core_blueprint_alert_recipient',
    'cb_core_audit_log_written': 'core_blueprint_audit_log_written',
    'cb_core_capability_catalog': 'core_blueprint_capability_catalog',
    'cb_core_content_models_register': 'core_blueprint_content_models_register',
    'cb_core_csp_report_only': 'core_blueprint_csp_report_only',
    'cb_core_export_extensions': 'core_blueprint_export_extensions',
    'cb_core_export_mime_types': 'core_blueprint_export_mime_types',
    'cb_core_header_test_sslverify': 'core_blueprint_header_test_sslverify',
    'cb_core_hud_default_brand': 'core_blueprint_hud_default_brand',
    'cb_core_hud_default_ghost': 'core_blueprint_hud_default_ghost',
    'cb_core_hud_enabled': 'core_blueprint_hud_enabled',
    'cb_core_hud_show_comments': 'core_blueprint_hud_show_comments',
    'cb_core_locale_allowed': 'core_blueprint_locale_allowed',
    'cb_core_locale_labels': 'core_blueprint_locale_labels',
    'cb_core_mail_transports': 'core_blueprint_mail_transports',
    'cb_core_maintenance_sources': 'core_blueprint_maintenance_sources',
    'cb_core_media_replaced': 'core_blueprint_media_replaced',
    'cb_core_menu_capability': 'core_blueprint_menu_capability',
    'cb_core_permissions_policy': 'core_blueprint_permissions_policy',
    'cb_core_register_brands': 'core_blueprint_register_brands',
    'cb_core_register_extensions': 'core_blueprint_register_extensions',
    'cb_core_register_pages': 'core_blueprint_register_pages',
    'cb_core_register_settings': 'core_blueprint_register_settings',
    'cb_core_report_notes': 'core_blueprint_report_notes',
    'cb_core_report_security': 'core_blueprint_report_security',
    'cb_core_reports_tabs': 'core_blueprint_reports_tabs',
    'cb_core_role_delete_reasons': 'core_blueprint_role_delete_reasons',
    'cb_core_snippets_safe_mode': 'core_blueprint_snippets_safe_mode',
    'cb_core_two_factor_authenticated': 'core_blueprint_two_factor_authenticated',
    'cb_admin_theme_apply': 'core_blueprint_admin_theme_apply',
    'cb_admin_theme_enqueue': 'core_blueprint_admin_theme_enqueue',
    'cb_admin_themes': 'core_blueprint_admin_themes',
    'cb_core_access_mode_changed': 'core_blueprint_access_mode_changed',
    'cb_core_booted': 'core_blueprint_booted',
    'cb_core_export_formats': 'core_blueprint_export_formats',
    'cb_core_export_': 'core_blueprint_export_',
    'cb_core_brand_themes_': 'core_blueprint_brand_themes_',
    'cb_core_reports_render_tab_': 'core_blueprint_reports_render_tab_',
    'cb_core_hud_capability': 'core_blueprint_hud_capability',
    'cb_core_modules': 'core_blueprint_modules',
    'cb_hud_header_actions': 'core_blueprint_hud_header_actions',
    'cb_hud_register_items': 'core_blueprint_hud_register_items',
    'cb_hud_register_sections': 'core_blueprint_hud_register_sections',
}

IDENTIFIER_RENAMES = {
    "cb_core_get_requirement_errors": "core_blueprint_get_requirement_errors",
    "cb_core_loaded_file": "core_blueprint_loaded_file",
    "cb_core_errors": "core_blueprint_errors",
}

DYNAMIC_HOOK_PREFIXES = {
    "cb_core_brand_themes_",
    "cb_core_dashboard_card_shortcuts_",
    "cb_core_export_",
    "cb_core_reports_render_tab_",
}

TOKEN_CHARS = r"A-Za-z0-9_"


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


def replace_exact_token(text: str, old: str, new: str) -> tuple[str, int]:
    pattern = re.compile(
        rf"(?<![{TOKEN_CHARS}]){re.escape(old)}(?![{TOKEN_CHARS}])"
    )
    return pattern.subn(new, text)


def replace_dynamic_prefix(text: str, old: str, new: str) -> tuple[str, int]:
    pattern = re.compile(rf"(?<![{TOKEN_CHARS}]){re.escape(old)}")
    return pattern.subn(new, text)


def transform(text: str) -> tuple[str, dict[str, int]]:
    updated = text
    counts: dict[str, int] = {}

    for old, new in HOOK_RENAMES.items():
        if old in DYNAMIC_HOOK_PREFIXES:
            updated, count = replace_dynamic_prefix(updated, old, new)
        else:
            updated, count = replace_exact_token(updated, old, new)
        if count:
            counts[old] = count

    for old, new in IDENTIFIER_RENAMES.items():
        updated, count = replace_exact_token(updated, old, new)
        if count:
            counts[old] = counts.get(old, 0) + count

    return updated, counts


def inspect() -> tuple[list[tuple[Path, dict[str, int]]], int]:
    affected: list[tuple[Path, dict[str, int]]] = []
    total = 0

    for path in candidate_files():
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue

        _updated, counts = transform(text)
        if counts:
            affected.append((path, counts))
            total += sum(counts.values())

    return affected, total


def apply() -> tuple[int, int]:
    affected, _total = inspect()
    replacements = 0

    for path, _counts in affected:
        text = path.read_text(encoding="utf-8")
        updated, counts = transform(text)
        count = sum(counts.values())
        if not count:
            continue

        path.write_text(updated, encoding="utf-8")
        replacements += count

    return len(affected), replacements


def print_report(affected: list[tuple[Path, dict[str, int]]], total: int) -> None:
    for path, counts in affected:
        detail = ", ".join(f"{name}={count}" for name, count in sorted(counts.items()))
        print(f"{path.relative_to(ROOT)}: {sum(counts.values())} [{detail}]")

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
            print("FAIL: legacy public contract references remain after apply.", file=sys.stderr)
            print_report(remaining, total)
            return 1

        print("PASS: no audited legacy public contract references remain in owned text files.")
        return 0

    affected, total = inspect()

    if args.check:
        if affected:
            print("FAIL: legacy public contract references remain.", file=sys.stderr)
            print_report(affected, total)
            return 1

        print("PASS: no audited legacy public contract references remain in owned text files.")
        return 0

    print_report(affected, total)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
