import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';

const moduleSource = await readFile(new URL('../../assets/js/design/shell/page-navigation.js', import.meta.url), 'utf8');
const {configureDesignerPageNavigation} = await import(
	'data:text/javascript;charset=utf-8,' + encodeURIComponent(moduleSource)
);
const editorSource = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
const shellSource = await readFile(new URL('../../assets/js/design/shell/index.js', import.meta.url), 'utf8');
const assetSource = await readFile(new URL('../../src/Design/Editor/Assets.php', import.meta.url), 'utf8');

class ClassList {
	constructor() {this.values = new Set();}
	add(...names) {names.forEach(name => this.values.add(name));}
	remove(...names) {names.forEach(name => this.values.delete(name));}
	contains(name) {return this.values.has(name);}
}

class FakeNode extends EventTarget {
	constructor(doc, tag = 'div') {
		super();
		this.ownerDocument = doc;
		this.tagName = tag;
		this.children = [];
		this.parentElement = null;
		this.attributes = new Map();
		this.classList = new ClassList();
		this.style = {values: new Map(), setProperty(name, value) {this.values.set(name, value);}};
		this.isConnected = true;
		this.hidden = false;
		this.disabled = false;
		this.textContent = '';
		this.dataset = {};
	}
	append(...nodes) {
		for (const node of nodes) {
			if (node.tagName === '#fragment') {
				this.append(...[...node.children]);
				node.children = [];
			} else {
				node.parentElement = this;
				node.isConnected = this.isConnected;
				this.children.push(node);
			}
		}
	}
	replaceChildren(...nodes) {
		for (const child of this.children) child.parentElement = null;
		this.children = [];
		this.append(...nodes);
	}
	remove() {
		if (this.parentElement) {
			this.parentElement.children = this.parentElement.children.filter(node => node !== this);
			this.parentElement = null;
		}
		this.isConnected = false;
	}
	setAttribute(name, value) {this.attributes.set(name, String(value));}
	getAttribute(name) {return this.attributes.get(name) ?? null;}
	removeAttribute(name) {this.attributes.delete(name);}
	contains(target) {return target === this || this.children.some(child => child.contains(target));}
	closest(selector) {
		let current = this;
		while (current) {
			if (selector === '.cb-core-design-shell__toolbar--designer'
				&& current.classList.contains('cb-core-design-shell__toolbar--designer')) return current;
			current = current.parentElement;
		}
		return null;
	}
	getBoundingClientRect() {
		return this.tagName === 'button'
			? {left: 500, top: 20, bottom: 64, width: 44, height: 44}
			: {left: 0, top: 0, bottom: 200, width: 230, height: 200};
	}
	focus() {this.ownerDocument.activeElement = this;}
}

class FakeDocument extends EventTarget {
	constructor() {
		super();
		this.defaultView = new EventTarget();
		this.defaultView.innerWidth = 1280;
		this.defaultView.innerHeight = 800;
		this.documentElement = {clientWidth: 1280, clientHeight: 800};
		this.activeElement = null;
	}
	createElement(tag) {return new FakeNode(this, tag);}
	createDocumentFragment() {return new FakeNode(this, '#fragment');}
}

globalThis.Element = FakeNode;

const fixture = (signal = null) => {
	const document = new FakeDocument();
	const root = new FakeNode(document);
	const toolbar = new FakeNode(document);
	toolbar.classList.add('cb-core-design-shell__toolbar--designer');
	const group = new FakeNode(document);
	const trigger = new FakeNode(document, 'button');
	root.append(toolbar);
	toolbar.append(group);
	group.append(trigger);
	root.querySelector = selector => selector === '[data-cb-design-shell-page-navigation]' ? trigger : null;
	const controller = configureDesignerPageNavigation(root, {signal});
	const menu = group.children[1];
	return {document, root, toolbar, group, trigger, menu, controller};
};

const page = (id, label = id) => ({id, label});
const event = (type, key = null) => {
	const result = new Event(type, {bubbles: true, cancelable: true});
	if (key !== null) Object.defineProperty(result, 'key', {value: key});
	return result;
};

