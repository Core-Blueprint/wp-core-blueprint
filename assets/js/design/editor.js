import { bindRegisteredCommand, bindRegisteredBatch } from './core/commands.js';
import { validateStableProjectV1 } from './core/stable-schema.js';
import { sameValue } from './core/value.js';
import { createPlatformHost } from './platform/host.js';
import { createRegistryView } from './platform/registry.js';
import { createPersistedFields, canonicalSaveJSON } from './platform/persisted-fields.js';
import { createSaveCoordinator as createCanonicalSaveCoordinator } from './platform/save-coordinator.js';
import {
	CommandHistory,
	EditorState,
	ProjectState,
	buildInspectorContext,
	handleEditorShortcut,
	insertNodeCommand,
	nodeAt,
	normalizePath,
	removeNodeCommand,
	reorderNodeCommand,
	setPropertyCommand,
} from './core/index.js';
import {
	DESIGNER_MOTION_DEFAULTS,
	DESIGNER_MOTION_KEY_ATTRIBUTE,
	animateLayoutChange,
} from '@cb-core/design-motion';
import * as fixedProfile from './document/fixed/index.js';
import * as flowProfile from './document/flow/index.js';
import * as mailProfile from './mail/index.js';
import {
	DESIGNER_ICON_NAMES,
	DESIGNER_LAYER_ACTIONS,
	DESIGNER_PALETTE_ROLES,
	DESIGNER_SIDEBAR_ROLES,
	configureDesignerSidebar,
	createDesignerIcon,
	createDesignerInspectorControls,
	createDesignerInspectorTextControl,
	createDesignerInspectorIdentity,
	createDesignerInspectorSection,
	createDesignerInspectorToggle,
	enableDesignerTextareaAutosize,
	createDesignerLayerRow,
	createDesignerLayerTree,
	createDesignerNavigation,
	createDesignerSelectionController,
	createDesignerShell,
	claimDesignerHostRoot,
	decorateDesignerControl,
	decorateDesignerElement,
} from './shell/index.js';
import {
	DESIGNER_VIEWPORT_ORDER,
	configureDesignerViewports,
} from './shell/viewports.js';

const PROFILE_APIS = Object.freeze({
	'document-fixed': Object.freeze({ ...fixedProfile }),
	'document-flow': Object.freeze({ ...flowProfile }),
	'mail': Object.freeze({ ...mailProfile }),
});

const resolveProfile = (profileId) => {
	const id = String(profileId || '').trim();
	if (!Object.hasOwn(PROFILE_APIS, id)) {
		throw new RangeError(`Unknown Design Foundation editor profile: ${id || '(empty)'}.`);
	}
	return Object.freeze({ id, api: PROFILE_APIS[id] });
};

const validateProfileProject = (profile, project) => {
	if (typeof profile.api.validateProject !== 'function') {
		throw new TypeError(`Design Foundation profile ${profile.id} does not expose validateProject().`);
	}
	profile.api.validateProject(project);
};

const runValidation = (validate, project, context, editorState) => {
	if (typeof validate !== 'function') {
		editorState.validation.clear();
		return [];
	}
	const diagnostics = validate(project, context);
	if (!Array.isArray(diagnostics)) {
		throw new TypeError('Design editor validate() must return an array of diagnostics.');
	}
	editorState.validation.replace(diagnostics);
	return editorState.validation.all();
};

/**
 * Create one consumer-owned Design Foundation editor session.
 *
 * Base owns project/session/history mechanics and the selected profile invariant.
 * Consumers own their domain model, persistence, validation policy and rendered
 * editor UI. Profiles own their output-specific project validation.
 */
