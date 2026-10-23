"""A2.3a document-authority invariants after native Designer adoption.

The retired Mail DocumentAdapter is not part of Base Platform v1.
These read-only checks verify the preserved cutover authority and the new
single native Mail/Reports transaction authority, not legacy write paths.
No WordPress, MariaDB, Docker or source mutation is required.
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
DOC = ROOT / "src" / "Design" / "Document"
MAIL = ROOT / "src" / "Design" / "Editor" / "PlatformTransport.php"
PORT = ROOT / "src" / "Design" / "Persistence" / "BaseDocumentRepositoryPort.php"
REPORTS = ROOT / "src" / "Reports" / "DesignDocumentAdapter.php"


class A23aCheckpointASourceContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.authority = (DOC / "Authority.php").read_text(encoding="utf-8")
        cls.mail = MAIL.read_text(encoding="utf-8")
        cls.port = PORT.read_text(encoding="utf-8")
        cls.reports = REPORTS.read_text(encoding="utf-8")

    @staticmethod
    def _without_line_comments(source):
        return re.sub(r"(?m)^[ \t]*//[^\n]*", "", source)

    def test_comment_filter_is_non_destructive(self):
        fixture = "// Migration::freshness() does not run here\n$actual = get_option('x');\n"
        stripped = self._without_line_comments(fixture)
        self.assertNotIn("Migration::freshness(", stripped)
        self.assertIn("get_option(", stripped)

    def test_authority_does_not_mutate_or_auto_activate(self):
        source = self._without_line_comments(self.authority)
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
        self.assertNotIn("get_option(", self._without_line_comments(self.authority))

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
        # The historical migration authority still verifies that a missing
        # row was never customized. Native Host context is a separate,
        # read-only default view, not a revived legacy migration writer.
        self.assertIn("Mail::default_for_resource( $resource )", self.mail)
        self.assertIn("'revision'", self.mail)
        self.assertIn("$revision = '0';", self.mail)
        self.assertIn("self::valid_content( $doc['project'], $doc['fields'], $registry, $profile )", self.mail)

    def test_native_mail_create_requires_revision_zero_validated_identity(self):
        for requirement in (
            "Repository::MAIL",
            "'0' === $item->expected_revision",
            "self::identity( $item->profile_id, $item->resource_key ) !== null",
            "self::valid_native_document( $item->resource_type",
            "new BaseDocumentRepositoryPort(",
            "new TransactionalSave(",
            "SaveEnvelope::canonical_json(",
            "Mail::default_for_resource( $resource )",
        ):
            with self.subTest(requirement=requirement):
                self.assertIn(requirement, self.mail)

        self.assertIn("Repository::write_in_transaction(", self.port)
        self.assertNotIn("Repository::save( Repository::MAIL", self.mail)
        self.assertNotIn("Repository::create( Repository::MAIL", self.mail)
        self.assertNotIn("update_option(", self.mail)
        self.assertNotIn("delete_option(", self.mail)

    def test_native_reports_missing_default_is_read_only_and_legacy_writer_blocked(self):
        for requirement in (
            "'missing' === ( $read['status'] ?? null )",
            "PlatformAdapter::default_project()",
            "PlatformAdapter::default_fields()",
            "NativeComposition::assert_document( $document )",
            "Repository::get( Repository::REPORTS, self::KEY )",
            "public static function save(",
            "public static function reset(",
            "return [ 'status' => 'blocked' ];",
        ):
            with self.subTest(requirement=requirement):
                self.assertIn(requirement, self.reports)

        self.assertIn("Repository::REPORTS", self.mail)
        self.assertIn("new TransactionalSave(", self.mail)
        self.assertNotIn("Repository::save( Repository::REPORTS", self.reports)
        self.assertNotIn("Repository::reset( Repository::REPORTS", self.reports)
        self.assertNotIn("Settings::set_key(", self.reports)
        self.assertNotIn("update_option(", self.reports)


if __name__ == "__main__":
    unittest.main()
