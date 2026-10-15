import assert from 'node:assert/strict';
import test from 'node:test';

import {
	createMailInlineTextController,
	inlineMailIsSafe,
	inlineMailProperty,
} from '../../assets/js/design/mail/inline-text.js';

// No browser / WordPress required. The same production controller is exercised
// against the narrowly defined editor/selection DOM boundary.
const fixture = ({ text = 'Hello', type = 'mail.heading', source = text, failCommit = false } = {}) => {
	const events = {};
	const target = {
		ownerDocument: null,
		dataset: {},
		attributes: new Map(),
		listeners: {},
		innerHTML: '<div>' + text + '</div>',
		innerText: text,
		textContent: text,
		setAttribute(key, value) { this.attributes.set(key, value); },
		removeAttribute(key) { this.attributes.delete(key); },
		addEventListener(key, callback) { this.listeners[key] = callback; },
		focus() { this.focused = true; },
	};
	const marker = {
		dataset: { cbMailPath: '[0,0]' },
		attributes: new Map(),
		querySelector() { return target; },
		setAttribute(key, value) { this.attributes.set(key, value); },
		removeAttribute(key) { this.attributes.delete(key); },
	};
	const doc = {
		querySelectorAll() { return [marker]; },
		addEventListener(key, callback) { events[key] = callback; },
	};
	target.ownerDocument = doc;
	const property = type === 'mail.rich-text' ? 'markup' : 'text';
	const node = { type, provider: 'core', properties: { [property]: source }, children: [] };
	const project = { root: { children: [{ type: 'mail.section', children: [node] }] } };
	const session = { project: () => project, selection: () => ({ primary: [0, 0] }) };
	const changes = [];
	const statuses = [];
	const editorEvents = [];
	let fail = failCommit;
	const controller = createMailInlineTextController({
		session,
		select: (path) => editorEvents.push(['select', path]),
		executeProperty: (path, key, value) => {
			if (fail) throw new Error('Simulated storage failure');
			changes.push({ path, key, value });
			node.properties[key] = value;
		},
		onStatus: (message) => statuses.push(message),
		onEditingChange: (value) => editorEvents.push(['editing', value]),
	});
	return { controller, target, marker, doc, events, changes, statuses, editorEvents, node, setFail: (value) => { fail = value; } };
};

test('single selection/F2 entry and plain text commit use one undoable session command', () => {
	const f = fixture();
	f.controller.attach(f.doc);
	const prevent = { prevented: false, stopped: false, key: 'F2', preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; } };
	f.events.keydown(prevent);
	assert.equal(prevent.prevented, true);
	assert.equal(f.controller.isEditing(), true);
	assert.equal(f.target.attributes.get('contenteditable'), 'true');
	f.target.innerText = 'Edited';
	assert.equal(f.controller.finish(true), true);
	assert.equal(f.controller.isEditing(), false);
	assert.deepEqual(f.changes, [{ path: [0, 0], key: 'text', value: 'Edited' }]);
	assert.equal(f.target.attributes.has('contenteditable'), false);
});

test('Escape/cancel restores preview copy without touching session or undo history', () => {
	const f = fixture();
	assert.equal(f.controller.startSelected(f.doc), true);
	f.target.innerHTML = '<div>Draft</div>';
	f.target.innerText = 'Draft';
	assert.equal(f.controller.finish(false), true);
	assert.equal(f.target.innerHTML, '<div>Hello</div>');
	assert.deepEqual(f.changes, []);
	assert.equal(f.controller.isEditing(), false);
});

test('failed session command retains full editable text and permits retry', () => {
	const f = fixture({ failCommit: true });
	assert.equal(f.controller.startSelected(f.doc), true);
	f.target.innerText = 'Important unsaved draft';
	assert.equal(f.controller.finish(true), false);
	assert.equal(f.controller.isEditing(), true);
	assert.equal(f.target.innerText, 'Important unsaved draft');
	assert.deepEqual(f.changes, []);
	f.setFail(false);
	assert.equal(f.controller.finish(true), true);
	assert.deepEqual(f.changes.map((change) => change.value), ['Important unsaved draft']);
});

test('oversized text is refused without silently discarding the draft', () => {
	const f = fixture();
	assert.equal(f.controller.startSelected(f.doc), true);
	f.target.innerText = 'x'.repeat(100001);
	assert.equal(f.controller.finish(true), false);
	assert.equal(f.controller.isEditing(), true);
	assert.equal(f.target.innerText.length, 100001);
	f.target.innerText = 'Fixed size';
	assert.equal(f.controller.finish(true), true);
	assert.deepEqual(f.changes.map((change) => change.value), ['Fixed size']);
});

test('missing, unknown and malformed tokens never reverse-interpolate preview text', () => {
	for (const source of ['{{contact.name}}', '{{ unknown.variable }}', 'literal {{ unmatched', 'extra }} end']) {
		assert.equal(inlineMailIsSafe({ type: 'mail.text', provider: 'core', properties: { text: source } }), false, source);
	}
	assert.equal(inlineMailIsSafe({ type: 'mail.text', provider: 'core', properties: { text: 'Safe body copy' } }), true);
	assert.equal(inlineMailProperty({ type: 'mail.button', provider: 'core' }), null);
	assert.equal(inlineMailProperty({ type: 'mail.text', provider: 'extension' }), null);
});

test('preview listener attachment is idempotent and unrelated F2 has no edit effect', () => {
	const f = fixture();
	const count = { dblclick: 0, keydown: 0 };
	const doc = {
		querySelectorAll() { return [f.marker]; },
		addEventListener(type) { count[type] += 1; },
	};
	f.controller.attach(doc);
	f.controller.attach(doc);
	assert.deepEqual(count, { dblclick: 1, keydown: 1 });
	assert.equal(f.controller.isEditing(), false);
	assert.equal(f.controller.finish(false), false);
});
