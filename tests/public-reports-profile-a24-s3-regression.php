<?php
declare(strict_types=1);

/**
 * A2.4-S3 test-first SOURCE fixture, no Docker, live WordPress or MariaDB.
 *
 * Reuse the *unmodified* independently accepted S2 in-memory SQL harness.
 * It runs its own legacy S2 scenarios first. Never skip that existing suite.
 */
require_once __DIR__ . '/public-reports-profile-a24-regression.php';

use CoreBlueprint\Core\Profiles\ReportsProfileTransaction as Transaction;
use CoreBlueprint\Core\Profiles\Sections\ReportsSection;
use CoreBlueprint\Core\Reports\DesignDocumentAdapter as Design;
use CoreBlueprint\Core\Settings as Settings;
use CoreBlueprint\Core\Settings\OptionCas;
use CoreBlueprint\Core\Design\Document\Authority;

function s3_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('A2.4-S3 regression FAILED: ' . $message);
    }
}

function s3_reset(): S2FakeDb {
    global $wpdb;
    $wpdb = new S2FakeDb();
    Authority::$state = 'active';
    $GLOBALS['s2_filter_rewrite'] = false;
    Settings::$values = [
        'reports' => [
            'retention_days' => 365,
            'email_alerts' => ['generation_failed' => false, 'other' => true],
            'branding' => ['legacy_logo_id' => 420],
        ],
        'unrepresented_root' => ['keep' => true],
    ];
    OptionCas::$row = [
        'status' => 'ok',
        'value' => Settings::$values,
        'raw' => serialize(Settings::$values),
        'autoload' => 'off',
        'table' => 'wp_options',
    ];
    Design::$record = [
        'revision' => '9007199254740993',
        'branding' => [
            'logo_attachment_id' => 0, 'show_logo' => false,
            'provider_name' => 'old', 'provider_contact' => 'private@example.test',
            'accent_color' => '#0064c8', 'surface_style' => 'cards',
            'density' => 'comfortable', 'corner_style' => 'soft',
            'text_scale' => 'standard',
        ],
        'composer' => ['schema_version' => 1, 'blocks' => []],
    ];
    return $wpdb;
}

function s3_expected(): array {
    $section = new ReportsSection();
    $w = $section->version_witness();
    s3_assert('active' === ($w['authority'] ?? ''), 'active witness authority');
    s3_assert(isset($w['settings_sha256'], $w['document_revision'], $w['document_sha256']),
        'active witness must include entire canonical document');
    s3_assert((bool) preg_match('/^[a-f0-9]{64}$/D', $w['document_sha256']), 'canonical digest type');
    return [
        'settings_sha256' => $w['settings_sha256'],
        'document_revision' => $w['document_revision'],
        'document_sha256' => $w['document_sha256'],
    ];
}

function s3_incoming(): array {
    $value = (new ReportsSection())->export();
    $value['retention_days'] = 90;
    $value['email_alerts']['generation_failed'] = true;
    $value['branding']['provider_name'] = 'approved-new';
    return $value;
}

s3_assert(method_exists(Transaction::class, 'apply_profile_guarded'),
    'RED contract: strict S3 guarded entry must exist');
s3_assert(method_exists(Transaction::class, 'reverse_profile_guarded'),
    'RED contract: strict S3 compensation must exist');
s3_assert(method_exists(ReportsSection::class, 'apply_profile_guarded'),
    'RED contract: Reports Engine-specific entry must exist');

$wpdb = s3_reset();
$section = new ReportsSection();
$before = $section->export();
$approved = s3_expected();
$next = s3_incoming();
s3_assert('9007199254740993' === $approved['document_revision'],
    'revision above JS safe-integer boundary preserved as decimal');
s3_assert($approved['document_sha256'] === Transaction::document_hash(Design::read()['document']),
    'one canonical digest across Engine and two-row transaction');

// A full Engine preview fingerprint must change even when the exported
// Reports subsection and monotonic revision stay identical.
$previewDoc = [
    'format' => CoreBlueprint\Core\Profiles\Document::FORMAT,
    'format_version' => CoreBlueprint\Core\Profiles\Document::FORMAT_VERSION,
    'profile' => ['name' => 'S3 full-document', 'description' => ''],
    'sections' => ['reports' => ['schema_version' => 1, 'data' => $before]],
];
$previewBefore = CoreBlueprint\Core\Profiles\Engine::preview($previewDoc);
Design::$record['branding']['density'] = 'compact';
$previewAfter = CoreBlueprint\Core\Profiles\Engine::preview($previewDoc);
s3_assert($previewBefore['fingerprint'] !== $previewAfter['fingerprint'],
    'unrepresented document drift must invalidate Engine preview consent');
s3_assert($previewBefore['witnesses']['reports']['document_revision'] ===
    $previewAfter['witnesses']['reports']['document_revision'],
    'same revision alone never establishes full-content equality');
Design::$record['branding']['density'] = 'comfortable';