export const createSession = ({
	project,
	profile,
	validate = null,
	onChange = null,
	onSelectionChange = null,
	allowCommand = null,
	historyLimit = 100,
	verifyRebase = null,
} = {}) => {
	const profileContext = resolveProfile(profile);
	const projectState = new ProjectState(project, {
		validate: (candidate) => validateProfileProject(profileContext, candidate),
	});
	const editorState = new EditorState();
	const history = new CommandHistory(projectState, editorState, { limit: historyLimit, verifyRebase });
	let disposed = false;

	const validationContext = (event = null) => Object.freeze({
		profile: profileContext,
		event,
		editorState,
	});

	const validateCurrent = (event = null) => runValidation(
		validate,
		projectState.current(),
		validationContext(event),
		editorState,
	);

	validateCurrent();

	const unsubscribeProject = projectState.subscribe((event, currentProject) => {
		const diagnostics = validateCurrent(event);
		if (typeof onChange === 'function') {
			onChange(currentProject, Object.freeze({
				event,
				diagnostics,
				profile: profileContext,
			}));
		}
	});

	const selectionContext = (event) => Object.freeze({
		event,
		profile: profileContext,
		projectRevision: projectState.revision,
		inspector: buildInspectorContext(
			projectState.current().root,
			editorState.selection,
			editorState.validation,
		),
	});

	const subscribeSelection = (listener, options = {}) => editorState.selection.subscribe(
		(event, snapshot) => listener(snapshot, selectionContext(event)),
		options,
	);

	const unsubscribeConfiguredSelection = typeof onSelectionChange === 'function'
		? subscribeSelection(onSelectionChange)
		: null;

	const assertActive = () => {
		if (disposed) throw new Error('Design editor session has been disposed.');
	};

	const execute = (editorCommand) => {
		assertActive();
		history.assertWritable();
		if (!editorCommand || typeof editorCommand.apply !== 'function') {
			throw new TypeError('Design editor execute() requires an editor command.');
		}
		if (typeof allowCommand === 'function') {
			const allowed = history.guardPolicy(() => allowCommand(editorCommand, Object.freeze({
				project: projectState.current(),
				editorState,
				profile: profileContext,
			})));
			if (allowed === false) {
				throw new Error(`Design editor command denied by consumer policy: ${String(editorCommand.label || 'command')}.`);
			}
		}
		return history.execute(editorCommand);
	};

	return Object.freeze({
		profile: profileContext,
		projectState,
		editorState,
		history,
		project() { assertActive(); return projectState.current(); },
		snapshot() { assertActive(); return projectState.snapshot(); },
		replace(nextProject, options = {}) {
			assertActive();
			return history.replace(nextProject, {
				source: options?.source || 'editor',
				resetSelection: options?.resetSelection === true,
				contextChanged: options?.contextChanged === true,
			});
		},
		selection() { assertActive(); return editorState.selection.snapshot(); },
		/**
		 * Set the transient selection atomically without mutating document data.
		 * An explicitly opted-in external projection relaxes only node existence
		 * checks; path shape and primary membership always fail closed.
		 */
		setSelection(paths, { primary = null, source = 'consumer', external = false } = {}) {
			assertActive();
			history.assertWritable();
			if (!Array.isArray(paths)) throw new TypeError('Design editor selection paths must be an array.');
			const normalized = paths.map(normalizePath);
			const normalizedPrimary = primary === null ? null : normalizePath(primary);
			if (external !== true && normalized.some((path) => !nodeAt(projectState.current().root, path))) {
				throw new RangeError('Design editor selection contains a path outside the current project.');
			}
			if (normalizedPrimary !== null && !normalized.some((path) => (
				path.length === normalizedPrimary.length
				&& path.every((segment, index) => segment === normalizedPrimary[index])
			))) {
				throw new RangeError('Design editor selection primary must be one of the selected paths.');
			}
			return editorState.selection.set(normalized, normalizedPrimary, { source, action: 'set' });
		},
		/**
		 * Read-only status of the committed history stack, not its mutable internals.
		 */
		historyStatus() {
			assertActive();
			return history.status();
		},
		subscribeHistory(listener) {
			assertActive();
			return history.subscribeHistory(listener);
		},
		subscribeDiagnostics(listener) {
			assertActive();
			return history.subscribeDiagnostics(listener);
		},
		captureSave() {
			assertActive();
			return history.captureSave();
		},
		baselineSnapshot() {
			assertActive();
			return history.baselineSnapshot();
		},
		rebase(options) {
			assertActive();
			return history.rebase(options);
		},
		clearHistory() {
			assertActive();
			return history.clear();
		},
		select(path, { additive = false, source = 'consumer' } = {}) {
			assertActive();
			history.assertWritable();
			if (!Array.isArray(path) || !nodeAt(projectState.current().root, path)) return false;
			editorState.selection.select(path, { additive, source, action: 'select' });
			return true;
		},
		clearSelection({ source = 'consumer' } = {}) {
			assertActive();
			history.assertWritable();
			return editorState.selection.clear({ source, action: 'clear' });
		},
		subscribeSelection(listener, options = {}) {
			assertActive();
			if (typeof listener !== 'function') throw new TypeError('Design editor selection listener must be a function.');
			return subscribeSelection(listener, options);
		},
		execute,
		undo() {
			assertActive();
			return history.undo();
		},
		redo() {
			assertActive();
			return history.redo();
		},
		validate() {
			assertActive();
			return validateCurrent();
		},
		inspector() {
			assertActive();
			return buildInspectorContext(
				projectState.current().root,
				editorState.selection,
				editorState.validation,
			);
		},
		handleShortcut(event, { onDelete = null } = {}) {
			assertActive();
			return handleEditorShortcut(event, { history, onDelete });
		},
		dispose() {
			if (disposed) return;
			disposed = true;
			unsubscribeConfiguredSelection?.();
			unsubscribeProject();
			history.dispose();
		},
	});
};

/**
 * The single domain-neutral session backed by Base's canonical transaction
 * engine. Consumers retain authority over domain schema and persistence.
 */
