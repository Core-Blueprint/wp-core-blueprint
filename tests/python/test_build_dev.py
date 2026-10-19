"""Offline contract tests for the INTERNAL Base dev ZIP.

No WordPress, Docker, MariaDB, network, CI or release gates are run.
"""
from __future__ import annotations

import importlib.machinery
import importlib.util
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[2]


def load(path: Path, name: str):
    loader = importlib.machinery.SourceFileLoader(name, str(path))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


dev = load(ROOT / "tools/build-dev", "cb_dev_builder_contract")
canonical = load(ROOT / "tools/build-release", "cb_release_builder_contract")


class DevBuildContractTest(unittest.TestCase):
    SHA = "a" * 40

    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.source = self.root / "base"
        self.source.mkdir()
        for name in canonical.ROOT_FILES:
            (self.source / name).write_text("fixture\n")
        (self.source / "core-blueprint.php").write_text(
            "<?php\n/**\n * Version: 1.0.0\n */\n"
            "define( 'CB_CORE_VERSION', '1.0.0' );\n"
        )
        for name in canonical.RUNTIME_DIRS:
            directory = self.source / name
            directory.mkdir()
            (directory / "fixture.txt").write_text(name)
        design = self.source / "assets/js/design"
        (design / "shell").mkdir(parents=True)
        (design / "editor.js").write_text(
            "import { item } from './shell/item.js';\nexport { item };\n"
        )
        (design / "shell/item.js").write_text("export const item = true;\n")
        (self.source / "src/fixture.php").write_text("<?php return true;\n")
        self.output = self.source / "dist/dev"

    def fake_build(self):
        with mock.patch.object(dev, "SOURCE", self.source), mock.patch.object(
            dev, "OUTPUT", self.output
        ), mock.patch.object(dev, "check_tools"), mock.patch.object(
            dev, "require_tracked_runtime"
        ), mock.patch.object(
            dev, "check_git", return_value=self.SHA
        ), mock.patch.object(dev, "syntax_check", return_value=(2, 2)), mock.patch.object(
            dev, "translations_check"
        ):
            return dev.build()

    def test_build_is_installable_and_internal_only(self):
        archive, checksum, receipt = self.fake_build()
        self.assertTrue(archive.is_file())
        self.assertTrue(checksum.is_file())
        self.assertTrue(receipt.is_file())
        self.assertEqual(
            "core-blueprint-1.0.0-dev-" + self.SHA[:12] + ".zip", archive.name
        )
        metadata = json.loads(receipt.read_text())
        self.assertEqual(self.SHA, metadata["source_sha"])
        self.assertIn("NOT PUBLIC RELEASE VALIDATED", metadata["status"])
        self.assertEqual(hashlib.sha256(archive.read_bytes()).hexdigest(),
                         metadata["artifact_sha256"])
        self.assertEqual(f"{metadata['artifact_sha256']}  {archive.name}\n",
                         checksum.read_text())
        self.assertFalse((self.source / "dist/core-blueprint-1.0.0.zip").exists())
        with zipfile.ZipFile(archive) as result:
            names = result.namelist()
            self.assertIn("core-blueprint/core-blueprint.php", names)
            self.assertIn("core-blueprint/assets/js/design/editor.js", names)
            self.assertFalse(any(name.startswith("core-blueprint/tests/") for name in names))
            self.assertIn("?cb-design=", result.read(
                "core-blueprint/assets/js/design/editor.js").decode())

    def test_dev_package_is_deterministic_without_revalidation(self):
        first, checksum, receipt = self.fake_build()
        archive_bytes, receipt_bytes = first.read_bytes(), receipt.read_bytes()
        second, _, _ = self.fake_build()
        self.assertEqual(first, second)
        self.assertEqual(archive_bytes, second.read_bytes())
        self.assertEqual(receipt_bytes, receipt.read_bytes())

    def test_public_release_artifact_is_not_overwritten(self):
        release = self.source / "dist/core-blueprint-1.0.0.zip"
        release.parent.mkdir()
        release.write_bytes(b"accepted-public-release")
        self.fake_build()
        self.assertEqual(b"accepted-public-release", release.read_bytes())

    def test_source_mutation_after_checks_is_rejected(self):
        def mutation(*args):
            (self.source / "uninstall.php").write_text("changed")
            return (2, 2)
        with mock.patch.object(dev, "SOURCE", self.source), mock.patch.object(
            dev, "OUTPUT", self.output
        ), mock.patch.object(dev, "check_tools"), mock.patch.object(
            dev, "require_tracked_runtime"
        ), mock.patch.object(
            dev, "check_git", return_value=self.SHA
        ), mock.patch.object(dev, "syntax_check", side_effect=mutation), mock.patch.object(
            dev, "translations_check"
        ):
            with self.assertRaises(SystemExit):
                dev.build()
        self.assertFalse(self.output.exists())

    def test_altered_same_sha_artifact_is_not_replaced(self):
        zip_path = self.output / f"core-blueprint-1.0.0-dev-{self.SHA[:12]}.zip"
        self.output.mkdir(parents=True)
        zip_path.write_bytes(b"protected-existing-artifact")
        with self.assertRaises(SystemExit):
            self.fake_build()
        self.assertEqual(b"protected-existing-artifact", zip_path.read_bytes())

    def test_symlinked_output_is_rejected(self):
        target = self.root / "other"
        target.mkdir()
        (self.source / "dist").symlink_to(target, target_is_directory=True)
        with self.assertRaises(SystemExit):
            self.fake_build()
        self.assertEqual([], list(target.iterdir()))

    def test_symlinked_runtime_file_is_rejected(self):
        target = self.source / "src/fixture.php"
        target.unlink()
        target.symlink_to(self.source / "core-blueprint.php")
        with self.assertRaises(SystemExit):
            self.fake_build()
        self.assertFalse(self.output.exists())

    def test_syntax_failure_prevents_artifact(self):
        with mock.patch.object(dev, "SOURCE", self.source), mock.patch.object(
            dev, "OUTPUT", self.output
        ), mock.patch.object(dev, "check_tools"), mock.patch.object(
            dev, "require_tracked_runtime"
        ), mock.patch.object(
            dev, "check_git", return_value=self.SHA
        ), mock.patch.object(dev, "syntax_check", side_effect=SystemExit(1)), mock.patch.object(
            dev, "translations_check"
        ):
            with self.assertRaises(SystemExit):
                dev.build()
        self.assertFalse(self.output.exists())

    def test_translations_failure_prevents_artifact(self):
        with mock.patch.object(dev, "SOURCE", self.source), mock.patch.object(
            dev, "OUTPUT", self.output
        ), mock.patch.object(dev, "check_tools"), mock.patch.object(
            dev, "require_tracked_runtime"
        ), mock.patch.object(
            dev, "check_git", return_value=self.SHA
        ), mock.patch.object(dev, "syntax_check", return_value=(2, 2)), mock.patch.object(
            dev, "translations_check", side_effect=SystemExit(1)
        ):
            with self.assertRaises(SystemExit):
                dev.build()
        self.assertFalse(self.output.exists())

    def test_builder_never_calls_release_or_integration_gates(self):
        # Force the exact canonical module under test so these sentinels
        # exercise the code path actually called by the dev entrypoint.
        with mock.patch.object(dev, "canonical_builder", return_value=canonical), mock.patch.object(
            canonical, "preflight", side_effect=AssertionError(
                "public release gate must not execute"
            )
        ), mock.patch.object(canonical, "run_gate", side_effect=AssertionError(
            "public release gate must not execute"
        )):
            self.fake_build()

    def test_worktree_git_refusal(self):
        def response(args):
            args = tuple(args)
            if args[-2:] == ("rev-parse", "--show-toplevel"):
                return mock.Mock(returncode=0, stdout=str(self.source), stderr="")
            if args[-2:] == ("rev-parse", "HEAD"):
                return mock.Mock(returncode=0, stdout=self.SHA, stderr="")
            return mock.Mock(returncode=0, stdout=" M core-blueprint.php", stderr="")
        with mock.patch.object(dev.subprocess, "run", side_effect=lambda command, **kwargs: response(command)):
            with self.assertRaises(SystemExit):
                dev.check_git(self.source)

    def test_untracked_or_ignored_runtime_file_is_rejected(self):
        files = canonical.collect_files(self.source)
        with mock.patch.object(
            dev.subprocess, "run",
            return_value=mock.Mock(returncode=0, stdout=b"core-blueprint.php\\0"),
        ):
            with self.assertRaises(SystemExit):
                dev.require_tracked_runtime(self.source, files)

    def test_output_has_no_custom_path_option_or_disable_flag(self):
        script = (ROOT / "tools/build-dev").read_text()
        self.assertNotIn("build-release --skip", script)
        self.assertNotIn("CB_BASE_INTEGRATION_APPROVAL", script)
        self.assertNotIn("docker run", script)
        self.assertNotIn("WP_DB_PASSWORD", script)


if __name__ == "__main__":
    unittest.main()
