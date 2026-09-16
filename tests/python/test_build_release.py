"""Failure-path and artifact tests; native PHP/WordPress gates run separately."""
import importlib.machinery
import importlib.util
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('base_release_builder', str(ROOT / 'tools/build-release'))
spec = importlib.util.spec_from_loader(loader.name, loader)
builder = importlib.util.module_from_spec(spec)
loader.exec_module(builder)


class ReleaseBuilderTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source = self.root / 'source'
        self.source.mkdir()
        for name in builder.ROOT_FILES:
            (self.source / name).write_text('fixture\n')
        (self.source / 'core-blueprint.php').write_text(
            "<?php\n/**\n * Version: 1.0.0-rc1\n */\ndefine( 'CB_CORE_VERSION', '1.0.0-rc1' );\n")
        for name in builder.RUNTIME_DIRS:
            (self.source / name).mkdir()
            (self.source / name / 'fixture.txt').write_text(name)
        self.output = self.root / 'dist'

    def archive(self, path, extra=False):
        with zipfile.ZipFile(path, 'w') as archive:
            for item in builder.collect_files(self.source):
                builder.add_file(archive, self.source, item)
            if extra:
                archive.writestr('other-plugin/unexpected.php', 'unexpected')

    def test_failed_preflight_preserves_previous_artifact(self):
        self.output.mkdir()
        archive = self.output / 'core-blueprint-1.0.0-rc1.zip'
        checksum = self.output / (archive.name + '.sha256')
        archive.write_bytes(b'previous accepted artifact')
        checksum.write_text('previous checksum')
        with mock.patch.object(builder, 'preflight', side_effect=SystemExit(1)):
            with self.assertRaises(SystemExit):
                builder.build(self.source, self.output)
        self.assertEqual(b'previous accepted artifact', archive.read_bytes())
        self.assertEqual('previous checksum', checksum.read_text())

    def test_missing_tool_fails_before_any_archive(self):
        with mock.patch.object(builder.shutil, 'which', return_value=None):
            with self.assertRaises(SystemExit):
                builder.build(self.source, self.output)
        self.assertFalse(self.output.exists())

    def test_failed_subprocess_gate_is_not_a_warning(self):
        with mock.patch.object(builder.subprocess, 'run', return_value=mock.Mock(returncode=7)):
            with self.assertRaises(SystemExit):
                builder.run_gate(['fixture-gate'], self.source, 'fixture')

    def test_archive_rejects_extra_root(self):
        path = self.root / 'bad.zip'
        self.archive(path, extra=True)
        with self.assertRaises(SystemExit):
            builder.verify_archive(path, builder.runtime_hashes(self.source))

    def test_archive_rejects_changed_bytes(self):
        path = self.root / 'bad.zip'
        expected = builder.runtime_hashes(self.source)
        (self.source / 'uninstall.php').write_text('mutated')
        self.archive(path)
        with self.assertRaises(SystemExit):
            builder.verify_archive(path, expected)

    def test_failed_package_gate_does_not_publish(self):
        with mock.patch.object(builder, 'preflight'), mock.patch.object(
                builder, 'run_gate', side_effect=SystemExit(1)):
            with self.assertRaises(SystemExit):
                builder.build(self.source, self.output)
        self.assertEqual([], list(self.output.iterdir()))

    def test_corruption_after_package_gate_does_not_publish(self):
        def corrupt(command, source, label):
            Path(command[2]).write_bytes(b'broken ZIP')
        with mock.patch.object(builder, 'preflight'), mock.patch.object(builder, 'run_gate', side_effect=corrupt):
            with self.assertRaises(zipfile.BadZipFile):
                builder.build(self.source, self.output)
        self.assertEqual([], list(self.output.iterdir()))

    def test_source_mutation_after_package_gate_does_not_publish(self):
        def mutate(command, source, label):
            (source / 'uninstall.php').write_text('mutated')
        with mock.patch.object(builder, 'preflight'), mock.patch.object(builder, 'run_gate', side_effect=mutate):
            with self.assertRaises(SystemExit):
                builder.build(self.source, self.output)
        self.assertEqual([], list(self.output.iterdir()))

    def test_output_inside_runtime_is_rejected(self):
        with mock.patch.object(builder, 'preflight') as preflight:
            with self.assertRaises(SystemExit):
                builder.build(self.source, self.source / 'assets' / 'release')
        preflight.assert_not_called()

    def test_stale_wordpress_copy_fails_before_executing_gates(self):
        for relative in ('tools/check-translations.php', 'tests/bin/php-lint.sh',
                         'tests/bin/run-release-package-scenario.sh', 'vendor/bin/phpunit',
                         'vendor/autoload.php', 'phpunit.xml.dist', 'tests/python/test_build_release.py'):
            path = self.source / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.touch()
        wordpress = self.root / 'wordpress'
        wordpress.mkdir()
        (wordpress / 'wp-load.php').touch()
        tests = self.root / 'wp-tests'
        (tests / 'includes').mkdir(parents=True)
        (tests / 'includes/bootstrap.php').touch()
        plugin = wordpress / 'wp-content/plugins/core-blueprint'
        builder.shutil.copytree(self.source, plugin)
        (plugin / 'uninstall.php').write_text('stale runtime')
        env = {'WP_CORE_DIR': str(wordpress), 'WP_TESTS_DIR': str(tests),
               'CB_PLUGIN_FILE': str(plugin / 'core-blueprint.php'),
               'WP_DB_NAME': 'isolated', 'WP_DB_USER': 'test',
               'WP_DB_PASSWORD': 'test', 'WP_DB_HOST': '127.0.0.1:33077'}
        with mock.patch.dict(builder.os.environ, env), mock.patch.object(
                builder.shutil, 'which', return_value='/tool'), mock.patch.object(builder, 'run_gate') as gate:
            with self.assertRaises(SystemExit):
                builder.build(self.source, self.output)
        gate.assert_not_called()
        self.assertFalse(self.output.exists())

    def test_verified_archive_remains_deterministic(self):
        with mock.patch.object(builder, 'preflight'), mock.patch.object(builder, 'run_gate'):
            first, _ = builder.build(self.source, self.output)
            initial = first.read_bytes()
            second, checksum = builder.build(self.source, self.output)
        self.assertEqual(initial, second.read_bytes())
        self.assertTrue(checksum.read_text().startswith(builder.hashlib.sha256(initial).hexdigest()))
        builder.verify_archive(second, builder.runtime_hashes(self.source))


if __name__ == '__main__':
    unittest.main()
