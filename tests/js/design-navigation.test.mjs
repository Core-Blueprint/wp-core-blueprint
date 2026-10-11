import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../assets/js/design/shell/navigation.js', import.meta.url), 'utf8');
const navigation = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));

class FakeElement extends EventTarget {
	constructor(documentRef, tagName) {
		super();
		this.ownerDocument = documentRef;
		this.tagName = tagName.toUpperCase();
		this.children = [];
		this.parentElement = null;
		this.attributes = new Map();
		this.dataset = {};
		this.className = '';
		this.classList = {
			values: new Set(),
			toggle(name, state) {
				if (state) this.values.add(name);
				else this.values.delete(name);
			},
		};
		this.textContent = '';
		this.hidden = false;
		this.tabIndex = 0;
		this.scrollCount = 0;
	}
	append(...nodes) {
		for (const node of nodes) {
			if (node.tagName === '#FRAGMENT') {
				this.append(...node.children.slice());
				node.children = [];
			} else {
				node.parentElement?.removeChild(node);
				this.children.push(node);
				node.parentElement = this;
			}
		}
	}
	removeChild(node) {
		this.children = this.children.filter((candidate) => candidate !== node);
		node.parentElement = null;
	}
	replaceChildren(...nodes) {
		this.children.forEach((child) => { child.parentElement = null; });
		this.children = [];
		this.append(...nodes);
	}
	remove() {
		this.parentElement?.removeChild(this);
	}
	setAttribute(name, value) {
		this.attributes.set(name, String(value));
	}
	getAttribute(name) {
		return this.attributes.get(name) ?? null;
	}
	hasAttribute(name) {
		return this.attributes.has(name);
	}
	removeAttribute(name) {
		this.attributes.delete(name);
	}
	contains(other) {
		return other === this || this.children.some((child) => child.contains(other));
	}
	closest(selector) {
		for (let node = this; node; node = node.parentElement) {
			if (selector === '[data-cb-design-shell]' && node.hasAttribute('data-cb-design-shell')) return node;
			if (selector === '[hidden]' && node.hidden) return node;
		}
		return null;
	}
	focus() {
		this.ownerDocument.activeElement = this;
	}
	scrollIntoView() {
		this.scrollCount += 1;
	}
}

class FakeDocument {
	constructor() {
		this.nodes = [];
		this.activeElement = null;
	}
	createElement(tagName) {
		const node = new FakeElement(this, tagName);
		this.nodes.push(node);
		return node;
	}
	createDocumentFragment() {
		return new FakeElement(this, '#FRAGMENT');
	}
	getElementById(id) {
		return this.nodes.find((node) => node.getAttribute('id') === id) || null;
	}
}

const fixture = () => {
	const documentRef = new FakeDocument();
	const root = documentRef.createElement('main');
	root.setAttribute('data-cb-design-shell', '');
	const first = documentRef.createElement('section');
	first.setAttribute('id', 'stage-when');
	const second = documentRef.createElement('section');
	second.setAttribute('id', 'stage-then');
	const external = documentRef.createElement('section');
	external.setAttribute('id', 'external-target');
	root.append(first, second);
	const items = [
		{ id: 'when', targetId: 'stage-when', step: '1', label: 'Wanneer', description: 'Trigger',
			status: { label: 'Vereist', tone: 'warning' } },
		{ id: 'then', targetId: 'stage-then', step: '2', label: 'Dan', description: 'Acties' },
	];
	return { documentRef, root, first, second, external, items };
};

const control = (nav, index) => nav.element.children[0].children[index].children[0];
const click = (node) => {
	const event = new Event('click', { cancelable: true });
	node.dispatchEvent(event);
	return event;
};

test('navigation roles extend the left rail without changing old Elements / Dynamic Data order', () => {
	assert.deepEqual(navigation.DESIGNER_PALETTE_ROLES, ['navigation', 'elements', 'dynamic-data']);
});

