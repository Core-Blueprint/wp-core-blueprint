import { nodeAt, normalizePath, pathKey } from './tree.js';

const clonePath = (path) => [...path];

const samePath = (left, right) => {
	if (left === null || right === null) return left === right;
	return left.length === right.length && left.every((value, index) => value === right[index]);
};

const sameSnapshot = (left, right) => (
	left.paths.length === right.paths.length
	&& left.paths.every((path, index) => samePath(path, right.paths[index]))
	&& samePath(left.primary, right.primary)
);

const eventMeta = (metadata, fallbackAction) => {
	const source = typeof metadata?.source === 'string' && metadata.source.trim()
		? metadata.source.trim()
		: 'editor';
	const action = typeof metadata?.action === 'string' && metadata.action.trim()
		? metadata.action.trim()
		: fallbackAction;
	return { source, action };
};

export class SelectionState {
	#paths = [];
	#primary = null;
	#listeners = new Set();
	#revision = 0;
	#batchDepth = 0;

	get revision() {
		return this.#revision;
	}

	paths() {
		return this.#paths.map(clonePath);
	}

	primary() {
		return this.#primary === null ? null : clonePath(this.#primary);
	}

	clear(metadata = {}) {
		const before = this.snapshot();
		this.#paths = [];
		this.#primary = null;
		return this.#publishIfChanged(before, eventMeta(metadata, 'clear'));
	}

	set(paths, primary = null, metadata = {}) {
		if (!Array.isArray(paths)) throw new TypeError('Selection paths must be an array.');
		const before = this.snapshot();
		const unique = [];
		const keys = new Set();
		paths.forEach((path) => {
			const normalized = normalizePath(path);
			const key = pathKey(normalized);
			if (!keys.has(key)) {
				keys.add(key);
				unique.push(normalized);
			}
		});
		this.#paths = unique;

		if (primary !== null) {
			const normalizedPrimary = normalizePath(primary);
			this.#primary = keys.has(pathKey(normalizedPrimary)) ? normalizedPrimary : null;
		} else {
			this.#primary = unique.length ? clonePath(unique.at(-1)) : null;
		}
		return this.#publishIfChanged(before, eventMeta(metadata, 'set'));
	}

	select(path, { additive = false, source = 'editor', action = 'select' } = {}) {
		const before = this.snapshot();
		const normalized = normalizePath(path);
		if (!additive) {
			this.#paths = [normalized];
			this.#primary = clonePath(normalized);
			return this.#publishIfChanged(before, { source, action });
		}
		const key = pathKey(normalized);
		if (!this.#paths.some((candidate) => pathKey(candidate) === key)) this.#paths.push(normalized);
		this.#primary = clonePath(normalized);
		return this.#publishIfChanged(before, { source, action });
	}

	remap(mapper, metadata = {}) {
		if (typeof mapper !== 'function') throw new TypeError('Selection remappers must be functions.');
		const before = this.snapshot();
		const primaryKey = this.#primary === null ? null : pathKey(this.#primary);
		const remapped = [];
		let remappedPrimary = null;
		const seen = new Set();

		this.#paths.forEach((path) => {
			const candidate = mapper(clonePath(path));
			if (candidate === null) return;
			const normalized = normalizePath(candidate);
			const key = pathKey(normalized);
			if (!seen.has(key)) {
				seen.add(key);
				remapped.push(normalized);
			}
			if (primaryKey !== null && pathKey(path) === primaryKey) remappedPrimary = normalized;
		});

		this.#paths = remapped;
		this.#primary = remappedPrimary ?? (remapped.length ? clonePath(remapped.at(-1)) : null);
		return this.#publishIfChanged(before, eventMeta(metadata, 'remap'));
	}

	reconcile(root, metadata = {}) {
		return this.remap(
			(path) => nodeAt(root, path) ? path : null,
			{ ...metadata, action: metadata?.action || 'reconcile' }
		);
	}

	batch(metadata, callback) {
		if (typeof callback !== 'function') throw new TypeError('Selection batch requires a callback.');
		const before = this.snapshot();
		const meta = eventMeta(metadata, 'batch');
		this.#batchDepth += 1;
		let completed = false;
		try {
			const result = callback();
			completed = true;
			return result;
		} catch (error) {
			this.#paths = before.paths.map(clonePath);
			this.#primary = before.primary === null ? null : clonePath(before.primary);
			throw error;
		} finally {
			this.#batchDepth -= 1;
			if (completed && this.#batchDepth === 0) this.#publishIfChanged(before, meta);
		}
	}

	subscribe(listener, { emitCurrent = false } = {}) {
		if (typeof listener !== 'function') throw new TypeError('SelectionState listeners must be functions.');
		this.#listeners.add(listener);
		if (emitCurrent) {
			listener(
				Object.freeze({ revision: this.#revision, source: 'initial', action: 'sync' }),
				this.snapshot()
			);
		}
		return () => this.#listeners.delete(listener);
	}

	snapshot() {
		return { paths: this.paths(), primary: this.primary() };
	}

	restore(snapshot, metadata = {}) {
		if (!snapshot || typeof snapshot !== 'object') throw new TypeError('Invalid selection snapshot.');
		return this.set(
			snapshot.paths ?? [],
			snapshot.primary ?? null,
			{ ...metadata, action: metadata?.action || 'restore' }
		);
	}

	#publishIfChanged(before, metadata) {
		const after = this.snapshot();
		if (sameSnapshot(before, after)) return false;
		if (this.#batchDepth > 0) return true;

		this.#revision += 1;
		const meta = eventMeta(metadata, 'change');
		const event = Object.freeze({
			revision: this.#revision,
			source: meta.source,
			action: meta.action,
		});
		this.#listeners.forEach((listener) => listener(event, this.snapshot()));
		return true;
	}

	toJSON() {
		throw new TypeError('SelectionState is editor-session state and must not be persisted in DesignProject.');
	}
}
