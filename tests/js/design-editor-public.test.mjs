import assert from 'node:assert/strict';
import { cp, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { after, before, test } from 'node:test';

const designDirectory = new URL('../../assets/js/design/', import.meta.url);
let tempDirectory;
let publicEditor;

const convertJsTreeToMjs = async (directory) => {
	const entries = await readdir(directory, { withFileTypes: true });
	for (const entry of entries) {
		const path = join(directory, entry.name);
		if (entry.isDirectory()) {
			await convertJsTreeToMjs(path);
			continue;
		}
		if (!entry.isFile() || !entry.name.endsWith('.js')) continue;
		const source = await readFile(path, 'utf8');
		let esm = source.replaceAll(/(['"])(\.\.?\/[^'"]+)\.js\1/g, '$1$2.mjs$1');
		if (entry.name === 'editor.js') {
			esm = esm.replace("'@cb-core/design-motion'", "'./core/motion.mjs'");
		}
		await writeFile(path.replace(/\.js$/, '.mjs'), esm);
	}
};

before(async () => {
	tempDirectory = await mkdtemp(join(tmpdir(), 'cb-design-editor-public-'));
	const copiedDesignDirectory = join(tempDirectory, 'design');
	await cp(designDirectory, copiedDesignDirectory, { recursive: true });
	await convertJsTreeToMjs(copiedDesignDirectory);
	globalThis.window = {};
	publicEditor = await import(pathToFileURL(join(copiedDesignDirectory, 'editor.mjs')).href);
});

after(async () => {
	delete globalThis.window;
	if (tempDirectory) await rm(tempDirectory, { recursive: true, force: true });
});

const node = (type, children = [], properties = {}) => ({
	type,
	provider: 'fixture.public-editor',
	properties,
	children,
});

const flowProject = () => ({
	schema_version: 0,
	design_type: 'fixture.public-editor.flow',
	root: node('document', [node('text', [], { value: { source: 'literal', text: 'Hello' } })], {
		layout: {
			mode: 'flow',
			units: 'mm',
			page: { width: 210, height: 297 },
			margins: { top: 15, right: 15, bottom: 15, left: 15 },
		},
	}),
});

const fixedProject = () => ({
	schema_version: 0,
	design_type: 'fixture.public-editor.fixed',
	root: node('document', [node('text', [], { frame: { x: 10, y: 10, width: 80, height: 15 } })], {
		layout: { mode: 'fixed', units: 'mm', page: { width: 210, height: 297 } },
	}),
});

test('public facade exposes stable session, shell, motion, commands and profile APIs without consumer private-path imports', () => {
	assert.equal(typeof publicEditor.createSession, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.createSession, 'function');
	assert.equal(typeof publicEditor.createDesignerShell, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.create, 'function');
	assert.equal(typeof publicEditor.createDesignerSelectionController, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.selection?.createController, 'function');
	assert.equal(typeof publicEditor.createDesignerInspectorIdentity, 'function');
	assert.equal(typeof publicEditor.createDesignerInspectorControls, 'function');
	assert.equal(typeof publicEditor.createDesignerInspectorToggle, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.inspector?.createIdentity, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.inspector?.createControls, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.inspector?.createToggle, 'function');
	assert.equal(typeof publicEditor.animateLayoutChange, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.motion?.animateLayoutChange, 'function');
	assert.equal(typeof publicEditor.commands?.insertNode, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.commands?.insertNode, 'function');
	assert.equal(typeof publicEditor.profiles['document-flow'].normalizeFlowLayout, 'function');
	assert.equal(typeof publicEditor.profiles['document-flow'].createFlowPreviewHost, 'function');
	assert.equal(typeof publicEditor.profiles['document-fixed'].translateFrame, 'function');
});

test('canonical Inspector helpers own identity and one-row boolean control markup', () => {
	const documentRef = {
		createElement(tagName) {
			return {
				tagName,
				className: '',
				textContent: '',
				type: '',
				checked: false,
				disabled: false,
				dataset: {},
				children: [],
				append(...children) {
					this.children.push(...children);
				},
			};
		},
	};

	const identity = publicEditor.createDesignerInspectorIdentity({ label: 'Header', documentRef });
	assert.equal(identity.element.className, 'cb-core-design-shell__inspector-identity');
	assert.equal(identity.title.className, 'cb-core-design-shell__inspector-title');
	assert.equal(identity.title.textContent, 'Header');
	identity.setLabel('Footer');
	assert.equal(identity.title.textContent, 'Footer');

	const controls = publicEditor.createDesignerInspectorControls({ documentRef });
	assert.equal(controls.className, 'cb-core-design-shell__inspector-controls');

	const toggle = publicEditor.createDesignerInspectorToggle({
		label: 'Visible',
		checked: true,
		documentRef,
		dataset: { cbFixture: 'visible' },
	});
	assert.equal(toggle.element.className, 'cb-core-design-shell__inspector-toggle');
	assert.equal(toggle.label.className, 'cb-core-design-shell__inspector-toggle-label');
	assert.equal(toggle.label.textContent, 'Visible');
	assert.equal(toggle.control.type, 'checkbox');
	assert.equal(toggle.control.checked, true);
	assert.equal(toggle.control.dataset.cbFixture, 'visible');
	assert.deepEqual(toggle.element.children, [toggle.label, toggle.control]);
});

test('flow consumer session owns history while persistence remains consumer-controlled', () => {
	const changes = [];
	const session = publicEditor.createSession({
		project: flowProject(),
		profile: 'document-flow',
		onChange: (project, context) => changes.push({ project, context }),
	});

	session.execute(publicEditor.setPropertyCommand([0], ['value', 'text'], 'Changed'));
	assert.equal(session.project().root.children[0].properties.value.text, 'Changed');
	assert.equal(changes.length, 1);
	assert.equal(changes[0].context.profile.id, 'document-flow');
	assert.equal(session.undo(), true);
	assert.equal(session.project().root.children[0].properties.value.text, 'Hello');
	assert.equal(session.redo(), true);
	assert.equal(session.project().root.children[0].properties.value.text, 'Changed');
	session.dispose();
});

test('selected profile remains an invariant across commands and replacements', () => {
	const session = publicEditor.createSession({ project: flowProject(), profile: 'document-flow' });
	const before = session.snapshot();
	assert.throws(
		() => session.execute(publicEditor.setPropertyCommand([], ['layout', 'mode'], 'fixed')),
		/Flow layout must use the root-owned millimetre contract/
	);
	assert.deepEqual(session.snapshot(), before);

	const replacement = flowProject();
	replacement.root.properties.layout.mode = 'fixed';
	assert.throws(
		() => session.replace(replacement, { source: 'server-refresh' }),
		/Flow layout must use the root-owned millimetre contract/
	);
	assert.deepEqual(session.snapshot(), before);
	session.dispose();
});

test('consumer validation feeds shared session feedback without entering persisted project state', () => {
	const session = publicEditor.createSession({
		project: flowProject(),
		profile: 'document-flow',
		validate: (project, context) => {
			assert.equal(context.profile.id, 'document-flow');
			return project.root.children.length > 0
				? [{ code: 'fixture.required', message: 'Fixture diagnostic', location: 'root.children.0' }]
				: [];
		},
	});

	assert.equal(session.editorState.validation.count(), 1);
	assert.equal(Object.hasOwn(session.snapshot(), 'editor_state'), false);
	assert.throws(() => JSON.stringify(session.editorState), /session-only/);
	session.dispose();
});

test('consumer command policy can deny destructive operations without changing project state', () => {
	const session = publicEditor.createSession({
		project: flowProject(),
		profile: 'document-flow',
		allowCommand: (command) => command.label !== 'remove-node',
	});
	const before = session.snapshot();
	assert.throws(
		() => session.execute(publicEditor.removeNodeCommand([0])),
		/denied by consumer policy/
	);
	assert.deepEqual(session.snapshot(), before);
	session.dispose();
});

test('fixed profile is available through the same public boundary', () => {
	const session = publicEditor.createSession({ project: fixedProject(), profile: 'document-fixed' });
	const frame = session.profile.api.translateFrame(
		{ x: 10, y: 10, width: 80, height: 15 },
		5,
		7,
		{ width: 210, height: 297 }
	);
	assert.deepEqual(frame, { x: 15, y: 17, width: 80, height: 15 });
	session.dispose();
});

test('unknown profiles fail closed', () => {
	assert.throws(
		() => publicEditor.createSession({ project: flowProject(), profile: 'commerce-financial' }),
		/Unknown Design Foundation editor profile/
	);
});


test('session selection lifecycle validates paths, reconciles replacement and exposes inspector context', () => {
	const changes = [];
	const session = publicEditor.createSession({
		project: flowProject(),
		profile: 'document-flow',
		onSelectionChange: (snapshot, context) => changes.push({ snapshot, context }),
	});

	assert.equal(session.select([0], { source: 'layers' }), true);
	assert.equal(changes.length, 1);
	assert.equal(changes[0].context.event.source, 'layers');
	assert.equal(changes[0].context.inspector.target.kind, 'single');
	assert.deepEqual(changes[0].snapshot.primary, [0]);
	assert.equal(session.select([99], { source: 'layers' }), false);
	assert.equal(changes.length, 1);

	const replacement = flowProject();
	replacement.root.children = [];
	session.replace(replacement, { source: 'context-switch' });
	assert.equal(changes.length, 2);
	assert.equal(changes[1].context.event.source, 'replace');
	assert.equal(changes[1].context.event.action, 'context-switch');
	assert.equal(changes[1].snapshot.primary, null);
	session.dispose();
});

test('public historyStatus exposes frozen undo/redo capability without CommandHistory access', () => {
	const session = publicEditor.createSession({ project: flowProject(), profile: 'document-flow' });
	assert.deepEqual(session.historyStatus(), { canUndo: false, canRedo: false, size: 0 });
	assert.equal(Object.isFrozen(session.historyStatus()), true);

	session.execute(publicEditor.setPropertyCommand([0], ['value', 'text'], 'Revised'));
	assert.deepEqual(session.historyStatus(), { canUndo: true, canRedo: false, size: 1 });
	assert.equal(session.undo(), true);
	assert.deepEqual(session.historyStatus(), { canUndo: false, canRedo: true, size: 0 });
	assert.equal(session.redo(), true);
	assert.deepEqual(session.historyStatus(), { canUndo: true, canRedo: false, size: 1 });
	session.replace(flowProject(), { source: 'server-refresh' });
	assert.deepEqual(session.historyStatus(), { canUndo: false, canRedo: false, size: 0 });
	session.dispose();
	assert.throws(() => session.historyStatus(), /disposed/);
});

test('public setSelection provides atomic multi-path and external projection without persisting selection', () => {
	const events = [];
	const session = publicEditor.createSession({
		project: flowProject(),
		profile: 'document-flow',
		onSelectionChange: (snapshot, context) => events.push({ snapshot, action: context.event.action }),
	});
	assert.equal(session.setSelection([[0]], { primary: [0], source: 'layers' }), true);
	assert.deepEqual(session.selection(), { paths: [[0]], primary: [0] });
	assert.equal(session.setSelection([[0]], { primary: [0], source: 'layers' }), false);
	assert.equal(events.length, 1);

	const before = session.selection();
	assert.throws(() => session.setSelection([[0], [42]], { source: 'layers' }), /outside the current project/);
	assert.deepEqual(session.selection(), before, 'invalid project paths must not partially mutate selection');

	assert.equal(session.setSelection([[0], [42], [42]], { primary: [42], source: 'external-projection', external: true }), true);
	assert.deepEqual(session.selection(), { paths: [[0], [42]], primary: [42] });
	assert.equal(events.length, 2, 'one coherent event must publish the external set');
	assert.equal(events[1].action, 'set');
	assert.deepEqual(Object.keys(session.snapshot()).includes('editor_state'), false, 'selection must not be persisted');
	assert.throws(() => session.setSelection([[-1]], { external: true }), /non-negative integers/);
	assert.throws(() => session.setSelection([[0]], { primary: [-1], external: true }), /non-negative integers/);
	assert.deepEqual(session.selection(), { paths: [[0], [42]], primary: [42] }, 'invalid external paths or primary must not mutate selection');

	assert.equal(session.clearSelection({ source: 'external-projection' }), true);
	assert.deepEqual(session.selection(), { paths: [], primary: null });
	session.dispose();
	assert.throws(() => session.setSelection([[0]], { external: true }), /disposed/);
});

test('canonical selection controller synchronizes Layers Inspector and canvas and opens Inspector for UI and insert selection', () => {
	const session = publicEditor.createSession({ project: flowProject(), profile: 'document-flow' });
	const order = [];
	const panels = [];
	const controller = publicEditor.createDesignerSelectionController({
		session,
		shell: { activatePanel: (panel) => panels.push(panel) },
		renderLayers: (context) => order.push(['layers', context.primary]),
		renderInspector: (context) => order.push(['inspector', context.inspector.target.kind]),
		syncCanvas: (context) => order.push(['canvas', context.primary]),
	});

	assert.deepEqual(order.slice(0, 3).map(([name]) => name), ['layers', 'inspector', 'canvas']);
	order.length = 0;

	assert.equal(controller.select([0]), true);
	assert.deepEqual(order.map(([name]) => name), ['layers', 'inspector', 'canvas']);
	assert.deepEqual(panels, ['inspector']);
	order.length = 0;

	session.execute(publicEditor.insertNodeCommand([], 1, node('text', [], { value: { source: 'literal', text: 'Inserted' } })));
	assert.deepEqual(order.map(([name]) => name), ['layers', 'inspector', 'canvas']);
	assert.deepEqual(panels, ['inspector', 'inspector']);
	assert.deepEqual(session.selection().primary, [1]);

	controller.destroy();
	session.dispose();
});


test('same-path insert still publishes semantic selection and keeps the controller synchronized', () => {
	const session = publicEditor.createSession({ project: flowProject(), profile: 'document-flow' });
	session.select([0]);
	const changes = [];
	session.subscribeSelection((snapshot, context) => changes.push({ snapshot, context }));

	session.execute(publicEditor.insertNodeCommand([], 0, node('text', [], { value: { source: 'literal', text: 'Inserted first' } })));
	assert.equal(changes.length, 1);
	assert.equal(changes[0].context.event.source, 'command');
	assert.equal(changes[0].context.event.action, 'insert-node');
	assert.deepEqual(changes[0].snapshot.primary, [0]);
	session.dispose();
});

test('project change consumers observe reconciled selection for structural commands and replacement', () => {
	let session = null;
	const observed = [];
	session = publicEditor.createSession({
		project: flowProject(),
		profile: 'document-flow',
		onChange: (project, context) => observed.push({
			source: context.event.source,
			count: project.root.children.length,
			primary: session.selection().primary,
		}),
	});
	session.select([0]);

	session.execute(publicEditor.insertNodeCommand([], 0, node('text', [], { value: { source: 'literal', text: 'Inserted first' } })));
	assert.deepEqual(observed[0], { source: 'command', count: 2, primary: [0] });

	const replacement = flowProject();
	replacement.root.children = [];
	session.replace(replacement, { source: 'context-switch' });
	assert.deepEqual(observed[1], { source: 'context-switch', count: 0, primary: null });
	session.dispose();
});
