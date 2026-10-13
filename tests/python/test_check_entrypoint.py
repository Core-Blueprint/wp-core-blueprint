"""Isolated, non-destructive orchestration tests for the canonical Base Level 1 check."""

import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


SOURCE_CHECK = Path(__file__).resolve().parents[2] / 'tools' / 'check'


class CheckEntrypointTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.area = Path(self.temporary.name)
        self.root = self.area / 'source'
        self.bin = self.area / 'bin'
        self.bin.mkdir()
        self.trace = self.area / 'invocations.txt'

        def write(relative, content='fixture\n'):
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content, encoding='utf-8')
            return path

        write('tools/check', SOURCE_CHECK.read_text(encoding='utf-8'))
        write('tests/bin/php-lint.sh', '#!/usr/bin/env bash\nexit 0\n')
        write('tests/public-admin-navigation-foundation-regression.php')
        write('tests/public-tile-foundation-regression.php')
        write('tests/python/fixture.py')
        write('tests/js/fixture.test.mjs')
        write('assets/js/editor.js')

        executable = '#!/usr/bin/env bash\n' \
            'printf "%s|%s\\n" "${0##*/}" "$*" >> "$CB_LEVEL1_TRACE"\n' \
            'if [[ "${0##*/}|$1" == "${CB_LEVEL1_FAIL:-}" ]]; then exit 23; fi\n'
        for tool in ('php', 'composer', 'node', 'python3'):
            file = self.bin / tool
            file.write_text(executable, encoding='utf-8')
            file.chmod(0o755)

    def run_check(self, fail=''):
        env = dict(os.environ)
        env['PATH'] = str(self.bin) + os.pathsep + env.get('PATH', '')
        env['CB_LEVEL1_TRACE'] = str(self.trace)
        env['CB_LEVEL1_FAIL'] = fail
        return subprocess.run(
            ['bash', str(self.root / 'tools/check')],
            cwd=self.area, env=env, capture_output=True, text=True, check=False,
        )

    def source_hashes(self):
        return {
            item.relative_to(self.root).as_posix(): hashlib.sha256(item.read_bytes()).hexdigest()
            for item in self.root.rglob('*') if item.is_file()
        }

    def trace_lines(self):
        return self.trace.read_text(encoding='utf-8').splitlines() if self.trace.exists() else []

    def test_all_level1_checks_run_without_writing_source(self):
        before = self.source_hashes()
        result = self.run_check()
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(before, self.source_hashes())
        self.assertFalse((self.root / 'dist').exists())
        commands = self.trace_lines()
        self.assertTrue(any('composer|validate --strict --no-check-publish' == c for c in commands))
        self.assertEqual(2, sum(c.startswith('php|tests/public-') for c in commands))
        self.assertEqual(1, sum(c.startswith('node|--check ') for c in commands))
        self.assertEqual(1, sum(c.startswith('node|--test ') for c in commands))
        self.assertEqual(1, sum(c.startswith('python3|-B -m unittest discover') for c in commands))
        self.assertIn('Core Blueprint Base Level 1 checks: PASS', result.stdout)

    def test_composer_failure_stops_before_product_checks(self):
        result = self.run_check('composer|validate')
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(any(x.startswith('node|') for x in self.trace_lines()))
        self.assertNotIn('Level 1 checks: PASS', result.stdout)

    def test_php_source_gate_failure_stops_before_javascript(self):
        (self.root / 'tests/bin/php-lint.sh').write_text('exit 42\n', encoding='utf-8')
        result = self.run_check()
        self.assertEqual(42, result.returncode)
        self.assertFalse(any(x.startswith('node|') for x in self.trace_lines()))

    def test_javascript_syntax_failure_stops_before_runtime_tests(self):
        result = self.run_check('node|--check')
        self.assertEqual(23, result.returncode)
        self.assertFalse(any(x.startswith('node|--test ') for x in self.trace_lines()))

    def test_missing_javascript_regressions_fail_closed(self):
        (self.root / 'tests/js/fixture.test.mjs').unlink()
        result = self.run_check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('JavaScript regression suite is missing', result.stderr)

    def test_javascript_runtime_failure_stops_before_python(self):
        result = self.run_check('node|--test')
        self.assertEqual(23, result.returncode)
        self.assertFalse(any(x.startswith('python3|') for x in self.trace_lines()))

    def test_missing_public_foundation_regression_fails_closed(self):
        (self.root / 'tests/public-tile-foundation-regression.php').unlink()
        result = self.run_check()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('required source check missing', result.stderr)
        self.assertFalse(any(x.startswith('composer|') for x in self.trace_lines()))

    def test_python_failure_stops_pass_reporting(self):
        result = self.run_check('python3|-B')
        self.assertEqual(23, result.returncode)
        self.assertNotIn('Level 1 checks: PASS', result.stdout)


if __name__ == '__main__':
    unittest.main()
