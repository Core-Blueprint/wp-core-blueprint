"""Source-only A2.3a checkpoint A guards. No WP, MariaDB or Docker.

This does not replace separately authorized DB-backed, editor or PDF tests.
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
DOC = ROOT / "src" / "Design" / "Document"
MAIL = ROOT / "src" / "Mail" / "Designer" / "DocumentAdapter.php"
REPORTS = ROOT / "src" / "Reports" / "DesignDocumentAdapter.php"


class A23aCheckpointASourceContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.authority = (DOC / "Authority.php").read_text(encoding="utf-8")
        cls.mail = MAIL.read_text(encoding="utf-8")
        cls.reports = REPORTS.read_text(encoding="utf-8")

    def test_authority_does_not_mutate_or_auto_activate(self):
        source = re.sub(r"//[^\\n]*", "", self.authority)
        self.assertIn("cb_core_design_docs_cutover", source)
        self.assertIn("Migration::PREPARED_OPTION", source)
        self.assertIn("MigrationInventory::read_option", source)
        for prohibited in (
            "update_option(",
            "add_option(",
            "delete_option(",
            "Migration::prepare(",
            "Migration::freshness(",
            "START TRANSACTION",
            "wp_ajax_",
            "register_rest_route(",
        ):
            self.assertNotIn(prohibited, source)

    def test_marker_is_site_bound_and_checked_without_cache(self):
        for requirement in (
            "get_current_blog_id()",
            "options_table",
            "$wpdb->options",
            "autoload",
            "fingerprint",
            "target_hash",
            "'prepared'",
            "'active'",
            "'legacy'",
            "'blocked'",
        ):
            with self.subTest(requirement=requirement):
                self.assertIn(requirement, self.authority)
        self.assertNotIn("get_option(", re.sub(r"//[^\n]*", "", self.authority))

    def test_missing_mail_row_requires_matching_verified_raw_backup(self):
        for requirement in (
            "MigrationBackup::read_verified()",
            "Repository::valid_identity( Repository::MAIL",
            "hash_equals(",
            "maybe_unserialize(",
            "array_key_exists( $id, $decoded )",
            "TemplateRepository::OPTION",
        ):
            with self.subTest(requirement=requirement):
                self.assertIn(requirement, self.authority)
        self.assertIn("! Authority::uncustomized_mail_id", self.mail)

    def test_mail_revision_zero_create_and_versioned_reset(self):
        for requirement in (
            "'revision' => '0'",
            "Repository::create( Repository::MAIL",
            "Repository::save( Repository::MAIL",
            "Repository::reset( Repository::MAIL",
            "Repository::valid_revision(",
            "new Validator()",
        ):
            with self.subTest(requirement=requirement):
                self.assertIn(requirement, self.mail)
        self.assertNotIn("update_option(", self.mail)
        self.assertNotIn("delete_option(", self.mail)

    def test_reports_one_cas_document_no_default_on_missing(self):
        for requirement in (
            "'branding' => $branding",
            "'composer' => [ 'maintenance' => $composer ]",
            "Repository::save( Repository::REPORTS",
            "Repository::reset( Repository::REPORTS",
            "MaintenanceTemplate::normalize(",
            "ReportBrandingInput::normalize(",
            "'status' => 'missing' === ",
        ):
            with self.subTest(requirement=requirement):
                self.assertIn(requirement, self.reports)
        self.assertNotIn("Settings::set_key(", self.reports)
        self.assertNotIn("update_option(", self.reports)


if __name__ == "__main__":
    unittest.main()