export const createDomainSession = ({
	project,
	validateProject = null,
	allowCommand = null,
	onChange = null,
	verifyRebase = null,
	historyLimit = 100,
} = {}) => {
	for (const [name, candidate] of Object.entries({ validateProject, allowCommand, onChange, verifyRebase })) {
		if (candidate !== null && typeof candidate !== 'function') {
			throw new TypeError('Domain session ' + name + ' must be a function or null.');
		}
	}
	const projectState = new ProjectState(project, { validate: validateProject });
	const editorState = new EditorState();
	const history = new CommandHistory(projectState, editorState, { limit: historyLimit, verifyRebase });
	let disposed = false;
	const assertActive = () => {
		if (disposed) throw new Error('Domain editor session has been disposed.');
	};
	const subscribe = (listener) => {
		assertActive();
		if (typeof listener !== 'function') throw new TypeError('Domain session listener must be a function.');
		return projectState.subscribe((event, current) => listener(current, event));
	};
	const unsubscribeConfigured = onChange ? subscribe(onChange) : null;
	return Object.freeze({
		project() { assertActive(); return projectState.current(); },
		snapshot() { assertActive(); return projectState.snapshot(); },
		replace(nextProject, options = {}) {
			assertActive();
			return history.replace(nextProject, options);
		},
		subscribe,
		execute(command) {
			assertActive();
			if (!command || typeof command.apply !== 'function') {
				throw new TypeError('Domain session execute() requires a command with apply(project).');
			}
			history.assertWritable();
			if (allowCommand && history.guardPolicy(
				() => allowCommand(command, projectState.current()),
			) === false) {
				throw new Error('Domain session command denied by consumer policy.');
			}
			return history.execute(command);
		},
		undo() { assertActive(); return history.undo(); },
		redo() { assertActive(); return history.redo(); },
		clearHistory() { assertActive(); return history.clear(); },
		historyStatus() { assertActive(); return history.status(); },
		subscribeHistory(listener) { assertActive(); return history.subscribeHistory(listener); },
		subscribeDiagnostics(listener) { assertActive(); return history.subscribeDiagnostics(listener); },
		captureSave() { assertActive(); return history.captureSave(); },
		baselineSnapshot() { assertActive(); return history.baselineSnapshot(); },
		rebase(options) { assertActive(); return history.rebase(options); },
		dispose() {
			if (disposed) return;
			disposed = true;
			unsubscribeConfigured?.();
			history.dispose();
		},
	});
};

/**
 * Single Host-owned command, selection and History authority for a staged
 * context. The only executable command definitions originate in its trusted
 * installed profile module, never the document, DOM or PHP transport.
 */
export const createPlatformSessionBridge = ({
	initialProject, context, profile, profileModule, schemaSnapshot,
} = {}) => {
	if (!profileModule || typeof profileModule.validateProject !== 'function'
		|| !context || !Number.isSafeInteger(context.contextEpoch)
		|| !profile || schemaSnapshot?.profileId !== profile.id
		|| typeof profileModule.resolveCommand !== 'function'
		|| typeof profileModule.validateCommandInput !== 'function'
		|| typeof profileModule.authorizeCommand !== 'function') {
		throw new TypeError('HOST_CAPABILITY_UNAVAILABLE');
	}
	const validatePlatformProject = (candidate) => {
		const result = validateStableProjectV1(candidate, {
			profileId: profile.id, projectSchema: profile.projectSchema,
			registrySnapshot: schemaSnapshot,
		});
		if (result?.valid !== true || result.diagnostics?.length !== 0) {
			throw new TypeError('SCHEMA_DOMAIN_INVALID');
		}
		const accepted = profileModule.validateProject(candidate, schemaSnapshot);
		if (accepted === false || accepted?.valid === false) {
			throw new TypeError('SCHEMA_DOMAIN_INVALID');
		}
	};
	const projectState = new ProjectState(initialProject, {
		validate: validatePlatformProject,
	});
	const editorState = new EditorState();
	editorState.selection.configureTyped({
		contextEpoch: context.contextEpoch,
		resolveEntity: typeof profileModule.resolveEntity === 'function'
			? (ref, project) => profileModule.resolveEntity(ref, project, context) === true
			: () => false,
	});
	const history = new CommandHistory(projectState, editorState);
	const subscriptions = new Set();
	let disposed = false;
	const active = () => {
		if (disposed) throw new Error('HOST_CONTEXT_REFUSED');
	};
	const policies = Object.freeze({
		validateInput: (definition, input) => profileModule.validateCommandInput(definition, input, schemaSnapshot),
		authorize: (definition, project, input) =>
			profileModule.authorizeCommand(definition, project, input, context) === true,
	});
	const resolve = (id) => profileModule.resolveCommand(id);
	return Object.freeze({
		project() { active(); return projectState.current(); },
		snapshot() { active(); return projectState.snapshot(); },
		selection() { active(); return editorState.selection.typedSnapshot(); },
		select(refs, primary = null) {
			active();
			history.assertWritable();
			return editorState.selection.setRefs(refs, primary, {
				project: projectState.current(), contextEpoch: context.contextEpoch,
				source: 'host', action: 'select',
			});
		},
		execute(id, input) {
			active();
			const definition = resolve(id);
			if (!definition || definition.id !== id) throw new Error('COMMAND_REFUSED');
			return history.execute(bindRegisteredCommand(definition, input, policies));
		},
		executeBatch(requests) {
			active();
			const compound = bindRegisteredBatch(requests, {resolveCommand: resolve, ...policies});
			return history.executeBatch(compound.steps);
		},
		undo() { active(); return history.undo(); },
		redo() { active(); return history.redo(); },
		historyStatus() { active(); return history.status(); },
		subscribe(listener) {
			active();
			if (typeof listener !== 'function') throw new TypeError('HOST_CAPABILITY_UNAVAILABLE');
			const notify = () => listener();
			const offProject = projectState.subscribe(notify);
			const offSelection = editorState.selection.subscribe(notify);
			const offHistory = history.subscribeHistory(notify);
			const unsubscribe = () => {
				offProject();
				offSelection();
				offHistory();
				subscriptions.delete(unsubscribe);
			};
			subscriptions.add(unsubscribe);
			return unsubscribe;
		},
		// Private SaveCoordinator integration. Tokens never cross the adapter.
		captureSave() { active(); return history.captureSave(); },
		rebase(options) { active(); return history.rebase(options); },
		baselineSnapshot() { active(); return history.baselineSnapshot(); },
		validateAcceptedProject(project) {
			active();
			validatePlatformProject(project);
		},
		isProjectDirty() {
			active();
			return !sameValue(projectState.current(), history.baselineSnapshot());
		},
		dispose() {
			if (disposed) return;
			disposed = true;
			for (const off of [...subscriptions]) off();
			history.dispose();
		},
	});
};

