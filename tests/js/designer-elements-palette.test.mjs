import assert from 'node:assert/strict';
import {test} from 'node:test';
import {createDesignerElementsPalette} from '../../assets/js/design/shell/elements-palette.js';

class FakeElement extends EventTarget {
	constructor(tagName, documentRef) {
		super();
		this.tagName = tagName;
		this.ownerDocument = documentRef;
		this.children = [];
		this.parentNode = null;
		this.dataset = {};
		this.attrs = new Map();
		this.className = '';
		this.textContent = '';
		this.disabled = false;
		this.classList = {
			add: (...classes) => { this.className += ' ' + classes.join(' '); },
			contains: (name) => this.className.split(/\s+/).includes(name),
		};
	}
	append(...items) { for (const item of items) { item.parentNode = this; this.children.push(item); } }
	prepend(...items) { for (const item of items.reverse()) { item.parentNode = this; this.children.unshift(item); } }
	replaceChildren(...items) {
		for (const child of this.children) child.parentNode = null;
		this.children = [];
		this.append(...items);
	}
	setAttribute(key, value) { this.attrs.set(key, String(value)); }
	getAttribute(key) { return this.attrs.get(key) ?? null; }
	removeAttribute(key) { this.attrs.delete(key); }
	contains(element) {
		return element === this || this.children.some(item => item.contains(element));
	}
	querySelectorAll(selector) {
		const found = [];
		const key = selector.match(/\[data-cb-design-element-source(?:="([^"]+)")?\]/);
		const visit = (item) => {
			if (key && item.dataset.cbDesignElementSource
				&& (!key[1] || item.dataset.cbDesignElementSource === key[1])) found.push(item);
			item.children.forEach(visit);
		};
		this.children.forEach(visit);
		return found;
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
	focus() { this.ownerDocument.activeElement = this; }
	click() { if (!this.disabled) this.dispatchEvent(new Event('click')); }
}
const fakeDocument = () => ({
	activeElement: null,
	createElement(tag) { return new FakeElement(tag, this); },
	createElementNS(_namespace, tag) { return new FakeElement(tag, this); },
});
const element = (id, action = 'add', state = 'interactive') => ({
	id, label: id === 'a' ? 'Heading' : id === 'b' ? 'Paragraph' : 'Table',
	type: id === 'a' ? 'heading' : 'paragraph', action, state,
});
const tokens = [{id: 'site.name', label: 'Site name', value: '{{site.name}}', state: 'interactive', action: 'copy'}];
const cards = (palette, source = 'elements') => palette.element.querySelectorAll(
	'[data-cb-design-element-source="' + source + '"]'
);

test('public palette renders accessible plain labels, informational and disabled states, and token presence', () => {
	const doc = fakeDocument();
	const events = [];
	const palette = createDesignerElementsPalette({
		documentRef: doc,
		elements: [element('a'), element('b', 'edit', 'disabled'), element('c', null, 'informational')],
		dynamicData: [], onIntent: event => events.push(event),
	});
	const items = cards(palette);
	assert.equal(items.length, 3);
	assert.equal(items[0].tagName, 'button');
	assert.equal(items[0].children.at(-1).textContent, 'Heading');
	assert.equal(items[0].getAttribute('aria-label'), 'Add Heading');
	assert.equal(items[0].title, 'Add Heading');
	assert.equal(items[1].disabled, true);
	assert.equal(items[2].tagName, 'span');
	assert.equal(items[2].getAttribute('aria-label'), 'Table');
	assert.equal(cards(palette, 'dynamic-data').length, 0);
	assert.equal(palette.element.children.length, 1, 'no fake Dynamic Data section');
	items[0].click(); items[1].click(); items[2].click();
	assert.deepEqual(events, [{source: 'elements', id: 'a', action: 'add'}]);
	palette.update({elements: [element('a')], dynamicData: tokens});
	assert.equal(palette.element.children.length, 2);
	assert.equal(cards(palette, 'dynamic-data').length, 1);
	assert.equal(cards(palette, 'dynamic-data')[0].title, '{{site.name}}');
	cards(palette, 'dynamic-data')[0].click();
	assert.equal(events.at(-1).value, '{{site.name}}');
	palette.destroy();
});

test('focus follows same id/action or falls back safely when removed, disabled or changed', () => {
	const doc = fakeDocument();
	const palette = createDesignerElementsPalette({
		documentRef: doc, elements: [element('a')], onIntent() {},
	});
	cards(palette)[0].focus();
	palette.update({elements: [element('a')], dynamicData: []});
	assert.equal(doc.activeElement, cards(palette)[0]);
	palette.update({elements: [element('a', 'edit')], dynamicData: []});
	assert.equal(doc.activeElement.tagName, 'h3');
	cards(palette)[0].focus();
	palette.update({elements: [element('a', 'add', 'disabled')], dynamicData: []});
	assert.equal(doc.activeElement.tagName, 'h3');
	palette.destroy();
});

test('async fulfillment/rejection, double activation and stale update/destroy never reactivate controls', async () => {
	const doc = fakeDocument();
	let resolve;
	let calls = 0;
	const deferred = () => new Promise(done => { resolve = done; });
	let work = deferred();
	const palette = createDesignerElementsPalette({
		documentRef: doc, elements: [element('a')], onIntent: () => { calls++; return work; },
	});
	const first = cards(palette)[0];
	first.click(); first.dispatchEvent(new Event('click'));
	assert.equal(calls, 1);
	assert.equal(first.disabled, true);
	palette.update({elements: [element('a', 'edit')], dynamicData: []});
	const replacement = cards(palette)[0];
	assert.equal(replacement.disabled, false);
	resolve();
	await Promise.resolve(); await Promise.resolve();
	assert.equal(first.disabled, true, 'late async settle never restores removed DOM');
	assert.equal(replacement.disabled, false);
	work = Promise.reject(Error('expected consumer failure'));
	replacement.click();
	await Promise.resolve(); await Promise.resolve();
	assert.equal(replacement.disabled, false, 'rejected intent clears busy state');
	work = deferred();
	replacement.click();
	assert.equal(replacement.disabled, true);
	palette.destroy();
	resolve();
	await Promise.resolve(); await Promise.resolve();
	assert.equal(replacement.disabled, true, 'destroyed controller never reactivates a control');
	assert.equal(palette.element.children.length, 0);
	assert.throws(() => palette.update({elements: [], dynamicData: []}), TypeError);
});

test('descriptor input is validated and plain text never becomes markup', () => {
	const doc = fakeDocument();
	assert.throws(() => createDesignerElementsPalette({
		documentRef: doc, elements: [element('a'), element('a')], onIntent() {},
	}), /DESIGNER_ELEMENTS_PALETTE_INVALID/);
	assert.throws(() => createDesignerElementsPalette({
		documentRef: doc, elements: [{...element('a'), action: 'drop'}], onIntent() {},
	}), /DESIGNER_ELEMENTS_PALETTE_INVALID/);
	const palette = createDesignerElementsPalette({
		documentRef: doc, elements: [{...element('a'), label: '<img src=x onerror=alert(1)>'}], onIntent() {},
	});
	assert.equal(cards(palette)[0].children.at(-1).textContent, '<img src=x onerror=alert(1)>');
	palette.destroy();
});
