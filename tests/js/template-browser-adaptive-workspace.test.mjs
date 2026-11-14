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

function harness(modalHook = async () => false, fixtures = {}) {
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
            const query = new URL(url, 'https://example.test');
            const fallbackSources = [ { id: 'core', extension: 'core-blueprint', designer: 'mail',
                categories: [ { id: 'mail', label: 'Mail' } ] } ];
            const fallbackPage = { items: [ { id: 'template-1', title: 'Authorized template',
                source: 'core', origin: 'built-in', category: 'mail', designer: 'mail', version: '2.1',
                compatible: true, description: 'Reusable content' } ], next_cursor: null };
            return { ok: true, json: async () => query.pathname.endsWith('/sources')
                ? { sources: fixtures.sources || fallbackSources }
                : typeof fixtures.templatePage === 'function'
                    ? fixtures.templatePage(query.searchParams) : fallbackPage,
            };
        },
    };
    const executable = browserSource
        .replace("import { show as showModal } from '@cb-core/modal';", 'const showModal = globalThis.modalHook;')
        .replace("import { createActionMenu } from '@cb-core/template-actions';", 'const createActionMenu = () => null;')
        .replace("import { createImportControl } from '@cb-core/template-resource-import';", 'const createImportControl = () => null;')
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
    const categoryField = toolbar.children[3];
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
    assert.equal(toolbar.children[3].children[0].textContent, 'Source');
    assert.equal(toolbar.children[3].children[1].children[0].textContent, 'All');
    assert.equal(toolbar.children[4].children[0].textContent, 'Plugin');
    assert.equal(toolbar.children[5].children[0].textContent, 'Designer type');
    assert.equal(toolbar.children[6].children[0].textContent, 'Category');
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
        const grid = contextual ? content.children[2].children[0] : content.children[2];
        const card = grid.children[0];
        const body = card.children.find(child =>
            child.className.includes('cb-template-browser__card-body'));
        assert.ok(body);
        const meta = body.children.find(child =>
            child.className.includes('cb-template-browser__card-meta'));
        assert.deepEqual(meta.children.map(child => child.textContent), ['Built-in', 'Mail', 'Mail'],
            'known IDs have readable captions; provider identity and provenance stay unchanged');
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

test('TL-1B2 polish: Search and Sort keep visible native labels in both Browser contexts', async () => {
    for (const contextual of [ false, true ]) {
        const { api } = harness(undefined, { sources: [
            { id: 'core', extension: 'core-blueprint', designer: 'mail',
                sorts: [ 'title-asc' ], categories: [ { id: 'mail', label: 'Mail' } ] },
        ] });
        const root = new Element('div');
        const instance = api.createBrowser(root, contextual ? {
            mode: 'designer', contextToken: 'server-issued-scope',
            designs: [ { id: 'design-1', title: 'Existing', provider: 'WordPress' } ],
        } : {});
        await flush();
        const shell = root.children[0];
        const content = shell.children[contextual ? 2 : 1];
        const toolbar = content.children[0];
        const searchField = toolbar.children[1];
        const sortField = toolbar.children[2];
        assert.equal(searchField.tagName, 'LABEL', 'Search preserves its native label semantics');
        assert.equal(sortField.tagName, 'LABEL', 'Sort preserves its native label semantics');
        assert.equal(searchField.children[1].tagName, 'INPUT');
        assert.equal(sortField.children[1].tagName, 'SELECT');
        assert.equal(sortField.children[0].textContent, 'Sort by');
        assert.equal(searchField.children[0].textContent, contextual ? 'Search designs' : 'Search templates');
        assert.equal(searchField.children[1].attributes['aria-label'],
            searchField.children[0].textContent, 'label and input accessible name remain in sync');
        if ( contextual ) {
            assert.equal(sortField.hidden, true, 'Designs view never offers template sort');
            shell.children[0].children[1].events.get('click')();
            await flush();
            assert.equal(searchField.children[0].textContent, 'Search templates',
                'label changes with scoped view, not a hard-coded visible caption');
            assert.equal(sortField.hidden, false);
        } else {
            assert.equal(sortField.hidden, false);
        }
        instance.destroy();
    }
});