test('Navigation renders semantic, human-readable and scoped links with active state', () => {
	const f = fixture();
	const nav = navigation.createDesignerNavigation({
		documentRef: f.documentRef, root: f.root, items: f.items,
		activeId: 'when', ariaLabel: 'Workflowstappen',
	});
	f.root.append(nav.element);
	assert.equal(nav.element.tagName, 'NAV');
	assert.equal(nav.element.getAttribute('aria-label'), 'Workflowstappen');
	assert.equal(control(nav, 0).getAttribute('href'), '#stage-when');
	assert.equal(control(nav, 0).getAttribute('aria-current'), 'location');
	assert.equal(control(nav, 1).getAttribute('aria-current'), null);
	assert.equal(control(nav, 0).children[1].children[0].textContent, 'Wanneer');
	assert.equal(control(nav, 0).children[2].dataset.tone, 'warning');
	const event = click(control(nav, 1));
	assert.equal(event.defaultPrevented, true);
	assert.equal(f.documentRef.activeElement, f.second);
	assert.equal(f.second.scrollCount, 1);
	assert.equal(control(nav, 1).getAttribute('aria-current'), 'location');
	assert.equal(control(nav, 0).getAttribute('aria-current'), null);
	assert.equal(f.second.getAttribute('tabindex'), '-1');
	f.second.dispatchEvent(new Event('blur'));
	assert.equal(f.second.getAttribute('tabindex'), null);
	nav.destroy();
});

test('navigation cannot move focus to an element outside its Designer root', () => {
	const f = fixture();
	const items = [{ id: 'outside', targetId: 'external-target', label: 'External' }];
	const nav = navigation.createDesignerNavigation({ documentRef: f.documentRef, root: f.root, items });
	f.root.append(nav.element);
	click(control(nav, 0));
	assert.equal(f.external.scrollCount, 0);
	assert.equal(f.documentRef.activeElement, null);
	assert.equal(control(nav, 0).getAttribute('aria-current'), null);
});

test('consumer callback can cancel navigation, while update keeps translated labels and valid focus', () => {
	const f = fixture();
	let calls = 0;
	const nav = navigation.createDesignerNavigation({
		documentRef: f.documentRef, root: f.root, items: f.items,
		activeId: 'when', onNavigate: () => { calls += 1; return false; },
	});
	f.root.append(nav.element);
	click(control(nav, 1));
	assert.equal(calls, 1);
	assert.equal(control(nav, 0).getAttribute('aria-current'), 'location');
	assert.equal(f.second.scrollCount, 0);
	control(nav, 0).focus();
	assert.equal(nav.update([{ ...f.items[0], label: 'When' }, f.items[1]]), 2);
	assert.equal(f.documentRef.activeElement, control(nav, 0));
	assert.equal(control(nav, 0).children[1].children[0].textContent, 'When');
	assert.equal(nav.setActive('then'), true);
	assert.equal(nav.setActive('missing'), false);
	assert.equal(control(nav, 1).getAttribute('aria-current'), 'location');
	assert.throws(() => nav.update([f.items[0], f.items[0]]), /Duplicate/);
	assert.equal(nav.element.children[0].children.length, 2);
});

test('disabled navigation items are inert; target-free items require consumer routing', () => {
	const f = fixture();
	const items = [
		{ ...f.items[0], disabled: true },
		{ id: 'preview', label: 'Preview' },
	];
	const nav = navigation.createDesignerNavigation({ documentRef: f.documentRef, root: f.root, items });
	f.root.append(nav.element);
	click(control(nav, 0));
	click(control(nav, 1));
	assert.equal(f.first.scrollCount, 0);
	assert.equal(control(nav, 0).getAttribute('aria-disabled'), 'true');
	assert.equal(control(nav, 1).getAttribute('aria-current'), null);
	nav.destroy();
	assert.throws(() => nav.update(f.items), /destroyed/);
});

test('Navigation validates malformed descriptors before mutating current DOM', () => {
	const f = fixture();
	const nav = navigation.createDesignerNavigation({ documentRef: f.documentRef, root: f.root, items: f.items });
	assert.throws(() => nav.update([{ id: 'x', label: '' }]), /non-empty/);
	assert.throws(() => nav.update({}), /array/);
	assert.equal(nav.element.children[0].children.length, 2);
	const dangerous = { id: 'safe', label: '<img src=x onerror=alert(1)>', targetId: 'stage-then' };
	nav.update([dangerous]);
	assert.equal(control(nav, 0).children[0].children[0].textContent, dangerous.label);
	assert.equal(control(nav, 0).children[0].children.length, 1);
});
