"""Clean-v1 source fences for read-only admission and native Save.

No WordPress, MariaDB, migration execution or source modification.
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

    def test_authority_has_no_legacy_migration_dependencies_or_writes(self):
        source = self._without_line_comments(self.authority)
        for required in (
            "Schema::healthy()", "get_current_blog_id()", "$wpdb->options",
            "cb_core_design_docs_prepared_v1", "cb_core_design_docs_migration_backup_v1",
            "cb_core_design_docs_cutover", "cb_core_design_docs_ever_active_v1",
            "TemplateRepository::OPTION", "reports_design_is_default",
        ):
            self.assertIn(required, source)
        for prohibited in (
            "Migration::", "MigrationInventory::", "MigrationBackup::",
            "update_option(", "add_option(", "delete_option(",
            "START TRANSACTION", "wp_ajax_", "register_rest_route(",
            "get_option(", "Repository::create(",
        ):
            self.assertNotIn(prohibited, source)

    def test_rest_context_save_and_create_all_require_authority(self):
        # Context and pre-save use verified resource identity. The immutable
        # SaveEnvelope type is also checked by the transaction callbacks.
        self.assertEqual(self.mail.count("Authority::inspect( $identity['type'] )"), 2)
        self.assertEqual(self.mail.count("Authority::inspect( $item->resource_type )"), 2)
        self.assertNotIn("Authority::inspect()", self.mail)
        self.assertIn("'active' === ( Authority::inspect( $item->resource_type )['status'] ?? 'blocked' )", self.mail)
        self.assertIn("self::valid_content( $doc['project'], $doc['fields'], $registry, $profile )", self.mail)
        self.assertIn("$revision = '0';", self.mail)
        self.assertIn("Mail::default_for_resource( $resource )", self.mail)

    def test_native_reports_reader_denies_conflicts(self):
        self.assertIn("Authority::inspect( Repository::REPORTS )", self.reports)
        self.assertNotIn("Authority::inspect()", self.reports)
        self.assertIn("return [ 'status' => 'blocked' ];", self.reports)
        self.assertIn("PlatformAdapter::default_project()", self.reports)
        self.assertIn("PlatformAdapter::default_fields()", self.reports)

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
