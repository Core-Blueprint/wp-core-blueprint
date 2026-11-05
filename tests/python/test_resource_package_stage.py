"""FRG-T4-01D: file-backed staging contract, independent of WordPress DB/ZIP extension."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
DRIVER = r'''<?php
namespace {
    define('ABSPATH', '/nonexistent/public-wordpress/');
    class WP_Error { public function __construct(public string $code, public string $message) {} }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    $GLOBALS['actor'] = 7;
    $GLOBALS['allowed'] = true;
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function current_user_can(string $cap): bool { return 'upload_files' === $cap && $GLOBALS['allowed']; }
    function wp_salt(string $kind): string { return str_repeat('s', 64) . $kind; }
    require_once $argv[1] . '/src/DataExchange/ResourcePackageStage.php';
}
namespace CoreBlueprint\Core\DataExchange {
    final class ResourcePackageVerifier {
        public static function verify_file(string $path): array|\WP_Error {
            return is_file($path) && str_starts_with(file_get_contents($path), 'PK')
                ? ['package_sha256' => hash_file('sha256', $path), 'document_json' => '{"records":[]}']
                : new \WP_Error('invalid_package', 'Invalid fixture.');
        }
    }
}
namespace {
    use CoreBlueprint\Core\DataExchange\ResourcePackageStage as Stage;
    $source = $argv[2]; $root = $argv[3]; $scenario = $argv[4];
    $result = Stage::stage($source, $root, 'report-a', 'upload_files');
    if ($result instanceof WP_Error) { echo json_encode(['stage_error'=>$result->code]); exit; }
    $ticket = $result;
    $report = ['ticket_length'=>strlen($ticket)];
    $inspect = Stage::inspect($ticket, $root, 'report-a');
    $report['inspected'] = !($inspect instanceof WP_Error);
    if ($scenario === 'wrong-actor') { $GLOBALS['actor']=8; }
    if ($scenario === 'revoked') { $GLOBALS['allowed']=false; }
    if ($scenario === 'wrong-destination') { $destination='report-b'; } else { $destination='report-a'; }
    if ($scenario === 'tampered-ticket') { $ticket .= '0'; }
    if ($scenario === 'tampered-file') {
        foreach (glob($root . '/*.zip') as $file) { file_put_contents($file, 'PK-tampered-but-not-same'); }
    }
    if ($scenario === 'symlink-file') {
        foreach (glob($root . '/*.zip') as $file) {
            $copy = $file . '.real'; rename($file, $copy); symlink($copy, $file);
        }
    }
    $checked = Stage::inspect($ticket, $root, $destination);
    $report['reinspect'] = !($checked instanceof WP_Error);
    $report['discarded'] = Stage::discard($ticket, $root, $destination);
    $report['files_remaining'] = count(glob($root . '/*.zip'));
    echo json_encode($report);
}
'''


class ResourcePackageStageTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = Path(self.tmp.name)
        self.private = self.home / 'private'
        self.private.mkdir(mode=0o700)
        self.source = self.home / 'uploaded.zip'
        self.source.write_bytes(b'PK' + b'fixture' * 50)
        self.driver = self.home / 'driver.php'
        self.driver.write_text(DRIVER)

    def run_stage(self, scenario='valid'):
        p = subprocess.run(['php', str(self.driver), str(ROOT), str(self.source),
                            str(self.private), scenario], capture_output=True, text=True)
        self.assertEqual(0, p.returncode, p.stderr)
        return json.loads(p.stdout)

    def test_valid_stage_inspect_and_discard(self):
        r = self.run_stage()
        self.assertTrue(r['inspected'], r)
        self.assertTrue(r['reinspect'], r)
        self.assertTrue(r['discarded'], r)
        self.assertEqual(0, r['files_remaining'])
        self.assertLess(r['ticket_length'], 1024)

    def test_wrong_actor_rejected(self):
        r = self.run_stage('wrong-actor')
        self.assertFalse(r['reinspect'], r)
        self.assertFalse(r['discarded'], r)

    def test_revoked_capability_rejected(self):
        r = self.run_stage('revoked')
        self.assertFalse(r['reinspect'], r)
        self.assertFalse(r['discarded'], r)

    def test_wrong_destination_rejected(self):
        r = self.run_stage('wrong-destination')
        self.assertFalse(r['reinspect'], r)
        self.assertFalse(r['discarded'], r)

    def test_tampered_ticket_rejected(self):
        r = self.run_stage('tampered-ticket')
        self.assertFalse(r['reinspect'], r)
        self.assertFalse(r['discarded'], r)

    def test_mutated_package_rejected(self):
        r = self.run_stage('tampered-file')
        self.assertFalse(r['reinspect'], r)

    def test_symlink_rejected(self):
        r = self.run_stage('symlink-file')
        self.assertFalse(r['reinspect'], r)
        self.assertFalse(r['discarded'], r)

    def test_prune_only_old_owned_stage_named_files(self):
        import os
        stale = self.private / ('a' * 48 + '.zip')
        current = self.private / ('b' * 48 + '.zip')
        unrelated = self.private / 'unrelated.txt'
        stale.write_bytes(b'old')
        current.write_bytes(b'new')
        unrelated.write_bytes(b'keep')
        os.utime(stale, (1, 1))
        os.utime(unrelated, (1, 1))
        script = self.home / 'prune.php'
        script.write_text("<?php define('ABSPATH','/nonexistent/public-wordpress/');"
                          "require_once $argv[1].'/src/DataExchange/ResourcePackageStage.php';"
                          "echo \\CoreBlueprint\\Core\\DataExchange\\ResourcePackageStage::prune_expired($argv[2]);")
        p = subprocess.run(['php', str(script), str(ROOT), str(self.private)], capture_output=True, text=True)
        self.assertEqual(0, p.returncode, p.stderr)
        self.assertEqual('1', p.stdout)
        self.assertFalse(stale.exists())
        self.assertTrue(current.exists())
        self.assertTrue(unrelated.exists())

    def test_public_directory_rejected(self):
        self.private.chmod(0o755)
        self.assertEqual('cb_core_resource_stage_unavailable', self.run_stage()['stage_error'])

    def test_invalid_package_never_persists(self):
        self.source.write_bytes(b'notzip' * 20)
        r = self.run_stage()
        self.assertEqual('invalid_package', r['stage_error'])
        self.assertEqual([], list(self.private.glob('*.zip')))


if __name__ == '__main__':
    unittest.main()
