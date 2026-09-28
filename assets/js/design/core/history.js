import { cloneValue } from './value.js';

const normalizeLimit = (limit) => {
	if (!Number.isInteger(limit) || limit < 1 || limit > 1000) {
		throw new RangeError('Command history limit must be an integer between 1 and 1000.');
	}
	return limit;
};

export class CommandHistory {
	#projectState;
	#editorState;
	#limit;
	#undo = [];
	#redo = [];
	#unsubscribe;

	constructor(projectState, editorState, { limit = 100 } = {}) {
		if (!projectState?.current || !projectState?.replace || !projectState?.subscribe) {
			throw new TypeError('CommandHistory requires a ProjectState instance.');
		}
		if (!editorState?.snapshot || !editorState?.restore || !editorState?.selection) {
			throw new TypeError('CommandHistory requires an EditorState instance.');
		}
		this.#projectState = projectState;
		this.#editorState = editorState;
		this.#limit = normalizeLimit(limit);
		this.#unsubscribe = projectState.subscribe((event) => {
			if (event.source !== 'command' && event.source !== 'history') this.clear();
		});
	}

	get canUndo() {
		return this.#undo.length > 0;
	}

	get canRedo() {
		return this.#redo.length > 0;
	}

	get size() {
		return this.#undo.length;
	}

	execute(editorCommand) {
		if (!editorCommand || typeof editorCommand.apply !== 'function') {
			throw new TypeError('CommandHistory can only execute editor commands.');
		}

		const beforeProject = this.#projectState.snapshot();
		const beforeEditor = this.#editorState.snapshot();
		const effect = editorCommand.apply(beforeProject);
		if (!effect || typeof effect !== 'object' || !effect.project) {
			throw new TypeError('Editor commands must return a project transition.');
		}

		const commandLabel = String(editorCommand.label || 'command');
		try {
			this.#projectState.replace(effect.project, { source: 'command' });
			this.#editorState.selection.batch({ source: 'command', action: commandLabel }, () => {
				if (typeof effect.remapSelection === 'function') {
					this.#editorState.selection.remap(effect.remapSelection);
				}
				if (Array.isArray(effect.selectPath)) {
					this.#editorState.selection.select(effect.selectPath);
				}
				this.#editorState.reconcile(this.#projectState.current().root);
			});
		} catch (error) {
			this.#projectState.replace(beforeProject, { source: 'history' });
			this.#editorState.restore(beforeEditor, { source: 'history', action: 'rollback' });
			throw error;
		}

		const entry = Object.freeze({
			label: commandLabel,
			beforeProject: cloneValue(beforeProject),
			beforeEditor: cloneValue(beforeEditor),
			afterProject: this.#projectState.snapshot(),
			afterEditor: cloneValue(this.#editorState.snapshot()),
		});
		this.#undo.push(entry);
		if (this.#undo.length > this.#limit) this.#undo.splice(0, this.#undo.length - this.#limit);
		this.#redo = [];
		return this.#projectState.current();
	}

	undo() {
		const entry = this.#undo.pop();
		if (!entry) return false;
		this.#projectState.replace(entry.beforeProject, { source: 'history' });
		this.#editorState.restore(entry.beforeEditor, { source: 'history', action: 'undo' });
		this.#redo.push(entry);
		return true;
	}

	redo() {
		const entry = this.#redo.pop();
		if (!entry) return false;
		this.#projectState.replace(entry.afterProject, { source: 'history' });
		this.#editorState.restore(entry.afterEditor, { source: 'history', action: 'redo' });
		this.#undo.push(entry);
		return true;
	}

	clear() {
		this.#undo = [];
		this.#redo = [];
	}

	dispose() {
		this.clear();
		this.#unsubscribe?.();
		this.#unsubscribe = null;
	}
}
