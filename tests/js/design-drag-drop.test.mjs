import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const source = await readFile(
	new URL('../../assets/js/design/shell/drag-drop.js', import.meta.url), 'utf8'
);
const { createDesignerDragDropController } = await import(
	'data:text/javascript;charset=utf-8,' + encodeURIComponent(source)
);

class FakeElement {
	constructor(doc, name = 'div') {
		this.ownerDocument = doc;
		this.name = name;
		this.isConnected = true;
		this.parent = null;
		this.children = [];
		this.attributes = new Map();
		this.listeners = new Map();
		this.style = {};
		this.textContent = '';
	}
	append(child) {
		child.parent = this;
		this.children.push(child);
	}
	remove() {
		if (this.parent) this.parent.children = this.parent.children.filter(item => item !== this);
		this.parent = null;
		this.isConnected = false;
	}
	contains(target) {
		return this === target || this.children.some(child => child.contains(target));
	}
	getAttribute(name) { return this.attributes.get(name) ?? null; }
	setAttribute(name, value) { this.attributes.set(name, String(value)); }
	removeAttribute(name) { this.attributes.delete(name); }
	addEventListener(name, callback) {
		if (!this.listeners.has(name)) this.listeners.set(name, new Set());
		this.listeners.get(name).add(callback);
	}
	removeEventListener(name, callback) { this.listeners.get(name)?.delete(callback); }
	getBoundingClientRect() { return {top: 100, height: 120}; }
	focus() { this.ownerDocument.activeElement = this; this.fire('focus'); }
	fire(name, extra = {}) {
		let prevented = false;
		const event = {
			key: undefined, altKey: false, repeat: false, clientY: 160,
			relatedTarget: null,
			preventDefault() { prevented = true; },
			stopPropagation() {},
			...extra,
		};
		for (const listener of this.listeners.get(name) ?? []) listener(event);
		return prevented;
	}
}
class FakeDocument extends FakeElement {
	constructor() {
		super(null, 'document');
		this.ownerDocument = this;
	}
	createElement(name) { return new FakeElement(this, name); }
}

const fixture = (policy = () => true) => {
	const doc = new FakeDocument();
	const root = doc.createElement('main');
	doc.append(root);
	const source = doc.createElement('button');
	const zone = doc.createElement('section');
	root.append(source);
	root.append(zone);
	let epoch = 'site:1:report:1';
	let ready = true;
	const commits = [];
	const statuses = [];
	const controller = createDesignerDragDropController({
		root,
		isReady: () => ready,
		getContextKey: () => epoch,
		resolveIntent: ({source: dragged, zoneId, edge}) =>
			({commandId: 'fixture.insert', input: {type: dragged.type, zoneId, edge}}),
		canDrop: policy,
		commit: ({source: dragged, intent}) => { commits.push({dragged, intent}); },
		onStatus: status => statuses.push(status.status),
	});
	controller.registerSource(source, {id: 'fixture/heading', type: 'fixture.heading'});
	controller.registerZone(zone, {id: 'report/section-1'});
	return {
		doc, root, source, zone, commits, statuses, controller,
		setEpoch: value => { epoch = value; },
		setReady: value => { ready = value; },
	};
};
const transfer = () => ({
	effectAllowed: '', dropEffect: '',
	setData(type, value) { this[type] = value; },
});

test('a palette drag yields one authorized domain intent, never DOM/project mutation', () => {
	const f = fixture();
	const dataTransfer = transfer();
	assert.equal(f.source.fire('dragstart', {dataTransfer}), false);
	assert.equal(dataTransfer['text/plain'], 'cb-designer-element');
	assert.equal(f.controller.active, true);
	assert.equal(f.zone.fire('dragover', {dataTransfer, clientY: 105}), true);
	assert.equal(f.zone.getAttribute('data-cb-design-drop-edge'), 'before');
	assert.equal(f.zone.fire('drop', {dataTransfer, clientY: 105}), true);
	assert.equal(f.commits.length, 1);
	assert.equal(f.commits[0].intent.commandId, 'fixture.insert');
	assert.deepEqual(f.commits[0].intent.input, {
		type: 'fixture.heading', zoneId: 'report/section-1', edge: 'before',
	});
	assert.equal(f.zone.getAttribute('data-cb-design-drop-active'), null);
	assert.equal(f.zone.fire('drop', {dataTransfer}), false, 'replayed drop is ignored');
	assert.equal(f.commits.length, 1);
	f.controller.destroy();
});

test('external DataTransfer payload is never a source of authority', () => {
	const f = fixture();
	const dataTransfer = transfer();
	dataTransfer.setData('text/plain', 'cb-designer-element');
	assert.equal(f.zone.fire('drop', {dataTransfer}), false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
});

test('a domain policy rejection never prevents an invalid drop or dispatches a command', () => {
	const f = fixture(() => false);
	f.source.fire('dragstart', {dataTransfer: transfer()});
	assert.equal(f.zone.fire('dragover', {dataTransfer: transfer()}), false);
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
});

test('context changes and inactive sessions cancel drag before an outdated command', () => {
	const f = fixture();
	f.source.fire('dragstart', {dataTransfer: transfer()});
	f.setEpoch('site:1:report:2');
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(f.commits.length, 0);
	assert.equal(f.controller.active, false);
	f.source.fire('dragstart', {dataTransfer: transfer()});
	f.setReady(false);
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
});

test('keyboard operation preserves click and uses a registered dropzone', () => {
	const f = fixture();
	assert.equal(f.source.fire('keydown', {key: 'Enter'}), false, 'Enter click unaffected');
	assert.equal(f.controller.active, false);
	assert.equal(f.source.fire('keydown', {key: 'ArrowDown', altKey: true}), true);
	assert.equal(f.controller.active, true);
	assert.equal(f.doc.activeElement, f.zone);
	f.zone.fire('keydown', {key: 'ArrowUp'});
	assert.equal(f.zone.getAttribute('data-cb-design-drop-edge'), 'before');
	assert.equal(f.zone.fire('keydown', {key: 'Enter'}), true);
	assert.equal(f.commits[0].intent.input.edge, 'before');
	f.controller.destroy();
});

test('cancel, unregister and destroy clean up listeners and DOM attributes', () => {
	const f = fixture();
	f.source.fire('dragstart', {dataTransfer: transfer()});
	f.doc.fire('keydown', {key: 'Escape'});
	assert.equal(f.controller.active, false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
	assert.equal(f.source.getAttribute('draggable'), null);
	assert.equal(f.source.getAttribute('aria-keyshortcuts'), null);
	assert.equal(f.zone.getAttribute('tabindex'), null);
	assert.equal(f.root.children.some(child => child.name === 'div'), false);
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
});

test('detached sources and zones cannot dispatch stale canvas commands', () => {
	const detachedZone = fixture();
	detachedZone.source.fire('dragstart', {dataTransfer: transfer()});
	detachedZone.zone.remove();
	assert.equal(detachedZone.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(detachedZone.commits.length, 0);
	detachedZone.controller.destroy();

	const detachedSource = fixture();
	detachedSource.source.fire('dragstart', {dataTransfer: transfer()});
	detachedSource.source.remove();
	assert.equal(detachedSource.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(detachedSource.controller.active, false);
	assert.equal(detachedSource.commits.length, 0);
	detachedSource.controller.destroy();
});

test('async domain policy is never accepted as authorization', () => {
	const f = fixture(() => Promise.resolve(true));
	f.source.fire('dragstart', {dataTransfer: transfer()});
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
});
