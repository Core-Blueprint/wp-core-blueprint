import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const browser = readFileSync(new URL('../../assets/js/core/template-browser.js', import.meta.url), 'utf8');
const templateActions = readFileSync(new URL('../../assets/js/core/template-actions.js', import.meta.url), 'utf8');
const assets = readFileSync(new URL('../../src/TemplateLibrary/Assets.php', import.meta.url), 'utf8');
const modal = readFileSync(new URL('../../assets/js/core/modal.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../assets/css/components/template-library.css', import.meta.url), 'utf8');

test('Template Browser exposes the canonical public module and shared workspace modal', () => {
  assert.match(browser, /import \{ show as showModal \} from '@cb-core\/modal'/);
  assert.match(browser, /size: 'fullscreen'/);
  assert.doesNotMatch(browser, /expandable: true/);
  assert.match(browser, /export \{ createBrowser, open, loadForApply \}/);
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
  assert.match(browser, /if \( contextual \|\| !item\.compatible \) body\.append\( stateBadge \)/);
});

test('Load more carries the signed page cursor through the GET query and keeps filters', () => {
  assert.match(browser, /queryString\( filters, cursor \)/);
  assert.match(browser, /request\( 'templates', state\.filters, token, controller\.signal, state\.cursor \|\| '' \)/);
  assert.match(browser, /function queryString\( filters, cursor = '' \)/);
  assert.match(browser, /if \( cursor \) query\.set\( 'cursor', cursor \)/);
  assert.match(browser, /const response = await fetch\( settings\.restRoot \+ route \+ '\?' \+ queryString\( filters, cursor \)/);
});

test('TL-M2 global and contextual Browser reuse provider-authorized actions without nesting interactive controls', () => {
  assert.match(browser, /import \{ createActionMenu \} from '@cb-core\/template-actions'/);
  assert.match(assets, /'@cb-core\/template-actions'/);
  assert.match(assets, /self::version\( 'assets\/js\/core\/template-actions\.js' \)/);
  assert.match(browser, /contextual && !menu \? 'label' : 'article'/);
  assert.match(browser, /cb-template-browser__card-select/);
  assert.match(browser, /state\.actionMenus\.push\( menu \)/);
  assert.match(templateActions, /const OPERATIONS = Object\.freeze/);
  assert.match(templateActions, /declared\.includes\( id \)/);
  assert.match(templateActions, /!globalMode \|\| !item\.navigation/);
  assert.match(templateActions, /target\.origin !== window\.location\.origin/);
  assert.match(templateActions, /target\.pathname\.includes\( '\/wp-admin\/' \)/);
  assert.match(templateActions, /'X-WP-Nonce'/);
  assert.match(templateActions, /panel\.setAttribute\( 'popover', 'manual' \)/);
  assert.match(templateActions, /createIcon\( 'ellipsis'/);
  assert.match(assets, /\[ '@cb-core\/modal', '@cb-core\/icon' \]/);
  assert.match(templateActions, /'X-CB-Designer-Scope'/);
  assert.match(templateActions, /method: 'POST', credentials: 'same-origin'/);
  assert.match(templateActions, /confirmCheck: destructive/);
  assert.doesNotMatch(templateActions, /\.innerHTML\s*=/);
});

test('standalone Template Library fills the page without stretching card grid rows', () => {
  assert.match(styles, /\.cb-template-browser\s*\{\s*display: grid;/);
  assert.match(styles, /\.cb-template-library-page\s*\{[^}]*max-width: none;/);
  assert.doesNotMatch(styles, /\.cb-template-library-page\s*\{[^}]*max-width:\s*1440px;/);
  assert.match(styles, /\.cb-template-library-page\s*\{[^}]*display: flex;[^}]*flex-direction: column;[^}]*min-height: calc\(100dvh - 80px\);/);
  assert.match(styles, /\.cb-template-library-page > \[data-cb-template-library-global\]\s*\{[^}]*display: flex;[^}]*flex: 1 1 auto;/);
  assert.match(styles, /\.cb-template-library-page \.cb-template-browser\s*\{[^}]*display: grid;[^}]*flex: 1 1 auto;/);
  assert.match(styles, /\.cb-template-library-page \.cb-template-browser__sidebar\s*\{[^}]*min-width: 0;/);
  assert.match(styles, /\.cb-template-library-page \.cb-template-browser__grid\s*\{[^}]*flex: 0 0 auto;/);
  assert.match(styles, /\.cb-template-browser__grid\s*\{[^}]*grid-auto-rows: 1fr;/);
  assert.match(styles, /\.cb-template-browser__grid\s*\{[^}]*align-content: start;/);
  assert.match(styles, /\.cb-template-browser__card\s*\{[^}]*display: flex;[^}]*flex-direction: column;/);
  assert.match(styles, /\.cb-template-browser__card-body\s*\{[^}]*flex: 1 1 auto;/);
  assert.doesNotMatch(styles, /\.cb-template-browser__card-body\s*\{[^}]*height: 100%;/);
  assert.match(styles, /\.cb-template-browser__actions\s*\{[^}]*position: absolute;[^}]*inset-block-start: 12px;[^}]*inset-inline-end: 12px;/);
  assert.match(styles, /\.cb-template-browser__action-panel\s*\{[^}]*position: fixed;/);
  assert.match(styles, /\.cb-template-browser__choice\s*\{[^}]*inset-inline-start: 12px;/);
  assert.match(styles, /@media \(max-width: 720px\)\s*\{[\s\S]*?\.cb-template-browser--workspace\s*\{[\s\S]*?grid-template-columns: minmax\(0, 1fr\);/);
});

