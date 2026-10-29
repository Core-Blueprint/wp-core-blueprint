import { cloneFrozen, cloneValue } from './value.js';
import {
	insertChild,
	moveNode,
	remapPathAfterMove,
	normalizePath,
	removeNode,
	reorderChild,
	remapPathAfterInsertion,
	remapPathAfterRemoval,
	remapPathAfterReorder,
	setNodeProperty,
} from './tree.js';

const withRoot = (project, root) => ({ ...cloneValue(project), root });

const command = (label, apply) => Object.freeze({
	label,
	apply(project) {
		if (!project || typeof project !== 'object' || Array.isArray(project)) {
			throw new TypeError('Editor commands require a DesignProject-shaped object.');
		}
		return apply(project);
	},
});

export const insertNodeCommand = (parentPath, index, node) => {
	const parent = normalizePath(parentPath);
	const nextNode = cloneValue(node);
	return command('insert-node', (project) => ({
		project: withRoot(project, insertChild(project.root, parent, index, nextNode)),
		remapSelection: (path) => remapPathAfterInsertion(path, parent, index),
		selectPath: [...parent, index],
	}));
};

export const removeNodeCommand = (path) => {
	const target = normalizePath(path);
	return command('remove-node', (project) => ({
		project: withRoot(project, removeNode(project.root, target)),
		remapSelection: (selectedPath) => remapPathAfterRemoval(selectedPath, target),
	}));
};

export const reorderNodeCommand = (parentPath, fromIndex, toIndex) => {
	const parent = normalizePath(parentPath);
	return command('reorder-node', (project) => ({
		project: withRoot(project, reorderChild(project.root, parent, fromIndex, toIndex)),
		remapSelection: (path) => remapPathAfterReorder(path, parent, fromIndex, toIndex),
	}));
};

/** One project and selection transition, never separate remove/insert commits. */
export const moveNodeCommand = (fromPath, parentPath, index) => {
 const from=normalizePath(fromPath), parent=normalizePath(parentPath);
 return command('move-node', project=>({
  project:withRoot(project,moveNode(project.root,from,parent,index)),
  remapSelection:path=>remapPathAfterMove(path,from,parent,index),
 }));
};

export const setPropertyCommand = (nodePath, propertyPath, value) => {
	const target = normalizePath(nodePath);
	const properties = Array.isArray(propertyPath) ? [...propertyPath] : propertyPath;
	const nextValue = cloneValue(value);
	return command('set-property', (project) => ({
		project: withRoot(project, setNodeProperty(project.root, target, properties, nextValue)),
	}));
};

const refused = (code) => {
	const error = new Error('Registered Designer command refused.');
	error.code = code;
	return error;
};

/**
 * Trusted registry adapter, not a command registry or an evaluator.
 * The registered schema owner validates the explicit frozen input; this
 * wrapper adapts apply(project,input) to existing History.apply(project).
 */
export const bindRegisteredCommand = (definition, input, {
	validateInput, authorize,
} = {}) => {
	if (!definition || definition.contractVersion !== 1
		|| typeof definition.id !== 'string' || !definition.id
		|| typeof definition.apply !== 'function'
		|| typeof definition.canApply !== 'function'
		|| typeof validateInput !== 'function'
		|| typeof authorize !== 'function') throw refused('COMMAND_REFUSED');
	const copied = cloneFrozen(input);
	let accepted;
	try { accepted = validateInput(definition, copied); }
	catch (_) { throw refused('COMMAND_REFUSED'); }
	if (accepted !== true) throw refused('COMMAND_REFUSED');
	return Object.freeze({
		label: definition.id,
		apply(project) {
			const permitted = authorize(definition, project, copied);
			if (permitted?.then || permitted !== true) throw refused('COMMAND_REFUSED');
			const canApply = definition.canApply(cloneFrozen(project), copied);
			if (canApply?.then || canApply !== true) throw refused('COMMAND_REFUSED');
			const result = definition.apply(cloneFrozen(project), copied);
			if (result?.then) throw refused('COMMAND_REFUSED');
			if (!result || typeof result !== 'object' || !result.project) throw refused('COMMAND_REFUSED');
			return result;
		},
	});
};

/**
 * Validate all registered IDs before staging. The returned immutable compound
 * is executed through CommandHistory.executeBatch(), never step-by-step via a
 * public session and never as a separate history authority.
 */
export const bindRegisteredBatch = (requests, {
	resolveCommand, validateInput, authorize,
} = {}) => {
	if (!Array.isArray(requests) || !requests.length || requests.length > 64
		|| typeof resolveCommand !== 'function') throw refused('BATCH_ABORTED');
	const steps = requests.map((request) => {
		if (!request || typeof request !== 'object'
			|| typeof request.id !== 'string' || !request.id) throw refused('BATCH_ABORTED');
		const definition = resolveCommand(request.id);
		if (!definition || definition.id !== request.id) throw refused('BATCH_ABORTED');
		return bindRegisteredCommand(definition, request.input, { validateInput, authorize });
	});
	return Object.freeze({ label: 'batch', steps: Object.freeze(steps) });
};
