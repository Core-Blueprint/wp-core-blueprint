"""Regression coverage for minimal Base translation pruning."""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PRUNE = ROOT / "tools/prune-translations-to-source.php"


class TranslationPruneTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / "tools").mkdir()
        (self.root / "languages/base").mkdir(parents=True)
        shutil.copy2(PRUNE, self.root / "tools/prune-translations-to-source.php")
        (self.root / "core-blueprint.php").write_text(
            "<?php\n__( 'Keep me', 'core-blueprint' );\n",
            encoding="utf-8",
        )
        self.catalog = self.root / "languages/base/core-blueprint-nl_NL.php"

    def run_prune(self) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["php", "tools/prune-translations-to-source.php"],
            cwd=self.root,
            check=True,
            capture_output=True,
            text=True,
        )

    def test_aligned_catalog_is_not_reformatted(self) -> None:
        source = """<?php
declare(strict_types=1);

return [
    'messages' => [
        'Keep me' => 'Bewaren',
    ],
];
"""
        self.catalog.write_text(source, encoding="utf-8")

        result = self.run_prune()

        self.assertIn("1 canonical source keys", result.stdout)
        self.assertIn("0 files changed", result.stdout)
        self.assertIn("0 stale message entries removed", result.stdout)
        self.assertEqual(source, self.catalog.read_text(encoding="utf-8"))
        self.assertEqual(
            "1\n",
            (self.root / "tools/canonical-i18n-count.txt").read_text(encoding="utf-8"),
        )

    def test_stale_catalog_entry_is_removed(self) -> None:
        self.catalog.write_text(
            """<?php
declare(strict_types=1);

return [
    'messages' => [
        'Keep me' => 'Bewaren',
        'Stale key' => 'Verouderd',
    ],
];
""",
            encoding="utf-8",
        )

        result = self.run_prune()
        pruned = self.catalog.read_text(encoding="utf-8")

        self.assertIn("1 files changed", result.stdout)
        self.assertIn("1 stale message entries removed", result.stdout)
        self.assertIn("Keep me", pruned)
        self.assertNotIn("Stale key", pruned)


if __name__ == "__main__":
    unittest.main()