// R01, R02: same revision, unrepresented change before stage: first read must reject.
Design::$record['branding']['density'] = 'compact';
s3_assert($approved['document_revision'] === Design::$record['revision'],
    'independent same-revision writer retained revision');
s3_assert($approved['document_sha256'] !== s3_expected()['document_sha256'],
    'unrepresented content changes preview witness');
$baselineOptionWrites = $wpdb->option_writes;
$baselineDocWrites = $wpdb->document_writes;
$stale = Transaction::apply_profile_guarded($next, $approved);
s3_assert('conflict' === ($stale['status'] ?? ''), 'prestage same-revision drift blocked');
s3_assert($baselineOptionWrites === $wpdb->option_writes && $baselineDocWrites === $wpdb->document_writes,
    'prestage rejected before either UPDATE');
s3_assert('compact' === Design::$record['branding']['density'],
    'outside writer is preserved');

// R03: document changes between helper read and row lock.
$wpdb = s3_reset();
$approved = s3_expected();
$wpdb->before_lock = static function(): void {
    Design::$record['branding']['density'] = 'compact';
};
$staleLocked = Transaction::apply_profile_guarded($next, $approved);
s3_assert('conflict' === ($staleLocked['status'] ?? ''), 'locked same-revision drift refused');
s3_assert(0 === $wpdb->option_writes && 0 === $wpdb->document_writes,
    'locked drift cannot mutate any row');
s3_assert('compact' === Design::$record['branding']['density'], 'locked race preserves outsider');

// R05: malformed/missing digest fails closed, never falls back to two-field S2.
$wpdb = s3_reset();
$approved = s3_expected();
foreach ([
    array_diff_key($approved, ['document_sha256' => true]),
    array_replace($approved, ['document_sha256' => 'bad']),
    array_replace($approved, ['document_sha256' => strtoupper($approved['document_sha256'])]),
    array_replace($approved, ['document_sha256' => str_repeat('0', 64)]),
] as $wrong) {
    $result = Transaction::apply_profile_guarded($next, $wrong);
    s3_assert(in_array($result['status'] ?? '', ['invalid', 'conflict'], true),
        'malformed/forged S3 witness must not authorize');
}
s3_assert(0 === $wpdb->option_writes && 0 === $wpdb->document_writes,
    'bad trusted witness cannot write');

// R07, R08: direct exact transaction receipt, safe reverse with untouched postimage.
$wpdb = s3_reset();
$before = (new ReportsSection())->export();
$approved = s3_expected();
$saved = Transaction::apply_profile_guarded($next, $approved);
s3_assert('saved' === ($saved['status'] ?? '') && isset($saved['witness']['document_sha256']),
    'definite committed S3 change returns exact full postreceipt');
s3_assert($saved['witness']['document_sha256'] === Transaction::document_hash(Design::read()['document']),
    'committed postreceipt from exact staged document');
s3_assert(1 === $wpdb->option_writes && 1 === $wpdb->document_writes,
    'exact two-row write');
$reversed = Transaction::reverse_profile_guarded($before, $saved['witness']);
s3_assert(in_array($reversed['status'] ?? '', ['saved', 'unchanged'], true),
    'definite S3 receipt permits conditional rollback of unchanged postimage');
s3_assert((new ReportsSection())->verify($before), 'conditional compensation restored represented fields');

// S3-11/S3-12: document-only update cannot rewrite unrelated Settings,
// and sparse valid Settings must only gain the two represented paths.
$wpdb = s3_reset();
$approved = s3_expected();
$docOnly = (new ReportsSection())->export();
$docOnly['branding']['provider_name'] = 'doc-only';
$docOnlyResult = Transaction::apply_profile_guarded($docOnly, $approved);
s3_assert('saved' === ($docOnlyResult['status'] ?? ''), 'document-only canonical update saved');
s3_assert(0 === $wpdb->option_writes && 1 === $wpdb->document_writes,
    'document-only S3 does not write Settings or call Settings update hooks');
s3_assert('comfortable' === Design::$record['branding']['density'],
    'document-only S3 keeps unrepresented branding');

$wpdb = s3_reset();
$minimalSettings = ['site_mode' => 'production', 'opaque' => ['keep' => true]];
Settings::accept_cas($minimalSettings);
OptionCas::$row['value'] = $minimalSettings;
OptionCas::$row['raw'] = serialize($minimalSettings);
$minimalWitness = s3_expected();
$sparseDesired = (new ReportsSection())->export();
$sparseDesired['retention_days'] = 180;
$sparseDesired['email_alerts']['generation_failed'] = true;
$sparseSaved = Transaction::apply_profile_guarded($sparseDesired, $minimalWitness);
s3_assert('saved' === ($sparseSaved['status'] ?? ''), 'sparse settings S3 commit succeeds');
s3_assert(1 === $wpdb->option_writes && 0 === $wpdb->document_writes,
    'sparse settings-only S3 update does not increment document revision');
s3_assert(true === Settings::$values['opaque']['keep'], 'unrelated sparse root preserved');
s3_assert(!isset(Settings::$values['reports']['branding']),
    'sparse settings never materialize unrelated Reports branding defaults');
