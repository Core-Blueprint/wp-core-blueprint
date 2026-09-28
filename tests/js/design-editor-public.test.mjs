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
	assert.equal(typeof publicEditor.animateLayoutChange, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.motion?.animateLayoutChange, 'function');
	assert.equal(typeof publicEditor.commands?.insertNode, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.commands?.insertNode, 'function');
	assert.equal(typeof publicEditor.profiles['document-flow'].normalizeFlowLayout, 'function');
	assert.equal(typeof publicEditor.profiles['document-fixed'].translateFrame, 'function');
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
