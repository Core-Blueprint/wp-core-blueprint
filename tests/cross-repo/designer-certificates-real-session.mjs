/**
 * Executable, non-WordPress, cross-repository contract proof.
 *
 * Execute ONLY against the previously verified, frozen Certificates RC1.2
 * local checkout:
 *   CB_CERTIFICATES_SOURCE="$HOME/Downloads/wp-core-blueprint-certificates" \
 *     node --test tests/cross-repo/designer-certificates-real-session.mjs
 *
 * Imports the REAL candidate Base editor module and REAL Certificates
 * design-editor and selection-bridge modules. No mocks of either public API.
 * Converts browser ESM sources inside a newly allocated temporary directory.
 * No application storage, WordPress DB, or product-source files are modified.
 */
import assert from 'node:assert/strict';
import { cp, mkdtemp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { isAbsolute, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { before, after, test } from 'node:test';

const baseDesign = new URL('../../assets/js/design/', import.meta.url);
const certificatesSource = process.env.CB_CERTIFICATES_SOURCE;
let workingDirectory;
let certificates;

const convertTree = async (directory) => {
	for (const entry of await readdir(directory, { withFileTypes: true })) {
		const location = join(directory, entry.name);
		if (entry.isDirectory()) {
			await convertTree(location);
			continue;
		}
		if (!entry.isFile() || !entry.name.endsWith('.js')) continue;
		const original = await readFile(location, 'utf8');
		let esm = original.replaceAll(/(['"])(\.\.?\/[^'"]+)\.js\1/g, '$1$2.mjs$1');
		if (entry.name === 'editor.js') {
			esm = esm.replace("'@cb-core/design-motion'", "'./core/motion.mjs'");
		}
		await writeFile(location.replace(/\.js$/, '.mjs'), esm);
	}
};

before(async () => {
	assert.ok(
		typeof certificatesSource === 'string' && isAbsolute(certificatesSource),
		'Set CB_CERTIFICATES_SOURCE to the verified absolute Certificates RC1.2 checkout path.',
	);
	const source = resolve(certificatesSource);
	workingDirectory = await mkdtemp(join(tmpdir(), 'cb-base-certs-real-session-'));
	const design = join(workingDirectory, 'design');
	await cp(baseDesign, design, { recursive: true });
	await convertTree(design);

	const cert = join(workingDirectory, 'certificates');
	await mkdir(join(cert, 'designer'), { recursive: true });
	const originalEditor = await readFile(join(source, 'assets/js/design-editor.js'), 'utf8');
	const originalSelection = await readFile(join(source, 'assets/js/designer/selection.js'), 'utf8');

	assert.ok(
		originalEditor.includes("from '@cb-core/design-editor'"),
		'Certificates must use only the public Base design-editor module.',
	);
	assert.ok(
		originalEditor.includes("from './designer/selection.js'"),
		'Certificates must use its actual selection bridge.',
	);

	await writeFile(
		join(cert, 'design-editor.mjs'),
		originalEditor.replace("from '@cb-core/design-editor'", "from '../design/editor.mjs'")
			.replace("from './designer/selection.js'", "from './designer/selection.mjs'"),
	);
	await writeFile(join(cert, 'designer/selection.mjs'), originalSelection);
	certificates = await import(pathToFileURL(join(cert, 'design-editor.mjs')).href);
});

after(async () => {
	if (workingDirectory) await rm(workingDirectory, { recursive: true, force: true });
});

const design = () => ({
	schema_version: '1.0',
	document: { format: 'A4', units: 'mm', orientation: 'landscape' },
	background: {},
	elements: [
		{ id: 'alpha', type: 'text', x: 5, y: 5, width: 40, height: 10 },
		{ id: 'beta', type: 'text', x: 50, y: 5, width: 40, height: 10 },
	],
});

test('real Certificates fixed-document adapter creates a valid real Base session', () => {
	const session = certificates.createCertificateSession(design());
	assert.equal(session.profile.id, 'document-fixed');
	assert.equal(session.project().root.children.length, 2);
	assert.deepEqual(session.historyStatus(), { canUndo: false, canRedo: false, size: 0 });
	assert.equal(Object.isFrozen(session.historyStatus()), true);
	session.dispose();
});

test('real Certificates history adapter commits and restores via Base public session', () => {
	const restores = [];
	const history = certificates.createAuthoringHistory(
		design(),
		{ label: 'before' },
		{ onRestore: (snapshot, meta) => restores.push({ snapshot, action: meta.action }) },
	);
	assert.equal(history.canUndo, false);
	assert.equal(history.commit({ label: 'after' }), true);
	assert.equal(history.canUndo, true);
	assert.equal(history.size, 1);
	assert.equal(history.undo(), true);
	assert.equal(history.canRedo, true);
	assert.deepEqual(restores.at(-1), { snapshot: { label: 'before' }, action: 'undo' });
	assert.equal(history.redo(), true);
	assert.deepEqual(restores.at(-1), { snapshot: { label: 'after' }, action: 'redo' });
	history.dispose();
	assert.throws(() => history.session.historyStatus(), /disposed/);
});

test('real Certificates ID bridge survives reordering and strict Base primary membership', () => {
	let elements = [{ id: 'alpha' }, { id: 'beta' }, { id: 'gamma' }];
	const history = certificates.createAuthoringHistory(design(), { label: 'selection' });
	const session = history.session;
	const events = [];
	const unsubscribe = session.subscribeSelection((snapshot, context) => {
		events.push({ snapshot, source: context.event.source });
	});

	const bridge = certificates.createAuthoringSelection(history, () => elements);
	assert.deepEqual(bridge.set(['alpha', 'gamma'], 'gamma'), ['alpha', 'gamma']);
	assert.deepEqual(session.selection(), { paths: [[0], [2]], primary: [2] });
	assert.equal(events.length, 1);
	assert.equal(events[0].source, 'certificates');
	const before = session.selection();
	assert.throws(
		() => session.setSelection([[0]], { primary: [2], external: true }),
		/primary must be one of the selected paths/,
	);
	assert.deepEqual(session.selection(), before);
	assert.equal(events.length, 1, 'rejected primary must not publish an event');

	elements = [{ id: 'gamma' }, { id: 'beta' }, { id: 'alpha' }];
	assert.deepEqual(bridge.snapshot(), { ids: ['alpha', 'gamma'], primaryId: 'gamma' });
	assert.deepEqual(session.selection(), { paths: [[2], [0]], primary: [0] });

	elements = [{ id: 'alpha' }, { id: 'beta' }];
	assert.deepEqual(bridge.snapshot(), { ids: ['alpha'], primaryId: 'alpha' });
	assert.deepEqual(session.selection(), { paths: [[0]], primary: [0] });
	assert.equal(bridge.clear(), undefined);
	assert.deepEqual(session.selection(), { paths: [], primary: null });
	unsubscribe();
	history.dispose();
});
