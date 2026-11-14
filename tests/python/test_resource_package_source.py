"""FRG-T4-01J: opt-in provider export cannot be forged from Browser metadata."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

PHP_DRIVER = r'''<?php
declare(strict_types=1);
namespace {
    define('ABSPATH', '/site/');
    class WP_Error {
        public function __construct(public string $code, public string $message) {}
    }
    function is_wp_error(mixed $x): bool { return $x instanceof WP_Error; }
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function current_user_can(string $cap): bool {
        return $cap === 'manage_options' && $GLOBALS['admin'];
    }
    function did_action(string $action): int { return 1; }
    function wp_strip_all_tags(string $s): string { return strip_tags($s); }
    $GLOBALS['scenario'] = $argv[2];
    $GLOBALS['actor'] = 7;
    $GLOBALS['admin'] = true;
    $GLOBALS['view'] = true;
    $GLOBALS['export'] = true;
    $GLOBALS['create_calls'] = 0;
    $GLOBALS['read_calls'] = 0;
    $GLOBALS['discarded'] = false;
    if ($GLOBALS['scenario'] === 'revoked-admin') $GLOBALS['admin'] = false;
    if ($GLOBALS['scenario'] === 'revoked-view') $GLOBALS['view'] = false;
    if ($GLOBALS['scenario'] === 'revoked-package') $GLOBALS['export'] = false;
}
namespace CoreBlueprint\Core\DataExchange {
    final class ResourcePackageExport {
        static function create(string $ext, string $entity, array $context,
            callable $read, string $privateRoot): array|\WP_Error {
            ++$GLOBALS['create_calls'];
            if ($ext !== 'acme-test' || $entity !== 'template.report'
                || ($context['templateId'] ?? null) !== 'report-a') {
                return new \WP_Error('failed', 'Bad provider contract.');
            }
            if ($GLOBALS['scenario'] === 'read-revoked') $GLOBALS['export'] = false;
            $bytes = $read(['key'=>'brand-logo','media_type'=>'image/png','size'=>8,
                'sha256'=>str_repeat('b',64)]);
            if ($bytes instanceof \WP_Error) return $bytes;
            ++$GLOBALS['read_calls'];
            if ($GLOBALS['scenario'] === 'actor-switched') $GLOBALS['actor'] = 9;
            return [
                'path'=>$privateRoot . '/' . str_repeat('a',48) . '.export.zip',
                'package_sha256'=>str_repeat('a',64),
                'document_sha256'=>str_repeat('c',64), 'resources'=>[],
            ];
        }
        static function discard(string $path, string $root): bool {
            $GLOBALS['discarded'] = str_starts_with($path, $root . '/');
            return $GLOBALS['discarded'];
        }
    }
}
namespace {
    require_once $argv[1] . '/src/TemplateLibrary/TemplateSourceInterface.php';
    require_once $argv[1] . '/src/TemplateLibrary/TemplateResourceExportSourceInterface.php';
    require_once $argv[1] . '/src/TemplateLibrary/TemplateQuery.php';
    require_once $argv[1] . '/src/TemplateLibrary/TemplateMetadata.php';
    require_once $argv[1] . '/src/TemplateLibrary/TemplateSourceRegistry.php';

    use CoreBlueprint\Core\TemplateLibrary\TemplateSourceRegistry as Registry;
    use CoreBlueprint\Core\TemplateLibrary\TemplateResourceExportSourceInterface as Contract;
    use CoreBlueprint\Core\TemplateLibrary\TemplateQuery;
    use CoreBlueprint\Core\TemplateLibrary\TemplatePage;
    use CoreBlueprint\Core\TemplateLibrary\TemplateMetadata;

    final class FixtureSource implements Contract {
        public function source_id(): string { return 'reports'; }
        public function extension_id(): string { return 'acme-test'; }
        public function designer_type(): string { return 'reports'; }
        public function can_browse(int $actor): bool { return $actor === 7; }
        public function can_view(string $id, int $actor): bool {
            return $id === 'report-a' && $actor === 7 && $GLOBALS['view'];
        }
        public function query(TemplateQuery $query, ?string $cursor): TemplatePage {
            throw new \RuntimeException('Not used');
        }
        public function resource_export_entity(): string { return 'template.report'; }
        public function can_export_resource_package(string $id, string $version, int $actor): bool {
            return $id === 'report-a' && $version === '1.0' && $actor === 7
                && $GLOBALS['export'];
        }
        public function resource_export_context(string $id, string $version, int $actor): array|\WP_Error {
            if ($GLOBALS['scenario'] === 'wrong-context') return ['actorId'=>999];
            if ($GLOBALS['scenario'] === 'throw-context') throw new \RuntimeException('Private details');
            return ['templateId'=>$id,'version'=>$version,'actorId'=>$actor];
        }
        public function read_export_resource(string $id, string $version, array $resource,
            int $actor): string|\WP_Error {
            return 'fixture8';
        }
    }
    final class OrdinarySource implements \CoreBlueprint\Core\TemplateLibrary\TemplateSourceInterface {
        public function source_id(): string { return 'normal'; }
        public function extension_id(): string { return 'acme-test'; }
        public function designer_type(): string { return 'reports'; }
        public function can_browse(int $actor): bool { return true; }
        public function can_view(string $id, int $actor): bool { return true; }
        public function query(TemplateQuery $query, ?string $cursor): TemplatePage {
            throw new \RuntimeException('Not used');
        }
    }

    $source = new FixtureSource();
    $reflection = new \ReflectionClass(Registry::class);
    $reflection->getProperty('sources')->setValue(null, [
        'reports'=>$source, 'normal'=>new OrdinarySource(),
    ]);
    $reflection->getProperty('collected')->setValue(null, true);
    $name = $GLOBALS['scenario'] === 'ordinary' ? 'normal'
        : ($GLOBALS['scenario'] === 'missing' ? 'missing' : 'reports');
    $id = $GLOBALS['scenario'] === 'wrong-id' ? 'other' : 'report-a';
    $version = $GLOBALS['scenario'] === 'wrong-version' ? '2.0' : '1.0';
    $metadata = new TemplateMetadata('reports','acme-test','reports','report-a',
        'Report','Summary','personal','report','en_US','1.0');
    $hint = Registry::supports_resource_export($metadata);
    $result = Registry::export_resource_package($name, $id, $version, '/private');
    echo json_encode([
        'hint'=>$hint,
        'ready'=>is_array($result),
        'error'=>is_wp_error($result) ? $result->code : null,
        'creates'=>$GLOBALS['create_calls'], 'reads'=>$GLOBALS['read_calls'],
        'discarded'=>$GLOBALS['discarded'],
    ], JSON_THROW_ON_ERROR);
}
'''


class ResourceExportSourceContractTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.driver = Path(temp.name) / "driver.php"
        self.driver.write_text(PHP_DRIVER)

    def call(self, scenario):
        result = subprocess.run([
            "php", str(self.driver), str(ROOT), scenario
        ], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(result.stdout)

    def test_exact_authorized_provider_and_context_yield_one_canonical_export(self):
        result = self.call("happy")
        self.assertTrue(result["hint"], result)
        self.assertTrue(result["ready"], result)
        self.assertEqual(1, result["creates"], result)
        self.assertEqual(1, result["reads"], result)
        self.assertFalse(result["discarded"], result)

    def test_unregistered_ordinary_and_revoked_source_never_reach_export_engine(self):
        for case in ("ordinary", "missing", "wrong-id", "wrong-version",
                     "revoked-admin", "revoked-view", "revoked-package", "wrong-context",
                     "throw-context"):
            with self.subTest(case=case):
                result = self.call(case)
                self.assertFalse(result["ready"], result)
                self.assertEqual(0, result["creates"], result)
                self.assertEqual(0, result["reads"], result)

    def test_revocation_during_byte_reads_and_after_package_creation_fail_closed(self):
        revoked = self.call("read-revoked")
        self.assertFalse(revoked["ready"])
        self.assertEqual(1, revoked["creates"])
        self.assertEqual(0, revoked["reads"])
        changed = self.call("actor-switched")
        self.assertFalse(changed["ready"])
        self.assertEqual(1, changed["creates"])
        self.assertTrue(changed["discarded"])


if __name__ == "__main__":
    unittest.main()
