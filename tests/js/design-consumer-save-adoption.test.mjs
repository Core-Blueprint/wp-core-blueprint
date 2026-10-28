/**
 * Source-only A2.3a consumer/history/authority contract.
 * Production browser and MariaDB acceptance remains separate Level 2.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const src = (relative) => readFileSync(new URL('../../' + relative, import.meta.url), 'utf8');
const mail = src('assets/js/features/mail-designer.js');
const mailTransport = src('assets/js/features/mail-designer-save.js');
const reports = src('assets/js/features/reports-preferences.js');
const native = src('src/Mail/Designer/WordPressIntegration.php');
const authority = src('src/Design/Document/Authority.php');
const profiles = src('src/Profiles/Sections/ReportsSection.php');

test('Mail captures immutable Base project + subject + server revision and rebases only after matching receipt', () => {
  for (const marker of [
    'session.captureSave()', 'session.rebase({',
    'saveToken: captured.checkpoint.token', 'captured.checkpoint.submittedProject',
    'acknowledged.subject !== captured.subject',
    'captured.templateId !== templateId', 'captured.epoch !== contextEpoch',
    'data?.revision', 'persistedFingerprint = fingerprintOf',
    "saveUncertain = true",
  ]) assert.ok(mail.includes(marker), marker);
  for (const marker of ['cb:mail:savecapture', 'cb:mail:saveack', 'cb:mail:savefailed',
    "body.set('expected_revision', captured.revision)", 'controller.signal']) {
    assert.ok(mailTransport.includes(marker), marker);
  }
});

test('Reports conserves Composer history and external branding baseline; no replace after saving', () => {
  for (const marker of [
    'session.captureSave()', 'session.rebase(', 'saveToken: submitted.checkpoint.token',
    'brandSignature( doc.branding )', 'brandingBaseline',
    'expected_revision: submitted.revision', "saveUncertain = true",
    'session.baselineSnapshot()', 'syncDirtyIndicator()', 'cb-core-reports-designer__dirty',
  ]) assert.ok(reports.includes(marker), marker);
  const mutations = reports.slice(reports.indexOf('const saveReceipt ='));
  assert.doesNotMatch(mutations, /session\.replace\s*\(/);
});

test('Original WordPress security notifications are preserved with bounded operator audit', () => {
  for (const name of [
    'retrieve_password_notification_email', 'wp_new_user_notification_email',
    'wp_new_user_notification_email_admin', 'password_change_email',
    'email_change_email', 'wp_password_change_notification_email',
  ]) assert.ok(native.includes(name), name);
  assert.match(native, /catch \( DocumentUnavailable \$error \) \{[\s\S]*?return \$email;/);
  assert.ok(native.includes("current_user_can( 'manage_options' )"));
  assert.ok(native.includes("get_transient( self::DIAGNOSTIC_KEY )"));
  assert.ok(!native.includes('getMessage()'));
});

test('Native authority scopes Mail history to Mail and refuses global conflicts and uncertain DB state', () => {
  const inspectStart = authority.indexOf('public static function inspect( string $resource_type = Repository::MAIL ): array');
  const reportsStart = authority.indexOf('private static function reports_design_is_default(');
  assert.ok(inspectStart >= 0 && reportsStart > inspectStart, 'native-v1 inspect method exists');
  const inspect = authority.slice(inspectStart, reportsStart);

  // All five historical sources must be inspected before treating native storage as clean.
  const namesStart = inspect.indexOf('$names = [');
  const namesEnd = inspect.indexOf('];', namesStart);
  assert.ok(namesStart >= 0 && namesEnd > namesStart, 'physical source inventory exists');
  const names = inspect.slice(namesStart, namesEnd);
  for (const marker of [
    "ACTIVE_OPTION = 'cb_core_design_docs_cutover'",
    "EVER_ACTIVE_OPTION = 'cb_core_design_docs_ever_active_v1'",
    "PREPARED_OPTION = 'cb_core_design_docs_prepared_v1'",
    "BACKUP_OPTION = 'cb_core_design_docs_migration_backup_v1'",
  ]) assert.ok(authority.includes(marker), 'Missing historical authority marker: ' + marker);
  for (const source of [
    'self::ACTIVE_OPTION', 'self::EVER_ACTIVE_OPTION',
    'self::PREPARED_OPTION', 'self::BACKUP_OPTION',
    '\\CoreBlueprint\\Core\\Mail\\Designer\\TemplateRepository::OPTION',
    'self::SETTINGS_OPTION',
  ]) assert.ok(names.includes(source), 'Missing physical option source: ' + source);

  // No cached option fallback: admission reads each site-bound option from the primary DB.
  assert.ok(inspect.includes('get_current_blog_id()'));
  assert.ok(inspect.includes('Schema::healthy()'));
  assert.match(inspect, /foreach\s*\(\s*\$names\s+as\s+\$name\s*\)/);
  assert.match(inspect, /\$wpdb->get_row\s*\(\s*\$wpdb->prepare\s*\(/);
  assert.ok(inspect.includes("'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1'"));
  assert.ok(inspect.includes('$wpdb->options'));
  assert.ok(inspect.includes('ARRAY_A'));
  assert.match(inspect, /\$wpdb->last_error[\s\S]*?return \[\s*'status'\s*=>\s*'blocked'\s*\];/);

  // Only Reports can skip the Mail-specific sentinel; other historic rows remain denied.
  assert.match(inspect, /foreach\s*\(\s*array_slice\s*\(\s*\$names\s*,\s*0\s*,\s*5\s*\)\s+as\s+\$name\s*\)/);
  assert.match(inspect, /Repository::REPORTS\s*===\s*\$resource_type[\s\S]*?TemplateRepository::OPTION\s*===\s*\$name[\s\S]*?continue;/);
  assert.match(inspect, /if\s*\(\s*null !== \$rows\[\$name\]\s*\)\s*\{\s*return \[\s*'status' => 'blocked'\s*\];/);
  assert.match(inspect, /null !== \$rows\[self::SETTINGS_OPTION\][\s\S]*?reports_design_is_default\(\s*\$rows\[self::SETTINGS_OPTION\]\s*\)[\s\S]*?return \[\s*'status' => 'blocked'\s*\];/);
  assert.ok(inspect.includes("return [ 'status' => 'active' ];"));

  const codeOnly = authority.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
  for (const pattern of [
    /\bget_option\s*\(/, /\bupdate_option\s*\(/, /\badd_option\s*\(/,
    /\bdelete_option\s*\(/, /\bMigration(?:Inventory|Backup)?::/,
  ]) assert.doesNotMatch(codeOnly, pattern);
});

test('Reports Profile v2 requires active native authority, full witness and guarded compensation', () => {
  const method = (name) => {
    const start = profiles.indexOf(`public function ${name}(`);
    assert.notEqual(start, -1, `Missing Reports Profile method: ${name}`);
    const end = profiles.indexOf('\n\t}', start);
    assert.notEqual(end, -1, `Unterminated Reports Profile method: ${name}`);
    return profiles.slice(start, end + 3);
  };

  const preflight = method('preflight');
  assert.match(preflight, /\$this->version_witness\(\)/);

  const witness = method('version_witness');
  assert.match(witness, /'active' !== \$authority/);
  assert.match(witness, /ReportsProfileTransaction::document_hash\(/);
  for (const field of ['settings_sha256', 'document_revision', 'document_sha256']) {
    assert.ok(witness.includes(`'${field}'`), `Missing native witness: ${field}`);
  }

  const admission = method('assert_apply_allowed');
  assert.match(admission, /ReportsProfileTransaction::witness\(\)/);
  for (const field of ['settings_sha256', 'document_revision', 'document_sha256']) {
    assert.ok(admission.includes(`['${field}']`), `Missing admission check: ${field}`);
  }
  assert.match(admission, /REPORTS_PROFILE_ACTIVE_AUTHORITY_REQUIRED/);
  assert.doesNotMatch(profiles, /require_legacy_profile_authority|parent::restore\(/);

  const apply = method('apply');
  assert.match(apply, /throw new \\RuntimeException\( 'REPORTS_PROFILE_GUARDED_APPLY_REQUIRED' \)/);
  const restore = method('restore');
  assert.match(restore, /throw new \\RuntimeException\( 'REPORTS_PROFILE_GUARDED_RESTORE_REQUIRED' \)/);
  const guarded = method('restore_guarded');
  assert.match(guarded, /ReportsProfileTransaction::reverse_profile_guarded\(/);
  assert.match(guarded, /concurrent_revision/);
  assert.match(guarded, /compensation_unverified/);
  assert.match(guarded, /document_sha256/);
});
