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
			const allowed = allowCommand(editorCommand, Object.freeze({
				project: projectState.current(),
				editorState,
				profile: profileContext,
			}));
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
			if (allowCommand && allowCommand(command, projectState.current()) === false) {
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
