import assert from 'node:assert/strict';
import { cp, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { before, after, test } from 'node:test';

// Load the real Base modules, not a fabricated History or controller.
let editor, temp;
async function convert(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const file = join(dir, entry.name);
    if (entry.isDirectory()) { await convert(file); continue; }
    if (!entry.isFile() || !file.endsWith('.js')) continue;
    const source = await readFile(file, 'utf8');
    let moduleSource = source.replaceAll(/(['"])(\.\.?\/[^'"]+)\.js\1/g, '$1$2.mjs$1');
    if (entry.name === 'editor.js') {
      moduleSource = moduleSource.replace("'@cb-core/design-motion'", "'./core/motion.mjs'");
    }
    await writeFile(file.replace(/\.js$/, '.mjs'), moduleSource);
  }
}
before(async () => {
  temp = await mkdtemp(join(tmpdir(), 'cb-b2-f02-'));
  const dest = join(temp, 'design');
  await cp(new URL('../../assets/js/design/', import.meta.url), dest, { recursive: true });
  await convert(dest);
  globalThis.window = {};
  editor = await import(pathToFileURL(join(dest, 'editor.mjs')).href);
});
after(async () => {
  delete globalThis.window;
  if (temp) await rm(temp, { recursive: true, force: true });
});

const project = (ids = ['one'], version = 0) => ({
  schema_version: 0, design_type: 'fixture.selection',
  root: {
    id: 'evaluation', type: 'evaluation-definition',
    props: { version, records: ids.map((id) => ({ id, kind: 'question' })) },
    children: [{ type: 'content', children: [] }],
  },
});
const ref = (id) => Object.freeze({ kind: 'entity', type: 'evaluator.question', id });
const resolver = (candidate, current) => candidate.type === 'evaluator.question'
  && current.root.props.records.filter((item) => item.kind === 'question' && item.id === candidate.id).length === 1;
const create = () => editor.createDomainSession({ project: project(), resolveSelectionEntity: resolver });
const change = (ids, version) => Object.freeze({
  label: 'change-items',
  apply(current) {
    const next = structuredClone(current);
    next.root.props.version = version;
    next.root.props.records = ids.map((id) => ({ id, kind: 'question' }));
    return { project: next };
  },
});
const install = (session, adapters = {}) => {
  const trace = [];
  const render = (name) => (ctx) => {
    trace.push([name, ctx.event, ctx.selection, ctx.project]);
    adapters[name]?.(ctx);
  };
  const controller = editor.createDesignerSelectionController({
    typed: 'domain', session, openInspectorOnInsert: false,
    renderLayers: render('layers'), renderInspector: render('inspector'),
    syncCanvas: render('canvas'),
  });
  trace.length = 0; // Initial presentation is not a History commit.
  return { trace, controller };
};
const expectViews = (trace) => assert.deepEqual(trace.map((line) => line[0]), ['layers', 'inspector', 'canvas']);

test('F02-T01: empty equal-project context reset waits for matching final History', () => {
  const session = create(), sequence = [], meta = [];
  session.subscribeSelection((_snapshot, {event}) => {
    sequence.push('S');
    meta.push(event);
  });
  session.subscribeHistory((event) => {
    sequence.push('H');
    assert.equal(meta.at(-1)?.historyRevision, event.historyRevision);
  });
  const {controller} = install(session, {
    layers: () => sequence.push('V'),
    inspector: () => assert.equal(sequence.at(-1), 'V'),
  });
  session.replace(project(), { contextChanged: true, source: 'context' });
  assert.deepEqual(sequence, ['S', 'H', 'V']);
  assert.equal(session.selection().contextEpoch, 1);
  assert.equal(session.selection().refs.length, 0);
  assert.equal(Number.isSafeInteger(meta[0].historyRevision), true);
  assert.equal(Object.isFrozen(meta[0]), true);
  controller.destroy(); session.dispose();
});

test('F02-T02: populated equal-project context reset clears Selection only at final view', () => {
  const session = create();
  session.select([ref('one')], ref('one'), {expectedEpoch: 0});
  const phases = [];
  session.subscribeSelection((_snap, {event}) => phases.push(['S', event.historyRevision]));
  session.subscribeHistory((event) => phases.push(['H', event.historyRevision]));
  const {controller, trace} = install(session, {layers: (ctx) => {
    phases.push(['V', ctx.selection.contextEpoch]);
    assert.equal(ctx.selection.primary, null);
  }});
  session.replace(project(), {contextChanged: true});
  assert.deepEqual(phases.map(([phase]) => phase), ['S', 'H', 'V']);
  assert.equal(phases[0][1], phases[1][1]);
  expectViews(trace);
  assert.equal(session.selection().contextEpoch, 1);
  assert.throws(() => controller.select(ref('one'), {expectedEpoch: 0}),
    (error) => error.code === 'SELECTION_INVALID');
  controller.destroy(); session.dispose();
});

test('F02-T03: changed project, project-only and combined commits flush once at History', () => {
  const session = create(), phases = [];
  session.subscribe((_project, event) => phases.push(['P', event.source]));
  session.subscribeSelection((_selection, {event}) => phases.push(['S', event.historyRevision]));
  session.subscribeHistory((event) => phases.push(['H', event.historyRevision]));
  const {controller, trace} = install(session, {layers: () => phases.push(['V'])});
  session.execute(change(['one'], 1));
  assert.deepEqual(phases.map(([phase]) => phase), ['P', 'H', 'V']);
  expectViews(trace);
  phases.length = 0; trace.length = 0;
  session.select([ref('one')], ref('one'), {expectedEpoch: 0});
  phases.length = 0; trace.length = 0;
  session.execute(change([], 2));
  assert.deepEqual(phases.map(([phase]) => phase), ['P', 'S', 'H', 'V']);
  expectViews(trace);
  controller.destroy(); session.dispose();
});

test('F02-T04: standalone Selection, misleading source, no-op and clear stay immediate', () => {
  const session = create(), order = [], selections = [], history = [];
  session.subscribeSelection((_snap, {event}) => {order.push('S'); selections.push(event);});
  session.subscribeHistory((event) => history.push(event));
  const {controller, trace} = install(session, {layers: () => order.push('V')});
  assert.equal(session.select([ref('one')], ref('one'), {expectedEpoch: 0, source: 'command'}), true);
  assert.deepEqual(order, ['S', 'V']);
  assert.equal(Object.hasOwn(selections[0], 'historyRevision'), false);
  expectViews(trace);
  trace.length = 0;
  assert.equal(session.select([ref('one')], ref('one'), {expectedEpoch: 0, source: 'replace'}), false);
  assert.deepEqual(trace, []);
  assert.equal(session.select([], null, {expectedEpoch: 0, source: 'command'}), true);
  expectViews(trace);
  assert.equal(history.length, 0);
  controller.destroy(); session.dispose();
});

test('F02-T05: Undo and Redo finalize at History without context rewind', () => {
  const session = create(), order = [];
  session.subscribeHistory(() => order.push('H'));
  const {controller, trace} = install(session, {layers: () => order.push('V')});
  session.select([ref('one')], ref('one'), {expectedEpoch: 0});
  order.length = 0; trace.length = 0;
  session.execute(change([], 1));
  assert.deepEqual(order, ['H', 'V']);
  order.length = 0; trace.length = 0;
  assert.equal(session.undo(), true);
  assert.deepEqual(order, ['H', 'V']);
  assert.deepEqual(session.selection().primary, ref('one'));
  order.length = 0; trace.length = 0;
  assert.equal(session.redo(), true);
  assert.deepEqual(order, ['H', 'V']);
  assert.equal(session.selection().contextEpoch, 0);
  expectViews(trace);
  controller.destroy(); session.dispose();
});

test('F02-T06/T07: Save rebase and effective History-only operations refresh exactly once', () => {
  const session = create(), observed = [];
  session.subscribeHistory((event) => observed.push(event.action));
  const {controller, trace} = install(session);
  session.select([ref('one')], ref('one'), {expectedEpoch: 0});
  trace.length = 0;
  const checkpoint = session.captureSave();
  session.rebase({saveToken: checkpoint.token, baselineProject: checkpoint.submittedProject});
  assert.deepEqual(observed, ['rebase']);
  expectViews(trace);
  assert.deepEqual(session.selection().primary, ref('one'));
  trace.length = 0;
  session.execute(change(['one'], 1));
  trace.length = 0;
  session.clearHistory();
  expectViews(trace);
  assert.equal(session.historyStatus().size, 0);
  trace.length = 0;
  session.clearHistory();
  assert.equal(trace.length, 0);
  assert.equal(session.selection().contextEpoch, 0);
  controller.destroy(); session.dispose();
});

test('F02-T06: normalized rejected save preserves state; no fabricated final view', () => {
  const session = create(), {controller, trace} = install(session);
  session.execute(change(['one'], 1));
  const checkpoint = session.captureSave();
  trace.length = 0;
  assert.throws(() => session.rebase({
    saveToken: checkpoint.token, baselineProject: project(['other'], 22),
  }), (error) => error.code === 'SAVE_REPLAY_CONFLICT');
  assert.equal(trace.length, 0);
  assert.equal(session.historyStatus().canUndo, true);
  assert.equal(session.selection().contextEpoch, 0);
  controller.destroy(); session.dispose();
});

test('F02-T08: failed policies, references and stale epochs cannot produce views', () => {
  const session = create(), {controller, trace} = install(session);
  assert.throws(() => session.select([ref('missing')], ref('missing'), {expectedEpoch: 0}),
    (error) => error.code === 'SELECTION_INVALID');
  assert.throws(() => session.select([ref('one'), ref('one')], ref('one'), {expectedEpoch: 0}),
    (error) => error.code === 'SELECTION_INVALID');
  assert.throws(() => session.select([ref('one')], ref('missing'), {expectedEpoch: 0}),
    (error) => error.code === 'SELECTION_INVALID');
  assert.throws(() => session.select([ref('one')], ref('one'), {expectedEpoch: 200}),
    (error) => error.code === 'SELECTION_INVALID');
  assert.throws(() => session.execute(Object.freeze({label: 'invalid', apply() {throw Error('denied');}})), /denied/);
  assert.equal(trace.length, 0);
  controller.destroy(); session.dispose();
});

test('F02-T09: History adapter errors diagnose and next commit has no stale pending', () => {
  for (const failing of ['layers', 'inspector', 'canvas']) {
    const session = create(), diagnostics = [];
    session.subscribeDiagnostics((event) => diagnostics.push(event));
    let errorOnce = false;
    const {controller, trace} = install(session, {
      [failing]() { if (errorOnce) {errorOnce = false; throw Error('adapter failure');} },
    });
    errorOnce = true;
    session.replace(project(), {contextChanged: true});
    assert.equal(diagnostics.length, 1);
    assert.equal(diagnostics[0].code, 'CB_DESIGNER_OBSERVER_FAILED');
    assert.equal(diagnostics[0].phase, 'history');
    trace.length = 0;
    session.replace(project(), {contextChanged: true});
    expectViews(trace);
    controller.destroy(); session.dispose();
  }
});

test('F02-T09: ordinary Selection failure does not fabricate History diagnostics', () => {
  const session = create(), diagnostics = [];
  session.subscribeDiagnostics((event) => diagnostics.push(event));
  let errorOnce = false;
  const {controller, trace} = install(session, {layers() {
    if (errorOnce) { errorOnce = false; throw Error('standalone view failure'); }
  }});
  errorOnce = true;
  session.select([ref('one')], ref('one'), {expectedEpoch: 0});
  assert.deepEqual(diagnostics, []);
  trace.length = 0;
  session.select([], null, {expectedEpoch: 0});
  expectViews(trace);
  controller.destroy(); session.dispose();
});

test('F02-T10: explicit refresh is independent; destroy is idempotent', () => {
  const session = create(), {controller, trace} = install(session);
  controller.refresh();
  expectViews(trace);
  controller.destroy(); controller.destroy();
  trace.length = 0;
  session.replace(project(), {contextChanged: true});
  assert.equal(trace.length, 0);
  assert.throws(() => controller.refresh(), /destroyed/);
  session.dispose();
});

test('F02-T11: native Host remains on original subscribe/selection ABI', () => {
  const calls = [];
  let listener;
  const native = {
    profile: {id: 'mail'},
    selection: () => ({refs: [], primary: null, revision: 0, contextEpoch: 8}),
    project: () => project(),
    subscribe(cb) {listener = cb; return () => {listener = null;};},
    select(refs, primary) {calls.push([refs, primary]); return true;},
  };
  const controller = editor.createDesignerSelectionController({
    typed: true, session: native, renderLayers: () => calls.push('native-render'),
  });
  assert.equal(typeof listener, 'function');
  controller.clear();
  assert.deepEqual(calls.at(-1), [[], null]);
  controller.destroy();
  assert.equal(listener, null);
});

test('F02-T08/T09: tampered or repeated commit markers fail closed with History diagnostic', () => {
  // Negative fault injection wraps only the published event; CommandHistory
  // and the controller remain the real Base implementations.
  for (const damage of ['mismatch', 'duplicate', 'invalid']) {
    const session = create(), diagnostics = [], views = [];
    session.subscribeDiagnostics((event) => diagnostics.push(event));
    const faultySession = Object.freeze({
      project: () => session.project(),
      selection: () => session.selection(),
      select: (...args) => session.select(...args),
      subscribe: (listener) => session.subscribe(listener),
      subscribeHistory: (listener) => session.subscribeHistory(listener),
      subscribeSelection(listener) {
        return session.subscribeSelection((selection, context) => {
          const changed = {
            ...context.event,
            historyRevision: damage === 'mismatch'
              ? context.event.historyRevision + 1
              : damage === 'invalid' ? 'not-an-integer' : context.event.historyRevision,
          };
          listener(selection, { ...context, event: Object.freeze(changed) });
          if (damage === 'duplicate') listener(selection, { ...context, event: Object.freeze(changed) });
        });
      },
    });
    const controller = editor.createDesignerSelectionController({
      typed: 'domain', session: faultySession,
      renderLayers: () => views.push('layers'),
      renderInspector: () => views.push('inspector'),
      syncCanvas: () => views.push('canvas'),
    });
    views.length = 0;
    session.replace(project(), { contextChanged: true });
    assert.deepEqual(views, [], damage + ' must never publish verified final views');
    assert.equal(diagnostics.length, 1, damage + ' must report isolated History observer error');
    assert.equal(diagnostics[0].phase, 'history');
    controller.destroy();
    session.dispose();
  }
});

test('F02-T07/T10: History-only does not activate panel or rebind Selection epoch', () => {
  const session = create();
  let activations = 0, views = 0;
  const controller = editor.createDesignerSelectionController({
    typed: 'domain', session, shell: { activatePanel() { activations += 1; } },
    renderLayers: () => { views += 1; },
    renderInspector: () => {},
    syncCanvas: () => {},
  });
  views = 0;
  const epoch = session.selection().contextEpoch;
  const baseline = session.captureSave();
  session.rebase({saveToken: baseline.token, baselineProject: baseline.submittedProject});
  assert.equal(views, 1);
  assert.equal(activations, 0);
  assert.equal(session.selection().contextEpoch, epoch);
  assert.equal(session.historyStatus().size, 0);
  controller.destroy(); session.dispose();
});

test('F02-T10: refresh refuses pending Project but final commit still renders', () => {
  const session = create(), errors = [];
  const {controller, trace} = install(session);
  session.subscribe(() => {
    try { controller.refresh(); }
    catch (error) { errors.push(error.message); }
  });
  session.execute(change(['one'], 1));
  assert.equal(errors.length, 1);
  assert.match(errors[0], /pending History/);
  expectViews(trace);
  controller.destroy(); session.dispose();
});

test('F02-T06: verified normalized replay can commit Selection without Project event', () => {
  const session = editor.createDomainSession({
    project: project(), resolveSelectionEntity: resolver,
    verifyRebase: () => true,
  });
  session.select([ref('one')], ref('one'), {expectedEpoch: 0});
  const checkpoint = session.captureSave();
  session.execute(change(['one'], 1)); // Late edit after the checkpoint.
  const phases = [];
  session.subscribe((_project, event) => phases.push(['P', event]));
  session.subscribeSelection((_selection, {event}) => phases.push(['S', event.historyRevision]));
  session.subscribeHistory((event) => phases.push(['H', event.historyRevision]));
  const {controller, trace} = install(session, {layers: () => phases.push(['V'])});
  const result = session.rebase({
    saveToken: checkpoint.token, baselineProject: project(['one'], 2),
  });
  assert.equal(result.normalized, true);
  assert.equal(result.retainedUndoSteps, 1);
  assert.deepEqual(phases.map(([kind]) => kind), ['S', 'H', 'V']);
  assert.equal(phases[0][1], phases[1][1]);
  assert.equal(session.selection().primary, null);
  expectViews(trace);
  controller.destroy(); session.dispose();
});
