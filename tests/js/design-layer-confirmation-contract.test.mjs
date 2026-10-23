import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const source = await readFile(new URL('../../assets/js/design/shell/layers.js', import.meta.url), 'utf8');
const begin = source.indexOf('const resolveRemoveConfirmation = ');
const end = source.indexOf('\nconst normalizeAction = ', begin);
assert.ok(begin >= 0 && end > begin, 'Shared Layers owns the confirmation resolver');
const resolveRemoveConfirmation = new Function(
  source.slice(begin, end) + '\nreturn resolveRemoveConfirmation;'
)();

test('shared Layers honors accurate semantic confirmation for Evaluator Section removal', () => {
  const descriptor = ({ label, descendantCount }) => ({
    title: 'Remove ' + label + '?',
    message: 'Move ' + descendantCount + ' Questions to Unassigned without deleting their Rules.',
    confirmLabel: 'Move Questions and Remove Section',
  });
  const result = resolveRemoveConfirmation(descriptor, { label: 'Section', descendantCount: 3 });
  assert.deepEqual(result, {
    title: 'Remove Section?',
    message: 'Move 3 Questions to Unassigned without deleting their Rules.',
    confirmLabel: 'Move Questions and Remove Section',
  });
  assert.equal(Object.isFrozen(result), true);
});

test('remove confirmation rejects malformed or misleading descriptors before actions', () => {
  for (const bad of [false, '', [], {}, { title: 'Delete', message: 'text' },
    { title: 'Delete', message: 'text', confirmLabel: '' },
    { title: 'Delete', message: 'text', confirmLabel: 'X'.repeat(401) },
    () => Promise.resolve({ title: 'Delete', message: 'text', confirmLabel: 'Delete' }),
  ]) assert.throws(() => resolveRemoveConfirmation(bad, { label: 'Section', descendantCount: 2 }));
});

test('confirmation values use textContent, never HTML, and command stays after explicit click', () => {
  const dialog = source.slice(source.indexOf('const confirmChildrenDelete = '),
    source.indexOf('for (const { name, config } of entries)'));
  assert.match(dialog, /heading\.textContent = confirmation\.title;/);
  assert.match(dialog, /message\.textContent = confirmation\.message;/);
  assert.match(dialog, /remove\.textContent = confirmation\.confirmLabel;/);
  assert.doesNotMatch(dialog, /innerHTML|insertAdjacentHTML/);
  assert.match(dialog, /if \(activeMenu\?\.popup !== popup \|\| action\.disabled\) return;/);
  assert.match(dialog, /closeMenu\(\{ restoreFocus: true \}\);\s*action\.onActivate\(\);/);
  assert.match(source, /config\.confirmation !== null/);
  assert.match(source, /catch \(_\) \{ closeMenu\(\{ restoreFocus: true \}\); \}/);
});