test('TL-1B2: global sort is gated by all eligible backend capabilities and uses signed paging', async () => {
    const sources = [
        { id: 'core-a', extension: 'core-blueprint', designer: 'mail', sorts: ['title-asc'], categories: [] },
        { id: 'core-b', extension: 'core-blueprint', designer: 'mail', sorts: ['title-asc'], categories: [] },
    ];
    const { api, calls } = harness(undefined, { sources, templatePage: params => {
        const sorted = params.get('sort') === 'title-asc';
        const cursor = params.get('cursor');
        return {
            items: [ { id: cursor ? 'second' : 'first', title: sorted
                ? cursor ? 'Beta' : 'Alpha' : 'Default',
                source: 'core-a', origin: 'built-in', category: 'mail', designer: 'mail',
                version: '1', compatible: true } ],
            next_cursor: sorted && !cursor ? 'opaque-signed-position' : null,
        };
    } });
    const root = new Element('div');
    const instance = api.createBrowser(root);
    await flush();
    const content = root.children[0].children[1];
    const toolbar = content.children[0];
    const sortField = toolbar.children[2], select = sortField.children[1];
    assert.match(sortField.className, /cb-template-browser__field--sort/);
    assert.equal(sortField.hidden, false);
    assert.equal(sortField.children[0].textContent, 'Sort by');
    assert.equal(toolbar.children[1].children[1].tagName, 'INPUT', 'Search is before sort in Tab order');
    assert.equal(toolbar.children[3].children[1].tagName, 'SELECT', 'Sort is before source filters in Tab order');
    assert.deepEqual(select.children.map(item => item.textContent),
        ['Source order', 'Title (exact, case-sensitive)']);
    assert.equal(select.value, '', 'default source order remains unchanged');
    select.value = 'title-asc';
    select.events.get('change')();
    await flush();
    assert.ok(calls.some(url => url.includes('sort=title-asc')), 'sorted URL is explicitly opted in');
    assert.equal(content.children[2].children[0].children[1].children[0].textContent, 'Alpha');
    const more = content.children[3].children[1];
    assert.equal(more.hidden, false);
    more.events.get('click')();
    await flush();
    assert.ok(calls.some(url => url.includes('cursor=opaque-signed-position') && url.includes('sort=title-asc')),
        'Load more retains the server-owned cursor and sort');
    assert.equal(content.children[2].children.length, 2);
    assert.equal(more.hidden, true);
    select.value = '';
    select.events.get('change')();
    await flush();
    assert.equal(content.children[2].children.length, 1,
        'changing sort resets the prior result page instead of locally reordering');
    assert.equal(content.children[2].children[0].children[1].children[0].textContent, 'Default');
    assert.equal(new URL(calls.at(-1), 'https://example.test').searchParams.has('sort'), false);
    assert.equal(new URL(calls.at(-1), 'https://example.test').searchParams.has('cursor'), false);
    instance.destroy();
});

test('TL-1B2: unsupported and oversized source sets never claim global sorting', async () => {
    for (const sources of [
        [ { id: 'supported', extension: 'core-blueprint', designer: 'mail', sorts: ['title-asc'] },
            { id: 'unsupported', extension: 'core-blueprint', designer: 'reports', sorts: [] } ],
        Array.from({ length: 13 }, (_, index) => ({
            id: 'many-' + index, extension: 'core-blueprint', designer: 'mail', sorts: ['title-asc'],
        })),
        [],
    ]) {
        const { api, calls } = harness(undefined, { sources });
        const root = new Element('div');
        const instance = api.createBrowser(root);
        await flush();
        const content = root.children[0].children[1], toolbar = content.children[0];
        const field = toolbar.children[2], sort = field.children[1];
        assert.equal(field.hidden, true, 'All means every matching authorized source');
        assert.equal(sort.disabled, true);
        assert.ok(!calls.some(url => url.includes('sort=title-asc')));
        if (sources.length === 2) {
            const type = toolbar.children[5].children[1];
            type.value = 'mail';
            type.events.get('change')();
            await flush();
            assert.equal(field.hidden, false, 'Designer filter narrows the actual backend source set');
            sort.value = 'title-asc';
            sort.events.get('change')();
            await flush();
            assert.ok(calls.some(url => url.includes('designer=mail') && url.includes('sort=title-asc')));
            type.value = 'reports';
            type.events.get('change')();
            await flush();
            assert.equal(field.hidden, true, 'unsupported filter revokes sort option');
            assert.equal(sort.value, '', 'selected sort state clears before refresh');
            assert.equal(new URL(calls.at(-1), 'https://example.test').searchParams.has('sort'), false,
                'a new query cannot reuse the old sorted cursor/identity');
        }
        instance.destroy();
    }
});

