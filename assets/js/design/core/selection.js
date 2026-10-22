import { nodeAt, normalizePath, pathKey } from './tree.js';
import { cloneFrozen } from './value.js';

const identity = (value) => {
	if (typeof value !== 'string' || !/^[a-zA-Z0-9][a-zA-Z0-9._:/-]{0,127}$/.test(value)) {
		throw new TypeError('Selection entity identity must be a bounded registered identifier.');
	}
	return value;
};
const normalizeRef = (ref) => {
	if (!ref || typeof ref !== 'object' || Array.isArray(ref)) throw new TypeError('Invalid selection reference.');
	if (ref.kind === 'node' && Object.keys(ref).length === 2) {
		return Object.freeze({ kind: 'node', path: Object.freeze(normalizePath(ref.path)) });
	}
	if (ref.kind === 'entity' && Object.keys(ref).length === 3) {
		return Object.freeze({ kind: 'entity', type: identity(ref.type), id: identity(ref.id) });
	}
	throw new TypeError('Selection reference must be a strict node or entity reference.');
};
const refKey = (ref) => ref.kind === 'node'
	? 'node:' + pathKey(ref.path)
	: 'entity:' + JSON.stringify([ref.type, ref.id]);
const sameRef = (left, right) => left === null || right === null
	? left === right
	: refKey(left) === refKey(right);
const sameRefs = (left, right) => left.refs.length === right.refs.length
	&& left.refs.every((ref, index) => sameRef(ref, right.refs[index]))
	&& sameRef(left.primary, right.primary);
const eventMeta = (value, action) => Object.freeze({
	source: typeof value?.source === 'string' && value.source.trim() ? value.source.trim() : 'editor',
	action: typeof value?.action === 'string' && value.action.trim() ? value.action.trim() : action,
	force: value?.force === true,
});
const normalizeSnapshot = (snapshot) => {
	if (!snapshot || typeof snapshot !== 'object' || Array.isArray(snapshot)) {
		throw new TypeError('Invalid selection snapshot.');
	}
	const paths = Array.isArray(snapshot.paths) ? snapshot.paths : [];
	const refs = Array.isArray(snapshot.refs)
		? snapshot.refs
		: paths.map((path) => ({ kind: 'node', path }));
	const normalized = [];
	const seen = new Set();
	for (const candidate of refs) {
		const ref = normalizeRef(candidate);
		const key = refKey(ref);
		if (seen.has(key)) continue;
		seen.add(key);
		normalized.push(ref);
	}
	const primaryCandidate = Object.hasOwn(snapshot, 'primaryRef')
		? snapshot.primaryRef
		: (snapshot.primary?.kind ? snapshot.primary
			: snapshot.primary === null || snapshot.primary === undefined
				? null : { kind: 'node', path: snapshot.primary });
	const primary = primaryCandidate === null ? null : normalizeRef(primaryCandidate);
	if (primary && !seen.has(refKey(primary))) throw new RangeError('Selected primary must belong to selection.');
	return Object.freeze({ refs: Object.freeze(normalized), primary });
};

/**
 * The single transient SelectionState. Staged EditorState forks use the same
 * class, never a second selection authority. Entity lookup is provided only
 * by the trusted frozen schema/Host context; no DOM-derived identity is valid.
 */
export class SelectionState {
	#refs = [];
	#primary = null;
	#revision = 0;
	#contextEpoch = 0;
	#resolveEntity = null;
	#configured = false;
	#listeners = new Set();
	#batchDepth = 0;

