import { cloneValue } from './value.js';

const normalizeLimit = (limit) => {
	if (!Number.isInteger(limit) || limit < 1 || limit > 1000) {
		throw new RangeError('Snapshot history limit must be an integer between 1 and 1000.');
	}
	return limit;
};

/**
 * Base-owned bounded history for consumers whose domain state is not a Design
 * Foundation project tree. Consumers provide read/write adapters; Base owns the
 * undo/redo stacks, cloning, limit enforcement and history capability shape.
 */
export const createSnapshotHistory = ({
	read,
	write,
	limit = 100,
	onChange = null,
} = {}) => {
	if (typeof read !== 'function' || typeof write !== 'function') {
		throw new TypeError('Snapshot history requires read() and write() adapters.');
	}
	if (onChange !== null && typeof onChange !== 'function') {
		throw new TypeError('Snapshot history onChange must be a function or null.');
	}

	const maxEntries = normalizeLimit(limit);
	const undoStack = [];
	const redoStack = [];
	const snapshot = () => cloneValue(read());
	const notify = () => onChange?.(history);

	const history = Object.freeze({
		get canUndo() {
			return undoStack.length > 0;
		},
		get canRedo() {
			return redoStack.length > 0;
		},
		get size() {
			return undoStack.length;
		},
	});

	const checkpoint = () => {
		undoStack.push(snapshot());
		if (undoStack.length > maxEntries) undoStack.splice(0, undoStack.length - maxEntries);
		redoStack.length = 0;
		notify();
		return true;
	};

	const undo = () => {
		const previous = undoStack.at(-1);
		if (previous === undefined) return false;
		const current = snapshot();
		write(cloneValue(previous));
		undoStack.pop();
		redoStack.push(current);
		notify();
		return true;
	};

	const redo = () => {
		const next = redoStack.at(-1);
		if (next === undefined) return false;
		const current = snapshot();
		write(cloneValue(next));
		redoStack.pop();
		undoStack.push(current);
		notify();
		return true;
	};

	const clear = () => {
		undoStack.length = 0;
		redoStack.length = 0;
		notify();
	};

	return Object.freeze({ history, checkpoint, undo, redo, clear });
};