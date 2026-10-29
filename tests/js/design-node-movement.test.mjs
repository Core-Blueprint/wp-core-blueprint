import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

// Resolve the existing private modules in isolated data-URL ESM so this test
// never modifies installed files or requires another Node module loader.
const load = async path => readFile(new URL(path, import.meta.url), 'utf8');
const dataUrl = text => 'data:text/javascript;charset=utf-8,' + encodeURIComponent(text);
const valueURL = dataUrl(await load('../../assets/js/design/core/value.js'));
const treeURL = dataUrl((await load('../../assets/js/design/core/tree.js'))
	.replaceAll("'./value.js'", JSON.stringify(valueURL)));
const commandsURL = dataUrl((await load('../../assets/js/design/core/commands.js'))
	.replaceAll("'./value.js'", JSON.stringify(valueURL))
	.replaceAll("'./tree.js'", JSON.stringify(treeURL)));
const [tree, commands, drop] = await Promise.all([
	import(treeURL), import(commandsURL),
	import(dataUrl(await load('../../assets/js/design/shell/drop-placement.js'))),
]);

const node = (type, children = [], properties = {}) => ({
	type, provider: 'fixture', properties, children,
});
const project = () => ({
	schema_version: 0,
	design_type: 'fixture.move',
	root: node('root', [
		node('section', [
			node('heading', [node('leaf', [], {text: 'nested'})], {name: 'keep'}),
			node('paragraph', [], {text: 'move me'}),
		]),
		node('section', [node('paragraph', [], {text: 'original'})]),
	]),
});
const allowed = new Map([
	['section', new Set(['root'])],
	['heading', new Set(['section'])],
	['paragraph', new Set(['section'])],
	['leaf', new Set(['heading'])],
]);
const resolve = (p, source, zoneId, edge) => drop.resolveDesignerMovePlacement({
	project: p, source, zoneId, edge, commandId: 'fixture.move',
	allowedParentsByType: allowed,
});

test('cross-parent move keeps subtree/property identity and remaps unrelated selections', () => {
	const p = project();
	const source = {kind: 'node', type: 'heading', path: [0, 0]};
	const intent = resolve(p, source, 'node/1', 'inside');
	assert.deepEqual(intent?.input, {fromPath: [0, 0], parentPath: [1], index: 1});
	assert.ok(Object.isFrozen(intent.input));
	const result = commands.moveNodeCommand(...Object.values(intent.input)).apply(p);
	assert.equal(result.project.root.children[0].children.length, 1);
	assert.equal(result.project.root.children[1].children[1].properties.name, 'keep');
	assert.equal(result.project.root.children[1].children[1].children[0].properties.text, 'nested');
	assert.deepEqual(result.remapSelection([0, 0, 0]), [1, 1, 0]);
	assert.deepEqual(result.remapSelection([0, 1]), [0, 0]);
	assert.deepEqual(result.remapSelection([1, 0]), [1, 0]);
	assert.equal(p.root.children[0].children.length, 2, 'source snapshot is unchanged');
});

test('same-parent move normalizes target index AFTER source removal', () => {
	const p = project();
	const source = {kind: 'node', type: 'heading', path: [0, 0]};
	const intent = resolve(p, source, 'node/0.1', 'after');
	assert.deepEqual(intent?.input, {fromPath: [0, 0], parentPath: [0], index: 1});
	const effect = commands.moveNodeCommand(...Object.values(intent.input)).apply(p);
	assert.deepEqual(effect.project.root.children[0].children.map(n => n.type), ['paragraph', 'heading']);
	assert.deepEqual(effect.remapSelection([0, 1]), [0, 0]);
	assert.deepEqual(effect.remapSelection([0, 0, 0]), [0, 1, 0]);
});

test('invalid parents, cycles, same-position no-ops and stale source type fail closed', () => {
	const p = project();
	const section = {kind:'node',type:'section',path:[0]};
	const heading = {kind:'node',type:'heading',path:[0,0]};
	assert.equal(resolve(p, section, 'node/0.0', 'inside'), null);
	assert.equal(resolve(p, section, 'node/0.0.0', 'before'), null);
	assert.equal(resolve(p, heading, 'node/0.1', 'before'), null);
	assert.equal(resolve(p, heading, 'root', 'inside'), null);
	assert.equal(resolve(p, {kind:'node',type:'paragraph',path:[0,0]}, 'node/1', 'inside'), null);
	assert.equal(resolve(p, heading, 'node/99', 'after'), null);
	assert.throws(() => commands.moveNodeCommand([0,0], [0], 0).apply(p), /no-op/i);
	assert.throws(() => commands.moveNodeCommand([], [], 0).apply(p), /Invalid node move/);
});

test('root sibling indexes normalize if an earlier section is removed', () => {
	const p = project();
	const source = {kind:'node', type:'section', path:[0]};
	const intent = resolve(p, source, 'root', 'inside');
	assert.deepEqual(intent?.input, {fromPath:[0],parentPath:[],index:1});
	const effect = commands.moveNodeCommand(...Object.values(intent.input)).apply(p);
	assert.equal(effect.project.root.children[1].children[0].type,'heading');
	assert.deepEqual(effect.remapSelection([1,0]),[0,0]);
	assert.deepEqual(effect.remapSelection([0,0,0]),[1,0,0]);
});

test('native consumers share DND controller and preserve one command authority', async () => {
	const editor = await load('../../assets/js/design/editor.js');
	const layers = await load('../../assets/js/design/shell/layers.js');
	const mail = await load('../../assets/js/design/mail/platform-adapter.js');
	const reports = await load('../../assets/js/design/reports/platform-adapter.js');
	assert.match(editor,/sharedDragDrop:\s*true/);
	assert.match(editor,/resolveDesignerMovePlacement/);
	assert.match(editor,/registeredNodeSources/);
	assert.ok(editor.includes("prefix + '.move'"));
	assert.match(layers,/reorder && sharedDragDrop !== true/);
	assert.match(mail,/'mail.move'/);
	assert.match(reports,/'reports.move'/);
});
