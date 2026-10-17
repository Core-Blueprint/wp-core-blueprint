"""A2.2: prove the canonical Level 2 runner actually invokes the real SQL gate.

Source-only. No WordPress, Docker, database, network or test-fixture execution.
The independently authorized Level 2 run supplies runtime evidence later.
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
CLI = ROOT / "tests" / "bin" / "run-cli-smoke-scenario.sh"
FIXTURE = ROOT / "tests" / "bin" / "design-document-migration-conformance.php"
RUNNER = ROOT / "tools" / "integration" / "execution.py"


class DesignDocumentMigrationCliGateTest(unittest.TestCase):
    def test_fixture_is_called_after_real_wp_cli_install(self):
        shell = CLI.read_text(encoding="utf-8")
        self.assertIn("wp_cli plugin activate core-blueprint", shell)
        self.assertIn(
            'wp_cli eval-file "$ROOT/tests/bin/design-document-migration-conformance.php"',
            shell,
        )
        self.assertLess(
            shell.index("wp_cli plugin activate core-blueprint"),
            shell.index("design-document-migration-conformance.php"),
        )
        self.assertIn("[A2.2] final prepare-only conformance PASS", shell)

    def test_canonical_level2_runs_the_cli_gate(self):
        runner = RUNNER.read_text(encoding="utf-8")
        self.assertIn('"tests/bin/run-cli-smoke-scenario.sh"', runner)

    def test_fixture_rejects_non_isolated_database_and_proves_rollback(self):
        source = FIXTURE.read_text(encoding="utf-8")
        for required in (
            "CB_BASE_TEST_SLOT",
            "core_blueprint_base_",
            "CB_CLI_TABLE_PREFIX",
            "Migration::prepare()",
            "Migration::freshness()",
            "MigrationBackup::read_verified()",
            "START TRANSACTION",  # fixture description, no manual transaction
            "CREATE TRIGGER",
            "SIGNAL SQLSTATE '45000'",
            "partial inserts fully rolled back",
            "source_changed",
            "target_changed",
            "no active cutover marker",
            "preexisting target-row conflict",
            "cb_a22_clear_preparation",
        ):
            with self.subTest(required=required):
                self.assertIn(required, source)

    def test_isolation_guard_accepts_original_and_new_one_shot_retry_slots(self):
        source = FIXTURE.read_text(encoding="utf-8")
        match = re.search(r"preg_match\( '(/[^']+/D)', \$slot \)", source)
        self.assertIsNotNone(match, "PHP slot guard must have a testable explicit regex")
        # PHP's /D modifier requires '
        # WP-CLI executes eval-file inside eval(); a strict_types directive
        # is not permitted here and would abort before the fixture starts.
        source = FIXTURE.read_text(encoding="utf-8")
        self.assertTrue(source.startswith("<?php\n"))
        self.assertNotIn("declare(strict_types=1)", source)

    def test_fixture_and_runner_do_not_expose_public_migration_endpoint(self):
        source = FIXTURE.read_text(encoding="utf-8")
        self.assertNotIn("register_rest_route(", source)
        self.assertNotIn("register_activation_hook(", source)
        self.assertNotIn("wp_ajax_cb_core_design_docs", source)


if __name__ == "__main__":
    unittest.main()
 to match the absolute end, which
        # corresponds to Python fullmatch. Test the exact pattern from PHP.
        php_regex = match.group(1)
        matcher = re.compile(php_regex[1:-2])
        for slot in (
            "g1d_a22_20261017",
            "g1d_a22r1_20261017",
            "g1d_a22r2_20261017",
        ):
            with self.subTest(approved_slot=slot):
                self.assertIsNotNone(matcher.fullmatch(slot))
        for slot in (
            "g1d_a22r0_20261017",
            "g1d_a22foo_20261017",
            "g1d_a22r2_20261017_extra",
            "g1d_a22_20261017/other",
        ):
            with self.subTest(rejected_slot=slot):
                self.assertIsNone(matcher.fullmatch(slot))

    def test_isolation_guards_identify_failing_clause_without_printing_values(self):
        source = FIXTURE.read_text(encoding="utf-8")
        self.assertIn("'approved A2.2 test slot'", source)
        self.assertIn("'isolated database name'", source)
        self.assertIn("'isolated test workspace'", source)
        self.assertIn("'loopback MariaDB binding'", source)
        self.assertIn("'isolated WordPress table prefix'", source)
        self.assertIn("'real WP-CLI runtime'", source)
        self.assertIn("cb_a22_assert( $passes, 'isolation guard: ' . $label )", source)

    def test_eval_file_does_not_use_strict_types_directive(self):
        # WP-CLI executes eval-file inside eval(); a strict_types directive
        # is not permitted here and would abort before the fixture starts.
        source = FIXTURE.read_text(encoding="utf-8")
        self.assertTrue(source.startswith("<?php\n"))
        self.assertNotIn("declare(strict_types=1)", source)

    def test_fixture_and_runner_do_not_expose_public_migration_endpoint(self):
        source = FIXTURE.read_text(encoding="utf-8")
        self.assertNotIn("register_rest_route(", source)
        self.assertNotIn("register_activation_hook(", source)
        self.assertNotIn("wp_ajax_cb_core_design_docs", source)


if __name__ == "__main__":
    unittest.main()
