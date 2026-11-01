/**
 * Internal Base-owned lifecycle for managed Designer document contexts.
 *
 * The controller is never exposed on a consumer session or the public
 * Designer editor export. Consumers receive only the read-only ContextHandle.
 * A gate is captured by each canonical store at construction; changing a
 * handle's visible metadata cannot authorize writes.
 */
export const createManagedContextAuthority = ({ createProfileSession, createDomainSession }) => {
	if (typeof createProfileSession !== 'function' || typeof createDomainSession !== 'function') {
		throw new TypeError('Managed context authority requires canonical Base session factories.');
	}

	const records = new WeakMap();
	let active = null;
	let nextGeneration = 0;

	const refused = (code = 'DESIGNER_CONTEXT_NOT_WRITABLE') => {
		const error = new Error('Designer context does not have active write authority.');
		error.code = code;
		return error;
	};
	const recordFor = (handle) => {
		const record = records.get(handle);
		if (!record) throw refused('DESIGNER_CONTEXT_INVALID');
		return record;
	};
	const writable = (record) => {
		if (active !== record || record.status !== 'ACTIVE') throw refused();
	};

	const stage = ({ id, kind = 'profile', sessionOptions, document = null } = {}) => {
		if (typeof id !== 'string' || !id.trim() || nextGeneration === Number.MAX_SAFE_INTEGER) {
			throw refused('DESIGNER_CONTEXT_INVALID');
		}
		const factory = kind === 'profile' ? createProfileSession
			: kind === 'domain' ? createDomainSession : null;
		if (!factory || !sessionOptions || typeof sessionOptions !== 'object') {
			throw refused('DESIGNER_CONTEXT_INVALID');
		}
		const record = { id, status: 'STAGED', generation: ++nextGeneration, session: null };
		// Base alone owns this closure. Every low-level mutable store captures it.
		const session = factory(sessionOptions, () => writable(record));
		if (!session || typeof session.dispose !== 'function') {
			throw refused('DESIGNER_CONTEXT_INVALID');
		}
		record.session = session;
		const handle = Object.freeze({
			id,
			generation: record.generation,
			document: document === null ? null : Object.freeze({ ...document }),
			session,
			get status() { return record.status; },
		});
		records.set(handle, record);
		return handle;
	};

	const activateInitial = (handle) => {
		const record = recordFor(handle);
		if (active !== null || record.status !== 'STAGED') throw refused();
		active = record;
		record.status = 'ACTIVE';
		return true;
	};
	const lock = (handle) => {
		const record = recordFor(handle);
		if (record !== active || record.status !== 'ACTIVE') throw refused();
		record.status = 'LOCKED';
		return true;
	};
	const unlock = (handle) => {
		const record = recordFor(handle);
		if (record !== active || record.status !== 'LOCKED') throw refused();
		record.status = 'ACTIVE';
		return true;
	};
	/**
	 * Synchronous, reversible handoff. Source remains LOCKED until a separate
	 * verified retirement; at no point are both sessions writable.
	 */
	const switchTo = (source, target) => {
		const prior = recordFor(source);
		const next = recordFor(target);
		if (prior !== active || prior.status !== 'LOCKED' || next.status !== 'STAGED') throw refused();
		active = next;
		next.status = 'ACTIVE';
		return true;
	};
	const rollback = (source, target) => {
		const prior = recordFor(source);
		const next = recordFor(target);
		if (next !== active || next.status !== 'ACTIVE' || prior.status !== 'LOCKED') throw refused();
		next.status = 'INVALID';
		active = prior;
		prior.status = 'ACTIVE';
		return true;
	};
	const retire = (source, target) => {
		const prior = recordFor(source);
		const next = recordFor(target);
		if (next !== active || next.status !== 'ACTIVE' || prior.status !== 'LOCKED') throw refused();
		prior.status = 'RETIRED';
		return true;
	};
	const invalidate = (handle) => {
		const record = recordFor(handle);
		if (record.status === 'RETIRED' || record.status === 'INVALID') return false;
		record.status = 'INVALID';
		if (active === record) active = null;
		return true;
	};
	const isCurrent = (handle) => {
		const record = records.get(handle);
		return Boolean(record && active === record && record.status === 'ACTIVE');
	};
	const current = () => {
		// There is intentionally no public way to create or forge a handle
		// from a session. The coordinator retains its own handle reference.
		return active?.id ?? null;
	};
	return Object.freeze({
		stage, activateInitial, lock, unlock, switchTo, rollback, retire,
		invalidate, isCurrent, current,
	});
};
