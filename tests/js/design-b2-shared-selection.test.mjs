import assert from 'node:assert/strict';
import { cp, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { before, after, test } from 'node:test';

let editor;
let temporary;
const convert = async (dir) => {
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
};
before(async () => {
  temporary = await mkdtemp(join(tmpdir(), 'cb-b2-selection-'));
  const dest = join(temporary, 'design');
  await cp(new URL('../../assets/js/design/', import.meta.url), dest, { recursive: true });
  await convert(dest);
  globalThis.window = {};
  editor = await import(pathToFileURL(join(dest, 'editor.mjs')).href);
});
after(async () => {
  delete globalThis.window;
  if (temporary) await rm(temporary, { recursive: true, force: true });
});

const project = (ids = ['one'], version = 0) => ({
  schema_version: 0,
  design_type: 'fixture.selection',
  root: {
    id: 'evaluation', type: 'evaluation-definition',
    props: { version, records: ids.map((id) => ({ id, kind: 'question' })) },
    children: [{ type: 'content', children: [] }],
  },
});
const ref = (id) => Object.freeze({ kind: 'entity', type: 'evaluator.question', id });
const node = Object.freeze({ kind: 'node', path: Object.freeze([0]) });
const resolver = (candidate, current) => candidate.type === 'evaluator.question'
  && current.root.props.records.filter((item) => item.id === candidate.id && item.kind === 'question').length === 1;
const create = (ids = ['one']) => editor.createDomainSession({
  project: project(ids), resolveSelectionEntity: resolver,
});
const edit = (ids, version = 1) => Object.freeze({
  label: 'edit-records',
  apply(current) {
    const next = structuredClone(current);
    next.root.props.records = ids.map((id) => ({ id, kind: 'question' }));
    next.root.props.version = version;
    return { project: next };
  },
});

test('B2-S01/R3: one immutable typed Domain facade, strict shape and membership', () => {
  const session = create();
  assert.equal(typeof session.selection, 'function');
  assert.equal(typeof session.select, 'function');
  assert.equal(typeof session.subscribeSelection, 'function');
  const initial = session.selection();
  assert.deepEqual(initial, { refs: [], primary: null, revision: 0, contextEpoch: 0 });
  assert.ok(Object.isFrozen(initial));
  assert.equal(session.select([ref('one')], ref('one'), { expectedEpoch: 0 }), true);
  const selected = session.selection();
  assert.ok(Object.isFrozen(selected.refs));
  assert.ok(Object.isFrozen(selected.refs[0]));
  assert.ok(Object.isFrozen(selected.primary));
  assert.deepEqual(selected.primary, ref('one'));
  assert.equal(session.select([ref('one')], ref('one'), { expectedEpoch: 0 }), false);
  assert.deepEqual(session.historyStatus(), { canUndo: false, canRedo: false, size: 0 });
  assert.equal(session.select([node], node, { expectedEpoch: 0 }), true);
  assert.deepEqual(session.selection().primary, node);
  const saved = session.snapshot();
  assert.equal(JSON.stringify(saved).includes('contextEpoch'), false);
  session.dispose();
});

test('B2-S02/R3: invalid, duplicate and ambiguous refs are rejected before mutation', () => {
  const session = create();
  const before = session.selection();
  const refuse = (refs, primary = null) => assert.throws(
    () => session.select(refs, primary, { expectedEpoch: 0 }),
    (error) => error.code === 'SELECTION_INVALID',
  );
  refuse([ref('one'), ref('one')], ref('one'));
  refuse([ref('one'), ref('missing')], ref('one'));
  refuse([ref('one')], ref('missing'));
  refuse([{ kind: 'entity', type: 'evaluator.question', id: 'one', html: '<b>' }]);
  refuse([{ kind: 'entity', type: 'evaluator.question', id: '__proto__' }]);
  refuse([{ kind: 'node', path: [3] }]);
  refuse([{ kind: 'missing', id: 'one' }]);
  assert.deepEqual(session.selection(), before);
  const duplicates = create(['one', 'one']);
  assert.throws(() => duplicates.select([ref('one')], ref('one'), { expectedEpoch: 0 }), /Selection reference/);
  duplicates.dispose();
  session.dispose();
});

test('B2-R3: absent, throwing, async and non-boolean resolvers refuse membership', () => {
  const answers = [null, false, 1, Promise.resolve(true)];
  for (const answer of answers) {
    const session = editor.createDomainSession({ project: project(), resolveSelectionEntity: () => answer });
    assert.throws(() => session.select([ref('one')], ref('one'), { expectedEpoch: 0 }), /Selection reference/);
    assert.equal(session.selection().refs.length, 0);
    session.dispose();
  }
  const absent = editor.createDomainSession({ project: project() });
  assert.throws(() => absent.select([ref('one')], ref('one'), { expectedEpoch: 0 }), /Selection reference/);
  absent.dispose();
  const failing = editor.createDomainSession({
    project: project(), resolveSelectionEntity: () => { throw Error('internal secret'); },
  });
  assert.throws(() => failing.select([ref('one')], ref('one'), { expectedEpoch: 0 }),
    (error) => error.code === 'SELECTION_INVALID' && !String(error).includes('internal secret'));
  failing.dispose();
});

test('B2-R1: effective replace advances History epoch, no-op leaves it unchanged', () => {
  const session = create();
  assert.equal(session.select([ref('one')], ref('one'), { expectedEpoch: 0 }), true);
  const events = [];
  session.subscribeSelection((snapshot, context) => events.push([snapshot, context]));
  session.replace(project(), { source: 'identical' });
  assert.equal(session.selection().contextEpoch, 0);
  session.replace(project(['one'], 1), { source: 'same-context' });
  assert.equal(session.selection().contextEpoch, 1);
  assert.deepEqual(session.selection().primary, ref('one'));
  assert.equal(events.length, 1);
  assert.equal(events[0][0].contextEpoch, 1);
  assert.equal(events[0][1].event.source, 'replace');
  assert.throws(() => session.select([], null, { expectedEpoch: 0 }), /Selection reference/);
  session.select([], null, { expectedEpoch: 1 });
  session.replace(project(['one'], 1), { contextChanged: true });
  assert.equal(session.selection().contextEpoch, 2);
  assert.equal(session.selection().refs.length, 0);
  session.dispose();
});

test('B2-R1: failed replace leaves project, selection, epoch and history untouched', () => {
  const session = editor.createDomainSession({
    project: project(),
    resolveSelectionEntity: resolver,
    validateProject(value) { if (value.root.props.version === 99) throw Error('rejected'); },
  });
  session.select([ref('one')], ref('one'), { expectedEpoch: 0 });
  const before = session.selection(), source = session.snapshot(), history = session.historyStatus();
  assert.throws(() => session.replace(project(['two'], 99), { contextChanged: true }), /rejected/);
  assert.deepEqual(session.selection(), before);
  assert.deepEqual(session.snapshot(), source);
  assert.deepEqual(session.historyStatus(), history);
  session.dispose();
});

test('B2-R1: command delete, Undo, Redo restore membership without rewinding epoch', () => {
  const session = create();
  session.select([ref('one')], ref('one'), { expectedEpoch: 0 });
  session.execute(edit([], 1));
  assert.equal(session.selection().primary, null);
  assert.equal(session.undo(), true);
  assert.deepEqual(session.selection().primary, ref('one'));
  assert.equal(session.redo(), true);
  assert.equal(session.selection().primary, null);
  assert.equal(session.selection().contextEpoch, 0);
  session.replace(project(['one'], 3), { contextChanged: true });
  assert.equal(session.selection().contextEpoch, 1);
  assert.equal(session.undo(), false);
  session.dispose();
});

test('B2-S03/S07: subscribe, clear, Save and disposal remain transient', () => {
  const session = create();
  const observations = [];
  const off = session.subscribeSelection((selection, context) => {
    observations.push([selection, context]);
  }, { emitCurrent: true });
  assert.equal(observations.length, 1);
  assert.equal(observations[0][1].event.action, 'sync');
  session.select([ref('one')], ref('one'), { expectedEpoch: 0 });
  assert.equal(observations.length, 2);
  const checkpoint = session.captureSave();
  assert.equal(JSON.stringify(checkpoint.submittedProject).includes('selection'), false);
  session.rebase({ saveToken: checkpoint.token, baselineProject: checkpoint.submittedProject });
  assert.deepEqual(session.selection().primary, ref('one'));
  assert.equal(session.select([], null, { expectedEpoch: 0 }), true);
  assert.equal(session.select([], null, { expectedEpoch: 0 }), false);
  off(); off();
  session.select([ref('one')], ref('one'), { expectedEpoch: 0 });
  assert.equal(observations.length, 3);
  session.dispose(); session.dispose();
  assert.throws(() => session.selection(), /disposed/);
  assert.throws(() => session.select([], null, { expectedEpoch: 0 }), /disposed/);
  assert.throws(() => session.subscribeSelection(() => {}), /disposed/);
});

test('B2-R1: subscriptions observe project, selection and History atomically', () => {
  const session = create();
  const order = [];
  session.subscribe((_project, event) => {
    order.push(['project', event.source, session.selection().contextEpoch]);
    assert.equal(session.historyStatus().canUndo, true);
  });
  session.subscribeSelection((selection) => {
    order.push(['selection', selection.primary?.id || '', selection.contextEpoch]);
    assert.equal(session.historyStatus().canUndo, true);
  });
  session.subscribeHistory(() => order.push(['history']));
  session.select([ref('one')], ref('one'), { expectedEpoch: 0 });
  order.length = 0;
  session.execute(edit([], 2));
  assert.deepEqual(order.map((entry) => entry[0]), ['project', 'selection', 'history']);
  assert.equal(order[1][1], '');
  session.dispose();
});

test('B2-R2: typed Domain controller coalesces project+selection and rejects stale DOM epoch', () => {
  const session = create();
  const views = [];
  const controller = editor.createDesignerSelectionController({
    typed: 'domain',
    session,
    renderLayers: (ctx) => views.push(['layers', ctx]),
    renderInspector: (ctx) => views.push(['inspector', ctx]),
    syncCanvas: (ctx) => views.push(['canvas', ctx]),
    openInspectorOnInsert: false,
  });
  assert.deepEqual(views.map((v) => v[0]), ['layers', 'inspector', 'canvas']);
  const epoch = views[0][1].selection.contextEpoch;
  assert.equal(controller.select(ref('one'), { expectedEpoch: epoch }), true);
  views.length = 0;
  session.execute(edit([], 1));
  assert.deepEqual(views.map((v) => v[0]), ['layers', 'inspector', 'canvas']);
  assert.equal(views[0][1].selection.primary, null);
  views.length = 0;
  session.replace(project(['two'], 2), { contextChanged: true });
  assert.deepEqual(views.map((v) => v[0]), ['layers', 'inspector', 'canvas']);
  assert.throws(() => controller.select(ref('two'), { expectedEpoch: epoch }), /Selection reference/);
  assert.throws(() => controller.clear({ expectedEpoch: epoch }), /Selection reference/);
  assert.equal(session.selection().primary, null);
  assert.equal(controller.select(ref('two'), { expectedEpoch: session.selection().contextEpoch }), true);
  controller.destroy(); controller.destroy();
  views.length = 0;
  session.select([], null, { expectedEpoch: session.selection().contextEpoch });
  assert.equal(views.length, 0);
  assert.throws(() => controller.refresh(), /destroyed/);
  session.dispose();
});

test('B2-R2: native Host controller retains its exact trusted ABI', () => {
  let callback;
  const calls = [];
  const native = {
    profile: { id: 'mail' },
    project: () => project(),
    selection: () => ({ refs: [], primary: null, revision: 0, contextEpoch: 77 }),
    subscribe(fn) { callback = fn; return () => { callback = null; }; },
    select(refs, primary) { calls.push([refs, primary]); return true; },
  };
  const controller = editor.createDesignerSelectionController({
    typed: true, session: native, renderLayers: () => {},
  });
  assert.equal(typeof callback, 'function');
  controller.select(ref('one'));
  assert.deepEqual(calls[0][0], [ref('one')]);
  assert.deepEqual(calls[0][1], ref('one'));
  controller.clear();
  assert.deepEqual(calls[1], [[], null]);
  controller.destroy();
  assert.equal(callback, null);
});

test('B2-S08: Domain Selection never authorizes native generation 1', () => {
  const invalid = project();
  invalid.schema_version = 1;
  assert.throws(() => editor.createDomainSession({ project: invalid, resolveSelectionEntity: resolver }),
    /verified Host registry/);
});

test('B2-R2: project-only command refreshes all views once without fabricating a selection event', () => {
  const session = create();
  session.select([ref('one')], ref('one'), { expectedEpoch: 0 });
  const observations = [];
  const semantics = [];
  session.subscribeSelection((_selection, meta) => semantics.push(meta.event.action));
  const controller = editor.createDesignerSelectionController({
    typed: 'domain', session,
    renderLayers: () => observations.push('layers'),
    renderInspector: () => observations.push('inspector'),
    syncCanvas: () => observations.push('canvas'),
  });
  observations.length = 0;
  session.execute(edit(['one'], 2));
  assert.deepEqual(observations, ['layers', 'inspector', 'canvas']);
  assert.equal(semantics.length, 0, 'Project-only commits are not Selection edits');
  assert.deepEqual(session.selection().primary, ref('one'));
  controller.destroy();
  session.dispose();
});

test('B2-R1: Save token and observer reentrancy cannot cross canonical context', () => {
  const session = create();
  const oldCheckpoint = session.captureSave();
  session.select([ref('one')], ref('one'), { expectedEpoch: 0 });
  const refusals = [];
  const off = session.subscribe(() => {
    try {
      session.select([ref('one')], ref('one'), { expectedEpoch: session.selection().contextEpoch });
    } catch (error) { refusals.push(error.code); }
  });
  session.replace(project(['one'], 7), { contextChanged: true });
  assert.deepEqual(refusals, ['DESIGNER_REENTRANT']);
  assert.equal(session.selection().contextEpoch, 1);
  assert.equal(session.selection().primary, null);
  assert.throws(() => session.rebase({
    saveToken: oldCheckpoint.token, baselineProject: oldCheckpoint.submittedProject,
  }), (error) => error.code === 'SAVE_CONTEXT_CHANGED');
  assert.equal(session.selection().contextEpoch, 1);
  assert.equal(session.selection().primary, null);
  off();
  session.dispose();
});
