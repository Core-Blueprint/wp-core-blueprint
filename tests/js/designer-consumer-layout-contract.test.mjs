import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { auditDesignerLayout } from '../../tools/validate-designer-layout.mjs';

const source = (file) => readFile(new URL('../../' + file, import.meta.url), 'utf8');
const rejects = (input, message, options = {}) =>
  assert.ok(auditDesignerLayout(input, options).some((finding) => finding.includes(message)),
    'Expected source gate to reject: ' + message);

test('Base first-party Mail and Reports preferences satisfy canonical SOURCE layout', async () => {
  const [mail, reports] = await Promise.all([
    source('templates/mail-designer.php'),
    source('templates/preferences-reports.php'),
  ]);
  assert.deepEqual(auditDesignerLayout(mail, { mail: true }), []);
  assert.deepEqual(auditDesignerLayout(reports), []);
});

test('Old Newsletter right-hand Layers role is rejected', async () => {
  const mail = await source('templates/mail-designer.php');
  const legacy = mail.replace('data-cb-design-shell-palette-role="layers"',
    'data-cb-design-shell-sidebar-role="layers"');
  assert.notEqual(legacy, mail);
  rejects(legacy, 'Layers must be left', { mail: true });
  rejects(legacy, 'Palette tab missing semantic role', { mail: true });
});

test('Legacy standalone Dynamic Data tab fails closed', async () => {
  const mail = await source('templates/mail-designer.php');
  const legacy = mail.replace('data-cb-design-shell-tab="elements"',
    'data-cb-design-shell-tab="dynamic-data"');
  assert.notEqual(legacy, mail);
  rejects(legacy, 'Dynamic Data must be a section', { mail: true });
});

test('Mail header is forbidden; Reports is permitted a composed document header', async () => {
  const [mail, reports] = await Promise.all([
    source('templates/mail-designer.php'),
    source('templates/preferences-reports.php'),
  ]);
  const changed = mail.replace('data-cb-mail-preview-frame',
    'cb-core-design-shell__canvas-header data-cb-mail-preview-frame');
  assert.notEqual(changed, mail);
  rejects(changed, 'Mail editing canvas must be headerless', { mail: true });
  assert.deepEqual(auditDesignerLayout(reports, { mail: false }), []);
});

test('Missing or inconsistent tab role and panel identity cannot pass', async () => {
  const mail = await source('templates/mail-designer.php');
  const noRole = mail.replace('data-cb-design-shell-palette-role="elements"', '');
  rejects(noRole, 'Palette tab missing semantic role', { mail: true });
  const noPanel = mail.replace('data-cb-design-shell-panel="structure"',
    'data-cb-design-shell-panel="orphaned"');
  rejects(noPanel, 'no matching panel', { mail: true });
  const wrongSidebar = mail.replace('data-cb-design-shell-sidebar-role="inspector"',
    'data-cb-design-shell-sidebar-role="layers"');
  rejects(wrongSidebar, 'Right sidebar: unsupported role', { mail: true });
});

test('Base contract documents agree on canonical first-party source roles', async () => {
  const [layout, api] = await Promise.all([
    source('docs/DESIGNER-LAYOUT-CONTRACT.md'),
    source('docs/DESIGNER-FOUNDATION-API.md'),
  ]);
  const left = layout.slice(layout.indexOf('## Left rail'), layout.indexOf('## Right rail'));
  const right = layout.slice(layout.indexOf('## Right rail'), layout.indexOf('## Ownership boundary'));
  assert.match(left, /layers.*left of the canvas/i);
  assert.match(left, /Dynamic Data is a titled section inside Elements/);
  assert.doesNotMatch(left, /- .dynamic-data. .* data tokens/);
  assert.match(right, /- .inspector./i);
  assert.match(right, /- .settings./i);
  assert.doesNotMatch(right, /\n- .layers./i);
  assert.match(api, /Sidebar Layout & Iconography Standard v1 \(canonical SOURCE contract/);
  assert.match(api, /separate Dynamic Data tab, right-hand Layers.*fail the canonical source layout/);
  assert.match(layout, /installed ZIP\/browser acceptance pending/);
});

test('Reusable gate CLI accepts exact Base Mail source without claiming browser PASS', () => {
  const base = fileURLToPath(new URL('../../', import.meta.url));
  const result = spawnSync(process.execPath,
    ['tools/validate-designer-layout.mjs', 'templates/mail-designer.php', '--mail'],
    { cwd: base, encoding: 'utf8', timeout: 20000 });
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /SOURCE contract: PASS/);
  assert.match(result.stdout, /browser NOT RUN/);
});
