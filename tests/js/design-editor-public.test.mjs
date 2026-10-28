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
	assert.equal(typeof publicEditor.decorateDesignerElement, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.icons?.decorateElement, 'function');
	assert.equal(typeof publicEditor.createDesignerNavigation, 'function');
	assert.equal(typeof publicEditor.createDesignerCanvasNavigation, 'function');
	assert.equal(typeof publicEditor.createDesignerContextMenu, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.contextMenu?.create, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.canvasNavigation?.create, 'function');
	assert.deepEqual(publicEditor.DESIGNER_PALETTE_ROLES, ['navigation', 'elements', 'layers']);
	assert.equal(typeof window.cbCore?.designEditor?.shell?.navigation?.create, 'function');
	assert.deepEqual(window.cbCore?.designEditor?.shell?.paletteRoles, ['navigation', 'elements', 'layers']);
	assert.equal(typeof window.cbCore?.designEditor?.shell?.create, 'function');
	assert.equal(typeof publicEditor.createDesignerSelectionController, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.selection?.createController, 'function');
	assert.equal(typeof publicEditor.createDesignerInspectorIdentity, 'function');
	assert.equal(typeof publicEditor.createDesignerInspectorControls, 'function');
	assert.equal(typeof publicEditor.createDesignerInspectorToggle, 'function');
	assert.equal(typeof publicEditor.createDesignerInspectorSection, 'function');
	assert.equal(typeof publicEditor.enableDesignerTextareaAutosize, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.inspector?.createIdentity, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.inspector?.createControls, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.inspector?.createToggle, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.inspector?.createSection, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.shell?.inspector?.autosizeTextarea, 'function');
	assert.equal(typeof publicEditor.animateLayoutChange, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.motion?.animateLayoutChange, 'function');
	assert.equal(typeof publicEditor.commands?.insertNode, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.commands?.insertNode, 'function');
	assert.equal(typeof publicEditor.profiles['document-flow'].normalizeFlowLayout, 'function');
	assert.equal(typeof publicEditor.profiles['document-flow'].createFlowPreviewHost, 'function');
	assert.equal(typeof publicEditor.profiles['document-fixed'].translateFrame, 'function');
});

