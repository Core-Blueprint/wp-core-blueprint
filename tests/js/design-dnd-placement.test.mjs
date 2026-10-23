import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const js = await readFile(new URL('../../assets/js/design/shell/drop-placement.js', import.meta.url), 'utf8');
const {designerDropZoneId,resolveDesignerDropPlacement} = await import(
	'data:text/javascript;charset=utf-8,' + encodeURIComponent(js)
);
const make = (type, children = []) => ({
	type, provider: 'fixture', properties: {}, children,
});
const project = {
	schema_version: 1, design_type: 'fixture.report',
	root: make('fixture.report', [
		make('reports.header'),
		make('reports.section', [make('reports.heading')]),
		make('reports.footer'),
	]),
};
const allowed = new Map([
	['reports.heading', new Set(['fixture.report', 'reports.section'])],
	['reports.section', new Set(['fixture.report'])],
]);
const source = {id: 'fixture/heading', kind: 'palette', type: 'reports.heading', path: []};
const place = (zoneId, edge, extra = {}) => resolveDesignerDropPlacement({
	project, source, zoneId, edge, allowedParentsByType: allowed,
	commandId: 'reports.insert', ...extra,
});

test('Base projects deterministic insertion positions for top-level and nested elements', () => {
	assert.deepEqual(place('node/1', 'before').input,
		{parentPath: [], index: 1, type: 'reports.heading'});
	assert.deepEqual(place('node/1', 'after').input,
		{parentPath: [], index: 2, type: 'reports.heading'});
	assert.deepEqual(place('node/1', 'inside').input,
		{parentPath: [1], index: 1, type: 'reports.heading'});
	assert.deepEqual(place('node/1.0', 'before').input,
		{parentPath: [1], index: 0, type: 'reports.heading'});
	assert.deepEqual(place('root', 'inside').input,
		{parentPath: [], index: 3, type: 'reports.heading'});
	assert.equal(designerDropZoneId([1,0]), 'node/1.0');
	assert.equal(designerDropZoneId([]), 'root');
});

test('Base refuses unknown paths and invalid parent-child positions without mutation', () => {
	const original = JSON.stringify(project);
	for (const zone of ['node/9','node/1.-1','node/01','node/1.__proto__','node/99.0','node/1.5000']) {
		assert.equal(place(zone, 'after'), null, zone);
	}
	assert.equal(place('node/0', 'inside'), null, 'Header is not a container');
	assert.equal(place('node/2', 'inside'), null, 'Footer is not a container');
	assert.equal(place('node/1.0', 'inside'), null, 'Heading is not a container');
	assert.equal(place('node/1', 'inside', {source:{...source, kind:'node'}}), null);
	assert.equal(place('node/1', 'inside', {source:{...source, type:'reports.section'}}), null);
	assert.equal(place('node/1', 'after', {canInsert:()=>false}), null);
	assert.equal(place('node/1', 'after', {canInsert:()=>{throw Error('fail closed')}}), null);
	assert.throws(()=>designerDropZoneId([-1]), /DESIGNER_DROP_PATH_INVALID/);
	assert.equal(JSON.stringify(project), original);
});

test('Domain restrictions take precedence over otherwise valid geometry', () => {
	const blocked = place('node/1', 'after', {
		canInsert:(_project,type,parentPath)=>type!=='reports.heading'||parentPath.length>0,
	});
	assert.equal(blocked, null);
	const approved = place('node/1', 'inside', {
		canInsert:(_project,type,parentPath)=>type==='reports.heading'&&parentPath.length===1,
	});
	assert.equal(approved.input.index, 1);
});
