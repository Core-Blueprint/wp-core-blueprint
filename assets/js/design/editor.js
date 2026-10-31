import { bindRegisteredCommand, bindRegisteredBatch } from './core/commands.js';
import { validateStableProjectV1 } from './core/stable-schema.js';
import { sameValue } from './core/value.js';
import { createPlatformHost } from './platform/host.js';
import { createRegistryView } from './platform/registry.js';
import { createPersistedFields, canonicalSaveJSON } from './platform/persisted-fields.js';
import { createSaveCoordinator as createCanonicalSaveCoordinator } from './platform/save-coordinator.js';
import { createDesignerDragDropController } from './shell/drag-drop.js';
import { createDesignerInteractionPresentation } from './shell/interaction-presentation.js';
import { createDesignerCanvasNavigation } from './shell/canvas-navigation.js';
import { createDesignerContextMenu } from './shell/context-menu.js';
import { createDesignerElementsPalette } from './shell/elements-palette.js';
import { designerDropZoneId, resolveDesignerDropPlacement, resolveDesignerInsertPlacement, resolveDesignerMovePlacement } from './shell/drop-placement.js';
import {
	CommandHistory,
	EditorState,
	ProjectState,
	buildInspectorContext,
	handleEditorShortcut,
	insertNodeCommand,
	moveNodeCommand,
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
	createDesignerInspectorValueControl,
	createDesignerInspectorIdentity,
	createDesignerInspectorSection,
	createDesignerInspectorToggle,
	enableDesignerTextareaAutosize,
	createDesignerLayerRow,
	createDesignerLayerTree,
	projectDesignerLayerInteractions,
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
import { configureDesignerDocumentView } from './shell/document-view.js';

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
		discardToBaseline() {
			assertActive();
			return history.discardToBaseline();
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
	resolveSelectionEntity = null,
	historyLimit = 100,
} = {}) => {
	for (const [name, candidate] of Object.entries({ validateProject, allowCommand, onChange, verifyRebase, resolveSelectionEntity })) {
		if (candidate !== null && typeof candidate !== 'function') {
			throw new TypeError('Domain session ' + name + ' must be a function or null.');
		}
	}
	const projectState = new ProjectState(project, { validate: validateProject });
	const editorState = new EditorState();
	const history = new CommandHistory(projectState, editorState, { limit: historyLimit, verifyRebase });
	editorState.selection.configureDomain({ resolveEntity: resolveSelectionEntity, contextEpoch: history.contextEpoch });
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
		selection() { assertActive(); return editorState.selection.typedSnapshot(); },
		select(refs, primary = null, { expectedEpoch, source = 'ui' } = {}) {
			assertActive();
			// Resolver and observer callbacks execute inside the same guarded
			// Selection mutation. Nested writes are denied before publication.
			return history.guardPolicy(() => editorState.selection.setDomainRefs(refs, primary, {
				project: projectState.current(), contextEpoch: expectedEpoch, source,
			}));
		},
		subscribeSelection(listener, { emitCurrent = false } = {}) {
			assertActive();
			if (typeof listener !== 'function') throw new TypeError('Domain Selection listener must be a function.');
			return editorState.selection.subscribe(
				(event) => listener(editorState.selection.typedSnapshot(), Object.freeze({
					event, projectRevision: projectState.revision,
				})),
				{ emitCurrent },
			);
		},
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
		discardToBaseline() { assertActive(); return history.discardToBaseline(); },
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
		// Native v1 is validated by ProjectState against the SAME verified
		// server Registry snapshot already used by the Host and domain validator.
		stableSchema: Object.freeze({
			profileId: profile.id,
			projectSchema: profile.projectSchema,
			registrySnapshot: schemaSnapshot,
		}),
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
		// Host-private coordinated project/fields restoration.
		discardToBaseline(options = {}) { active(); return history.discardToBaseline(options); },
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
/**
 * Presentation-only label resolver for every native Designer profile.
 * Verified Registry keys and Save/Command authority remain unchanged.
 */
const platformFieldLabel = (key, fallback = '') => {
	const source = String(key ?? '');
	const leaf = source.split('.').at(-1);
	const dictionary = typeof window !== 'undefined' ? window.cbCorePlatformV1?.labels : null;
	const localized = dictionary && typeof dictionary === 'object'
		? (dictionary[source] ?? dictionary[leaf]) : null;
	if (typeof localized === 'string' && localized.trim()) return localized;
	const raw = typeof fallback === 'string' && fallback.trim() && fallback !== source
		? fallback : leaf;
	return raw.replace(/([a-z\d])([A-Z])/g, '$1 $2')
		.replace(/[_-]+/g, ' ').replace(/\bUrl\b/gi, 'URL')
		.replace(/\bId\b/gi, 'ID')
		.replace(/^./, letter => letter.toUpperCase());
};

const composePlatformChrome = (root, profileId) => {
	if (root.childNodes.length || root.isConnected) throw new Error('HOST_CONTEXT_REFUSED');
	const doc = root.ownerDocument;
	root.classList.add('cb-core-design-shell', 'cb-core-design-shell--native');
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
	const toolbar = node('div', 'cb-core-design-shell__toolbar cb-core-design-shell__toolbar--designer', {
		role: 'toolbar', 'aria-label': 'Designer actions',
	});
	const startZone = node('div', 'cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--start');
	const centerZone = node('div', 'cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--center');
	const endZone = node('div', 'cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--end');
	// Native Mail/Reports reuse the same Base brand grammar as Designer Mode:
	// canonical gradient mark, locally bundled Miriam Libre, and blue word dot.
	const designerTitle = ({
		'base/mail': 'Mail Designer',
		'base/reports': 'Reports Designer',
	})[profileId];
	if (!designerTitle) throw new Error('HOST_CAPABILITY_UNAVAILABLE');
	const brand = node('div', 'cb-core-design-shell__brand cb-core-brand-lockup', {
		'aria-label': designerTitle + ', Core Blueprint',
	});
	brand.append(node('span', 'cb-core-brand-lockup__mark cb-core-design-shell__brand-mark', {
		'aria-hidden': 'true',
	}));
	const wordmark = node('span', 'cb-core-brand-lockup__wordmark cb-core-design-shell__brand-wordmark', {
		'aria-hidden': 'true',
	});
	for (const [index, word] of designerTitle.trim().split(/\s+/).entries()) {
		if (index > 0) wordmark.append(node('span', 'cb-core-brand-lockup__dot', {}, '.'));
		wordmark.append(node('span', 'cb-core-design-shell__brand-word', {}, word.toLocaleLowerCase()));
	}
	brand.append(wordmark);
	const contextSlot = node('span', 'cb-core-design-shell__native-context', {
		'data-cb-platform-context-slot': '',
	});
	startZone.append(brand, contextSlot);
	if (profileId === 'base/mail') {
		const viewport = node('div', 'cb-core-design-shell__toolbar-group', {
			'data-cb-design-shell-viewport-group': '', role: 'group', 'aria-label': 'Preview width',
		});
		for (const [id, label] of [['mobile','Mobile'], ['tablet','Tablet'], ['desktop','Desktop']]) {
			viewport.append(node('button', 'button cb-core-button', {
				type: 'button', 'data-cb-design-shell-viewport': id,
				'aria-pressed': id === 'desktop' ? 'true' : 'false',
			}, label));
		}
		centerZone.append(viewport);
	} else {
		const documentGroup = node('div', 'cb-core-design-shell__toolbar-group', {
			role: 'group', 'aria-label': 'Document view',
		});
		const documentButton = button('Document view', 'data-cb-design-shell-document-view-toggle');
		decorateDesignerControl(documentButton, 'file-text', {
			iconOnly: true, label: 'Switch to fixed document width',
		});
		documentButton.setAttribute('aria-pressed', 'true');
		documentGroup.append(documentButton);
		centerZone.append(documentGroup);
	}
	const history = node('div', 'cb-core-design-shell__toolbar-group', {
		role: 'group', 'aria-label': 'History',
	});
	const undo = button('Undo', 'data-cb-design-shell-undo');
	const redo = button('Redo', 'data-cb-design-shell-redo');
	undo.disabled = true;
	redo.disabled = true;
	decorateDesignerControl(undo, 'undo-2', {iconOnly: true, label: 'Undo'});
	decorateDesignerControl(redo, 'redo-2', {iconOnly: true, label: 'Redo'});
	history.append(undo, redo);
	const save = button('Save', 'data-cb-design-shell-primary-action');
	save.classList.add('button-primary', 'cb-core-button--primary');
	decorateDesignerControl(save, 'save', {iconOnly: true, label: 'Save'});
	const closeSlot = node('span', 'cb-core-design-shell__native-close', {
		'data-cb-platform-close-slot': '',
	});
	endZone.append(history, closeSlot, save);
	toolbar.append(startZone, centerZone, endZone);
	const notice = node('p', 'cb-core-design-shell__persistent-notice', {
		'data-cb-design-shell-persistent-notice': '', role: 'status', 'aria-live': 'polite',
	});
	notice.hidden = true;
	const workspace = node('div', 'cb-core-design-shell__workspace cb-core-design-shell__workspace--collapsible');
	const palette = node('section', 'cb-core-design-shell__palette cb-core-design-shell__palette--composed cb-core-design-shell__palette--tabbed', {
		'aria-label': 'Elements and layers',
	});
	const canvas = node('main', 'cb-core-design-shell__canvas cb-core-design-shell__canvas--composed', {'aria-label': 'Design canvas'});
	const surface = node('div', 'cb-core-design-shell__canvas-workarea', {'data-cb-platform-render-surface': ''});
	canvas.append(surface);
	const sidebar = node('aside', 'cb-core-design-shell__sidebar cb-core-design-shell__sidebar--composed', {'aria-label': 'Inspector and settings'});
	const panelHeader = (side, label) => {
		const heading = node('span', 'cb-core-design-shell__panel-heading', {'aria-hidden': 'true'}, label);
		const toggle = button(side === 'left' ? 'Collapse elements' : 'Collapse inspector', 'data-cb-design-shell-panel-toggle', side);
		toggle.className = 'cb-core-design-shell__panel-toggle cb-core-design-shell__panel-toggle--' + side;
		const header = node('div', 'cb-core-design-shell__panel-header cb-core-design-shell__panel-header--' + side);
		header.append(heading, toggle);
		return header;
	};
	palette.append(panelHeader('left', 'Content'));
	sidebar.append(panelHeader('right', 'Properties'));
	const tabs = (container, group, definitions) => {
		const tablist = node('div', 'cb-core-design-shell__tabs', {role: 'tablist'});
		for (const [id, label] of definitions) {
			const attrs = {'data-cb-design-shell-tab': id, role: 'tab', 'aria-selected': 'false'};
			if (group) {
				attrs['data-cb-design-shell-group'] = group;
				attrs['data-cb-design-shell-palette-role'] = id;
			}
			tablist.append(node('button', 'cb-core-design-shell__tab', {...attrs, type: 'button'}, label));
		}
		container.append(tablist);
		for (const [id] of definitions) {
			const attrs = {'data-cb-design-shell-panel': id};
			if (group) attrs['data-cb-design-shell-group'] = group;
			const panel = node('div', 'cb-core-design-shell__panel cb-core-design-shell__sidebar-panel', attrs);
			panel.append(node('div', 'cb-core-design-shell__panel-body', {'data-cb-platform-panel': id}));
			container.append(panel);
		}
	};
	tabs(palette, 'palette', [['elements','Elements'],['layers','Layers']]);
	tabs(sidebar, null, [['inspector','Inspector'],['settings','Settings']]);
	const backdrop = button('Close panel', 'data-cb-design-shell-drawer-backdrop');
	backdrop.className = 'cb-core-design-shell__drawer-backdrop';
	backdrop.setAttribute('aria-label', 'Close sidebar');
	backdrop.hidden = true;
	workspace.append(palette, canvas, sidebar, backdrop);
	const leftLauncher = button('Elements', 'data-cb-design-shell-drawer-toggle', 'left');
	const rightLauncher = button('Inspector', 'data-cb-design-shell-drawer-toggle', 'right');
	leftLauncher.classList.add('cb-core-design-shell__drawer-launcher');
	rightLauncher.classList.add('cb-core-design-shell__drawer-launcher');
	decorateDesignerControl(leftLauncher, 'shapes', {iconOnly: true, label: 'Open Elements and Layers'});
	decorateDesignerControl(rightLauncher, 'sliders-horizontal', {iconOnly: true, label: 'Open Inspector and Settings'});
	startZone.append(leftLauncher);
	endZone.prepend(rightLauncher);
	root.append(toolbar, notice, workspace);
	return {save, notice, undo, redo, surface};
};

/**
 * Condense the native identity when its actual start-zone capacity is used up.
 * Keep the full accessible Designer label on the brand, including in icon mode.
 * This runs only for the active staged Host shell and uses its AbortSignal.
 */
const configurePlatformBrand = (root, signal) => {
	const toolbar = root.querySelector('.cb-core-design-shell__toolbar--designer');
	const start = toolbar?.querySelector('.cb-core-design-shell__toolbar-zone--start');
	const view = root.ownerDocument.defaultView;
	if (!toolbar || !start || !view || !signal) return;
	const sync = () => {
		if (signal.aborted || !root.isConnected) return;
		toolbar.classList.remove('is-identity-condensed');
		const available = start.clientWidth;
		const center = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--center');
		const end = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--end');
		const gap = parseFloat(view.getComputedStyle(toolbar).columnGap) || 8;
		const startRect = start.getBoundingClientRect();
		const centerRect = center?.getBoundingClientRect();
		const endRect = end?.getBoundingClientRect();
		// The start zone can be intrinsically wider than its grid track:
		// measuring scrollWidth alone would miss overlap with Center / Save.
		const collision = startRect.width > 0 && Boolean(
			(centerRect?.width > 0 && startRect.right + gap > centerRect.left + 1)
			|| (endRect?.width > 0 && startRect.right + gap > endRect.left + 1)
		);
		toolbar.classList.toggle('is-identity-condensed',
			available > 0 && (start.scrollWidth > available + 1 || collision));
	};
	const resize = typeof view.ResizeObserver === 'function'
		? new view.ResizeObserver(sync) : null;
	resize?.observe(toolbar);
	resize?.observe(start);
	const mutation = typeof view.MutationObserver === 'function'
		? new view.MutationObserver(sync) : null;
	mutation?.observe(start, {childList: true, subtree: true, characterData: true});
	view.addEventListener('resize', sync, {signal, passive: true});
	view.document.fonts?.ready?.then(() => { if (!signal.aborted) sync(); });
	signal.addEventListener('abort', () => {
		resize?.disconnect();
		mutation?.disconnect();
	}, {once: true});
	sync();
};

/**
 * Reuse the existing Base Designer Mode rail/drawer CSS and Shell tab controller.
 * The Host's AbortSignal owns every event listener: a context switch cannot
 * strand listeners or a second drawer over the next detached stage.
 */
const configurePlatformSidebars = (root, signal) => {
	const doc = root.ownerDocument;
	const view = doc.defaultView;
	const media = view?.matchMedia?.('(max-width: 1280px)') || null;
	const workspace = root.querySelector('.cb-core-design-shell__workspace--collapsible');
	const backdrop = workspace?.querySelector('[data-cb-design-shell-drawer-backdrop]');
	const rails = ['left','right'].map((side) => {
		const panel = workspace?.querySelector(side === 'left' ? '.cb-core-design-shell__palette' : '.cb-core-design-shell__sidebar');
		const toggle = panel?.querySelector('[data-cb-design-shell-panel-toggle]');
		const launcher = root.querySelector('[data-cb-design-shell-drawer-toggle="' + side + '"]');
		return {side, panel, toggle, launcher, className: side === 'left' ? 'is-left-panel-collapsed' : 'is-right-panel-collapsed'};
	});
	if (!workspace || !backdrop || rails.some((record) => !record.panel || !record.toggle || !record.launcher)) {
		throw new Error('HOST_CAPABILITY_UNAVAILABLE');
	}
	let compact = false;
	const collapsed = (record) => root.classList.contains(record.className);
	const sync = () => {
		const anyOpen = compact && rails.some((record) => !collapsed(record));
		root.classList.toggle('is-responsive-drawer-mode', compact);
		root.classList.toggle('has-responsive-drawer-open', anyOpen);
		backdrop.hidden = !anyOpen;
		for (const record of rails) {
			const isClosed = collapsed(record);
			record.toggle.setAttribute('aria-expanded', isClosed ? 'false' : 'true');
			record.launcher.setAttribute('aria-expanded', isClosed ? 'false' : 'true');
			decorateDesignerControl(record.toggle,
				record.side === 'left' ? (isClosed ? 'chevron-right' : 'chevron-left')
					: (isClosed ? 'chevron-left' : 'chevron-right'),
				{iconOnly: true, label: (isClosed ? 'Expand ' : 'Collapse ')
					+ (record.side === 'left' ? 'Elements and Layers' : 'Inspector and Settings')});
			if (compact) {
				record.panel.setAttribute('aria-hidden', isClosed ? 'true' : 'false');
				if (isClosed) {
					record.panel.setAttribute('inert', '');
					record.panel.removeAttribute('role');
					record.panel.removeAttribute('aria-modal');
				} else {
					record.panel.removeAttribute('inert');
					record.panel.setAttribute('role', 'dialog');
					record.panel.setAttribute('aria-modal', 'true');
				}
			} else {
				record.panel.removeAttribute('aria-hidden');
				record.panel.removeAttribute('inert');
				record.panel.removeAttribute('role');
				record.panel.removeAttribute('aria-modal');
			}
		}
	};
	const close = (restoreFocus = false) => {
		const open = rails.find((record) => !collapsed(record));
		for (const record of rails) root.classList.add(record.className);
		sync();
		if (restoreFocus) open?.launcher.focus();
	};
	const toggle = (record) => {
		if (compact && collapsed(record)) {
			for (const other of rails) root.classList.add(other.className);
		}
		root.classList.toggle(record.className);
		sync();
		if (compact) (collapsed(record) ? record.launcher : record.toggle).focus();
	};
	for (const record of rails) {
		record.toggle.addEventListener('click', () => toggle(record), {signal});
		record.launcher.addEventListener('click', () => toggle(record), {signal});
	}
	backdrop.addEventListener('click', () => close(true), {signal});
	workspace.addEventListener('keydown', (event) => {
		if (!compact || event.defaultPrevented) return;
		const open = rails.find((record) => !collapsed(record));
		if (!open) return;
		if (event.key === 'Escape') {
			event.preventDefault();
			event.stopPropagation();
			close(true);
		} else if (event.key === 'Tab') {
			// Focus stays inside the mobile drawer; collapse restores launcher focus.
			const tabbables = [...open.panel.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]')]
				.filter((element) => element.getClientRects().length > 0);
			if (!tabbables.length) return;
			const first = tabbables[0], last = tabbables[tabbables.length - 1];
			const active = doc.activeElement;
			if (!open.panel.contains(active) || (event.shiftKey && active === first)) {
				event.preventDefault();
				(event.shiftKey ? last : first).focus();
			} else if (!event.shiftKey && active === last) {
				event.preventDefault();
				first.focus();
			}
		}
	}, {signal});
	const responsive = () => {
		const next = media?.matches === true;
		if (next !== compact) {
			compact = next;
			for (const record of rails) root.classList.toggle(record.className, compact);
		}
		sync();
	};
	media?.addEventListener?.('change', responsive, {signal});
	responsive();
	return Object.freeze({close});
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
	notifySave = null,
} = {}) => {
	if (!(root instanceof Element) || !root.matches('[data-cb-design-shell]')) {
		throw new TypeError('Designer Platform requires a canonical shell root.');
	}
	if (typeof createHost !== 'function' || typeof createSaveAdapter !== 'function'
		|| typeof loadReceipt !== 'function'
		|| !(runtimeModules instanceof Map) || !canvasRoot || !root.contains(canvasRoot)) {
		throw new TypeError('Designer Platform requires a statically imported Host factory.');
	}
	if (notifySave !== null && typeof notifySave !== 'function') {
		throw new TypeError('HOST_CAPABILITY_UNAVAILABLE');
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
				discardToBaseline: (options) => sessionBridge.discardToBaseline(options),
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
				'execute', 'executeBatch', 'undo', 'redo', 'historyStatus', 'saveStatus',
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
			const activeRegistry = registriesByIdentity.get(JSON.stringify(context));
			if (!activeRegistry) throw new Error('REGISTRY_INVALID');
			const chrome = composePlatformChrome(stagedRoot, activeRegistry.profileId);
			chrome.save.disabled = true;
			const syncSaveUI = () => {
				if (signal.aborted || host?.state !== 'READY' || !stagedRoot.isConnected) return;
				const status = saveAuthority.status();
				const state = status?.state;
				const uncertain = state === 'UNKNOWN';
				const unsynchronized = state === 'PERSISTED_UNSYNCHRONIZED';
				const working = ['CHECKPOINTED', 'SUBMITTING', 'RECEIPT_VERIFIED', 'RECONCILING'].includes(state);
				const saveLabel = uncertain ? 'Verify save status'
					: working ? 'Saving…' : status?.dirty ? 'Save' : 'Saved';
				// Icon-only visual control; retain the real Save Authority status in
				// its accessible name and native tooltip on every state transition.
				decorateDesignerControl(chrome.save, 'save', {iconOnly: true, label: saveLabel});
				chrome.save.disabled = working || unsynchronized || (!uncertain && status?.dirty !== true);
				// Base Toast is the primary feedback channel for this native
				// Host. The inline notice is a safety fallback if the toast
				// runtime is missing, never a second routine notification.
				chrome.notice.hidden = !!notifySave || !(uncertain || unsynchronized);
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
				Promise.resolve().then(() => editor.save()).then((result) => {
					if (signal.aborted || host?.state !== 'READY') return;
					const outcome = ['committed', 'refused', 'conflict', 'idempotency_reused',
						'unknown', 'persisted-unsynchronized'].includes(result?.status)
						? result.status : 'unknown';
					const saveState = outcome === 'committed' ? 'saved'
						: outcome === 'unknown' || outcome === 'persisted-unsynchronized'
							? 'unknown' : 'error';
					// Compatibility signal for consumers of the public Designer
					// notification contract. The native adapter presents exactly
					// one Base toast; this event is not a persistence receipt.
					root.dispatchEvent(new CustomEvent('cb:design-shell:savechange', {
						bubbles: true,
						detail: Object.freeze({state: saveState, nativeHandled: notifySave !== null}),
					}));
					try { notifySave?.(Object.freeze({
						outcome, phase: 'save',
						reason: [
							'SAVE_TRANSPORT_UNVERIFIED', 'SAVE_SERVER_UNVERIFIED',
							'SAVE_RECEIPT_INVALID', 'SAVE_RECOVERY_UNVERIFIED',
							'SAVE_TX_STATE_UNVERIFIED', 'SAVE_TX_BEGIN_UNVERIFIED',
							'SAVE_TX_ROLLBACK_UNVERIFIED', 'SAVE_OPERATION_CLAIM_UNVERIFIED',
							'SAVE_TX_COMMIT_UNVERIFIED', 'SAVE_POSTCOMMIT_RECEIPT_UNVERIFIED',
							'SAVE_TX_EXCEPTION_UNVERIFIED', 'SAVE_RECOVERY_PREFLIGHT_UNVERIFIED',
							'SAVE_LEDGER_SCHEMA_UNVERIFIED', 'SAVE_LEDGER_READ_UNVERIFIED',
							'SAVE_LEDGER_STATE_UNVERIFIED', 'SAVE_LEDGER_RECEIPT_INVALID',
							'SAVE_DISPATCH_UNVERIFIED',
							'SAVE_PREFLIGHT_REFUSED', 'SAVE_DOCUMENT_LOAD_REFUSED',
							'SAVE_DOCUMENT_AUTHORIZATION_REFUSED', 'SAVE_DOCUMENT_CREATION_REFUSED',
							'SAVE_DOCUMENT_VALIDATION_REFUSED', 'SAVE_DOCUMENT_STORAGE_REFUSED',
							'SAVE_DOCUMENT_NORMALIZATION_REFUSED', 'SAVE_DOCUMENT_WRITE_REFUSED',
							'SAVE_DOCUMENT_VERIFY_REFUSED', 'SAVE_OPERATION_RECEIPT_REFUSED',
							'SAVE_REFUSED_UNCLASSIFIED',
						].includes(result?.reason) || /^SAVE_STORAGE_(CREATE|UPDATE)_(INVALID|SCHEMA_UNAVAILABLE|QUERY_FAILED|READBACK_UNVERIFIED|READ_UNAVAILABLE|UNCLASSIFIED|DB_[1-9][0-9]{0,4})$/.test(result?.reason ?? '') ? result.reason : null,
					})); }
					catch (_) { /* UI observers cannot change Save Authority. */ }
				}).catch(() => {
					// A failed browser callback or rejection is NOT proof of no-write.
					if (signal.aborted) return;
					try { notifySave?.(Object.freeze({outcome: 'unknown', phase: 'save'})); }
					catch (_) { /* Notification cannot change Save Authority. */ }
				}).finally(syncSaveUI);
			}, {signal});
			stagedSaveUI.set(stagedRoot, Object.freeze({syncSaveUI, signal}));
			// The outer shell root retains its exclusive lease throughout
			// staging/switching. The detached stage is independently guarded
			// against duplicate controllers by createDesignerShell().
			configurePlatformSidebars(stagedRoot, signal);
			configurePlatformBrand(stagedRoot, signal);
			if (activeRegistry.profileId === 'base/mail') {
				configureDesignerViewports(stagedRoot, {active: 'desktop'});
			} else if (activeRegistry.profileId === 'base/reports') {
				configureDesignerDocumentView(stagedRoot, {mode: 'fit', signal});
			}
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
				sharedDragDrop: true,
				onSelectItem: (ref) => {
					if (!signal.aborted && host.state === 'READY') editor.select([ref], ref);
				},
			});
			layersPanel.append(tree.element);
			const profileModule = activeRegistry.moduleNamespace(activeRegistry.profile?.moduleId);
			if (!activeRegistry || typeof profileModule?.componentsFor !== 'function'
				|| typeof profileModule?.propertiesFor !== 'function'
				|| typeof profileModule?.resolveCommand !== 'function'
				|| typeof profileModule?.layerPolicyFor !== 'function') {
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
			// Registered parent relations, not Base-specific component names,
			// determine the only safe insertion path for all product profiles.
			const allowedParentsByType = new Map(
				activeRegistry.schemaSnapshot.nodeTypes.map((item) => [item.id, new Set(item.allowedParents)])
			);
			const propertySchemas = new Map(
				activeRegistry.schemaSnapshot.domainSchemas.map((item) => [item.id, item.schema])
			);
			const nodeTypes = new Map(
				activeRegistry.schemaSnapshot.nodeTypes.map((item) => [item.id, item])
			);
			const fieldDefinitions = activeRegistry.persistedFieldFacet(activeRegistry.profileId).fieldDescriptors;
			const fieldsByKey = new Map(fieldDefinitions.map((item) => [item.saveKey, item]));
			const propertySchemaFor = (node, key) => {
				const schemaId = nodeTypes.get(node.type)?.propertiesSchemaId;
				const schema = propertySchemas.get(schemaId)?.properties?.[key];
				if (!schema || typeof schema !== 'object') throw new Error('REGISTRY_INVALID');
				return schema;
			};
			// Native Mail and Maintenance Reports share the exact public
			// Elements renderer; only the registered profile owns commands.
			const doc = stagedRoot.ownerDocument;
			const mailData = [
				['Site name','site.name'], ['Site URL','site.url'],
				['Admin email','site.admin_email'], ['Display name','user.display_name'],
				['Username','user.login'], ['User email','user.email'],
				['Action URL','action.url'],
			];
			const nativeTokens = activeRegistry.profileId === 'base/mail'
				? mailData.map(([label, key]) => ({
					id: key, label, value: '{{' + key + '}}',
					state: 'interactive', action: 'copy',
				})) : [];
			// Palette state follows the live document and selection. A trusted profile
			// can further restrict insertability (for example Reports singletons).
			// The registered command still enforces all domain rules.
			// Reports has an anchored terminal Footer. A deliberate *after Footer*
			// target is invalid; it cannot be silently clamped before the Footer.
			// An unselected document-root append remains permitted by its domain.
			const forbiddenAfterFooter = (project, zoneId, edge) =>
				activeRegistry.profileId === 'base/reports' && edge === 'after'
				&& project?.root?.children?.at(-1)?.type === 'reports.footer'
				&& zoneId === designerDropZoneId([project.root.children.length - 1]);
			const insertionTarget = (project, type) => {
				const selectedRef = editor.selection()?.primary ?? null;
				if (selectedRef?.kind === 'node' && Array.isArray(selectedRef.path)
					&& selectedRef.path.length === 1
					&& Number.isSafeInteger(selectedRef.path[0]) && selectedRef.path[0] >= 0
					&& forbiddenAfterFooter(project, designerDropZoneId(selectedRef.path), 'after')) return null;
				return resolveDesignerInsertPlacement({
					project, selectedRef, type,
					allowedParentsByType, commandId: prefix + '.insert',
					canInsert: typeof profileModule.canInsert === 'function'
						? profileModule.canInsert : null,
				});
			};

			// One Base DND controller for both native profiles. The renderer
			// supplies semantic node surfaces; no canvas mutation occurs here.
			const dropSurface = chrome.surface;
			const registeredZones = new Map();
			const registeredNodeSources = new Map();
			let lastProject = null, projectToken = 0;
			let interactions = null;
			const dnd = createDesignerDragDropController({
				root: stagedRoot,
				isReady: () => !signal.aborted && host?.state === 'READY',
				getContextKey: () => {
					const current = editor.project();
					if (current !== lastProject) { lastProject = current; projectToken += 1; }
					return ['host', context.siteId, context.actorId, context.resourceType,
						context.resourceId, context.contextEpoch, activeRegistry.profileId,
						'p', projectToken].join(':');
				},
				resolveIntent: ({source, zoneId, edge}) => {
					const current = editor.project();
					if (forbiddenAfterFooter(current, zoneId, edge)) return null;
					if (source.kind === 'node') return resolveDesignerMovePlacement({
						project: current, source, zoneId, edge,
						allowedParentsByType, commandId: prefix + '.move',
					});
					return resolveDesignerDropPlacement({
						project: current, source, zoneId, edge,
						allowedParentsByType, commandId: prefix + '.insert',
						canInsert: typeof profileModule.canInsert === 'function'
							? profileModule.canInsert : null,
					});
				},
				canDrop: ({intent}) => {
					if (signal.aborted || host?.state !== 'READY'
						|| ![prefix + '.insert', prefix + '.move'].includes(intent?.commandId)) return false;
					const registered = profileModule.resolveCommand(intent.commandId);
					try {
						return registered?.canApply(editor.project(), intent.input) === true;
					} catch (_) { return false; }
				},
				commit: ({intent}) => {
					if (signal.aborted || host?.state !== 'READY'
						|| ![prefix + '.insert', prefix + '.move'].includes(intent?.commandId)) {
						throw new Error('HOST_CONTEXT_REFUSED');
					}
					if (intent.commandId === prefix + '.move') {
						if (profileModule.resolveCommand(intent.commandId)
							?.canApply(editor.project(), intent.input) !== true)
							throw new Error('COMMAND_REFUSED');
						editor.execute(intent.commandId, intent.input);
						// Direct canvas drag may start without a preceding selection:
						// preserve Inspector focus on the actual moved node, not its
						// old position. This is transient selection, not a History step.
						const {parentPath, index} = intent.input;
						const path = [...parentPath, index];
						if (nodeAt(editor.project().root, path)) {
							const ref = {kind: 'node', path};
							const selected = editor.selection()?.primary;
							if (selected?.kind !== 'node'
								|| JSON.stringify(selected.path) !== JSON.stringify(path)) {
								editor.select([ref], ref);
							}
						}
						return;
					}
					const {parentPath, index, type} = intent.input;
					const before = editor.project();
					const oldParent = nodeAt(before.root, parentPath);
					if (!oldParent) throw new Error('COMMAND_REFUSED');
					editor.execute(intent.commandId, intent.input);
					const insertedIndex = activeRegistry.profileId === 'base/reports'
						&& parentPath.length === 0
						? Math.min(index, oldParent.children.length - 1) : index;
					const path = [...parentPath, insertedIndex];
					if (nodeAt(editor.project().root, path)?.type === type) {
						const ref = {kind: 'node', path};
						editor.select([ref], ref);
					}
				},
				onStatus: ({status}) => {
					const active = status === 'started';
					stagedRoot.dataset.cbDesignDndActive = active ? 'true' : 'false';
					// The isolated Flow iframe cannot accept parent HTML drags.
					// Its existing sandbox/CSP stays intact. During a drag only,
					// pointer events pass through to the canonical parent canvas.
					for (const iframe of dropSurface.querySelectorAll('iframe[data-cb-core-flow-preview-host]')) {
						iframe.style.pointerEvents = (active || activeRegistry.profileId === 'base/reports') ? 'none' : '';
					}
					// Reports' same-document node regions are already registered as
					// native Base drop-targets via data-cb-design-node-path. Revealing
					// their overlapping proxy zones during dragstart retargets the
					// pointer away from the drag source and can end the browser drag.
					// Keep Mail's existing proxy behavior unchanged.
					const proxyActive = active && activeRegistry.profileId !== 'base/reports';
					for (const proxy of dropSurface.querySelectorAll('[data-cb-design-drop-zone-id]')) {
						proxy.style.pointerEvents = proxyActive ? 'auto' : 'none';
						proxy.style.visibility = proxyActive ? 'visible' : 'hidden';
					}
					interactions?.refresh();
				},
			});
			// Base owns Layers row registrations and their lifecycle.
			tree.bindDragDrop({
				controller: dnd,
				describe: ({item}) => {
					if (!Array.isArray(item.path)) return null;
					const node = nodeAt(editor.project().root, item.path);
					if (!node) return null;
					const zone = {id: designerDropZoneId(item.path)};
					if (!item.path.length
						|| (activeRegistry.profileId === 'base/mail' && node.type === 'mail.column')
						|| (activeRegistry.profileId === 'base/reports'
							&& ['reports.header', 'reports.footer'].includes(node.type))) return {zone};
					return {zone, source: {
						id: designerDropZoneId(item.path), type: node.type,
						kind: 'node', path: item.path,
					}};
				},
			});
			// The Host requires an EMPTY render-surface through offscreen staging.
			// Initialize the Base presentation only after its successful commit.
			const activateInteractions = () => {
				if (interactions) return true;
				if (signal.aborted || host?.state !== 'READY' || !stagedRoot.isConnected) {
					throw new Error('DESIGNER_INTERACTION_UNAVAILABLE');
				}
				const candidate = createDesignerInteractionPresentation({
					root: stagedRoot, surface: dropSurface,
					getSelection: () => editor.selection()?.primary,
					isDragging: () => dnd.active,
				});
				try {
					candidate.refresh();
					interactions = candidate;
					return true;
				} catch (error) {
					candidate.destroy();
					throw error;
				}
			};
			const releaseNodeSources = () => {
				for (const release of registeredNodeSources.values()) release();
				registeredNodeSources.clear();
			};
			const syncNodeSources = () => {
				const next = new Map();
				for (const element of stagedRoot.querySelectorAll('[data-cb-design-node-drag-source]')) {
					let path;
					try { path = JSON.parse(element.dataset.cbDesignNodeDragSource); } catch (_) { continue; }
					if (!Array.isArray(path) || !path.length) continue;
					const moving = nodeAt(editor.project().root, path);
					if (!moving || moving.type !== element.dataset.cbDesignNodeType
						|| (activeRegistry.profileId === 'base/mail' && moving.type === 'mail.column')
						|| (activeRegistry.profileId === 'base/reports'
							&& ['reports.header', 'reports.footer'].includes(moving.type))) continue;
					next.set(element, {path, type: moving.type, directCanvas: true});
				}
				// Layer sources are now bound by the shared Base Layer Tree.
				for (const [element, release] of registeredNodeSources) {
					if (next.has(element)) continue;
					release();
					registeredNodeSources.delete(element);
				}
				for (const [element, item] of next) if (!registeredNodeSources.has(element)) {
					registeredNodeSources.set(element, dnd.registerSource(element, {
						id: designerDropZoneId(item.path), type: item.type,
						kind: 'node', path: item.path, directCanvas: item.directCanvas === true,
					}));
				}
			};
			const syncDropZones = () => {
				const next = new Map([[dropSurface, 'root']]);
				for (const element of dropSurface.querySelectorAll('[data-cb-design-drop-zone-id]')) {
					// Reports' separate geometry proxies overlap the same semantic
					// nodes already registered below. Never register these hidden
					// duplicates as native OR keyboard drop-targets.
					if (activeRegistry.profileId === 'base/reports') continue;
					if (typeof element.dataset.cbDesignDropZoneId === 'string') {
						next.set(element, element.dataset.cbDesignDropZoneId);
					}
				}
				for (const element of dropSurface.querySelectorAll('[data-cb-design-node-path]')) {
					let path = null;
					try { path = JSON.parse(element.dataset.cbDesignNodePath || ''); }
					catch (_) { continue; }
					try { next.set(element, designerDropZoneId(path)); }
					catch (_) { /* renderer path is never authority */ }
				}
				// Layer zones are now bound by the shared Base Layer Tree.
				for (const [element, record] of registeredZones) {
					if (next.get(element) === record.id) continue;
					record.release();
					registeredZones.delete(element);
				}
				for (const [element, id] of next) {
					if (!registeredZones.has(element)) {
						registeredZones.set(element, {id, release: dnd.registerZone(element, {id})});
					}
				}
			};
			dropSurface.addEventListener('cb:designer-dropzones-changed', () => {
				syncDropZones();
				syncNodeSources();
				interactions?.refresh();
			}, {signal});
			const canInsertComponent = (project, component) => {
				const target = insertionTarget(project, component.type);
				if (!target) return false;
				try {
					return profileModule.resolveCommand(target.commandId)?.canApply(project, target.input) === true;
				} catch (_) { return false; }
			};
			// Reports singleton ownership remains a trusted domain decision.
			const existingReportComponentPath = (project, component) => {
				if (activeRegistry.profileId !== 'base/reports' || component.singleton !== true
					|| !Array.isArray(project?.root?.children)) return null;
				const index = project.root.children.findIndex(node => node?.type === component.type);
				return index < 0 ? null : [index];
			};
			const showSelectedInspector = () => {
				controller.activatePanel('inspector');
				if (stagedRoot.classList.contains('is-right-panel-collapsed')) {
					stagedRoot.querySelector('[data-cb-design-shell-drawer-toggle="right"]')?.click();
				}
			};
			const palette = createDesignerElementsPalette({
				documentRef: doc,
				elements: [],
				dynamicData: nativeTokens,
				labels: {
					elements: window.cbCoreDesignerLaunch?.paletteLabels?.elements || 'Elements',
					dynamicData: window.cbCoreDesignerLaunch?.paletteLabels?.dynamicData || 'Dynamic Data',
					actions: window.cbCoreDesignerLaunch?.paletteLabels?.actions || {
						add: 'Add', edit: 'Edit', select: 'Select', copy: 'Copy',
					},
				},
				onIntent: ({source, id, action, value}) => {
					if (signal.aborted || host?.state !== 'READY') return;
					if (source === 'dynamic-data') {
						if (activeRegistry.profileId !== 'base/mail'
							|| action !== 'copy' || !nativeTokens.some(token => token.id === id && token.value === value)) return;
						const clipboard = doc.defaultView?.navigator?.clipboard;
						if (typeof clipboard?.writeText !== 'function') {
							doc.defaultView?.cbCore?.toast?.error?.('Copy unavailable');
							return;
						}
						return clipboard.writeText(value).catch(() => {
							if (!signal.aborted) doc.defaultView?.cbCore?.toast?.error?.('Copy unavailable');
						});
					}
					if (source !== 'elements') return;
					const component = components.find(item => item.id === id);
					if (!component) return;
					const project = editor.project();
					const existingPath = existingReportComponentPath(project, component);
					if (action === 'edit' && existingPath !== null) {
						const ref = {kind: 'node', path: existingPath};
						editor.select([ref], ref);
						showSelectedInspector();
						return;
					}
					if (action !== 'add' || existingPath !== null || !canInsertComponent(project, component)) return;
					const target = insertionTarget(project, component.type);
					if (!target) return;
					const {parentPath, index} = target.input;
					const parent = nodeAt(project.root, parentPath);
					if (!parent || profileModule.resolveCommand(target.commandId)
						?.canApply(project, target.input) !== true) return;
					editor.execute(target.commandId, target.input);
					// Native Reports reserves its anchored Footer on root insertion;
					// an explicit before/after index is otherwise preserved.
					if (activeRegistry.profileId === 'base/reports') {
						const siblings = nodeAt(editor.project().root, parentPath)?.children;
						const actualIndex = parentPath.length === 0
							? Math.min(index, parent.children.length - 1) : index;
						if (siblings?.[actualIndex]?.type === component.type) {
							const path = [...parentPath, actualIndex];
							const ref = {kind: 'node', path};
							editor.select([ref], ref);
							showSelectedInspector();
						}
					}
				},
			});
			elementsPanel.replaceChildren(palette.element);
			const registeredPaletteSources = new Map();
			const releasePaletteSources = () => {
				for (const release of registeredPaletteSources.values()) release();
				registeredPaletteSources.clear();
			};
			const refreshElements = (project, selection) => {
				const descriptors = components.map(component => {
					const existingPath = existingReportComponentPath(project, component);
					const available = !signal.aborted && (existingPath !== null || canInsertComponent(project, component));
					const selected = existingPath !== null && selection?.primary?.kind === 'node'
						&& JSON.stringify(selection.primary.path) === JSON.stringify(existingPath);
					return {
						id: component.id, type: component.type,
						label: platformFieldLabel(component.type, component.label),
						state: available ? 'interactive' : 'disabled',
						action: existingPath !== null ? 'edit' : 'add',
						selected,
						disabledReason: 'This element cannot be added in the current report context.',
					};
				});
				// Release existing DND source handles before replacing DOM cards.
				releasePaletteSources();
				palette.update({elements: descriptors, dynamicData: nativeTokens});
				const byId = new Map(components.map(component => [component.id, component]));
				for (const button of palette.element.querySelectorAll('[data-cb-design-element-source="elements"]')) {
					const component = byId.get(button.dataset.cbDesignComponentId);
					if (!component || button.disabled || button.dataset.cbDesignElementAction !== 'add') continue;
					registeredPaletteSources.set(button, dnd.registerSource(button, {
						id: component.id, type: component.type,
					}));
				}
			};

			const settingsPanel = stagedRoot.querySelector('[data-cb-platform-panel="settings"]');
			if (!settingsPanel) throw new Error('HOST_CAPABILITY_UNAVAILABLE');
			const settingsControls = new Map();
			const refreshSettings = () => {
				const fields = editor.persistedFields();
				for (const [key,value] of Object.entries(fields)) {
					const definition = fieldsByKey.get(key);
					if (!definition) throw new Error('REGISTRY_INVALID');
					let control = settingsControls.get(key);
					if (!control) {
						control = createDesignerInspectorValueControl({
							key: 'persisted.' + key, label: platformFieldLabel(key, definition.labelKey), value,
							schema: definition.valueSchema,
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
			let inspectorInitialized = false;
			const inspectorControls = new Map();
			const clearInspector = () => {
				for (const control of inspectorControls.values()) control.destroy();
				inspectorControls.clear();
				inspectorInitialized = false;
				inspectorPanel.replaceChildren();
			};
			const renderInteraction = ({project, selection, signal: renderSignal}) => {
				if (renderSignal?.aborted || signal.aborted || !project?.root) return;
				const selected = new Set((selection?.refs || [])
					.filter((ref) => ref?.kind === 'node').map((ref) => JSON.stringify(ref.path)));
				const walk = (node, path) => {
					if (!node || !Array.isArray(node.children)) throw new Error('SCHEMA_DOMAIN_INVALID');
					const summary = activeRegistry.profileId === 'base/reports'
						? profileModule.layerSummaryFor?.(node) : null;
					const typeLabel = platformFieldLabel(node.type);
					// This is presentation text only; every mutation remains routed
					// through the typed session commands and saved schema.
					const displayLabel = typeof summary === 'string' && [...summary].length <= 45 && summary
						? typeLabel + ' · ' + summary : typeLabel;
					const ref = Object.freeze({kind: 'node', path: Object.freeze([...path])});
					const parentPath = path.slice(0, -1);
					const parent = path.length ? nodeAt(project.root, parentPath) : null;
					const policy = profileModule.layerPolicyFor(node, parent, path.at(-1));
					const interaction = projectDesignerLayerInteractions({
						node, parent, path, policy,
						commandIds: {
							remove: prefix + '.remove',
							reorder: prefix + '.reorder',
							set: prefix + '.set',
						},
						execute: (id, input) => editor.execute(id, input),
						isReady: () => !signal.aborted && host.state === 'READY',
					});
					return {
						key: JSON.stringify(path),
						path,
						ref,
						label: displayLabel,
						meta: path.length === 0 ? 'Document' : '',
						selected: selected.has(JSON.stringify(path)),
						children: node.children.map((child, i) => walk(child, [...path, i])),
						...(interaction.reorder ? {reorder: interaction.reorder} : {}),
						...(Object.keys(interaction.actions).length ? {actions: interaction.actions} : {}),
						...(interaction.hidden !== null ? {
							dataset: {cbDesignLayerHidden: interaction.hidden ? 'true' : 'false'},
						} : {}),
					};
				};
			releaseNodeSources();
			tree.render([walk(project.root, [])]);
				const primary = selection?.primary;
				const inspected = primary?.kind === 'node' ? nodeAt(project.root, primary.path) : null;
				const key = inspected ? JSON.stringify([primary.path, inspected.type]) : null;
				if (key !== inspectorSelectionKey) {
					clearInspector();
					inspectorSelectionKey = key;
				}
				if (key !== null) {
					const node = nodeAt(project.root, primary.path);
					if (!node) throw new Error('SELECTION_INVALID');
					if (!inspectorInitialized) {
						inspectorInitialized = true;
						const identity = createDesignerInspectorIdentity({
							label: platformFieldLabel(node.type), eyebrow: node.provider,
							context: 'Selected element', documentRef: stagedRoot.ownerDocument,
						});
						inspectorPanel.append(identity.element);
						const fields = profileModule.propertiesFor(node);
						const addControl = (field, target) => {
							const control = createDesignerInspectorValueControl({
								key: 'node.' + field.key, label: platformFieldLabel(field.key, field.label),
								value: field.value, multiline: field.multiline,
								schema: propertySchemaFor(node, field.key),
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
							target.append(control.element);
						};
						if (activeRegistry.profileId === 'base/reports' && fields.length > 1) {
							// Group the existing typed controls for Reports only.
							// Registry fields and command paths stay unchanged.
							const displayFields = fields.filter((field) => {
								const schema = propertySchemaFor(node, field.key);
								return schema.type === 'boolean' || (schema.type === 'string'
									&& Array.isArray(schema.enum) && schema.enum.length === 2
									&& schema.enum.includes('on') && schema.enum.includes('off'));
							});
							const contentFields = fields.filter((field) => !displayFields.includes(field));
							for (const [title, groupFields] of [
								['Content', contentFields], ['Display options', displayFields],
							]) {
								if (!groupFields.length) continue;
								const section = createDesignerInspectorSection({
									title, expanded: true, documentRef: stagedRoot.ownerDocument,
								});
								inspectorPanel.append(section.element);
								for (const field of groupFields) addControl(field, section.body);
							}
						} else {
							for (const field of fields) addControl(field, inspectorPanel);
						}
					} else {
						for (const field of profileModule.propertiesFor(node)) {
							inspectorControls.get(field.key)?.update({value:field.value});
						}
					}
				}
				refreshSettings();
				refreshElements(project, selection);
				syncDropZones();
				syncNodeSources();
				interactions?.refresh();
				// A field edit may dirty the joint Save without changing History.
				// Refresh the ONE SaveCoordinator-derived button status after Host
				// projection, never infer saved state from a toolbar event.
				stagedSaveUI.get(stagedRoot)?.syncSaveUI();
			};
			// publishCommitted() must receive the Host-facing shell facade,
			// never the internal createDesignerShell controller. The facade
			// owns post-commit interaction activation and refresh.
			const shellFacade = Object.freeze({
				destroy: () => {
					releasePaletteSources();
					releaseNodeSources();
					tree.destroy();
					palette.destroy();
					dnd.destroy();
					interactions?.destroy();
					interactions = null;
					controller.destroy();
				},
				activatePlatformSession: () => controller.activatePlatformSession(),
				activateInteractions,
				refreshInteraction: () => interactions?.refresh(),
				renderInteraction,
			});
			stagedShells.set(stagedRoot, shellFacade);
			return shellFacade;
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
		// The Host mounts in a disconnected stage; paint only after commit.
		shell.refreshInteraction();
		const saveUI = stagedSaveUI.get(canvas);
		if (!saveUI || saveUI.signal.aborted) throw new Error('SAVE_NOT_READY');
		// Edit-state notifications refresh the button without altering Save
		// authority, while aborted Host contexts drop their subscriptions.
		const unsubscribe = host.editor.subscribe(saveUI.syncSaveUI);
		if (typeof unsubscribe !== 'function') throw new Error('HOST_CAPABILITY_UNAVAILABLE');
		saveUI.signal.addEventListener('abort', unsubscribe, {once: true});
		saveUI.syncSaveUI();
		// Post-commit presentation is optional, but a failed activation must
		// never make an already-READY Host look like a failed Host commit.
		try {
			shell.activateInteractions();
		} catch (error) {
			canvas.dataset.cbDesignInteractionStatus = 'unavailable';
			// Keep Save/History/session authority intact, expose a visible
			// non-silent degraded state and a machine-readable diagnostic.
			const warning = canvas.ownerDocument.createElement('p');
			warning.className = 'notice notice-error cb-core-design-shell__interaction-warning';
			warning.setAttribute('role', 'alert');
			warning.textContent = 'Designer interaction feedback is unavailable. Reload the Designer before editing.';
			canvas.querySelector('[data-cb-design-shell-persistent-notice]')?.after(warning);
			canvas.dispatchEvent(new CustomEvent('cb:design-shell:interaction-failure', {
				bubbles: true, detail: Object.freeze({code: 'DESIGNER_INTERACTION_UNAVAILABLE'}),
			}));
			console.error('DESIGNER_INTERACTION_UNAVAILABLE', error);
		}
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
	moveNode: moveNodeCommand,
	removeNode: removeNodeCommand,
	reorderNode: reorderNodeCommand,
	setProperty: setPropertyCommand,
});
export {
	designerDropZoneId,
	resolveDesignerDropPlacement,
	resolveDesignerInsertPlacement,
	resolveDesignerMovePlacement,
	createDesignerDragDropController,
	createDesignerInteractionPresentation,
	createDesignerElementsPalette,
	createDesignerCanvasNavigation,
	createDesignerContextMenu,
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
	configureDesignerDocumentView,
	createDesignerIcon,
	createDesignerInspectorControls,
	createDesignerInspectorTextControl,
	createDesignerInspectorIdentity,
	createDesignerInspectorSection,
	createDesignerInspectorToggle,
	enableDesignerTextareaAutosize,
	createDesignerLayerRow,
	createDesignerLayerTree,
	projectDesignerLayerInteractions,
	createDesignerNavigation,
	createDesignerSelectionController,
	createDesignerShell,
	decorateDesignerControl,
	decorateDesignerElement,
	insertNodeCommand,
	moveNodeCommand,
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
		configureDocumentView: configureDesignerDocumentView,
		sidebarRoles: DESIGNER_SIDEBAR_ROLES,
		viewportOrder: DESIGNER_VIEWPORT_ORDER,
		paletteRoles: DESIGNER_PALETTE_ROLES,
		placement: Object.freeze({ resolveInsert: resolveDesignerInsertPlacement, resolveMove: resolveDesignerMovePlacement }),
		elements: Object.freeze({ createPalette: createDesignerElementsPalette }),
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
		canvasNavigation: Object.freeze({ create: createDesignerCanvasNavigation }),
		interactions: Object.freeze({ createPresentation: createDesignerInteractionPresentation }),
		contextMenu: Object.freeze({ create: createDesignerContextMenu }),
		dragDrop: Object.freeze({
			createController: createDesignerDragDropController,
			zoneId: designerDropZoneId,
			resolvePlacement: resolveDesignerDropPlacement,
			resolveMove: resolveDesignerMovePlacement,
		}),
		layers: Object.freeze({
			actions: DESIGNER_LAYER_ACTIONS,
			createRow: createDesignerLayerRow,
			createTree: createDesignerLayerTree,
			projectInteractions: projectDesignerLayerInteractions,
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
