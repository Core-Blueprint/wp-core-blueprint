import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const php = read('src/Design/Editor/Assets.php');
const js = read('assets/js/features/designer-exit-guard.js');
const expectedNew = [
  'Close Designer?',
  'Return to the previous page?',
  'Changes may not be saved. Close Designer?',
  'Unsaved changes',
  'Save or discard your changes before closing the Designer.',
  'What should happen to your changes?',
  'Discard changes',
  'Close Designer',
  'Wait for the current save to finish.',
  'The Designer cannot be closed safely. Try again.',
  'Your changes could not be verified as saved. The Designer remains open.',
];
const reused = ['Save changes', 'Cancel'];
const keys = [...expectedNew, ...reused];
const locales = ['nl_NL', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'pt_PT'];
const phpKey = (key) => "'" + key.replaceAll("'", "\\'") + "'";

test('EXIT-I18N-A01: Base localizes all shared Exit Guard defaults on their own early-loading script', () => {
  const begin = php.indexOf("'cbCoreDesignerExitGuardLabels'");
  const end = php.indexOf('self::DESIGNER_MODE_SCRIPT', begin);
  assert.ok(begin > 0 && end > begin, 'Exit Guard localization precedes Designer launch registration');
  const snippet = php.slice(begin, end);
  assert.equal((snippet.match(/'core-blueprint' \)/g) || []).length, keys.length);
  for (const key of keys) assert.ok(snippet.includes("__(") && snippet.includes(phpKey(key)), key);
  assert.match(js, /\.\.\.\(window\.cbCoreDesignerExitGuardLabels \|\| \{\}\)/);
  assert.match(js, /\.\.\.defaults, \.\.\.\(adapter\.labels \|\| \{\}\)/);
});

test('EXIT-I18N-A02: six composed locale catalogs cover new keys and reuse existing translations', () => {
  // Catalog growth is normal: translations are validated against the PHP
  // source by tools/check-translations.php. Never freeze a retired global
  // count in this focused Exit Guard label coverage test.
  const total = Number(read('tools/canonical-i18n-count.txt').trim());
  assert.ok(Number.isSafeInteger(total) && total >= 3990, 'canonical catalogs may only grow');
  for (const locale of locales) {
    const composed = read(`languages/core-blueprint-${locale}.l10n.php`);
    const base = read(`languages/base/core-blueprint-${locale}.php`);
    for (const key of expectedNew) {
      assert.ok(composed.includes(phpKey(key) + ' => '), `${locale} composed translation missing: ${key}`);
      assert.ok(!base.includes(phpKey(key) + ' => '), `${locale} has unexpected old duplicate: ${key}`);
    }
    for (const key of reused) {
      assert.ok(base.includes(phpKey(key) + ' => '), `${locale} existing canonical translation missing: ${key}`);
    }
    assert.equal((composed.match(/Canonical Base Designer Exit Guard v1 labels/g) || []).length, 1);
  }
});

test('EXIT-I18N-A03: translated defaults reach the clean modal, with consumer overrides retaining priority', async () => {
  class Element { constructor() { this.dataset = {}; } hasAttribute() { return false; } }
  const events = new Map();
  const shown = [];
  const window = {
    cbCoreDesignerExitGuardLabels: { cleanTitle: 'Designer sluiten?', cleanDescription: 'Terug naar de vorige pagina?', cancel: 'Annuleren' },
    addEventListener: (name, listener) => events.set(name, listener),
    cbCore: { modal: { show: async (options) => { shown.push(options); return false; } } },
  };
  const document = { querySelector: () => null };
  runInNewContext(js, { window, document, Element });
  const root = new Element();
  window.cbCore.designerExitGuard.register(root, { isDirty: () => false });
  assert.equal(await window.cbCore.designerExitGuard.request(root, () => true), false);
  assert.equal(shown[0].title, 'Designer sluiten?');
  assert.equal(shown[0].cancelLabel, 'Annuleren');
  window.cbCore.designerExitGuard.register(root, { isDirty: () => false, labels: { cleanTitle: 'Notes Designer sluiten?' } });
  assert.equal(await window.cbCore.designerExitGuard.request(root, () => true), false);
  assert.equal(shown[1].title, 'Notes Designer sluiten?');
  assert.equal(shown[1].cancelLabel, 'Annuleren', 'unmodified labels still inherit translated Base defaults');
});
