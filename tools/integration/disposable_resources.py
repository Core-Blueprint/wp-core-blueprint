"""Disposable Base-only rootless Podman resource lifecycle.

Only generated run identities can reach this module. Never discover, adopt,
restart or remove the historical cb-base-l2-db / cb-base-l2-db-data resources.
All cleanup is opt-in via the disposable caller and verified before mutation.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import time
from dataclasses import dataclass

from tools.integration import preflight

IMAGE = "docker.io/library/mariadb:10.11.19"
LABEL_KEY = "io.coreblueprint.level2.owner"
RUN_PATTERN = re.compile(r"d[0-9a-f]{12}\Z")
PORT_PATTERN = re.compile(r"127\.0\.0\.1:([0-9]{4,5})\Z")


def call(args: list[str], *, env: dict[str, str] | None = None,
         timeout: int = 30) -> subprocess.CompletedProcess:
    try:
        return subprocess.run(args, capture_output=True, text=True,
                              check=False, timeout=timeout, env=env)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise preflight.PreflightError(
            "Disposable Podman command failed or timed out; inspect resources by run identity."
        ) from error


def require_success(args: list[str], *, env: dict[str, str] | None = None,
                    timeout: int = 30) -> str:
    result = call(args, env=env, timeout=timeout)
    if result.returncode:
        # Do not leak database credentials or display untrusted Podman stderr.
        raise preflight.PreflightError(
            "Disposable Podman operation failed; owned resources left for inspection."
        )
    return result.stdout.strip()


def absent(kind: str, name: str) -> None:
    result = call(["podman", kind, "exists", name])
    if result.returncode != 1:
        raise preflight.PreflightError(
            "Disposable resource name collision or ambiguous Podman inventory."
        )


def require_rootless() -> None:
    if os.geteuid() == 0:
        raise preflight.PreflightError("Refusing rootful Podman.")
    if require_success(["podman", "info", "--format",
                        "{{.Host.Security.Rootless}}"], timeout=20).lower() != "true":
        raise preflight.PreflightError("Rootless Podman is required.")


@dataclass
class Resources:
    token: str
    password: str
    container_id: str = ""
    port: str = ""
    volume_created: bool = False

    def __post_init__(self) -> None:
        if RUN_PATTERN.fullmatch(self.token) is None:
            raise preflight.PreflightError("Untrusted disposable run token.")
        if re.fullmatch(r"[A-Za-z0-9_-]{24,100}", self.password) is None:
            raise preflight.PreflightError("Invalid disposable database credential.")

    @property
    def container(self) -> str:
        return "cb-base-l2-" + self.token

    @property
    def volume(self) -> str:
        return self.container + "-data"

    @property
    def label(self) -> str:
        return f"{LABEL_KEY}=base-disposable-{self.token}"

    def child_env(self, base: dict[str, str]) -> dict[str, str]:
        if not self.port:
            raise preflight.PreflightError("Disposable database port not established.")
        return {**base,
                "CB_BASE_DISPOSABLE_TOKEN": self.token,
                "CB_BASE_DB_PORT": self.port,
                "CB_BASE_DB_PASSWORD": self.password,
                "CB_BASE_TEST_SLOT": self.token}

    def create(self, base: dict[str, str]) -> None:
        require_rootless()
        absent("container", self.container)
        absent("volume", self.volume)
        require_success(["podman", "volume", "create",
                         "--label", self.label, self.volume])
        self.volume_created = True
        # An empty host port instructs Podman to allocate an available loopback
        # port itself. Never reserve/release a port in another process.
        output = require_success([
            "podman", "run", "--detach", "--name", self.container,
            "--label", self.label, "--publish", "127.0.0.1::3306",
            "--volume", f"{self.volume}:/var/lib/mysql:Z",
            "--env", "MARIADB_ROOT_PASSWORD", IMAGE,
        ], env={**base, "MARIADB_ROOT_PASSWORD": self.password}, timeout=180)
        if re.fullmatch(r"[a-f0-9]{64}", output) is None:
            raise preflight.PreflightError(
                "Disposable Podman returned an ambiguous container ID.")
        self.container_id = output
        mapping = require_success(["podman", "port", self.container_id, "3306/tcp"])
        match = PORT_PATTERN.fullmatch(mapping)
        if match is None or not 1024 <= int(match.group(1)) <= 65535:
            raise preflight.PreflightError("Disposable port is not loopback-only.")
        self.port = match.group(1)

    def wait_ready(self) -> None:
        for _ in range(45):
            result = call(["podman", "exec", "--env", "MYSQL_PWD",
                           self.container_id, "mariadb", "-uroot", "--batch",
                           "--skip-column-names", "-e", "SELECT 1;"],
                          env={**os.environ, "MYSQL_PWD": self.password},
                          timeout=10)
            if result.returncode == 0 and result.stdout.strip() == "1":
                return
            time.sleep(2)
        raise preflight.PreflightError(
            "Disposable MariaDB did not become ready; preserve diagnostic workspace.")

    def verify_container_for_cleanup(self, env: dict[str, str]) -> None:
        if not self.container_id or not self.port:
            raise preflight.PreflightError("Missing exact disposable container identity.")
        inspected = require_success(["podman", "container", "inspect",
                                     "--format", "{{.Id}}", self.container])
        if inspected != self.container_id:
            raise preflight.PreflightError("Disposable container ID changed.")
        proof = call(["python3", "-B", "-c",
                      "from tools.integration import database; "
                      "database.validate_metadata(database.read_metadata(), "
                      "require_running=False)"],
                     env=env, timeout=35)
        if proof.returncode:
            raise preflight.PreflightError(
                "Disposable container/volume ownership validation failed; no cleanup.")

    def verify_volume_for_cleanup(self) -> None:
        output = require_success(["podman", "volume", "inspect", self.volume])
        try:
            entries = json.loads(output)
        except (ValueError, TypeError) as error:
            raise preflight.PreflightError("Untrusted disposable volume JSON.") from error
        if not isinstance(entries, list) or len(entries) != 1 or not isinstance(entries[0], dict):
            raise preflight.PreflightError("Invalid disposable volume metadata.")
        labels = entries[0].get("Labels")
        if (entries[0].get("Name") != self.volume
                or not isinstance(labels, dict)
                or labels.get(LABEL_KEY) != "base-disposable-" + self.token):
            raise preflight.PreflightError("Disposable volume identity changed.")
        consumers = require_success(["podman", "ps", "--all",
                                     "--filter", f"volume={self.volume}",
                                     "--format", "{{.Names}}"])
        if consumers:
            raise preflight.PreflightError(
                "Disposable volume still has consumers; refusing deletion.")

    def cleanup(self, env: dict[str, str]) -> None:
        """Remove only resources freshly created and independently verified here.

        Leave both resources untouched when proof is ambiguous. A failed create
        may have produced an unknown container; never guess an ID or delete it.
        """
        if self.container_id:
            self.verify_container_for_cleanup(env)
            require_success(["podman", "rm", "--force", self.container_id], timeout=45)
            self.container_id = ""
        if self.volume_created:
            self.verify_volume_for_cleanup()
            require_success(["podman", "volume", "rm", self.volume], timeout=45)
            self.volume_created = False