test('documented public Designer ESM imports resolve in the actual module, not only the global facade', async () => {
	const documentation = await readFile(new URL('../../docs/DESIGNER-FOUNDATION-API.md', import.meta.url), 'utf8');
	const section = documentation.split('## Canonical browser module\n')[1]?.split('\n## ')[0] || '';
	const code = section.match(/```js\s*([\s\S]*?)```/)?.[1] || '';
	const importList = code.match(/import\s*\{([^}]+)\}\s*from\s*['"]@cb-core\/design-editor['"]/)?.[1] || '';
	assert.ok(importList, 'the canonical ESM example must import from the real registered Base module');
	for (const named of importList.split(',').map((item) => item.trim()).filter(Boolean)) {
		assert.ok(Object.hasOwn(publicEditor, named), `documented ESM named export ${named} must exist`);
	}
	assert.equal(typeof publicEditor.animateLayoutChange, 'function');
	assert.equal(Object.hasOwn(publicEditor, 'motion'), false, 'motion is a global facade convenience, not a named ESM export');
	assert.equal(typeof window.cbCore?.designEditor?.motion?.animateLayoutChange, 'function');
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

test('compact Inspector primitives preserve session-owned disclosure and optional textarea sizing', async () => {
	const createElement = (tagName) => {
		const el = {
			tagName: tagName.toUpperCase(),
			className: '', textContent: '', open: false, scrollHeight: 84,
			style: {}, children: [], listeners: new Map(),
			append(...items) { this.children.push(...items); },
			addEventListener(type, handler) { this.listeners.set(type, handler); },
			fire(type) { this.listeners.get(type)?.(); },
		};
		el.classList = { add(name) { el.className += ' ' + name; } };
		return el;
	};
	const documentRef = { createElement };
	const identity = publicEditor.createDesignerInspectorIdentity({
		label: 'Question', eyebrow: 'Multiple Choice', context: 'Your current stack',
		compact: true, documentRef,
	});
	assert.match(identity.element.className, /inspector-identity--compact/);
	assert.equal(identity.title.textContent, 'Question');
	assert.equal(identity.element.children.length, 3);

	let toggled = null;
	const section = publicEditor.createDesignerInspectorSection({
		title: 'Behavior', summary: '2 settings', expanded: false,
		documentRef, onToggle: (open) => { toggled = open; },
	});
	assert.equal(section.element.open, false);
	assert.equal(section.heading.children[0].textContent, 'Behavior');
	assert.equal(section.heading.children[1].textContent, '2 settings');
	section.element.open = true;
	section.element.fire('toggle');
	assert.equal(toggled, true);

	const textarea = createElement('textarea');
	const resize = publicEditor.enableDesignerTextareaAutosize(textarea);
	await Promise.resolve();
	assert.equal(textarea.style.height, '84px');
	textarea.scrollHeight = 140;
	textarea.fire('input');
	assert.equal(textarea.style.height, '140px');
	assert.equal(typeof resize, 'function');
	assert.throws(() => publicEditor.enableDesignerTextareaAutosize(createElement('input')), TypeError);
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

// Typed refs/contextEpoch are canonical; these older selection tests assert
// only the backwards-compatible node-path projection without hiding refs.
const nodePathSelection = (snapshot) => {
	assert.ok(Array.isArray(snapshot.refs), 'typed selection refs remain public');
	assert.ok(Number.isSafeInteger(snapshot.contextEpoch), 'typed context epoch remains public');
	assert.deepEqual(snapshot.refs.map((ref) => {
		assert.equal(ref.kind, 'node', 'this fixture uses node-only selection');
		return ref.path;
	}), snapshot.paths, 'typed refs and node-path projection stay equivalent');
	assert.deepEqual(snapshot.primaryRef?.path ?? null, snapshot.primary,
		'typed primary identity and legacy projection stay equivalent');
	return { paths: snapshot.paths, primary: snapshot.primary };
};

test('public setSelection provides atomic multi-path and external projection without persisting selection', () => {
	const events = [];
	const session = publicEditor.createSession({
		project: flowProject(),
		profile: 'document-flow',
		onSelectionChange: (snapshot, context) => events.push({ snapshot, action: context.event.action }),
	});
	assert.equal(session.setSelection([[0]], { primary: [0], source: 'layers' }), true);
	assert.deepEqual(nodePathSelection(session.selection()), { paths: [[0]], primary: [0] });
	assert.equal(session.setSelection([[0]], { primary: [0], source: 'layers' }), false);
	assert.equal(events.length, 1);

	const before = nodePathSelection(session.selection());
	assert.throws(() => session.setSelection([[0], [42]], { source: 'layers' }), /outside the current project/);
	assert.deepEqual(nodePathSelection(session.selection()), before, 'invalid project paths must not partially mutate selection');

	assert.equal(session.setSelection([[0], [42], [42]], { primary: [42], source: 'external-projection', external: true }), true);
	assert.deepEqual(nodePathSelection(session.selection()), { paths: [[0], [42]], primary: [42] });
	assert.equal(events.length, 2, 'one coherent event must publish the external set');
	assert.equal(events[1].action, 'set');
	assert.deepEqual(Object.keys(session.snapshot()).includes('editor_state'), false, 'selection must not be persisted');
	assert.throws(() => session.setSelection([[-1]], { external: true }), /non-negative integers/);
	assert.throws(() => session.setSelection([[0]], { primary: [-1], external: true }), /non-negative integers/);
	assert.deepEqual(nodePathSelection(session.selection()), { paths: [[0], [42]], primary: [42] }, 'invalid external paths or primary must not mutate selection');

	assert.equal(session.clearSelection({ source: 'external-projection' }), true);
	assert.deepEqual(nodePathSelection(session.selection()), { paths: [], primary: null });
	session.dispose();
	assert.throws(() => session.setSelection([[0]], { external: true }), /disposed/);
});

test('public setSelection rejects non-member primary atomically in both strict and external modes', () => {
	const events = [];
	const session = publicEditor.createSession({
		project: flowProject(),
		profile: 'document-flow',
		onSelectionChange: (snapshot, context) => events.push({ snapshot, action: context.event.action }),
	});
	assert.equal(session.setSelection([[0]], { source: 'consumer' }), true);
	const before = nodePathSelection(session.selection());
	const revisionEvents = events.length;

	// [] addresses the existing root, but it was not selected.
	assert.throws(
		() => session.setSelection([[0]], { primary: [], source: 'invalid-primary' }),
		/primary must be one of the selected paths/,
	);
	assert.throws(
		() => session.setSelection([[0], [42]], { primary: [99], external: true }),
		/primary must be one of the selected paths/,
	);
	assert.throws(
		() => session.setSelection([], { primary: [0], external: true }),
		/primary must be one of the selected paths/,
	);
	assert.deepEqual(nodePathSelection(session.selection()), before, 'invalid primary must not change state');
	assert.equal(events.length, revisionEvents, 'invalid primary must not publish an event');

	assert.equal(session.setSelection([[0], [0], [42]], { primary: [42], external: true }), true);
	assert.deepEqual(nodePathSelection(session.selection()), { paths: [[0], [42]], primary: [42] }, 'deduplication must preserve valid primary');
	assert.equal(events.length, revisionEvents + 1);
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


const domainProject = (description = 'First') => ({
	// Source-only consumer unit fixture has no verified installed Host registry.
	// Only schema generation 0 is permitted without that trusted context.
	schema_version: 0,
	design_type: 'fixture.domain',
	root: { id: 'domain', type: 'domain', props: { description }, children: [] },
});

const editDomain = (description) => ({
	label: 'edit-domain',
	apply(project) {
		const next = structuredClone(project);
		next.root.props.description = description;
		return { project: next };
	},
});

test('untrusted domain unit sessions reject stable generation 1 without Host registry', () => {
	const candidate = domainProject('Rejected');
	candidate.schema_version = 1;
	assert.throws(() => publicEditor.createDomainSession({ project: candidate }),
		/verified Host registry context/);
});

test('P02 domain session validates consumer commands and owns isolated reversible history', () => {
	assert.equal(typeof publicEditor.createDomainSession, 'function');
	assert.equal(typeof window.cbCore?.designEditor?.createDomainSession, 'function');
	const changes = [];
	const session = publicEditor.createDomainSession({
		project: domainProject(),
		validateProject(project) {
			if (project.root.type !== 'domain') throw new TypeError('Only domain projects allowed');
		},
		allowCommand: (command) => command.label !== 'forbidden',
		onChange: (project, event) => changes.push([project.root.props.description, event.source]),
		historyLimit: 2,
	});
	const snapshot = session.snapshot();
	snapshot.root.props.description = 'Mutated externally';
	assert.equal(session.project().root.props.description, 'First');
	assert.throws(() => { session.project().root.props.description = 'Mutation'; }, TypeError);
	assert.throws(() => session.execute({ label: 'forbidden', apply: () => ({ project: domainProject('Forbidden') }) }), /denied/);
	assert.equal(session.historyStatus().size, 0);
	session.execute(editDomain('Second'));
	assert.deepEqual({ ...session.historyStatus() }, { canUndo: true, canRedo: false, size: 1 });
	assert.equal(session.project().root.props.description, 'Second');
	assert.equal(session.undo(), true);
	assert.equal(session.project().root.props.description, 'First');
	assert.equal(session.redo(), true);
	assert.equal(session.project().root.props.description, 'Second');
	assert.deepEqual(changes.map(([name]) => name), ['Second', 'First', 'Second']);
	assert.throws(() => session.replace({ ...domainProject(), root: { ...domainProject().root, type: 'document' } }), /Only domain projects/);
	assert.equal(session.project().root.props.description, 'Second');
	session.replace(domainProject('Server'), { source: 'baseline' });
	assert.deepEqual({ ...session.historyStatus() }, { canUndo: false, canRedo: false, size: 0 });
	session.execute(editDomain('Draft'));
	session.clearHistory();
	assert.equal(session.historyStatus().size, 0);
	session.dispose();
	assert.throws(() => session.snapshot(), /disposed/);
	assert.throws(() => session.historyStatus(), /disposed/);
	assert.throws(() => session.execute(editDomain('Later')), /disposed/);
});

test('P02 concurrent domain sessions isolate snapshots and undo stacks', () => {
	const first = publicEditor.createDomainSession({ project: domainProject('One') });
	const second = publicEditor.createDomainSession({ project: domainProject('Two') });
	first.execute(editDomain('Changed'));
	assert.equal(first.historyStatus().canUndo, true);
	assert.equal(second.historyStatus().canUndo, false);
	assert.equal(second.project().root.props.description, 'Two');
	first.dispose();
	second.dispose();
});

test('G1b: project observers read fully committed history, ordered before history listeners', () => {
	const order = [];
	const session = publicEditor.createDomainSession({
		project: domainProject('A'),
		onChange() {
			order.push('project');
			assert.equal(session.historyStatus().canUndo, true);
		},
	});
	session.subscribeHistory((event) => {
		order.push('history');
		assert.equal(Object.isFrozen(event), true);
		assert.equal(Object.isFrozen(event.status), true);
		assert.equal(event.status.canUndo, session.historyStatus().canUndo);
	});
	session.execute(editDomain('B'));
	assert.deepEqual(order, ['project', 'history']);
	assert.equal(session.historyStatus().size, 1);
	session.dispose();
});

test('G1b: observer failures isolate callbacks and publish one sanitized diagnostic', () => {
	const project = domainProject('A');
	const session = publicEditor.createDomainSession({ project });
	const notices = [], diagnostics = [];
	session.subscribe(() => { throw new Error('PRIVATE SECRET WITH ID 0001'); });
	session.subscribe(() => notices.push('project'));
	session.subscribeHistory(() => { throw new Error('SECRET HISTORY'); });
	session.subscribeHistory(() => notices.push('history'));
	session.subscribeDiagnostics((d) => {
		assert.deepEqual(Object.keys(d).sort(), ['code', 'phase', 'recoverable']);
		assert.equal(d.code, 'CB_DESIGNER_OBSERVER_FAILED');
		assert.equal(d.phase, 'multiple');
		assert.equal(Object.isFrozen(d), true);
		diagnostics.push(d);
		throw new Error('DIAGNOSTIC FAILURE');
	});
	session.subscribeDiagnostics(() => notices.push('diagnostic'));
	session.execute(editDomain('B'));
	assert.deepEqual(notices, ['project', 'history', 'diagnostic']);
	assert.equal(diagnostics.length, 1);
	assert.equal(session.project().root.props.description, 'B');
	session.dispose();
});

test('G1b: normal Save preserves earlier undo and later distinct intent steps', () => {
	const session = publicEditor.createDomainSession({ project: domainProject('A') });
	const events = [];
	session.subscribeHistory((event) => events.push(event));
	session.execute(editDomain('B'));
	const save = session.captureSave();
	assert.equal(Object.isFrozen(save), true);
	assert.equal(Object.isFrozen(save.submittedProject), true);
	session.execute(editDomain('C'));
	session.execute(editDomain('D'));
	const result = session.rebase({ saveToken: save.token, baselineProject: domainProject('B') });
	assert.equal(result.normalized, false);
	assert.equal(result.retainedUndoSteps, 3);
	assert.equal(session.baselineSnapshot().root.props.description, 'B');
	assert.equal(session.project().root.props.description, 'D');
	assert.deepEqual(events.map((e) => e.action), ['execute', 'execute', 'execute', 'rebase']);
	assert.equal(session.undo(), true);
	assert.equal(session.project().root.props.description, 'C');
	assert.equal(session.undo(), true);
	assert.equal(session.project().root.props.description, 'B');
	assert.equal(session.undo(), true);
	assert.equal(session.project().root.props.description, 'A');
	assert.equal(session.redo(), true);
	assert.equal(session.redo(), true);
	assert.equal(session.redo(), true);
	assert.equal(session.project().root.props.description, 'D');
	assert.throws(() => session.rebase({ saveToken: save.token, baselineProject: domainProject('B') }), (e) => e.code === 'SAVE_STALE' || e.code === 'SAVE_INVALID');
	session.dispose();
});

test('G1b: Save acknowledgement is latest-issued-wins and rejects wrong context', () => {
	const session = publicEditor.createDomainSession({ project: domainProject('A') });
	const changes = [];
	session.subscribeHistory((e) => changes.push(e.action));
	const first = session.captureSave();
	session.execute(editDomain('B'));
	const second = session.captureSave();
	assert.throws(
		() => session.rebase({ saveToken: first.token, baselineProject: domainProject('A') }),
		(e) => e.code === 'SAVE_STALE',
	);
	assert.equal(session.project().root.props.description, 'B');
	assert.equal(session.historyStatus().size, 1);
	assert.equal(changes.length, 1);
	session.rebase({ saveToken: second.token, baselineProject: domainProject('B') });
	assert.equal(session.baselineSnapshot().root.props.description, 'B');
	const staleContext = session.captureSave();
	session.replace(domainProject('B'), { source: 'context-switch', contextChanged: true });
	assert.throws(
		() => session.rebase({ saveToken: staleContext.token, baselineProject: domainProject('B') }),
		(e) => e.code === 'SAVE_CONTEXT_CHANGED',
	);
	session.dispose();
});

test('G1b: rejected normalized rebase preserves entire live draft and history', () => {
	const session = publicEditor.createDomainSession({ project: domainProject('A') });
	session.execute(editDomain('B'));
	const save = session.captureSave();
	session.execute(editDomain('C'));
	const old = session.snapshot(), status = session.historyStatus(), baseline = session.baselineSnapshot();
	const events = [];
	session.subscribeHistory((e) => events.push(e.action));
	assert.throws(
		() => session.rebase({ saveToken: save.token, baselineProject: domainProject('Normalized B') }),
		(e) => e.code === 'SAVE_REPLAY_CONFLICT',
	);
	assert.deepEqual(session.snapshot(), old);
	assert.deepEqual(session.historyStatus(), status);
	assert.deepEqual(session.baselineSnapshot(), baseline);
	assert.deepEqual(events, []);
	session.dispose();
});

test('G1b: normalized replay requires explicit verified per-intent commands', () => {
	const session = publicEditor.createDomainSession({
		project: domainProject('A'),
		verifyRebase: ({ submittedProject, acceptedBaseline, replayedCurrent }) =>
			submittedProject.root.props.description === 'A'
			&& acceptedBaseline.root.props.description === 'A normalized'
			&& replayedCurrent.root.props.description === 'C',
	});
	const save = session.captureSave();
	session.execute(editDomain('B'));
	session.execute(editDomain('C'));
	const result = session.rebase({ saveToken: save.token, baselineProject: domainProject('A normalized') });
	assert.equal(result.normalized, true);
	assert.equal(session.project().root.props.description, 'C');
	assert.equal(session.undo(), true);
	assert.equal(session.project().root.props.description, 'B');
	assert.equal(session.undo(), true);
	assert.equal(session.project().root.props.description, 'A normalized');
	assert.equal(session.redo(), true);
	assert.equal(session.redo(), true);
	assert.equal(session.project().root.props.description, 'C');
	session.dispose();
});

test('G1b: new history methods exist on document profiles and preserve Save history', () => {
	const session = publicEditor.createSession({ project: flowProject(), profile: 'document-flow' });
	for (const method of ['subscribeHistory', 'subscribeDiagnostics', 'captureSave', 'baselineSnapshot', 'rebase', 'clearHistory']) {
		assert.equal(typeof session[method], 'function', method);
	}
	const checkpoint = session.captureSave();
	const result = session.rebase({ saveToken: checkpoint.token, baselineProject: checkpoint.submittedProject });
	assert.equal(result.normalized, false);
	assert.deepEqual(session.baselineSnapshot(), checkpoint.submittedProject);
	session.dispose();
});

test('G1b: semantic no-op and rejected command leave redo and revisions untouched', () => {
	const session = publicEditor.createDomainSession({
		project: domainProject('A'),
		validateProject: (project) => {
			if (project.root.props.description === 'BAD') throw new Error('INVALID');
		},
	});
	const events = [];
	session.subscribeHistory((event) => events.push(event));
	session.execute(editDomain('B'));
	session.undo();
	assert.equal(session.historyStatus().canRedo, true);
	session.execute({ label: 'same-value', apply: (project) => ({ project: structuredClone(project) }) });
	assert.equal(events.length, 2);
	assert.equal(session.historyStatus().canRedo, true);
	assert.throws(() => session.execute(editDomain('BAD')), /INVALID/);
	assert.equal(session.historyStatus().canRedo, true);
	assert.equal(events.length, 2);
	session.dispose();
});

test('G1b: acknowledged Save retains active redo branch and baseline dirty equality', () => {
	const session = publicEditor.createDomainSession({ project: domainProject('A') });
	session.execute(editDomain('B'));
	session.execute(editDomain('C'));
	session.undo();
	const checkpoint = session.captureSave();
	session.rebase({ saveToken: checkpoint.token, baselineProject: checkpoint.submittedProject });
	assert.equal(session.historyStatus().canRedo, true);
	assert.equal(session.historyStatus().size, 1);
	assert.deepEqual(session.baselineSnapshot(), session.project());
	session.redo();
	assert.equal(session.project().root.props.description, 'C');
	assert.equal(session.undo(), true);
	assert.deepEqual(session.baselineSnapshot(), session.project());
	session.dispose();
});

test('G1b: reentrant observer mutation is rejected without cancelling committed edit', () => {
	const session = publicEditor.createDomainSession({ project: domainProject('A') });
	const phases = [];
	session.subscribe(() => {
		assert.throws(() => session.execute(editDomain('NESTED')), (error) => error.code === 'DESIGNER_REENTRANT');
		phases.push('guarded');
	});
	session.subscribeHistory(() => phases.push('history'));
	session.execute(editDomain('B'));
	assert.deepEqual(phases, ['guarded', 'history']);
	assert.equal(session.project().root.props.description, 'B');
	session.dispose();
});


const g1cMailProject = () => ({
	schema_version: 0,
	design_type: 'mail-template',
	root: {
		type: 'mail.root',
		provider: 'core',
		properties: {
			layout: {
				width: 600,
				background: '#f3f4f6',
				contentBackground: '#ffffff',
				fontFamily: 'Arial, Helvetica, sans-serif',
				textColor: '#1f2937',
				accentColor: '#2563eb',
			},
		},
		children: [{
			type: 'mail.section',
			provider: 'core',
			properties: { padding: 32 },
			children: [{
				type: 'mail.text',
				provider: 'core',
				properties: { text: 'Hello' },
				children: [],
			}],
		}],
	},
});

test('G1c: public v2 session contract parity across domain, Mail, flow and fixed profiles', () => {
	const cases = [
		{
			name: 'domain',
			create: () => publicEditor.createDomainSession({ project: domainProject('A') }),
			change: (session) => session.execute(editDomain('B')),
		},
		{
			name: 'mail',
			create: () => publicEditor.createSession({ profile: 'mail', project: g1cMailProject() }),
			change: (session) => session.execute(publicEditor.commands.setProperty([0, 0], ['text'], 'Changed')),
		},
		{
			name: 'document-flow',
			create: () => publicEditor.createSession({ profile: 'document-flow', project: flowProject() }),
			change: (session) => session.execute(publicEditor.commands.setProperty([0], ['value', 'text'], 'Changed')),
		},
		{
			name: 'document-fixed',
			create: () => publicEditor.createSession({ profile: 'document-fixed', project: fixedProject() }),
			change: (session) => session.execute(publicEditor.commands.setProperty([0], ['frame', 'x'], 12)),
		},
	];
	const required = [
		'project', 'snapshot', 'replace', 'execute', 'undo', 'redo',
		'historyStatus', 'subscribeHistory', 'clearHistory',
		'captureSave', 'baselineSnapshot', 'rebase', 'subscribeDiagnostics', 'dispose',
	];
	for (const consumer of cases) {
		const session = consumer.create();
		try {
			for (const name of required) assert.equal(typeof session[name], 'function', consumer.name + '.' + name);
			assert.equal(Object.isFrozen(session), true, consumer.name + ' session');
			const oldProject = session.project();
			const before = session.baselineSnapshot();
			const history = [];
			const unsubscribe = session.subscribeHistory((e) => history.push(e));
			const unsubscribeDiagnostics = session.subscribeDiagnostics(() => assert.fail('unexpected diagnostics'));
			consumer.change(session);
			assert.equal(history.length, 1, consumer.name + ' execute event');
			assert.equal(history[0].action, 'execute');
			assert.equal(Object.isFrozen(history[0]), true);
			assert.equal(Object.isFrozen(history[0].status), true);
			assert.equal(session.historyStatus().canUndo, true);
			const checkpoint = session.captureSave();
			assert.equal(Object.isFrozen(checkpoint), true);
			assert.equal(Object.isFrozen(checkpoint.submittedProject), true);
			assert.notEqual(checkpoint.submittedProject, session.project());
			assert.equal(Object.getPrototypeOf(checkpoint.token), null);
			assert.equal(JSON.stringify(checkpoint.token), '{}');
			const saved = session.rebase({ saveToken: checkpoint.token, baselineProject: checkpoint.submittedProject });
			assert.equal(saved.normalized, false);
			assert.equal(saved.retainedUndoSteps, 1);
			assert.equal(session.historyStatus().canUndo, true, consumer.name + ' retained undo');
			assert.deepEqual(session.baselineSnapshot(), checkpoint.submittedProject);
			assert.equal(session.undo(), true);
			assert.deepEqual(session.project(), oldProject);
			assert.deepEqual(session.baselineSnapshot(), checkpoint.submittedProject, consumer.name + ' immutable baseline');
			assert.equal(session.redo(), true);
			assert.deepEqual(session.project(), checkpoint.submittedProject);
			assert.equal(session.historyStatus().size, 1);
			assert.deepEqual(history.map((e) => e.action), ['execute', 'rebase', 'undo', 'redo'], consumer.name);
			assert.notDeepEqual(before, checkpoint.submittedProject, consumer.name + ' changed project');
			assert.throws(() => session.rebase({ saveToken: checkpoint.token, baselineProject: checkpoint.submittedProject }), (e) => e.code === 'SAVE_STALE' || e.code === 'SAVE_INVALID');
			unsubscribe();
			unsubscribeDiagnostics();
		} finally {
			session.dispose();
			session.dispose();
		}
		for (const name of ['project', 'snapshot', 'historyStatus', 'subscribeHistory', 'subscribeDiagnostics', 'captureSave', 'baselineSnapshot', 'rebase']) {
			assert.throws(() => session[name](), /disposed/, consumer.name + '.' + name + ' disposed');
		}
	}
});

test('G1c: same-value context switch invalidates a pending checkpoint without changing project values', () => {
	const session = publicEditor.createDomainSession({ project: domainProject('Same') });
	const before = session.project();
	const checkpoint = session.captureSave();
	const events = [];
	session.subscribeHistory((event) => events.push(event));
	session.replace(domainProject('Same'), { source: 'context-switch', contextChanged: true });
	assert.deepEqual(session.project(), before);
	assert.deepEqual(events.map((e) => [e.action, e.source]), [['replace', 'context-switch']]);
	assert.throws(
		() => session.rebase({ saveToken: checkpoint.token, baselineProject: checkpoint.submittedProject }),
		(error) => error.code === 'SAVE_CONTEXT_CHANGED',
	);
	assert.deepEqual(session.baselineSnapshot(), before);
	session.dispose();
});


test('B2: public ESM and frozen browser facade expose typed Domain Selection without internal classes', () => {
	assert.equal(publicEditor.createDomainSession, window.cbCore.designEditor.createDomainSession);
	assert.equal(publicEditor.createDesignerSelectionController,
		window.cbCore.designEditor.shell.selection.createController);
	assert.equal(Object.isFrozen(window.cbCore.designEditor), true);
	const session = publicEditor.createDomainSession({ project: domainProject('Selection') });
	assert.deepEqual(Object.keys(session).filter((name) =>
		['selection', 'select', 'subscribeSelection'].includes(name)).sort(),
		['select', 'selection', 'subscribeSelection']);
	assert.equal(session.selection().contextEpoch, 0);
	assert.equal(Object.isFrozen(session.selection()), true);
	assert.equal(session.select([], null, { expectedEpoch: 0 }), false);
	assert.throws(() => session.select([], null, { expectedEpoch: 9 }),
		(error) => error.code === 'SELECTION_INVALID');
	session.dispose();
});

test('CN-1 pan, fit and coordinates operate on transient viewport state', () => {
	const events = new EventTarget();
	const scene = {style: {transform: '', transformOrigin: ''}};
	const doc = {defaultView: events};
	const classes = new Set();
	const viewport = new EventTarget();
	Object.assign(viewport, {
		ownerDocument: doc, clientWidth: 700, clientHeight: 500,
		scrollLeft: 0, scrollTop: 0, isConnected: true,
		contains: value => value === scene,
		getBoundingClientRect: () => ({left: 100, top: 200, width: 700, height: 500}),
		setPointerCapture() {}, hasPointerCapture: () => false,
		classList: {add: key => classes.add(key), remove: key => classes.delete(key)},
	});
	const root = new EventTarget();
	Object.assign(root, {
		isConnected: true, contains: value => value === viewport,
		getBoundingClientRect: () => ({left: 0, top: 0, width: 700, height: 500}),
	});
	let context = 'editor/1';
	const controller = publicEditor.createDesignerCanvasNavigation({
		root, viewport, scene, getContentBounds: () => ({x: 0, y: 0, width: 1400, height: 900}),
		getContextKey: () => context, isReady: () => true,
	});
	const fitted = controller.getView();
	assert.ok(fitted.scale > 0 && fitted.scale < 1);
	const client = controller.canvasToClient({x: 132, y: 254});
	const point = controller.clientToCanvas(client);
	assert.ok(Math.abs(point.x - 132) < 1e-8);
	assert.ok(Math.abs(point.y - 254) < 1e-8);
	assert.equal(controller.panBy(40, -20), true);
	assert.equal(controller.getView().x, fitted.x + 40);
	assert.equal(controller.getView().y, fitted.y - 20);
	controller.centerOnContent();
	assert.notEqual(controller.getView().x, fitted.x + 40);
	assert.equal(controller.fitToScreen({padding: 16}), true);
	assert.ok(controller.getView().scale > fitted.scale);
	context = 'editor/2';
	assert.ok(controller.getView().scale > 0, 'context reset remains a valid fit');
	controller.destroy();
	assert.equal(scene.style.transform, '');
	assert.equal(classes.size, 0);
	assert.throws(() => controller.getView(), /DESTROYED/);
});

test('CM-1 stays optional, flat and command-authority-neutral', async () => {
	const source = await readFile(new URL('../../assets/js/design/shell/context-menu.js', import.meta.url), 'utf8');
	const caps = await readFile(new URL('../../src/Design/Editor/Assets.php', import.meta.url), 'utf8');
	assert.match(caps, /public const CANVAS_NAVIGATION_API = 1;/);
	assert.match(caps, /public const DESIGNER_CONTEXT_MENU_API = 1;/);
	assert.match(source, /item\.children !== undefined/);
	assert.match(source, /currentContext\(\) === entry\.context/);
	assert.match(source, /normalizedItems\(entry\.target\)\.find/);
	assert.match(source, /canInvoke\(Object\.freeze\(\{actionId, target: entry\.target\}\)\) === true/);
	assert.match(source, /menu\.setAttribute\('role', 'menu'\)/);
	assert.match(source, /button\.setAttribute\('role', 'menuitem'\)/);
	assert.match(source, /event\.key === 'Escape'/);
	assert.match(source, /close\(\{restoreFocus: false\}\)/);
	assert.match(source, /listeners\.splice\(0\)\.forEach\(off => off\(\)\)/);
});
