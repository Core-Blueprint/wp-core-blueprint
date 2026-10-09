#!/usr/bin/env python3
"""Read-only Base integration safety preflight, not a WordPress integration runner.

No WordPress provisioning, database queries, Docker state changes, or cleanup.
The full Level 2 runner is deliberately blocked pending separate approval.
"""

from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import sys

CANONICAL_TEST_ROOT = Path('/tmp/core-blueprint-tests/core-blueprint')
CONTAINER = 'cb-base-test-db'
IMAGE = 'mariadb:10.11.19'
PORT = '3307'


class PreflightError(Exception):
    """An unsafe or incomplete local integration prerequisite."""


def validate_test_root(candidate: str, required_root: Path = CANONICAL_TEST_ROOT) -> str:
    """Recognise only the owned Base test root; never create or clean it."""
    if not candidate or not candidate.startswith('/'):
        raise PreflightError('Test root must be an absolute path.')
    if any(part in ('.', '..', '') for part in candidate.split('/')[1:]):
        raise PreflightError('Test root must not contain dot segments or repeated separators.')
    if candidate != str(required_root):
        raise PreflightError(f'Test root must be exactly {required_root}.')

    current = Path('/')
    for segment in Path(candidate).parts[1:]:
        current = current / segment
        if current.is_symlink():
            raise PreflightError(f'Symlink found in test-root path: {current}.')
        if current.exists() and not current.is_dir():
            raise PreflightError(f'Non-directory component in test-root path: {current}.')
    root = Path(candidate)
    if root.exists() and any(root.iterdir()):
        raise PreflightError(
            'Existing non-empty Base test root is not proven disposable; manual review required.'
        )
    return 'empty' if root.is_dir() else 'absent'


def validate_docker_metadata(entries: object) -> tuple[list[str], str]:
    """Validate the named existing container from Docker inspect JSON only."""
    if not isinstance(entries, list) or len(entries) != 1 or not isinstance(entries[0], dict):
        raise PreflightError('Docker inspect must return exactly one container.')
    obj = entries[0]
    if obj.get('Name') != f'/{CONTAINER}':
        raise PreflightError('Unexpected Docker container identity.')
    if not isinstance(obj.get('Config'), dict) or obj['Config'].get('Image') != IMAGE:
        raise PreflightError('MariaDB container image does not match the canonical baseline.')
    if not isinstance(obj.get('State'), dict) or obj['State'].get('Running') is not True:
        raise PreflightError('MariaDB container is not running; preflight will not start it.')
    host_config = obj.get('HostConfig')
    network = obj.get('NetworkSettings')
    if not isinstance(host_config, dict) or not isinstance(network, dict):
        raise PreflightError('Docker port metadata missing.')
    actual = host_config.get('PortBindings')
    advertised = network.get('Ports')
    if not isinstance(actual, dict) or not isinstance(advertised, dict):
        raise PreflightError('Docker port bindings missing.')
    if set(actual) != {'3306/tcp'} or set(advertised) != {'3306/tcp'}:
        raise PreflightError('Unexpected published container ports.')
    binding = actual['3306/tcp']
    external = advertised['3306/tcp']
    if not isinstance(binding, list) or not binding or not isinstance(external, list):
        raise PreflightError('Docker MariaDB host port is missing.')
    for pair in (binding, external):
        if len(pair) != len(binding):
            raise PreflightError('Docker port metadata is inconsistent.')
        for entry in pair:
            if not isinstance(entry, dict) or entry.get('HostPort') != PORT:
                raise PreflightError('MariaDB must publish only port 3307.')
    warnings = []
    ips = {item.get('HostIp', '') for item in binding}
    if not ips.issubset({'127.0.0.1', '::1'}):
        warnings.append(
            'MariaDB is bound beyond loopback; check workstation firewall/network exposure.'
        )
    mounts = obj.get('Mounts', [])
    if not isinstance(mounts, list):
        raise PreflightError('Docker mount metadata is invalid.')
    if mounts:
        warnings.append(
            'Container has persistent mounts; preflight does not establish data ownership.'
        )
    return warnings, f'{IMAGE}, existing container {CONTAINER}, host port {PORT}'


def read_docker_metadata() -> object:
    try:
        result = subprocess.run(
            ['docker', 'inspect', CONTAINER],
            capture_output=True, text=True, check=False, timeout=10,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise PreflightError('Docker inspect unavailable or timed out.') from error
    if result.returncode != 0:
        raise PreflightError(f'Existing {CONTAINER} container not found or Docker is unavailable.')
    try:
        return json.loads(result.stdout)
    except ValueError as error:
        raise PreflightError('Docker inspect returned invalid JSON.') from error


def read_source_commit(source: Path) -> str:
    """Resolve checked-out source identity without changing checkout state."""
    try:
        result = subprocess.run(
            ['git', '-C', str(source), 'rev-parse', '--verify', 'HEAD'],
            capture_output=True, text=True, check=False, timeout=10,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise PreflightError('Git source identity could not be inspected.') from error
    sha = result.stdout.strip()
    if result.returncode != 0 or len(sha) != 40 or any(ch not in '0123456789abcdef' for ch in sha):
        raise PreflightError('Base source Git HEAD is unavailable or invalid.')
    return sha


def preflight() -> int:
    try:
        source = Path(__file__).resolve().parents[2]
        if not (source / 'core-blueprint.php').is_file():
            raise PreflightError('Base source checkout or canonical entrypoint is missing.')
        root = os.environ.get('CB_TEST_ROOT', str(CANONICAL_TEST_ROOT))
        state = validate_test_root(root)
        sha = read_source_commit(source)
        warnings, description = validate_docker_metadata(read_docker_metadata())
    except PreflightError as error:
        print(f'Base integration safety preflight: BLOCKED: {error}', file=sys.stderr)
        return 1

    print('Base integration safety preflight: PASS (read-only)')
    print(f'Base source commit: {sha}')
    print(f'Test root: {root} ({state}; no files touched)')
    print(f'MariaDB: {description} (not started or modified)')
    for warning in warnings:
        print(f'WARNING: {warning}', file=sys.stderr)
    print('Database safety: NOT AUTHORIZED for reset or schema changes.')
    print('WordPress integration: NOT RUN. No Level 2 evidence established.')
    return 0


if __name__ == '__main__':
    if sys.argv[1:] != ['--preflight']:
        print('Usage: python3 -B tools/integration/preflight.py --preflight', file=sys.stderr)
        raise SystemExit(64)
    raise SystemExit(preflight())
