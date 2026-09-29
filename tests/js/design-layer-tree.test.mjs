import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { after, before, test } from 'node:test';

const layersSource = new URL('../../assets/js/design/shell/layers.js', import.meta.url);
const iconsSource = new URL('../../assets/js/design/shell/icons.js', import.meta.url);
let tempDirectory;
let createDesignerLayerTree;

class FakeClassList {
	constructor() {
		this.values = new Set();
	}
	add(...values) {
		values.forEach((value) => this.values.add(value));
	}
	remove(...values) {
		values.forEach((value) => this.values.delete(value));
	}
	contains(value) {
		return this.values.has(value);
	}
	toggle(value, force) {
		if (force === true) this.values.add(value);
		else if (force === false) this.values.delete(value);
		else if (this.values.has(value)) this.values.delete(value);
		else this.values.add(value);
		return this.values.has(value);
	}
}

class FakeStyle {
	constructor() {
		this.values = new Map();
	}
	setProperty(name, value) {
		this.values.set(name, String(value));
	}
	getPropertyValue(name) {
		return this.values.get(name) || '';
	}
}

class FakeElement extends EventTarget {
	constructor(ownerDocument, tagName = 'DIV') {
		super();
		this.ownerDocument = ownerDocument;
		this.tagName = String(tagName).toUpperCase();
		this.dataset = {};
		this.attributes = new Map();
		this.classList = new FakeClassList();
		this.style = new FakeStyle();
		this.children = [];
		this.parentElement = null;
		this.hidden = false;
		this.disabled = false;
		this.draggable = false;
		this.type = '';
		this.textContent = '';
		this.className = '';
	}
	append(...children) {
		children.forEach((child) => {
			if (!child) return;
			child.parentElement = this;
			this.children.push(child);
		});
	}
	prepend(...children) {
		[...children].reverse().forEach((child) => {
			if (!child) return;
			child.parentElement = this;
			this.children.unshift(child);
		});
	}
	replaceChildren(...children) {
		this.children.forEach((child) => { child.parentElement = null; });
		this.children = [];
		this.append(...children);
	}
	remove() {
		if (!this.parentElement) return;
		this.parentElement.children = this.parentElement.children.filter((child) => child !== this);
		this.parentElement = null;
	}
	setAttribute(name, value) {
		this.attributes.set(name, String(value));
	}
	getAttribute(name) {
		return this.attributes.has(name) ? this.attributes.get(name) : null;
	}
	removeAttribute(name) {
		this.attributes.delete(name);
	}
	hasAttribute(name) {
		return this.attributes.has(name);
	}
	contains(candidate) {
		if (candidate === this) return true;
		return this.children.some((child) => child.contains(candidate));
	}
	querySelector(selector) {
		return this.querySelectorAll(selector)[0] ?? null;
	}
	querySelectorAll(selector) {
		const matches = [];
		const visit = (node) => {
			if (selector === '[data-cb-design-shell-lucide-icon]' && Object.hasOwn(node.dataset, 'cbDesignShellLucideIcon')) {
				matches.push(node);
			}
			if (selector === '.cb-core-design-shell__layer-meta' && String(node.className).split(/\s+/).includes('cb-core-design-shell__layer-meta')) {
				matches.push(node);
			}
			node.children.forEach(visit);
		};
		this.children.forEach(visit);
		return matches;
	}
	focus() {
		this.ownerDocument.activeElement = this;
		this.dispatchEvent(new Event('focus'));
	}
}

class FakeDocument {
	constructor() {
		this.activeElement = null;
	}
	createElement(tagName) {
		return new FakeElement(this, tagName);
	}
	createElementNS(namespace, tagName) {
		return new FakeElement(this, tagName);
	}
}

const keyEvent = (key) => {
	const event = new Event('keydown', { cancelable: true });
	Object.defineProperty(event, 'key', { value: key });
	return event;
};

before(async () => {
	tempDirectory = await mkdtemp(join(tmpdir(), 'cb-design-layer-tree-'));
	await writeFile(join(tempDirectory, 'package.json'), '{"type":"module"}\n');
	await writeFile(join(tempDirectory, 'icons.js'), await readFile(iconsSource, 'utf8'));
	await writeFile(join(tempDirectory, 'layers.js'), await readFile(layersSource, 'utf8'));
	globalThis.document = new FakeDocument();
	({ createDesignerLayerTree } = await import(pathToFileURL(join(tempDirectory, 'layers.js')).href));
});

after(async () => {
	delete globalThis.document;
	if (tempDirectory) await rm(tempDirectory, { recursive: true, force: true });
});