s3_assert(['generation_failed' => true] === Settings::$values['reports']['email_alerts'],
    'sparse settings materialize only represented alert leaf');

// Section-level S3 integration: the Engine-owned four-field witness reaches
// S2, and compensation verifies the NEW monotone revision after restoration.
$wpdb = s3_reset();
$section = new ReportsSection();
$before = $section->export();
$engineWitness = $section->version_witness();
$sectionReceipt = $section->apply_profile_guarded($next, 'source-only', $engineWitness);
s3_assert('saved' === ($sectionReceipt['status'] ?? ''), 'strict section adapter saves with full witness');
$section->restore_guarded($before, $next, 'profile-rollback:source-only',
    $engineWitness, $sectionReceipt['witness']);
s3_assert($section->verify($before), 'section-level guarded compensation restores fields');
s3_assert($section->version_witness()['document_revision'] !== $engineWitness['document_revision'],
    'compensation never invents rollback of monotone document revision');

// State/authority failure never permits legacy fallback.
Authority::$state = 'recovery_required';
$blockedAuthority = false;
try { $section->assert_apply_allowed(); } catch (RuntimeException $e) { $blockedAuthority = true; }
s3_assert($blockedAuthority, 'unknown or recovery authority blocked');
Authority::$state = 'active';

// R09: writer changes only unrepresented document field AFTER commit, before rollback.
$wpdb = s3_reset();
$approved = s3_expected();
$saved = Transaction::apply_profile_guarded($next, $approved);
s3_assert('saved' === ($saved['status'] ?? ''), 'fixture postcommit setup');
Design::$record['branding']['density'] = 'compact';
$writesBefore = [$wpdb->option_writes, $wpdb->document_writes];
$reversed = Transaction::reverse_profile_guarded($before, $saved['witness']);
s3_assert('conflict' === ($reversed['status'] ?? ''), 'postcommit outsider makes original receipt stale');
s3_assert($writesBefore === [$wpdb->option_writes, $wpdb->document_writes],
    'stale compensation cannot clobber outsider');
s3_assert('compact' === Design::$record['branding']['density'], 'outside content preserved');

// R07-R09: the SECTION adapter must never exchange the receipt obtained
// synchronously from the transaction for a later post-commit live read.
$wpdb = s3_reset();
$section = new ReportsSection();
$original = $section->export();
$approvedFull = $section->version_witness();
$sectionSaved = $section->apply_profile_guarded($next, 'source-only', $approvedFull);
s3_assert('saved' === ($sectionSaved['status'] ?? ''), 'receipt race section setup saved');
$originalReceipt = $sectionSaved['witness'];
Design::$record['branding']['density'] = 'compact'; // no revision change
$writeCounts = [$wpdb->option_writes, $wpdb->document_writes];
$restoreBlocked = false;
try {
    $section->restore_guarded($original, $next, 'profile-rollback:source-only',
        $approvedFull, $originalReceipt);
} catch (RuntimeException $exception) {
    $restoreBlocked = true;
}
s3_assert($restoreBlocked, 'section refuses reverse using stale actual commit receipt');
s3_assert($writeCounts === [$wpdb->option_writes, $wpdb->document_writes],
    'section rollback never overwrites postcommit outside writer');
s3_assert('compact' === Design::$record['branding']['density'],
    'section rollback preserves non-exported postcommit change');

// R10: outsider Settings raw edit after commit similarly denies compensation.
$wpdb = s3_reset();
$approved = s3_expected();
$saved = Transaction::apply_profile_guarded($next, $approved);
OptionCas::$row['value']['unrepresented_root']['keep'] = false;
OptionCas::$row['raw'] = serialize(OptionCas::$row['value']);
$reversed = Transaction::reverse_profile_guarded($before, $saved['witness']);
s3_assert('conflict' === ($reversed['status'] ?? ''), 'postcommit settings writer preserved');

// R11,R12: fully witnessed unchanged is an actual no-write; check the lock race.
$wpdb = s3_reset();
$before = (new ReportsSection())->export();
$approved = s3_expected();
$noChange = Transaction::apply_profile_guarded($before, $approved);
s3_assert('unchanged' === ($noChange['status'] ?? '') &&
    $approved === ($noChange['witness'] ?? null), 'unchanged returns checked original exact pair');
s3_assert(0 === $wpdb->option_writes && 0 === $wpdb->document_writes, 'no-op wrote no rows');
$wpdb->before_lock = static function(): void {
    Design::$record['branding']['density'] = 'compact';
};
$noOpRace = Transaction::apply_profile_guarded($before, $approved);
s3_assert('conflict' === ($noOpRace['status'] ?? ''), 'no-op race must validate under lock');

// R13: failed/uncertain COMMIT has NO invented post-commit receipt.
$wpdb = s3_reset();
$approved = s3_expected();
$wpdb->fail_commit = true;
$uncertain = Transaction::apply_profile_guarded($next, $approved);
s3_assert('recovery_required' === ($uncertain['status'] ?? '') &&
    !isset($uncertain['witness']), 'uncertain commit cannot return a compensable witness');
