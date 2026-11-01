import assert from 'node:assert/strict';
import { cp, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { before, after, test } from 'node:test';

let editorModule, authorityModule, projectModule, editorStateModule, historyModule;
let temp;
async function convert(dir) {
	for (const entry of await readdir(dir, { withFileTypes: true })) {
		const file = join(dir, entry.name);
		if (entry.isDirectory()) { await convert(file); continue; }
		if (!entry.isFile() || !file.endsWith('.js')) continue;
		const source = await readFile(file, 'utf8');
		let converted = source.replaceAll(/(['"])(\.\.?\/[^'"]+)\.js\1/g, '$1$2.mjs$1');
		if (entry.name === 'editor.js') {
			converted = converted.replace("'@cb-core/design-motion'", "'./core/motion.mjs'");
		}
		await writeFile(file.replace(/\.js$/, '.mjs'), converted);
	}
}
before(async () => {
	temp = await mkdtemp(join(tmpdir(), 'cb-df-cx02b-'));
	const dest = join(temp, 'design');
	await cp(new URL('../../assets/js/design/', import.meta.url), dest, { recursive: true });
	await convert(dest);
	globalThis.window = {};
	const module = (path) => import(pathToFileURL(join(dest, path)).href);
	[editorModule, authorityModule, projectModule, editorStateModule, historyModule] = await Promise.all([
		module('editor.mjs'), module('core/context-authority.mjs'),
		module('core/project-state.mjs'), module('core/editor-state.mjs'), module('core/history.mjs'),
	]);
});
after(async () => {
	delete globalThis.window;
	if (temp) await rm(temp, { recursive: true, force: true });
});

const project = (revision = 0) => ({
	schema_version: 0,
	design_type: 'fixture.cx02b',
	root: { type: 'fixture', props: { revision }, children: [] },
});
const change = () => Object.freeze({
	label: 'increment',
	apply(current) {
		const next = structuredClone(current);
		next.root.props.revision += 1;
		return { project: next };
	},
});
const denied = (call) => assert.throws(call, (error) => error?.code === 'DESIGNER_CONTEXT_NOT_WRITABLE');

const createRawFixture = () => {
	const raw = new Map();
	const factory = (_options, gates) => {
		const projectState = new projectModule.ProjectState(project(), { writeGuard: gates.write });
		const editorState = new editorStateModule.EditorState({ writeGuard: gates.write });
		const history = new historyModule.CommandHistory(projectState, editorState, { writeGuard: gates.write });
		const value = {
			projectState, editorState, history,
			project() { gates.read(); return projectState.current(); },
			execute(command) { return history.execute(command); },
			dispose() { history.dispose(); },
		};
		raw.set(_options.key, value);
		return Object.freeze(value);
	};
	const authority = authorityModule.createManagedContextAuthority({
		createProfileSession: factory,
		createDomainSession: factory,
	});
	const stage = (id) => authority.stage({ id, kind: 'domain', sessionOptions: { key: id } });
	return { authority, stage, raw };
};

test('CX02B: initial managed domain session writable; public handle hides mutable stores and teardown', () => {
	const binding = editorModule.createManagedContextBinding({
		initial: { id: 'invoice', kind: 'domain', sessionOptions: { project: project() } },
	});
	const session = binding.current.session;
	assert.equal(binding.current.status, 'ACTIVE');
	assert.equal(session.projectState, undefined);
	assert.equal(session.editorState, undefined);
	assert.equal(session.history, undefined);
	assert.equal(session.dispose, undefined);
	assert.equal(typeof binding.activateInitial, 'undefined');
	assert.equal(typeof binding.switchTo, 'undefined');
	session.execute(change());
	assert.equal(session.project().root.props.revision, 1);
	const staged = binding.stageContext({
		id: 'quote', kind: 'domain', sessionOptions: { project: project(10) },
	});
	assert.equal(staged.status, 'STAGED');
	denied(() => staged.session.execute(change()));
	denied(() => staged.session.select([], null, { expectedEpoch: 0 }));
	assert.equal(staged.session.project().root.props.revision, 10);
});

test('CX02B: every direct managed state bypass is fenced during staging', () => {
	const { authority, stage, raw } = createRawFixture();
	const staged = stage('invoice');
	const { projectState, editorState, history } = raw.get('invoice');
	denied(() => history.execute(change()));
	denied(() => history.undo());
	denied(() => history.clear());
	denied(() => history.captureSave());
	denied(() => projectState.replace(project(100)));
	denied(() => projectState.commitStaged(projectState.stage(project(100))));
	denied(() => projectState.notify({ source: 'malicious' }));
	denied(() => editorState.restore(editorState.snapshot()));
	denied(() => editorState.commitSnapshot(editorState.snapshot()));
	denied(() => editorState.selection.clear());
	denied(() => editorState.selection.notify({ source: 'malicious' }));
	assert.equal(staged.session.project().root.props.revision, 0);
	assert.equal(authority.isCurrent(staged), false);
});

test('CX02B: lock blocks writes, failed target permits reversible handoff', () => {
	const { authority, stage, raw } = createRawFixture();
	const source = stage('invoice');
	authority.activateInitial(source);
	source.session.execute(change());
	assert.equal(source.session.project().root.props.revision, 1);
	authority.lock(source);
	denied(() => source.session.execute(change()));
	const target = stage('quote');
	denied(() => target.session.execute(change()));
	authority.switchTo(source, target);
	assert.equal(target.status, 'ACTIVE');
	denied(() => source.session.execute(change()));
	target.session.execute(change());
	authority.rollback(source, target);
	assert.equal(source.status, 'ACTIVE');
	assert.equal(target.status, 'INVALID');
	assert.equal(source.session.project().root.props.revision, 1);
	denied(() => target.session.execute(change()));
	denied(() => raw.get('quote').projectState.replace(project(999)));
	assert.throws(() => target.session.project(), (error) => error?.code === 'DESIGNER_CONTEXT_INVALID');
	source.session.execute(change());
	assert.equal(source.session.project().root.props.revision, 2);
});

test('CX02B: retired session remains fenced, even with retained direct references', () => {
	const { authority, stage, raw } = createRawFixture();
	const source = stage('invoice');
	authority.activateInitial(source);
	const checkpoint = raw.get('invoice').history.captureSave();
	const target = stage('quote');
	authority.lock(source);
	authority.switchTo(source, target);
	authority.retire(source, target);
	assert.equal(source.status, 'RETIRED');
	assert.equal(target.status, 'ACTIVE');
	denied(() => source.session.execute(change()));
	denied(() => raw.get('invoice').projectState.replace(project(1000)));
	denied(() => raw.get('invoice').history.rebase({
		saveToken: checkpoint.token, baselineProject: project(),
	}));
	denied(() => raw.get('invoice').editorState.selection.set([], null));
	assert.throws(() => source.session.project(), (error) => error?.code === 'DESIGNER_CONTEXT_INVALID');
	assert.throws(() => raw.get('quote').history.rebase({
		saveToken: checkpoint.token, baselineProject: project(),
	}), (error) => error?.code === 'SAVE_INVALID');
	target.session.execute(change());
	assert.equal(target.session.project().root.props.revision, 1);
	assert.throws(() => authority.rollback(source, target), /active write authority/);
	const restaged = stage('invoice');
	assert.equal(restaged.status, 'STAGED', 'return to a document creates a fresh session');
	denied(() => restaged.session.execute(change()));
});

test('CX02B: ordinary unmanaged domain sessions retain existing mutation behaviour', () => {
	const session = editorModule.createDomainSession({ project: project() });
	session.execute(change());
	assert.equal(session.project().root.props.revision, 1);
	session.undo();
	assert.equal(session.project().root.props.revision, 0);
	session.redo();
	assert.equal(session.project().root.props.revision, 1);
	session.dispose();
});

const flowProject = () => ({
	schema_version: 0, design_type: 'fixture.cx02b.flow',
	root: {
		type: 'document', provider: 'fixture.cx02b', children: [], properties: {
			layout: {
				mode: 'flow', units: 'mm',
				page: { width: 210, height: 297 },
				margins: { top: 15, right: 15, bottom: 15, left: 15 },
			},
		},
	},
});

test('CX02B: profile sessions expose only read-only validation context during staging', () => {
	let exposed = null;
	const binding = editorModule.createManagedContextBinding({
		initial: {
			id: 'invoice', kind: 'profile', sessionOptions: {
				project: flowProject(), profile: 'document-flow',
				validate(_project, context) { exposed = context.editorState; return []; },
			},
		},
	});
	assert.equal(binding.current.status, 'ACTIVE');
	assert.equal(typeof exposed.snapshot, 'function');
	assert.equal(exposed.selection.set, undefined);
	assert.equal(exposed.validation.replace, undefined);
	assert.equal(binding.current.session.projectState, undefined);
	assert.deepEqual(binding.current.session.validate(), []);
	const quote = binding.stageContext({
		id: 'quote', kind: 'profile', sessionOptions: {
			project: flowProject(), profile: 'document-flow',
		},
	});
	denied(() => quote.session.setSelection([]));
	denied(() => quote.session.validate());
	assert.deepEqual(quote.session.baselineSnapshot(), flowProject());
});
