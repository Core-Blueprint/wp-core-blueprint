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

class FakeClassList {
	constructor() {
		this.values = new Set();
	}
	add(...values) {
		values.forEach((value) => this.values.add(value));
	}
	remove(...values) {
		values.forEach((value) => this.values.delete(value));
	}
	contains(value) {
		return this.values.has(value);
	}
}

class FakeElement extends EventTarget {
	constructor(ownerDocument = null) {
		super();
		this.ownerDocument = ownerDocument;
		this.dataset = {};
		this.classList = new FakeClassList();
		this.attributes = new Map();
		this.children = [];
		this.isConnected = true;
	}
	querySelector() {
		return null;
	}
	querySelectorAll() {
		return [];
	}
	contains(candidate) {
		return candidate === this;
	}
	hasAttribute(name) {
		return this.attributes.has(name);
	}
	setAttribute(name, value) {
		this.attributes.set(name, String(value));
	}
	removeAttribute(name) {
		this.attributes.delete(name);
	}
	focus() {
		if (this.ownerDocument) this.ownerDocument.activeElement = this;
	}
}

class FakeButton extends FakeElement {}

class FakeDocument extends EventTarget {
	constructor() {
		super();
		this.activeElement = null;
		this.documentElement = new FakeElement(this);
	}
}

class FakeCustomEvent extends Event {
	constructor(type, options = {}) {
		super(type, options);
		this.detail = options.detail;
	}
}

const fixedProject = () => ({
	schema_version: 0,
	design_type: 'fixture.shell-proof.fixed',
	root: {
		type: 'document',
		provider: 'fixture.shell-proof',
		properties: {
			layout: {
				mode: 'fixed',
				units: 'mm',
				page: { width: 210, height: 297 },
			},
		},
		children: [{
			type: 'text',
			provider: 'fixture.shell-proof',
			properties: { frame: { x: 10, y: 12, width: 80, height: 20 } },
			children: [],
		}],
	},
});

before(async () => {
	tempDirectory = await mkdtemp(join(tmpdir(), 'cb-design-shell-profile-proof-'));
	const copiedDesignDirectory = join(tempDirectory, 'design');
	await cp(designDirectory, copiedDesignDirectory, { recursive: true });
	await convertJsTreeToMjs(copiedDesignDirectory);

	globalThis.window = {};
	globalThis.document = new FakeDocument();
	globalThis.Element = FakeElement;
	globalThis.HTMLButtonElement = FakeButton;
	globalThis.CustomEvent = FakeCustomEvent;
	publicEditor = await import(pathToFileURL(join(copiedDesignDirectory, 'editor.mjs')).href);
});

after(async () => {
	delete globalThis.window;
	delete globalThis.document;
	delete globalThis.Element;
	delete globalThis.HTMLButtonElement;
	delete globalThis.CustomEvent;
	if (tempDirectory) await rm(tempDirectory, { recursive: true, force: true });
});

test('document-fixed consumer session remains unchanged across shared shell fullscreen UI state', () => {
	const session = publicEditor.createSession({
		project: fixedProject(),
		profile: 'document-fixed',
	});
	const projectBefore = session.snapshot();
	const historyBefore = session.history.size;
	const outside = new FakeButton(document);
	outside.focus();
	const root = new FakeElement(document);
	const shell = publicEditor.createDesignerShell(root, { session });
	const changes = [];
	root.addEventListener('cb:design-shell:fullscreenchange', (event) => changes.push(event.detail.fullscreen));

	assert.equal(shell.enterFullscreen(), true);
	assert.equal(shell.isFullscreen(), true);
	assert.deepEqual(session.snapshot(), projectBefore);
	assert.equal(session.history.size, historyBefore);

	assert.equal(shell.exitFullscreen(), true);
	assert.equal(shell.isFullscreen(), false);
	assert.deepEqual(session.snapshot(), projectBefore);
	assert.equal(session.history.size, historyBefore);
	assert.deepEqual(changes, [true, false]);
	assert.equal(document.activeElement, outside);

	session.dispose();
});


test('P02 public history facade drives Base-owned toolbar history without exposing private classes', async () => {
	const project = {
		schema_version: 1,
		design_type: 'fixture.shell-proof.domain',
		root: { type: 'domain', props: { label: 'Initial' }, children: [] },
	};
	const session = publicEditor.createDomainSession({ project });
	const root = new FakeElement(document);
	const undo = new FakeButton(document);
	const redo = new FakeButton(document);
	root.querySelector = (selector) => ({
		'[data-cb-design-shell-undo]': undo,
		'[data-cb-design-shell-redo]': redo,
	}[selector] ?? null);
	const facade = Object.freeze({
		historyStatus: () => session.historyStatus(),
		subscribeHistory: (listener) => session.subscribeHistory(listener),
		subscribeDiagnostics: (listener) => session.subscribeDiagnostics(listener),
		undo: () => session.undo(),
		redo: () => session.redo(),
	});
	const shell = publicEditor.createDesignerShell(root, { session: facade });
	shell.syncHistory();
	assert.equal(undo.disabled, true);
	assert.equal(redo.disabled, true);
	session.execute({
		label: 'edit-domain',
		apply(current) {
			const next = structuredClone(current);
			next.root.props.label = 'Updated';
			return { project: next };
		},
	});
	assert.equal(undo.disabled, false);
	assert.equal(redo.disabled, true);
	facade.undo();
	assert.equal(undo.disabled, true);
	assert.equal(redo.disabled, false);
	facade.redo();
	assert.equal(undo.disabled, false);
	assert.equal(redo.disabled, true);
	shell.destroy();
	session.dispose();
});
