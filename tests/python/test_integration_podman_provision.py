"""Base-only Podman isolation and explicit new-resource provisioning contracts.

Everything is mocked or uses a disposable temporary path. No Podman, SQL or
filesystem resource outside TemporaryDirectory is modified by these tests.
"""
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

from tools.integration import database, preflight, provision


class BasePodmanIsolationTest(unittest.TestCase):
    def test_identity_is_distinct_from_bookings_and_old_shared_docker_slot(self):
        self.assertEqual("podman", database.ENGINE)
        self.assertEqual("cb-base-l2-db", database.CONTAINER)
        self.assertEqual("cb-base-l2-db-data", database.VOLUME)
        self.assertEqual("127.0.0.1:3308", database.HOST)
        self.assertNotIn("bookings", database.VOLUME)

    def test_rootless_user_and_podman_are_mandatory(self):
        with mock.patch.object(database.os, "geteuid", return_value=0), \
             mock.patch.object(database.subprocess, "run") as run:
            with self.assertRaisesRegex(database.IsolationError, "rootful"):
                database.rootless()
        run.assert_not_called()

        with mock.patch.object(database.os, "geteuid", return_value=1000), \
             mock.patch.object(database.subprocess, "run",
                               return_value=mock.Mock(returncode=0, stdout="false\n")):
            with self.assertRaisesRegex(database.IsolationError, "rootless"):
                database.rootless()

        with mock.patch.object(database.os, "geteuid", return_value=1000), \
             mock.patch.object(database.subprocess, "run",
                               return_value=mock.Mock(returncode=0, stdout="true\n")) as run:
            database.rootless()
        self.assertEqual(["podman", "info", "--format", "{{.Host.Security.Rootless}}"],
                         run.call_args.args[0])

    def test_provision_refuses_any_preexisting_container_or_volume(self):
        for kind, name in (("container", database.CONTAINER), ("volume", database.VOLUME)):
            with self.subTest(kind=kind):
                with mock.patch.object(database.subprocess, "run",
                                       return_value=mock.Mock(returncode=0)) as run:
                    with self.assertRaisesRegex(database.IsolationError, "already exists"):
                        database.absent(kind, name)
                self.assertEqual(["podman", kind, "exists", name], run.call_args.args[0])

    def test_provision_fails_closed_on_uncertain_resource_inventory(self):
        with mock.patch.object(database.subprocess, "run",
                               return_value=mock.Mock(returncode=125)):
            with self.assertRaisesRegex(database.IsolationError, "Unknown"):
                database.absent("volume", database.VOLUME)

    def test_new_only_provisioning_uses_owned_volume_and_loopback(self):
        with tempfile.TemporaryDirectory() as folder:
            lock = Path(folder) / "absent.lock"
            with mock.patch.object(provision.execution, "TEST_ROOT", Path(folder) / "absent"), \
                 mock.patch.object(provision.execution, "LOCK_FILE", lock), \
                 mock.patch.object(provision.preflight, "verify_parent_security"), \
                 mock.patch.object(provision.preflight, "validate_test_root", return_value="absent"), \
                 mock.patch.object(provision.plan, "verify_source"), \
                 mock.patch.object(provision.database, "rootless"), \
                 mock.patch.object(provision.database, "absent") as absent, \
                 mock.patch.object(provision, "port_available"), \
                 mock.patch.object(provision, "run_new") as run_new, \
                 mock.patch.object(provision.preflight, "read_podman_metadata", return_value={}), \
                 mock.patch.object(provision.preflight, "validate_podman_metadata"), \
                 mock.patch.object(provision.subprocess, "run",
                                   return_value=mock.Mock(returncode=0, stdout="1\n")):
                provision.provision()
        self.assertEqual(2, absent.call_count)
        self.assertEqual(2, run_new.call_count)
        volume = run_new.call_args_list[0].args[0]
        container = run_new.call_args_list[1].args[0]
        self.assertEqual(volume[:3], ["podman", "volume", "create"])
        self.assertIn(database.VOLUME, volume)
        self.assertIn(database.LABEL, volume)
        self.assertEqual(container[:3], ["podman", "run", "--detach"])
        self.assertIn(f"127.0.0.1:{database.PORT}:3306", container)
        self.assertIn(f"{database.VOLUME}:/var/lib/mysql:Z", container)
        self.assertIn(database.LABEL, container)
        self.assertNotIn("--replace", container)
        self.assertFalse(any(part in container for part in ("rm", "stop", "reset")))

    def test_provision_refuses_occupied_port_before_volume_mutation(self):
        with mock.patch.object(provision.socket, "socket", side_effect=OSError("busy")):
            with self.assertRaisesRegex(preflight.PreflightError, "occupied"):
                provision.port_available()

    def test_missing_operator_approval_blocks_without_provision(self):
        with mock.patch.dict(os.environ, {"CB_BASE_DB_PROVISION_APPROVAL": ""}), \
             mock.patch.object(provision, "provision") as runner:
            self.assertEqual(2, provision.main(["--provision-db"]))
        runner.assert_not_called()

    def test_unsupported_provision_mode_cannot_mutate(self):
        with mock.patch.object(provision, "provision") as runner:
            self.assertEqual(64, provision.main(["--reset"]))
        runner.assert_not_called()


if __name__ == "__main__":
    unittest.main()