/**
 * Real Base chrome, created only in Host's disconnected staging canvas.
 * The registered RenderAdapter owns the inner render surface, not this toolbar.
 * No template HTML or document-provided event handlers are executed.
 */
const composePlatformChrome = (root) => {
	if (root.childNodes.length || root.isConnected) throw new Error('HOST_CONTEXT_REFUSED');
	const doc = root.ownerDocument;
	root.classList.add('cb-core-design-shell');
	root.setAttribute('data-cb-design-shell', '');
	const node = (tag, className, attrs = {}, label = null) => {
		const el = doc.createElement(tag);
		if (className) el.className = className;
		for (const [key, value] of Object.entries(attrs)) el.setAttribute(key, value);
		if (label !== null) el.textContent = label;
		return el;
	};
	const button = (label, attribute, value = '') => node('button', 'button cb-core-button', {
		type: 'button', [attribute]: value,
	}, label);
	const toolbar = node('div', 'cb-core-design-shell__toolbar', {
		role: 'toolbar', 'aria-label': 'Designer actions',
	});
	const history = node('div', 'cb-core-design-shell__toolbar-group');
	const undo = button('Undo', 'data-cb-design-shell-undo');
	const redo = button('Redo', 'data-cb-design-shell-redo');
	undo.disabled = true;
	redo.disabled = true;
	history.append(undo, redo);
	const fullscreen = button('Fullscreen', 'data-cb-design-shell-fullscreen');
	fullscreen.setAttribute('aria-pressed', 'false');
	const save = button('Save', 'data-cb-design-shell-primary-action');
	save.classList.add('button-primary', 'cb-core-button--primary');
	toolbar.append(history, fullscreen, save);
	const notice = node('p', 'cb-core-design-shell__persistent-notice', {
		'data-cb-design-shell-persistent-notice': '', 'role': 'status', 'aria-live': 'polite',
	});
	notice.hidden = true;
	const workspace = node('div', 'cb-core-design-shell__workspace');
	const palette = node('section', 'cb-core-design-shell__palette', {'aria-label': 'Elements and layers'});
	const canvas = node('section', 'cb-core-design-shell__canvas', {'aria-label': 'Design canvas'});
	const surface = node('div', 'cb-core-design-shell__canvas-workarea', {'data-cb-platform-render-surface': ''});
	canvas.append(surface);
	const sidebar = node('aside', 'cb-core-design-shell__sidebar', {'aria-label': 'Inspector and settings'});
	const tabs = (container, group, definitions) => {
		const tablist = node('div', 'cb-core-design-shell__tabs', {role: 'tablist'});
		for (const [id, label] of definitions) {
			const attrs = {'data-cb-design-shell-tab': id, role: 'tab', 'aria-selected': 'false'};
			if (group) attrs['data-cb-design-shell-group'] = group;
			tablist.append(node('button', 'cb-core-design-shell__tab', {
				...attrs, type: 'button',
			}, label));
		}
		container.append(tablist);
		for (const [id] of definitions) {
			const attrs = {'data-cb-design-shell-panel': id};
			if (group) attrs['data-cb-design-shell-group'] = group;
			const panel = node('div', 'cb-core-design-shell__panel', attrs);
			panel.append(node('div', 'cb-core-design-shell__panel-body', {
				'data-cb-platform-panel': id,
			}));
			container.append(panel);
		}
	};
	tabs(palette, 'palette', [['elements','Elements'],['layers','Layers']]);
	tabs(sidebar, null, [['inspector','Inspector'],['settings','Settings']]);
	workspace.append(palette, canvas, sidebar);
	root.append(toolbar, notice, workspace);
	return {save, notice, undo, redo, surface};
};

/**
 * Trusted static-ESM A2 Host attachment boundary. The integration owner must
 * import the actual Host factory from platform/host.js and obtain bootstrap
 * from authorized PHP; neither is available in this SOURCE-only branch.
 * This bridge holds one shell lease, never a second History/Save/render queue.
 * Do not expose this constructor on window.cbCore or treat its bootstrap
 * argument as proof of authorization: the Host validates trusted evidence.
 */
