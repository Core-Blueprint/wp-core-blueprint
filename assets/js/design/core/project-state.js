import { cloneFrozen, cloneValue, inspectJsonValue, isPlainObject } from './value.js';
import { validateStableProjectV1 } from './stable-schema.js';

const PROJECT_KEYS = new Set(['schema_version', 'design_type', 'root']);

const normalizeProject = (project) => {
	// The entire incoming graph is inspected before reading even one getter.
	inspectJsonValue(project);
	if (
		!isPlainObject(project)
		|| Object.keys(project).some((key) => !PROJECT_KEYS.has(key))
		|| !['schema_version', 'design_type', 'root'].every((key) =>
			Object.prototype.hasOwnProperty.call(project, key))
		|| !Number.isSafeInteger(project.schema_version)
		|| project.schema_version < 0
		|| typeof project.design_type !== 'string'
		|| project.design_type === ''
		|| !isPlainObject(project.root)
	) {
		throw new TypeError('ProjectState requires a DesignProject-shaped object.');
	}
	return cloneFrozen(project);
};

export class ProjectState {
	#project;
	#revision = 0;
	#listeners = new Set();
	#validate;
	#stableSchema;

	/**
	 * The optional stableSchema context is supplied ONLY by the verified
	 * installed Host bootstrap; it is not embedded in project JSON.
	 * Historical experimental generation 0 remains on the existing path.
	 */
	constructor(project, { validate = null, stableSchema = null } = {}) {
		if (null !== validate && typeof validate !== 'function') {
			throw new TypeError('ProjectState validate must be a function or null.');
		}
		this.#validate = validate;
		this.#stableSchema = stableSchema;
		this.#project = this.#normalize(project);
	}

	get revision() {
		return this.#revision;
	}

	current() {
		return this.#project;
	}

	snapshot() {
		return cloneValue(this.#project);
	}

	/** Stage the complete validated project without any public effect. */
	stage(project) {
		return this.#normalize(project);
	}

	/** Commit an already validated frozen project without invoking observers. */
	commitStaged(next, { source = 'editor' } = {}) {
		this.#project = next;
		this.#revision += 1;
		return Object.freeze({ revision: this.#revision, source: String(source || 'editor') });
	}

	/** Internal failure-path restoration before any public transaction event. */
	restoreCommitted(snapshot, revision) {
		this.#project = snapshot;
		this.#revision = revision;
	}

	/** Notify only after all cooperating editor stores have been committed. */
	notify(event, onObserverFailure = null) {
		for (const listener of [...this.#listeners]) {
			if (!this.#listeners.has(listener)) continue;
			try {
				listener(event, this.#project);
			} catch (error) {
				if (typeof onObserverFailure === 'function') onObserverFailure('project');
			}
		}
	}

	replace(project, { source = 'editor' } = {}) {
		const event = this.commitStaged(this.stage(project), { source });
		this.notify(event);
		return this.#project;
	}

	subscribe(listener) {
		if (typeof listener !== 'function') throw new TypeError('ProjectState listeners must be functions.');
		this.#listeners.add(listener);
		return () => this.#listeners.delete(listener);
	}

	#normalize(project) {
		const normalized = normalizeProject(project);
		if (normalized.schema_version === 1) {
			if (null === this.#stableSchema) {
				throw new TypeError('Stable DesignProject requires verified Host registry context.');
			}
			const result = validateStableProjectV1(normalized, this.#stableSchema);
			if (!result.valid) {
				const error = new TypeError('Stable DesignProject refused by canonical schema validation.');
				error.diagnostics = result.diagnostics;
				throw error;
			}
		} else if (normalized.schema_version !== 0) {
			throw new TypeError('Unsupported DesignProject schema generation.');
		}
		if (this.#validate) this.#validate(normalized);
		return normalized;
	}
}
