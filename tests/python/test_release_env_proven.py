"""Fail-closed release-environment binding: no Docker or WordPress required."""
import os
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


if __name__ == "__main__":
    unittest.main()
