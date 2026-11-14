"""FRG-T4-01G: canonical import plan gates resource reads without ZipArchive."""
from pathlib import Path
import json
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

PHP_DRIVER = r'''<?php
declare(strict_types=1);
namespace {
    define('ABSPATH', '/nonexistent-wp-root/');
    class WP_Error {
        public function __construct(private string $code, private string $message) {}
        public function get_error_code(): string { return $this->code; }
    }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    $GLOBALS['actor'] = 7;
    $GLOBALS['stage_reads'] = 0;
    $GLOBALS['bytes'] = 'verified-resource-bytes';
    $GLOBALS['scenario'] = $argv[2];
    function get_current_user_id(): int { return $GLOBALS['actor']; }
}
namespace CoreBlueprint\Core\DataExchange {
    class Foundation {
        const MAX_INPUT_BYTES = 10485760;
        const MODE_CREATE_ONLY = 'create_only';
        const OP_CREATE = 'create';
    }
    class ResourcePackageStage {
        static function inspect(string $ticket, string $root, string $destination): array|\WP_Error {
            $json = '{"record":"fixture"}';
            return [
                'document_json' => $json,
                'document_sha256' => hash('sha256', $json),
                'package_sha256' => str_repeat('b', 64),
                'extension_id' => 'acme-integrated',
                'entity' => 'template.report',
                'resources' => [[
                    'key' => 'brand-logo',
                    'sha256' => hash('sha256', $GLOBALS['bytes']),
                    'size' => strlen($GLOBALS['bytes']),
                    'media_type' => 'image/png',
                ]],
            ];
        }
        static function read_resource(string $ticket, string $root, string $destination, string $key): string|\WP_Error {
            ++$GLOBALS['stage_reads'];
            if ($ticket !== 'ticket' || $root !== '/private' || $destination !== 'copy-a'
                || $key !== 'brand-logo' || $GLOBALS['actor'] !== 7) {
                return new \WP_Error('stage_denied', 'Staged media unavailable');
            }
            return $GLOBALS['bytes'];
        }
    }
    class Engine {
        static function preview_json(string $json, string $mode, array $context): array|\WP_Error {
            $plan = ResourcePackageImport::bind_plan([
                'operation' => Foundation::OP_CREATE, 'reference' => 'copy-a',
                'payload' => [], 'warnings' => [],
            ], $context);
            if ($plan instanceof \WP_Error) { return $plan; }
            if (!isset($GLOBALS['preview_denied'])) {
                $probe = ResourcePackageImport::read_resource($plan, $context, 'brand-logo');
                $GLOBALS['preview_denied'] = $probe instanceof \WP_Error
                    && $probe->get_error_code() === 'cb_core_resource_package_binding_invalid';
            }
            return [
                'valid' => true, 'record_count' => 1,
                'fingerprint' => str_repeat('f', 64),
            ];
        }
        static function apply_json(string $json, string $mode, string $fingerprint, array $context): array|\WP_Error {
            $plan = ResourcePackageImport::bind_plan([
                'operation' => Foundation::OP_CREATE, 'reference' => 'copy-a',
                'payload' => [], 'warnings' => [],
            ], $context);
            if ($plan instanceof \WP_Error) { return $plan; }
            if ($GLOBALS['scenario'] === 'wrong-actor') { $GLOBALS['actor'] = 8; }
            if ($GLOBALS['scenario'] === 'wrong-context') { $context['destination_id'] = 'copy-b'; }
            if ($GLOBALS['scenario'] === 'unbound-plan') { unset($plan['payload']['cb_resource_package_binding']); }
            $key = $GLOBALS['scenario'] === 'wrong-key' ? 'different-resource' : 'brand-logo';
            $bytes = ResourcePackageImport::read_resource($plan, $context, $key);
            if ($bytes instanceof \WP_Error) { return $bytes; }
            $GLOBALS['read_digest'] = hash('sha256', $bytes);
            return ['status' => 'complete'];
        }
    }
    require_once $argv[1];
    use CoreBlueprint\Core\DataExchange\ResourcePackageImport as Bridge;
    $pre = Bridge::preview('ticket', '/private', 'copy-a');
    $out = [
        'preview_valid' => !is_wp_error($pre) && ($pre['valid'] ?? false),
        'preview_denied' => $GLOBALS['preview_denied'] ?? false,
        'reads_after_preview' => $GLOBALS['stage_reads'],
    ];
    $result = Bridge::apply('ticket', '/private', 'copy-a', str_repeat('f', 64));
    $out['apply_status'] = is_wp_error($result) ? $result->get_error_code() : $result['status'];
    $out['read_digest'] = $GLOBALS['read_digest'] ?? null;
    $out['stage_reads'] = $GLOBALS['stage_reads'];
    $outside = Bridge::read_resource([], [], 'brand-logo');
    $out['outside_refused'] = $outside instanceof \WP_Error
        && $outside->get_error_code() === 'cb_core_resource_package_binding_invalid';
    echo json_encode($out, JSON_THROW_ON_ERROR);
}
'''


class ScopedResourceReadTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.driver = Path(temp.name) / "driver.php"
        self.driver.write_text(PHP_DRIVER)

    def run_scenario(self, scenario):
        completed = subprocess.run(
            ["php", str(self.driver), str(ROOT / "src/DataExchange/ResourcePackageImport.php"), scenario],
            capture_output=True, text=True,
        )
        self.assertEqual(0, completed.returncode, completed.stderr)
        return json.loads(completed.stdout)

    def test_preview_has_no_bytes_and_bound_apply_reads_exact_resource(self):
        result = self.run_scenario("ok")
        self.assertTrue(result["preview_valid"], result)
        self.assertTrue(result["preview_denied"], result)
        self.assertEqual(0, result["reads_after_preview"], result)
        self.assertEqual("complete", result["apply_status"], result)
        import hashlib
        self.assertEqual(hashlib.sha256(b"verified-resource-bytes").hexdigest(), result["read_digest"])
        self.assertEqual(1, result["stage_reads"], result)
        self.assertTrue(result["outside_refused"], result)

    def test_unknown_resource_never_reaches_private_stage(self):
        result = self.run_scenario("wrong-key")
        self.assertTrue(result["preview_denied"], result)
        self.assertEqual("cb_core_resource_package_binding_invalid", result["apply_status"])
        self.assertEqual(0, result["stage_reads"], result)
        self.assertIsNone(result["read_digest"], result)
        self.assertTrue(result["outside_refused"], result)

    def test_actor_destination_and_plan_binding_refuse_resource_reads(self):
        for scenario in ("wrong-actor", "wrong-context", "unbound-plan"):
            with self.subTest(scenario=scenario):
                result = self.run_scenario(scenario)
                self.assertTrue(result["preview_denied"], result)
                self.assertEqual("cb_core_resource_package_binding_invalid", result["apply_status"])
                self.assertEqual(0, result["stage_reads"], result)
                self.assertIsNone(result["read_digest"], result)
                self.assertTrue(result["outside_refused"], result)


if __name__ == "__main__":
    unittest.main()
