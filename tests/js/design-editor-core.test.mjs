import assert from 'node:assert/strict';
import { mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { after, before, test } from 'node:test';

const sourceDirectory = new URL('../../assets/js/design/core/', import.meta.url);
let tempDirectory;
let core;

before(async () => {
	tempDirectory = await mkdtemp(join(tmpdir(), 'cb-design-editor-'));
	const entries = await readdir(sourceDirectory, { withFileTypes: true });
	for (const entry of entries) {
		if (!entry.isFile() || !entry.name.endsWith('.js')) continue;
		const source = await readFile(new URL(entry.name, sourceDirectory), 'utf8');
		const esm = source.replaceAll(/(['"])\.\/([^'"]+)\.js\1/g, "$1./$2.mjs$1");
		await writeFile(join(tempDirectory, entry.name.replace(/\.js$/, '.mjs')), esm);
	}
	core = await import(pathToFileURL(join(tempDirectory, 'index.mjs')).href);
});

after(async () => {
	if (tempDirectory) await rm(tempDirectory, { recursive: true, force: true });
});

const node = (type, children = [], properties = {}) => ({
	type,
	provider: 'acme.editor',
	properties,
	children,
});

const project = () => ({
	schema_version: 0,
	design_type: 'acme.editor.design',
	root: node('root', [
		node('alpha', [node('alpha-child')], { title: 'Alpha' }),
		node('beta', [], { title: 'Beta' }),
		node('gamma', [], { title: 'Gamma' }),
	]),
});

test('ProjectState owns immutable snapshots and rejects non-project envelope state', () => {
	const state = new core.ProjectState(project());
	assert.equal(Object.isFrozen(state.current()), true);
	assert.equal(Object.isFrozen(state.current().root.children), true);
	const snapshot = state.snapshot();
	snapshot.root.children[0].properties.title = 'Changed outside';
	assert.equal(state.current().root.children[0].properties.title, 'Alpha');
	assert.throws(
		() => new core.ProjectState({ ...project(), editor_state: { selection: [] } }),
		/DesignProject-shaped/
	);
});

test('EditorState and its sub-states are session-only and cannot be JSON persisted', () => {
	const editor = new core.EditorState();
	editor.selection.select([1]);
	editor.inspector.setActiveSection('content');
	editor.validation.append({ code: 'demo', message: 'Demo', location: 'root.children.1' });
	assert.throws(() => JSON.stringify(editor), /session-only/);
	assert.throws(() => JSON.stringify(editor.selection), /editor-session state/);
	assert.throws(() => JSON.stringify(editor.inspector), /editor-session state/);
	assert.throws(() => JSON.stringify(editor.validation), /editor-session state/);
});

test('tree paths fail closed and immutable property editing blocks prototype-pollution keys', () => {
	const original = project().root;
	assert.throws(() => core.normalizePath([0, -1]), /non-negative integers/);
	assert.throws(() => core.setNodeProperty(original, [0], ['__proto__', 'polluted'], true), /safe non-empty/);
	assert.throws(() => core.setNodeProperty(original, [0], ['constructor', 'prototype'], true), /safe non-empty/);
	const changed = core.setNodeProperty(original, [0], ['text', 'weight'], 700);
	assert.equal(changed.children[0].properties.text.weight, 700);
	assert.equal(original.children[0].properties.text, undefined);
	assert.equal({}.polluted, undefined);
});

test('insert command preserves existing selection paths and selects the inserted node', () => {
	const state = new core.ProjectState(project());
	const editor = new core.EditorState();
	editor.selection.select([1]);
	const history = new core.CommandHistory(state, editor);
	history.execute(core.insertNodeCommand([], 1, node('inserted')));
	assert.deepEqual(state.current().root.children.map((item) => item.type), ['alpha', 'inserted', 'beta', 'gamma']);
	assert.deepEqual(editor.selection.primary(), [1]);
	assert.equal(history.canUndo, true);
	history.dispose();
});

test('remove command deletes descendants from selection and remaps following siblings', () => {
	const state = new core.ProjectState(project());
	const editor = new core.EditorState();
	editor.selection.set([[0, 0], [2]], [2]);
	const history = new core.CommandHistory(state, editor);
	history.execute(core.removeNodeCommand([0]));
	assert.deepEqual(state.current().root.children.map((item) => item.type), ['beta', 'gamma']);
	assert.deepEqual(editor.selection.paths(), [[1]]);
	assert.deepEqual(editor.selection.primary(), [1]);
	history.dispose();
});

test('reorder command remaps selected sibling paths deterministically', () => {
	const state = new core.ProjectState(project());
	const editor = new core.EditorState();
	editor.selection.set([[0], [1], [2]], [0]);
	const history = new core.CommandHistory(state, editor);
	history.execute(core.reorderNodeCommand([], 0, 2));
	assert.deepEqual(state.current().root.children.map((item) => item.type), ['beta', 'gamma', 'alpha']);
	assert.deepEqual(editor.selection.paths(), [[2], [0], [1]]);
	assert.deepEqual(editor.selection.primary(), [2]);
	history.dispose();
});

test('bounded command history supports undo and redo with project and editor state', () => {
	const state = new core.ProjectState(project());
	const editor = new core.EditorState();
	editor.selection.select([0]);
	const history = new core.CommandHistory(state, editor, { limit: 2 });
	history.execute(core.setPropertyCommand([0], ['title'], 'One'));
	history.execute(core.setPropertyCommand([0], ['title'], 'Two'));
	history.execute(core.setPropertyCommand([0], ['title'], 'Three'));
	assert.equal(history.size, 2);
	assert.equal(state.current().root.children[0].properties.title, 'Three');
	assert.equal(history.undo(), true);
	assert.equal(state.current().root.children[0].properties.title, 'Two');
	assert.equal(history.undo(), true);
	assert.equal(state.current().root.children[0].properties.title, 'One');
	assert.equal(history.undo(), false);
	assert.equal(history.redo(), true);
	assert.equal(state.current().root.children[0].properties.title, 'Two');
	history.dispose();
});

test('external ProjectState replacement invalidates stale undo and redo history', () => {
	const state = new core.ProjectState(project());
	const editor = new core.EditorState();
	const history = new core.CommandHistory(state, editor);
	history.execute(core.setPropertyCommand([0], ['title'], 'Local edit'));
	assert.equal(history.canUndo, true);
	const refreshed = project();
	refreshed.root.children[0].properties.title = 'Server refresh';
	state.replace(refreshed, { source: 'server-refresh' });
	assert.equal(history.canUndo, false);
	assert.equal(history.canRedo, false);
	assert.equal(state.current().root.children[0].properties.title, 'Server refresh');
	history.dispose();
});

test('inspector context resolves selection targets and location-scoped diagnostics', () => {
	const editor = new core.EditorState();
	editor.selection.select([0]);
	editor.validation.replace([
		{ code: 'invalid.alpha', message: 'Alpha invalid', location: 'root.children.0.properties.title' },
		{ code: 'invalid.beta', message: 'Beta invalid', location: 'root.children.1.properties.title' },
	]);
	const context = core.buildInspectorContext(project().root, editor.selection, editor.validation);
	assert.equal(context.target.kind, 'single');
	assert.equal(context.target.entries[0].node.type, 'alpha');
	assert.deepEqual(context.diagnostics.map((item) => item.code), ['invalid.alpha']);
});

test('keyboard shortcuts map undo redo and delete while respecting editable targets', () => {
	assert.equal(core.shortcutForEvent({ key: 'z', ctrlKey: true }), 'undo');
	assert.equal(core.shortcutForEvent({ key: 'Z', metaKey: true, shiftKey: true }), 'redo');
	assert.equal(core.shortcutForEvent({ key: 'y', ctrlKey: true }), 'redo');
	assert.equal(core.shortcutForEvent({ key: 'Delete' }), 'delete');
	assert.equal(core.shortcutForEvent({ key: 'z', ctrlKey: true, target: { tagName: 'INPUT' } }), null);
	assert.equal(core.shortcutForEvent({ key: 'Backspace', target: { isContentEditable: true } }), null);

	let prevented = false;
	const history = { undo: () => true };
	const handled = core.handleEditorShortcut(
		{ key: 'z', ctrlKey: true, preventDefault: () => { prevented = true; } },
		{ history }
	);
	assert.equal(handled, true);
	assert.equal(prevented, true);
});

test('command failures are transactional and do not mutate project or editor snapshots', () => {
	const state = new core.ProjectState(project());
	const editor = new core.EditorState();
	editor.selection.select([1]);
	const history = new core.CommandHistory(state, editor);
	const beforeProject = state.snapshot();
	const beforeEditor = editor.snapshot();
	assert.throws(() => history.execute(core.removeNodeCommand([])), /root node cannot be deleted/);
	assert.deepEqual(state.snapshot(), beforeProject);
	assert.deepEqual(editor.snapshot(), beforeEditor);
	assert.equal(history.canUndo, false);
	history.dispose();
});


test('SelectionState publishes only real changes and batches compound selection mutations', () => {
	const selection = new core.SelectionState();
	const events = [];
	const unsubscribe = selection.subscribe((event, snapshot) => events.push({ event, snapshot }));

	assert.equal(selection.select([0], { source: 'ui' }), true);
	assert.equal(selection.select([0], { source: 'ui' }), false);
	assert.equal(events.length, 1);
	assert.equal(events[0].event.source, 'ui');
	assert.equal(events[0].event.action, 'select');

	selection.batch({ source: 'fixture', action: 'no-op' }, () => {
		selection.select([0]);
	});
	assert.equal(events.length, 1, 'A genuine no-op batch must not publish noise.');

	selection.batch({ source: 'command', action: 'insert-node', force: true }, () => {
		selection.remap((path) => [path[0] + 1]);
		selection.select([0]);
	});
	assert.equal(events.length, 2, 'Structural selection must publish when the same path now identifies a different node.');
	assert.equal(events[1].event.action, 'insert-node');
	assert.deepEqual(events[1].snapshot.primary, [0]);

	selection.batch({ source: 'command', action: 'reorder-node' }, () => {
		selection.remap((path) => [path[0] + 1]);
		selection.reconcile(project().root);
	});
	assert.equal(events.length, 3);
	assert.equal(events[2].event.source, 'command');
	assert.equal(events[2].event.action, 'reorder-node');
	assert.deepEqual(events[2].snapshot.primary, [1]);

	const beforeFailure = selection.snapshot();
	assert.throws(() => selection.batch({ source: 'command', action: 'failed' }, () => {
		selection.select([2]);
		throw new Error('selection failure');
	}), /selection failure/);
	assert.deepEqual(selection.snapshot(), beforeFailure);
	assert.equal(events.length, 3, 'Failed batches must roll back without publishing transient selection.');
	unsubscribe();
});

test('command history publishes one coherent final selection event for insert and history restore', () => {
	const state = new core.ProjectState(project());
	const editor = new core.EditorState();
	editor.selection.select([2]);
	const history = new core.CommandHistory(state, editor);
	const events = [];
	editor.selection.subscribe((event, snapshot) => events.push({ event, snapshot }));

	history.execute(core.insertNodeCommand([], 1, node('inserted')));
	assert.equal(events.length, 1);
	assert.equal(events[0].event.source, 'command');
	assert.equal(events[0].event.action, 'insert-node');
	assert.deepEqual(events[0].snapshot.primary, [1]);

	assert.equal(history.undo(), true);
	assert.equal(events.length, 2);
	assert.equal(events[1].event.source, 'history');
	assert.equal(events[1].event.action, 'undo');
	assert.deepEqual(events[1].snapshot.primary, [2]);

	assert.equal(history.redo(), true);
	assert.equal(events.length, 3);
	assert.equal(events[2].event.action, 'redo');
	assert.deepEqual(events[2].snapshot.primary, [1]);
	history.dispose();
});


test('structural project listeners observe the final remapped selection', () => {
	const state = new core.ProjectState(project());
	const editor = new core.EditorState();
	editor.selection.select([0]);
	const observed = [];
	state.subscribe(() => observed.push(editor.selection.primary()));
	const history = new core.CommandHistory(state, editor);

	history.execute(core.reorderNodeCommand([], 0, 2));
	assert.deepEqual(observed, [[2]]);
	assert.deepEqual(editor.selection.primary(), [2]);
	history.dispose();
});

test('undo and redo publish project changes with restored selection already active', () => {
	const state = new core.ProjectState(project());
	const editor = new core.EditorState();
	editor.selection.select([0]);
	const history = new core.CommandHistory(state, editor);
	history.execute(core.reorderNodeCommand([], 0, 2));

	const observed = [];
	state.subscribe((event) => observed.push({ source: event.source, primary: editor.selection.primary() }));
	assert.equal(history.undo(), true);
	assert.equal(history.redo(), true);
	assert.deepEqual(observed, [
		{ source: 'history', primary: [0] },
		{ source: 'history', primary: [2] },
	]);
	history.dispose();
});
