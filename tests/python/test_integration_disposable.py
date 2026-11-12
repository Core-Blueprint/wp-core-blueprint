"""Base disposable Level 2: mocked safety / lifecycle regressions (no Podman)."""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

from tools.integration import disposable, disposable_resources as resources, preflight


SHA = "a" * 40
TOKEN = "dabcdef012345"
IDENTITY = "b" * 64


class DisposableIdentityTests(unittest.TestCase):
    def test_generated_identity_is_not_the_legacy_base_database(self):
        item = resources.Resources(TOKEN, "Z" * 32)
        self.assertEqual("cb-base-l2-" + TOKEN, item.container)
        self.assertEqual(item.container + "-data", item.volume)
        self.assertNotEqual("cb-base-l2-db", item.container)
        self.assertNotEqual("cb-base-l2-db-data", item.volume)
        self.assertEqual("io.coreblueprint.level2.owner=base-disposable-" + TOKEN,
                         item.label)

    def test_invalid_token_and_password_are_fail_closed(self):
        for token in ("", "base", "d" + "f" * 11, "d" + "f" * 13,
                      "d" + "X" * 12, "../data", "d" + "f" * 12 + ";"):
            with self.subTest(token=token), self.assertRaises(preflight.PreflightError):
                resources.Resources(token, "Z" * 32)
        for password in ("", "root", "unsafe whitespace", "x" * 101):
            with self.subTest(password=password), self.assertRaises(preflight.PreflightError):
                resources.Resources(TOKEN, password)

    def test_disposable_connection_parameters_are_bounded_and_legacy_is_unmodified(self):
        script = ("import json; from tools.integration import database; "
                  "print(json.dumps({'container': database.CONTAINER, "
                  "'volume': database.VOLUME, 'port': database.PORT, "
                  "'password': database.ROOT_PASSWORD, 'label': database.LABEL_VALUE}))")
        env = {**os.environ, "CB_BASE_DISPOSABLE_TOKEN": TOKEN,
               "CB_BASE_DB_PORT": "45673", "CB_BASE_DB_PASSWORD": "Z" * 32}
        result = subprocess.run([sys.executable, "-B", "-c", script],
                                cwd=disposable.SOURCE, env=env,
                                capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        payload = json.loads(result.stdout)
        self.assertEqual("cb-base-l2-" + TOKEN, payload["container"])
        self.assertEqual("cb-base-l2-" + TOKEN + "-data", payload["volume"])
        self.assertEqual("45673", payload["port"])
        self.assertEqual("Z" * 32, payload["password"])
        self.assertEqual("base-disposable-" + TOKEN, payload["label"])

    def test_invalid_disposable_environment_never_selects_legacy_resources(self):
        script = "from tools.integration import database"
        for overrides in (
            {"CB_BASE_DISPOSABLE_TOKEN": TOKEN, "CB_BASE_DB_PORT": "3308",
             "CB_BASE_DB_PASSWORD": "root"},
            {"CB_BASE_DISPOSABLE_TOKEN": TOKEN, "CB_BASE_DB_PORT": "44321",
             "CB_BASE_DB_PASSWORD": ""},
            {"CB_BASE_DISPOSABLE_TOKEN": "", "CB_BASE_DB_PORT": "44321",
             "CB_BASE_DB_PASSWORD": ""},
            {"CB_BASE_DISPOSABLE_TOKEN": "wrong", "CB_BASE_DB_PORT": "44321",
             "CB_BASE_DB_PASSWORD": "Z" * 32},
        ):
            with self.subTest(overrides=overrides):
                result = subprocess.run(
                    [sys.executable, "-B", "-c", script],
                    cwd=disposable.SOURCE,
                    env={**disposable.isolated_environment(), **overrides},
                    capture_output=True, text=True)
                self.assertNotEqual(0, result.returncode)

    def test_stopped_disposable_metadata_requires_durable_port_and_owner(self):
        from tools.integration import database
        with mock.patch.object(database, "CONTAINER", "cb-base-l2-" + TOKEN), \
             mock.patch.object(database, "VOLUME", "cb-base-l2-" + TOKEN + "-data"), \
             mock.patch.object(database, "LABEL_VALUE", "base-disposable-" + TOKEN), \
             mock.patch.object(database, "PORT", "45673"):
            volume_name = database.VOLUME
            owner = "base-disposable-" + TOKEN
            mount = "/tmp/fake-owned-database-volume"
            payload = {
                "containers": [{
                    "Name": database.CONTAINER,
                    "Config": {"Image": database.IMAGE,
                               "Labels": {database.LABEL_KEY: owner}},
                    "State": {"Running": False},
                    "Mounts": [{"Type": "volume", "Name": volume_name,
                                "Destination": "/var/lib/mysql", "RW": True,
                                "Source": mount}],
                    "HostConfig": {
                        "NetworkMode": "bridge",
                        "PortBindings": {
                            "3306/tcp": [{"HostIp": "127.0.0.1", "HostPort": "45673"}]
                        }
                    },
                    "NetworkSettings": {"Ports": None}
                }],
                "volumes": [{"Name": volume_name,
                             "Labels": {database.LABEL_KEY: owner},
                             "Mountpoint": mount}],
                "consumers": [database.CONTAINER],
            }
            self.assertIn(volume_name, database.validate_metadata(
                payload, require_running=False))
            with self.assertRaises(database.IsolationError):
                database.validate_metadata(payload)  # Running required for actual tests.
            payload["containers"][0]["HostConfig"]["PortBindings"]["3306/tcp"][0]["HostIp"] = "0.0.0.0"
            with self.assertRaises(database.IsolationError):
                database.validate_metadata(payload, require_running=False)

    def test_inherited_customer_database_configuration_is_not_used(self):
        with mock.patch.dict(os.environ, {
            "WP_DB_HOST": "production.example", "WP_DB_PASSWORD": "secret",
            "WP_CORE_DIR": "/var/www/customer", "CB_TEST_ROOT": "/unsafe",
            "CB_BASE_TEST_SLOT": "oldslot", "CB_BASE_DB_PASSWORD": "oldsecret",
            "DATABASE_URL": "mysql://prod", "PATH": "/usr/bin",
        }):
            env = disposable.isolated_environment()
        for name in ("WP_DB_HOST", "WP_DB_PASSWORD", "WP_CORE_DIR", "CB_TEST_ROOT",
                     "CB_BASE_TEST_SLOT", "CB_BASE_DB_PASSWORD", "DATABASE_URL"):
            self.assertNotIn(name, env)
        self.assertEqual("/usr/bin", env["PATH"])


class ResourceLifecycleTests(unittest.TestCase):
    def test_create_requests_unique_volume_random_loopback_port_and_exact_id(self):
        item = resources.Resources(TOKEN, "Z" * 32)
        with mock.patch.object(resources, "require_rootless"), \
             mock.patch.object(resources, "absent") as absent, \
             mock.patch.object(resources, "require_success",
                               side_effect=[item.volume, IDENTITY,
                                            "127.0.0.1:42612"]) as run:
            item.create({"PATH": "/usr/bin"})
        self.assertEqual("42612", item.port)
        self.assertEqual(IDENTITY, item.container_id)
        self.assertTrue(item.volume_created)
        self.assertEqual(2, absent.call_count)
        run_command = run.call_args_list[1].args[0]
        self.assertEqual(run_command[:3], ["podman", "run", "--detach"])
        self.assertIn("127.0.0.1::3306", run_command)
        self.assertIn("MARIADB_ROOT_PASSWORD", run_command)
        self.assertNotIn("Z" * 32, run_command)
        self.assertEqual("Z" * 32, run.call_args_list[1].kwargs["env"]["MARIADB_ROOT_PASSWORD"])

    def test_readiness_passes_ephemeral_password_by_environment_not_argv(self):
        item = resources.Resources(TOKEN, "Z" * 32)
        item.container_id = IDENTITY
        with mock.patch.object(resources, "call",
                               return_value=mock.Mock(returncode=0, stdout="1\n")) as run:
            item.wait_ready()
        args = run.call_args.args[0]
        self.assertIn("--env", args)
        self.assertIn("MYSQL_PWD", args)
        self.assertNotIn("Z" * 32, " ".join(args))
        self.assertEqual("Z" * 32, run.call_args.kwargs["env"]["MYSQL_PWD"])

    def test_public_or_ambiguous_port_fails_closed(self):
        for bad in ("0.0.0.0:42612", "127.0.0.1:330", "localhost:42612", ""):
            item = resources.Resources(TOKEN, "Z" * 32)
            with mock.patch.object(resources, "require_rootless"), \
                 mock.patch.object(resources, "absent"), \
                 mock.patch.object(resources, "require_success",
                                   side_effect=[item.volume, IDENTITY, bad]):
                with self.subTest(port=bad), self.assertRaises(preflight.PreflightError):
                    item.create({})
            self.assertTrue(item.volume_created)
            self.assertEqual(IDENTITY, item.container_id)
            self.assertFalse(item.port)

    def test_cleanup_requires_container_id_and_storage_proof_before_deleting(self):
        item = resources.Resources(TOKEN, "Z" * 32)
        item.volume_created = True
        item.container_id = IDENTITY
        item.port = "42612"
        with mock.patch.object(item, "verify_container_for_cleanup") as container, \
             mock.patch.object(item, "verify_volume_for_cleanup") as volume, \
             mock.patch.object(resources, "require_success", return_value="") as run:
            item.cleanup({"CB_BASE_DISPOSABLE_TOKEN": TOKEN})
        container.assert_called_once()
        volume.assert_called_once()
        self.assertEqual(["podman", "rm", "--force", IDENTITY],
                         run.call_args_list[0].args[0])
        self.assertEqual(["podman", "volume", "rm", "cb-base-l2-" + TOKEN + "-data"],
                         run.call_args_list[1].args[0])
        self.assertFalse(item.volume_created)

    def test_ambiguous_container_never_reaches_delete(self):
        item = resources.Resources(TOKEN, "Z" * 32)
        item.volume_created = True
        item.container_id = IDENTITY
        item.port = "42612"
        with mock.patch.object(item, "verify_container_for_cleanup",
                               side_effect=preflight.PreflightError("ownership")), \
             mock.patch.object(resources, "require_success") as run:
            with self.assertRaises(preflight.PreflightError):
                item.cleanup({})
        run.assert_not_called()
        self.assertTrue(item.volume_created)

    def test_unproven_volume_owner_is_protected(self):
        item = resources.Resources(TOKEN, "Z" * 32)
        with mock.patch.object(resources, "require_success", return_value=json.dumps([
            {"Name": item.volume, "Labels": {"io.coreblueprint.level2.owner": "foreign"}}
        ])):
            with self.assertRaisesRegex(preflight.PreflightError, "identity changed"):
                item.verify_volume_for_cleanup()

    def test_partial_volume_only_cleanup_is_scoped(self):
        item = resources.Resources(TOKEN, "Z" * 32)
        item.volume_created = True
        with mock.patch.object(item, "verify_volume_for_cleanup") as volume, \
             mock.patch.object(resources, "require_success", return_value="") as run:
            item.cleanup({})
        volume.assert_called_once()
        self.assertEqual(1, run.call_count)
        self.assertEqual(["podman", "volume", "rm", item.volume],
                         run.call_args.args[0])

    def test_refuses_to_assume_any_nonzero_podman_exit_means_absent(self):
        with mock.patch.object(resources, "call", return_value=mock.Mock(returncode=125)):
            with self.assertRaisesRegex(preflight.PreflightError, "ambiguous"):
                resources.absent("container", "cb-base-l2-" + TOKEN)


class DisposableSupervisorTests(unittest.TestCase):
    def test_cli_requires_explicit_approval_without_any_resource_operation(self):
        with mock.patch.dict(os.environ, {"CB_BASE_DISPOSABLE_APPROVAL": ""}), \
             mock.patch.object(disposable, "qualify") as qualify:
            self.assertEqual(2, disposable.main(["--execute"]))
            qualify.assert_not_called()
        cmd = subprocess.run(
            ["bash", str(disposable.SOURCE / "tools/check-integration"), "--disposable"],
            env={**disposable.isolated_environment(), "CB_BASE_DISPOSABLE_APPROVAL": ""},
            capture_output=True, text=True)
        self.assertEqual(2, cmd.returncode)
        self.assertIn("BLOCKED", cmd.stderr)

    def test_disposable_preflight_is_read_only(self):
        with mock.patch.object(disposable, "verify_source", return_value=SHA), \
             mock.patch.object(resources.Resources, "create") as create, \
             mock.patch.object(resources.Resources, "cleanup") as cleanup:
            self.assertEqual(0, disposable.main(["--preflight"]))
            create.assert_not_called()
            cleanup.assert_not_called()

    def mocked_qualify(self, *, matrix_status=0, cleanup_error=False,
                       inspect_status=None) -> tuple[int, list, mock.Mock]:
        reports = []
        fake = mock.Mock()
        fake.token = TOKEN
        fake.container = "cb-base-l2-" + TOKEN
        fake.volume = fake.container + "-data"
        fake.child_env.return_value = {
            **disposable.isolated_environment(), "CB_BASE_TEST_SLOT": TOKEN,
            "CB_BASE_DISPOSABLE_TOKEN": TOKEN}
        if cleanup_error:
            fake.cleanup.side_effect = preflight.PreflightError("unverified resource")
        def command(args, env, **kwargs):
            if args[:3] == ["git", "rev-parse", "--verify"]:
                return SHA
            if args[-2:] == ["--inspect"] or args[-1] == "--inspect":
                return inspect_status or (f"Recorded source: {SHA}\nCurrent source: {SHA}\n"
                                          "Level 2 outcome: PASS (recorded full Level 2 matrix")
            return ""
        with mock.patch.object(disposable, "verify_source", return_value=SHA), \
             mock.patch.object(disposable.secrets, "token_hex", return_value=TOKEN[1:]), \
             mock.patch.object(disposable.secrets, "token_urlsafe", return_value="Z" * 32), \
             mock.patch.object(disposable.resources, "Resources", return_value=fake), \
             mock.patch.object(disposable, "make_receipt_directory",
                               return_value=Path("/tmp/owned-proof")), \
             mock.patch.object(disposable, "write_once",
                               side_effect=lambda _d, name, payload: reports.append((name,payload))), \
             mock.patch.object(disposable, "command", side_effect=command), \
             mock.patch.object(disposable, "run_wordpress", return_value=matrix_status), \
             mock.patch.object(disposable, "copy_gate_receipts", return_value=25):
            status = disposable.qualify()
        return status, reports, fake

    def test_full_matrix_and_cleanup_required_for_success(self):
        status, receipts, fake = self.mocked_qualify()
        self.assertEqual(0, status)
        self.assertTrue(fake.create.called)
        self.assertTrue(fake.wait_ready.called)
        self.assertTrue(fake.cleanup.called)
        self.assertEqual("pass", receipts[-1][1]["status"])
        self.assertTrue(receipts[-1][1]["resources_removed"])
        self.assertEqual(25, receipts[-1][1]["archived_gate_receipts"])

    def test_matrix_failure_still_cleans_only_owned_resources_and_refuses_pass(self):
        status, receipts, fake = self.mocked_qualify(matrix_status=1)
        self.assertEqual(1, status)
        self.assertTrue(fake.cleanup.called)
        self.assertEqual("fail", receipts[-1][1]["status"])

    def test_abnormally_signal_terminated_runner_preserves_resources(self):
        status, receipts, fake = self.mocked_qualify(matrix_status=-9)
        self.assertEqual(1, status)
        fake.cleanup.assert_not_called()
        self.assertEqual("fail", receipts[-1][1]["status"])
        self.assertFalse(receipts[-1][1]["resources_removed"])

    def test_cleanup_failure_is_not_misreported_as_qualification_pass(self):
        status, receipts, fake = self.mocked_qualify(cleanup_error=True)
        self.assertEqual(1, status)
        self.assertEqual("fail", receipts[-1][1]["status"])
        self.assertFalse(receipts[-1][1]["resources_removed"])

    def test_stale_or_ambiguous_wordpress_receipt_cannot_be_promoted_to_pass(self):
        status, receipts, _fake = self.mocked_qualify(
            inspect_status=("Recorded source: " + SHA + "\n"
                            "Level 2 outcome: INCOMPLETE"))
        self.assertEqual(1, status)
        self.assertEqual("fail", receipts[-1][1]["status"])

    def test_gate_evidence_archive_is_no_follow_and_new_only(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            source = root / "test-root"
            evidence = source / "evidence"
            evidence.mkdir(parents=True)
            destination = root / "report"
            destination.mkdir()
            (evidence / "01-pass.json").write_text('{"status":"pass"}\n')
            self.assertEqual(1, disposable.copy_gate_receipts(source, destination))
            self.assertTrue((destination / "gates/01-pass.json").is_file())
            with self.assertRaises(FileExistsError):
                disposable.copy_gate_receipts(source, destination)

    def test_symlink_evidence_is_never_read_or_archived(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            source = root / "test-root"
            evidence = source / "evidence"
            evidence.mkdir(parents=True)
            target = root / "secret.txt"
            target.write_text("do not open")
            (evidence / "01-pass.json").symlink_to(target)
            destination = root / "report"
            destination.mkdir()
            with self.assertRaises(preflight.PreflightError):
                disposable.copy_gate_receipts(source, destination)
            self.assertFalse((destination / "gates/01-pass.json").exists())

    def test_owner_only_receipt_creation_refuses_collision(self):
        with tempfile.TemporaryDirectory() as folder:
            dest = Path(folder)
            disposable.write_once(dest, "00-start.json", {"status": "running"})
            with self.assertRaises(FileExistsError):
                disposable.write_once(dest, "00-start.json", {"status": "pass"})
            self.assertEqual({"status": "running"},
                             json.loads((dest / "00-start.json").read_text()))

    def test_source_guard_rejects_dirty_checkout_before_podman_creation(self):
        with mock.patch.object(disposable, "verify_source",
                               side_effect=preflight.PreflightError("dirty checkout")), \
             mock.patch.object(resources.Resources, "create") as create:
            with self.assertRaises(preflight.PreflightError):
                disposable.qualify()
            create.assert_not_called()


if __name__ == "__main__":
    unittest.main()