export const attachTrustedDesignerHost = ({
	root, canvasRoot, bootstrap, runtimeModules,
	createHost = createPlatformHost,
	createSaveAdapter,
	loadReceipt,
} = {}) => {
	if (!(root instanceof Element) || !root.matches('[data-cb-design-shell]')) {
		throw new TypeError('Designer Platform requires a canonical shell root.');
	}
	if (typeof createHost !== 'function' || typeof createSaveAdapter !== 'function'
		|| typeof loadReceipt !== 'function'
		|| !(runtimeModules instanceof Map) || !canvasRoot || !root.contains(canvasRoot)) {
		throw new TypeError('Designer Platform requires a statically imported Host factory.');
	}
	if (!bootstrap || typeof bootstrap !== 'object' || Array.isArray(bootstrap)) {
		throw new TypeError('Designer Platform requires server-provided bootstrap evidence.');
	}

	const lease = claimDesignerHostRoot(root);
	const stagedShells = new WeakMap();
	const stagedSaveUI = new WeakMap();
	const savesBySignal = new WeakMap();
	const activeRegistries = new Map();
	const registriesByIdentity = new Map();
	const registryFor = async (candidateBootstrap) => {
		const registry = createRegistryView(candidateBootstrap, runtimeModules);
		await registry.verify();
		const key = JSON.stringify([registry.profileId, registry.identity]);
		const identityKey = JSON.stringify(registry.identity);
		const prior = registriesByIdentity.get(identityKey);
		if (prior && prior.profileId !== registry.profileId) throw new Error('REGISTRY_COLLISION');
		activeRegistries.set(key, registry);
		registriesByIdentity.set(identityKey, registry);
		return registry;
	};
	// A single field store and a single Luna 5 SaveCoordinator per Host
	// context. Neither is exposed to consumer code or the EditorFacade.
	const fieldsOfSave = new WeakMap();
	const connectSaveCoordinator = async ({
		sessionBridge, identity, persistedFieldFacet, initialFields, evidence, signal,
	}) => {
		if (signal?.aborted || evidence?.verificationStatus !== 'verified') {
			throw new Error('SAVE_NOT_READY');
		}
		const registry = activeRegistries.get(JSON.stringify([persistedFieldFacet.profileId, identity]));
		const profile = registry?.profile;
		const schema = registry?.projectSchemaSnapshot(profile?.id);
		if (!registry || !schema
			|| registry.persistedFieldFacet(profile.id) !== persistedFieldFacet
			|| registry.evidence !== evidence) throw new Error('REGISTRY_INVALID');
		const loaded = await loadReceipt(Object.freeze({
			identity, profile, schema, evidence, registryFingerprint: registry.fingerprint,
			signal,
		}));
		if (signal.aborted || loaded?.status !== 'loaded'
			|| loaded.profileId !== profile.id
			|| loaded.projectSchema !== profile.projectSchema
			|| loaded.registryFingerprint !== registry.fingerprint
			|| loaded.contextEpoch !== identity.contextEpoch
			|| loaded.blogId !== identity.siteId
			|| loaded.actorId !== identity.actorId
			|| loaded.resourceType !== identity.resourceType
			|| loaded.resourceKey !== identity.resourceId
			|| !/^(0|[1-9][0-9]*)$/.test(loaded.storeRevision || '')
			|| canonicalSaveJSON(loaded.project) !== canonicalSaveJSON(sessionBridge.project())
			|| canonicalSaveJSON(loaded.fields) !== canonicalSaveJSON(initialFields)) {
			throw new Error('SAVE_NOT_READY');
		}
		const fields = createPersistedFields({
			registryView: registry,
			profileId: profile.id,
			projectSchema: profile.projectSchema,
			projectRegistrySnapshot: schema,
			fieldValidationContext: persistedFieldFacet,
			fieldFacet: persistedFieldFacet,
			initialFields: loaded.fields,
		});
		let coordinator;
		try {
			const adapter = await createSaveAdapter(Object.freeze({
				identity, profile, schema, evidence, loadReceipt: loaded, signal,
			}));
			if (signal.aborted) throw new Error('SAVE_CONTEXT_CHANGED');
			const context = Object.freeze({
				ready: true, canSave: true,
				blogId: identity.siteId, actorId: identity.actorId,
				resourceType: identity.resourceType, resourceKey: identity.resourceId,
				profileId: profile.id, projectSchema: profile.projectSchema,
				contextEpoch: identity.contextEpoch,
				registryFingerprint: registry.fingerprint, storeRevision: loaded.storeRevision,
			});
			const hostBridge = Object.freeze({
				saveContext: () => {
					if (signal.aborted || adapter.canSave() !== true) throw new Error('SAVE_NOT_READY');
					return context;
				},
				captureSave: () => sessionBridge.captureSave(),
				rebase: (options) => sessionBridge.rebase(options),
				validateAcceptedProject: (project) => sessionBridge.validateAcceptedProject(project),
				isProjectDirty: () => sessionBridge.isProjectDirty(),
			});
			coordinator = createCanonicalSaveCoordinator({host: hostBridge, fields, adapter});
			fieldsOfSave.set(coordinator, fields);
			savesBySignal.set(signal, coordinator);
			return coordinator;
		} catch (error) {
			fields.destroy();
			throw error;
		}
	};
	const readPersistedFields = (coordinator) => {
		const fields = fieldsOfSave.get(coordinator);
		if (!fields) throw new Error('SAVE_NOT_READY');
		return fields.snapshot().fields;
	};
	const updatePersistedField = (coordinator, key, value) => {
		const fields = fieldsOfSave.get(coordinator);
		if (!fields || coordinator.status().blocked) throw new Error('SAVE_PENDING_CONTEXT_BLOCKED');
		return fields.set(key, value);
	};
	const connectSessionBridge = ({initialProject, context, profile, schemaSnapshot, signal}) => {
		if (signal?.aborted) throw new Error('HOST_CONTEXT_REFUSED');
		const key = JSON.stringify([profile.id, context]);
		const registry = activeRegistries.get(key);
		const module = registry?.moduleNamespace(profile.moduleId);
		if (!module) throw new Error('HOST_MODULE_MISSING');
		return createPlatformSessionBridge({
			initialProject, context, profile, profileModule: module, schemaSnapshot,
		});
	};
	let constructing = true;
	let closed = false;
	let inFlightUnmount = null;
	let host;
	try {
		// Exact Luna 3 createPlatformHost factory signature. The Host supplies
		// its canonical readonly EditorFacade and an OFFSCREEN candidate root;
		// never attach a staged session to the active, leased Base shell.
		const createShell = ({ root: stagedRoot, editor, context, signal } = {}) => {
			if (constructing || closed || inFlightUnmount) throw new Error('HOST_CONTEXT_REFUSED');
			if (!(stagedRoot instanceof Element)
				|| stagedRoot === root
				|| stagedRoot.ownerDocument !== root.ownerDocument
				|| !stagedRoot.matches('[data-cb-platform-canvas]')
				|| stagedRoot.isConnected
				|| root.contains(stagedRoot)) {
				throw new Error('HOST_CONTEXT_REFUSED');
			}
			if (!context || typeof context !== 'object'
				|| !signal || signal.aborted !== false) {
				throw new Error('HOST_CONTEXT_REFUSED');
			}
			// No SessionBridge/History/Save proxy: shell controls consume the
			// ONE EditorFacade from the Host (never the private session).
			const requiredEditorMethods = [
				'project', 'snapshot', 'persistedFields', 'setPersistedField', 'selection', 'select',
				'execute', 'executeBatch', 'undo', 'redo', 'historyStatus',
				'subscribe', 'save',
			];
			if (!editor || requiredEditorMethods.some((name) => typeof editor[name] !== 'function')) {
				throw new Error('HOST_CAPABILITY_UNAVAILABLE');
			}
			// Luna 3 currently supplies an empty staging canvas. Real Base
			// chrome must be composed there in the later joint integration;
			// accepting an empty shell would falsely claim UI readiness.
			const saveAuthority = savesBySignal.get(signal);
			if (!saveAuthority) throw new Error('SAVE_NOT_READY');
			const chrome = composePlatformChrome(stagedRoot);
			chrome.save.disabled = true;
			const syncSaveUI = () => {
				if (signal.aborted || host?.state !== 'READY' || !stagedRoot.isConnected) return;
				const status = saveAuthority.status();
				const state = status?.state;
				const uncertain = state === 'UNKNOWN';
				const unsynchronized = state === 'PERSISTED_UNSYNCHRONIZED';
				const working = ['CHECKPOINTED', 'SUBMITTING', 'RECEIPT_VERIFIED', 'RECONCILING'].includes(state);
				chrome.save.textContent = uncertain ? 'Verify save status'
					: working ? 'Saving…' : status?.dirty ? 'Save' : 'Saved';
				chrome.save.disabled = working || unsynchronized || (!uncertain && status?.dirty !== true);
				chrome.notice.hidden = !(uncertain || unsynchronized);
				chrome.notice.textContent = uncertain
					? 'Save outcome unknown. Verify the original operation before continuing.'
					: unsynchronized
						? 'Changes were persisted, but the editor history could not be reconciled. Keep this Designer open.'
						: '';
			};
			const unsubscribeSaveStatus = saveAuthority.subscribe(syncSaveUI);
			signal.addEventListener('abort', unsubscribeSaveStatus, {once: true});
			chrome.save.addEventListener('click', () => {
				if (signal.aborted || host?.state !== 'READY' || chrome.save.disabled) return;
				chrome.save.disabled = true;
				Promise.resolve().then(() => editor.save()).catch(() => {
					// Only the SaveCoordinator may determine UNKNOWN. An exception
					// before transport does not prove any particular write outcome.
					stagedRoot.dispatchEvent(new CustomEvent('cb:design-shell:failure', {
						bubbles: true, detail: Object.freeze({phase: 'save'}),
					}));
				}).finally(syncSaveUI);
			}, {signal});
			stagedSaveUI.set(stagedRoot, Object.freeze({syncSaveUI, signal}));
			// The outer shell root retains its exclusive lease throughout
			// staging/switching. The detached stage is independently guarded
			// against duplicate controllers by createDesignerShell().
			const controller = createDesignerShell(stagedRoot, {
				session: editor,
				platformStaged: true,
				platformSignal: signal,
			});
			const layersPanel = stagedRoot.querySelector('[data-cb-platform-panel="layers"]');
			const inspectorPanel = stagedRoot.querySelector('[data-cb-platform-panel="inspector"]');
			const elementsPanel = stagedRoot.querySelector('[data-cb-platform-panel="elements"]');
			if (!layersPanel || !inspectorPanel || !elementsPanel) throw new Error('HOST_CAPABILITY_UNAVAILABLE');
			const tree = createDesignerLayerTree({
				documentRef: stagedRoot.ownerDocument,
				ariaLabel: 'Design layers',
				onSelectItem: (ref) => {
					if (!signal.aborted && host.state === 'READY') editor.select([ref], ref);
				},
			});
			layersPanel.append(tree.element);
			const activeRegistry = registriesByIdentity.get(JSON.stringify(context));
			const profileModule = activeRegistry?.moduleNamespace(activeRegistry.profile?.moduleId);
			if (!activeRegistry || typeof profileModule?.componentsFor !== 'function'
				|| typeof profileModule?.propertiesFor !== 'function'
				|| typeof profileModule?.resolveCommand !== 'function') {
				throw new Error('HOST_MODULE_MISSING');
			}
			const declared = new Set(activeRegistry.components.map((entry) => entry.id));
			const components = profileModule.componentsFor();
			if (!Array.isArray(components) || components.length !== declared.size
				|| components.some((entry) => !declared.has(entry.id)
					|| typeof entry.type !== 'string' || typeof entry.label !== 'string')) {
				throw new Error('REGISTRY_INVALID');
			}
			const prefix = activeRegistry.profileId === 'base/mail' ? 'mail' : 'reports';
			for (const component of components) {
				const button = stagedRoot.ownerDocument.createElement('button');
				button.type = 'button';
				button.className = 'button cb-core-design-shell__palette-item';
				button.textContent = component.label;
				button.dataset.cbDesignComponentId = component.id;
				button.addEventListener('click', () => {
					if (signal.aborted || host.state !== 'READY') return;
					const project = editor.project();
					const primary = editor.selection()?.primary;
					let parentPath = primary?.kind === 'node' ? [...primary.path] : [];
					let parent = nodeAt(project.root, parentPath);
					if (!parent || !['mail.section','reports.section',project.design_type].includes(parent.type)) {
						parentPath = parentPath.slice(0, -1);
						parent = nodeAt(project.root, parentPath);
					}
					if (!parent) throw new Error('SELECTION_INVALID');
					editor.execute(prefix + '.insert', {
						parentPath, index: parent.children.length, type: component.type,
					});
				}, {signal});
				elementsPanel.append(button);
			}
			const settingsPanel = stagedRoot.querySelector('[data-cb-platform-panel="settings"]');
			if (!settingsPanel) throw new Error('HOST_CAPABILITY_UNAVAILABLE');
			const settingsControls = new Map();
			const refreshSettings = () => {
				const fields = editor.persistedFields();
				for (const [key,value] of Object.entries(fields)) {
					if (typeof value !== 'string') throw new Error('SCHEMA_DOMAIN_INVALID');
					let control = settingsControls.get(key);
					if (!control) {
						control = createDesignerInspectorTextControl({
							key: 'persisted.' + key, label: key, value,
							documentRef: stagedRoot.ownerDocument,
							onCommit: (next) => {
								if (!signal.aborted && host.state === 'READY') editor.setPersistedField(key, next);
							},
						});
						settingsControls.set(key, control);
						settingsPanel.append(control.element);
					} else control.update({value});
				}
			};
			let inspectorSelectionKey = null;
			const inspectorControls = new Map();
			const clearInspector = () => {
				for (const control of inspectorControls.values()) control.destroy();
				inspectorControls.clear();
				inspectorPanel.replaceChildren();
			};
			const renderInteraction = ({project, selection, signal: renderSignal}) => {
				if (renderSignal?.aborted || signal.aborted || !project?.root) return;
				const selected = new Set((selection?.refs || [])
					.filter((ref) => ref?.kind === 'node').map((ref) => JSON.stringify(ref.path)));
				const walk = (node, path) => {
					if (!node || !Array.isArray(node.children)) throw new Error('SCHEMA_DOMAIN_INVALID');
					return {
						key: JSON.stringify(path),
						path,
						ref: Object.freeze({kind: 'node', path: Object.freeze([...path])}),
						label: node.type,
						meta: node.provider,
						selected: selected.has(JSON.stringify(path)),
						children: node.children.map((child, i) => walk(child, [...path, i])),
					};
				};
				tree.render([walk(project.root, [])]);
				const primary = selection?.primary;
				const key = primary?.kind === 'node' ? JSON.stringify(primary.path) : null;
				if (key !== inspectorSelectionKey) {
					clearInspector();
					inspectorSelectionKey = key;
				}
				if (key !== null) {
					const node = nodeAt(project.root, primary.path);
					if (!node) throw new Error('SELECTION_INVALID');
					if (!inspectorControls.size) {
						const identity = createDesignerInspectorIdentity({
							label: node.type, eyebrow: node.provider,
							context: 'Selected element', documentRef: stagedRoot.ownerDocument,
						});
						inspectorPanel.append(identity.element);
						for (const field of profileModule.propertiesFor(node)) {
							const control = createDesignerInspectorTextControl({
								key: 'node.' + field.key, label: field.label,
								value: field.value, multiline: field.multiline,
								documentRef: stagedRoot.ownerDocument,
								onCommit: (value) => {
									if (!signal.aborted && host.state === 'READY') {
										editor.execute(prefix + '.set', {
										path: [...primary.path], key: field.key, value,
										});
									}
								},
							});
							inspectorControls.set(field.key, control);
							inspectorPanel.append(control.element);
						}
					} else {
						for (const field of profileModule.propertiesFor(node)) {
							inspectorControls.get(field.key)?.update({value:field.value});
						}
					}
				}
				refreshSettings();
			};
			stagedShells.set(stagedRoot, controller);
			return Object.freeze({
				destroy: () => controller.destroy(),
				activatePlatformSession: () => controller.activatePlatformSession(),
				renderInteraction,
			});
		};
		host = createHost(Object.freeze({
			root, canvasRoot, resolveRegistryView: registryFor,
			createSessionBridge: connectSessionBridge,
			createSaveCoordinator: connectSaveCoordinator,
			readPersistedFields,
			updatePersistedField,
			createShell,
		}));
		if (!host || host.root !== root
			|| typeof host.mount !== 'function'
			|| typeof host.switchContext !== 'function'
			|| typeof host.refresh !== 'function'
			|| typeof host.unmount !== 'function') {
			throw new TypeError('Designer Host does not satisfy the A1 lifecycle interface.');
		}
	} catch (error) {
		lease.release(); // Construction was forbidden from creating a shell.
		throw error;
	} finally {
		constructing = false;
	}

	const assertActive = () => {
		if (closed || inFlightUnmount) throw new Error('HOST_CONTEXT_REFUSED');
	};
	const publishCommitted = () => {
		if (host.state !== 'READY') throw new Error('HOST_CONTEXT_REFUSED');
		const canvas = canvasRoot.firstElementChild;
		const shell = canvas ? stagedShells.get(canvas) : null;
		// Host commit already activated the shell before exposing READY.
		if (!shell || canvas?.dataset?.cbDesignShellInitialized !== 'true') {
			throw new Error('HOST_CAPABILITY_UNAVAILABLE');
		}
		const saveUI = stagedSaveUI.get(canvas);
		if (!saveUI || saveUI.signal.aborted) throw new Error('SAVE_NOT_READY');
		// Edit-state notifications refresh the button without altering Save
		// authority, while aborted Host contexts drop their subscriptions.
		const unsubscribe = host.editor.subscribe(saveUI.syncSaveUI);
		if (typeof unsubscribe !== 'function') throw new Error('HOST_CAPABILITY_UNAVAILABLE');
		saveUI.signal.addEventListener('abort', unsubscribe, {once: true});
		saveUI.syncSaveUI();
	};

	return Object.freeze({
		get root() { return root; },
		get state() { return host.state; },
		get profileId() { return host.profileId; },
		get context() { return host.context; },
		get editor() { return host.editor; },
		async mount(initialContext) {
			assertActive();
			const result = await host.mount(initialContext);
			publishCommitted();
			return result;
		},
		async switchContext(nextContext, options) {
			assertActive();
			const result = await host.switchContext(nextContext, options);
			publishCommitted();
			return result;
		},
		refresh(reason) {
			assertActive();
			return host.refresh(reason);
		},
		async unmount() {
			if (closed) return true;
			if (inFlightUnmount) return inFlightUnmount;
			// Host MUST refuse unsafe Save states, abort work, then destroy its
			// shell through the existing Exit Guard before relinquishing root.
			inFlightUnmount = (async () => {
				const outcome = await host.unmount();
				if (outcome === false) return false;
				if (!lease.release()) throw new Error('HOST_CLEANUP_FAILED');
				closed = true;
				return true;
			})();
			try {
				return await inFlightUnmount;
			} finally {
				inFlightUnmount = null;
			}
		},
	});
};

