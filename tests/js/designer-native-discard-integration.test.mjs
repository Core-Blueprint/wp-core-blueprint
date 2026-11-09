import assert from 'node:assert/strict';
import { isDeepStrictEqual } from 'node:util';
import { cp, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { runInNewContext } from 'node:vm';
import { before, after, test } from 'node:test';

// DF-EXIT-01R: actual Base History, SessionBridge, Persisted Fields,
// SaveCoordinator, Native Host, registry verification and Exit Guard.
// Only the external browser DOM, WordPress transport and profile renderer
// are controlled fixtures. No substitute Discard implementation is supplied.
let modules;
let temporaryDirectory;
const hash = (letter) => 'sha256:' + letter.repeat(64);
const provider = 'fixture.owner';
const moduleId = '@fixture/native';
const profileId = 'base/mail';

async function convert(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const file = join(dir, entry.name);
    if (entry.isDirectory()) { await convert(file); continue; }
    if (!entry.isFile() || !file.endsWith('.js')) continue;
    const original = await readFile(file, 'utf8');
    let rewritten = original.replaceAll(/(['"])(\.\.?\/[^'"]+)\.js\1/g, '$1$2.mjs$1');
    if (entry.name === 'editor.js') {
      rewritten = rewritten.replace("'@cb-core/design-motion'", "'./core/motion.mjs'");
    }
    await writeFile(file.replace(/\.js$/, '.mjs'), rewritten);
  }
}

class NodeElement {
  constructor(document, rootConnected = false) {
    this.ownerDocument = document;
    this.rootConnected = rootConnected;
    this.parentNode = null;
    this.childNodes = [];
    this.attributes = new Map();
    this.dataset = {};
    this.style = { cssText: '' };
    this.inert = false;
    this.checked = false;
    this.disabled = false;
  }
  matches(selector) { return selector === '[data-cb-design-shell]' && this.hasAttribute('data-cb-design-shell'); }
  get isConnected() { return this.rootConnected || Boolean(this.parentNode?.isConnected); }
  contains(other) {
    for (let node = other; node; node = node.parentNode) if (node === this) return true;
    return false;
  }
  appendChild(child) {
    child.remove?.();
    this.childNodes.push(child);
    child.parentNode = this;
    return child;
  }
  append(...children) { for (const child of children) this.appendChild(child); }
  replaceChildren(...children) {
    for (const child of this.childNodes) child.parentNode = null;
    this.childNodes = [];
    this.append(...children);
  }
  remove() {
    if (!this.parentNode) return;
    const siblings = this.parentNode.childNodes;
    const at = siblings.indexOf(this);
    if (at >= 0) siblings.splice(at, 1);
    this.parentNode = null;
  }
  setAttribute(key, value) { this.attributes.set(key, String(value)); }
  getAttribute(key) { return this.attributes.get(key) ?? null; }
  hasAttribute(key) { return this.attributes.has(key); }
  querySelector(selector) {
    const search = (node) => {
      for (const child of node.childNodes) {
        if (child.hasAttribute?.(selector.slice(1, -1))) return child;
        const found = search(child);
        if (found) return found;
      }
      return null;
    };
    return search(this);
  }
}

before(async () => {
  temporaryDirectory = await mkdtemp(join(tmpdir(), 'cb-df-exit-01r-'));
  const destination = join(temporaryDirectory, 'design');
  await cp(new URL('../../assets/js/design/', import.meta.url), destination, { recursive: true });
  await convert(destination);
  globalThis.window = {};
  globalThis.Element = NodeElement;
  const imported = await Promise.all([
    'editor.mjs',
    'platform/host.mjs',
    'platform/registry.mjs',
    'platform/persisted-fields.mjs',
    'platform/save-coordinator.mjs',
  ].map(path => import(pathToFileURL(join(destination, path)).href)));
  modules = {
    editor: imported[0],
    host: imported[1],
    registry: imported[2],
    fields: imported[3],
    coordinator: imported[4],
  };
});
after(async () => {
  delete globalThis.window;
  delete globalThis.Element;
  if (temporaryDirectory) await rm(temporaryDirectory, { recursive: true, force: true });
});

const baselineProject = () => ({
  schema_version: 1,
  design_type: 'fixture.document',
  root: { type: 'fixture.document', provider, properties: { title: 'confirmed' }, children: [] },
});
const baselineFields = () => ({ preheader: 'confirmed' });
const statusSummary = (value) => value
  ? { state: value.state, dirty: value.dirty, blocked: value.blocked }
  : null;

async function createFixture({ unknownSave = false } = {}) {
  const { canonicalDigest, createRegistryView } = modules.registry;
  let rejectConfirmedBaseline = false;
  const command = Object.freeze({
    contractVersion: 1, id: 'fixture.set-title',
    canApply: () => true,
    apply(project, input) {
      return { project: {
        ...project,
        root: { ...project.root, properties: { ...project.root.properties, title: input.value } },
      } };
    },
  });
  const profileModule = Object.freeze({
    __cbPlatformModuleStamp: 'fixture-stamp',
    createRenderAdapter: () => ({
      contractVersion: 1,
      mount: async () => {},
      update: async () => {},
      syncSelection: () => {},
      captureFocus: () => null,
      restoreFocus: () => {},
      destroy: async () => {},
    }),
    validateProject: project => !rejectConfirmedBaseline
      || project.root.properties.title !== 'confirmed',
    resolveCommand: id => id === command.id ? command : null,
    validateCommandInput: () => true,
    authorizeCommand: () => true,
    resolveEntity: () => false,
  });
  const projectSchema = 'fixture/v1';
  const snapshotData = {
    contractVersion: 1, schemaGeneration: 1, profileId, projectSchema,
    providers: [provider],
    designTypes: [{ id: 'fixture.document', ownerProvider: provider, allowSitePresentational: false }],
    nodeTypes: [{
      id: 'fixture.document', provider, allowedParents: [],
      propertiesSchemaId: 'fixture.props',
    }],
    domainSchemas: [{
      id: 'fixture.props', ownerProvider: provider,
      schema: { type: 'object', properties: { title: { type: 'string' } },
        required: ['title'], additionalProperties: false },
    }],
  };
  const schemaSnapshot = { ...snapshotData,
    fingerprint: await canonicalDigest(snapshotData, 'RegistrySnapshotV1') };
  const registryFingerprint = hash('c');
  const fieldData = {
    contractVersion: 1, schemaGeneration: 1, profileId, projectSchema,
    registryFingerprint, projectSchemaFingerprint: schemaSnapshot.fingerprint,
    fieldDescriptors: [{
      contractVersion: 1, id: 'preheader', saveKey: 'preheader',
      labelKey: 'Preheader', placement: 'header', valueSchema: { type: 'string' },
    }],
  };
  const persistedFieldFacet = { ...fieldData,
    fingerprint: await canonicalDigest(fieldData, 'PersistedFieldFacetV1') };
  const identity = {
    siteId: 1, actorId: 2, resourceType: 'mail',
    resourceId: 'fixture', contextEpoch: 0,
  };
  const descriptors = [{
    id: moduleId, owner: provider, dependencies: [],
    exports: ['createRenderAdapter'],
    stamp: 'fixture-stamp', packagePath: 'base/fixture-native.js',
  }];
  const evidence = {
    contractVersion: 1, verificationStatus: 'verified',
    buildId: hash('a'), moduleGraphFingerprint: await canonicalDigest(descriptors, 'ModuleGraphV1'),
    artifactModuleGraphFingerprint: hash('b'), packageMode: 'base-only',
    approvedPackages: { base: { manifestDigest: hash('d'), buildId: hash('e') } },
    context: { siteBlogId: identity.siteId, actorId: identity.actorId,
      resourceType: identity.resourceType, resourceKey: identity.resourceId,
      profileId, registryFingerprint },
    moduleStamps: { [moduleId]: 'fixture-stamp' },
    moduleFileBindings: { [moduleId]: {
      packagePath: 'base/fixture-native.js', packagedDigest: hash('f') } },
    requiredCss: [],
  };
  const bootstrap = {
    contractVersion: 1, registryFingerprint, profileId, identity,
    profile: { id: profileId, moduleId, projectSchema },
    schemaSnapshot, persistedFieldFacet, components: [], controls: [],
    modules: descriptors, evidence,
  };
  const view = createRegistryView(bootstrap, new Map([[moduleId, profileModule]]));
  const document = { defaultView: { getComputedStyle: () => ({
    getPropertyValue: () => '',
  }) } };
  document.createElement = () => new NodeElement(document);
  document.createTextNode = text => ({ textContent: text, parentNode: null, remove() {} });
  document.querySelector = () => null;
  const root = new NodeElement(document, true);
  root.setAttribute('data-cb-design-shell', '');
  const canvasRoot = new NodeElement(document);
  root.appendChild(canvasRoot);
  let realFields;
  let realCoordinator;
  let realBridge;
  const saveContext = Object.freeze({
    ready: true, canSave: true,
    blogId: identity.siteId, actorId: identity.actorId,
    resourceType: identity.resourceType, resourceKey: identity.resourceId,
    profileId, projectSchema, contextEpoch: identity.contextEpoch,
    registryFingerprint, storeRevision: '0',
  });
  const host = modules.host.createPlatformHost({
    root, canvasRoot,
    resolveRegistryView: async () => view,
    createSessionBridge({ initialProject, context, profile, schemaSnapshot: schema }) {
      realBridge = modules.editor.createPlatformSessionBridge({
        initialProject, context, profile, profileModule, schemaSnapshot: schema,
      });
      return realBridge;
    },
    createSaveCoordinator({ sessionBridge }) {
      realFields = modules.fields.createPersistedFields({
        registryView: view, profileId, projectSchema,
        projectRegistrySnapshot: view.projectSchemaSnapshot(profileId),
        fieldValidationContext: view.persistedFieldFacet(profileId),
        fieldFacet: view.persistedFieldFacet(profileId),
        initialFields: baselineFields(),
      });
      const adapter = Object.freeze({
        canSave: () => true,
        serialize: checkpoint => {
          if (!unknownSave) throw new Error('Unexpected Save transport');
          return {
            project: checkpoint.project, fields: checkpoint.fields,
            operationId: checkpoint.operationId,
            expectedStoreRevision: checkpoint.expectedStoreRevision,
          };
        },
        submit: async () => {
          if (!unknownSave) throw new Error('Unexpected Save transport');
          return { status: 'unknown', reason: 'SAVE_TX_STATE_UNVERIFIED' };
        },
        lookupOperation: () => { throw new Error('Unexpected Save recovery'); },
        verifyReceipt: () => false,
        destroy: () => {},
      });
      realCoordinator = modules.coordinator.createSaveCoordinator({
        host: {
          saveContext: () => saveContext,
          captureSave: () => sessionBridge.captureSave(),
          rebase: options => sessionBridge.rebase(options),
          discardToBaseline: options => sessionBridge.discardToBaseline(options),
          validateAcceptedProject: project => sessionBridge.validateAcceptedProject(project),
          isProjectDirty: () => sessionBridge.isProjectDirty(),
        },
        fields: realFields, adapter,
      });
      return realCoordinator;
    },
    createShell({ root: staged }) {
      const surface = document.createElement('div');
      surface.setAttribute('data-cb-platform-render-surface', '');
      staged.appendChild(surface);
      return { activatePlatformSession: () => true, destroy: () => {} };
    },
    readPersistedFields: () => realFields.snapshot().fields,
    updatePersistedField: (_save, key, value) => realFields.set(key, value),
    applyTemplateDocument: (_save, document) => realBridge.applyTemplateDocument(
      document.project, realFields.prepareReplace(document.fields),
    ),
  });
  await host.mount({ bootstrap, project: baselineProject(), persistedFields: baselineFields() });
  assert.equal(host.state, 'READY', 'The real Native Host must mount before diagnosis');
  // Exercise the same public facade the WordPress Native adapter receives.
  // Its createHost seam returns our *already mounted real Host*, preserving
  // real History/Fields/SaveCoordinator while avoiding unrelated chrome DOM.
  const publicHost = modules.editor.attachTrustedDesignerHost({
    root, canvasRoot, bootstrap,
    runtimeModules: new Map([[moduleId, profileModule]]),
    createHost: () => host,
    createSaveAdapter: () => { throw new Error('Unexpected Save adapter creation'); },
    loadReceipt: async () => { throw new Error('Unexpected Save receipt read'); },
  });
  assert.equal(typeof publicHost.discard, 'function', 'Native public Host must expose Discard');
  return { host, publicHost: () => publicHost, root, document, bridge: () => realBridge,
    fields: () => realFields, coordinator: () => realCoordinator,
    rejectBaseline: () => { rejectConfirmedBaseline = true; } };
}

async function runScenario(name, { projectDirty = false, fieldDirty = false, rejectRestore = false, unknownSave = false } = {}) {
  const instance = await createFixture({ unknownSave });
  const { root, document } = instance;
  const host = instance.publicHost();
  if (projectDirty) host.editor.execute('fixture.set-title', { value: 'unsaved' });
  if (fieldDirty) host.editor.setPersistedField('preheader', 'unsaved');
  if (rejectRestore) instance.rejectBaseline();
  let directUnknownRefusal = null;
  if (unknownSave) {
    const result = await host.editor.save();
    assert.equal(result.status, 'unknown', 'UNKNOWN must originate in real SaveCoordinator');
    try { host.discard(); }
    catch (error) {
      directUnknownRefusal = { name: error?.name ?? null, code: error?.code ?? null };
    }
  }
  const originalDirty = host.editor.saveStatus().dirty;
  const coordinatorBefore = statusSummary(instance.coordinator().status());
  const historyBefore = instance.bridge().historyStatus();
  let discardOutcome = null;
  let exception = null;
  let exitCount = 0;
  const toasts = [];
  const window = {
    addEventListener: () => {},
    cbCore: {
      toast: { error: message => toasts.push('error'), warning: message => toasts.push('warning') },
      modal: { show: async options => {
        if (!options.onConfirm) return true;
        const walk = node => [node, ...(node.childNodes || []).flatMap(walk)];
        const radios = walk(options.body).filter(node => node?.value === 'save' || node?.value === 'discard');
        for (const radio of radios) radio.checked = radio.value === 'discard';
        return options.onConfirm();
      } },
    },
  };
  const guardSource = readFileSync(new URL('../../assets/js/features/designer-exit-guard.js', import.meta.url), 'utf8');
  runInNewContext(guardSource, { window, document, Element: NodeElement, Error, TypeError });
  const guard = window.cbCore.designerExitGuard;
  guard.register(root, {
    isDirty: () => host.editor.saveStatus().dirty,
    isUncertain: () => {
      const status = host.editor.saveStatus();
      return status.blocked !== false || !['READY_CLEAN', 'READY_DIRTY'].includes(status.state);
    },
    discard: () => {
      try { discardOutcome = host.discard(); return discardOutcome; }
      catch (error) {
        discardOutcome = false;
        exception = {
          name: error?.name ?? 'Error',
          code: error?.code ?? null,
          message: error?.message ?? null,
          site: String(error?.stack ?? '').split('\n')[1]?.trim() ?? null,
        };
        throw error;
      }
    },
  });
  const exitAllowed = await guard.request(root, () => { exitCount++; return true; });
  const coordinatorAfter = statusSummary(instance.coordinator().status());
  const historyRestored = isDeepStrictEqual(instance.bridge().project(), baselineProject());
  const fieldsRestored = instance.fields().snapshot().dirty === false;
  const finalDirty = host.editor.saveStatus().dirty;
  const report = {
    scenario: name, originalDirty, coordinatorBefore,
    historyBefore, exception, discardOutcome, directUnknownRefusal,
    historyRestored, fieldsRestored, coordinatorAfter,
    finalDirty, exitAllowed, exitCount, toasts,
    component: exception?.site ?? null,
  };
  console.log('DF-EXIT-01R DIAGNOSTIC ' + JSON.stringify(report));
  return report;
}

for (const [name, variant] of [
  ['A project-only dirty', { projectDirty: true }],
  ['B persisted-fields-only dirty', { fieldDirty: true }],
  ['C combined dirty', { projectDirty: true, fieldDirty: true }],
]) {
  test(name, async () => {
    const result = await runScenario(name, variant);
    assert.equal(result.originalDirty, true);
    assert.equal(result.discardOutcome, true);
    assert.equal(typeof result.discardOutcome, 'boolean', 'Discard must be synchronous');
    assert.equal(result.historyRestored, true);
    assert.equal(result.fieldsRestored, true);
    assert.equal(result.finalDirty, false);
    assert.equal(result.coordinatorAfter.state, 'READY_CLEAN');
    assert.equal(result.exitAllowed, true);
    assert.equal(result.exitCount, 1);
    assert.equal(result.exception, null);
  });
}

test('D failed restoration preserves fail-closed Exit Guard', async () => {
  const result = await runScenario('D forced schema rejection', {
    projectDirty: true, fieldDirty: true, rejectRestore: true,
  });
  assert.equal(result.originalDirty, true);
  assert.equal(result.discardOutcome, false);
  assert.equal(result.exitAllowed, false);
  assert.equal(result.exitCount, 0);
  assert.ok(result.exception, 'Original exception must remain observable at adapter boundary');
  assert.equal(result.finalDirty, true);
});


test('E UNKNOWN Save remains blocked across public Native Host and Exit Guard', async () => {
  const result = await runScenario('E UNKNOWN Save', { fieldDirty: true, unknownSave: true });
  assert.equal(result.originalDirty, true);
  assert.equal(result.coordinatorBefore.state, 'UNKNOWN');
  assert.equal(result.coordinatorBefore.blocked, true);
  assert.equal(result.discardOutcome, null, 'Exit Guard must refuse before invoking Discard');
  assert.deepEqual(result.directUnknownRefusal, {
    name: 'Error', code: 'SAVE_PENDING_CONTEXT_BLOCKED',
  }, 'Public Host Discard must enforce UNKNOWN Save gate');
  assert.equal(result.exitAllowed, false);
  assert.equal(result.exitCount, 0);
  assert.equal(result.finalDirty, true);
  assert.equal(result.coordinatorAfter.state, 'UNKNOWN');
});


// T3-01 uses real canonical PlatformHost, PlatformSessionBridge,
// CommandHistory, PersistedFields and SaveCoordinator, with no template Save.
test('T3-01 native template apply is one dirty, undoable, independent project + fields change', async () => {
  const instance = await createFixture();
  const editor = instance.publicHost().editor;
  const intent = editor.captureApplyIntent();
  const document = {
    project: {
      ...baselineProject(), root: {
        ...baselineProject().root, properties: {title: 'applied'},
      },
    },
    fields: {preheader: 'template value'},
  };
  const reply = {
    status: 'ready', source: 'core-blueprint-mail', templateId: 'wordpress.new-user',
    profileId, resourceType: 'mail', resourceKey: 'fixture',
    contextEpoch: 0, blogId: 1, actorId: 2, document,
  };
  assert.equal(editor.applyTemplate(reply, intent), true);
  assert.equal(editor.project().root.properties.title, 'applied');
  assert.equal(editor.persistedFields().preheader, 'template value');
  assert.equal(editor.historyStatus().size, 1);
  assert.equal(editor.saveStatus().dirty, true);
  assert.equal(editor.undo(), true);
  assert.deepEqual(editor.project(), baselineProject());
  assert.deepEqual({ ...editor.persistedFields() }, baselineFields());
  assert.equal(editor.saveStatus().dirty, false);
  assert.equal(editor.redo(), true);
  assert.equal(editor.project().root.properties.title, 'applied');
  assert.equal(editor.persistedFields().preheader, 'template value');
  assert.equal(editor.saveStatus().dirty, true);
  assert.equal(editor.applyTemplate(reply, intent), false, 'reused Apply intent must be refused');
});

test('T3-01 incorrect target, changed editor and UNKNOWN Save refuse Apply without partial changes', async () => {
  const instance = await createFixture({unknownSave: true});
  const editor = instance.publicHost().editor;
  const doc = {project: {...baselineProject(), root: {
    ...baselineProject().root, properties: {title: 'applied'},
  }}, fields: {preheader: 'new'}};
  const receipt = {
    status: 'ready', source: 'core-blueprint-mail', templateId: 'wordpress.new-user',
    profileId, resourceType: 'mail', resourceKey: 'fixture',
    contextEpoch: 0, blogId: 1, actorId: 2, document: doc,
  };
  const wrongTarget = editor.captureApplyIntent();
  assert.throws(() => editor.applyTemplate({...receipt, resourceKey: 'other'}, wrongTarget),
    /SAVE_CONTEXT_CHANGED/);
  assert.equal(editor.saveStatus().dirty, false);
  const changedIntent = editor.captureApplyIntent();
  editor.execute('fixture.set-title', {value: 'intervening'});
  assert.throws(() => editor.applyTemplate(receipt, changedIntent), /SAVE_CONTEXT_CHANGED/);
  assert.equal(editor.project().root.properties.title, 'intervening');
  assert.equal(editor.persistedFields().preheader, 'confirmed');

  const unknownIntent = editor.captureApplyIntent();
  editor.setPersistedField('preheader', 'pending');
  const outcome = await editor.save();
  assert.equal(outcome.status, 'unknown');
  assert.throws(() => editor.applyTemplate(receipt, unknownIntent),
    /SAVE_CONTEXT_CHANGED|SAVE_PENDING_CONTEXT_BLOCKED/);
  assert.throws(() => editor.captureApplyIntent(), /SAVE_PENDING_CONTEXT_BLOCKED/);
  assert.equal(editor.persistedFields().preheader, 'pending');
});

test('T3-01 rejected project and failed field adjunct preserve atomic History state', async () => {
  const instance = await createFixture();
  const bridge = instance.bridge();
  const fields = instance.fields();
  const beforeProject = bridge.snapshot();
  const beforeFields = fields.snapshot();
  const oldHistory = bridge.historyStatus();
  const witness = fields.prepareReplace({preheader: 'new'});
  assert.throws(() => bridge.applyTemplateDocument({
    ...baselineProject(), root: {
      ...baselineProject().root, properties: {title: 123},
    },
  }, witness), /SCHEMA_DOMAIN_INVALID|Stable|schema/i);
  assert.deepEqual(bridge.snapshot(), beforeProject);
  assert.equal(fields.snapshot().fieldRevision, beforeFields.fieldRevision);
  assert.deepEqual(bridge.historyStatus(), oldHistory);

  let touched = false;
  const broken = Object.freeze({
    ...witness, commit() { witness.commit(); touched = true; throw new Error('INJECTED_FIELD_COMMIT_FAILURE'); },
  });
  assert.throws(() => bridge.applyTemplateDocument({
    ...baselineProject(), root: {
      ...baselineProject().root, properties: {title: 'updated'},
    },
  }, broken), /INJECTED_FIELD_COMMIT_FAILURE/);
  assert.equal(touched, true);
  assert.deepEqual(bridge.snapshot(), beforeProject);
  assert.deepEqual(fields.snapshot().fields, beforeFields.fields);
  assert.equal(fields.snapshot().fieldRevision, beforeFields.fieldRevision);
  assert.deepEqual(bridge.historyStatus(), oldHistory);
});
