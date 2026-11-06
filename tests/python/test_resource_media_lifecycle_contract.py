"""FRG-T4-01D-3: consumer-owned media apply/cleanup contract (test-only fixture).

No production media service, WordPress DB or real asset decoder is exercised.
The fixture makes failure/rollback ordering and truthful status observable.
"""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

FIXTURE = r'''<?php
declare(strict_types=1);

final class ResourceMediaLifecycleFixture {
    public array $assets = ['shared-1' => ['owner' => 'other']];
    public array $drafts = [];
    public array $events = [];
    public array $journal = [];
    private int $sequence = 0;

    public function preview(array $package, bool $authorized = true): array {
        $before = count($this->events);
        $ok = $this->preflight($package, $authorized);
        return ['valid' => $ok, 'write_count' => count($this->events) - $before];
    }

    public function apply(array $package, string $failure = '', bool $authorized = true): array {
        if (!$this->preflight($package, $authorized)) {
            return ['status' => 'failed', 'reason' => 'preflight', 'cleanup' => 'not_needed'];
        }
        foreach ($package['resources'] as $resource) {
            if ($failure === 'media_write') {
                return $this->fail('media_write', $failure);
            }
            $id = 'created-' . (++$this->sequence);
            $this->assets[$id] = ['owner' => 'current', 'sha256' => $resource['sha256']];
            $this->journal[] = $id; // Record only newly-created resources, before draft write.
            $this->events[] = 'create:' . $id;
        }
        if ($failure === 'draft_write' || $failure === 'cleanup_failure') {
            return $this->fail($failure, $failure);
        }
        if (isset($this->drafts['destination-a'])) {
            return $this->fail('destination_collision', $failure);
        }
        $this->drafts['destination-a'] = ['state' => 'draft', 'assets' => $this->journal];
        $this->events[] = 'draft:create';
        $this->journal = [];
        return ['status' => 'complete', 'created' => count($package['resources'])];
    }

    private function fail(string $reason, string $failure): array {
        $unremoved = [];
        // Reverse order: last created is cleaned first.
        foreach (array_reverse($this->journal) as $id) {
            if ($failure === 'cleanup_failure' && $id === 'created-1') {
                $unremoved[] = $id;
                $this->events[] = 'cleanup_failed:' . $id;
                continue;
            }
            if (!isset($this->assets[$id]) || $this->assets[$id]['owner'] !== 'current') {
                $unremoved[] = $id; // Never delete unknown or non-owned assets.
                continue;
            }
            unset($this->assets[$id]);
            $this->events[] = 'delete:' . $id;
        }
        $this->journal = array_reverse($unremoved);
        return [
            'status' => 'failed',
            'reason' => $reason,
            'cleanup' => $unremoved === [] ? 'complete' : 'incomplete',
            'pending_ids' => $this->journal,
        ];
    }

    private function preflight(array $package, bool $authorized): bool {
        if (!$authorized || ($package['destination'] ?? '') !== 'destination-a'
            || ($package['mode'] ?? '') !== 'create_only'
            || ($package['actor'] ?? 0) !== 7
            || isset($this->drafts['destination-a'])
            || !is_array($package['resources'] ?? null)
            || !array_is_list($package['resources']) || count($package['resources']) > 16) {
            return false;
        }
        $keys = [];
        $total = 0;
        foreach ($package['resources'] as $resource) {
            if (!is_array($resource) || array_keys($resource) !== ['key','media_type','size','sha256','bytes']
                || !is_string($resource['key']) || !preg_match('/^[a-z][a-z0-9-]*$/D', $resource['key'])
                || isset($keys[$resource['key']])
                || !in_array($resource['media_type'], ['image/png', 'image/jpeg', 'image/webp'], true)
                || !is_int($resource['size']) || $resource['size'] < 1 || $resource['size'] > 5*1024*1024
                || !is_string($resource['bytes']) || strlen($resource['bytes']) !== $resource['size']
                || !is_string($resource['sha256'])
                || !hash_equals(hash('sha256', $resource['bytes']), $resource['sha256'])) {
                return false;
            }
            $keys[$resource['key']] = true;
            $total += $resource['size'];
        }
        return $total <= 20 * 1024 * 1024;
    }
}

function fixture_package(int $resource_count = 1): array {
    $resources = [];
    for ($i=0; $i<$resource_count; ++$i) {
        $bytes = 'image-bytes-' . $i;
        $resources[] = [
            'key' => 'artwork-' . $i,
            'media_type' => 'image/png',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'bytes' => $bytes,
        ];
    }
    return ['destination' => 'destination-a', 'actor' => 7,
            'mode' => 'create_only', 'resources' => $resources];
}

$case = $argv[1];
$fixture = new ResourceMediaLifecycleFixture();
$package = fixture_package(in_array($case, ['reverse_cleanup','cleanup_failure'], true) ? 2 : 1);
$failure = '';
$allowed = true;
switch ($case) {
    case 'denied': $allowed = false; break;
    case 'wrong_actor': $package['actor'] = 8; break;
    case 'wrong_destination': $package['destination'] = 'destination-b'; break;
    case 'foreign_id': $package['resources'][0]['source_attachment_id'] = 345; break;
    case 'remote_url': $package['resources'][0]['url'] = 'https://source.invalid/image.png'; break;
    case 'tampered_bytes': $package['resources'][0]['bytes'] = 'not-the-same'; break;
    case 'unsupported_mime': $package['resources'][0]['media_type'] = 'image/svg+xml'; break;
    case 'duplicate_key': $package = fixture_package(2); $package['resources'][1]['key'] = $package['resources'][0]['key']; break;
    case 'media_failure': $failure = 'media_write'; break;
    case 'draft_failure': $failure = 'draft_write'; break;
    case 'reverse_cleanup': $failure = 'draft_write'; break;
    case 'cleanup_failure': $failure = 'cleanup_failure'; break;
    case 'too_many': $package = fixture_package(17); break;
}
$preview = $fixture->preview($package, $allowed);
$result = $fixture->apply($package, $failure, $allowed);
echo json_encode([
    'preview' => $preview, 'apply' => $result, 'assets' => $fixture->assets,
    'drafts' => $fixture->drafts, 'events' => $fixture->events,
], JSON_THROW_ON_ERROR);
'''


