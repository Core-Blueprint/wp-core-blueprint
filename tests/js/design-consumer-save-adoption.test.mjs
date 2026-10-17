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

test('Reports Profile preflight/restore block active before any mutation', () => {
  for (const marker of [
    'public function preflight(', 'self::require_legacy_profile_authority();',
    'public function restore(', 'parent::restore(', 'public function apply(',
  ]) assert.ok(profiles.includes(marker), marker);
});
