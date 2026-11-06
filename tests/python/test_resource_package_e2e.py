"""FRG-T4-01E: real ZIP -> verifier -> private stage -> canonical Engine.

Uses the actual Base PHP production classes and a test-only Registry/entity.
No WordPress DB, media creation, upload endpoint, or consumer app is invoked.
"""
import base64
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9gZyC9sAAAAASUVORK5CYII="
)
HAS_ZIP = subprocess.run(
    ["php", "-r", "exit(class_exists('ZipArchive', false) ? 0 : 1);"],
    check=False, capture_output=True,
).returncode == 0

PHP_DRIVER = r'''<?php
declare(strict_types=1);
namespace {
    define('ABSPATH', '/nonexistent-wp-public-root/');
    class WP_Error {
        public function __construct(public string $code, public string $message, public mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    $GLOBALS['actor'] = 7;
    $GLOBALS['allowed'] = true;
    $GLOBALS['domain_allowed'] = true;
    $GLOBALS['plan_revision'] = 1;
    $GLOBALS['skip_binding'] = false;
    $GLOBALS['store'] = [];
    $GLOBALS['apply_calls'] = 0;
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function current_user_can(string $capability): bool {
        return $capability === 'upload_files' && $GLOBALS['allowed'];
    }
    function wp_salt(string $kind): string { return str_repeat('s', 64) . $kind; }
    function sanitize_text_field(string $s): string { return $s; }
}
namespace CoreBlueprint\Core\Interoperability {
    final class Registry {
        public static function is_ready(): bool { return true; }
        public static function implementation(string $owner, string $contract, string $version,
            string $provider, string $entity): ?array {
            return $provider === 'acme-integrated' && $entity === 'template.report'
                ? ['provider' => $provider, 'id' => $entity,
                    'supports' => ['import', 'format.json']] : null;
        }
        public static function resolve(string $owner, string $contract, string $version,
            string $provider, string $entity): \CoreBlueprint\Core\DataExchange\EntityInterface {
            return new \IntegrationEntity();
        }
    }
}
namespace {
    require_once $argv[1] . '/src/DataExchange/Foundation.php';
    require_once $argv[1] . '/src/DataExchange/EntityInterface.php';
    require_once $argv[1] . '/src/DataExchange/Engine.php';
    require_once $argv[1] . '/src/DataExchange/ResourcePackageVerifier.php';
    require_once $argv[1] . '/src/DataExchange/ResourcePackageStage.php';
    require_once $argv[1] . '/src/DataExchange/ResourcePackageImport.php';

    use CoreBlueprint\Core\DataExchange\Engine;
    use CoreBlueprint\Core\DataExchange\EntityInterface;
    use CoreBlueprint\Core\DataExchange\Foundation;
    use CoreBlueprint\Core\DataExchange\ResourcePackageImport as Bridge;
    use CoreBlueprint\Core\DataExchange\ResourcePackageStage as Stage;

    final class IntegrationEntity implements EntityInterface {
        public function is_available(): bool { return true; }
        public function schema_version(): int { return 1; }
        public function supports_schema_version(int $v): bool { return $v === 1; }
        public function can_export(array $context = []): bool { return false; }
        public function export_records(array $context = []): iterable|WP_Error { return []; }
        public function can_import(array $context = []): bool {
            return $GLOBALS['domain_allowed'] && current_user_can('upload_files')
                && get_current_user_id() === 7
                && ($context['intent'] ?? null) === 'create_personal_copy'
                && ($context['destination_id'] ?? null) === 'copy-a';
        }
        public function plan_import(array $record, string $mode, int $schema, array $context = []): array|WP_Error {
            if (!$this->can_import($context) || $mode !== Foundation::MODE_CREATE_ONLY
                || $schema !== 1 || isset($GLOBALS['store']['copy-a'])
                || array_keys($record) !== ['portable_id', 'resources', 'document']
                || ($record['document'] ?? null) !== ['type' => 'report', 'resource_key' => 'brand-logo']) {
                return new WP_Error('integration_invalid_record', 'Invalid or occupied destination.');
            }
            $plan = [
                'operation' => Foundation::OP_CREATE,
                'reference' => 'template:copy-a',
                'payload' => ['actor' => get_current_user_id(),
                    'destination' => 'copy-a', 'source' => $record['portable_id'],
                    'revision' => $GLOBALS['plan_revision'],
                    'document' => $record['document']],
                'warnings' => [],
            ];
            if ($GLOBALS['skip_binding']) { return $plan; }
            $bound = Bridge::bind_plan($plan, $context);
            if (is_wp_error($bound)) { return $bound; }
            if (($record['resources'] ?? null) !==
                $bound['payload']['cb_resource_package_binding']['resources']) {
                return new WP_Error('integration_unverified_resource', 'Unverified record asset.');
            }
            return $bound;
        }
        public function apply_import(array $plan, array $context = []): array|WP_Error {
            ++$GLOBALS['apply_calls'];
            if (!Bridge::assert_bound_plan($plan, $context) || !$this->can_import($context)
                || isset($GLOBALS['store']['copy-a'])) {
                return new WP_Error('integration_apply_forbidden', 'Apply forbidden.');
            }
            $GLOBALS['store']['copy-a'] = [
                'owner' => get_current_user_id(),
                'status' => 'draft',
                'document' => $plan['payload']['document'],
            ];
            return ['reference' => 'template:copy-a'];
        }
    }

    function summarize(array|WP_Error $value): array {
        if ($value instanceof WP_Error) { return ['error' => $value->get_error_code()]; }
        return [
            'valid' => $value['valid'] ?? null,
            'status' => $value['status'] ?? null,
            'fingerprint' => $value['fingerprint'] ?? null,
            'record_count' => $value['record_count'] ?? null,
            'leaks_binding' => str_contains(json_encode($value), 'cb_resource_package_binding'),
        ];
    }

    $source = $argv[2];
    $root = $argv[3];
    $scenario = $argv[4];
    $out = [];
    $stage = Stage::stage($source, $root, 'copy-a', 'upload_files');
    $out['stage'] = $stage instanceof WP_Error ? $stage->get_error_code() : 'ok';
    if (!($stage instanceof WP_Error)) {
        $pre = Bridge::preview($stage, $root, 'copy-a');
        $out['preview'] = summarize($pre);
        $out['writes_at_preview'] = count($GLOBALS['store']);
        $out['apply_calls_at_preview'] = $GLOBALS['apply_calls'];
        if ($pre instanceof WP_Error || !($pre['valid'] ?? false)) {
            $out['discard'] = Stage::discard($stage, $root, 'copy-a');
        } else {
            switch ($scenario) {
                case 'changed-actor': $GLOBALS['actor'] = 8; break;
                case 'revoked-capability': $GLOBALS['allowed'] = false; break;
                case 'revoked-domain': $GLOBALS['domain_allowed'] = false; break;
                case 'stale-plan': $GLOBALS['plan_revision'] = 2; break;
                case 'missing-binding': $GLOBALS['skip_binding'] = true; break;
                case 'mutated-stage':
                    foreach (glob($root . '/*.zip') as $file) {
                        file_put_contents($file, 'PK-modified after preview');
                    }
                    break;
                case 'discarded-stage': Stage::discard($stage, $root, 'copy-a'); break;
                case 'expired-ticket':
                    [$encoded, $signature] = explode('.', $stage);
                    $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
                    $payload['expires'] = time() - 1;
                    $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
                    $key = wp_salt('auth') . wp_salt('secure_auth');
                    $stage = $encoded . '.' . hash_hmac('sha256', 'cb-resource-stage-v1:' . $encoded, $key);
                    break;
            }
            $out['apply'] = summarize(Bridge::apply(
                $stage, $root, $scenario === 'changed-destination' ? 'copy-b' : 'copy-a',
                $pre['fingerprint']
            ));
            if ($scenario === 'happy') {
                $out['discard'] = Stage::discard($stage, $root, 'copy-a');
            }
        }
    }
    $out['writes_after'] = count($GLOBALS['store']);
    $out['apply_calls_after'] = $GLOBALS['apply_calls'];
    $out['store'] = $GLOBALS['store'];
    $out['remaining_files'] = count(glob($root . '/*.zip'));
    echo json_encode($out, JSON_THROW_ON_ERROR);
}
'''


