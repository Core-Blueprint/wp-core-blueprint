import assert from 'node:assert/strict';
import {cp, mkdtemp, readFile, readdir, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
import {before, after, test} from 'node:test';

let shellModule, selectionModule, coordinatorModule, editorModule;
let temp;
async function convert(dir) {
	for (const entry of await readdir(dir, {withFileTypes: true})) {
		const file = join(dir, entry.name);
		if (entry.isDirectory()) { await convert(file); continue; }
		if (!entry.isFile() || !file.endsWith('.js')) continue;
		let source = (await readFile(file, 'utf8'))
			.replaceAll(/(['"])(\.\.?\/[^'"]+)\.js\1/g, '$1$2.mjs$1');
		if (entry.name === 'editor.js') {
			source = source.replace("'@cb-core/design-motion'", "'./core/motion.mjs'");
		}
		await writeFile(file.replace(/\.js$/, '.mjs'), source);
	}
}
class FakeElement {
	constructor() {
		this.dataset = {};
		this.isConnected = true;
		this.classList = {
			add() {}, remove() {}, toggle() {}, contains() {return false;},
		};
		this.handlers = new Map();
		this.ownerDocument = globalThis.document;
		this.attributes = new Map();
	}
	matches(selector) {return selector === '[data-cb-design-shell]';}
	querySelector(selector) {
		if (selector === '[data-cb-design-shell-undo]') return this.undoButton;
		if (selector === '[data-cb-design-shell-redo]') return this.redoButton;
		return null;
	}
	querySelectorAll() {return [];}
	addEventListener(type, listener) {
		if (!this.handlers.has(type)) this.handlers.set(type, []);
		this.handlers.get(type).push(listener);
	}
	removeEventListener(type, listener) {
		this.handlers.set(type, (this.handlers.get(type) || []).filter((item) => item !== listener));
	}
	dispatchEvent() {return true;}
	setAttribute(name, value) {this.attributes.set(name, value);}
	getAttribute(name) {return this.attributes.get(name) ?? null;}
	hasAttribute(name) {return this.attributes.has(name);}
	removeAttribute(name) {this.attributes.delete(name);}
	focus() {globalThis.document.activeElement = this;}
	fire(type, event = {}) {
		for (const handler of this.handlers.get(type) || []) handler(event);
	}
	contains(node) {return node === this;}
}
class FakeButton extends FakeElement {
	constructor() {super(); this.disabled = false;}
}
class FakeEvent {
	constructor(type, options = {}) {this.type = type; this.detail = options.detail;}
}
before(async () => {
	temp = await mkdtemp(join(tmpdir(), 'cb-df-cx02c-'));
	const dest = join(temp, 'design');
	await cp(new URL('../../assets/js/design/', import.meta.url), dest, {recursive: true});
	await convert(dest);
	globalThis.document = {
		activeElement: null,
		documentElement: {classList: {add() {}, remove() {}}},
		querySelector() {return null;},
		addEventListener() {}, removeEventListener() {},
	};
	globalThis.Element = FakeElement;
	globalThis.HTMLButtonElement = FakeButton;
	globalThis.CustomEvent = FakeEvent;
	globalThis.window = {
		cbCore: {designerExitGuard: {canTransition: () => true}},
		matchMedia: () => ({matches: false}),
	};
	const module = (path) => import(pathToFileURL(join(dest, path)).href);
	[shellModule, selectionModule, coordinatorModule, editorModule] = await Promise.all([
		module('shell/index.mjs'), module('shell/selection-controller.mjs'),
		module('shell/context-rebinding.mjs'), module('editor.mjs'),
	]);
});
after(async () => {
	delete globalThis.document;
	delete globalThis.Element;
	delete globalThis.HTMLButtonElement;
	delete globalThis.CustomEvent;
	delete globalThis.window;
	if (temp) await rm(temp, {recursive: true, force: true});
});

const fixture = (name, {canUndo = false, canRedo = false} = {}) => {
	const historyListeners = new Set();
	const diagnosticsListeners = new Set();
	const selectionListeners = new Set();
	const oldHistory = [];
	const oldSelection = [];
	const counters = {undo: 0, redo: 0};
	let status = {canUndo, canRedo};
	let snapshot = {primary: null, paths: []};
	const subscribe = (store, seen, callback) => {
		store.add(callback);
		seen?.push(callback);
		return () => {store.delete(callback);};
	};
	return {
		name, counters, oldHistory, oldSelection,
		historyStatus: () => status,
		subscribeHistory: (fn) => subscribe(historyListeners, oldHistory, fn),
		subscribeDiagnostics: (fn) => subscribe(diagnosticsListeners, null, fn),
		subscribeSelection: (fn, {emitCurrent = false} = {}) => {
			const off = subscribe(selectionListeners, oldSelection, fn);
			if (emitCurrent) fn(snapshot, {event: {source:'fixture', action:'sync'}});
			return off;
		},
		setHistory(canUndo, canRedo) {
			status = {canUndo, canRedo};
			for (const fn of [...historyListeners]) fn({historyRevision: 1});
		},
		emitSelection(next) {
			snapshot = next;
			for (const fn of [...selectionListeners]) fn(snapshot, {event: {source:'ui', action:'set'}});
		},
		project: () => ({name}),
		selection: () => snapshot,
		inspector: () => ({name}),
		select() {return true;},
		clearSelection() {return true;},
		undo() {counters.undo++; return true;},
		redo() {counters.redo++; return true;},
	};
};
const fixtureShell = (session) => {
	const root = new FakeElement();
	root.undoButton = new FakeButton();
	root.redoButton = new FakeButton();
	return {root, shell: shellModule.createDesignerShell(root, {session})};
};
const fixtureSelection = (session, shell, records, renderInspector = null) =>
	selectionModule.createDesignerSelectionController({
		session, shell,
		renderLayers: ({project}) => records.push('layers:' + project.name),
		renderInspector: renderInspector ?? (({project}) => records.push('inspector:' + project.name)),
		syncCanvas: ({project}) => records.push('canvas:' + project.name),
	});
const prepare = (shell, selection, source, target) =>
	coordinatorModule.prepareDesignerViewRebinding({
		shell, selection, sourceSession: source, targetSession: target,
	});

test('CX02C: same Shell and Selection controller rebind to target History, Undo/Redo and Inspector', () => {
	const source = fixture('source');
	const target = fixture('target', {canUndo: true});
	const {root, shell} = fixtureShell(source);
	const renders = [];
	const selection = fixtureSelection(source, shell, renders);
	const receipt = prepare(shell, selection, source, target);
	assert.equal(root.undoButton.disabled, true);
	assert.equal(renders.at(-1), 'canvas:source', 'prepare must not publish target');
	assert.equal(receipt.commit(), true);
	assert.equal(root.undoButton.disabled, false);
	assert.equal(renders.at(-1), 'canvas:target');
	root.undoButton.fire('click');
	assert.equal(source.counters.undo, 0);
	assert.equal(target.counters.undo, 1);
	target.emitSelection({primary: null, paths: [['target']]});
	assert.equal(renders.at(-1), 'canvas:target');
	receipt.finalize();
	source.setHistory(true, true);
	source.emitSelection({primary: null, paths: [['stale']]});
	source.oldHistory[0]();
	source.oldSelection[0]({primary: null}, {event: {source: 'stale'}});
	assert.equal(root.undoButton.disabled, false);
	assert.equal(root.redoButton.disabled, true);
	assert.equal(renders.at(-1), 'canvas:target');
	assert.equal(shellModule.createDesignerShell(root, {session: target}), shell);
	assert.throws(() => shellModule.createDesignerShell(root, {session: source}), /different session/);
	selection.destroy();
	assert.equal(shell.destroy(), undefined);
});

test('CX02C: rollback restores original subscriptions, selection and Undo/Redo without replacing Shell', () => {
	const source = fixture('source', {canUndo: true});
	const target = fixture('target');
	const {root, shell} = fixtureShell(source);
	const renders = [];
	const selection = fixtureSelection(source, shell, renders);
	const receipt = prepare(shell, selection, source, target);
	receipt.commit();
	assert.equal(root.undoButton.disabled, true);
	assert.equal(receipt.rollback(), true);
	assert.equal(root.undoButton.disabled, false);
	assert.equal(renders.at(-1), 'canvas:source');
	root.undoButton.fire('click');
	assert.equal(source.counters.undo, 1);
	assert.equal(target.counters.undo, 0);
	target.setHistory(true, false);
	target.emitSelection({primary: null, paths: [['stale']]});
	assert.equal(renders.at(-1), 'canvas:source');
	assert.equal(root.undoButton.disabled, false);
	assert.equal(shellModule.createDesignerShell(root, {session: source}), shell);
	selection.destroy();
	shell.destroy();
});

test('CX02C: target subscription failure preserves source and its presentation', () => {
	const source = fixture('source', {canRedo: true});
	const target = fixture('target');
	target.subscribeSelection = () => {throw new Error('subscribe failed');};
	const {root, shell} = fixtureShell(source);
	const renders = [];
	const selection = fixtureSelection(source, shell, renders);
	assert.throws(() => prepare(shell, selection, source, target), /subscribe failed/);
	assert.equal(root.redoButton.disabled, false);
	assert.equal(renders.at(-1), 'canvas:source');
	source.setHistory(true, true);
	assert.equal(root.undoButton.disabled, false);
	selection.destroy();
	shell.destroy();
});

test('CX02C: rendering failure rolls back partial target view and restores source', () => {
	const source = fixture('source', {canUndo: true});
	const target = fixture('target');
	const {root, shell} = fixtureShell(source);
	const renders = [];
	const selection = fixtureSelection(source, shell, renders, ({project}) => {
		if (project.name === 'target') throw new Error('inspector projection failed');
		renders.push('inspector:' + project.name);
	});
	const receipt = prepare(shell, selection, source, target);
	assert.throws(() => receipt.commit(), /inspector projection failed/);
	assert.equal(renders.at(-1), 'canvas:source');
	assert.equal(root.undoButton.disabled, false);
	assert.equal(shellModule.createDesignerShell(root, {session: source}), shell);
	selection.destroy();
	shell.destroy();
});

test('CX02C: real canonical DomainSession selection follows active context and old History stays isolated', () => {
	const project = (name) => ({schema_version: 0, design_type: 'fixture.cx02c', root: {
		type: 'fixture', props: {name}, children: [],
	}});
	const source = editorModule.createDomainSession({project: project('source')});
	const target = editorModule.createDomainSession({project: project('target')});
	const {shell} = fixtureShell(source);
	const views = [];
	const controller = selectionModule.createDesignerSelectionController({
		session: source, typed: 'domain', shell,
		renderLayers: ({project}) => views.push(project.root.props.name),
	});
	const tx = prepare(shell, controller, source, target);
	tx.commit();
	tx.finalize();
	const change = {
		label: 'mutation',
		apply(current) {
			const next = structuredClone(current);
			next.root.props.name += '-edited';
			return {project: next};
		},
	};
	source.execute(change);
	assert.equal(views.at(-1), 'target');
	target.execute(change);
	assert.equal(views.at(-1), 'target-edited');
	assert.equal(target.historyStatus().canUndo, true);
	controller.destroy();
	shell.destroy();
	source.dispose();
	target.dispose();
});
