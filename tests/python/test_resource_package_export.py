"""Source-level producer-contract tests; real ZipArchive/verifier E2E is separate."""
from pathlib import Path
import json
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

PHP_DRIVER = r'''<?php
declare(strict_types=1);
namespace {
    define('ABSPATH', __DIR__ . '/public/');
    class WP_Error {
        private string $code;
        function __construct(string $code, string $message) { $this->code = $code; }
        function get_error_code(): string { return $this->code; }
    }
    function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    // Test double: verifies producer control flow, never claims a valid ZIP.
    class ZipArchive {
        const OVERWRITE = 8;
        const CREATE = 1;
        const CM_DEFLATE = 8;
        private string $path;
        private array $entries = [];
        function open(string $path, int $flags): bool { $this->path = $path; return true; }
        function addFromString(string $name, string $bytes): bool { $this->entries[$name] = base64_encode($bytes); return true; }
        function setCompressionName(string $name, int $method): bool { return isset($this->entries[$name]); }
        function close(): bool { return false !== file_put_contents($this->path, json_encode($this->entries, JSON_THROW_ON_ERROR)); }
    }
}
namespace CoreBlueprint\Core\DataExchange {
    use \WP_Error;
    class Foundation {
        const MAX_INPUT_BYTES = 10485760;
        const FORMAT_ID = 'core-blueprint-data-exchange';
        const FORMAT_VERSION = 1;
    }
    class ResourcePackageStage {
        static function private_root_path(string $root): ?string {
            return is_dir($root) && (fileperms($root) & 0077) === 0 ? realpath($root) : null;
        }
    }
    class Engine {
        static function export_json(string $extension, string $entity, array $context): string|WP_Error {
            ++$GLOBALS['export_calls'];
            if (!$GLOBALS['allowed']) { return new WP_Error('denied', 'Denied'); }
            $record = $GLOBALS['record'];
            $record['revision'] = $GLOBALS['version'];
            return json_encode([
                'format' => Foundation::FORMAT_ID, 'format_version' => 1,
                'extension_id' => $extension, 'entity' => $entity,
                'schema_version' => 1, 'exported_at' => '2026-11-14T00:00:00Z',
                'records' => [$record],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
    class ResourcePackageVerifier {
        static function verify_file(string $path): array|WP_Error {
            $entries = json_decode(file_get_contents($path), true);
            if (!isset($entries['manifest.json'], $entries['data-exchange.json'])) {
                return new WP_Error('invalid', 'Invalid');
            }
            $manifest = json_decode(base64_decode($entries['manifest.json']), true);
            $document = base64_decode($entries['data-exchange.json']);
            if (hash('sha256', $document) !== $manifest['document']['sha256']) {
                return new WP_Error('invalid', 'Invalid');
            }
            return [
                'package_sha256' => hash_file('sha256', $path),
                'document_sha256' => hash('sha256', $document),
                'extension_id' => $manifest['extension_id'],
                'entity' => $manifest['entity'],
                'resources' => array_map(
                    static fn ($r) => ['key' => $r['key'], 'sha256' => $r['sha256'],
                        'size' => $r['size'], 'media_type' => $r['media_type']],
                    $manifest['resources']
                ),
            ];
        }
    }
    require_once $argv[1];
    $scenario = $argv[2];
    $root = $argv[3];
    $bytes = 'synthetic-local-asset';
    $resource = [
        'key' => 'brand-logo', 'sha256' => hash('sha256', $bytes),
        'size' => strlen($bytes), 'media_type' => 'image/png',
    ];
    $GLOBALS['record'] = ['resources' => [$resource], 'portable_id' => 'report-a'];
    $GLOBALS['actor'] = 7;
    $GLOBALS['allowed'] = true;
    $GLOBALS['version'] = 1;
    $GLOBALS['export_calls'] = 0;
    if ($scenario === 'no-resource') { $GLOBALS['record']['resources'] = []; }
    if ($scenario === 'wrong-type') { $GLOBALS['record']['resources'][0]['media_type'] = 'image/svg+xml'; }
    if ($scenario === 'duplicate-digest') {
        $second = $resource;
        $second['key'] = 'brand-logo-2';
        $GLOBALS['record']['resources'][] = $second;
    }
    if ($scenario === 'private-root') { $root .= '/non-existent'; }
    $loader = static function (array $r) use ($bytes, $scenario): string {
        if ($scenario === 'actor-switch') { $GLOBALS['actor'] = 8; }
        if ($scenario === 'revoked') { $GLOBALS['allowed'] = false; }
        if ($scenario === 'stale') { $GLOBALS['version'] = 2; }
        if ($scenario === 'throws') { throw new \RuntimeException('untrusted provider exception'); }
        return $scenario === 'mismatch' ? 'tampered' : $bytes;
    };
    $result = ResourcePackageExport::create('acme-integrated', 'template.report', [], $loader, $root);
    if ($result instanceof WP_Error) {
        $output = ['error' => $result->get_error_code()];
    } else {
        $output = ['ok' => true, 'private' => is_file($result['path']),
                   'sha256' => hash_file('sha256', $result['path']) === $result['package_sha256'],
                   'perms' => fileperms($result['path']) & 0077,
                   'resource_count' => count($result['resources'])];
        unlink($result['path']);
    }
    $output['export_calls'] = $GLOBALS['export_calls'];
    $output['leftovers'] = count(glob($argv[3] . '/*.zip'));
    echo json_encode($output, JSON_THROW_ON_ERROR);
}
'''


class ResourcePackageExportTests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        folder = Path(tmp.name)
        self.script = folder / "driver.php"
        self.script.write_text(PHP_DRIVER)
        self.private = folder / "private"
        self.private.mkdir(mode=0o700)

    def run_case(self, scenario):
        result = subprocess.run(
            ["php", str(self.script), str(ROOT / "src/DataExchange/ResourcePackageExport.php"),
             scenario, str(self.private)], capture_output=True, text=True,
        )
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(result.stdout)

    def test_export_emits_one_private_package_with_verified_digest(self):
        result = self.run_case("happy")
        self.assertTrue(result["ok"], result)
        self.assertTrue(result["private"])
        self.assertTrue(result["sha256"])
        self.assertEqual(0, result["perms"])
        self.assertEqual(1, result["resource_count"])
        self.assertEqual(2, result["export_calls"])
        self.assertEqual(0, result["leftovers"])

    def test_invalid_resources_and_domain_changes_fail_without_retained_file(self):
        for scenario in ("mismatch", "revoked", "actor-switch", "stale", "throws",
                         "no-resource", "wrong-type", "duplicate-digest", "private-root"):
            with self.subTest(scenario=scenario):
                result = self.run_case(scenario)
                self.assertEqual("cb_core_resource_package_export_invalid", result["error"], result)
                self.assertEqual(0, result["leftovers"], result)


if __name__ == "__main__":
    unittest.main()