test('DP-NAV-01 unknown/zero/one page never opens or changes document width', () => {
	const {root, trigger, menu, controller} = fixture();
	root.dataset.cbDesignShellDocumentView = 'fit';
	assert.equal(trigger.disabled, true);
	assert.equal(trigger.getAttribute('aria-expanded'), 'false');
	trigger.dispatchEvent(event('click'));
	assert.equal(menu.hidden, true);
	assert.equal(controller.open, false);
	controller.update({pages: [], activePageId: '', onNavigate: () => {}});
	assert.equal(trigger.disabled, true);
	controller.update({pages: [page('one', 'Page 1')], activePageId: 'one', onNavigate: () => {}});
	assert.equal(trigger.disabled, true);
	assert.equal(trigger.getAttribute('aria-label'), 'Page 1 of 1');
	trigger.dispatchEvent(event('click'));
	assert.equal(menu.hidden, true);
	assert.equal(root.dataset.cbDesignShellDocumentView, 'fit');
	controller.destroy();
});

test('DP-NAV-01 only verified multi-page data with a callback activates the page dropdown', () => {
	const {trigger, menu, controller, document} = fixture();
	const calls = [];
	controller.update({pages: [page('p-1', 'Page 1'), page('p-2', 'Page 2')], activePageId: 'p-1'});
	assert.equal(trigger.disabled, true, 'no navigation authority yet');
	controller.update({
		pages: [page('p-1', 'Page 1'), page('p-2', 'Page 2')],
		activePageId: 'p-1',
		onNavigate: intent => calls.push(intent),
	});
	assert.equal(trigger.disabled, false);
	assert.equal(trigger.getAttribute('aria-haspopup'), 'menu');
	assert.equal(trigger.getAttribute('aria-controls'), menu.id);
	trigger.dispatchEvent(event('click'));
	assert.equal(menu.hidden, false);
	assert.equal(trigger.getAttribute('aria-expanded'), 'true');
	assert.equal(document.activeElement, menu.children[0]);
	assert.equal(menu.children[0].getAttribute('aria-checked'), 'true');
	assert.equal(menu.children[1].getAttribute('aria-checked'), 'false');
	menu.children[1].dispatchEvent(event('click'));
	assert.deepEqual(calls, [{pageId: 'p-2', pageIndex: 1, totalPages: 2}]);
	assert.equal(menu.hidden, true);
	assert.equal(document.activeElement, trigger);
	assert.equal(controller.activePageId, 'p-1', 'consumer must confirm navigation');
	assert.equal(controller.setActivePage('p-2'), true);
	assert.equal(menu.children[1].getAttribute('aria-checked'), 'true');
	assert.equal(controller.setActivePage('unverified'), false);
	controller.destroy();
});

test('DP-NAV-01 validates descriptors and preserves a previous valid state atomically', () => {
	const {controller, trigger, menu} = fixture();
	const original = {pages: [page('p1', 'Page 1'), page('p2', 'Page 2')], activePageId: 'p1', onNavigate: () => {}};
	controller.update(original);
	for (const invalid of [
		{...original, pages: [page('p1'), page('p1')]},
		{...original, pages: [page('p1', ''), page('p2')]},
		{...original, pages: [page('bad id'), page('p2')]},
		{...original, pages: [page('p1', 'x\nInjected'), page('p2')]},
		{...original, pages: Array.from({length: 501}, (_, i) => page('p' + i))},
		{...original, activePageId: 'missing'},
		{...original, onNavigate: 'untrusted'},
		{...original, pages: 'unknown'},
	]) {
		assert.throws(() => controller.update(invalid), /DESIGNER_PAGE_NAVIGATION_/);
		assert.equal(controller.pages.length, 2);
		assert.equal(controller.activePageId, 'p1');
		assert.equal(trigger.disabled, false);
		assert.equal(menu.children.length, 2);
	}
	controller.update({pages: [page('p1', '<img src=x onerror=alert(1)>'), page('p2', 'Page 2')], activePageId: 'p1', onNavigate: () => {}});
	assert.equal(menu.children[0].textContent, '<img src=x onerror=alert(1)>', 'label is inert text');
	controller.destroy();
});