export const profiles = PROFILE_APIS;
export const commands = Object.freeze({
	insertNode: insertNodeCommand,
	removeNode: removeNodeCommand,
	reorderNode: reorderNodeCommand,
	setProperty: setPropertyCommand,
});
export {
	CommandHistory,
	DESIGNER_ICON_NAMES,
	DESIGNER_LAYER_ACTIONS,
	DESIGNER_MOTION_DEFAULTS,
	DESIGNER_MOTION_KEY_ATTRIBUTE,
	DESIGNER_PALETTE_ROLES,
	DESIGNER_SIDEBAR_ROLES,
	DESIGNER_VIEWPORT_ORDER,
	EditorState,
	ProjectState,
	animateLayoutChange,
	configureDesignerSidebar,
	configureDesignerViewports,
	createDesignerIcon,
	createDesignerInspectorControls,
	createDesignerInspectorTextControl,
	createDesignerInspectorIdentity,
	createDesignerInspectorSection,
	createDesignerInspectorToggle,
	enableDesignerTextareaAutosize,
	createDesignerLayerRow,
	createDesignerLayerTree,
	createDesignerNavigation,
	createDesignerSelectionController,
	createDesignerShell,
	decorateDesignerControl,
	decorateDesignerElement,
	insertNodeCommand,
	removeNodeCommand,
	reorderNodeCommand,
	setPropertyCommand,
};

