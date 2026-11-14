"""T4-01K: Template Library import affordance is provider-owned, not a grant.

Production TemplateSourceRegistry and optional interface are loaded directly,
with only WordPress action/capability shims. Never open a staged ZIP here.
"""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

PHP_DRIVER = r'''<?php
declare(strict_types=1);
namespace {
    define('ABSPATH', '/test-wordpress/');
    $GLOBALS['actor'] = 7;
    $GLOBALS['admin'] = true;
    $GLOBALS['browse'] = true;
    $GLOBALS['import'] = true;
    $GLOBALS['scenario'] = $argv[2];
    function current_user_can(string $cap): bool {
        return $cap === 'manage_options' && $GLOBALS['admin'];
    }
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function doing_action(string $action): bool {
        return $action === 'core_blueprint_register_template_sources';
    }
    // Fixture registers sources directly; real init collection is not run.
    function did_action(string $action): int { return 0; }
    function add_action(string $name, mixed $callback, int $priority = 10): void {}
    if ($GLOBALS['scenario'] === 'anonymous') $GLOBALS['actor'] = 0;
    if ($GLOBALS['scenario'] === 'no-admin') $GLOBALS['admin'] = false;
    if ($GLOBALS['scenario'] === 'no-browse') $GLOBALS['browse'] = false;
    if ($GLOBALS['scenario'] === 'no-import') $GLOBALS['import'] = false;
}
namespace CoreBlueprint\Core\TemplateLibrary {
    require_once $argv[1] . '/src/TemplateLibrary/TemplateQuery.php';
    require_once $argv[1] . '/src/TemplateLibrary/TemplateSourceInterface.php';
    require_once $argv[1] . '/src/TemplateLibrary/TemplateResourceImportSourceInterface.php';
    require_once $argv[1] . '/src/TemplateLibrary/TemplateSourceRegistry.php';

    final class Provider implements TemplateResourceImportSourceInterface {
        public function source_id(): string { return 'reports'; }
        public function extension_id(): string { return 'core-blueprint'; }
        public function designer_type(): string { return 'reports'; }
        public function can_browse(int $actor): bool { return $GLOBALS['browse'] && $actor === 7; }
        public function can_view(string $id, int $actor): bool { return $actor === 7; }
        public function query(TemplateQuery $query, ?string $cursor): TemplatePage {
            throw new \RuntimeException('Not used');
        }
        public function resource_import_destination(): string {
            if ($GLOBALS['scenario'] === 'provider-throws') throw new \RuntimeException('private');
            if ($GLOBALS['scenario'] === 'bad-destination') return '../private';
            return 'reports.drafts';
        }
        public function can_import_resource_package(int $actor): bool {
            return $GLOBALS['import'] && $actor === 7;
        }
    }
    final class Ordinary implements TemplateSourceInterface {
        public function source_id(): string { return 'mail'; }
        public function extension_id(): string { return 'core-blueprint'; }
        public function designer_type(): string { return 'mail'; }
        public function can_browse(int $actor): bool { return $actor === 7; }
        public function can_view(string $id, int $actor): bool { return $actor === 7; }
        public function query(TemplateQuery $query, ?string $cursor): TemplatePage {
            throw new \RuntimeException('Not used');
        }
    }

    TemplateSourceRegistry::register(new Provider());
    TemplateSourceRegistry::register(new Ordinary());
    $good = TemplateSourceRegistry::resource_import_destination('reports');
    $ordinary = TemplateSourceRegistry::resource_import_destination('mail');
    $missing = TemplateSourceRegistry::resource_import_destination('forged');
    echo json_encode([
        'destination' => $good, 'ordinary' => $ordinary, 'missing' => $missing,
    ], JSON_THROW_ON_ERROR);
}
'''


class ImportDestinationContractTests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.file = Path(tmp.name) / "fixture.php"
        self.file.write_text(PHP_DRIVER)

    def run_case(self, scenario):
        result = subprocess.run([
            "php", str(self.file), str(ROOT), scenario,
        ], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(result.stdout)

    def test_only_registered_explicitly_authorized_provider_advertises_destination(self):
        good = self.run_case("happy")
        self.assertEqual("reports.drafts", good["destination"])
        self.assertIsNone(good["ordinary"])
        self.assertIsNone(good["missing"])

    def test_invalid_destination_and_revoked_actor_fail_closed(self):
        for scenario in ("anonymous", "no-admin", "no-browse", "no-import",
                         "bad-destination", "provider-throws"):
            with self.subTest(scenario=scenario):
                response = self.run_case(scenario)
                self.assertIsNone(response["destination"], response)
                self.assertIsNone(response["ordinary"], response)


if __name__ == "__main__":
    unittest.main()