def make_package(path: Path, *, scenario: str = "happy") -> None:
    digest = hashlib.sha256(PNG).hexdigest()
    resource = {"key": "brand-logo", "sha256": digest, "size": len(PNG), "media_type": "image/png"}
    record = {
        "portable_id": "report-a",
        "resources": [resource],
        "document": {"type": "report", "resource_key": "brand-logo"},
    }
    doc = {
        "format": "core-blueprint-data-exchange", "format_version": 1,
        "extension_id": "acme-integrated", "entity": "template.report",
        "schema_version": 1, "exported_at": "2026-11-06T00:00:00Z", "records": [record],
    }
    document_json = json.dumps(doc, separators=(",", ":"), ensure_ascii=False).encode()
    manifest = {
        "package_format": "core-blueprint-template-resources", "package_version": 1,
        "extension_id": "acme-integrated", "entity": "template.report", "record_count": 1,
        "document": {"path": "data-exchange.json", "size": len(document_json),
                     "sha256": hashlib.sha256(document_json).hexdigest()},
        "resources": [{"key": "brand-logo", "path": f"resources/sha256/{digest}",
                       "media_type": "image/png", "size": len(PNG), "sha256": digest}],
    }
    if scenario == "invalid-digest":
        manifest["document"]["sha256"] = "0" * 64
    if scenario == "invalid-record-resource":
        doc["records"][0]["resources"][0]["sha256"] = "a" * 64
        document_json = json.dumps(doc, separators=(",", ":"), ensure_ascii=False).encode()
        manifest["document"]["size"] = len(document_json)
        manifest["document"]["sha256"] = hashlib.sha256(document_json).hexdigest()
    entries = [
        ("manifest.json", json.dumps(manifest, separators=(",", ":"), ensure_ascii=False).encode()),
        ("data-exchange.json", document_json),
        (f"resources/sha256/{digest}", PNG),
    ]
    if scenario == "extra-member":
        entries.append(("readme.php", b"<?php echo 1;"))
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, data in entries:
            archive.writestr(name, data)


