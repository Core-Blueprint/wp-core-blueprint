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

    def test_root_owned_sticky_tmp_parent_is_allowed_for_base_workspace(self):
        result = preflight.verify_parent_security(preflight.CANONICAL_TEST_ROOT)
        self.assertIn('sticky /tmp', result)
        self.assertEqual(preflight.CANONICAL_TEST_ROOT.parent, Path('/tmp'))

    def test_group_writable_shared_parent_is_refused_before_execution(self):
        shared = self.folder / 'shared'
        shared.mkdir(mode=0o775)
        shared.chmod(0o775)
        with self.assertRaisesRegex(preflight.PreflightError, 'Test parent'):
            preflight.verify_parent_security(shared / 'core-blueprint')
        self.assertEqual(0o775, shared.stat().st_mode & 0o777)

    def test_private_operator_parent_is_allowed(self):
        private = self.folder / 'private'
        private.mkdir(mode=0o700)
        self.assertIn(
            'operator-owned private', preflight.verify_parent_security(private / 'core-blueprint')
        )

    def test_symlink_parent_is_refused(self):
        target = self.folder / 'private-real'
        target.mkdir(mode=0o700)
        linked = self.folder / 'private-link'
        linked.symlink_to(target, target_is_directory=True)
        with self.assertRaisesRegex(preflight.PreflightError, 'symlink'):
            preflight.verify_parent_security(linked / 'core-blueprint')

    def podman_info(self, **overrides):
        obj = {
            "Name": "cb-base-l2-db",
            "Config": {
                "Image": "docker.io/library/mariadb:10.11.19",
                "Labels": {"io.coreblueprint.level2.owner": "base-isolated"},
            },
            "State": {"Running": True},
            "HostConfig": {
                "NetworkMode": "slirp4netns",
                "PortBindings": {"3306/tcp": [{"HostIp": "127.0.0.1", "HostPort": "3308"}]}
            },
            "NetworkSettings": {
                "Ports": {"3306/tcp": [{"HostIp": "127.0.0.1", "HostPort": "3308"}]}
            },
            "Mounts": [{
                "Type": "volume", "Name": "cb-base-l2-db-data",
                "Source": "/home/test/volumes/cb-base-l2-db-data/_data",
                "Destination": "/var/lib/mysql", "RW": True,
            }],
        }
        obj.update(overrides)
        return {
            "containers": [obj],
            "volumes": [{
                "Name": "cb-base-l2-db-data",
                "Mountpoint": "/home/test/volumes/cb-base-l2-db-data/_data",
                "Labels": {"io.coreblueprint.level2.owner": "base-isolated"},
            }],
            "consumers": ["cb-base-l2-db"],
        }

    def test_dedicated_podman_metadata_is_accepted(self):
        warnings, description = preflight.validate_podman_metadata(self.podman_info())
        self.assertEqual([], warnings)
        self.assertIn("owned volume", description)
        self.assertIn("rootless Podman", description)

    def test_wrong_name_image_stopped_or_port_is_refused(self):
        broken = (
            {"Name": "cb-base-test-db"},
            {"Config": {"Image": "docker.io/library/mariadb:latest",
                        "Labels": {"io.coreblueprint.level2.owner": "base-isolated"}}},
            {"State": {"Running": False}},
            {"HostConfig": {"PortBindings": {"3306/tcp": [
                {"HostIp": "127.0.0.1", "HostPort": "3307"}]}}},
        )
        for value in broken:
            with self.subTest(value=value):
                with self.assertRaises(preflight.PreflightError):
                    preflight.validate_podman_metadata(self.podman_info(**value))

    def test_public_port_or_extra_bindings_are_refused(self):
        info = self.podman_info()
        info["containers"][0]["HostConfig"]["PortBindings"]["3306/tcp"][0]["HostIp"] = "0.0.0.0"
        with self.assertRaisesRegex(preflight.PreflightError, "bind only"):
            preflight.validate_podman_metadata(info)
        info = self.podman_info()
        info["containers"][0]["NetworkSettings"]["Ports"]["80/tcp"] = []
        with self.assertRaisesRegex(preflight.PreflightError, "port mapping"):
            preflight.validate_podman_metadata(info)

    def test_host_network_mode_is_refused(self):
        info = self.podman_info()
        info["containers"][0]["HostConfig"]["NetworkMode"] = "host"
        with self.assertRaisesRegex(preflight.PreflightError, "network mode"):
            preflight.validate_podman_metadata(info)

    def test_unowned_or_shared_persistent_storage_is_refused(self):
        info = self.podman_info()
        info["volumes"][0]["Labels"] = {}
        with self.assertRaisesRegex(preflight.PreflightError, "ownership"):
            preflight.validate_podman_metadata(info)
        info = self.podman_info()
        info["consumers"].append("cb-bookings")
        with self.assertRaisesRegex(preflight.PreflightError, "shared"):
            preflight.validate_podman_metadata(info)
        info = self.podman_info(Mounts=[{"Type": "bind", "Destination": "/var/lib/mysql"}])
        with self.assertRaisesRegex(preflight.PreflightError, "mount"):
            preflight.validate_podman_metadata(info)

    def test_rootless_podman_inspection_is_read_only(self):
        from tools.integration import database
        payload = self.podman_info()
        with mock.patch.object(database, "rootless"), \
             mock.patch.object(database.subprocess, "run", side_effect=[
                 mock.Mock(returncode=0, stdout=json.dumps(payload["containers"])),
                 mock.Mock(returncode=0, stdout=json.dumps(payload["volumes"])),
                 mock.Mock(returncode=0, stdout="cb-base-l2-db\n"),
             ]) as runner:
            self.assertEqual(payload, preflight.read_podman_metadata())
        self.assertEqual(["podman", "container", "inspect", "cb-base-l2-db"],
                         runner.call_args_list[0].args[0])
        self.assertEqual(["podman", "volume", "inspect", "cb-base-l2-db-data"],
                         runner.call_args_list[1].args[0])
        self.assertEqual("podman", runner.call_args_list[2].args[0][0])
        self.assertIn("volume=cb-base-l2-db-data", runner.call_args_list[2].args[0])

    def test_podman_missing_and_bad_json_are_refused(self):
        from tools.integration import database
        with mock.patch.object(database, "rootless"), \
             mock.patch.object(database.subprocess, "run",
                               return_value=mock.Mock(returncode=1, stdout="")):
            with self.assertRaises(preflight.PreflightError):
                preflight.read_podman_metadata()
        with mock.patch.object(database, "rootless"), \
             mock.patch.object(database.subprocess, "run",
                               return_value=mock.Mock(returncode=0, stdout="bad-json")):
            with self.assertRaises(preflight.PreflightError):
                preflight.read_podman_metadata()

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