$wpdb->fail_commit = false;

// R17: generic Reports apply remains blocked in active mode.
$wpdb = s3_reset();
$directFailed = false;
try {
    (new ReportsSection())->apply($next, 'direct-unauthorized');
} catch (Throwable $error) {
    $directFailed = true;
}
s3_assert($directFailed && 0 === $wpdb->option_writes && 0 === $wpdb->document_writes,
    'direct active mutation cannot bypass Engine supplied preview');

// Source seam check: Engine must copy the actual returned transaction receipt
// before section verification, never overwrite it with a fresh witness.
$engineSource = file_get_contents(dirname(__DIR__) . '/src/Profiles/Engine.php');
s3_assert(is_string($engineSource), 'read current Engine source');
$receiptOffset = strpos($engineSource, '$post_witnesses[ $id ] = $result[\'witness\']');
$verifyOffset = strpos($engineSource, 'if ( ! $section->verify( $payload[\'data\'] ) )');
s3_assert(false !== $receiptOffset && false !== $verifyOffset && $receiptOffset < $verifyOffset,
    'Engine must store original S2 transaction receipt before verify');
s3_assert(false !== strpos($engineSource, '$unchanged_sections[ $id ] = true;'),
    'Engine requires a no-write record separate from saved status');
$postreceiptGuard = strpos($engineSource, 'postreceipt_drift');
s3_assert(false !== $postreceiptGuard && $receiptOffset < $postreceiptGuard
    && $postreceiptGuard < $verifyOffset,
    'Engine retains exact S2 receipt then verifies complete poststate before reporting success');
s3_assert(false !== strpos($engineSource, 'document_sha256'),
    'Engine validates complete document payload hash, not represented fields only');
s3_assert(false !== strpos($engineSource, '$uncertain_sections[ $id ] = true;')
    && false !== strpos($engineSource, 'uncertain_transaction'),
    'Engine cannot treat uncertain COMMIT/posthook as a verified no-op rollback');

echo "Core Blueprint Base A2.4-S3 canonical Reports Profile fixture PASS\n";


/**
 * P0-S3-S01/S02 real Engine::apply() contract. Run a fresh PHP process with
 * the ORIGINAL S2 harness declarations as input, but a separately instrumented
 * test lease/registry. The accepted S2 fixture file is never edited, skipped,
 * or asked to abandon its intentionally blocking legacy ApplyLock.
 */
