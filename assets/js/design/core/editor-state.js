import { InspectorState } from './inspector.js';
import { SelectionState } from './selection.js';
import { ValidationFeedback } from './validation-feedback.js';

export class EditorState {
	selection;
	inspector;
	validation;

	constructor() {
		this.selection = new SelectionState();
		this.inspector = new InspectorState();
		this.validation = new ValidationFeedback();
	}

	snapshot() {
		return {
			selection: this.selection.snapshot(),
			inspector: this.inspector.snapshot(),
			validation: this.validation.all(),
		};
	}

	restore(snapshot, selectionEvent = {}) {
		if (!snapshot || typeof snapshot !== 'object' || Array.isArray(snapshot)) {
			throw new TypeError('Invalid editor state snapshot.');
		}
		this.selection.restore(snapshot.selection ?? { paths: [], primary: null }, selectionEvent);
		this.inspector.restore(snapshot.inspector ?? { activeSection: null, expanded: [] });
		this.validation.replace(Array.isArray(snapshot.validation) ? snapshot.validation : []);
	}

	reconcile(root, selectionEvent = {}) {
		return this.selection.reconcile(root, selectionEvent);
	}

	toJSON() {
		throw new TypeError('EditorState is session-only and must not be persisted in DesignProject.');
	}
}