test('FIELD-04B: contextual Browser scrolls results while card rows remain intrinsic', () => {
  assert.match(browser, /cb-template-browser--modal/);
  assert.match(browser, /cb-template-browser__results-viewport/);
  assert.match(browser, /content.append\( toolbar, message, resultsViewport \|\| results, footer \)/);
  assert.match(styles, /cb-template-browser__mount \{[^}]*flex: 1 1 auto;[^}]*min-height: 0;/);
  assert.match(styles, /cb-template-browser--modal \.cb-template-browser__results-viewport \{[^}]*flex: 1 1 auto;[^}]*overflow-y: auto;/);
  assert.match(styles, /cb-template-browser--modal \.cb-template-browser__grid \{ flex: 0 0 auto; \}/);
  assert.match(styles, /cb-template-browser--modal \.cb-template-browser__footer \{ flex: 0 0 auto; \}/);
  assert.match(styles, /grid-template-rows: auto auto minmax\(0, 1fr\);/);
});

test('Browser Golden UX shares layout while contextual Save remains Designer-only', () => {
  assert.match(browser, /resultHeading\.append\( resultTitle, resultCount \)/);
  assert.match(browser, /toolbar\.prepend\( resultHeading, searchField \)/);
  assert.match(browser, /resultCount\.textContent = String\( state\.entries\.length \)/);
  assert.match(browser, /cb-template-browser__collection-count/);
  assert.match(browser, /sidebar\.append\( saveArea \)/);
  assert.match(browser, /if \( contextual && typeof options\.onSaveAs === 'function' \)/);
  assert.doesNotMatch(browser, /body\.append\( saveAction \)/);
  assert.match(browser, /footer\.hidden = contextual && !state\.cursor/);
  assert.match(styles, /\.cb-template-browser__field\[hidden\],[\s\S]*?display: none !important;/);
  assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__card\.is-current/);
  assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__card:has\(\.cb-template-browser__choice:checked\)/);
  assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__sidebar-footer/);
  assert.match(styles, /@media \(max-width: 720px\) \{[\s\S]*?\.cb-template-browser--workspace \.cb-template-browser__save-as/);
  assert.match(styles, /\.cb-template-library-page \.cb-template-browser \{/);
});

