"""FRG-T4-01D-2: exercise the real Base Data Exchange Engine with an isolated test-only entity.

No WordPress DB, archive decoding, browser endpoint or media writes. The Stage is
a deterministic trust-boundary fixture; the real stage/verifier have separate tests.
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
    define('ABSPATH', '/');
    class WP_Error {
        public function __construct(public string $code, public string $message, public mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function current_user_can(string $cap): bool { return 'upload_files' === $cap && $GLOBALS['allowed']; }
    function sanitize_text_field(string $value): string { return $value; }
    $GLOBALS['actor'] = 7;
    $GLOBALS['allowed'] = true;
    $GLOBALS['package_bytes'] = 'PK-fixture-a';
    $GLOBALS['stored'] = [];
    $GLOBALS['planner_calls'] = 0;
    $GLOBALS['scenario'] = $argv[2];
}

namespace CoreBlueprint\Core\DataExchange {
    final class ResourcePackageStage {
        public static function inspect(string $ticket, string $root, string $destination): array|\WP_Error {
            if ('fixed-private-ticket' !== $ticket || '/private' !== $root
                || $destination !== 'copy-a' || \get_current_user_id() !== 7
                || ! \current_user_can('upload_files')) {
                return new \WP_Error('cb_core_resource_stage_unavailable', 'Stage is unavailable.');
            }
            $record = [
                'portable_id' => 'origin-report',
                'resources' => [[
                    'key' => 'brand-logo', 'sha256' => str_repeat('b', 64),
                    'media_type' => 'image/png', 'size' => 68,
                ]],
                'document' => ['type' => 'report', 'resource_key' => 'brand-logo'],
            ];
            $doc = json_encode([
                'format' => Foundation::FORMAT_ID,
                'format_version' => Foundation::FORMAT_VERSION,
                'extension_id' => 'acme-test',
                'entity' => 'template.report',
                'schema_version' => 1,
                'exported_at' => '2026-11-06T00:00:00Z',
                'records' => [$record],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return [
                'document_json' => $doc,
                'document_sha256' => hash('sha256', $doc),
                'package_sha256' => hash('sha256', $GLOBALS['package_bytes']),
                'extension_id' => 'acme-test',
                'entity' => 'template.report',
                'resources' => $record['resources'],
            ];
        }
    }
}
namespace CoreBlueprint\Core\Interoperability {
    final class Registry {
        public static function is_ready(): bool { return true; }
        public static function implementation(string $owner, string $contract, string $version,
            string $provider, string $entity): ?array {
            return 'acme-test' === $provider && 'template.report' === $entity
                ? [
                    'provider' => $provider, 'id' => $entity,
                    'supports' => ['import', 'format.json'],
                ] : null;
        }
        public static function resolve(string $owner, string $contract, string $version,
            string $provider, string $entity): \CoreBlueprint\Core\DataExchange\EntityInterface {
            return new \FixtureEntity();
        }
    }
}
namespace {
    require_once $argv[1] . '/src/DataExchange/Foundation.php';
    require_once $argv[1] . '/src/DataExchange/EntityInterface.php';
    require_once $argv[1] . '/src/DataExchange/Engine.php';
    require_once $argv[1] . '/src/DataExchange/ResourcePackageImport.php';

    use CoreBlueprint\Core\DataExchange\EntityInterface;
    use CoreBlueprint\Core\DataExchange\Foundation;
    use CoreBlueprint\Core\DataExchange\ResourcePackageImport as Bridge;
    use CoreBlueprint\Core\DataExchange\ResourcePackageStage as Stage;

    final class FixtureEntity implements EntityInterface {
        public function is_available(): bool { return true; }
        public function schema_version(): int { return 1; }
        public function supports_schema_version(int $v): bool { return $v === 1; }
        public function can_export(array $context = []): bool { return false; }
        public function export_records(array $context = []): iterable|WP_Error { return []; }
        public function can_import(array $context = []): bool {
            return \current_user_can('upload_files')
                && ($context['intent'] ?? null) === 'create_personal_copy'
                && ($context['destination_id'] ?? null) === 'copy-a';
        }
        public function plan_import(array $record, string $mode, int $schema, array $context = []): array|WP_Error {
            ++$GLOBALS['planner_calls'];
            if ($mode !== Foundation::MODE_CREATE_ONLY || $schema !== 1 || !$this->can_import($context)
                || ($record['resources'][0]['key'] ?? null) !== 'brand-logo'
                || isset($GLOBALS['stored']['copy-a'])) {
                return new WP_Error('fixture_invalid', 'Import not permitted.');
            }
            $plan = [
                'operation' => Foundation::OP_CREATE,
                'reference' => 'resource-template:copy-a',
                'payload' => [
                    'actor' => \get_current_user_id(),
                    'destination' => $context['destination_id'],
                    'document' => $record['document'],
                ],
                'warnings' => [],
            ];
            if ($GLOBALS['scenario'] === 'omit-binding'
                || ($GLOBALS['scenario'] === 'omit-second-plan' && $GLOBALS['planner_calls'] >= 3)) {
                return $plan;
            }
            return Bridge::bind_plan($plan, $context);
        }
        public function apply_import(array $plan, array $context = []): array|WP_Error {
            if (!Bridge::assert_bound_plan($plan, $context) || !$this->can_import($context)) {
                return new WP_Error('fixture_unbound', 'Package binding is missing.');
            }
            if (isset($GLOBALS['stored']['copy-a'])) {
                return new WP_Error('fixture_collision', 'Destination occupied.');
            }
            $GLOBALS['stored']['copy-a'] = ['owner' => \get_current_user_id(), 'draft' => true];
            return ['reference' => 'resource-template:copy-a'];
        }
    }

    function summarize(array|WP_Error $value): array {
        if ($value instanceof WP_Error) {
            return ['error' => $value->get_error_code()];
        }
        return [
            'valid' => $value['valid'] ?? null,
            'status' => $value['status'] ?? null,
            'fingerprint' => $value['fingerprint'] ?? null,
            'has_preview_binding' => str_contains(json_encode($value), 'cb_resource_package_binding'),
        ];
    }

    $context = [];
    if ($GLOBALS['scenario'] === 'context-spoof') {
        $context['cb_resource_package_scope'] = 'fake-user-scope';
    }
    if ($GLOBALS['scenario'] === 'destination-override') {
        $context['destination_id'] = 'copy-b';
    }

    $preview = Bridge::preview('fixed-private-ticket', '/private', 'copy-a', $context);
    $out = ['preview' => summarize($preview), 'written_before_apply' => count($GLOBALS['stored'])];
    if (!($preview instanceof WP_Error) && !empty($preview['valid'])) {
        $fingerprint = $preview['fingerprint'];
        if ($GLOBALS['scenario'] === 'changed-package') {
            $GLOBALS['package_bytes'] = 'PK-fixture-b';
        } elseif ($GLOBALS['scenario'] === 'changed-actor') {
            $GLOBALS['actor'] = 8;
        } elseif ($GLOBALS['scenario'] === 'revoked') {
            $GLOBALS['allowed'] = false;
        } elseif ($GLOBALS['scenario'] === 'changed-destination') {
            $destination = 'copy-b';
        }
        $to_apply = $GLOBALS['scenario'] === 'invalid-fingerprint' ? 'incorrect' : $fingerprint;
        $result = Bridge::apply('fixed-private-ticket', '/private', $destination ?? 'copy-a', $to_apply);
        $out['apply'] = summarize($result);
    }
    $outside = Bridge::bind_plan([
        'operation' => Foundation::OP_CREATE, 'payload' => [],
    ], ['cb_resource_package_scope' => 'arbitrary']);
    $out['outside_scope_denied'] = $outside instanceof WP_Error;
    $out['written_after_apply'] = count($GLOBALS['stored']);
    echo json_encode($out, JSON_THROW_ON_ERROR);
}
'''


class PackageImportBindingTest(unittest.TestCase):
    def run_case(self, name):
        with tempfile.TemporaryDirectory() as d:
            script = Path(d) / 'driver.php'
            script.write_text(PHP_DRIVER)
            result = subprocess.run(
                ['php', str(script), str(ROOT), name],
                capture_output=True, text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            return json.loads(result.stdout)

    def test_preview_is_read_only_and_apply_creates_one_draft(self):
        result = self.run_case('happy')
        self.assertTrue(result['preview']['valid'], result)
        self.assertEqual(0, result['written_before_apply'])
        self.assertEqual('complete', result['apply']['status'], result)
        self.assertEqual(1, result['written_after_apply'])
        self.assertFalse(result['preview']['has_preview_binding'])
        self.assertTrue(result['outside_scope_denied'])

    def test_package_changed_between_requests_refuses(self):
        result = self.run_case('changed-package')
        self.assertEqual('cb_core_data_exchange_stale_plan', result['apply']['error'])
        self.assertEqual(0, result['written_after_apply'])

    def test_actor_changed_between_requests_refuses(self):
        result = self.run_case('changed-actor')
        self.assertEqual('cb_core_resource_stage_unavailable', result['apply']['error'])
        self.assertEqual(0, result['written_after_apply'])

    def test_permission_revoked_between_requests_refuses(self):
        result = self.run_case('revoked')
        self.assertEqual('cb_core_resource_stage_unavailable', result['apply']['error'])
        self.assertEqual(0, result['written_after_apply'])

    def test_destination_changed_between_requests_refuses(self):
        result = self.run_case('changed-destination')
        self.assertEqual('cb_core_resource_stage_unavailable', result['apply']['error'])
        self.assertEqual(0, result['written_after_apply'])

    def test_consumer_must_bind_its_canonical_plan(self):
        result = self.run_case('omit-binding')
        self.assertEqual('cb_core_resource_package_binding_invalid', result['preview']['error'])
        self.assertEqual(0, result['written_after_apply'])

    def test_missing_binding_on_apply_replan_cannot_write(self):
        result = self.run_case('omit-second-plan')
        self.assertEqual('cb_core_data_exchange_stale_plan', result['apply']['error'])
        self.assertEqual(0, result['written_after_apply'])

    def test_reserved_context_scope_cannot_be_supplied_by_caller(self):
        result = self.run_case('context-spoof')
        self.assertEqual('cb_core_resource_package_binding_invalid', result['preview']['error'])
        self.assertEqual(0, result['written_after_apply'])

    def test_destination_override_cannot_be_supplied_by_caller(self):
        result = self.run_case('destination-override')
        self.assertEqual('cb_core_resource_package_binding_invalid', result['preview']['error'])
        self.assertEqual(0, result['written_after_apply'])

    def test_invalid_fingerprint_rejected_before_apply(self):
        result = self.run_case('invalid-fingerprint')
        self.assertEqual('cb_core_data_exchange_invalid_fingerprint', result['apply']['error'])
        self.assertEqual(0, result['written_after_apply'])


if __name__ == '__main__':
    unittest.main()