class ConsumerMediaLifecycleContractTests(unittest.TestCase):
    def run_case(self, name):
        with tempfile.TemporaryDirectory() as temp:
            script = Path(temp) / 'fixture.php'
            script.write_text(FIXTURE)
            process = subprocess.run(['php', str(script), name], capture_output=True, text=True)
            self.assertEqual(0, process.returncode, process.stderr)
            return json.loads(process.stdout)

    def test_success_creates_only_draft_and_owned_media(self):
        result = self.run_case('success')
        self.assertTrue(result['preview']['valid'])
        self.assertEqual(0, result['preview']['write_count'])
        self.assertEqual('complete', result['apply']['status'])
        self.assertEqual('draft', result['drafts']['destination-a']['state'])
        self.assertEqual({'owner': 'other'}, result['assets']['shared-1'])
        self.assertEqual('current', result['assets']['created-1']['owner'])

    def test_rejected_inputs_never_write_resources_or_draft(self):
        for scenario in ('denied','wrong_actor','wrong_destination','foreign_id',
                         'remote_url','tampered_bytes','unsupported_mime','duplicate_key','too_many'):
            with self.subTest(scenario=scenario):
                result = self.run_case(scenario)
                self.assertFalse(result['preview']['valid'])
                self.assertEqual(0, result['preview']['write_count'])
                self.assertEqual('failed', result['apply']['status'])
                self.assertEqual([], result['drafts'])
                self.assertEqual(['shared-1'], list(result['assets']))

    def test_media_write_failure_does_not_create_draft(self):
        result = self.run_case('media_failure')
        self.assertEqual('failed', result['apply']['status'])
        self.assertEqual('complete', result['apply']['cleanup'])
        self.assertEqual([], result['drafts'])
        self.assertEqual(['shared-1'], list(result['assets']))

    def test_failed_draft_rolls_back_only_new_media(self):
        result = self.run_case('draft_failure')
        self.assertEqual('failed', result['apply']['status'])
        self.assertEqual('complete', result['apply']['cleanup'])
        self.assertEqual(['create:created-1', 'delete:created-1'], result['events'])
        self.assertEqual(['shared-1'], list(result['assets']))
        self.assertEqual([], result['drafts'])

    def test_multiple_created_resources_are_removed_in_reverse_order(self):
        result = self.run_case('reverse_cleanup')
        self.assertEqual(['create:created-1', 'create:created-2',
                          'delete:created-2', 'delete:created-1'], result['events'])
        self.assertEqual(['shared-1'], list(result['assets']))
        self.assertEqual([], result['drafts'])

    def test_cleanup_failure_never_claims_complete_import(self):
        result = self.run_case('cleanup_failure')
        self.assertEqual('failed', result['apply']['status'])
        self.assertEqual('incomplete', result['apply']['cleanup'])
        self.assertEqual(['created-1'], result['apply']['pending_ids'])
        self.assertEqual([], result['drafts'])
        self.assertIn('created-1', result['assets'])
        self.assertEqual({'owner': 'other'}, result['assets']['shared-1'])


if __name__ == '__main__':
    unittest.main()