test('TL-1B2: scoped sort respects Designs mode and clears selection on ordering changes', async () => {
    const sources = [ {
        id: 'core', extension: 'core-blueprint', designer: 'mail',
        sorts: ['title-asc'], categories: [ { id: 'mail', label: 'Mail' } ],
    } ];
    const { api, calls } = harness(undefined, { sources });
    const root = new Element('div');
    const instance = api.createBrowser(root, {
        mode: 'designer', contextToken: 'server-issued-scope',
        designs: [ { id: 'existing', title: 'Existing design', provider: 'WordPress' } ],
    });
    await flush();
    const shell = root.children[0], content = shell.children[2], toolbar = content.children[0];
    const sortField = toolbar.children[2], sort = sortField.children[1];
    assert.equal(shell.dataset.browserView, 'designs');
    assert.equal(sortField.hidden, true, 'sorting is for templates only');
    shell.children[0].children[1].events.get('click')();
    await flush();
    assert.equal(sortField.hidden, false);
    const grid = content.children[2].children[0];
    const radio = grid.children[0].children[0];
    radio.checked = true;
    radio.events.get('change')();
    assert.ok(instance.selected(), 'template selection exists before changing sort');
    sort.value = 'title-asc';
    sort.events.get('change')();
    await flush();
    assert.equal(instance.selected(), null, 'changing global order invalidates scoped selection');
    assert.ok(calls.some(url => url.includes('sort=title-asc')));
    shell.children[0].children[0].events.get('click')();
    await flush();
    assert.equal(sortField.hidden, true, 'Designs never display a template sort');
    shell.children[0].children[1].events.get('click')();
    await flush();
    assert.equal(sortField.hidden, false);
    assert.equal(sort.value, 'title-asc', 'template choice persists through Designs navigation');
    instance.destroy();
});

test('TL-1B2: sort has a responsive, semantic presentation in both Browser modes', () => {
    assert.match(browserSource, /const sortField = el\( 'label', 'cb-template-browser__field cb-template-browser__field--sort' \)/);
    assert.match(browserSource, /sortSelect\.dataset\.filter = 'sort'/);
    assert.match(browserSource, /eligible\.length <= 12/);
    assert.match(styles, /TL-1B2 — one truthful, provider-gated sort control/);
    assert.match(styles, /\.cb-template-browser--modal\.cb-template-browser--workspace \.cb-template-browser__field--sort/);
    assert.match(styles, /\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__field--sort/);
    assert.match(styles, /@media \(max-width: 720px\)[\s\S]*?\.cb-template-browser--modal\.cb-template-browser--workspace \.cb-template-browser__field--sort/);
    assert.match(browserSource, /toolbar\.prepend\( resultHeading, searchField, sortField \)/,
        'native tab order must match the visual ordering');
    assert.doesNotMatch(browserSource, /state\.entries\.sort\(/,
        'client-side sorting a paginated result set would be incorrect');
    assert.match(styles, /@media \(max-width: 720px\)[\s\S]*?\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__field--search\s*\{\s*grid-row: 2;/,
        'mobile search must not overlap the heading');
    assert.match(styles, /@media \(max-width: 720px\)[\s\S]*?\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__field--sort\s*\{[^}]*grid-row: 3;/,
        'mobile sort follows search before the four source facets');
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
    assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__field\s*\{[^}]*display: grid;[^}]*align-self: stretch;[^}]*grid-template-rows: auto minmax\(40px, auto\);/,
        'all Workspace controls share label and input tracks');
    assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__field > span\s*\{[^}]*position: static;[^}]*overflow: visible;[^}]*font-weight: 600;/,
        'the existing accessible label is also visible in global and contextual Browsers');
    assert.doesNotMatch(styles, /\.cb-template-browser--workspace \.cb-template-browser__field > span\s*\{[^}]*position: absolute;/,
        'search must not remain visually hidden');
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
    assert.match(styles, /\.cb-template-browser--workspace \.cb-template-browser__field > span\s*\{[^}]*position: static;/,
        'visible labels are owned once by the shared Workspace, not the global-only CSS');
    assert.match(styles, /\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__card-preview\s*\{[^}]*height: 142px;/);
    assert.match(styles, /@media \(max-width: 480px\)\s*\{[^}]*\.cb-template-library-page \.cb-template-browser--workspace \.cb-template-browser__toolbar/);
    assert.match(styles, /\.cb-template-browser--workspace\.cb-template-browser--with-sections \.cb-template-browser__content\s*\{[^}]*grid-row: auto;[^}]*grid-column: 1;/);
    assert.match(styles, /\.cb-template-browser--workspace\.cb-template-browser--with-sections \.cb-template-browser__collections\s*\{[^}]*grid-row: auto;[^}]*grid-column: 1;/);
    assert.match(styles, /\.cb-template-browser--modal\.cb-template-browser--workspace\s*\{ min-height: 0; \}/);
});