function s3_engine_integration_child(): void {
    $source = file_get_contents(__DIR__ . '/public-reports-profile-a24-regression.php');
    s3_assert(is_string($source), 'unchanged S2 harness is readable');
    $boundary = "    require_once dirname( __DIR__ ) . '/src/Profiles/VersionGuardedSectionInterface.php';";
    $pos = strpos($source, $boundary);
    s3_assert(false !== $pos, 'S2 declarations boundary is stable');
    $harness = substr($source, 0, $pos);

    $oldLease = <<<'OLD'
    final class ApplyLock {
        public static int $acquisitions = 0;
        public static function acquire( string $actor ): string {
            self::$acquisitions++;
            throw new \RuntimeException( 'Active Profile test must never acquire a lock' );
        }
    }
OLD;
    $newLease = <<<'NEW'
    final class ApplyLock {
        public static int $acquisitions = 0;
        public static int $releases = 0;
        public static function acquire( string $actor ): string {
            ++self::$acquisitions;
            return 'engine-test-lease';
        }
        public static function refresh( string $token ): bool {
            return 'engine-test-lease' === $token;
        }
        public static function release( string $token ): void {
            if ( 'engine-test-lease' !== $token ) {
                throw new \RuntimeException( 'unexpected lease release' );
            }
            ++self::$releases;
        }
    }
NEW;
    s3_assert(1 === substr_count($harness, $oldLease), 'replace isolated lease once');
    $harness = str_replace($oldLease, $newLease, $harness);

    // Separate Engine child ONLY: inject a same-revision document writer
    // after ReportsSection's successful first version_witness(), exactly
    // as the strict reverse transaction reads its first preimage.
    $oldCas = <<<'OLD_CAS'
        public static function read(): array { return self::$row; }
OLD_CAS;
    $newCas = <<<'NEW_CAS'
        public static bool $s3_reverse_window_armed = false;
        public static int $s3_reverse_window_reads = 0;
        public static function read(): array {
            if (self::$s3_reverse_window_armed) {
                ++self::$s3_reverse_window_reads;
                if (2 === self::$s3_reverse_window_reads) {
                    self::$s3_reverse_window_armed = false;
                    \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['density'] = 'compact';
                    $GLOBALS['s3_reverse_window_fired'] = true;
                }
            }
            return self::$row;
        }
NEW_CAS;
    s3_assert(1 === substr_count($harness, $oldCas),
        'exactly one isolated S2 OptionCas read seam');
    $harness = str_replace($oldCas, $newCas, $harness);

    $fakeSections = <<<'SECTIONS'
    final class S3SecuritySection extends ExactSection {
        public function id(): string { return 'security'; }
        public function label(): string { return 'Security'; }
        public function description(): string { return 'Shared Settings writer'; }
        public function schema_version(): int { return 1; }
        public function export(): array {
            return ['enabled' => (bool) (\CoreBlueprint\Core\Settings::$values['security']['enabled'] ?? false)];
        }
        public function normalize( array $incoming ): array {
            if (array_keys($incoming) !== ['enabled'] || !is_bool($incoming['enabled'])) {
                throw new \InvalidArgumentException('bad security fixture');
            }
            return $incoming;
        }
        public function preview(array $current, array $incoming): array {
            return $current === $incoming ? [] : ['enabled' => true];
        }
        public function apply(array $incoming, string $actor): void {
            $stored = \CoreBlueprint\Core\Settings\OptionCas::$row['value'];
            $stored['security']['enabled'] = $incoming['enabled'];
            \CoreBlueprint\Core\Settings\OptionCas::$row['value'] = $stored;
            \CoreBlueprint\Core\Settings\OptionCas::$row['raw'] = serialize($stored);
            \CoreBlueprint\Core\Settings::accept_cas($stored);
            $GLOBALS['s3_engine_events'][] = 'security:' . (int) $incoming['enabled'];
        }
        public function restore(array $snapshot, array $incoming, string $actor): void {
            $this->apply($snapshot, $actor);
        }
    }
    final class S3LaterSection extends ExactSection {
        public function id(): string { return 'later'; }
        public function label(): string { return 'Later'; }
        public function description(): string { return 'Independent later failing section'; }
        public function schema_version(): int { return 1; }
        public function export(): array { return ['intent' => 'idle']; }
        public function normalize(array $incoming): array {
            if (!isset($incoming['intent']) || !in_array($incoming['intent'], ['idle','fail','external-doc','external-settings','reverse-window'], true)) {
                throw new \InvalidArgumentException('bad later fixture');
            }
            return ['intent' => $incoming['intent']];
        }
        public function preview(array $current, array $incoming): array {
            return $current === $incoming ? [] : ['intent' => true];
        }
        public function apply(array $incoming, string $actor): void {
            $mode = $incoming['intent'];
            if ('external-doc' === $mode) {
                \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['density'] = 'compact';
            }
            if ('external-settings' === $mode) {
                $row = &\CoreBlueprint\Core\Settings\OptionCas::$row;
                $row['value']['unrepresented_root']['keep'] = false;
                $row['raw'] = serialize($row['value']);
                \CoreBlueprint\Core\Settings::accept_cas($row['value']);
            }
            if ('reverse-window' === $mode) {
                \CoreBlueprint\Core\Settings\OptionCas::$s3_reverse_window_armed = true;
                \CoreBlueprint\Core\Settings\OptionCas::$s3_reverse_window_reads = 0;
            }
            if ('idle' !== $mode) {
                throw new \RuntimeException('deliberate later section failure');
            }
        }
        public function restore(array $snapshot, array $incoming, string $actor): void {
            // Deliberate failure occurs strictly BEFORE any of this section's writes.
        }
    }
SECTIONS;
    s3_assert(1 === substr_count($harness, '    final class SectionRegistry {'),
        'isolated fixture registry declaration found once');
    // PHP resolves parent classes as it evaluates each declaration.
    // Keep the original S2 SectionRegistry where it is, and define the
    // S3 Engine doubles only AFTER the unchanged ExactSection declaration.
    $endProfiles = "}\nnamespace {\n    /**\n     * Fake transactional SQL store";
    s3_assert(1 === substr_count($harness, $endProfiles),
        'single post-ExactSection insertion boundary in S2 harness');
    $harness = str_replace($endProfiles, $fakeSections . "\n" . $endProfiles, $harness);
    $oldRegistry = "return [ 'reports' => new \\CoreBlueprint\\Core\\Profiles\\Sections\\ReportsSection() ];";
    $newRegistry = "return [ 'security' => new S3SecuritySection(), 'reports' => new \\CoreBlueprint\\Core\\Profiles\\Sections\\ReportsSection(), 'later' => new S3LaterSection() ];";
    s3_assert(1 === substr_count($harness, $oldRegistry), 'original S2 registry replacement only in isolated child');
    $harness = str_replace($oldRegistry, $newRegistry, $harness);

    $oldPreview = 'public function preview( array $c, array $i ): array { return []; }';
    $newPreview = 'public function preview( array $c, array $i ): array { return $c === $i ? [] : [ \'changed\' => true ]; }';
    s3_assert(1 === substr_count($harness, $oldPreview), 'source-exact fallback Diff for isolated Reports');
    $harness = str_replace($oldPreview, $newPreview, $harness);

    $audit = <<<'AUDIT'
namespace CoreBlueprint\Core\Log {
    final class AuditLog {
        public static array $events = [];
        public static function log(string $event, string $level, array $context): void {
            self::$events[] = [$event, $level, $context];
        }
    }
}
AUDIT;
    $nsMarker = "namespace {\n    /**\n     * Fake transactional SQL store";
    s3_assert(1 === substr_count($harness, $nsMarker), 'insert audit sink into isolated process');
    $harness = str_replace($nsMarker, $audit . "\n" . $nsMarker, $harness);

    // This specific hook runs after the transaction's readback, before
    // the transaction returns its receipt. It lets Engine catch a real
    // *post-receipt* outsider change without borrowing a fresh witness.
    $oldAction = 'function do_action( string $hook, mixed ...$args ): void { $GLOBALS[\'s2_actions\'][] = $hook; }';
    $newAction = <<<'ACTION'
function do_action( string $hook, mixed ...$args ): void {
        $GLOBALS['s2_actions'][] = $hook;
        if ('updated_option' === $hook && is_callable($GLOBALS['s3_commit_observer'] ?? null)) {
            $observer = $GLOBALS['s3_commit_observer'];
            $GLOBALS['s3_commit_observer'] = null;
            $observer();
        }
    }
ACTION;
    s3_assert(1 === substr_count($harness, $oldAction), 'inject observable postcommit hook once');
    $harness = str_replace($oldAction, $newAction, $harness);

    $root = dirname(__DIR__);
    $preamble = implode("\n", [
        "    require_once " . var_export($root . '/src/Profiles/VersionGuardedSectionInterface.php', true) . ";",
        "    require_once " . var_export($root . '/src/Profiles/Sections/ReportsSection.php', true) . ";",
        "    require_once " . var_export($root . '/src/Profiles/ReportsProfileTransaction.php', true) . ";",
        "    require_once " . var_export($root . '/src/Profiles/Engine.php', true) . ";",
    ]);
    $body = <<<'ENGINE'
    function s3e_assert(bool $ok, string $label): void {
        if (!$ok) { throw new \RuntimeException('S3 REAL ENGINE RED: ' . $label); }
    }
    function s3e_reset(): S2FakeDb {
        $db = new S2FakeDb();
        $GLOBALS['wpdb'] = $db;
        $GLOBALS['s2_filter_rewrite'] = false;
        $GLOBALS['s2_actions'] = [];
        $GLOBALS['s3_commit_observer'] = null;
        $GLOBALS['s3_reverse_window_fired'] = false;
        \CoreBlueprint\Core\Settings\OptionCas::$s3_reverse_window_armed = false;
        \CoreBlueprint\Core\Settings\OptionCas::$s3_reverse_window_reads = 0;
        $GLOBALS['s3_engine_events'] = [];
        \CoreBlueprint\Core\Log\AuditLog::$events = [];
        \CoreBlueprint\Core\Profiles\ApplyLock::$acquisitions = 0;
        \CoreBlueprint\Core\Profiles\ApplyLock::$releases = 0;
        \CoreBlueprint\Core\Design\Document\Authority::$state = 'active';
        $value = [
            'security' => ['enabled' => false],
            'reports' => [
                'retention_days' => 365,
                'email_alerts' => ['generation_failed' => false, 'other' => true],
                'branding' => ['legacy_logo_id' => 420],
            ],
            'unrepresented_root' => ['keep' => true],
        ];
        \CoreBlueprint\Core\Settings::accept_cas($value);
        \CoreBlueprint\Core\Settings\OptionCas::$row = [
            'status' => 'ok', 'raw' => serialize($value), 'value' => $value,
            'autoload' => 'on', 'table' => 'wp_options',
        ];
        \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record = [
            'revision' => '9007199254740993',
            'branding' => [
                'logo_attachment_id' => 0, 'show_logo' => false, 'provider_name' => 'old',
                'provider_contact' => 'private@example.test', 'accent_color' => '#0064c8',
                'surface_style' => 'cards', 'density' => 'comfortable',
                'corner_style' => 'soft', 'text_scale' => 'standard',
            ],
            'composer' => ['schema_version' => 1, 'blocks' => []],
        ];
        return $db;
    }
    function s3e_doc(bool $reportsChange, bool $securityChange, string $later = ''): array {
        $reports = new \CoreBlueprint\Core\Profiles\Sections\ReportsSection();
        $data = $reports->export();
        if ($reportsChange) {
            $data['retention_days'] = 91;
            $data['email_alerts']['generation_failed'] = true;
            $data['branding']['provider_name'] = 'approved';
        }
        $sections = ['reports' => ['schema_version' => 1, 'data' => $data]];
        if ($securityChange) {
            $sections = ['security' => ['schema_version' => 1, 'data' => ['enabled' => true]]] + $sections;
        }
        if ('' !== $later) {
            $sections['later'] = ['schema_version' => 1, 'data' => ['intent' => $later]];
        }
        return [
            'format' => \CoreBlueprint\Core\Profiles\Document::FORMAT,
            'format_version' => \CoreBlueprint\Core\Profiles\Document::FORMAT_VERSION,
            'profile' => ['name' => 'S3 integrated Engine', 'description' => ''],
            'sections' => $sections,
        ];
    }
    function s3e_run(array $profile): array {
        $pre = \CoreBlueprint\Core\Profiles\Engine::preview($profile);
        return \CoreBlueprint\Core\Profiles\Engine::apply($profile, $pre['fingerprint'], 'authorized-test-operator');
    }
    function s3e_audit(string $event): bool {
        foreach (\CoreBlueprint\Core\Log\AuditLog::$events as $item) {
            if ($item[0] === $event) { return true; }
        }
        return false;
    }
    function s3e_lease(): void {
        s3e_assert(1 === \CoreBlueprint\Core\Profiles\ApplyLock::$acquisitions, 'one acquired lease');
        s3e_assert(1 === \CoreBlueprint\Core\Profiles\ApplyLock::$releases, 'one released lease');
    }
    // Positive, actual Reports-only Active Engine path.
    $db = s3e_reset();
    $res = s3e_run(s3e_doc(true, false));
    s3e_assert('complete' === ($res['status'] ?? null), 'Engine Reports-only committed');
    s3e_assert(1 === $db->option_writes && 1 === $db->document_writes, 'two canonical rows only');
    s3e_assert(s3e_audit('profiles.applied'), 'single Engine success audit');
    s3e_lease();

    // Mixed Security writes the SAME entire Settings option. Reports must use
    // the original operator preview rather than opportunistically rebasing.
    $db = s3e_reset();
    $res = s3e_run(s3e_doc(true, true));
    s3e_assert('complete' === ($res['status'] ?? null), 'Security+Reports complete with approved witness');
    s3e_assert(true === \CoreBlueprint\Core\Settings::$values['security']['enabled'], 'Security own Settings leaf committed');
    s3e_assert(1 === $db->option_writes && 1 === $db->document_writes, 'Reports exactly one pair');
    s3e_assert(s3e_audit('profiles.applied'), 'mixed applied only after both complete');
    s3e_lease();

    // Saved Reports followed by a failure. Exact postreceipt permits reverse
    // only after independently reversed Settings writers restore original raw.
    $db = s3e_reset();
    $before = \CoreBlueprint\Core\Settings\OptionCas::$row['raw'];
    try { s3e_run(s3e_doc(true, true, 'fail')); s3e_assert(false, 'later section must fail'); }
    catch (\RuntimeException $e) {
        s3e_assert(str_contains($e->getMessage(), 'deliberate later section failure'), 'bounded original failure');
    }
    s3e_assert(!s3e_audit('profiles.applied') && s3e_audit('profiles.apply_failed'), 'verified safe rollback audit');
    s3e_assert($before === \CoreBlueprint\Core\Settings\OptionCas::$row['raw'], 'full Settings restored');
    s3e_assert('old' === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['provider_name'], 'canonical provider reversed');
    s3e_assert('comfortable' === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['density'], 'unrepresented density retained');
    s3e_assert(2 === $db->document_writes && 2 === $db->option_writes, 'definite save and guarded reverse');
    s3e_lease();

    // Later non-exported independent writer cannot be clobbered by compensation.
    $db = s3e_reset();
    try { s3e_run(s3e_doc(true, true, 'external-doc')); s3e_assert(false, 'later writer must fail'); }
    catch (\RuntimeException $e) {
        s3e_assert(str_contains($e->getMessage(), 'could not be restored'), 'manual recovery after postcommit race');
    }
    s3e_assert(s3e_audit('profiles.rollback_failed') && !s3e_audit('profiles.applied'), 'postcommit outsider recovery audit');
    s3e_assert('compact' === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['density'], 'never clobber density');
    s3e_lease();

    // Same protection for unrelated Settings raw writer.
    $db = s3e_reset();
    try { s3e_run(s3e_doc(true, true, 'external-settings')); s3e_assert(false, 'outside settings writer must fail'); }
    catch (\RuntimeException $e) {
        s3e_assert(str_contains($e->getMessage(), 'could not be restored'), 'manual recovery for Settings outsider');
    }
    s3e_assert(false === \CoreBlueprint\Core\Settings\OptionCas::$row['value']['unrepresented_root']['keep'], 'outside Settings preserved');
    s3e_assert(s3e_audit('profiles.rollback_failed'), 'raw Settings conflict audit');
    s3e_lease();

    // P0-S3-R01: The first ReportsSection witness MUST succeed before the
    // outsider's edit. Inject after that check, at reverse transaction's
    // first OptionCas::read(), leaving document revision unchanged.
    // A legacy/non-strict reverse incorrectly commits a SECOND two-row
    // write; the S3 strict postreceipt reverse must refuse before SQL.
    $db = s3e_reset();
    $revision = \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['revision'];
    $beforeSettings = \CoreBlueprint\Core\Settings\OptionCas::$row['raw'];
    try {
        s3e_run(s3e_doc(true, true, 'reverse-window'));
        s3e_assert(false, 'reverse-window outsider requires operator recovery');
    } catch (\RuntimeException $e) {
        s3e_assert(str_contains($e->getMessage(), 'could not be restored'),
            'strict reverse-window conflict triggers manual recovery');
    }
    s3e_assert(true === ($GLOBALS['s3_reverse_window_fired'] ?? false),
        'witness completed before injected transaction-time outsider');
    s3e_assert(2 === \CoreBlueprint\Core\Settings\OptionCas::$s3_reverse_window_reads,
        'observer fired on second CAS read after section witness');
    s3e_assert($revision !== \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['revision'],
        'initial Reports commit occurred before injected outsider');
    s3e_assert(1 === $db->option_writes && 1 === $db->document_writes,
        'strict reverse refuses BEFORE any compensation SQL writes');
    s3e_assert($beforeSettings !== \CoreBlueprint\Core\Settings\OptionCas::$row['raw'],
        'Reports committed option remains awaiting explicit recovery');
    s3e_assert('approved' === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['provider_name'],
        'Reports postcommit represented fields not silently reversed');
    s3e_assert('compact' === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['density'],
        'independent same-revision density edit preserved');
    s3e_assert(s3e_audit('profiles.rollback_failed') && !s3e_audit('profiles.apply_failed')
        && !s3e_audit('profiles.applied'), 'recovery audit never claims safe rollback or apply');
    s3e_lease();

    // Active Reports no-op within a mixed Profile is witnessed but not reversed.
    $db = s3e_reset();
    try { s3e_run(s3e_doc(false, true, 'fail')); s3e_assert(false, 'later no-op test must fail'); }
    catch (\RuntimeException $e) {
        s3e_assert(str_contains($e->getMessage(), 'deliberate later section failure'), 'no-op later failure reported');
    }
    s3e_assert(0 === $db->option_writes && 0 === $db->document_writes, 'no-op Reports never SQL updates');
    s3e_assert(s3e_audit('profiles.apply_failed') && !s3e_audit('profiles.rollback_failed'), 'no-op rollback no phantom recovery');
    s3e_lease();

    // Reports-only no-op short-circuits before lease without false success.
    $db = s3e_reset();
    try { s3e_run(s3e_doc(false, false)); s3e_assert(false, 'Reports no-op must be refused'); }
    catch (\RuntimeException $e) {
        s3e_assert(0 === \CoreBlueprint\Core\Profiles\ApplyLock::$acquisitions, 'no-op no mutation lease');
    }
    s3e_assert(!s3e_audit('profiles.applied'), 'Reports no-op not success');

    // COMMIT uncertainty cannot become a fresh-witness rollback or success.
    $db = s3e_reset();
    $db->fail_commit = true;
    try { s3e_run(s3e_doc(true, true)); s3e_assert(false, 'uncertain COMMIT must fail'); }
    catch (\RuntimeException $e) {
        s3e_assert(str_contains($e->getMessage(), 'could not be restored'), 'recovery barrier survives uncertain commit');
    }
    s3e_assert(s3e_audit('profiles.rollback_failed') && !s3e_audit('profiles.applied'), 'uncertain COMMIT recovery audit');
    s3e_assert(false === \CoreBlueprint\Core\Settings::$values['security']['enabled'], 'no later Security section applied');
    s3e_lease();

    // A confirmed COMMIT whose posthook injects an outsider cannot silently
    // succeed, even when the represented Reports fields still verify.
    $db = s3e_reset();
    $GLOBALS['s3_commit_observer'] = static function(): void {
        \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['density'] = 'compact';
    };
    try { s3e_run(s3e_doc(true, false)); s3e_assert(false, 'posthook outsider must fail'); }
    catch (\RuntimeException $e) {
        s3e_assert(str_contains($e->getMessage(), 'could not be restored'), 'postreceipt rollback conflict detected');
    }
    s3e_assert('compact' === \CoreBlueprint\Core\Reports\DesignDocumentAdapter::$record['branding']['density'], 'posthook outsider remains');
    s3e_assert(s3e_audit('profiles.rollback_failed') && !s3e_audit('profiles.applied'), 'posthook source recovery audit');
    s3e_lease();

    echo "Core Blueprint Base A2.4-S3 REAL Engine apply/rollback fixture PASS\n";
ENGINE;
    $child = tempnam(sys_get_temp_dir(), 'cb-s3-engine-');
    s3_assert(false !== $child, 'source fixture temp file reserved');
    try {
        s3_assert(false !== file_put_contents($child, $harness . "\n" . $preamble . "\n" . $body . "\n}\n"),
            'isolated PHP child source created');
        $process = proc_open([PHP_BINARY, $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        s3_assert(is_resource($process), 'real PHP subprocess available, not silently skipped');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        s3_assert(0 === $code, "Real Engine executable failed:\n" . $stdout . "\n" . $stderr);
        echo $stdout;
    } finally {
        @unlink($child);
    }
}
s3_engine_integration_child();
