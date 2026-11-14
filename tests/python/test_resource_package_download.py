"""FRG-T4-01J: actual Base admin-post download action with isolated WP shims.

This driver deliberately stubs the package-provisioning boundary; real archive
bytes and provider-authorized export are covered by separate ZIP/Engine suites.
"""
import hashlib
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
DOWNLOAD_BYTES = b"PK\x03\x04fixture-zip-delivery-verified-bytes"

PHP_DRIVER = r'''<?php
declare(strict_types=1);
namespace {
    define('ABSPATH', '/unavailable-public-site/');
    class WP_Error {
        public function __construct(private string $code, private string $message) {}
    }
    function is_wp_error(mixed $x): bool { return $x instanceof WP_Error; }
    class WP_REST_Request {}
    class WP_REST_Response {
        public function __construct(array $data, int $status) {}
        public function header(string $name, string $value): void {}
    }
    $GLOBALS['actor'] = 7;
    $GLOBALS['allowed'] = true;
    $GLOBALS['nonce'] = true;
    $GLOBALS['scenario'] = $argv[3];
    $GLOBALS['tmp'] = $argv[2];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'action' => 'cb_core_template_resource_export',
        '_wpnonce' => 'trusted-nonce',
        'source' => 'acme-test',
        'templateId' => 'report-a',
        'version' => '1.0',
    ];
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function get_current_blog_id(): int { return 1; }
    function is_user_logged_in(): bool { return $GLOBALS['actor'] > 0; }
    function current_user_can(string $cap): bool {
        return $cap === 'manage_options' && $GLOBALS['allowed'];
    }
    function wp_salt(string $type): string { return str_repeat('s', 64); }
    function wp_unslash(mixed $v): mixed { return $v; }
    function wp_verify_nonce(string $nonce, string $scope): int|false {
        return $GLOBALS['nonce'] && $nonce === 'trusted-nonce' && $scope === 'wp_rest' ? 1 : false;
    }
    function wp_die(string $message, string $title = '', array $opts = []): never {
        throw new \RuntimeException('refused-' . ($opts['response'] ?? 500));
    }
    function add_action(string $event, array $handler, int $priority = 10): void {}
    if ($GLOBALS['scenario'] === 'wrong-method') $_SERVER['REQUEST_METHOD'] = 'GET';
    if ($GLOBALS['scenario'] === 'bad-nonce') $_POST['_wpnonce'] = 'invalid';
    if ($GLOBALS['scenario'] === 'unauthorized') $GLOBALS['allowed'] = false;
    if ($GLOBALS['scenario'] === 'anonymous') $GLOBALS['actor'] = 0;
    if ($GLOBALS['scenario'] === 'extra-field') $_POST['provider'] = 'attacker';
    if ($GLOBALS['scenario'] === 'wrong-action') $_POST['action'] = 'other';
    if ($GLOBALS['scenario'] === 'invalid-id') $_POST['templateId'] = '../secret';
    if ($GLOBALS['scenario'] === 'invalid-version') $_POST['version'] = '0.0';
}
namespace CoreBlueprint\Core\DataExchange {
    function sys_get_temp_dir(): string { return $GLOBALS['tmp']; }
    final class ResourcePackageStage {
        static function private_root_path(string $root): ?string {
            return !is_link($root) && is_dir($root) && (fileperms($root) & 0077) === 0
                ? realpath($root) : null;
        }
    }
    final class ResourcePackageExport {
        static function discard(string $path, string $root): bool {
            if (!str_starts_with($path, $root . '/') || is_link($path)) return false;
            return @unlink($path);
        }
    }
}
namespace CoreBlueprint\Core\Log {
    final class AuditLog {
        static function log(string $event, string $severity, array $context): int|false {
            if ($event !== 'template_package_export_delivery' || $severity !== 'info'
                || ($context['source'] ?? '') !== 'acme-test'
                || ($context['template'] ?? '') !== 'report-a'
                || ($context['version'] ?? '') !== '1.0') return false;
            if ($GLOBALS['scenario'] === 'audit-failed') return false;
            if ($GLOBALS['scenario'] === 'audit-revoked') $GLOBALS['allowed'] = false;
            return 1;
        }
    }
}
namespace CoreBlueprint\Core\TemplateLibrary {
    final class TemplateSourceRegistry {
        static function export_resource_package(
            string $source, string $templateId, string $version, string $root
        ): array|\WP_Error {
            if ($source !== 'acme-test' || $templateId !== 'report-a'
                || $version !== '1.0' || $GLOBALS['scenario'] === 'provider-refuses') {
                return new \WP_Error('denied', 'No source.');
            }
            $path = $root . '/' . str_repeat('a', 48) . '.export.zip';
            $bytes = hex2bin('504b0304666978747572652d7a69702d64656c69766572792d76657269666965642d6279746573');
            file_put_contents($path, $bytes);
            chmod($path, 0600);
            $digest = hash('sha256', $bytes);
            if ($GLOBALS['scenario'] === 'digest-mismatch') $digest = str_repeat('b', 64);
            if ($GLOBALS['scenario'] === 'actor-switched') $GLOBALS['actor'] = 8;
            if ($GLOBALS['scenario'] === 'revoke') $GLOBALS['allowed'] = false;
            return ['path'=>$path,'package_sha256'=>$digest,'document_sha256'=>str_repeat('a',64),'resources'=>[]];
        }
    }
}
namespace {
    require_once $argv[1];
    try {
        \CoreBlueprint\Core\DataExchange\ResourcePackageController::download();
    } catch (\RuntimeException $e) {
        echo $e->getMessage();
    }
}
'''


class ResourcePackageDownloadTests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.folder = Path(tmp.name)
        self.driver = self.folder / "driver.php"
        self.driver.write_text(PHP_DRIVER)

    def call(self, scenario):
        result = subprocess.run([
            "php", str(self.driver),
            str(ROOT / "src/DataExchange/ResourcePackageController.php"),
            str(self.folder), scenario,
        ], capture_output=True)
        self.assertEqual(0, result.returncode, result.stderr.decode(errors="replace"))
        self.assertFalse(list(self.folder.glob("cb-resource-package-*/*.zip")),
                         "No export ZIP may remain after a completed or refused delivery")
        return result.stdout

    def test_confirmed_download_emits_exact_zip_bytes_then_deletes_private_file(self):
        out = self.call("happy")
        self.assertEqual(DOWNLOAD_BYTES, out)
        self.assertEqual(hashlib.sha256(DOWNLOAD_BYTES).hexdigest(),
                         hashlib.sha256(out).hexdigest())

    def test_request_shape_csrf_actor_and_version_refuse_without_binary_output(self):
        for scenario in ("wrong-method", "bad-nonce", "unauthorized", "anonymous",
                         "extra-field", "wrong-action", "invalid-id", "invalid-version"):
            with self.subTest(scenario=scenario):
                self.assertEqual(b"refused-403", self.call(scenario))

    def test_failed_export_invalid_digest_and_revocation_never_stream(self):
        self.assertEqual(b"refused-403", self.call("provider-refuses"))
        for scenario in ("digest-mismatch", "actor-switched", "revoke", "audit-failed", "audit-revoked"):
            with self.subTest(scenario=scenario):
                self.assertEqual(b"refused-503", self.call(scenario))


if __name__ == "__main__":
    unittest.main()
