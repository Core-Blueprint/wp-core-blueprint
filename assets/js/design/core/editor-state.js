import { InspectorState } from './inspector.js';
import { SelectionState } from './selection.js';
import { ValidationFeedback } from './validation-feedback.js';
import { cloneFrozen } from './value.js';

export class EditorState {
	selection;
	inspector;
	validation;
	#writeGuard;

	constructor({ writeGuard = null } = {}) {
		if (writeGuard !== null && typeof writeGuard !== 'function') throw new TypeError('EditorState write guard must be a function.');
		this.#writeGuard = writeGuard;
		this.selection = new SelectionState({ writeGuard });
		this.inspector = new InspectorState();
		this.validation = new ValidationFeedback();
	}

	fork() {
		const staged = new EditorState();
		staged.selection = this.selection.fork();
		staged.inspector.restore(this.inspector.snapshot());
		staged.validation.replace(this.validation.all());
		return staged;
	}

	snapshot() {
		return cloneFrozen({
			selection: this.selection.snapshot(),
			inspector: this.inspector.snapshot(),
			validation: this.validation.all(),
		});
	}

	restore(snapshot, selectionEvent = {}) {
		this.#writeGuard?.();
		if (!snapshot || typeof snapshot !== 'object' || Array.isArray(snapshot)) {
			throw new TypeError('Invalid editor state snapshot.');
		}
		this.selection.restore(snapshot.selection ?? { paths: [], primary: null }, selectionEvent);
		this.inspector.restore(snapshot.inspector ?? { activeSection: null, expanded: [] });
		this.validation.replace(Array.isArray(snapshot.validation) ? snapshot.validation : []);
	}

	commitSnapshot(snapshot, metadata = {}) {
		this.#writeGuard?.();
		if (!snapshot || typeof snapshot !== 'object' || Array.isArray(snapshot)) {
			throw new TypeError('Invalid staged editor snapshot.');
		}
		this.inspector.restore(snapshot.inspector ?? { activeSection: null, expanded: [] });
		this.validation.replace(Array.isArray(snapshot.validation) ? snapshot.validation : []);
		return this.selection.commitSnapshot(
			snapshot.selection ?? { paths: [], primary: null }, metadata,
		);
	}

	restoreCommitted(snapshot, selectionRevision) {
		this.#writeGuard?.();
		this.inspector.restore(snapshot.inspector ?? { activeSection: null, expanded: [] });
		this.validation.replace(Array.isArray(snapshot.validation) ? snapshot.validation : []);
		this.selection.restoreCommitted(
			snapshot.selection ?? { paths: [], primary: null }, selectionRevision,
		);
	}

	reconcile(root, options = {}) {
		this.#writeGuard?.();
		return this.selection.reconcile(root, options);
	}

	toJSON() {
		throw new TypeError('EditorState is session-only and must not be persisted in DesignProject.');
	}
}
