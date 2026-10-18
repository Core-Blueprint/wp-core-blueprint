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

echo "Core Blueprint Base A2.4-S3 canonical Reports Profile fixture PASS\n";
