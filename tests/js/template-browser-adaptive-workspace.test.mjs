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

function harness(modalHook = async () => false) {
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
        setTimeout, clearTimeout, modalHook,
        fetch: async url => {
            calls.push(url);
            return { ok: true, json: async () => url.includes('/sources?')
                ? { sources: [ { id: 'core', categories: [ { id: 'mail', label: 'Mail' } ] } ] }
                : { items: [ { id: 'template-1', title: 'Authorized template',
                    source: 'core', origin: 'built-in', category: 'mail', designer: 'mail', version: '2.1',
                    compatible: true, description: 'Reusable content' } ], next_cursor: null },
            };
        },
    };
    const executable = browserSource
        .replace("import { show as showModal } from '@cb-core/modal';", 'const showModal = globalThis.modalHook;')
        .replace("import { createActionMenu } from '@cb-core/template-actions';", 'const createActionMenu = () => null;')
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

test('TL-M3A-R1: global Library uses one shared two-pane Browser without Designer authority', async () => {
    const { api, calls } = harness();
    const root = new Element('div');
    const instance = api.createBrowser(root);
    await flush();
    const shell = root.children[0];
    assert.equal(shell.children.length, 2, 'no empty primary section when Designs are unavailable');
    assert.equal(shell.dataset.browserView, 'templates');
    assert.match(shell.className, /cb-template-browser--workspace/);
    assert.doesNotMatch(shell.className, /cb-template-browser--modal/);
    const [origins, content] = shell.children;
    assert.doesNotMatch(shell.className, /cb-template-browser--with-sections/);
    assert.equal(origins.attributes['aria-label'], 'Origin');
    assert.equal(origins.children[0].textContent, 'Origin', 'Origins are not labeled Collections');
    assert.equal(origins.children.length, 6, 'heading and five origin filters');
    assert.equal(origins.children[1].attributes['aria-pressed'], 'true');
    const toolbar = content.children[0];
    assert.match(toolbar.children[0].className, /cb-template-browser__result-heading/);
    assert.equal(toolbar.children[0].children[0].tagName, 'H2');
    assert.match(toolbar.children[1].className, /cb-template-browser__field--search/);
    assert.equal(toolbar.children[2].children[0].textContent, 'Source');
    assert.equal(toolbar.children[2].children[1].children[0].textContent, 'All');
    assert.equal(toolbar.children[3].children[0].textContent, 'Plugin');
    assert.equal(toolbar.children[4].children[0].textContent, 'Designer type');
    assert.equal(toolbar.children[5].children[0].textContent, 'Category');
    const templateCard = content.children[2].children[0];
    assert.equal(templateCard.tagName, 'ARTICLE', 'global browsing does not grant a selection/apply control');
    assert.match(templateCard.children[0].className, /cb-template-browser__card-preview/);
    assert.equal(templateCard.children[0].attributes['aria-hidden'], 'true');
    assert.match(templateCard.children[1].className, /cb-template-browser__card-body/);
    origins.children[2].events.get('click')();
    await flush();
    assert.equal(origins.children[2].attributes['aria-pressed'], 'true');
    assert.ok(calls.some(url => url.includes('origin=built-in')));
    instance.destroy();
});

test('TL-1A: global and scoped cards show declared provenance without creating navigation authority', async () => {
    for (const contextual of [false, true]) {
        const { api } = harness();
        const root = new Element('div');
        const browser = api.createBrowser(root, contextual
            ? {mode: 'designer', contextToken: 'server-issued-scope'} : {});
        await flush();
        const shell = root.children[0];
        const content = shell.children[1];
        const card = content.children[2].children[0];
        const body = card.children.find(child =>
            child.className.includes('cb-template-browser__card-body'));
        assert.ok(body);
        const meta = body.children.find(child =>
            child.className.includes('cb-template-browser__card-meta'));
        assert.deepEqual(meta.children.map(child => child.textContent), ['Built-in', 'mail', 'mail'],
            'origin is localized but category and Designer remain provider metadata');
        const provenance = body.children.find(child =>
            child.className.includes('cb-template-browser__card-provenance'));
        assert.ok(provenance, 'provenance stays visible without opening an action menu');
        assert.deepEqual(provenance.children.map(child => child.textContent),
            ['Source: core', 'v2.1']);
        assert.equal(provenance.children.every(child => child.tagName === 'SPAN'), true,
            'the provenance is text, never provider-authored navigation or executable HTML');
        assert.equal(card.children.some(child => child.tagName === 'A'), false,
            'a metadata label must never grant a clickable trusted-origin action');
        browser.destroy();
    }
});

test('TL-M3A-R1: two-pane scoped Browser preserves Save-as when no Designs exist', async () => {
    const { api } = harness();
    const root = new Element('div');
    let calls = 0;
    const instance = api.createBrowser(root, {
        mode: 'designer', contextToken: 'server-issued-scope',
        onSaveAs: async () => { calls++; },
    });
    await flush();
    const shell = root.children[0];
    assert.equal(shell.children.length, 2, 'without Designs there is no empty primary navigation pane');
    assert.doesNotMatch(shell.className, /cb-template-browser--with-sections/);
    const origins = shell.children[0];
    const footer = origins.children.find(node => node.className.includes('cb-template-browser__sidebar-footer'));
    assert.ok(footer, 'scoped Save-as stays accessible in the origin sidebar');
    assert.equal(footer.children[0].textContent, 'Save as template');
    await footer.children[0].events.get('click')();
    assert.equal(calls, 1);
    assert.equal(footer.children[0].disabled, false);
    instance.destroy();
});

