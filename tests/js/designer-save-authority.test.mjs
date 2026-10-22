import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const launch = readFileSync(new URL('assets/js/features/designer-launch.js', root), 'utf8');
const php = readFileSync(new URL('src/Design/Editor/Assets.php', root), 'utf8');
const begin = launch.indexOf('const bindSaveState = (shell, save) => {');
const end = launch.indexOf('\n\t};', begin);
assert.ok(begin !== -1 && end > begin, 'canonical Base save-state binder must exist');
// Evaluate the actual extracted binder with DOM event doubles, not a copied
// reimplementation. No browser or WordPress globals are required.
const expr = launch.slice(begin, end + '\n\t};'.length).trim().replace(/^const bindSaveState = /, '').replace(/;$/, '');
const events = [];
const bind = new Function('SAVE_EVENT', 'window', 'config',
  `return (${expr});`)(
  'cb:design-shell:savechange',
  { cbCore: { toast: { success: (s) => events.push(['success', s]), error: (s) => events.push(['error', s]) } } },
  { toastLabels: { saved: 'Saved', error: 'Failed' } }
);

const setup = (authorization) => {
  let onSave;
  const shell = {
    dataset: {},
    addEventListener(type, callback) {
      assert.equal(type, 'cb:design-shell:savechange');
      onSave = callback;
    },
  };
  const attrs = new Map();
  if (authorization !== null) attrs.set('data-cb-design-shell-save-authorized', authorization);
  const save = {
    disabled: authorization === 'false',
    getAttribute(name) { return attrs.get(name) ?? null; },
    setAttribute(name, value) { attrs.set(name, value); },
    removeAttribute(name) { attrs.delete(name); },
  };
  bind(shell, save);
  return {
    save, shell,
    dispatch(state) { onSave({ detail: { state, message: state } }); },
  };
};

test('explicitly blocked Save remains disabled through every UI status event', () => {
  assert.match(php, /public const SAVE_AUTHORITY_UI_API = 1;/);
  const ui = setup('false');
  for (const state of ['idle', 'saving', 'error', 'saved', 'idle']) {
    ui.dispatch(state);
    assert.equal(ui.save.disabled, true, 'forbidden Save became active on ' + state);
  }
  assert.equal(ui.shell.dataset.cbDesignShellSaveState, 'idle');
});

test('authorized Save recovers after busy and preserves toast feedback', () => {
  const ui = setup('true');
  ui.dispatch('saving');
  assert.equal(ui.save.disabled, true);
  ui.dispatch('error');
  assert.equal(ui.save.disabled, false);
  ui.dispatch('saving');
  ui.dispatch('saved');
  assert.equal(ui.save.disabled, false);
  assert.ok(events.some((e) => e[0] === 'error'));
  assert.ok(events.some((e) => e[0] === 'success'));
});

test('legacy consumers without the opt-in attribute preserve original behavior', () => {
  const ui = setup(null);
  ui.dispatch('saving');
  assert.equal(ui.save.disabled, true);
  ui.dispatch('idle');
  assert.equal(ui.save.disabled, false);
});

test('ambiguous Save outcome remains denied after delayed idle and saved events', () => {
  const ui = setup('true');
  const successesBefore = events.filter(([kind]) => kind === 'success').length;
  ui.dispatch('saving');
  ui.dispatch('unknown');
  assert.equal(ui.save.disabled, true);
  assert.equal(ui.shell.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
  ui.dispatch('idle');
  ui.dispatch('saved');
  assert.equal(ui.shell.dataset.cbDesignShellSaveState, 'unknown');
  assert.equal(ui.save.disabled, true);
  assert.equal(events.filter(([kind]) => kind === 'success').length, successesBefore);
});

test('explicitly unauthorized Save remains denied when its outcome becomes UNKNOWN', () => {
  const ui = setup('false');
  ui.dispatch('unknown');
  ui.dispatch('idle');
  assert.equal(ui.save.disabled, true);
  assert.equal(ui.shell.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
});
