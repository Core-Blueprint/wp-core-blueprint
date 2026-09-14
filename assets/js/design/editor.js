import {
	CommandHistory,
	EditorState,
	ProjectState,
	buildInspectorContext,
	handleEditorShortcut,
	insertNodeCommand,
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
	DESIGNER_SIDEBAR_ROLES,
	configureDesignerSidebar,
	createDesignerIcon,
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
	allowCommand = null,
	historyLimit = 100,
} = {}) => {
	const profileContext = resolveProfile(profile);
	const projectState = new ProjectState(project, {
		validate: (candidate) => validateProfileProject(profileContext, candidate),
	});
	const editorState = new EditorState();
	const history = new CommandHistory(projectState, editorState, { limit: historyLimit });
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

	const unsubscribe = projectState.subscribe((event, currentProject) => {
		const diagnostics = validateCurrent(event);
		if (typeof onChange === 'function') {
			onChange(currentProject, Object.freeze({
				event,
				diagnostics,
				profile: profileContext,
			}));
		}
	});

	const assertActive = () => {
		if (disposed) throw new Error('Design editor session has been disposed.');
	};

	const execute = (editorCommand) => {
		assertActive();
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
		project: () => projectState.current(),
		snapshot: () => projectState.snapshot(),
		replace(nextProject, options = {}) {
			assertActive();
			return projectState.replace(nextProject, options);
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
			unsubscribe();
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
	DESIGNER_MOTION_DEFAULTS,
	DESIGNER_MOTION_KEY_ATTRIBUTE,
	DESIGNER_SIDEBAR_ROLES,
	DESIGNER_VIEWPORT_ORDER,
	EditorState,
	ProjectState,
	animateLayoutChange,
	configureDesignerSidebar,
	configureDesignerViewports,
	createDesignerIcon,
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
	profiles,
	motion,
	shell: Object.freeze({
		create: createDesignerShell,
		configureSidebar: configureDesignerSidebar,
		configureViewports: configureDesignerViewports,
		sidebarRoles: DESIGNER_SIDEBAR_ROLES,
		viewportOrder: DESIGNER_VIEWPORT_ORDER,
		icons: Object.freeze({
			names: DESIGNER_ICON_NAMES,
			create: createDesignerIcon,
			decorate: decorateDesignerControl,
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