test('FIELD-05B: responsive styles separate design rows and template grid', () => {
    assert.match(styles, /\.cb-template-browser--workspace\[data-browser-view="designs"\] \.cb-template-browser__grid\s*\{[^}]*grid-auto-rows: auto;/);
    assert.match(styles, /\.cb-template-browser--workspace\[data-browser-view="designs"\] \.cb-template-browser__card--design\s*\{[^}]*display: grid;[^}]*min-height: 74px;/);
    assert.match(styles, /\.cb-template-browser--workspace\[data-browser-view="templates"\] \.cb-template-browser__grid\s*\{[^}]*grid-auto-rows: 1fr;/);
    assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__field > span\s*\{[^}]*position: absolute;[^}]*width: 1px;/);
    assert.match(styles, /@media \(max-width: 720px\)\s*\{[\s\S]*?data-browser-view="designs"\] \.cb-template-browser__grid/);
    assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__content\s*\{\s*padding: 0;/);
    assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__toolbar\s*\{[^}]*border-bottom: 1px solid var\(--cb-browser-line\);/);
    assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__footer\s*\{[^}]*border-top: 1px solid var\(--cb-browser-line\);/);
    assert.match(styles, /\.cb-template-browser--workspace\[data-browser-view="designs"\] \.cb-template-browser__card--design \.cb-template-browser__choice\s*\{[^}]*grid-column: 1;/);
    assert.match(styles, /\.cb-template-browser--workspace\[data-browser-view="designs"\] \.cb-template-browser__card--design \.cb-template-browser__card-body\s*\{[^}]*grid-column: 2;/);
    assert.match(styles, /\.cb-template-library-page \.cb-template-browser/);
});

test('FIELD-05C: Browser confirm label and availability track Designs and Templates', async () => {
    let actions = [], currentLabel = '', enabled = null;
    const { api } = harness(async options => {
        currentLabel = options.confirmLabel;
        enabled = options.confirmEnabled();
        const refresh = nextLabel => {
            if (nextLabel) currentLabel = nextLabel;
            enabled = options.confirmEnabled();
        };
        options.onReady({ updateConfirmAction: refresh });
        assert.equal(currentLabel, 'Open design');
        assert.equal(enabled, false);
        const shell = options.body.children[1].children[0];
        const search = shell.children[2].children[0].children[1].children[1];
        assert.equal(search.placeholder, 'Search designs');
        const first = shell.children[2].children[2].children[0].children[0].children[0];
        first.checked = true;
        first.events.get('change')();
        assert.equal(enabled, true);

        shell.children[0].children[1].events.get('click')();
        await flush();
        assert.equal(currentLabel, 'Use template');
        assert.equal(enabled, false, 'design selection must not survive switching views');
        assert.equal(search.placeholder, 'Search templates');
        const grid = shell.children[2].children[2].children[0];
        assert.equal(grid.children.length, 1);
        const templateRadio = grid.children[0].children[0];
        assert.equal(templateRadio.disabled, false);
        templateRadio.checked = true;
        templateRadio.events.get('change')();
        assert.equal(enabled, true);
        return await options.onConfirm();
    });
    const accepted = await api.open({
        contextToken: 'server-issued-scope',
        onChoose: async item => { actions.push(item.id); return true; },
        onOpenDesign: async () => { throw new Error('NO_DESIGN_NAVIGATION'); },
        designs: [ { id: 'wordpress.first', title: 'Email changed', provider: 'WordPress' } ],
    });
    assert.equal(accepted, true);
    assert.deepEqual(actions, ['template-1']);
});


test('TL-M3A-R1: global workspace is contained while Designer modal owns its frame', () => {
    assert.match(styles, /\.cb-template-library-page \.cb-template-browser--workspace\s*\{[^}]*border: 1px solid var\(--cb-browser-line\);[^}]*border-radius: 10px;[^}]*overflow: hidden;/);
    assert.match(styles, /\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__content\s*\{[^}]*background: var\(--cb-surface-1/);
    assert.match(styles, /\.cb-template-browser--workspace\.cb-template-browser--with-sections\s*\{[^}]*grid-template-columns: minmax\(152px, 190px\) minmax\(160px, 220px\) minmax\(0, 1fr\);/);
    assert.match(styles, /\.cb-template-browser--workspace\s*\{[^}]*grid-template-columns: minmax\(160px, 220px\) minmax\(0, 1fr\);/);
    assert.match(styles, /\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__toolbar\s*\{[^}]*display: grid;[^}]*grid-template-columns: repeat\(4, minmax\(0, 1fr\)\);/);
    assert.match(styles, /\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__field:not\(\.cb-template-browser__field--search\) > span\s*\{[^}]*position: static;/);
    assert.match(styles, /\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__card-preview\s*\{[^}]*height: 104px;/);
    assert.match(styles, /@media \(max-width: 480px\)\s*\{[^}]*\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__toolbar/);
    assert.match(styles, /\.cb-template-browser--workspace\.cb-template-browser--with-sections \.cb-template-browser__content\s*\{[^}]*grid-row: auto;[^}]*grid-column: 1;/);
    assert.match(styles, /\.cb-template-browser--workspace\.cb-template-browser--with-sections \.cb-template-browser__collections\s*\{[^}]*grid-row: auto;[^}]*grid-column: 1;/);
    assert.match(styles, /\.cb-template-browser--modal\.cb-template-browser--workspace\s*\{ min-height: 0; \}/);
});
