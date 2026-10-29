import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';

const source = await readFile(new URL('../../assets/js/design/shell/drop-placement.js', import.meta.url), 'utf8');
const {resolveDesignerInsertPlacement, resolveDesignerDropPlacement} = await import(
	'data:text/javascript;charset=utf-8,' + encodeURIComponent(source)
);

const make = (type, children = []) => ({type, provider:'fixture', properties:{}, children});
const project = {
	schema_version:1, design_type:'fixture.document',
	root: make('fixture.document', [
		make('fixture.section', [
			make('fixture.heading'),
			make('fixture.group', [make('fixture.paragraph')]),
		]),
		make('fixture.column'),
		make('fixture.paragraph'),
	]),
};
const allowedParentsByType = new Map([
	['fixture.heading', new Set(['fixture.document','fixture.section','fixture.group','fixture.column'])],
	['fixture.paragraph', new Set(['fixture.document','fixture.section','fixture.group','fixture.column'])],
	['fixture.table', new Set(['fixture.document','fixture.section','fixture.group','fixture.column'])],
	['fixture.section', new Set(['fixture.document','fixture.section'])],
	['fixture.group', new Set(['fixture.document','fixture.section','fixture.column'])],
	['fixture.column', new Set(['fixture.document','fixture.section'])],
]);
const place = (selectedRef, type = 'fixture.table', extra = {}) =>
	resolveDesignerInsertPlacement({
		project, selectedRef, type, allowedParentsByType,
		commandId: 'fixture.insert', ...extra,
	});
const ref = path => ({kind: 'node', path});

test('DF-C: no selection appends at root and selected containers append children', () => {
	assert.deepEqual(place(null).input, {parentPath:[], index:3, type:'fixture.table'});
	assert.deepEqual(place(ref([])).input, {parentPath:[], index:3, type:'fixture.table'});
	assert.deepEqual(place(ref([0])).input, {parentPath:[0], index:2, type:'fixture.table'});
	assert.deepEqual(place(ref([0,1])).input, {parentPath:[0,1], index:1, type:'fixture.table'});
	assert.deepEqual(place(ref([1])).input, {parentPath:[1], index:0, type:'fixture.table'});
	assert.equal(Object.isFrozen(place(ref([0])).input.parentPath), true);
});

test('DF-C: selected leaf adds immediately afterward as next sibling', () => {
	assert.deepEqual(place(ref([0,0])).input, {parentPath:[0], index:1, type:'fixture.table'});
	assert.deepEqual(place(ref([0,1,0])).input, {parentPath:[0,1], index:1, type:'fixture.table'});
	assert.deepEqual(place(ref([2])).input, {parentPath:[], index:3, type:'fixture.table'});
});

test('DF-C: invalid container cannot silently fall back to root or ancestor', () => {
	assert.equal(place(ref([1]), 'fixture.section'), null, 'Column is a container but not a Section parent');
	assert.equal(place(ref([0,1]), 'fixture.section'), null, 'Group cannot contain Section');
	assert.equal(place(ref([0,1,0]), 'fixture.section'), null,
		'selected paragraph under Group cannot insert a Section into the ancestor Section');
	assert.equal(place(ref([99])), null, 'stale selection must not fall back to root');
	assert.equal(place(ref([0,5])), null, 'stale nested path must refuse');
	assert.equal(place(ref([-1])), null, 'negative index refused');
	assert.equal(place({kind:'external', path:[0]}), null, 'external selection cannot become no-selection');
	assert.equal(place({kind:'node', path:'0'}), null);
	assert.equal(place(ref([0]), 'fixture.unknown'), null);
	assert.equal(place(ref([0]), 'fixture.table', {canInsert:()=>false}), null);
	assert.equal(place(ref([0]), 'fixture.table', {canInsert:()=>{throw Error('refused');}}), null);
});

test('DF-C and DF-D1 agree on the very same candidate placement', () => {
	for (const [path,edge] of [[[0],'inside'],[[0,0],'after'],[[0,1],'inside'],[[2],'after']]) {
		const click = place(ref(path));
		const drop = resolveDesignerDropPlacement({
			project, source:{kind:'palette', type:'fixture.table'},
			zoneId: path.length ? 'node/' + path.join('.') : 'root',
			edge, allowedParentsByType, commandId:'fixture.insert',
		});
		assert.deepEqual(click, drop);
	}
});

test('DF-C is a read-only candidate; consumer remains mutation authority', () => {
	const before = JSON.stringify(project);
	const one = place(ref([0]));
	assert.equal(one.commandId, 'fixture.insert');
	assert.equal(Object.isFrozen(one), true);
	assert.equal(JSON.stringify(project), before);
});

test('Base native palette uses public contextual resolver without ancestor traversal', async () => {
	const nativeSource = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
	assert.match(nativeSource, /const insertionTarget = \(project, type\) => resolveDesignerInsertPlacement\(\{/);
	assert.match(nativeSource, /selectedRef: editor\.selection\(\)\?\.primary \?\? null/);
	assert.doesNotMatch(nativeSource, /while \(parent && !admissible\.has\(parent\.type\)/);
	assert.match(nativeSource, /resolveDesignerDropPlacement\(\{/);
	assert.equal((nativeSource.match(/createDesignerDragDropController\(\{/g) || []).length, 1);
});
