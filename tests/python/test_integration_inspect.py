"""Pure tests of the Base Level 2 inspector and immutable receipts.

No real Docker, SQL, WordPress, network or production filesystem changes.
"""
from contextlib import redirect_stdout, redirect_stderr
from io import StringIO
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest import mock

from tools.integration import execution, inspect, preflight


class BaseEvidenceInspectionTest(unittest.TestCase):
    SHA = "a" * 40

    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name) / "core-blueprint-base-testfixture"
        self.root.mkdir(mode=0o700)
        self.marker = self.root / ".base-integration-owner.json"
        self.marker.write_text(json.dumps({
            "schema": 1, "product": "core-blueprint", "git_sha": self.SHA,
            "wordpress": "7.0", "database": execution.DB,
            "policy": "new-only-no-cleanup",
        }), encoding="utf-8")
        self.lock = Path(temp.name) / ".lock"
        self.lock.write_text("pid=12345\n", encoding="utf-8")
        self.lock.chmod(0o600)

    def scoped(self):
        return (
            mock.patch.object(execution, "TEST_ROOT", self.root),
            mock.patch.object(execution, "OWNER_FILE", self.marker),
            mock.patch.object(execution, "LOCK_FILE", self.lock),
            mock.patch.object(preflight, "verify_parent_security", return_value="private"),
            mock.patch.object(inspect.plan, "database_exists", return_value=True),
            mock.patch.object(preflight, "read_source_commit", return_value="b" * 40),
        )

    def run_inspect(self):
        stdout = StringIO()
        stderr = StringIO()
        with redirect_stdout(stdout), redirect_stderr(stderr):
            return inspect.inspect(), stdout.getvalue(), stderr.getvalue()

    def test_historical_owned_workspace_remains_unverified_and_unchanged(self):
        before_marker = self.marker.read_bytes()
        before_lock = self.lock.read_bytes()
        patches = self.scoped()
        with patches[0], patches[1], patches[2], patches[3], patches[4], patches[5]:
            rc, output, error = self.run_inspect()
        self.assertEqual(0, rc, error)
        self.assertIn("UNVERIFIED (historical runner", output)
        self.assertIn("Source moved since original execution: YES", output)
        self.assertNotIn("Level 2 outcome: PASS", output)
        self.assertEqual(before_marker, self.marker.read_bytes())
        self.assertEqual(before_lock, self.lock.read_bytes())
        self.assertFalse((self.root / "evidence").exists())

    def test_fully_recorded_matrix_and_final_parity_can_be_verified(self):
        evidence = self.root / "evidence"
        evidence.mkdir(mode=0o700)
        commands = execution.integration_commands("7.0")
        for index, command in enumerate(commands, start=1):
            (evidence / f"{index:02d}-pass.json").write_text(json.dumps({
                "git_sha": self.SHA, "wordpress": "7.0",
                "database": execution.DB, "status": "pass",
                "gate": command[1], "exit_code": 0,
            }), encoding="utf-8")
        (evidence / "99-final-pass.json").write_text(json.dumps({
            "schema": 1, "product": "core-blueprint", "git_sha": self.SHA,
            "wordpress": "7.0", "database": execution.DB, "status": "pass",
            "gate": "final-runtime-parity", "exit_code": 0,
            "gate_count": len(commands),
        }), encoding="utf-8")
        self.assertIn("PASS", inspect.final_evidence(self.root, json.loads(self.marker.read_text())))
        (evidence / "03-pass.json").unlink()
        with self.assertRaises(preflight.PreflightError):
            inspect.final_evidence(self.root, json.loads(self.marker.read_text()))

    def test_symlinked_owner_marker_is_rejected_without_mutating_target(self):
        original = self.marker.read_bytes()
        external = self.root / "external-owner.json"
        external.write_bytes(original)
        self.marker.unlink()
        self.marker.symlink_to(external)
        with self.assertRaisesRegex(preflight.PreflightError, "Untrusted"):
            inspect.owner_record(self.root, self.marker)
        self.assertEqual(original, external.read_bytes())

    def test_ownership_scope_mismatch_blocks_inspection(self):
        record = json.loads(self.marker.read_text())
        record["database"] = "wordpress_test"
        self.marker.write_text(json.dumps(record))
        patches = self.scoped()
        with patches[0], patches[1], patches[2], patches[3], patches[4], patches[5]:
            rc, output, error = self.run_inspect()
        self.assertEqual(1, rc)
        self.assertIn("BLOCKED", error)
        self.assertNotIn("PASS (recorded", output)

    def test_immutable_evidence_file_never_overwrites(self):
        (self.root / "evidence").mkdir(mode=0o700)
        with mock.patch.object(execution, "TEST_ROOT", self.root), \
             mock.patch.object(execution, "assert_owner"):
            execution.record_receipt("01-pass", self.SHA, "7.0", "pass",
                                     gate="phpunit", exit_code=0)
            receipt = self.root / "evidence/01-pass.json"
            original = receipt.read_bytes()
            self.assertEqual(stat.S_IMODE(receipt.stat().st_mode), 0o600)
            with self.assertRaisesRegex(preflight.PreflightError, "persist"):
                execution.record_receipt("01-pass", self.SHA, "7.0", "fail",
                                         gate="phpunit", exit_code=7)
            self.assertEqual(original, receipt.read_bytes())

    def test_missing_root_is_read_only_and_not_success(self):
        # Use a separate absent path to avoid touching the fixture marker.
        missing = self.root.parent / "not-created"
        with mock.patch.object(execution, "TEST_ROOT", missing), \
             mock.patch.object(preflight, "verify_parent_security", return_value="private"):
            rc, output, error = self.run_inspect()
        self.assertEqual(1, rc, error)
        self.assertIn("ABSENT", output)
        self.assertFalse(missing.exists())


if __name__ == "__main__":
    unittest.main()
