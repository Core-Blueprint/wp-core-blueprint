import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const browser = readFileSync(new URL('../../assets/js/core/template-browser.js', import.meta.url), 'utf8');
const assets = readFileSync(new URL('../../src/TemplateLibrary/Assets.php', import.meta.url), 'utf8');
const modal = readFileSync(new URL('../../assets/js/core/modal.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../assets/css/components/template-library.css', import.meta.url), 'utf8');

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

test('T3 global management stays delegated to local owner screens', () => {
  assert.match(browser, /if \( !contextual && item\.navigation/);
  assert.match(browser, /for \( const kind of \[ 'preview', 'manage' \] \)/);
  assert.match(browser, /destination\.origin !== window\.location\.origin/);
  assert.match(browser, /destination\.pathname\.includes\( '\/wp-admin\/' \)/);
  assert.match(browser, /el\( 'a', 'button cb-template-browser__action'/);
  assert.doesNotMatch(browser, /fetch\([^\n]*method: 'POST'/);
  assert.doesNotMatch(browser, /onDelete|deleteTemplate|exportTemplate/);
});

test('standalone Template Library uses viewport-filling flex without changing Designer modal layout', () => {
  assert.match(styles, /\.cb-template-browser\s*\{\s*display: grid;/);
  assert.match(styles, /\.cb-template-library-page\s*\{[^}]*max-width: none;/);
  assert.doesNotMatch(styles, /\.cb-template-library-page\s*\{[^}]*max-width:\s*1440px;/);
  assert.match(styles, /\.cb-template-library-page\s*\{[^}]*display: flex;[^}]*flex-direction: column;[^}]*min-height: calc\(100dvh - 80px\);/);
  assert.match(styles, /\.cb-template-library-page > \[data-cb-template-library-global\]\s*\{[^}]*display: flex;[^}]*flex: 1 1 auto;/);
  assert.match(styles, /\.cb-template-library-page \.cb-template-browser\s*\{[^}]*display: flex;[^}]*flex: 1 1 auto;/);
  assert.match(styles, /\.cb-template-library-page \.cb-template-browser__sidebar\s*\{[^}]*flex: 0 0 clamp\(160px, 19%, 236px\);/);
  assert.match(styles, /\.cb-template-library-page \.cb-template-browser__grid\s*\{[^}]*flex: 1 1 auto;/);
  assert.match(styles, /@media \(max-width: 720px\)\s*\{[\s\S]*?\.cb-template-library-page \.cb-template-browser\s*\{ flex-direction: column; \}/);
});

test('global filters distinguish translated Source and Plugin without changing their query keys', () => {
  assert.match(browser, /addSelect\( 'source', 'source', 'Source' \)/);
  assert.match(browser, /addSelect\( 'extension', 'plugin', 'Plugin' \)/);
  assert.match(assets, /'plugin'\s*=> __\( 'Plugin', 'core-blueprint' \)/);
  assert.doesNotMatch(browser, /addSelect\( 'extension', 'source'/);
});

test('Load more is hidden for zero results and only shown with a populated next page', () => {
  assert.match(browser, /more\.hidden = true;/);
  assert.match(browser, /more\.hidden = !state\.cursor \|\| !state\.entries\.length;/);
  assert.match(styles, /\.cb-template-browser__more\[hidden\]\s*\{\s*display: none !important;\s*\}/);
});


test('Template categories are declared by authorized sources and resolved under the current Designer scope', () => {
  assert.match(browser, /addSelect\( 'category', 'category', 'Category' \)/);
  assert.match(browser, /request\( 'sources', \{\}, token, undefined \)/);
  assert.match(browser, /Array\.isArray\( source\.categories \)/);
  assert.match(browser, /if \( state\.filters\.source && source\.id !== state\.filters\.source \) continue/);
  assert.match(browser, /if \( state\.filters\.extension && source\.extension !== state\.filters\.extension \) continue/);
  assert.match(browser, /if \( state\.filters\.designer && source\.designer !== state\.filters\.designer \) continue/);
  assert.match(browser, /categorySelect\.disabled = available\.size === 0/);
  assert.doesNotMatch(browser, /const categoryInput = el\( 'input'/);
});

test('filter interaction is safe while the source metadata response is pending', async () => {
  class FakeElement {
    constructor(tag) {
      this.tagName = tag.toUpperCase();
      this.children = [];
      this.dataset = {};
      this.events = new Map();
      this.value = '';
      this.hidden = false;
      this.disabled = false;
    }
    append(...nodes) { this.children.push(...nodes); }
    prepend(...nodes) { this.children.unshift(...nodes); }
    replaceChildren(...nodes) { this.children = [...nodes]; }
    setAttribute(name, value) { this[name] = String(value); }
    addEventListener(name, listener) { this.events.set(name, listener); }
  }
  const root = new FakeElement('div');
  const calls = [];
  const document = {
    createElement: (tag) => new FakeElement(tag),
    getElementById: () => ({
      textContent: JSON.stringify({
        restRoot: '/wp-json/core-blueprint/v1/template-library/',
        nonce: 'fixture-nonce',
      }),
    }),
    querySelector: () => null,
  };
  const sourcePending = new Promise(() => {});
  const context = {
    document, HTMLElement: FakeElement, URL, URLSearchParams, AbortController,
    setTimeout, clearTimeout,
    window: { location: { href: 'https://example.test/wp-admin/', origin: 'https://example.test' } },
    fetch: async (url) => {
      calls.push(url);
      if (url.includes('/sources?')) return sourcePending;
      return { ok: true, json: async () => ({ items: [], next_cursor: null }) };
    },
  };
  const executable = browser
    .replace("import { show as showModal } from '@cb-core/modal';", 'const showModal = async () => false;')
    .replace('export { createBrowser, open };', '')
    .replace('export default api;', '');
  runInNewContext(executable, context, { timeout: 1000 });
  context.window.cbCore.templateBrowser.createBrowser(root);
  const sidebar = root.children[0].children[0];
  const builtIn = sidebar.children[1];
  assert.doesNotThrow(() => builtIn.events.get('click')());
  assert.equal(
    calls.filter((url) => url.includes('/templates?') && new URL(url, 'https://example.test').searchParams.get('origin') === 'built-in').length,
    1,
    'Changing an origin before facets arrive must still refresh the template query',
  );
});
