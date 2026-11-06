"""Offline safety guards for canonical CI WordPress package staging."""
from __future__ import annotations

import importlib.machinery
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
LOADER = importlib.machinery.SourceFileLoader(
    "cb_stage_package_contract", str(ROOT / "tests/bin/stage-ci-package.py")
)
SPEC = importlib.util.spec_from_loader(LOADER.name, LOADER)
stage = importlib.util.module_from_spec(SPEC)
LOADER.exec_module(stage)


class CanonicalCIStagingContractTest(unittest.TestCase):
    ENV = {
        "GITHUB_ACTIONS": "true",
        "RUNNER_TEMP": "/tmp/ci-runner",
        "WP_CORE_DIR": "/tmp/core-blueprint-release-wp",
        "CB_PLUGIN_FILE": "/tmp/core-blueprint-release-wp/wp-content/plugins/core-blueprint/core-blueprint.php",
    }

    def test_valid_pinned_ephemeral_layouts_only(self):
        release, release_plugin = stage.ci_paths(dict(self.ENV))
        self.assertEqual(release / "wp-content/plugins/core-blueprint", release_plugin)
        env = dict(self.ENV)
        env["WP_CORE_DIR"] = "/tmp/core-blueprint-base-g1d_a22_20261019/wordpress"
        env["CB_PLUGIN_FILE"] = env["WP_CORE_DIR"] + "/wp-content/plugins/core-blueprint/core-blueprint.php"
        _, plugin = stage.ci_paths(env)
        self.assertEqual("core-blueprint", plugin.name)

    def test_local_execution_and_unknown_layout_fail_before_mutation(self):
        for change in (
            {"GITHUB_ACTIONS": "false"},
            {"RUNNER_TEMP": ""},
            {"WP_CORE_DIR": "/home/chris/Downloads/wp-core-blueprint-test-env"},
            {"WP_CORE_DIR": "/tmp/core-blueprint-other"},
            {"CB_PLUGIN_FILE": "/tmp/core-blueprint-release-wp/wp-content/plugins/other/plugin.php"},
        ):
            with self.subTest(change=change):
                with self.assertRaises(SystemExit):
                    stage.ci_paths({**self.ENV, **change})

    def test_ci_installer_is_not_implicitly_modified(self):
        installer = (ROOT / "tests/bin/install-wp-tests.sh").read_text()
        self.assertNotIn("stage-ci-package.py", installer)
        workflow = (ROOT / ".github/workflows/tests.yml").read_text()
        self.assertEqual(2, workflow.count("python3 tests/bin/stage-ci-package.py"))
        self.assertEqual(2, workflow.count("python3 tools/build-dev"))
        self.assertIn("workflow_dispatch:", workflow)


if __name__ == "__main__":
    unittest.main()
