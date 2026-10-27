"""Read-only clean-v1 WP-CLI smoke source contract."""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
CLI = ROOT / "tests" / "bin" / "run-cli-smoke-scenario.sh"
RUNNER = ROOT / "tools" / "integration" / "execution.py"


class NativeV1CliGateTest(unittest.TestCase):
    def test_clean_native_authority_is_read_after_activation(self):
        shell = CLI.read_text(encoding="utf-8")
        self.assertIn("wp_cli plugin activate core-blueprint", shell)
        self.assertIn("Authority::inspect()", shell)
        self.assertIn("Repository::get(", shell)
        self.assertIn('native_authority" != "active"', shell)
        self.assertIn('native_reports" != "missing"', shell)
        self.assertLess(
            shell.index("wp_cli plugin activate core-blueprint"),
            shell.index("Authority::inspect()"),
        )
        self.assertIn("[B0] real WP-CLI smoke scenario PASS", shell)
        for forbidden in (
            "Migration::prepare(", "MigrationBackup::",
            "migration-conformance.php", "db reset", "db drop", "wp_cli eval-file",
        ):
            self.assertNotIn(forbidden, shell)

    def test_level2_keeps_isolated_cli_gate(self):
        runner = RUNNER.read_text(encoding="utf-8")
        self.assertIn('"tests/bin/run-cli-smoke-scenario.sh"', runner)


if __name__ == "__main__":
    unittest.main()