test('global filters distinguish translated Source and Plugin without changing their query keys', () => {
  assert.match(browser, /addSelect\( 'source', 'source', 'Source' \)/);
  assert.match(browser, /addSelect\( 'extension', 'plugin', 'Plugin' \)/);
  assert.match(assets, /'plugin'\s*=> __\( 'Plugin', 'core-blueprint' \)/);
  assert.doesNotMatch(browser, /addSelect\( 'extension', 'source'/);
});

test('Load more honors a valid continuation cursor even after an empty authorized page', () => {
  assert.match(browser, /more\.hidden = true;/);
  assert.match(browser, /more\.hidden = !state\.cursor;/);
  assert.match(browser, /state\.entries\.length \|\| state\.cursor/);
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
      const cursor = new URL(url, 'https://example.test').searchParams.get('cursor');
      return { ok: true, json: async () => cursor
        ? { items: [{
            source: 'fixture', id: 'public', title: 'Public template',
            description: '', origin: 'built-in', category: 'example',
            designer: 'workflow', compatible: true,
          }], next_cursor: null }
        : { items: [], next_cursor: 'fixture-next-page' },
      };
    },
  };
  const executable = browser
    .replace("import { show as showModal } from '@cb-core/modal';", 'const showModal = async () => false;')
    .replace("import { createActionMenu } from '@cb-core/template-actions';", 'const createActionMenu = () => null;')
    .replace('export { createBrowser, open, loadForApply };', '')
    .replace('export default api;', '');
  runInNewContext(executable, context, { timeout: 1000 });
  context.window.cbCore.templateBrowser.createBrowser(root);
  const sidebar = root.children[0].children[0];
  const origins = root.children[0].children[1];
  const builtIn = origins.children[2];
  assert.equal(sidebar.role, 'group', 'Sections remain separate from origin filters');
  assert.equal(sidebar.children[0]['aria-pressed'], 'true');
  assert.equal(origins.role, 'group', 'Origin buttons are a filter group');
  assert.equal(origins.children[1]['aria-pressed'], 'true');
  assert.doesNotThrow(() => builtIn.events.get('click')());
  assert.equal(builtIn['aria-pressed'], 'true', 'The active origin is conveyed as pressed');
  assert.equal(origins.children[1]['aria-pressed'], 'false', 'The previous origin is no longer pressed');
  assert.equal(
    calls.filter((url) => url.includes('/templates?') && new URL(url, 'https://example.test').searchParams.get('origin') === 'built-in').length,
    1,
    'Changing an origin before facets arrive must still refresh the template query',
  );
  await new Promise((resolve) => setImmediate(resolve));
  const content = root.children[0].children[2];
  const more = content.children[3].children[1];
  assert.equal(more.hidden, false, 'Empty filtered pages must preserve valid continuation');
  more.events.get('click')();
  await new Promise((resolve) => setImmediate(resolve));
  assert.ok(
    calls.some((url) => new URL(url, 'https://example.test').searchParams.get('cursor') === 'fixture-next-page'),
    'Load more must request the next cursor',
  );
  assert.equal(content.children[2].children.length, 1, 'The next authorized template remains reachable');
});

test('T3-01 native Apply loader uses same-origin authorized read and refuses mismatched response', async () => {
  const target = {profileId: 'base/mail', resourceType: 'base.mail-template',
    resourceKey: 'wordpress.new-user', contextEpoch: 4};
  const calls = [];
  const expected = {
    status: 'ready', source: 'core-blueprint-mail', templateId: 'wordpress.new-user',
    ...target, blogId: 1, actorId: 2,
    document: {project: {schema_version: 1}, fields: {subject: 'Example', preheader: ''}},
  };
  const context = {
    document: {
      getElementById: () => ({textContent: JSON.stringify({
        restRoot: '/wp-json/core-blueprint/v1/template-library/', nonce: 'verified-nonce',
      })}),
      querySelector: () => null,
    },
    window: {}, AbortController,
    fetch: async (url, options) => {
      calls.push({url, options});
      return {ok: true, json: async () => expected};
    },
  };
  const executable = browser
    .replace("import { show as showModal } from '@cb-core/modal';", 'const showModal = async () => false;')
    .replace("import { createActionMenu } from '@cb-core/template-actions';", 'const createActionMenu = () => null;')
    .replace('export { createBrowser, open, loadForApply };', '')
    .replace('export default api;', '');
  runInNewContext(executable, context, {timeout: 1000});
  const loadForApply = context.window.cbCore.templateBrowser.loadForApply;
  const reply = await loadForApply({
    contextToken: 'signed-token', source: 'core-blueprint-mail',
    templateId: 'wordpress.new-user', target,
  });
  assert.equal(reply.status, 'ready');
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, '/wp-json/core-blueprint/v1/template-library/apply');
  assert.equal(calls[0].options.method, 'POST');
  assert.equal(calls[0].options.credentials, 'same-origin');
  assert.equal(calls[0].options.headers['X-CB-Designer-Scope'], 'signed-token');
  assert.equal(calls[0].options.headers['X-WP-Nonce'], 'verified-nonce');
  assert.equal(JSON.parse(calls[0].options.body).resourceKey, target.resourceKey);
  context.fetch = async () => ({ok: true, json: async () => ({
    ...expected, resourceKey: 'other-resource',
  })});
  await assert.rejects(loadForApply({
    contextToken: 'signed-token', source: 'core-blueprint-mail',
    templateId: 'wordpress.new-user', target,
  }), /Could not load templates/);
  await assert.rejects(loadForApply({
    contextToken: '', source: 'core-blueprint-mail',
    templateId: 'wordpress.new-user', target,
  }), /Could not load templates/);
});
