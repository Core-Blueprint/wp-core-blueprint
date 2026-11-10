import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../assets/js/core/modal.js', import.meta.url), 'utf8');
class Node {
    constructor(tag) {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.attributes = {};
        this.dataset = {};
        this.events = new Map();
        this.className = '';
        this.disabled = false;
        this.open = false;
        this.isConnected = true;
        this.value = '';
        this.classList = {
            add: value => { this.className += ' ' + value; },
            remove: value => { this.className = this.className.replace(' ' + value, ''); },
            contains: value => this.className.split(' ').includes(value),
            toggle: (value, enabled) => {
                if (enabled) this.classList.add(value);
                else this.classList.remove(value);
            },
        };
    }
    appendChild(item) { this.children.push(item); return item; }
    replaceChildren(...items) { this.children = items; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(name, fn) { this.events.set(name, fn); }
    querySelector(selector) {
        if (selector === '.cb-core-button__label') {
            return this.children.find(child => child.className === 'cb-core-button__label') || null;
        }
        return null;
    }
    focus() {}
    showModal() { this.open = true; }
    close() { this.open = false; }
    remove() { this.isConnected = false; }
    click() { return this.events.get('click')?.(); }
}

function modalHarness() {
    const body = new Node('body');
    const document = {
        body, activeElement: null,
        createElement: tag => new Node(tag),
        getElementById: () => null,
    };
    const window = {};
    const executable = source
        .replace("import { create as createIcon } from './icon.js';", 'const createIcon = () => null;')
        .replace('export { show };', '').replace('export default modal;', '');
    runInNewContext(executable, {
        document, window, HTMLElement: Node, requestAnimationFrame: callback => callback(),
        console: { error: () => {} },
    }, { timeout: 1000 });
    return { show: window.cbCore.modal.show, body };
}

test('dynamic modal confirm gate is disabled until a valid selection and updates its label', async () => {
    const { show, body } = modalHarness();
    let enabled = false, refresh, called = 0;
    const outcome = show({
        title: 'Browser', body: 'Select one', size: 'fullscreen',
        confirmLabel: 'Use template',
        confirmEnabled: () => enabled,
        onReady: ({ updateConfirmAction }) => { refresh = updateConfirmAction; },
        onConfirm: async () => { called++; return true; },
    });
    const dialog = body.children[0], confirm = dialog._cbConfirmBtn;
    assert.equal(confirm.disabled, true);
    assert.equal(confirm.textContent, 'Use template');
    assert.equal(typeof refresh, 'function');
    refresh('Open design');
    assert.equal(confirm.textContent, 'Open design');
    await confirm.click(); // programmatic click cannot bypass a blocked gate
    assert.equal(called, 0);
    enabled = true;
    refresh();
    assert.equal(confirm.disabled, false);
    await confirm.click();
    assert.equal(await outcome, true);
    assert.equal(called, 1);
});

test('a throwing external confirm gate fails closed, then recovers via refresh', async () => {
    const { show, body } = modalHarness();
    let throwGate = true, refresh;
    const outcome = show({
        title: 'Browser', confirmEnabled: () => {
            if (throwGate) throw new Error('not ready');
            return true;
        },
        onReady: ({ updateConfirmAction }) => { refresh = updateConfirmAction; },
    });
    const confirm = body.children[0]._cbConfirmBtn;
    assert.equal(confirm.disabled, true);
    throwGate = false;
    refresh();
    assert.equal(confirm.disabled, false);
    await confirm.click();
    assert.equal(await outcome, true);
});

test('pending confirmation remains non-dismissable and externally disabled', async () => {
    const { show, body } = modalHarness();
    let finish, refresh;
    const outcome = show({
        title: 'Browser', body: 'Choose',
        size: 'fullscreen',
        onReady: ({ updateConfirmAction }) => { refresh = updateConfirmAction; },
        confirmEnabled: () => true,
        onConfirm: () => new Promise(resolve => { finish = resolve; }),
    });
    const dialog = body.children[0], button = dialog._cbConfirmBtn;
    assert.equal(button.disabled, false);
    const inFlight = button.click();
    assert.equal(button.disabled, true);
    assert.equal(dialog._cbHeaderCloseBtn.disabled, true);
    refresh('Open design');
    assert.equal(button.disabled, true, 'pending state cannot be overridden');
    finish(false);
    await inFlight;
    assert.equal(button.disabled, false);
    assert.equal(dialog._cbHeaderCloseBtn.disabled, false);
    await dialog._cbHeaderCloseBtn.click();
    assert.equal(await outcome, false);
});

test('ordinary non-extended modals keep an enabled confirmation button', async () => {
    const { show, body } = modalHarness();
    const outcome = show({ title: 'Ordinary dialog' });
    assert.equal(body.children[0]._cbConfirmBtn.disabled, false);
    await body.children[0]._cbConfirmBtn.click();
    assert.equal(await outcome, true);
});
