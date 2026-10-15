import assert from 'node:assert/strict';
import test from 'node:test';

import { createMailInlineToolbar } from '../../assets/js/design/mail/inline-toolbar.js';

/**
 * Minimal DOM contract for the real toolbar module. Browser/integration
 * acceptance remains a separate gate; this covers deterministic operations.
 */
const fixture = (text = 'Example copy') => {
	const listeners = new Map();
	const nodes = [];
	const inserted = [];
	const element = (tagName) => {
		const events = {};
		const node = {
			tagName: tagName.toUpperCase(),
			nodeType: 1,
			children: [],
			attributes: new Map(),
			style: {},
			hidden: false,
			events,
			ownerDocument: null,
			append(...children) { this.children.push(...children); },
			setAttribute(name, value) { this.attributes.set(name, value); },
			getAttribute(name) { return this.attributes.get(name) || null; },
			addEventListener(name, callback) { events[name] = callback; },
			remove() { this.removed = true; },
			focus() { doc.activeElement = this; },
			getBoundingClientRect() { return { left: 24, top: 100, bottom: 140, height: 40 }; },
			get offsetWidth() { return 200; },
		};
		nodes.push(node);
		return node;
	};
	const doc = {
		documentElement: { clientWidth: 600 },
		head: null,
		body: null,
		activeElement: null,
		createElement: element,
		createRange: () => ({
			selectNodeContents(node) { this.selected = node; },
			collapse() { this.collapsed = true; },
		}),
		getElementById: () => null,
		addEventListener(name, callback) { listeners.set(name, callback); },
		removeEventListener(name, callback) { if (listeners.get(name) === callback) listeners.delete(name); },
		getSelection: () => selection,
	};
	doc.head = element('head');
	doc.body = element('body');
	const target = element('td');
	target.contains = (other) => other === target;
	const range = {
		commonAncestorContainer: target,
		startContainer: target,
		toString: () => text,
		cloneRange() { return { ...this }; },
		extractContents() { return { nodeType: 11, textContent: text }; },
		insertNode(node) { inserted.push(node); },
	};
	const selection = {
		rangeCount: 1,
		isCollapsed: false,
		getRangeAt: () => range,
		removeAllRanges() { this.rangeCount = 0; this.isCollapsed = true; },
		addRange() { this.rangeCount = 1; },
	};
	const status = [];
	const toolbar = createMailInlineToolbar({ documentRef: doc, target, onStatus: (message) => status.push(message) });
	const action = (label) => {
		const button = toolbar.element.children.find((item) => item.attributes.get('aria-label') === label);
		assert.ok(button, 'Missing action: ' + label);
		button.events.click();
	};
	return { doc, target, toolbar, listeners, selection, range, inserted, action, status, nodes };
};

test('inline formatting wraps selected content in allowlisted strong/em/u nodes', () => {
	for (const [label, type] of [
		['Bold selected text', 'STRONG'],
		['Italicize selected text', 'EM'],
		['Underline selected text', 'U'],
	]) {
		const f = fixture();
		f.action(label);
		assert.equal(f.inserted.length, 1, label);
		assert.equal(f.inserted[0].tagName, type, label);
		f.toolbar.destroy();
		assert.equal(f.listeners.size, 0);
	}
});

test('list buttons produce UL/OL with a LI child and do not change style properties', () => {
	for (const [label, type] of [
		['Create bulleted list from selected text', 'UL'],
		['Create numbered list from selected text', 'OL'],
	]) {
		const f = fixture();
		f.action(label);
		assert.equal(f.inserted[0].tagName, type);
		assert.equal(f.inserted[0].children[0].tagName, 'LI');
		assert.equal(f.target.style.fontSize, undefined);
		assert.equal(f.target.style.background, undefined);
	}
});

test('keyboard shortcuts are handled only when text is actually selected', () => {
	const f = fixture();
	assert.equal(f.toolbar.applyShortcut({ ctrlKey: true, key: 'b' }), true);
	assert.equal(f.inserted[0].tagName, 'STRONG');
	assert.equal(f.toolbar.applyShortcut({ ctrlKey: true, key: 'z' }), false);
	assert.equal(f.toolbar.applyShortcut({ ctrlKey: true, altKey: true, key: 'i' }), false);
	f.selection.isCollapsed = true;
	f.selection.rangeCount = 0;
	f.doc.activeElement = f.target;
	f.listeners.get('selectionchange')();
	assert.equal(f.toolbar.applyShortcut({ ctrlKey: true, key: 'i' }), false);
	assert.equal(f.status.at(-1), 'Select some text first.');
});

test('retained selection survives toolbar focus and is cleared after destroy', () => {
	const f = fixture();
	f.listeners.get('selectionchange')();
	f.selection.isCollapsed = true;
	f.selection.rangeCount = 0;
	f.doc.activeElement = null;
	assert.equal(f.toolbar.applyShortcut({ metaKey: true, key: 'u' }), true);
	assert.equal(f.inserted[0].tagName, 'U');
	f.toolbar.destroy();
	assert.equal(f.listeners.size, 0);
	assert.equal(f.toolbar.element.removed, true);
});

test('invalid link is rejected before creating any anchor', () => {
	const f = fixture();
	f.action('Link selected text');
	const field = f.nodes.find((node) => node.tagName === 'INPUT' && node.attributes.get('aria-label') === 'Link address');
	assert.ok(field);
	field.value = 'javascript:alert(1)';
	const panel = f.toolbar.element.children.find((node) => node.className === 'cb-mail-inline-format__link');
	const apply = panel.children.find((node) => node.tagName === 'BUTTON' && node.textContent === 'Apply');
	apply.events.click();
	assert.equal(f.inserted.length, 0);
});