test('DP-NAV-01 focus, arrow navigation, Escape, veto and stale page callbacks', () => {
	const {controller, document, trigger, menu} = fixture();
	const calls = [];
	controller.update({
		pages: [page('a'), page('b'), page('c')],
		activePageId: 'a', onNavigate: intent => {calls.push(intent); return false;},
	});
	trigger.dispatchEvent(event('click'));
	menu.dispatchEvent(event('keydown', 'ArrowDown'));
	assert.equal(document.activeElement, menu.children[1]);
	menu.dispatchEvent(event('keydown', 'End'));
	assert.equal(document.activeElement, menu.children[2]);
	menu.dispatchEvent(event('keydown', 'Home'));
	assert.equal(document.activeElement, menu.children[0]);
	menu.children[1].dispatchEvent(event('click'));
	assert.equal(calls.length, 1);
	assert.equal(menu.hidden, false, 'veto keeps the menu open');
	menu.dispatchEvent(event('keydown', 'Escape'));
	assert.equal(menu.hidden, true);
	assert.equal(document.activeElement, trigger);
	const stale = menu.children[1];
	controller.update({pages: [page('new'), page('second')], activePageId: 'new', onNavigate: () => calls.push('new')});
	trigger.dispatchEvent(event('click'));
	stale.dispatchEvent(event('click'));
	assert.equal(calls.length, 1, 'replaced menu callbacks are stale');
	menu.children[1].dispatchEvent(event('click'));
	assert.equal(calls.length, 2);
	controller.destroy();
});

test('DP-NAV-01 abort tears down menu, listeners and ARIA without changing document content', () => {
	const signal = new AbortController();
	const {controller, trigger, menu, group, root} = fixture(signal.signal);
	const same = configureDesignerPageNavigation(root);
	assert.equal(same, controller);
	controller.update({pages: [page('a'), page('b')], activePageId: 'a', onNavigate: () => {}});
	trigger.dispatchEvent(event('click'));
	assert.equal(controller.open, true);
	signal.abort();
	assert.equal(menu.isConnected, false);
	assert.equal(group.children.length, 1);
	assert.equal(trigger.getAttribute('aria-controls'), null);
	assert.equal(trigger.disabled, true);
	assert.equal(controller.update({pages: [], activePageId: ''}), false);
	trigger.dispatchEvent(event('click'));
	assert.equal(menu.hidden, true);
});

test('DP-NAV-01 supports exactly 500 bounded page descriptors, not 501', () => {
	const {controller, trigger, menu} = fixture();
	const pages = Array.from({length: 500}, (_, index) => page('page-' + index, 'Page ' + (index + 1)));
	controller.update({pages, activePageId: 'page-0', onNavigate: () => true});
	assert.equal(trigger.disabled, false);
	assert.equal(menu.children.length, 500);
	assert.equal(menu.children[0].getAttribute('aria-checked'), 'true');
	assert.throws(() => controller.update({
		pages: [...pages, page('page-500', 'Page 501')],
		activePageId: 'page-0', onNavigate: () => true,
	}), /DESIGNER_PAGE_NAVIGATION_PAGES_INVALID/);
	assert.equal(controller.pages.length, 500);
	controller.destroy();
});

test('DP-NAV-01 native Reports button no longer triggers width toggle and API is public', () => {
	assert.match(editorSource, /import \{ configureDesignerPageNavigation \} from '.\/shell\/page-navigation\.js'/);
	assert.match(editorSource, /centerZone\.append\(createDesignerPageNavigationControl\(doc\)\)/);
	assert.match(editorSource, /createPageNavigationControl: createDesignerPageNavigationControl/);
	assert.match(editorSource, /configureDesignerPageNavigation\(stagedRoot, \{signal\}\)/);
	assert.match(editorSource, /configurePageNavigation: configureDesignerPageNavigation/);
	assert.doesNotMatch(editorSource, /configureDesignerDocumentView\(stagedRoot/);
	assert.doesNotMatch(editorSource, /button\('Document view', 'data-cb-design-shell-document-view-toggle'\)/);
	assert.match(assetSource, /public const DESIGNER_PAGE_NAVIGATION_API = 1;/);
	assert.match(assetSource, /public const DESIGNER_PAGE_NAVIGATION_CHROME_API = 1;/);
	assert.match(shellSource, /root\.querySelector\?\.\('\.cb-core-design-shell__page-menu:not\(\[hidden\]\)'\)/);
});