	get revision() { return this.#revision; }
	get contextEpoch() { return this.#contextEpoch; }

	configureTyped({ resolveEntity, contextEpoch } = {}) {
		if (this.#configured || typeof resolveEntity !== 'function'
			|| !Number.isSafeInteger(contextEpoch) || contextEpoch < 0) {
			throw new TypeError('Selection requires one trusted entity resolver and context epoch.');
		}
		this.#resolveEntity = resolveEntity;
		this.#contextEpoch = contextEpoch;
		this.#configured = true;
	}

	fork() {
		const staged = new SelectionState();
		staged.#resolveEntity = this.#resolveEntity;
		staged.#contextEpoch = this.#contextEpoch;
		staged.#configured = this.#configured;
		staged.restoreCommitted(this.snapshot(), this.#revision);
		return staged;
	}

	#validateRefs(refs, project, expectedEpoch) {
		if (!Array.isArray(refs) || !project?.root || !Number.isSafeInteger(expectedEpoch)
			|| expectedEpoch !== this.#contextEpoch) {
			throw this.#invalid();
		}
		const unique = [];
		const seen = new Set();
		try {
			for (const candidate of refs) {
				const ref = normalizeRef(candidate);
				if (ref.kind === 'node' && !nodeAt(project.root, ref.path)) throw this.#invalid();
				if (ref.kind === 'entity' && (!this.#resolveEntity
					|| this.#resolveEntity(ref, project) !== true)) throw this.#invalid();
				const key = refKey(ref);
				if (seen.has(key)) continue;
				seen.add(key);
				unique.push(ref);
			}
		} catch (_) { throw this.#invalid(); }
		return { unique, seen };
	}

	#invalid() {
		const error = new Error('Selection reference is not valid for the current context.');
		error.code = 'SELECTION_INVALID';
		return error;
	}

	#publish(refs, primary, metadata) {
		const next = { refs, primary };
		if (sameRefs({ refs: this.#refs, primary: this.#primary }, next) && metadata.force !== true) return false;
		this.#refs = [...refs];
		this.#primary = primary;
		if (this.#batchDepth) return true;
		const event = Object.freeze({
			revision: ++this.#revision, source: metadata.source, action: metadata.action,
		});
		this.notify(event);
		return true;
	}

	setRefs(refs, primary = null, { project, contextEpoch = this.#contextEpoch, source = 'host', action = 'set', force = false } = {}) {
		const { unique, seen } = this.#validateRefs(refs, project, contextEpoch);
		let normalizedPrimary;
		try { normalizedPrimary = primary === null ? null : normalizeRef(primary); }
		catch (_) { throw this.#invalid(); }
		if (normalizedPrimary && !seen.has(refKey(normalizedPrimary))) throw this.#invalid();
		return this.#publish(unique, normalizedPrimary, { source, action, force });
	}

	typedSnapshot() {
		return cloneFrozen({
			refs: this.#refs, primary: this.#primary,
			revision: this.#revision, contextEpoch: this.#contextEpoch,
		});
	}

	// Node-path projection retained for existing Base consumers; typed refs
	// remain authoritative even when a selection contains only entities.
	paths() { return this.#refs.filter((ref) => ref.kind === 'node').map((ref) => [...ref.path]); }
	primary() { return this.#primary?.kind === 'node' ? [...this.#primary.path] : null; }
	snapshot() {
		return cloneFrozen({
			paths: this.paths(), primary: this.primary(),
			refs: this.#refs, primaryRef: this.#primary,
			contextEpoch: this.#contextEpoch,
		});
	}

	set(paths, primary = null, metadata = {}) {
		if (!Array.isArray(paths)) throw new TypeError('Selection paths must be an array.');
		const refs = paths.map((path) => normalizeRef({ kind: 'node', path }));
		const selectedPrimary = primary === null
			? (refs.at(-1) ?? null)
			: normalizeRef({ kind: 'node', path: primary });
		const normalized = normalizeSnapshot({ refs, primaryRef: selectedPrimary });
		return this.#publish(normalized.refs, normalized.primary, eventMeta(metadata, 'set'));
	}

	select(path, { additive = false, source = 'editor', action = 'select', force = false } = {}) {
		const candidate = normalizeRef({ kind: 'node', path });
		const refs = additive ? [...this.#refs.filter((ref) => !sameRef(ref, candidate)), candidate] : [candidate];
		return this.#publish(refs, candidate, { source, action, force });
	}

	clear(metadata = {}) { return this.#publish([], null, eventMeta(metadata, 'clear')); }

	remap(mapper, metadata = {}) {
		if (typeof mapper !== 'function') throw new TypeError('Selection remappers must be functions.');
		const next = [];
		const seen = new Set();
		let primary = null;
		for (const ref of this.#refs) {
			const candidate = ref.kind === 'node'
				? mapper([...ref.path]) : ref;
			if (candidate === null) continue;
			const normalized = ref.kind === 'node'
				? normalizeRef({ kind: 'node', path: candidate }) : ref;
			const key = refKey(normalized);
			if (!seen.has(key)) {
				next.push(normalized);
				seen.add(key);
			}
			if (sameRef(ref, this.#primary)) primary = normalized;
		}
		return this.#publish(next, primary ?? next.at(-1) ?? null, eventMeta(metadata, 'remap'));
	}

	reconcile(root, { project = null, ...metadata } = {}) {
		const refs = [];
		for (const ref of this.#refs) {
			if (ref.kind === 'node' && nodeAt(root, ref.path)) refs.push(ref);
			if (ref.kind === 'entity' && project && this.#resolveEntity) {
				let valid = false;
				try { valid = this.#resolveEntity(ref, project) === true; } catch (_) { /* deny */ }
				if (valid) refs.push(ref);
			}
		}
		const primary = refs.find((ref) => sameRef(ref, this.#primary)) ?? refs.at(-1) ?? null;
		return this.#publish(refs, primary, eventMeta(metadata, 'reconcile'));
	}

	batch(metadata, callback) {
		if (typeof callback !== 'function') throw new TypeError('Selection batch requires a callback.');
		const before = this.snapshot();
		const revision = this.#revision;
		this.#batchDepth += 1;
		let completed = false;
		try {
			const result = callback();
			if (result && typeof result.then === 'function') throw new TypeError('Selection batches are synchronous.');
			completed = true;
			return result;
		} catch (error) {
			this.restoreCommitted(before, revision);
			throw error;
		} finally {
			this.#batchDepth -= 1;
			if (completed && this.#batchDepth === 0) {
				const after = this.snapshot();
				if (!sameRefs(normalizeSnapshot(before), normalizeSnapshot(after))) {
					this.#revision += 1;
					this.notify(Object.freeze({
						revision: this.#revision, ...eventMeta(metadata, 'batch'),
					}));
				}
			}
		}
	}

	subscribe(listener, { emitCurrent = false } = {}) {
		if (typeof listener !== 'function') throw new TypeError('SelectionState listeners must be functions.');
		this.#listeners.add(listener);
		if (emitCurrent) listener(
			Object.freeze({ revision: this.#revision, source: 'initial', action: 'sync' }),
			this.snapshot(),
		);
		return () => { this.#listeners.delete(listener); };
	}

	restore(snapshot, metadata = {}) {
		const next = normalizeSnapshot(snapshot);
		return this.#publish(next.refs, next.primary, eventMeta(metadata, 'restore'));
	}

	commitSnapshot(snapshot, metadata = {}) {
		const next = normalizeSnapshot(snapshot);
		if (sameRefs({ refs: this.#refs, primary: this.#primary }, next) && metadata.force !== true) return null;
		this.#refs = [...next.refs];
		this.#primary = next.primary;
		return Object.freeze({
			revision: ++this.#revision,
			source: metadata.source || 'command',
			action: metadata.action || 'sync',
		});
	}

	restoreCommitted(snapshot, revision) {
		const next = normalizeSnapshot(snapshot);
		this.#refs = [...next.refs];
		this.#primary = next.primary;
		this.#revision = revision;
	}

	notify(event, onObserverFailure = null) {
		for (const listener of [...this.#listeners]) {
			if (!this.#listeners.has(listener)) continue;
			try { listener(event, this.snapshot()); }
			catch (_) { onObserverFailure?.('selection'); }
		}
	}

	toJSON() {
		throw new TypeError('SelectionState is editor-session state and must not be persisted in DesignProject.');
	}
}
