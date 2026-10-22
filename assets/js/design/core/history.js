import { EditorState } from './editor-state.js';
import { bindRegisteredBatch } from './commands.js';
import { cloneFrozen, cloneValue, sameValue } from './value.js';

const normalizeLimit = (limit) => {
	if (!Number.isInteger(limit) || limit < 1 || limit > 1000) {
		throw new RangeError('Command history limit must be an integer between 1 and 1000.');
	}
	return limit;
};
const safeSource = (source) => {
	if (typeof source !== 'string' || !/^[a-z][a-z0-9:-]{0,31}$/.test(source)) {
		throw new TypeError('Designer history source must be a bounded machine label.');
	}
	return source;
};
const statusOf = (undo, redo) => Object.freeze({
	canUndo: undo.length > 0,
	canRedo: redo.length > 0,
	size: undo.length,
});
const stableEntry = (entry) => Object.freeze(entry);

/**
 * The sole Base-owned history authority for both domain and profile sessions.
 * Everything that may fail is staged before project/editor/history publication.
 * Observer failures after publication cannot roll the transaction back.
 */
export class CommandHistory {
	#projectState;
	#editorState;
	#limit;
	#undo = [];
	#redo = [];
	#historyRevision = 0;
	#nextEntryId = 0;
	#historyListeners = new Set();
	#diagnosticListeners = new Set();
	#unsubscribeExternal;
	#disposed = false;
	#busy = false;
	#baseline;
	#baselineAcknowledged = false;
	#savepoints = new WeakMap();
	#saveSequence = 0;
	#acceptedSequence = 0;
	#contextEpoch = 0;
	#verifyRebase;

