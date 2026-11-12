"""Dedicated rootless Podman MariaDB identity for Base Level 2.

Never adopt an unlabeled container, arbitrary volume or Docker storage.
No command in this module creates, starts, deletes or resets resources.
"""
from __future__ import annotations

import json
import os
import subprocess


class IsolationError(Exception):
    """Cannot prove the Base-only Podman database boundary."""


ENGINE = "podman"
CONTAINER = "cb-base-l2-db"
VOLUME = "cb-base-l2-db-data"
IMAGE = "docker.io/library/mariadb:10.11.19"
PORT = "3308"
HOST = f"127.0.0.1:{PORT}"
LABEL_KEY = "io.coreblueprint.level2.owner"
LABEL_VALUE = "base-isolated"
LABEL = f"{LABEL_KEY}={LABEL_VALUE}"


def rootless() -> None:
    if os.geteuid() == 0:
        raise IsolationError("Base Level 2 refuses rootful Podman.")
    try:
        result = subprocess.run(
            ["podman", "info", "--format", "{{.Host.Security.Rootless}}"],
            capture_output=True, text=True, check=False, timeout=15,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise IsolationError("Rootless Podman is unavailable.") from error
    if result.returncode != 0 or result.stdout.strip().lower() != "true":
        raise IsolationError("Base Level 2 requires a verified rootless Podman runtime.")


def _json_command(args: list[str], name: str) -> object:
    try:
        result = subprocess.run(
            args, capture_output=True, text=True, check=False, timeout=15,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise IsolationError(f"Podman {name} inspection unavailable.") from error
    if result.returncode != 0:
        raise IsolationError(f"Dedicated Base Podman {name} missing or unavailable.")
    try:
        return json.loads(result.stdout)
    except ValueError as error:
        raise IsolationError(f"Invalid Podman {name} inspection JSON.") from error


def read_metadata() -> dict:
    """Read the exact container, volume and volume consumers. Never mutate."""
    rootless()
    container = _json_command(["podman", "container", "inspect", CONTAINER], "container")
    volume = _json_command(["podman", "volume", "inspect", VOLUME], "volume")
    try:
        users = subprocess.run(
            ["podman", "ps", "--all", "--filter", f"volume={VOLUME}",
             "--format", "{{.Names}}"],
            capture_output=True, text=True, check=False, timeout=15,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise IsolationError("Podman volume-consumer inspection unavailable.") from error
    if users.returncode != 0:
        raise IsolationError("Podman volume-consumer inspection failed.")
    return {"containers": container, "volumes": volume,
            "consumers": users.stdout.splitlines()}


def _one(value: object, name: str) -> dict:
    if not isinstance(value, list) or len(value) != 1 or not isinstance(value[0], dict):
        raise IsolationError(f"Expected exactly one Base {name} object.")
    return value[0]


def validate_metadata(payload: object) -> str:
    """Fail closed for unowned storage, wrong image, shared volume or open ports."""
    if not isinstance(payload, dict):
        raise IsolationError("Invalid Podman database metadata.")
    container = _one(payload.get("containers"), "container")
    volume = _one(payload.get("volumes"), "volume")
    if container.get("Name", "").lstrip("/") != CONTAINER:
        raise IsolationError("Wrong Base Podman container name.")
    config = container.get("Config")
    if not isinstance(config, dict):
        raise IsolationError("Missing Base container configuration.")
    if config.get("Image") != IMAGE and container.get("ImageName") != IMAGE:
        raise IsolationError("Base MariaDB image does not match the pinned version.")
    if (not isinstance(config.get("Labels"), dict)
            or config["Labels"].get(LABEL_KEY) != LABEL_VALUE):
        raise IsolationError("Base container has no valid ownership label.")
    state = container.get("State")
    if not isinstance(state, dict) or state.get("Running") is not True:
        raise IsolationError("Dedicated Base Podman database is not running.")
    if (volume.get("Name") != VOLUME
            or not isinstance(volume.get("Labels"), dict)
            or volume["Labels"].get(LABEL_KEY) != LABEL_VALUE):
        raise IsolationError("Base Podman volume ownership is not proven.")
    mounts = container.get("Mounts")
    if (not isinstance(mounts, list) or len(mounts) != 1
            or not isinstance(mounts[0], dict)):
        raise IsolationError("Base database must have one dedicated volume and no bind mounts.")
    mount = mounts[0]
    if (mount.get("Type") != "volume" or mount.get("Name") != VOLUME
            or mount.get("Destination") != "/var/lib/mysql"
            or mount.get("RW") is not True
            or mount.get("Source") != volume.get("Mountpoint")):
        raise IsolationError("Base database mount does not match its owned volume.")
    consumers = payload.get("consumers")
    if consumers != [CONTAINER]:
        raise IsolationError("Base Podman database volume is shared or cannot be proven exclusive.")
    host = container.get("HostConfig")
    network = container.get("NetworkSettings")
    if not isinstance(host, dict) or not isinstance(network, dict):
        raise IsolationError("Base database port metadata is missing.")
    for mapping in (host.get("PortBindings"), network.get("Ports")):
        if not isinstance(mapping, dict) or set(mapping) != {"3306/tcp"}:
            raise IsolationError("Unexpected Base database port mapping.")
        bindings = mapping["3306/tcp"]
        if (not isinstance(bindings, list) or len(bindings) != 1
                or not isinstance(bindings[0], dict)
                or bindings[0].get("HostIp") != "127.0.0.1"
                or str(bindings[0].get("HostPort")) != PORT):
            raise IsolationError("Base MariaDB must bind only to 127.0.0.1:" + PORT + ".")
    return f"rootless Podman {IMAGE}; {CONTAINER}; owned volume {VOLUME}; {HOST}"


def absent(kind: str, name: str) -> None:
    """Return only for a proven absent Podman resource (exit 1)."""
    if kind not in ("container", "volume"):
        raise IsolationError("Unsupported Podman resource type.")
    try:
        result = subprocess.run(
            ["podman", kind, "exists", name],
            capture_output=True, text=True, check=False, timeout=15,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise IsolationError(f"Could not verify absence of Base {kind}.") from error
    if result.returncode == 0:
        raise IsolationError(f"Base {kind} {name} already exists; refusing reuse.")
    if result.returncode != 1:
        raise IsolationError(f"Unknown Base {kind} inventory response; refusing provisioning.")
