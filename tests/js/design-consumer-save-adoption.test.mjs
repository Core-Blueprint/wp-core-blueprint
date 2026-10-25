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

test('No active marker is ever written; independent ever-active witness blocks downgrade', () => {
  assert.ok(authority.includes('EVER_ACTIVE_OPTION'));
  assert.ok(authority.includes("if ( null !== $witness_row )"));
  assert.ok(authority.includes("self::valid_receipt( $witness, 'ever-active' )"));
  for (const pattern of [/\bupdate_option\(/, /\badd_option\(/, /\bdelete_option\(/]) {
    assert.doesNotMatch(authority, pattern);
  }
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
