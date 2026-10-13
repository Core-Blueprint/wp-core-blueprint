"""Pure subprocess conformance for new-only Base Level 2 test-slot identity."""
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
PROGRAM = (
    "import json; "
    "from tools.integration import preflight, plan, execution; "
    "print(json.dumps({'slot': preflight.RUN_SLOT, "
    "'root': str(preflight.CANONICAL_TEST_ROOT), "
    "'schema': plan.DATABASE, 'query': plan.QUERY, "
    "'lock': str(execution.LOCK_FILE), "
    "'runtime_db': execution.test_environment('7.0')['WP_DB_NAME']}))"
)


class TestBaseSlotIsolation(unittest.TestCase):
    def inspect(self, slot):
        env = {**os.environ, "CB_BASE_TEST_SLOT": slot, "PYTHONDONTWRITEBYTECODE": "1"}
        return subprocess.run(
            [sys.executable, "-B", "-c", PROGRAM],
            cwd=ROOT, env=env, capture_output=True, text=True, check=False,
        )

    def test_default_preserves_documented_identity(self):
        run = self.inspect("")
        self.assertEqual(run.returncode, 0, run.stderr)
        info = json.loads(run.stdout)
        self.assertEqual(info["schema"], "core_blueprint_base_test")
        self.assertEqual(info["root"], "/tmp/core-blueprint-tests/core-blueprint")
        self.assertEqual(info["runtime_db"], info["schema"])

    def test_named_slot_is_consistent_across_preflight_plan_execution(self):
        run = self.inspect("e2_20261013")
        self.assertEqual(run.returncode, 0, run.stderr)
        info = json.loads(run.stdout)
        self.assertEqual(info["schema"], "core_blueprint_base_e2_20261013_test")
        self.assertEqual(info["root"], "/tmp/core-blueprint-tests/core-blueprint-e2_20261013")
        self.assertEqual(info["runtime_db"], info["schema"])
        self.assertIn(info["schema"], info["query"])
        self.assertIn("e2_20261013", info["lock"])
        self.assertNotIn("wordpress_test", info["query"])

    def test_invalid_slots_fail_before_importing_any_mutating_runner(self):
        for slot in ("../test", "test;DROP", "E2", "_e2", "e2_", "a__b",
                     "a" * 25, "test-123", "/tmp/evil"):
            with self.subTest(slot=slot):
                run = self.inspect(slot)
                self.assertNotEqual(run.returncode, 0)
                self.assertIn("CB_BASE_TEST_SLOT", run.stderr)

    def test_release_builder_invokes_canonical_level_one(self):
        source = (ROOT / "tools/build-release").read_text(encoding="utf-8")
        self.assertIn('run_gate(["bash", "tools/check"], source, "Canonical Base Level 1"', source)
        self.assertIn('"--fail-on-skipped", "--fail-on-incomplete"', source)


if __name__ == "__main__":
    unittest.main()
