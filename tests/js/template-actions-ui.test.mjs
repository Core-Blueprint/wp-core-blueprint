import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
    new URL('../../assets/js/core/template-actions.js', import.meta.url), 'utf8'
);

class Element {
    constructor(tag) {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.attributes = {};
        this.events = new Map();
        this.hidden = false;
        this.disabled = false;
        this.isConnected = true;
        this.textContent = '';
        this.value = '';
        this.className = '';
    }
    append(...children) { this.children.push(...children); }
    appendChild(child) { this.append(child); return child; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(name, handler) { this.events.set(name, handler); }
    removeEventListener(name) { this.events.delete(name); }
    contains(target) { return this === target || this.children.some(child => child.contains(target)); }
    focus() { this.focused = true; }
    remove() { this.isConnected = false; }
    click() { this.clicked = true; return this.events.get('click')?.({ target: this }); }
    key(name) {
        return this.events.get('keydown')?.({
            key: name, target: this, preventDefault() {}, stopPropagation() {},
        });
    }
    querySelector(selector) {
        if (selector !== 'a, button') return null;
        return this.children.find(child => ['A', 'BUTTON'].includes(child.tagName)) || null;
    }
}
const flush = () => new Promise(resolve => setImmediate(resolve));

function harness({ modal = null, response = null } = {}) {
    const requests = [], notices = [], changes = [], modals = [], blobs = [];
    let active = true;
    const docEvents = new Map();
    const document = {
        body: new Element('body'),
        createElement: tag => new Element(tag),
        addEventListener: (event, listener) => docEvents.set(event, listener),
        removeEventListener: (event, listener) => {
            if (docEvents.get(event) === listener) docEvents.delete(event);
        },
    };
    class FakeURL extends URL {
        static createObjectURL(blob) { blobs.push(blob); return 'blob:template-export'; }
        static revokeObjectURL() {}
    }
    const sandbox = {
        document, window: { location: {
            origin: 'https://example.test', href: 'https://example.test/wp-admin/admin.php',
        } },
        URL: FakeURL, Blob, TextEncoder, setTimeout: callback => callback(),
        fetch: async (url, options) => {
            requests.push({url, options});
            return response ? response(url, options) : {ok: true, json: async () => ({status:'renamed',revision:4})};
        },
        modalHook: async opts => {
            modals.push(opts);
            return modal ? modal(opts) : opts.onConfirm();
        },
    };
    const executable = source
        .replace("import { show as showModal } from '@cb-core/modal';", 'const showModal = globalThis.modalHook;')
        .replace("import { create as createIcon } from '@cb-core/icon';", 'const createIcon = () => null;')
        .replace('export function createActionMenu', 'function createActionMenu') +
        '\nglobalThis.__createActionMenu = createActionMenu;';
    runInNewContext(executable, sandbox, { timeout: 1000 });
    const baseOptions = {
        restRoot: '/wp-json/core-blueprint/v1/template-library/',
        nonce: 'nonce', contextToken: '', globalMode: false,
        label: (_key, fallback) => fallback,
        isCurrent: () => active,
        onOpening: () => {},
        onNotice: note => notices.push(note),
        onChanged: async note => { changes.push(note); },
    };
    const item = {
        id: 'saved.one', title: 'Saved one', source: 'core-blueprint-mail-saved',
        version: '3', actions: ['rename','duplicate','export-json','delete'],
    };
    return {
        create: (entry = item, props = {}) =>
            sandbox.__createActionMenu(entry, {...baseOptions, ...props}),
        item, requests, notices, changes, modals, blobs, document, docEvents,
        deactivate: () => { active = false; },
    };
}

test('TL-M2: no control without live allowlisted authority and an integer revision', () => {
    const h = harness();
    assert.equal(h.create({...h.item, actions: []}), null);
    assert.equal(h.create({...h.item, version: '1.2.3'}), null);
    assert.equal(h.create({...h.item, version: '9007199254740992'}), null);
    assert.equal(h.create({...h.item, actions: ['edit', 'import-zip']}), null);
});

test('TL-1C polish: Preview-only provider has one read-only menu action and restores trigger focus', async () => {
    const h = harness();
    let previewAnchor = null;
    const only = {...h.item, actions: [], navigation: undefined, version: '1'};
    assert.equal(h.create(only), null, 'no provider capability means no menu');
    assert.equal(h.create(only, {previewAvailable: true}), null,
        'a hint without a Browser-owned Preview callback grants nothing');
    const menu = h.create(only, {
        previewAvailable: true, onPreview: anchor => { previewAnchor = anchor; },
    });
    assert.ok(menu, 'Preview alone warrants the existing accessible Actions menu');
    // Preview uses a display version, not the CAS integer revision required
    // for mutable management operations.
    const semver = h.create({...only, version: '1.2.3'}, {
        previewAvailable: true, onPreview: () => {},
    });
    assert.ok(semver);
    semver.dispose();
    const [trigger, panel] = menu.node.children;
    assert.equal(panel.children.length, 1);
    assert.equal(panel.children[0].textContent, 'Preview');
    assert.equal(panel.children[0].attributes.role, 'menuitem');
    trigger.click();
    assert.equal(panel.hidden, false);
    panel.children[0].click();
    await flush();
    assert.equal(previewAnchor, trigger, 'Browser receives the stable Actions trigger');
    assert.equal(trigger.focused, true);
    assert.equal(panel.hidden, true);
    assert.equal(h.requests.length, 0, 'Preview does not POST to management');
    assert.equal(h.modals.length, 0, 'existing Browser owns preview, not management modal');
    menu.dispose();
});

test('TL-1C polish: stale Preview menu cannot invoke Browser callback after actor revalidation', () => {
    const h = harness();
    let previews = 0;
    const menu = h.create({...h.item, actions: []}, {
        previewAvailable: true, onPreview: () => { previews++; },
    });
    menu.node.children[0].click();
    h.deactivate();
    menu.node.children[1].children[0].click();
    assert.equal(previews, 0);
    assert.equal(h.requests.length, 0);
    menu.dispose();
});

test('TL-M2: contextual actions do not reveal global Manage navigation; unsafe links are rejected', () => {
    const h = harness();
    const item = {...h.item, actions: [], navigation: {
        preview: {url: 'https://example.test/wp-admin/admin.php?page=preview', label: 'Preview'},
        manage: {url: 'https://example.test/wp-admin/admin.php?page=manage', label: 'Manage'},
    }};
    assert.equal(h.create(item, {globalMode: false}), null,
        'Designer navigation must remain owned by Exit Guard, not the Browser');
    const global = h.create(item, {globalMode: true});
    assert.equal(global.node.children[1].children.length, 2);
    assert.equal(h.create({...item, navigation: {
        manage: {url: 'https://other.test/wp-admin/admin.php', label: 'Unsafe'},
    }}, {globalMode: true}), null);
});

test('TL-M2: Actions opens an accessible disclosure, supports Escape and disposes outside listeners', () => {
    const h = harness();
    const menu = h.create();
    const [trigger, panel] = menu.node.children;
    assert.equal(trigger.attributes['aria-expanded'], 'false');
    assert.equal(panel.hidden, true);
    trigger.click();
    assert.equal(trigger.attributes['aria-expanded'], 'true');
    assert.equal(panel.hidden, false);
    assert.equal(h.docEvents.has('pointerdown'), true);
    h.docEvents.get('keydown')?.({ key: 'Escape', preventDefault() {}, stopPropagation() {} });
    assert.equal(panel.hidden, true);
    assert.equal(trigger.focused, true);
    assert.equal(h.docEvents.has('pointerdown'), false);
    trigger.click();
    menu.dispose();
    assert.equal(h.docEvents.has('focusin'), false);
    assert.equal(trigger.attributes['aria-expanded'], 'false');
});

test('TL-M2: Rename posts only the exact provider identity, CAS revision, nonce and Designer scope', async () => {
    const h = harness();
    const menu = h.create(undefined, {contextToken: 'signed-scope'});
    menu.node.children[0].click();
    menu.node.children[1].children[0].click();
    await flush(); await flush();
    assert.equal(h.modals.length, 1);
    assert.equal(h.requests.length, 1);
    const req = h.requests[0];
    assert.equal(req.url, '/wp-json/core-blueprint/v1/template-library/manage');
    assert.equal(req.options.method, 'POST');
    assert.equal(req.options.credentials, 'same-origin');
    assert.equal(req.options.headers['X-WP-Nonce'], 'nonce');
    assert.equal(req.options.headers['X-CB-Designer-Scope'], 'signed-scope');
    assert.deepEqual(JSON.parse(req.options.body), {
        source: h.item.source, templateId: h.item.id, action: 'rename',
        revision: 3, input: {title: 'Saved one'},
    });
    assert.equal(h.changes.length, 1);
    assert.equal(h.modals[0].confirmVariant, 'primary');
});

test('TL-M2: Delete uses danger acknowledgement and refreshes on stale CAS conflict', async () => {
    const h = harness({response: async () => ({
        ok: false, json: async () => ({status: 'conflict'}),
    })});
    const menu = h.create();
    menu.node.children[0].click();
    menu.node.children[1].children[3].click();
    await flush(); await flush();
    assert.equal(h.modals[0].confirmVariant, 'danger');
    assert.equal(typeof h.modals[0].confirmCheck.label, 'string');
    assert.equal(h.requests.length, 1);
    assert.equal(JSON.parse(h.requests[0].options.body).action, 'delete');
    assert.deepEqual(JSON.parse(h.requests[0].options.body).input, {});
    assert.equal(h.changes.length, 1);
    assert.match(h.changes[0], /changed elsewhere/);
});

test('TL-M2: Export returns provider JSON as a download, never inserts data as HTML', async () => {
    const h = harness({response: async () => ({
        ok: true, json: async () => ({status:'ready',data:'{"template":"safe"}'}),
    })});
    const menu = h.create();
    menu.node.children[0].click();
    menu.node.children[1].children[2].click();
    await flush(); await flush();
    assert.equal(h.requests.length, 1);
    assert.equal(JSON.parse(h.requests[0].options.body).action, 'export-json');
    assert.equal(h.blobs.length, 1);
    const download = h.document.body.children.find( child => child.tagName === 'A' );
    assert.equal(download.download, 'core-blueprint-template-saved.one.json');
    assert.equal(download.clicked, true);
    assert.equal(h.modals.length, 0);
});

test('TL-M2: stale or destroyed Browser cards cannot issue a management request', async () => {
    const h = harness();
    const menu = h.create();
    menu.node.children[0].click();
    h.deactivate();
    menu.node.children[1].children[2].click();
    await flush();
    assert.equal(h.requests.length, 0);
    menu.dispose();
});
