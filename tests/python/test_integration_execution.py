"""B2c no-production-side-effect safety regression suite.

All filesystem cases use TemporaryDirectory; Docker, SQL, downloads and test
subprocesses are mocked. This test module NEVER provisions WordPress.
"""
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest import mock

from tools.integration import execution, preflight


class ExecutionSafetyTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.area = Path(self.temp.name)
        self.root = self.area / "core-blueprint"
        self.sha = "a" * 40

    def patch_root(self):
        return (
            mock.patch.object(execution, "TEST_ROOT", self.root),
            mock.patch.object(execution, "OWNER_FILE", self.root / ".base-integration-owner.json"),
            mock.patch.object(execution, "LOCK_FILE", self.area / ".lock"),
            mock.patch.object(execution.preflight, "validate_test_root", return_value="absent"),
        )

    def make_archive(self, names, root=""):
        output = io.BytesIO()
        with tarfile.open(fileobj=output, mode="w") as archive:
            for name, content, mode in names:
                info = tarfile.TarInfo(name)
                info.mode = 0o644
                info.type = mode
                if mode == tarfile.REGTYPE:
                    payload = content.encode()
                    info.size = len(payload)
                    archive.addfile(info, io.BytesIO(payload))
                else:
                    info.size = 0
                    if mode == tarfile.SYMTYPE:
                        info.linkname = "../../etc/passwd"
                    archive.addfile(info)
        return output.getvalue()

    def test_workspace_creation_marks_exact_commit_and_new_only_policy(self):
        patches = self.patch_root()
        with patches[0], patches[1], patches[2], patches[3]:
            execution.create_new_workspace(self.sha, "7.0")
            execution.assert_owner(self.sha)
            record = json.loads(execution.OWNER_FILE.read_text())
            self.assertEqual("new-only-no-cleanup", record["policy"])
            self.assertEqual("core_blueprint_base_test", record["database"])
            with self.assertRaises(preflight.PreflightError):
                execution.create_new_workspace(self.sha, "7.0")
            with self.assertRaises(preflight.PreflightError):
                execution.assert_owner("b" * 40)

    def test_owned_marker_tampering_fails_closed(self):
        patches = self.patch_root()
        with patches[0], patches[1], patches[2], patches[3]:
            execution.create_new_workspace(self.sha, "7.0")
            record = json.loads(execution.OWNER_FILE.read_text())
            record["database"] = "wordpress_test"
            execution.OWNER_FILE.write_text(json.dumps(record))
            with self.assertRaisesRegex(preflight.PreflightError, "does not match"):
                execution.assert_owner(self.sha)

    def test_workspace_collision_retains_untouched_data(self):
        patches = self.patch_root()
        self.root.mkdir()
        important = self.root / "keep.txt"
        important.write_text("safe")
        with patches[0], patches[1], patches[2], patches[3]:
            with self.assertRaises(preflight.PreflightError):
                execution.create_new_workspace(self.sha, "7.0")
        self.assertEqual("safe", important.read_text())

    def test_exclusive_lock_is_new_only_and_never_auto_deleted(self):
        patches = self.patch_root()
        with patches[0], patches[1], patches[2], patches[3]:
            with execution.exclusive_lock():
                self.assertTrue(execution.LOCK_FILE.exists())
                with self.assertRaisesRegex(preflight.PreflightError, "lock"):
                    with execution.exclusive_lock():
                        pass
            self.assertTrue(execution.LOCK_FILE.exists())

    def test_new_database_is_created_only_once_and_never_dropped(self):
        patches = self.patch_root()
        with patches[0], patches[1], patches[2], patches[3], \
             mock.patch.object(execution.plan, "database_exists", return_value=False), \
             mock.patch.object(execution.preflight, "read_docker_metadata", return_value=[]), \
             mock.patch.object(execution.preflight, "validate_docker_metadata", return_value=([], "MariaDB")), \
             mock.patch.object(execution.preflight, "read_source_commit", return_value=self.sha), \
             mock.patch.object(execution.subprocess, "run", return_value=mock.Mock(returncode=0)) as run:
            execution.create_new_workspace(self.sha, "7.0")
            execution.create_fresh_database(self.sha)
        run.assert_called_once()
        command = run.call_args.args[0]
        self.assertEqual(["docker", "exec", "cb-base-test-db"], command[:3])
        self.assertEqual("-e", command[-2])
        self.assertTrue(command[-1].startswith("CREATE DATABASE `core_blueprint_base_test`"))
        self.assertNotIn("DROP", command[-1])
        self.assertNotIn("IF NOT EXISTS", command[-1])

    def test_existing_database_prevents_create(self):
        patches = self.patch_root()
        with patches[0], patches[1], patches[2], patches[3], \
             mock.patch.object(execution.plan, "database_exists", return_value=True), \
             mock.patch.object(execution.subprocess, "run") as run:
            execution.create_new_workspace(self.sha, "7.0")
            with self.assertRaisesRegex(preflight.PreflightError, "collision"):
                execution.create_fresh_database(self.sha)
        run.assert_not_called()

    def test_network_exposure_change_blocks_database_creation(self):
        patches = self.patch_root()
        with patches[0], patches[1], patches[2], patches[3], \
             mock.patch.object(execution.plan, "database_exists", return_value=False), \
             mock.patch.object(execution.preflight, "read_docker_metadata", return_value=[]), \
             mock.patch.object(execution.preflight, "validate_docker_metadata",
                               return_value=(["MariaDB is bound beyond loopback"], "MariaDB")), \
             mock.patch.object(execution.subprocess, "run") as run:
            execution.create_new_workspace(self.sha, "7.0")
            with self.assertRaisesRegex(preflight.PreflightError, "network exposure changed"):
                execution.create_fresh_database(self.sha)
        run.assert_not_called()

    def test_git_head_change_blocks_database_creation(self):
        patches = self.patch_root()
        with patches[0], patches[1], patches[2], patches[3], \
             mock.patch.object(execution.plan, "database_exists", return_value=False), \
             mock.patch.object(execution.preflight, "read_docker_metadata", return_value=[]), \
             mock.patch.object(execution.preflight, "validate_docker_metadata",
                               return_value=([], "MariaDB")), \
             mock.patch.object(execution.preflight, "read_source_commit", return_value="b" * 40), \
             mock.patch.object(execution.subprocess, "run") as run:
            execution.create_new_workspace(self.sha, "7.0")
            with self.assertRaisesRegex(preflight.PreflightError, "Source changed"):
                execution.create_fresh_database(self.sha)
        run.assert_not_called()

    def test_failed_create_database_does_not_attempt_recovery_or_drop(self):
        patches = self.patch_root()
        with patches[0], patches[1], patches[2], patches[3], \
             mock.patch.object(execution.plan, "database_exists", return_value=False), \
             mock.patch.object(execution.preflight, "read_docker_metadata", return_value=[]), \
             mock.patch.object(execution.preflight, "validate_docker_metadata",
                               return_value=([], "MariaDB")), \
             mock.patch.object(execution.preflight, "read_source_commit", return_value=self.sha), \
             mock.patch.object(execution.subprocess, "run",
                               return_value=mock.Mock(returncode=1)) as run:
            execution.create_new_workspace(self.sha, "7.0")
            with self.assertRaisesRegex(preflight.PreflightError, "not succeed"):
                execution.create_fresh_database(self.sha)
        run.assert_called_once()
        self.assertNotIn("DROP", run.call_args.args[0][-1])

    def test_external_docker_port_binding_blocks_all_mutations(self):
        with mock.patch.object(execution.preflight, "validate_test_root", return_value="absent"), \
             mock.patch.object(execution.plan, "verify_source"), \
             mock.patch.object(execution.preflight, "read_source_commit", return_value=self.sha), \
             mock.patch.object(execution.preflight, "read_docker_metadata", return_value=[]), \
             mock.patch.object(execution.preflight, "validate_docker_metadata",
                               return_value=(["MariaDB is bound beyond loopback"], "MariaDB")), \
             mock.patch.object(execution.plan, "database_exists") as database:
            with self.assertRaisesRegex(preflight.PreflightError, "beyond loopback"):
                execution.verify_execution_scope()
            database.assert_not_called()

    def test_existing_schema_blocks_before_workspace_mutation(self):
        with mock.patch.object(execution.preflight, "validate_test_root", return_value="absent"), \
             mock.patch.object(execution.plan, "verify_source"), \
             mock.patch.object(execution.preflight, "read_source_commit", return_value=self.sha), \
             mock.patch.object(execution.preflight, "read_docker_metadata", return_value=[]), \
             mock.patch.object(execution.preflight, "validate_docker_metadata", return_value=([], "MariaDB")), \
             mock.patch.object(execution.plan, "database_exists", return_value=True):
            with self.assertRaisesRegex(preflight.PreflightError, "never reused"):
                execution.verify_execution_scope()

    def test_archive_staging_uses_exact_head_and_never_calls_legacy_installer(self):
        with mock.patch.object(execution, "assert_owner"), \
             mock.patch.object(execution, "download_pinned_archive", return_value=b"archive") as download, \
             mock.patch.object(execution, "safe_extract") as extract, \
             mock.patch.object(execution.subprocess, "run",
                               return_value=mock.Mock(returncode=0, stdout=b"git")) as run:
            execution.stage_pinned_environment("7.0", self.sha)
        self.assertEqual(2, download.call_count)
        self.assertEqual(3, extract.call_count)
        command = run.call_args.args[0]
        self.assertEqual(["git", "-C", str(execution.SOURCE), "archive",
                          "--format=tar", self.sha], command)
        self.assertNotIn("install-wp-tests.sh", " ".join(command))

    def test_group_writable_lock_parent_is_refused(self):
        self.area.chmod(0o770)
        with self.assertRaisesRegex(preflight.PreflightError, "not world-writable"):
            execution.require_owned_parent(self.area / ".base-lock")

    def test_successful_scope_inspection_has_no_writes(self):
        with mock.patch.object(execution.preflight, "validate_test_root", return_value="absent"), \
             mock.patch.object(execution.plan, "verify_source"), \
             mock.patch.object(execution.preflight, "read_source_commit", return_value=self.sha), \
             mock.patch.object(execution.preflight, "read_docker_metadata", return_value=[]), \
             mock.patch.object(execution.preflight, "validate_docker_metadata", return_value=([], "MariaDB")), \
             mock.patch.object(execution.plan, "database_exists", return_value=False):
            self.assertEqual(("7.0", self.sha), execution.verify_execution_scope())
        self.assertFalse(self.root.exists())

    def test_tar_prefixed_wordpress_archive_extracts_regular_files(self):
        content = self.make_archive([("wordpress/readme.txt", "WP pinned", tarfile.REGTYPE)])
        target = self.area / "wp"
        execution.safe_extract(content, target, "wordpress")
        self.assertEqual("WP pinned", (target / "readme.txt").read_text())

    def test_git_archive_without_root_prefix_extracts_regular_files(self):
        content = self.make_archive([("core-blueprint.php", "<?php", tarfile.REGTYPE)])
        target = self.area / "plugin"
        execution.safe_extract(content, target, "")
        self.assertEqual("<?php", (target / "core-blueprint.php").read_text())

    def test_tar_rejects_traversal_absolute_and_unexpected_roots(self):
        for name in ("wordpress/../escape", "/wordpress/file", "other/file",
                     "wordpress//double"):
            with self.subTest(name=name):
                content = self.make_archive([(name, "oops", tarfile.REGTYPE)])
                with self.assertRaises(preflight.PreflightError):
                    execution.safe_extract(content, self.area / "wp", "wordpress")
                self.assertFalse((self.area / "escape").exists())

    def test_tar_symlinks_are_rejected_before_destination_creation(self):
        data = self.make_archive([("wordpress/link", "", tarfile.SYMTYPE)])
        with self.assertRaisesRegex(preflight.PreflightError, "link"):
            execution.safe_extract(data, self.area / "wp", "wordpress")
        self.assertFalse((self.area / "wp").exists())

    def test_tar_existing_destination_is_never_overwritten(self):
        dest = self.area / "wp"
        dest.mkdir()
        (dest / "keep.txt").write_text("safe")
        data = self.make_archive([("wordpress/readme.txt", "new", tarfile.REGTYPE)])
        with self.assertRaisesRegex(preflight.PreflightError, "already exists"):
            execution.safe_extract(data, dest, "wordpress")
        self.assertEqual("safe", (dest / "keep.txt").read_text())

    def test_tar_size_guard_blocks_oversized_archive_before_creation(self):
        data = self.make_archive([("wordpress/large.txt", "data", tarfile.REGTYPE)])
        with mock.patch.object(execution, "MAX_UNPACK_BYTES", 2):
            with self.assertRaisesRegex(preflight.PreflightError, "size limit"):
                execution.safe_extract(data, self.area / "wp", "wordpress")
        self.assertFalse((self.area / "wp").exists())

    def test_unapproved_external_urls_are_refused_without_network(self):
        with mock.patch.object(execution.urllib.request, "urlopen") as network:
            for url in ("http://wordpress.org/wordpress-7.0.tar.gz",
                        "https://wordpress.org/wordpress-7.0.tar.gz.evil",
                        "https://example.com/wp.zip"):
                with self.assertRaises(preflight.PreflightError):
                    execution.download_pinned_archive(url)
            network.assert_not_called()

    def test_fixture_redirect_to_unapproved_host_is_refused(self):
        response = mock.MagicMock()
        response.__enter__.return_value.url = "https://malicious.example/archive.tar.gz"
        with mock.patch.object(execution.urllib.request, "urlopen", return_value=response):
            with self.assertRaisesRegex(preflight.PreflightError, "approved HTTPS hosts"):
                execution.download_pinned_archive("https://wordpress.org/wordpress-7.0.tar.gz")

    def test_full_matrix_keeps_phpunit_and_existing_scenarios(self):
        commands = execution.integration_commands("7.0")
        flat = " ".join(" ".join(item) for item in commands)
        for value in ("--fail-on-skipped", "--fail-on-incomplete",
                      "run-lifecycle-scenario.sh", "install-pinned-starter.sh",
                      "run-consumer-scenario.sh", "run-module-conformance-scenario.sh",
                      "run-performance-baseline.sh", "run-cli-provenance-conformance.sh",
                      "run-media-replace-persistence-conformance.sh"):
            self.assertIn(value, flat)
        self.assertNotIn("run-uninstall-scenario.sh", flat)
        self.assertNotIn("install-wp-tests.sh", flat)

    def test_environment_scopes_wordpress_database_and_tmp(self):
        env = execution.test_environment("7.0")
        self.assertEqual("core_blueprint_base_test", env["WP_DB_NAME"])
        self.assertEqual("127.0.0.1:3307", env["WP_DB_HOST"])
        self.assertEqual(str(execution.TEST_ROOT / "tmp"), env["TMPDIR"])
        self.assertTrue(env["CB_PLUGIN_FILE"].startswith(str(execution.TEST_ROOT)))
        self.assertTrue(env["CB_WP_CLI_PHAR"].startswith(str(execution.TEST_ROOT)))

    def test_run_suite_stops_at_first_failure(self):
        fake = mock.Mock(returncode=12)
        with mock.patch.object(execution, "verify_runtime_parity"), \
             mock.patch.object(execution, "assert_owner"), \
             mock.patch.object(execution.subprocess, "run", return_value=fake) as run, \
             mock.patch.object(execution, "TEST_ROOT", self.area):
            with self.assertRaisesRegex(preflight.PreflightError, "Required Base integration gate failed"):
                execution.run_full_suite("7.0", self.sha)
        run.assert_called_once()

    def test_cli_execute_remains_blocked_without_any_changes(self):
        result = subprocess.run(
            ["bash", str(execution.SOURCE / "tools/check-integration"), "--execute"],
            capture_output=True, text=True, check=False,
            env={**os.environ, "CB_BASE_INTEGRATION_APPROVAL": ""},
        )
        self.assertEqual(2, result.returncode)
        self.assertIn("BLOCKED", result.stderr)


    def test_browser_scenario_outputs_stay_in_owned_runner_workspace(self):
        scripts = (
            execution.SOURCE / "tests/bin/run-cli-provenance-conformance.sh",
            execution.SOURCE / "tests/bin/run-media-replace-persistence-conformance.sh",
        )
        for path in scripts:
            source = path.read_text(encoding="utf-8")
            self.assertIn("RUNNER_TEMP", source, str(path))
            self.assertNotIn("/tmp/cb-b2-console-", source, str(path))
            self.assertNotIn("/tmp/cb-b2-prune-invalid.txt", source, str(path))
            self.assertNotIn("/tmp/cb-c2c-success.html", source, str(path))
            self.assertNotIn("/tmp/cb-c2c-metadata-failure.html", source, str(path))
            self.assertNotIn("/tmp/cb-c2c-revision-failure.html", source, str(path))

    def test_explicit_approval_calls_prepared_pipeline_without_test_side_effects(self):
        with mock.patch.dict(os.environ, {
            "CB_BASE_INTEGRATION_APPROVAL": "I_APPROVE_FRESH_BASE_TEST_DATABASE"
        }), mock.patch.object(execution, "prepared_pipeline") as pipeline:
            self.assertEqual(0, execution.main(["--execute"]))
            pipeline.assert_called_once_with()

    def test_invalid_approval_cannot_reach_pipeline(self):
        for approval in ("", "yes", "I_APPROVE_FRESH_BASE_TEST_DATABASE_EXTRA"):
            with self.subTest(approval=approval):
                with mock.patch.dict(os.environ, {"CB_BASE_INTEGRATION_APPROVAL": approval}), \
                     mock.patch.object(execution, "prepared_pipeline") as pipeline:
                    self.assertEqual(2, execution.main(["--execute"]))
                    pipeline.assert_not_called()

    def test_failure_does_not_report_success_or_cleanup(self):
        with mock.patch.dict(os.environ, {
            "CB_BASE_INTEGRATION_APPROVAL": "I_APPROVE_FRESH_BASE_TEST_DATABASE"
        }), mock.patch.object(execution, "prepared_pipeline",
                             side_effect=preflight.PreflightError("unsafe network")):
            self.assertEqual(1, execution.main(["--execute"]))


if __name__ == "__main__":
    unittest.main()
