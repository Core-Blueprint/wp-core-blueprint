import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';
import {nodeAt} from '../../assets/js/design/core/tree.js';

const adapterSource = () => readFile(
	new URL('../../assets/js/design/reports/platform-adapter.js', import.meta.url), 'utf8'
);
const helpers = async () => {
	const src = await adapterSource();
	const startValid = src.indexOf('const validPath = ');
	const endValid = src.indexOf('const validValue = ');
	const start = src.indexOf('const previewRegionForPath = ');
	const end = src.indexOf('export function createRenderAdapter');
	assert.ok(startValid >= 0 && endValid > startValid && start > endValid && end > start);
	return new Function('nodeAt',
		src.slice(startValid, endValid) + src.slice(start, end)
		+ 'return {previewRegionForPath,previewPathForRegion};'
	)(nodeAt);
};
const node = (type, properties = {}, children = []) => ({
	type, provider: 'core.blueprint', properties, children,
});
const project = () => ({
	root: node('core.blueprint.reports', {}, [
		node('reports.header', {showSiteUrl: 'on'}),
		node('reports.section', {enabled: 'on', title: 'Editable'}, [
			node('reports.heading', {text: 'Heading'}),
			node('reports.paragraph', {text: 'Paragraph'}),
			node('reports.metric', {label: 'Metric', value: '42'}),
		]),
		node('reports.footer', {showPageNumber: 'on'}),
	]),
});
const selected = path => ({primary: {kind: 'node', path}});

test('root and nested Reports selections map to exact bounded Flow regions', async () => {
	const {previewRegionForPath,previewPathForRegion} = await helpers();
	const p = project();
	for (const [path, id] of [
		[[0], 'r0'], [[1], 'r1'], [[1, 0], 'r1.0'],
		[[1, 1], 'r1.1'], [[1, 2], 'r1.2'], [[2], 'r2'],
	]) {
		assert.equal(previewRegionForPath(selected(path), p), id);
		assert.deepEqual(previewPathForRegion(id, p), path);
	}
	assert.equal(previewRegionForPath(selected([]), p), null);
	assert.equal(previewRegionForPath(selected([1, 3]), p), null);
});

test('host rejects forged, missing, overdeep and hidden Reports selection targets', async () => {
	const {previewRegionForPath,previewPathForRegion} = await helpers();
	const p = project();
	for (const id of ['r01.0', 'r1.0.0', 'r1.__proto__', 'r0.0', 'r1.99', 'r999', 'r-1']) {
		assert.equal(previewPathForRegion(id, p), null, id);
	}
	p.root.children[1].properties.enabled = 'off';
	assert.equal(previewRegionForPath(selected([1, 0]), p), null);
	assert.equal(previewPathForRegion('r1.0', p), null);
});

test('Regions expose bounded nested proxy geometry and guarded Flow iframe ownership', async () => {
	const adapter = await adapterSource();
	const host = await readFile(new URL('../../assets/js/design/document/flow/preview-host.js', import.meta.url), 'utf8');
	const compiler = await readFile(new URL('../../src/Reports/MaintenanceFlowCompiler.php', import.meta.url), 'utf8');
	assert.match(adapter, /previewPathForRegion\(region\.id, lastProject\)/);
	assert.match(adapter, /syncInteractionSelection\(\)/);
	assert.match(adapter, /data-cb-design-node-drag-source/);
	assert.match(adapter, /cb-reports-platform__interaction-region/);
	assert.match(adapter, /hit\.addEventListener\('click', event => \{/);
	assert.match(adapter, /onActivate\(region\.id\)/);
	assert.doesNotMatch(adapter, /cb-reports-platform__drag-handle/);
	assert.match(adapter, /container\.classList\.contains\('is-fetching'\)/);
	assert.match(adapter, /editor\.select\(\[\{kind: 'node', path\}\], \{kind: 'node', path\}\)/);
	assert.match(host, /event\.source !== iframe\.contentWindow/);
	assert.match(host, /event\.data\.generation === activeGeneration/);
	assert.match(compiler, /\[ \$indexes\[0\], 0, \$child_index \+ 1 \]/);
	assert.match(compiler, /foreach \( \$nested_regions as \$region_id => \$paths \)/);
});
