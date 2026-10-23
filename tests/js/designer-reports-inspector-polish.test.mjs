import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';
import {createDesignerInspectorValueControl} from '../../assets/js/design/shell/inspector-value-control.js';

class FakeElement {
	constructor(tag) {
		this.tagName = tag.toUpperCase();
		this.children = [];
		this.dataset = {};
		this.listeners = new Map();
		this.checked = false;
		this.disabled = false;
		this.value = '';
	}
	append(...children) { this.children.push(...children); }
	addEventListener(type, listener) {
		if (!this.listeners.has(type)) this.listeners.set(type, new Set());
		this.listeners.get(type).add(listener);
	}
	removeEventListener(type, listener) { this.listeners.get(type)?.delete(listener); }
	dispatch(type) { for (const listener of this.listeners.get(type) ?? []) listener({type}); }
}
const fakeDocument = () => ({activeElement: null, createElement: tag => new FakeElement(tag)});
const switchSchema = {type: 'string', enum: ['on', 'off'], maxLength: 3};

test('on/off string enums render as shared checkboxes while retaining string Save values', () => {
	const values = [];
	const control = createDesignerInspectorValueControl({
		key: 'node.enabled', label: 'Enabled', value: 'on',
		schema: switchSchema, documentRef: fakeDocument(), onCommit: value => values.push(value),
	});
	assert.equal(control.element.tagName, 'LABEL');
	assert.equal(control.control.type, 'checkbox');
	assert.equal(control.control.dataset.cbControlKey, 'node.enabled');
	assert.equal(control.control.checked, true);
	control.control.checked = false;
	control.control.dispatch('change');
	control.control.dispatch('blur');
	assert.deepEqual(values, ['off']);
	control.update({value: 'on'});
	assert.equal(control.control.checked, true);
	control.destroy();
	control.control.checked = false;
	control.control.dispatch('change');
	assert.deepEqual(values, ['off']);
});

test('command refusal restores the original checkbox state', () => {
	const control = createDesignerInspectorValueControl({
		key: 'node.showDetails', label: 'Show details', value: 'off',
		schema: {type: 'string', enum: ['off', 'on']},
		documentRef: fakeDocument(), onCommit: () => { throw new Error('COMMAND_REFUSED'); },
	});
	control.control.checked = true;
	assert.throws(() => control.control.dispatch('change'), /COMMAND_REFUSED/);
	assert.equal(control.control.checked, false);
});

test('other enums remain selects and malformed toggle state fails closed', () => {
	const select = createDesignerInspectorValueControl({
		key: 'persisted.density', label: 'Density', value: 'comfortable',
		schema: {type: 'string', enum: ['compact', 'comfortable']},
		documentRef: fakeDocument(), onCommit: () => {},
	});
	assert.equal(select.control.tagName, 'SELECT');
	const toggle = createDesignerInspectorValueControl({
		key: 'node.enabled', label: 'Enabled', value: 'on',
		schema: switchSchema, documentRef: fakeDocument(), onCommit: () => {},
	});
	assert.throws(() => toggle.update({value: 'maybe'}), /INSPECTOR_SCHEMA_INVALID/);
});

test('Reports groups Content and Display options without changing Mail Inspector', async () => {
	const source = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
	assert.match(source, /activeRegistry\.profileId === 'base\/reports' && fields\.length > 1/);
	assert.match(source, /\['Content', contentFields\], \['Display options', displayFields\]/);
	assert.match(source, /createDesignerInspectorSection\(\{/);
	assert.match(source, /for \(const field of fields\) addControl\(field, inspectorPanel\)/);
	assert.match(source, /editor\.execute\(prefix \+ '\.set'/);
});