const nestedItems = () => [{
	key: '[0]',
	path: [0],
	label: 'Section',
	meta: 'Section',
	selected: true,
	onSelect: () => {},
	children: [{
		key: '[0,0]',
		path: [0, 0],
		label: 'Heading',
		meta: 'Your booking was cancelled',
		onSelect: () => {},
		children: [],
	}, {
		key: '[0,1]',
		path: [0, 1],
		label: 'Text',
		meta: 'Hi {{booking.customer_name}}',
		onSelect: () => {},
		children: [],
	}],
}];

test('hierarchical Layers mirror parent-child document structure and suppress redundant metadata', () => {
	const tree = createDesignerLayerTree({ documentRef: document, ariaLabel: 'Layers' });
	tree.render(nestedItems());

	assert.equal(tree.element.getAttribute('role'), 'tree');
	assert.equal(tree.element.getAttribute('aria-label'), 'Layers');

	const section = tree.row('[0]');
	const heading = tree.row('[0,0]');
	const text = tree.row('[0,1]');
	assert.ok(section);
	assert.ok(heading);
	assert.ok(text);
	assert.equal(section.getAttribute('aria-level'), '1');
	assert.equal(section.getAttribute('aria-expanded'), 'true');
	assert.equal(section.getAttribute('aria-selected'), 'true');
	assert.equal(heading.getAttribute('aria-level'), '2');
	assert.equal(text.getAttribute('aria-level'), '2');

	const sectionSelect = section.children[1];
	assert.equal(sectionSelect.querySelector('.cb-core-design-shell__layer-meta'), null);
	assert.equal(heading.children[1].querySelector('.cb-core-design-shell__layer-meta')?.textContent, 'Your booking was cancelled');
});

test('Layer selection stays in Layers while publishing selection intent to the consumer adapter', () => {
	let selected = null;
	const tree = createDesignerLayerTree({
		documentRef: document,
		onSelectItem: (path, options) => {
			selected = { path, options };
		},
	});
	tree.render(nestedItems());

	const headingSelect = tree.row('[0,0]').children[1];
	headingSelect.dispatchEvent(new Event('click'));

	assert.deepEqual(selected?.path, [0, 0]);
	assert.equal(selected?.options?.source, 'layers');
	assert.equal(selected?.options?.openInspector, false);
	assert.equal(selected?.options?.key, '[0,0]');
});

test('collapse is transient tree UI state and restores descendants without mutating descriptors', () => {
	const items = nestedItems();
	const tree = createDesignerLayerTree({ documentRef: document });
	tree.render(items);

	assert.equal(tree.collapse('[0]'), true);
	assert.equal(tree.isCollapsed('[0]'), true);
	assert.equal(tree.row('[0]').getAttribute('aria-expanded'), 'false');
	assert.equal(tree.row('[0,0]'), null);
	assert.equal(items[0].children.length, 2);

	assert.equal(tree.expand('[0]'), true);
	assert.equal(tree.isCollapsed('[0]'), false);
	assert.ok(tree.row('[0,0]'));
	assert.equal(tree.row('[0]').getAttribute('aria-expanded'), 'true');
});

test('tree keyboard navigation opens hierarchy and moves between parent and visible children', () => {
	const tree = createDesignerLayerTree({ documentRef: document });
	tree.render(nestedItems());
	tree.collapse('[0]');

	let sectionSelect = tree.row('[0]').children[1];
	sectionSelect.focus();
	const open = keyEvent('ArrowRight');
	sectionSelect.dispatchEvent(open);
	assert.equal(open.defaultPrevented, true);
	assert.equal(tree.isCollapsed('[0]'), false);

	sectionSelect = tree.row('[0]').children[1];
	const enterChild = keyEvent('ArrowRight');
	sectionSelect.dispatchEvent(enterChild);
	assert.equal(document.activeElement, tree.row('[0,0]').children[1]);

	const backToParent = keyEvent('ArrowLeft');
	document.activeElement.dispatchEvent(backToParent);
	assert.equal(backToParent.defaultPrevented, true);
	assert.equal(document.activeElement, tree.row('[0]').children[1]);
});

test('tree owns bounded sibling move controls while consumers provide only reorder policy', () => {
	let movedTo = null;
	const tree = createDesignerLayerTree({ documentRef: document });
	tree.render([{
		key: 'first',
		label: 'First',
		reorder: {
			index: 0,
			minIndex: 0,
			maxIndex: 1,
			onMove: (target) => { movedTo = target; },
		},
		children: [],
	}, {
		key: 'second',
		label: 'Second',
		reorder: {
			index: 1,
			minIndex: 0,
			maxIndex: 1,
			onMove: () => {},
		},
		children: [],
	}]);

	const first = tree.row('first');
	const actions = first.children[2];
	assert.equal(actions.children.length, 2);
	assert.equal(actions.children[0].disabled, true);
	assert.equal(actions.children[1].disabled, false);
	actions.children[1].dispatchEvent(new Event('click'));
	assert.equal(movedTo, 1);
});