@unittest.skipUnless(HAS_ZIP, "PHP ZipArchive is optional and unavailable")
class RealPackageEndToEndTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.source = self.folder / "source.zip"
        self.private = self.folder / "private"
        self.private.mkdir(mode=0o700)
        self.script = self.folder / "driver.php"
        self.script.write_text(PHP_DRIVER)

    def run_case(self, scenario: str):
        make_package(self.source, scenario=scenario)
        completed = subprocess.run(
            ["php", str(self.script), str(ROOT), str(self.source), str(self.private), scenario],
            capture_output=True, text=True,
        )
        self.assertEqual(0, completed.returncode, completed.stderr)
        return json.loads(completed.stdout)

    def test_real_zip_stage_preview_apply_and_discard(self):
        result = self.run_case("happy")
        self.assertEqual("ok", result["stage"], result)
        self.assertTrue(result["preview"]["valid"], result)
        self.assertEqual(0, result["writes_at_preview"])
        self.assertEqual(0, result["apply_calls_at_preview"])
        self.assertFalse(result["preview"]["leaks_binding"])
        self.assertEqual("complete", result["apply"]["status"], result)
        self.assertEqual(1, result["writes_after"])
        self.assertEqual(1, result["apply_calls_after"])
        self.assertEqual("draft", result["store"]["copy-a"]["status"])
        self.assertTrue(result["discard"])
        self.assertEqual(0, result["remaining_files"])

    def test_invalid_zip_entries_and_digest_fail_before_preview(self):
        for scenario in ("invalid-digest", "invalid-record-resource", "extra-member"):
            with self.subTest(scenario=scenario):
                result = self.run_case(scenario)
                self.assertEqual("cb_core_resource_package_invalid", result["stage"], result)
                self.assertEqual(0, result["writes_after"])
                self.assertEqual(0, result["apply_calls_after"])
                self.assertEqual(0, result["remaining_files"])

    def test_mutated_or_discarded_staging_fails_before_write(self):
        for scenario in ("mutated-stage", "discarded-stage"):
            with self.subTest(scenario=scenario):
                result = self.run_case(scenario)
                self.assertTrue(result["preview"]["valid"], result)
                self.assertEqual("cb_core_resource_stage_unavailable", result["apply"]["error"], result)
                self.assertEqual(0, result["writes_after"])
                self.assertEqual(0, result["apply_calls_after"])

    def test_revoked_permissions_and_changed_actor_fail_closed(self):
        for scenario, expected in (
            ("changed-actor", "cb_core_resource_stage_unavailable"),
            ("revoked-capability", "cb_core_resource_stage_unavailable"),
            ("revoked-domain", "cb_core_data_exchange_forbidden"),
            ("changed-destination", "cb_core_resource_stage_unavailable"),
        ):
            with self.subTest(scenario=scenario):
                result = self.run_case(scenario)
                self.assertEqual(expected, result["apply"]["error"], result)
                self.assertEqual(0, result["writes_after"])
                self.assertEqual(0, result["apply_calls_after"])

    def test_expired_signed_ticket_refuses_apply(self):
        result = self.run_case("expired-ticket")
        self.assertEqual("cb_core_resource_stage_unavailable", result["apply"]["error"], result)
        self.assertEqual(0, result["writes_after"])
        self.assertEqual(0, result["apply_calls_after"])

    def test_changed_canonical_plan_stales_engine_fingerprint(self):
        result = self.run_case("stale-plan")
        self.assertEqual("cb_core_data_exchange_stale_plan", result["apply"]["error"], result)
        self.assertEqual(0, result["writes_after"])
        self.assertEqual(0, result["apply_calls_after"])

    def test_missing_consumer_binding_fails_before_apply(self):
        result = self.run_case("missing-binding")
        self.assertEqual("cb_core_resource_package_binding_invalid", result["apply"]["error"], result)
        self.assertEqual(0, result["writes_after"])
        self.assertEqual(0, result["apply_calls_after"])


class RealPackageExtensionAbsentTest(unittest.TestCase):
    def test_missing_zip_extension_fails_closed_in_real_staging(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            private = base / "private"
            private.mkdir(mode=0o700)
            source = base / "source.zip"
            make_package(source)
            driver = base / "driver.php"
            driver.write_text(PHP_DRIVER)
            completed = subprocess.run(
                ["php", "-n", str(driver), str(ROOT), str(source), str(private), "happy"],
                capture_output=True, text=True,
            )
            self.assertEqual(0, completed.returncode, completed.stderr)
            result = json.loads(completed.stdout)
            self.assertEqual("cb_core_resource_package_unavailable", result["stage"], result)
            self.assertEqual(0, result["writes_after"])
            self.assertEqual(0, result["remaining_files"])


if __name__ == "__main__":
    unittest.main()
