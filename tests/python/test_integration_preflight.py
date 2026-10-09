"""Read-only safety-policy tests for future Base Level 2 integration."""

import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'tools/integration/preflight.py'
spec = importlib.util.spec_from_file_location('base_integration_preflight', SCRIPT)
preflight = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preflight)


class SafetyPolicyTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.target = self.folder / 'tests' / 'core-blueprint'

    def check_root(self, path):
        return preflight.validate_test_root(str(path), required_root=self.target)

    def test_missing_root_is_safe_and_no_directory_is_created(self):
        self.assertEqual('absent', self.check_root(self.target))
        self.assertFalse(self.target.exists())

    def test_empty_root_is_safe_and_unmodified(self):
        self.target.mkdir(parents=True)
        self.assertEqual('empty', self.check_root(self.target))
        self.assertEqual([], list(self.target.iterdir()))

    def test_non_empty_unowned_root_is_refused(self):
        self.target.mkdir(parents=True)
        (self.target / 'user-data.txt').write_text('keep')
        with self.assertRaisesRegex(preflight.PreflightError, 'not proven disposable'):
            self.check_root(self.target)
        self.assertEqual('keep', (self.target / 'user-data.txt').read_text())

    def test_symlink_at_test_root_is_refused(self):
        other = self.folder / 'real'
        other.mkdir()
        self.target.parent.mkdir()
        self.target.symlink_to(other, target_is_directory=True)
        with self.assertRaisesRegex(preflight.PreflightError, 'Symlink'):
            self.check_root(self.target)

    def test_symlink_in_parent_is_refused(self):
        real = self.folder / 'real'
        real.mkdir()
        (self.folder / 'tests').symlink_to(real, target_is_directory=True)
        with self.assertRaisesRegex(preflight.PreflightError, 'Symlink'):
            self.check_root(self.target)

    def test_prefix_confusion_dot_segments_and_relative_paths_are_refused(self):
        for candidate in (
            str(self.target) + '-other', str(self.target) + '/../other',
            str(self.target) + '/.', str(self.target) + '//other',
            './' + str(self.target), '/', '',
        ):
            with self.subTest(candidate=candidate):
                with self.assertRaises(preflight.PreflightError):
                    self.check_root(candidate)

    def test_non_directory_parent_is_refused(self):
        (self.folder / 'tests').write_text('not a directory')
        with self.assertRaisesRegex(preflight.PreflightError, 'Non-directory'):
            self.check_root(self.target)

    def docker_info(self, **overrides):
        obj = {
            'Name': '/cb-base-test-db',
            'Config': {'Image': 'mariadb:10.11.19'},
            'State': {'Running': True},
            'HostConfig': {'PortBindings': {'3306/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '3307'}]}},
            'NetworkSettings': {'Ports': {'3306/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '3307'}]}},
            'Mounts': [],
        }
        obj.update(overrides)
        return [obj]

    def test_exact_existing_mariadb_metadata_is_accepted(self):
        warnings, description = preflight.validate_docker_metadata(self.docker_info())
        self.assertEqual([], warnings)
        self.assertIn('mariadb:10.11.19', description)

    def test_wrong_name_image_stopped_or_port_is_refused(self):
        broken = (
            {'Name': '/another-db'},
            {'Config': {'Image': 'mariadb:latest'}},
            {'State': {'Running': False}},
            {'HostConfig': {'PortBindings': {'3306/tcp': [{'HostPort': '3306'}]}}},
            {'NetworkSettings': {'Ports': {'3306/tcp': [{'HostPort': '3306'}]}}},
        )
        for value in broken:
            with self.subTest(value=value):
                with self.assertRaises(preflight.PreflightError):
                    preflight.validate_docker_metadata(self.docker_info(**value))

    def test_wildcard_binding_warns_not_hidden(self):
        info = self.docker_info()
        info[0]['HostConfig']['PortBindings']['3306/tcp'][0]['HostIp'] = '0.0.0.0'
        warnings, _ = preflight.validate_docker_metadata(info)
        self.assertTrue(any('beyond loopback' in item for item in warnings))

    def test_persistent_mounts_warn_and_do_not_claim_disposability(self):
        warnings, _ = preflight.validate_docker_metadata(self.docker_info(Mounts=[{'Type': 'volume'}]))
        self.assertTrue(any('data ownership' in item for item in warnings))

    def test_only_docker_inspect_is_invoked(self):
        payload = self.docker_info()
        with mock.patch.object(preflight.subprocess, 'run', return_value=mock.Mock(
            returncode=0, stdout=json.dumps(payload))) as runner:
            result = preflight.read_docker_metadata()
        self.assertEqual(payload, result)
        runner.assert_called_once_with(
            ['docker', 'inspect', 'cb-base-test-db'], capture_output=True,
            text=True, check=False, timeout=10,
        )

    def test_docker_missing_and_bad_json_are_refused(self):
        with mock.patch.object(preflight.subprocess, 'run', return_value=mock.Mock(returncode=1, stdout='')):
            with self.assertRaises(preflight.PreflightError):
                preflight.read_docker_metadata()
        with mock.patch.object(preflight.subprocess, 'run', return_value=mock.Mock(returncode=0, stdout='bad-json')):
            with self.assertRaises(preflight.PreflightError):
                preflight.read_docker_metadata()

    def test_git_source_identity_is_read_only(self):
        fake_sha = 'a' * 40
        with mock.patch.object(preflight.subprocess, 'run', return_value=mock.Mock(
            returncode=0, stdout=fake_sha + '\n')) as runner:
            self.assertEqual(fake_sha, preflight.read_source_commit(self.folder))
        runner.assert_called_once_with(
            ['git', '-C', str(self.folder), 'rev-parse', '--verify', 'HEAD'],
            capture_output=True, text=True, check=False, timeout=10,
        )

    def test_git_invalid_sha_is_refused(self):
        with mock.patch.object(preflight.subprocess, 'run', return_value=mock.Mock(
            returncode=0, stdout='invalid\n')):
            with self.assertRaises(preflight.PreflightError):
                preflight.read_source_commit(self.folder)

    def test_integration_runner_is_explicitly_blocked(self):
        runner = ROOT / 'tools/check-integration'
        completed = subprocess.run(['bash', str(runner)], capture_output=True, text=True)
        self.assertEqual(2, completed.returncode, completed.stdout + completed.stderr)
        self.assertIn('BLOCKED', completed.stderr)

    def test_unsupported_mode_is_refused(self):
        runner = ROOT / 'tools/check-integration'
        completed = subprocess.run(['bash', str(runner), '--reset-db'], capture_output=True, text=True)
        self.assertEqual(64, completed.returncode)


if __name__ == '__main__':
    unittest.main()
