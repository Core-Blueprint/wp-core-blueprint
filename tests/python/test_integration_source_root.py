"""Keep repository-only contract evidence out of the installed Base runtime.

Runs offline: no WordPress, SQL, Docker or GitHub Actions needed. Production
assets remain checked against the real installed plugin via CB_CORE_DIR.
"""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
INTEGRATION = ROOT / "tests/integration"
SOURCE_ONLY_FILES = {
    "docs/PUBLIC-API.md",
    "docs/REORDER-FOUNDATION.md",
    "docs/SECRET-PROTECTION-FOUNDATION.md",
    "CHANGELOG-HISTORY.md",
    "docs/foundation-v1-contract.md",
    "tests/bin/run-performance-baseline.sh",
    "tests/performance/request.php",
    "docs/DASHBOARD-CARD-API.md",
    "SECURITY.md",
    "tools/build-release",
    "docs/DESIGNER-MODE.md",
    "docs/DESIGNER-LAYOUT-CONTRACT.md",
}
SOURCE_TEST_FILES = (
    "CoreApiDocumentationContractTest.php",
    "InternalHistoryLanguageContractTest.php",
    "PerformanceBaselineContractTest.php",
    "SnippetsExtractionContractTest.php",
    "WordPressOrgSubmissionContractTest.php",
)
DESIGNER_TEST_FILES = (
    "DesignerCompositionContractTest.php",
    "DesignerLayerInteractionContractTest.php",
)
# A packaged artifact must never be forced to ship docs/, tests/, tools/ or
# developer-only root files to satisfy a PHPUnit source-contract test.
RUNTIME_REFERENCE_TO_SOURCE_ONLY = re.compile(
    r"""CB_CORE_DIR\s*\.\s*['"](?:docs/|tests/|tools/|SECURITY\.md|CHANGELOG-HISTORY\.md)"""
)
REPO_ROOT = "dirname( __DIR__, 2 ) . '/'"
RUNTIME_SOURCE_SUBSTITUTION = re.compile(
    r"""dirname\(\s*__DIR__,\s*2\s*\)\s*\.\s*['"]/(?:src/|assets/|includes/|templates/)"""
)


class IntegrationSourceRootContractTest(unittest.TestCase):
    def test_source_contract_fixtures_exist_in_repository_not_release_package(self):
        for name in sorted(SOURCE_ONLY_FILES):
            with self.subTest(path=name):
                self.assertTrue((ROOT / name).is_file(), name)

    def test_all_integration_tests_keep_source_only_paths_out_of_runtime(self):
        for path in sorted(INTEGRATION.glob("*Test.php")):
            content = path.read_text(encoding="utf-8")
            with self.subTest(file=path.name):
                self.assertIsNone(
                    RUNTIME_REFERENCE_TO_SOURCE_ONLY.search(content),
                    "Repository-only contract evidence must not be loaded from CB_CORE_DIR."
                )

    def test_repository_only_contracts_use_checkout_root(self):
        for name in SOURCE_TEST_FILES:
            content = (INTEGRATION / name).read_text(encoding="utf-8")
            with self.subTest(file=name):
                self.assertIn(REPO_ROOT, content)
                self.assertIsNone(RUNTIME_SOURCE_SUBSTITUTION.search(content))

    def test_designer_helpers_keep_runtime_and_docs_distinct(self):
        for name in DESIGNER_TEST_FILES:
            content = (INTEGRATION / name).read_text(encoding="utf-8")
            with self.subTest(file=name):
                self.assertIn("str_starts_with( $relative_path, 'docs/' )", content)
                self.assertIn("? " + REPO_ROOT + " . $relative_path", content)
                self.assertIn(": CB_CORE_DIR . $relative_path", content)
                self.assertIsNone(RUNTIME_SOURCE_SUBSTITUTION.search(content))


if __name__ == "__main__":
    unittest.main()
