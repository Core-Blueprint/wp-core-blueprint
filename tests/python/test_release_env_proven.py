"""Fail-closed release-environment binding: no Docker or WordPress required."""
import os
from contextlib import ExitStack, contextmanager
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from tools.integration import release_env, preflight


class ProvenReleaseEnvironmentTest(unittest.TestCase):
    def setUp(self):
        self.env = patch.dict(os.environ, {}, clear=True)
        self.env.start()
        self.addCleanup(self.env.stop)

    def test_partial_manual_environment_never_gets_mixed_with_proven_credentials(self):
        os.environ["WP_DB_NAME"] = "wordpress_test"
        with self.assertRaisesRegex(preflight.PreflightError, "Partial"):
            release_env.bind(Path("/unused"))
        self.assertNotIn("WP_DB_PASSWORD", os.environ)

    def test_complete_manual_environment_is_not_overwritten(self):
        for key in release_env.REQUIRED:
            os.environ[key] = "manual"
        with patch.object(release_env, "resolve", side_effect=AssertionError("unexpected")):
            self.assertFalse(release_env.bind(Path("/unused")))
        self.assertEqual(os.environ["WP_DB_NAME"], "manual")

    def test_explicit_proven_mode_replaces_stale_shell_database(self):
        os.environ["WP_DB_NAME"] = "wordpress_test"
        os.environ["CB_BASE_USE_PROVEN_LEVEL2"] = "1"
        verified = {key: "proven_" + key for key in release_env.REQUIRED}
        verified["WP_PHPUNIT__TABLE_PREFIX"] = "cbtests_"
        with patch.object(release_env, "resolve", return_value=verified):
            self.assertTrue(release_env.bind(Path("/unused")))
        self.assertEqual(os.environ["WP_DB_NAME"], "proven_WP_DB_NAME")

    def test_unproved_workspace_cannot_populate_environment(self):
        with patch.object(release_env, "resolve", side_effect=preflight.PreflightError("No PASS")):
            with self.assertRaisesRegex(preflight.PreflightError, "No PASS"):
                release_env.bind(Path("/unused"))
        self.assertFalse(any(os.environ.get(k) for k in release_env.REQUIRED))

    def test_exact_evidence_binds_only_required_values(self):
        env = {key: "verified_" + key for key in release_env.REQUIRED}
        env["WP_PHPUNIT__TABLE_PREFIX"] = "cbtests_"
        with patch.object(release_env, "resolve", return_value=env):
            self.assertTrue(release_env.bind(Path("/unused")))
        self.assertEqual(os.environ["WP_DB_NAME"], "verified_WP_DB_NAME")
        self.assertEqual(os.environ["WP_PHPUNIT__TABLE_PREFIX"], "cbtests_")

    def test_cross_checkout_is_refused_before_database_access(self):
        with patch.object(release_env.plan, "SOURCE", Path("/proven/base")):
            with self.assertRaisesRegex(preflight.PreflightError, "another Base checkout"):
                release_env.resolve(Path("/unrelated/base"))

    def test_missing_final_receipts_are_blocking(self):
        with patch.object(release_env.plan, "SOURCE", Path("/source")), \
             patch.object(release_env.execution, "TEST_ROOT", Path("/tmp/core-blueprint-base")), \
             patch.object(release_env.execution, "OWNER_FILE", Path("/tmp/owner")), \
             patch.object(release_env.execution, "LOCK_FILE", Path("/tmp/lock")), \
             patch.object(release_env.preflight, "verify_parent_security"), \
             patch.object(release_env.inspect, "owner_record", return_value={
                 "git_sha": "a" * 40, "wordpress": "7.0"
             }), \
             patch.object(release_env.preflight, "read_source_commit", return_value="a" * 40), \
             patch.object(release_env.plan, "verify_source"), \
             patch.object(release_env.inspect, "require_file"), \
             patch.object(release_env.Path, "is_symlink", return_value=False), \
             patch.object(release_env.Path, "stat") as status, \
             patch.object(release_env.preflight, "read_docker_metadata", return_value={}), \
             patch.object(release_env.preflight, "validate_docker_metadata", return_value=([], "safe")), \
             patch.object(release_env.plan, "database_exists", return_value=True), \
             patch.object(release_env.inspect, "final_evidence", return_value="INCOMPLETE"):
            status.return_value.st_mode = 0o100600
            with self.assertRaisesRegex(preflight.PreflightError, "PASS receipts"):
                release_env.resolve(Path("/source"))


    @contextmanager
    def proved_workspace(self):
        """Simulate all read-only external evidence, without invoking Docker."""
        env = {key: "verified_" + key for key in release_env.REQUIRED}
        env["WP_PHPUNIT__TABLE_PREFIX"] = "cbtests_"
        with ExitStack() as stack:
            stack.enter_context(patch.object(release_env.plan, "SOURCE", Path("/source")))
            stack.enter_context(patch.object(release_env.execution, "TEST_ROOT", Path("/tmp/core-blueprint-base")))
            stack.enter_context(patch.object(release_env.execution, "OWNER_FILE", Path("/tmp/owner")))
            stack.enter_context(patch.object(release_env.execution, "LOCK_FILE", Path("/tmp/lock")))
            stack.enter_context(patch.object(release_env.preflight, "verify_parent_security"))
            stack.enter_context(patch.object(release_env.inspect, "owner_record", return_value={
                "git_sha": "a" * 40, "wordpress": "7.0"
            }))
            stack.enter_context(patch.object(release_env.plan, "verify_source"))
            stack.enter_context(patch.object(release_env.preflight, "read_source_commit", return_value="a" * 40))
            stack.enter_context(patch.object(release_env.inspect, "require_file"))
            stack.enter_context(patch.object(release_env.Path, "is_symlink", return_value=False))
            stack.enter_context(patch.object(release_env.Path, "exists", return_value=True))
            stat_mock = stack.enter_context(patch.object(release_env.Path, "stat"))
            stat_mock.return_value.st_mode = 0o100600
            stack.enter_context(patch.object(release_env.preflight, "read_docker_metadata", return_value={}))
            docker = stack.enter_context(patch.object(
                release_env.preflight, "validate_docker_metadata", return_value=([], "safe")
            ))
            database = stack.enter_context(patch.object(release_env.plan, "database_exists", return_value=True))
            receipts = stack.enter_context(patch.object(
                release_env.inspect, "final_evidence", return_value="PASS (recorded full Level 2 matrix)"
            ))
            parity = stack.enter_context(patch.object(release_env.execution, "verify_runtime_parity"))
            stack.enter_context(patch.object(release_env.execution, "test_environment", return_value=env))
            yield docker, database, receipts, parity

    def test_verified_workspace_can_resolve_without_manual_paths(self):
        with self.proved_workspace() as (_, _, _, parity):
            result = release_env.resolve(Path("/source"))
        self.assertEqual(result["WP_DB_NAME"], "verified_WP_DB_NAME")
        parity.assert_called_once_with("a" * 40)

    def test_wrong_head_is_blocked_before_database_contact(self):
        with self.proved_workspace() as (_, database, _, _), patch.object(
            release_env.preflight, "read_source_commit", return_value="b" * 40
        ):
            with self.assertRaisesRegex(preflight.PreflightError, "different source SHA"):
                release_env.resolve(Path("/source"))
            database.assert_not_called()

    def test_unsafe_docker_binding_blocks_database_access(self):
        with self.proved_workspace() as (docker, database, _, _):
            docker.side_effect = preflight.PreflightError("beyond loopback")
            with self.assertRaisesRegex(preflight.PreflightError, "beyond loopback"):
                release_env.resolve(Path("/source"))
            database.assert_not_called()

    def test_missing_dedicated_database_blocks_receipt_reuse(self):
        with self.proved_workspace() as (_, database, receipts, _):
            database.return_value = False
            with self.assertRaisesRegex(preflight.PreflightError, "database is missing"):
                release_env.resolve(Path("/source"))
            receipts.assert_not_called()

    def test_modified_plugin_runtime_blocks_release(self):
        with self.proved_workspace() as (_, _, _, parity):
            parity.side_effect = preflight.PreflightError("runtime bytes differ")
            with self.assertRaisesRegex(preflight.PreflightError, "runtime bytes differ"):
                release_env.resolve(Path("/source"))

    def test_different_wordpress_minor_blocks_prior_receipts(self):
        with self.proved_workspace(), patch.dict(
            os.environ, {"CB_TEST_WP_VERSION": "7.1"}
        ):
            with self.assertRaisesRegex(preflight.PreflightError, "WordPress version"):
                release_env.resolve(Path("/source"))

    def test_dirty_source_blocks_before_database_contact(self):
        with self.proved_workspace() as (_, database, _, _), patch.object(
            release_env.plan, "verify_source", side_effect=preflight.PreflightError("dirty checkout")
        ):
            with self.assertRaisesRegex(preflight.PreflightError, "dirty checkout"):
                release_env.resolve(Path("/source"))
            database.assert_not_called()

    def test_missing_owned_lock_blocks_release(self):
        with self.proved_workspace() as (_, database, _, _), patch.object(
            release_env.inspect, "require_file", side_effect=preflight.PreflightError("missing lock")
        ):
            with self.assertRaisesRegex(preflight.PreflightError, "missing lock"):
                release_env.resolve(Path("/source"))
            database.assert_not_called()

    def test_unsafe_lock_permissions_block_release(self):
        with self.proved_workspace(), patch.object(release_env.Path, "stat") as fake_stat:
            fake_stat.return_value.st_mode = 0o100644
            with self.assertRaisesRegex(preflight.PreflightError, "lock permissions"):
                release_env.resolve(Path("/source"))

    def test_symlinked_fixture_blocks_release(self):
        with self.proved_workspace(), patch.object(
            release_env.Path, "is_symlink", new=lambda path: path.name == "core-blueprint.php"
        ):
            with self.assertRaisesRegex(preflight.PreflightError, "symlink"):
                release_env.resolve(Path("/source"))



if __name__ == "__main__":
    unittest.main()
