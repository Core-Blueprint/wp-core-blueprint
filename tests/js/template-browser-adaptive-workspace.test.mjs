import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const browserSource = readFileSync(
    new URL('../../assets/js/core/template-browser.js', import.meta.url), 'utf8'
);
const styles = readFileSync(
    new URL('../../assets/css/components/template-library.css', import.meta.url), 'utf8'
);

class Element {
    constructor(tag) {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.dataset = {};
        this.events = new Map();
        this.attributes = {};
        this.className = '';
        this.textContent = '';
        this.parentElement = null;
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this.value = '';
        this.classList = { add: name => { this.className += ' ' + name; } };
    }
    append(...nodes) {
        for (const node of nodes) { node.parentElement = this; this.children.push(node); }
    }
    prepend(...nodes) {
        for (const node of nodes) node.parentElement = this;
        this.children.unshift(...nodes);
    }
    replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    addEventListener(name, fn) { this.events.set(name, fn); }
}

const flush = () => new Promise(resolve => setImmediate(resolve));

function harness() {
    const calls = [];
    const document = {
        createElement: tag => new Element(tag),
        getElementById: () => ({ textContent: JSON.stringify({
            restRoot: '/wp-json/core-blueprint/v1/template-library/', nonce: 'test-nonce',
        }) }),
        querySelector: () => null,
    };
    const window = { location: {
        origin: 'https://example.test', href: 'https://example.test/wp-admin/',
    } };
    const sandbox = {
        document, window, HTMLElement: Element, URL, URLSearchParams, AbortController,
        setTimeout, clearTimeout,
        fetch: async url => {
            calls.push(url);
            return { ok: true, json: async () => url.includes('/sources?')
                ? { sources: [ { id: 'core', categories: [ { id: 'mail', label: 'Mail' } ] } ] }
                : { items: [ { id: 'template-1', title: 'Authorized template',
                    source: 'core', origin: 'built-in', category: 'mail', designer: 'mail',
                    compatible: true, description: 'Reusable content' } ], next_cursor: null },
            };
        },
    };
    const executable = browserSource
        .replace("import { show as showModal } from '@cb-core/modal';", 'const showModal = async () => false;')
        .replace('export { createBrowser, open, loadForApply };', '')
        .replace('export default api;', '');
    runInNewContext(executable, sandbox, { timeout: 1000 });
    return { api: window.cbCore.templateBrowser, calls };
}

test('FIELD-05B: Designs use compact resource rows; Templates keep preview cards', async () => {
    const { api, calls } = harness();
    const root = new Element('div');
    const instance = api.createBrowser(root, {
        mode: 'designer', contextToken: 'server-issued-scope',
        activeDesignId: 'wordpress.first',
        designs: [
            { id: 'wordpress.first', title: 'Email changed', provider: 'WordPress' },
            { id: 'extension.second', title: 'Other notification', provider: 'Extension' },
        ],
    });
    const shell = root.children[0], sidebar = shell.children[0];
    const content = shell.children[2], toolbar = content.children[0];
    const grid = content.children[2].children[0];
    const categoryField = toolbar.children[2];
    assert.equal(shell.children.length, 3);
    assert.equal(shell.dataset.browserView, 'designs');
    assert.equal(grid.children.length, 2);
    assert.equal(categoryField.hidden, true);
    for (const card of grid.children) {
        assert.match(card.className, /cb-template-browser__card--design/);
        assert.equal(card.children.length, 2, 'only radio and details, no fictional thumbnail');
        assert.match(card.children[1].className, /cb-template-browser__card-body/);
        assert.equal(card.children[0].type, 'radio');
    }
    assert.match(grid.children[0].className, /is-current/);
    assert.equal(grid.children[0].children[1].children[1].children[1].textContent, 'Current');

    const second = grid.children[1].children[0];
    second.checked = true;
    second.events.get('change')();
    assert.equal(instance.selected().id, 'extension.second');

    sidebar.children[1].events.get('click')();
    await flush();
    assert.equal(shell.dataset.browserView, 'templates');
    assert.equal(instance.view(), 'templates');
    assert.equal(instance.selected(), null, 'old design selection cannot apply as a template');
    assert.equal(categoryField.hidden, false, 'category is relevant when facets exist');
    assert.equal(grid.children.length, 1);
    const template = grid.children[0];
    assert.equal(template.children[0].type, 'radio');
    assert.match(template.children[1].className, /cb-template-browser__card-preview/);
    assert.match(template.children[2].className, /cb-template-browser__card-body/);
    assert.ok(calls.some(url => url.includes('/templates?')), 'signed template read path stays active');

    sidebar.children[0].events.get('click')();
    assert.equal(shell.dataset.browserView, 'designs');
    assert.equal(categoryField.hidden, true);
    assert.equal(instance.selected(), null);
    assert.equal(grid.children.length, 2);
    assert.equal(grid.children[0].children.length, 2);
    instance.destroy();
});

test('FIELD-05B: global Template Library remains two-pane and unmodified', async () => {
    const { api } = harness();
    const root = new Element('div');
    const instance = api.createBrowser(root);
    await flush();
    const shell = root.children[0];
    assert.equal(shell.children.length, 2);
    assert.equal(shell.dataset.browserView, undefined);
    assert.doesNotMatch(shell.className, /cb-template-browser--modal/);
    const toolbar = shell.children[1].children[0];
    assert.match(toolbar.children[0].className, /cb-template-browser__field--search/);
    assert.equal(toolbar.children.some(node => node.className === 'cb-template-browser__result-heading'), false);
    const templateCard = shell.children[1].children[2].children[0];
    assert.equal(templateCard.tagName, 'ARTICLE');
    instance.destroy();
});

test('FIELD-05B: responsive styles separate design rows and template grid', () => {
    assert.match(styles, /\.cb-template-browser--modal\[data-browser-view="designs"\] \.cb-template-browser__grid\s*\{[^}]*grid-auto-rows: auto;/);
    assert.match(styles, /\.cb-template-browser--modal\[data-browser-view="designs"\] \.cb-template-browser__card--design\s*\{[^}]*display: grid;[^}]*min-height: 78px;/);
    assert.match(styles, /\.cb-template-browser--modal\[data-browser-view="templates"\] \.cb-template-browser__grid\s*\{[^}]*grid-auto-rows: 1fr;/);
    assert.match(styles, /\.cb-template-browser--modal \.cb-template-browser__field > span\s*\{[^}]*position: absolute;[^}]*width: 1px;/);
    assert.match(styles, /@media \(max-width: 720px\)\s*\{[\s\S]*?data-browser-view="designs"\] \.cb-template-browser__grid/);
    assert.match(styles, /\.cb-template-library-page \.cb-template-browser/);
});