const motion = Object.freeze({
	animateLayoutChange,
	defaults: DESIGNER_MOTION_DEFAULTS,
	keyAttribute: DESIGNER_MOTION_KEY_ATTRIBUTE,
});

const publicApi = Object.freeze({
	createSession,
	createDomainSession,
	profiles,
	motion,
	shell: Object.freeze({
		create: createDesignerShell,
		configureSidebar: configureDesignerSidebar,
		configureViewports: configureDesignerViewports,
		sidebarRoles: DESIGNER_SIDEBAR_ROLES,
		viewportOrder: DESIGNER_VIEWPORT_ORDER,
		paletteRoles: DESIGNER_PALETTE_ROLES,
		navigation: Object.freeze({ create: createDesignerNavigation }),
		icons: Object.freeze({
			names: DESIGNER_ICON_NAMES,
			create: createDesignerIcon,
			decorate: decorateDesignerControl,
			decorateElement: decorateDesignerElement,
		}),
		inspector: Object.freeze({
			createIdentity: createDesignerInspectorIdentity,
			createControls: createDesignerInspectorControls,
			createSection: createDesignerInspectorSection,
			createToggle: createDesignerInspectorToggle,
			autosizeTextarea: enableDesignerTextareaAutosize,
		}),
		layers: Object.freeze({
			actions: DESIGNER_LAYER_ACTIONS,
			createRow: createDesignerLayerRow,
			createTree: createDesignerLayerTree,
		}),
		selection: Object.freeze({
			createController: createDesignerSelectionController,
		}),
	}),
	commands,
});

if (typeof window !== 'undefined') {
	window.cbCore = window.cbCore || {};
	window.cbCore.designEditor = publicApi;
	if (typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
		window.dispatchEvent(new CustomEvent('cb:design-editor:ready', {
			detail: Object.freeze({ api: publicApi }),
		}));
	}
}
