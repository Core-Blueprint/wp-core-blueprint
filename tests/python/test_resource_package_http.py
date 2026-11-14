"""FRG-T4-01I: Base-owned REST transport admission, with isolated WordPress shims.

This suite checks the actual production controller, not the ZIP verifier, by
replacing only the WordPress request/permission/upload APIs and import services.
Real uploaded-file + WordPress runtime qualification remains an isolated L2 gate.
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
    define('ABSPATH', '/nonexistent-wordpress-public/');
    class WP_Error {
        public function __construct(private string $code, private string $message) {}
        public function get_error_code(): string { return $this->code; }
    }
    function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
    class WP_REST_Request {
        public function __construct(
            private string $method = 'POST',
            private array $headers = ['X-WP-Nonce' => 'valid'],
            private ?array $json = null,
            private array $fields = [],
            private array $files = [],
        ) {}
        public function get_method(): string { return $this->method; }
        public function get_header(string $k): ?string { return $this->headers[$k] ?? null; }
        public function get_body(): string { return $this->json === null ? '' : json_encode($this->json); }
        public function get_json_params(): ?array { return $this->json; }
        public function get_body_params(): array { return $this->fields; }
        public function get_file_params(): array { return $this->files; }
    }
    class WP_REST_Response {
        public function __construct(private array $data, private int $status) {}
        public function get_data(): array { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
    $GLOBALS['actor'] = 7;
    $GLOBALS['can_manage'] = true;
    $GLOBALS['nonce_ok'] = true;
    $GLOBALS['uploaded'] = true;
    $GLOBALS['routes'] = [];
    $GLOBALS['import_calls'] = 0;
    $GLOBALS['staged'] = false;
    $GLOBALS['discarded'] = false;
    $GLOBALS['scenario'] = $argv[3];
    $GLOBALS['tempbase'] = $argv[2];
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function get_current_blog_id(): int { return 42; }
    function current_user_can(string $cap): bool {
        return $cap === 'manage_options' && $GLOBALS['can_manage'];
    }
    function is_user_logged_in(): bool { return $GLOBALS['actor'] > 0; }
    function wp_verify_nonce(string $nonce, string $type): int|false {
        return $nonce === 'valid' && $type === 'wp_rest' && $GLOBALS['nonce_ok'] ? 1 : false;
    }
    function wp_salt(string $name): string { return str_repeat('s', 64) . $name; }
    function add_action(string $action, mixed $callback): void { $GLOBALS['hooks'][$action] = $callback; }
    function register_rest_route(string $namespace, string $route, array $config): void {
        $GLOBALS['routes'][] = [$namespace, $route, $config['methods']];
    }
}
namespace CoreBlueprint\Core\DataExchange {
    function sys_get_temp_dir(): string { return $GLOBALS['tempbase']; }
    function is_uploaded_file(string $name): bool {
        return $GLOBALS['uploaded'] && $name === '/verified-php-upload.tmp';
    }
    class Foundation { const OP_CREATE = 'create'; }
    class ResourcePackageStage {
        static function private_root_path(string $path): ?string {
            if (is_link($path) || !is_dir($path) || (fileperms($path) & 0077) !== 0) return null;
            return realpath($path) ?: null;
        }
        static function stage(string $file, string $root, string $destination, string $cap): string|\WP_Error {
            $GLOBALS['staged'] = $file === '/verified-php-upload.tmp'
                && $destination === 'copy-a' && $cap === 'manage_options'
                && $root !== $GLOBALS['tempbase'] && is_dir($root);
            return $GLOBALS['staged'] ? str_repeat('t', 124)
                : new \WP_Error('stage_refused', 'stage denied');
        }
        static function discard(string $ticket, string $root, string $destination): bool {
            $GLOBALS['discarded'] = $ticket === str_repeat('t', 124)
                && $destination === 'copy-a' && is_dir($root) && $GLOBALS['actor'] === 7;
            return $GLOBALS['discarded'];
        }
    }
    class ResourcePackageImport {
        static function preview(string $ticket, string $root, string $destination): array|\WP_Error {
            ++$GLOBALS['import_calls'];
            if ($ticket !== str_repeat('t', 124) || $destination !== 'copy-a') {
                return new \WP_Error('invalid_stage', 'invalid');
            }
            return [
                'valid' => $GLOBALS['scenario'] !== 'bad-preview',
                'record_count' => 1, 'counts' => ['create' => 1],
                'fingerprint' => str_repeat('f', 64),
            ];
        }
        static function apply(string $ticket, string $root, string $destination, string $fingerprint): array|\WP_Error {
            ++$GLOBALS['import_calls'];
            if ($ticket !== str_repeat('t', 124) || $destination !== 'copy-a'
                || $fingerprint !== str_repeat('f', 64)) {
                return new \WP_Error('invalid_stage', 'invalid');
            }
            if ($GLOBALS['scenario'] === 'revoked') {
                $GLOBALS['can_manage'] = false;
            }
            if ($GLOBALS['scenario'] === 'actor-switch') {
                $GLOBALS['actor'] = 9;
            }
            if ($GLOBALS['scenario'] === 'unknown') return ['status' => 'partial', 'record_count' => 1, 'applied_count' => 0];
            return [
                'status' => 'complete', 'record_count' => 1, 'applied_count' => 1,
                'items' => [['reference' => 'template:copy-a']],
            ];
        }
    }
    require_once $argv[1];
    use CoreBlueprint\Core\DataExchange\ResourcePackageController as Controller;

    $scenario = $GLOBALS['scenario'];
    $auth = ['X-WP-Nonce' => $scenario === 'bad-nonce' ? 'wrong' : 'valid'];
    if ($scenario === 'no-capability') $GLOBALS['can_manage'] = false;
    if ($scenario === 'anonymous') $GLOBALS['actor'] = 0;
    if ($scenario === 'bad-upload') $GLOBALS['uploaded'] = false;
    if ($scenario === 'bad-file') $GLOBALS['uploaded'] = false;
    $fields = ['destinationId' => 'copy-a'];
    $files = ['package' => [
        'name' => 'untrusted.zip', 'type' => 'application/zip',
        'tmp_name' => '/verified-php-upload.tmp', 'error' => UPLOAD_ERR_OK, 'size' => 500,
    ]];
    if ($scenario === 'extra-upload-field') $fields['provider'] = 'attacker';
    $json = ['destinationId' => 'copy-a', 'ticket' => str_repeat('t', 124)];
    if ($scenario === 'bad-destination') $json['destinationId'] = '../private';
    if ($scenario === 'unexpected-json') $json['provider'] = 'attacker';

    Controller::init();
    Controller::routes();
    if (in_array($scenario, ['stage', 'bad-upload', 'bad-nonce', 'no-capability',
        'anonymous', 'extra-upload-field'], true)) {
        $result = Controller::stage(new \WP_REST_Request(headers:$auth,fields:$fields,files:$files));
    } elseif (in_array($scenario, ['preview', 'bad-preview', 'bad-destination', 'unexpected-json'], true)) {
        $result = Controller::preview(new \WP_REST_Request(headers:$auth,json:$json));
    } elseif (in_array($scenario, ['apply', 'unknown', 'actor-switch', 'revoked', 'bad-fingerprint'], true)) {
        $json['fingerprint'] = $scenario === 'bad-fingerprint'
            ? 'incorrect' : str_repeat('f', 64);
        $result = Controller::apply(new \WP_REST_Request(headers:$auth,json:$json));
    } elseif ($scenario === 'discard') {
        $result = Controller::discard(new \WP_REST_Request(headers:$auth,json:$json));
    } else {
        $result = Controller::preview(new \WP_REST_Request('GET', headers:$auth,json:$json));
    }
    $data = $result->get_data();
    $private = glob($GLOBALS['tempbase'] . '/cb-resource-package-*');
    echo json_encode([
        'http' => $result->get_status(),
        'data' => $data,
        'calls' => $GLOBALS['import_calls'],
        'staged' => $GLOBALS['staged'],
        'discarded' => $GLOBALS['discarded'],
        'root_count' => count($private ?: []),
        'root_mode' => $private ? (fileperms($private[0]) & 0777) : null,
        'routes' => $GLOBALS['routes'],
        'secrets_exposed' => str_contains(json_encode($data), $GLOBALS['tempbase'])
            || str_contains(json_encode($data), '/verified-php-upload.tmp'),
    ], JSON_THROW_ON_ERROR);
}
'''


class PackageHttpContractTests(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.folder = Path(temp.name)
        self.driver = self.folder / "driver.php"
        self.driver.write_text(PHP_DRIVER)

    def run_case(self, scenario):
        result = subprocess.run([
            "php", str(self.driver),
            str(ROOT / "src/DataExchange/ResourcePackageController.php"),
            str(self.folder), scenario
        ], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(result.stdout)

    def test_routes_are_nonce_and_capability_guarded(self):
        good = self.run_case("stage")
        self.assertEqual(4, len(good["routes"]))
        self.assertEqual(
            ["/template-resource-package/" + action for action in ("stage", "preview", "apply", "discard")],
            [x[1] for x in good["routes"]])
        self.assertTrue(all(x[0] == "core-blueprint/v1" and x[2] == "POST"
                            for x in good["routes"]))
        self.assertEqual(200, good["http"])
        self.assertTrue(good["staged"])
        self.assertTrue(good["data"]["ticket"])
        self.assertEqual(1, good["root_count"])
        self.assertEqual(0o700, good["root_mode"])
        self.assertFalse(good["secrets_exposed"])

    def test_bad_upload_nonce_capability_actor_and_fields_fail_without_staging(self):
        for scenario, status in (("bad-upload", 400), ("extra-upload-field", 400),
                                 ("bad-nonce", 403), ("no-capability", 403), ("anonymous", 403)):
            with self.subTest(scenario=scenario):
                output = self.run_case(scenario)
                self.assertEqual(status, output["http"], output)
                self.assertFalse(output["staged"], output)
                self.assertEqual(0, output["calls"], output)

    def test_preview_requires_exact_shape_and_does_not_write(self):
        preview = self.run_case("preview")
        self.assertEqual(200, preview["http"], preview)
        self.assertEqual("ready", preview["data"]["status"])
        self.assertEqual(1, preview["calls"])
        self.assertNotIn("cb_resource_package_binding", json.dumps(preview["data"]))
        self.assertFalse(preview["secrets_exposed"])
        for scenario in ("bad-preview", "bad-destination", "unexpected-json"):
            with self.subTest(scenario=scenario):
                denied = self.run_case(scenario)
                self.assertEqual(400, denied["http"], denied)
                self.assertFalse(denied["secrets_exposed"])

    def test_apply_confirmation_and_ambiguous_outcome_fail_closed(self):
        applied = self.run_case("apply")
        self.assertEqual(200, applied["http"], applied)
        self.assertEqual({"status": "complete", "reference": "template:copy-a"}, applied["data"])
        for scenario in ("unknown", "actor-switch", "revoked"):
            with self.subTest(scenario=scenario):
                uncertain = self.run_case(scenario)
                self.assertEqual(503, uncertain["http"], uncertain)
                self.assertEqual({"status": "unknown"}, uncertain["data"])
        invalid = self.run_case("bad-fingerprint")
        self.assertEqual(400, invalid["http"])
        self.assertEqual(0, invalid["calls"])

    def test_discard_and_non_post_refusal(self):
        discarded = self.run_case("discard")
        self.assertEqual(200, discarded["http"], discarded)
        self.assertTrue(discarded["discarded"])
        refusal = self.run_case("wrong-method")
        self.assertEqual(403, refusal["http"])
        self.assertEqual(0, refusal["calls"])


if __name__ == "__main__":
    unittest.main()
