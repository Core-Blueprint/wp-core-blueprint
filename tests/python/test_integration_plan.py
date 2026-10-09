"""B2b: contract tests for a read-only Base integration execution plan."""
from contextlib import redirect_stderr, redirect_stdout
from io import StringIO
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

from tools.integration import plan, preflight


class BaseIntegrationPlanTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)

    def test_default_database_is_not_legacy_wordpress_test(self):
        self.assertEqual("core_blueprint_base_test", plan.DATABASE)
        self.assertEqual("127.0.0.1:3307", plan.DATABASE_HOST)

    def test_database_query_is_constant_read_only_information_schema(self):
        self.assertTrue(plan.QUERY.startswith("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA "))
        self.assertNotIn("DROP", plan.QUERY)
        self.assertNotIn("CREATE", plan.QUERY)
        with mock.patch.object(plan, "read_only", return_value="") as called:
            self.assertFalse(plan.database_exists())
        command = called.call_args.args[0]
        self.assertEqual(command[:3], ["docker", "exec", "cb-base-test-db"])
        self.assertEqual(command[-2:], ["-e", plan.QUERY])
        self.assertNotIn("wordpress_test", plan.QUERY)

    def test_existing_database_is_detected_not_claimed(self):
        with mock.patch.object(plan, "read_only", return_value="core_blueprint_base_test\n"):
            self.assertTrue(plan.database_exists())

    def test_unexpected_database_query_response_fails_closed(self):
        with mock.patch.object(plan, "read_only", return_value="wrong_db\n"):
            with self.assertRaisesRegex(preflight.PreflightError, "Unrecognised"):
                plan.database_exists()

    def test_database_inventory_read_failure_fails_closed(self):
        with mock.patch.object(plan, "read_only", side_effect=preflight.PreflightError("failed")):
            with self.assertRaises(preflight.PreflightError):
                plan.database_exists()

    def test_wordpress_version_is_allowlisted(self):
        self.assertEqual("7.0", plan.validate_version("7.0"))
        self.assertEqual("7.1", plan.validate_version("7.1"))
        for version in ("6.9", "7.2", "", "7.0;rm", "latest"):
            with self.subTest(version=version):
                with self.assertRaises(preflight.PreflightError):
                    plan.validate_version(version)

    def test_missing_required_files_are_refused(self):
        with self.assertRaisesRegex(preflight.PreflightError, "Missing"):
            plan.verify_source(self.root)

    def test_source_verification_uses_read_only_php_and_git(self):
        for file in plan.REQUIRED:
            f = self.root / file
            f.parent.mkdir(parents=True, exist_ok=True)
            f.write_text("fixture")
        with mock.patch.object(plan, "read_only", return_value="") as run:
            plan.verify_source(self.root)
        self.assertEqual(2, run.call_count)
        self.assertEqual(["php", "-r"], run.call_args_list[0].args[0][:2])
        git = run.call_args_list[1].args[0]
        self.assertEqual(git[:4], ["git", "-C", str(self.root), "status"])
        self.assertIn("--porcelain", git)

    def test_dirty_git_source_is_refused(self):
        for file in plan.REQUIRED:
            f = self.root / file
            f.parent.mkdir(parents=True, exist_ok=True)
            f.write_text("fixture")
        with mock.patch.object(plan, "read_only", side_effect=["", " M core-blueprint.php\n"]):
            with self.assertRaisesRegex(preflight.PreflightError, "uncommitted"):
                plan.verify_source(self.root)

    def test_read_only_command_disables_git_optional_locks(self):
        result = mock.Mock(returncode=0, stdout="ok\n")
        with mock.patch.object(plan.subprocess, "run", return_value=result) as called:
            self.assertEqual("ok\n", plan.read_only(["git", "status"], "git"))
        kw = called.call_args.kwargs
        self.assertEqual("0", kw["env"]["GIT_OPTIONAL_LOCKS"])
        self.assertTrue(kw["capture_output"])
        self.assertFalse(kw["check"])

    def test_failed_read_only_command_is_error(self):
        with mock.patch.object(plan.subprocess, "run",
                               return_value=mock.Mock(returncode=4, stdout="", stderr="blocked")):
            with self.assertRaisesRegex(preflight.PreflightError, "failed"):
                plan.read_only(["docker", "inspect"], "docker")

    def mock_live_checks(self, database_exists=False, warnings=()):
        return [
            mock.patch.object(plan.preflight, "validate_test_root", return_value="absent"),
            mock.patch.object(plan, "verify_source"),
            mock.patch.object(plan.preflight, "read_source_commit", return_value="a" * 40),
            mock.patch.object(plan.preflight, "read_docker_metadata", return_value=[]),
            mock.patch.object(plan.preflight, "validate_docker_metadata",
                              return_value=(list(warnings), "existing MariaDB")),
            mock.patch.object(plan, "database_exists", return_value=database_exists),
        ]

    def run_mock_dry_run(self, database_exists=False, warnings=()):
        patches = self.mock_live_checks(database_exists, warnings)
        with patches[0], patches[1], patches[2], patches[3], patches[4], patches[5]:
            stdout, stderr = StringIO(), StringIO()
            with redirect_stdout(stdout), redirect_stderr(stderr):
                rc = plan.dry_run()
        return rc, stdout.getvalue(), stderr.getvalue()

    def test_dry_run_reports_unexecuted_full_phpunit_and_exact_commit(self):
        rc, stdout, stderr = self.run_mock_dry_run()
        self.assertEqual(0, rc, stderr)
        self.assertIn("PASS (read-only inspection, not integration)", stdout)
        self.assertIn("a" * 40, stdout)
        self.assertIn("FULL vendor/bin/phpunit", stdout)
        self.assertIn("--fail-on-skipped --fail-on-incomplete", stdout)
        self.assertIn("WordPress installation: NOT RUN; PHPUnit: NOT RUN", stdout)
        self.assertIn("Execution: BLOCKED", stdout)

    def test_dry_run_collision_blocks_before_execution(self):
        rc, stdout, stderr = self.run_mock_dry_run(database_exists=True)
        self.assertEqual(1, rc)
        self.assertIn("existing database must be protected", stdout)
        self.assertIn("Execution: BLOCKED", stdout)

    def test_dry_run_surfaces_open_network_and_persistent_volume_warnings(self):
        rc, stdout, stderr = self.run_mock_dry_run(
            warnings=["MariaDB is bound beyond loopback", "persistent mounts unverified"]
        )
        self.assertEqual(0, rc)
        self.assertIn("beyond loopback", stderr)
        self.assertIn("persistent mounts unverified", stderr)

    def test_read_only_preflight_blocker_prevents_database_lookup(self):
        with mock.patch.object(plan.preflight, "validate_test_root",
                               side_effect=preflight.PreflightError("symlink")), \
             mock.patch.object(plan, "database_exists") as db:
            out, err = StringIO(), StringIO()
            with redirect_stdout(out), redirect_stderr(err):
                rc = plan.dry_run()
        self.assertEqual(1, rc)
        self.assertIn("symlink", err.getvalue())
        db.assert_not_called()

    def test_cli_unauthorised_execute_is_blocked_before_any_preflight(self):
        cmd = ["bash", str(plan.SOURCE / "tools/check-integration"), "--execute"]
        result = subprocess.run(
            cmd, capture_output=True, text=True, check=False,
            env={**os.environ, "CB_BASE_INTEGRATION_APPROVAL": ""},
        )
        self.assertEqual(2, result.returncode)
        self.assertIn("BLOCKED", result.stderr)

    def test_cli_no_arg_still_blocks_actual_wordpress_integration(self):
        cmd = ["bash", str(plan.SOURCE / "tools/check-integration")]
        result = subprocess.run(cmd, capture_output=True, text=True, check=False)
        self.assertEqual(2, result.returncode)
        self.assertIn("BLOCKED", result.stderr)

    def test_plan_preserves_extended_base_matrix(self):
        steps = " ".join(plan.plan_steps("7.0", "f" * 40))
        self.assertIn("performance baseline", steps)
        self.assertIn("WP-CLI", steps)
        self.assertIn("Media Replace", steps)
        self.assertIn("tests/bin/run-cli-provenance-conformance.sh", plan.REQUIRED)

    def test_execution_plan_has_no_destructive_uninstall_gate(self):
        steps = plan.plan_steps("7.0", "f" * 40)
        self.assertGreaterEqual(len(steps), 8)
        self.assertIn("destructive uninstall", steps[-1])
        self.assertIn("independently gated", steps[-1])


if __name__ == "__main__":
    unittest.main()