	constructor(projectState, editorState, { limit = 100, verifyRebase = null } = {}) {
		if (!projectState?.current || !projectState?.stage || !projectState?.commitStaged) {
			throw new TypeError('CommandHistory requires a stageable ProjectState.');
		}
		if (!editorState?.snapshot || !editorState?.commitSnapshot || !editorState?.selection) {
			throw new TypeError('CommandHistory requires a stageable EditorState.');
		}
		if (verifyRebase !== null && typeof verifyRebase !== 'function') {
			throw new TypeError('verifyRebase must be a synchronous function or null.');
		}
		this.#projectState = projectState;
		this.#editorState = editorState;
		this.#limit = normalizeLimit(limit);
		this.#verifyRebase = verifyRebase;
		this.#baseline = cloneFrozen(projectState.current());
		// Only direct low-level ProjectState writes use this private bridge.
		// Session mutations never use ProjectState.replace(), so subscribers
		// cannot observe an intermediate stack during public session actions.
		this.#unsubscribeExternal = projectState.subscribe((event) => {
			if (!this.#busy) {
				this.#undo = [];
				this.#redo = [];
				this.#contextEpoch += 1;
				this.#baseline = cloneFrozen(projectState.current());
			}
		});
	}

	get canUndo() { return this.#undo.length > 0; }
	get canRedo() { return this.#redo.length > 0; }
	get size() { return this.#undo.length; }

	assertWritable() {
		this.#assertActive();
		if (this.#busy) {
			const error = new Error('Designer session does not allow nested mutations.');
			error.code = 'DESIGNER_REENTRANT';
			throw error;
		}
	}

	#assertActive() {
		if (this.#disposed) throw new Error('Design editor session has been disposed.');
	}

	status() {
		this.#assertActive();
		return statusOf(this.#undo, this.#redo);
	}

	subscribeHistory(listener) {
		this.#assertActive();
		if (typeof listener !== 'function') throw new TypeError('History listener must be a function.');
		this.#historyListeners.add(listener);
		return () => { this.#historyListeners.delete(listener); };
	}

	subscribeDiagnostics(listener) {
		this.#assertActive();
		if (typeof listener !== 'function') throw new TypeError('Diagnostics listener must be a function.');
		this.#diagnosticListeners.add(listener);
		return () => { this.#diagnosticListeners.delete(listener); };
	}

	#notify({ projectEvent = null, selectionEvent = null, action, source }) {
		const failures = new Set();
		const failed = (phase) => failures.add(phase);
		if (projectEvent) this.#projectState.notify(projectEvent, failed);
		if (selectionEvent) this.#editorState.selection.notify(selectionEvent, failed);
		const event = Object.freeze({
			action,
			source,
			revision: this.#projectState.revision,
			historyRevision: this.#historyRevision,
			status: statusOf(this.#undo, this.#redo),
		});
		for (const listener of [...this.#historyListeners]) {
			if (!this.#historyListeners.has(listener)) continue;
			try { listener(event); } catch (_) { failures.add('history'); }
		}
		if (failures.size) {
			const diagnostic = Object.freeze({
				code: 'CB_DESIGNER_OBSERVER_FAILED',
				phase: failures.size > 1 ? 'multiple' : [...failures][0],
				recoverable: true,
			});
			for (const listener of [...this.#diagnosticListeners]) {
				if (!this.#diagnosticListeners.has(listener)) continue;
				try { listener(diagnostic); } catch (_) { /* isolated */ }
			}
		}
	}

	#stageEditor() {
		return this.#editorState.fork();
	}

	#stageCommand(command, project, editor) {
		if (Array.isArray(command?.steps)) {
			if (!command.steps.length || command.steps.length > 64) throw new TypeError('Invalid command batch.');
			let current = project;
			let forceSelection = false;
			for (const step of command.steps) {
				const result = this.#stageCommand(step, current, editor);
				current = result.project;
				forceSelection = forceSelection || result.forceSelection;
			}
			return { project: current, forceSelection };
		}
		if (!command || typeof command.apply !== 'function') {
			throw new TypeError('Designer command requires apply(project).');
		}
		const effect = command.apply(cloneFrozen(project));
		if (!effect || typeof effect !== 'object' || typeof effect.then === 'function' || !effect.project) {
			throw new TypeError('Designer commands must return a synchronous {project} transition.');
		}
		const next = this.#projectState.stage(effect.project);
		const forceSelection = typeof effect.remapSelection === 'function' || Array.isArray(effect.selectPath);
		if (typeof effect.remapSelection === 'function') {
			editor.selection.remap(effect.remapSelection);
		}
		if (Array.isArray(effect.selectPath)) editor.selection.select(effect.selectPath);
		if (Object.hasOwn(effect, 'selectionIntent')) {
			if (!Array.isArray(effect.selectionIntent)) {
				throw new TypeError('Command selectionIntent must be an array of typed references.');
			}
			editor.selection.setRefs(effect.selectionIntent, effect.primarySelection ?? null, {
				project: next, contextEpoch: editor.selection.contextEpoch,
				source: 'command', action: String(command.label || 'command'),
			});
		}
		editor.reconcile(next.root, { project: next });
		return { project: next, forceSelection };
	}

	#entry(command, beforeProject, beforeEditor, afterProject, afterEditor, id) {
		return stableEntry({
			id,
			command,
			label: String(command.label || 'command'),
			beforeProject: cloneFrozen(beforeProject),
			beforeEditor: cloneFrozen(beforeEditor),
			afterProject: cloneFrozen(afterProject),
			afterEditor: cloneFrozen(afterEditor),
		});
	}

	#commit({
		nextProject, nextEditor, undo, redo, action, source,
		forceSelection = false, baseline = null, acknowledge = false, contextReset = false,
		selectionSource = source, selectionAction = action,
	}) {
		// Clone/validate before even entering the synchronous, silent commit.
		const nextBaseline = baseline === null ? null : cloneFrozen(baseline);
		const previous = {
			project: this.#projectState.current(),
			revision: this.#projectState.revision,
			editor: this.#editorState.snapshot(),
			selectionRevision: this.#editorState.selection.revision,
			undo: this.#undo, redo: this.#redo,
			baseline: this.#baseline, acknowledged: this.#baselineAcknowledged,
			historyRevision: this.#historyRevision, contextEpoch: this.#contextEpoch,
		};
		const projectChanged = !sameValue(nextProject, previous.project);
		let projectEvent = null;
		let selectionEvent = null;
		try {
			selectionEvent = this.#editorState.commitSnapshot(nextEditor, {
				source: selectionSource, action: selectionAction, force: forceSelection,
			});
			if (projectChanged) {
				projectEvent = this.#projectState.commitStaged(nextProject, { source });
			}
			this.#undo = undo;
			this.#redo = redo;
			if (nextBaseline !== null) this.#baseline = nextBaseline;
			if (contextReset) {
				this.#contextEpoch += 1;
				this.#baselineAcknowledged = false;
			}
			if (acknowledge) this.#baselineAcknowledged = true;
			this.#historyRevision += 1;
		} catch (error) {
			// No observer has run. If even silent restoration fails, permanently
			// disable the session instead of presenting half-committed state.
			try {
				this.#projectState.restoreCommitted(previous.project, previous.revision);
				this.#editorState.restoreCommitted(previous.editor, previous.selectionRevision);
				this.#undo = previous.undo;
				this.#redo = previous.redo;
				this.#baseline = previous.baseline;
				this.#baselineAcknowledged = previous.acknowledged;
				this.#historyRevision = previous.historyRevision;
				this.#contextEpoch = previous.contextEpoch;
			} catch (_) {
				this.#disposed = true;
				this.#historyListeners.clear();
				this.#diagnosticListeners.clear();
			}
			throw error;
		}
		// Only now may a project callback execute, already observing final
		// selection, baseline, project and both settled history stacks.
		this.#notify({ projectEvent, selectionEvent, action, source });
		return this.#projectState.current();
	}

	#run(action) {
		this.assertWritable();
		this.#busy = true;
		try { return action(); } finally { this.#busy = false; }
	}

	/** Isolate consumer policy evaluation from nested mutation attempts. */
	guardPolicy(callback) {
		if (typeof callback !== 'function') throw new TypeError('Designer policy must be synchronous.');
		return this.#run(() => {
			const result = callback();
			if (result && typeof result.then === 'function') {
				throw new TypeError('Designer policy must not return a Promise.');
			}
			return result;
		});
	}

	execute(command) {
		return this.#run(() => {
			const beforeProject = this.#projectState.current();
			const beforeEditor = this.#editorState.snapshot();
			const editor = this.#stageEditor();
			const stage = this.#stageCommand(command, beforeProject, editor);
			if (sameValue(stage.project, beforeProject)) return beforeProject;
			const nextEditor = editor.snapshot();
			const entry = this.#entry(command, beforeProject, beforeEditor, stage.project, nextEditor, this.#nextEntryId + 1);
			const nextUndo = [...this.#undo, entry];
			if (nextUndo.length > this.#limit) nextUndo.splice(0, nextUndo.length - this.#limit);
			const result = this.#commit({
				nextProject: stage.project,
				nextEditor,
				undo: nextUndo,
				redo: [],
				action: 'execute',
				source: 'command',
				forceSelection: stage.forceSelection,
				selectionAction: String(command.label || 'command'),
			});
			this.#nextEntryId = entry.id;
			return result;
		});
	}

	/**
	 * Registered Host requests are adapted to pure commands before entering
	 * the single History transaction. No member publishes independently.
	 */
	executeRegisteredBatch(requests, registryOptions) {
		const batch = bindRegisteredBatch(requests, registryOptions);
		return this.executeBatch(batch.steps);
	}

	executeBatch(commands) {
		return this.#run(() => {
			const beforeProject = this.#projectState.current();
			const beforeEditor = this.#editorState.snapshot();
			const beforeHistoryRevision = this.#historyRevision;
			let steps;
			try {
				if (!Array.isArray(commands) || commands.length < 1 || commands.length > 64) {
					throw new TypeError('Invalid batch length.');
				}
				steps = Object.freeze([...commands]);
				if (steps.some((step) => !step || typeof step.apply !== 'function')) {
					throw new TypeError('Invalid batch member.');
				}
				const compound = Object.freeze({ label: 'batch', steps });
				const editor = this.#stageEditor();
				const stage = this.#stageCommand(compound, beforeProject, editor);
				const nextEditor = editor.snapshot();
				if (sameValue(stage.project, beforeProject)) {
					if (!sameValue(nextEditor.selection, beforeEditor.selection)) {
						throw new TypeError('Selection-only batch refused.');
					}
					return Object.freeze({
						changed: false,
						projectRevision: this.#projectState.revision,
						historyRevision: beforeHistoryRevision,
						selection: this.#editorState.selection.typedSnapshot(),
					});
				}
				const entry = this.#entry(compound, beforeProject, beforeEditor, stage.project, nextEditor, this.#nextEntryId + 1);
				const undo = [...this.#undo, entry];
				if (undo.length > this.#limit) undo.splice(0, undo.length - this.#limit);
				this.#commit({
					nextProject: stage.project, nextEditor, undo, redo: [],
					action: 'execute', source: 'command', forceSelection: false,
					selectionAction: 'batch',
				});
				this.#nextEntryId = entry.id;
				return Object.freeze({
					changed: true,
					projectRevision: this.#projectState.revision,
					historyRevision: this.#historyRevision,
					selection: this.#editorState.selection.typedSnapshot(),
				});
			} catch (_) {
				const error = new Error('Designer command batch was rejected atomically.');
				error.code = 'BATCH_ABORTED';
				throw error;
			}
		});
	}

	undo() {
		return this.#run(() => {
			if (!this.#undo.length) return false;
			const entry = this.#undo.at(-1);
			const project = this.#projectState.stage(entry.beforeProject);
			const editor = this.#stageEditor();
			editor.restore(entry.beforeEditor);
			this.#commit({
				nextProject: project, nextEditor: editor.snapshot(),
				undo: this.#undo.slice(0, -1), redo: [...this.#redo, entry],
				action: 'undo', source: 'history',
			});
			return true;
		});
	}

	redo() {
		return this.#run(() => {
			if (!this.#redo.length) return false;
			const entry = this.#redo.at(-1);
			const project = this.#projectState.stage(entry.afterProject);
			const editor = this.#stageEditor();
			editor.restore(entry.afterEditor);
			const undo = [...this.#undo, entry];
			if (undo.length > this.#limit) undo.splice(0, undo.length - this.#limit);
			this.#commit({
				nextProject: project, nextEditor: editor.snapshot(),
				undo, redo: this.#redo.slice(0, -1),
				action: 'redo', source: 'history',
			});
			return true;
		});
	}

	clear() {
		return this.#run(() => {
			if (!this.#undo.length && !this.#redo.length) return;
			this.#undo = [];
			this.#redo = [];
			this.#historyRevision += 1;
			this.#notify({ action: 'clear', source: 'consumer' });
		});
	}

	replace(project, { source = 'consumer', resetSelection = false, contextChanged = false } = {}) {
		return this.#run(() => {
			const label = safeSource(source);
			const next = this.#projectState.stage(project);
			const editor = this.#stageEditor();
			if (resetSelection) editor.selection.clear();
			else editor.reconcile(next.root, { project: next });
			const editorSnapshot = editor.snapshot();
			const differs = !sameValue(next, this.#projectState.current());
			const hadHistory = this.#undo.length > 0 || this.#redo.length > 0;
			const differsSelection = !sameValue(
				this.#editorState.selection.snapshot(),
				editorSnapshot.selection,
			);
			if (!differs && !hadHistory && !differsSelection && !contextChanged) return this.#projectState.current();
			return this.#commit({
				nextProject: next, nextEditor: editorSnapshot,
				undo: [], redo: [], action: 'replace', source: label,
				baseline: next, contextReset: true,
				selectionSource: 'replace', selectionAction: label,
			});
		});
	}

	baselineSnapshot() {
		this.#assertActive();
		return cloneFrozen(this.#baseline);
	}

	captureSave() {
		this.assertWritable();
		const token = Object.freeze(Object.create(null));
		const revision = this.#projectState.revision;
		const submittedProject = cloneFrozen(this.#projectState.current());
		const checkpoint = Object.freeze({
			session: this, contextEpoch: this.#contextEpoch,
			sequence: ++this.#saveSequence, revision,
			submittedProject,
			undoIds: Object.freeze(this.#undo.map((e) => e.id)),
			redoIds: Object.freeze(this.#redo.map((e) => e.id)),
			lastEntryId: this.#nextEntryId,
		});
		this.#savepoints.set(token, checkpoint);
		return Object.freeze({ token, submittedProject, revision });
	}

	#saveError(code) {
		const error = new Error('Designer save checkpoint rejected.');
		error.name = 'DesignerSaveCheckpointError';
		error.code = code;
		return error;
	}

	rebase({ saveToken, baselineProject, source = 'save-baseline' } = {}) {
		return this.#run(() => {
			const label = safeSource(source);
			const checkpoint = saveToken && typeof saveToken === 'object'
				? this.#savepoints.get(saveToken)
				: null;
			if (!checkpoint || checkpoint.session !== this) throw this.#saveError('SAVE_INVALID');
			if (checkpoint.contextEpoch !== this.#contextEpoch) throw this.#saveError('SAVE_CONTEXT_CHANGED');
			if (checkpoint.sequence !== this.#saveSequence || checkpoint.sequence <= this.#acceptedSequence) {
				throw this.#saveError('SAVE_STALE');
			}
			const accepted = this.#projectState.stage(baselineProject);
			const normalized = !sameValue(accepted, checkpoint.submittedProject);
			let nextProject = this.#projectState.current();
			let nextEditor = this.#editorState.snapshot();
			let undo = this.#undo;
			let redo = this.#redo;
			if (normalized) {
				const conflict = () => { throw this.#saveError('SAVE_REPLAY_CONFLICT'); };
				// A changed server baseline must never sever pre-save undo/redo.
				// Until each edge can be proven equivalent, reject safely.
				if (checkpoint.undoIds.length || checkpoint.redoIds.length) conflict();
				const entries = [...this.#undo, ...[...this.#redo].reverse()];
				const ids = entries.map((entry) => entry.id);
				if (entries.length !== this.#nextEntryId - checkpoint.lastEntryId
					|| ids.some((id) => id <= checkpoint.lastEntryId)) conflict();
				const editor = this.#stageEditor();
				editor.restore({ ...nextEditor, selection: { paths: [], primary: null } });
				let cursorProject = accepted;
				const replayed = [];
				try {
					for (const entry of entries) {
						const beforeProject = cursorProject;
						const beforeEditor = editor.snapshot();
						const effect = this.#stageCommand(entry.command, beforeProject, editor);
						if (sameValue(beforeProject, effect.project)) conflict();
						cursorProject = effect.project;
						replayed.push(this.#entry(
							entry.command, beforeProject, beforeEditor, cursorProject, editor.snapshot(), entry.id,
						));
					}
				} catch (_) { conflict(); }
				// Redo items represent future edits, so the active cursor is
				// the completed undo prefix, not the end of replayed future.
				undo = replayed.slice(0, this.#undo.length);
				redo = replayed.slice(this.#undo.length).reverse();
				nextProject = undo.length ? undo.at(-1).afterProject : accepted;
				nextEditor = undo.length ? undo.at(-1).afterEditor : editor.snapshot();
				if (!undo.length && redo.length) nextEditor = replayed[0].beforeEditor;
				if (this.#verifyRebase && entries.length > 0) {
					let verified = false;
					try {
						verified = this.#verifyRebase(Object.freeze({
							submittedProject: cloneFrozen(checkpoint.submittedProject),
							acceptedBaseline: cloneFrozen(accepted),
							previousCurrent: cloneFrozen(this.#projectState.current()),
							replayedCurrent: cloneFrozen(nextProject),
						})) === true;
					} catch (_) { conflict(); }
					if (!verified) conflict();
				} else if (entries.length > 0 && !this.#verifyRebase) {
					conflict();
				}
				// Check active cursor may only change through an explicitly
				// accepted replay; original state is still untouched here.
				this.#projectState.stage(nextProject);
			}
			const baselineChanged = !sameValue(this.#baseline, accepted) || !this.#baselineAcknowledged;
			if (baselineChanged || normalized) {
				this.#commit({
					nextProject, nextEditor, undo, redo, action: 'rebase', source: label,
					baseline: accepted, acknowledge: true,
				});
			}
			this.#acceptedSequence = checkpoint.sequence;
			this.#savepoints.delete(saveToken);
			return Object.freeze({
				project: this.#projectState.current(),
				baselineProject: cloneFrozen(accepted),
				retainedUndoSteps: this.#undo.length,
				normalized,
			});
		});
	}

	dispose() {
		if (this.#disposed) return;
		this.#disposed = true;
		this.#unsubscribeExternal?.();
		this.#unsubscribeExternal = null;
		this.#historyListeners.clear();
		this.#diagnosticListeners.clear();
		this.#undo = [];
		this.#redo = [];
		this.#savepoints = new WeakMap();
	}
}
