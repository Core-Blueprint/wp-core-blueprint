import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const browser = readFileSync(new URL('../../assets/js/core/template-browser.js', import.meta.url), 'utf8');
const modal = readFileSync(new URL('../../assets/js/core/modal.js', import.meta.url), 'utf8');

test('Template Browser exposes the canonical public module and shared workspace modal', () => {
  assert.match(browser, /import \{ show as showModal \} from '@cb-core\/modal'/);
  assert.match(browser, /size: 'workspace'/);
  assert.match(browser, /expandable: true/);
  assert.match(browser, /export \{ createBrowser, open \}/);
  assert.match(modal, /window\.cbCore\.modal = modal/);
});

test('contextual browser uses a server-issued scope and does not permit type switching', () => {
  assert.match(browser, /X-CB-Designer-Scope/);
  assert.match(browser, /contextToken/);
  assert.match(browser, /const sourceSelect = contextual \? null/);
  assert.match(browser, /const extensionSelect = contextual \? null/);
  assert.match(browser, /const designerSelect = contextual \? null/);
});

test('browser is metadata-only and uses inert DOM presentation', () => {
  assert.match(browser, /textContent = text/);
  assert.doesNotMatch(browser, /\.innerHTML\s*=/);
  assert.doesNotMatch(browser, /https?:\/\//);
  assert.doesNotMatch(browser, /eval\(/);
  assert.doesNotMatch(browser, /localStorage/);
  assert.match(browser, /await onChoose\( \{ \.\.\.selected \} \)/);
  assert.doesNotMatch(browser, /create[_-]workflow|activate[_-]workflow/i);
});

test('Load more carries the signed page cursor through the GET query and keeps filters', () => {
  assert.match(browser, /queryString\( filters, cursor \)/);
  assert.match(browser, /request\( 'templates', state\.filters, token, controller\.signal, state\.cursor \|\| '' \)/);
  assert.match(browser, /function queryString\( filters, cursor = '' \)/);
  assert.match(browser, /if \( cursor \) query\.set\( 'cursor', cursor \)/);
  assert.match(browser, /const response = await fetch\( settings\.restRoot \+ route \+ '\?' \+ queryString\( filters, cursor \)/);
});
