import { sameValue } from './value.js';
import { prepareDesignerViewRebinding } from '../shell/context-rebinding.js';

const SLOT_NAMES = Object.freeze(['canvas', 'layers', 'inspector', 'controls', 'preview']);
const failure = (code, cause = null) => {
	const error = new Error(code, cause === null ? undefined : {cause});
	error.code = code;
	return error;
};
const synchronous = (value, code) => {
	if (value && typeof value.then === 'function') throw failure(code);
	return value;
};
const saveStateIsStable = (value) => value === 'IDLE' || value === 'STABLE' || value === 'SAVED';

/**
 * Base-managed mount points. Only detached, distinct target nodes are accepted.
 * Receipts retain original nodes until the transaction has been verified.
 */
export const prepareManagedMountSlots = (slots, nodes) => {
	if (!slots || !nodes || typeof slots !== 'object' || typeof nodes !== 'object'
		|| Object.keys(slots).sort().join('|') !== [...SLOT_NAMES].sort().join('|')
		|| Object.keys(nodes).sort().join('|') !== [...SLOT_NAMES].sort().join('|')) {
		throw failure('DESIGNER_MOUNT_CONTRACT_INVALID');
	}
	const records = [];
	const used = new Set();
	for (const key of SLOT_NAMES) {
		const host = slots[key];
		const incoming = nodes[key];
		if (!host || typeof host.replaceChildren !== 'function' || !host.childNodes
			|| !Array.isArray(incoming)) throw failure('DESIGNER_MOUNT_CONTRACT_INVALID');
		const original = Array.from(host.childNodes);
		for (const item of incoming) {
			if (!item || typeof item !== 'object' || used.has(item)
				|| item.isConnected === true || item.parentNode != null
				|| original.includes(item)) throw failure('DESIGNER_MOUNT_CONTRACT_INVALID');
			used.add(item);
		}
		records.push({key, host, original, incoming: [...incoming]});
	}
	let phase = 'PREPARED';
	return Object.freeze({
		commit() {
			if (phase !== 'PREPARED') throw failure('DESIGNER_MOUNT_COMMIT_REFUSED');
			phase = 'COMMITTING';
			for (const entry of records) entry.host.replaceChildren(...entry.incoming);
			phase = 'COMMITTED';
			return true;
		},
		verify() {
			return phase === 'COMMITTED' && records.every(({host, incoming}) => {
				const actual = Array.from(host.childNodes);
				return actual.length === incoming.length && actual.every((node, index) => node === incoming[index]);
			});
		},
		rollback() {
			if (phase === 'ROLLED_BACK') return true;
			if (phase === 'FINALIZED') throw failure('DESIGNER_MOUNT_ROLLBACK_REFUSED');
			if (phase !== 'PREPARED') {
				// Restore all slots, even if a previous slot throws.
				const errors = [];
				for (const {host, original} of [...records].reverse()) {
					try { host.replaceChildren(...original); }
					catch (error) { errors.push(error); }
				}
				if (errors.length) throw failure('DESIGNER_MOUNT_RECOVERY_REQUIRED', errors[0]);
			}
			phase = 'ROLLED_BACK';
			return true;
		},
		finalize() {
			if (phase !== 'COMMITTED') throw failure('DESIGNER_MOUNT_FINALIZE_REFUSED');
			phase = 'FINALIZED';
			return true;
		},
	});
};

/**
 * CX-02D internal coordinator. It is constructed only by editor.js with its
 * private managed authority, never by the public window.cbCore Designer API.
 * All asynchronous operations happen BEFORE the synchronous authority commit.
 */
export const createManagedContextTransaction = ({
	authority, state, shell, selection, slots,
	authorize, revalidate, load, decide, save, saveStatus, verify,
} = {}) => {
	for (const [name, callback] of Object.entries({authorize, revalidate, load, decide, saveStatus, verify})) {
		if (typeof callback !== 'function') throw new TypeError('Designer transaction requires Base ' + name + ' callback.');
	}
	if (save !== null && save !== undefined && typeof save !== 'function') {
		throw new TypeError('Designer transaction save callback must be a function or null.');
	}
	if (!authority || !state || !shell || !selection || !slots) throw failure('DESIGNER_TRANSACTION_INVALID');
	let pending = null;
	let serial = 0;
	let recoveryRequired = false;
	const status = () => Object.freeze({
		state: recoveryRequired ? 'RECOVERY_REQUIRED' : pending ? pending.phase : 'READY',
		currentId: state.current?.id ?? null,
		generation: state.current?.generation ?? null,
	});
	const isCurrent = (attempt) => pending === attempt && !attempt.controller.signal.aborted
		&& !recoveryRequired && state.current === attempt.source
		&& authority.isCurrent(attempt.source);
	const invalidate = (target) => {
		if (!target || target.status === 'RETIRED') return;
		authority.invalidate(target);
		authority.dispose(target);
	};
	const markRecovery = (attempt = pending) => {
		recoveryRequired = true;
		// Preserve data and revoke write authority even when the rollback failed
		// after the target was activated but before state.current changed.
		for (const handle of [state.current, attempt?.source, attempt?.target]) {
			if (handle?.status === 'ACTIVE') {
				try { authority.lock(handle); } catch (_) { /* Record remains under recovery. */ }
			}
		}
		pending = null;
	};
	const assertIdentity = (attempt) => {
		if (!isCurrent(attempt) || attempt.source.status !== 'ACTIVE'
			|| attempt.source.id !== attempt.sourceId
			|| attempt.source.generation !== attempt.sourceGeneration) {
			throw failure('DESIGNER_CONTEXT_STALE');
		}
	};
	const assertSaveStable = (source) => {
		const stateValue = synchronous(saveStatus(source), 'DESIGNER_SAVE_STATUS_INVALID');
		if (!saveStateIsStable(stateValue)) throw failure('DESIGNER_SAVE_UNSETTLED');
	};
	const cancelPending = () => {
		if (!pending || pending.phase !== 'PREPARE') return false;
		const attempt = pending;
		attempt.controller.abort();
		pending = null;
		// A staged target is never exposed or write-authorized.
		if (attempt.target?.status === 'STAGED') {
			try { invalidate(attempt.target); }
			catch (_) { markRecovery(attempt); }
		}
		return true;
	};
	const transition = async ({targetId} = {}) => {
		if (recoveryRequired) return Object.freeze({status:'recovery_required', code:'RECOVERY_REQUIRED'});
		if (pending) return Object.freeze({status:'refused', code:'DESIGNER_TRANSITION_BUSY'});
		if (typeof targetId !== 'string' || !targetId.trim()) return Object.freeze({status:'refused', code:'DESIGNER_TARGET_INVALID'});
		if (serial === Number.MAX_SAFE_INTEGER) return Object.freeze({status:'refused', code:'DESIGNER_GENERATION_EXHAUSTED'});
		const source = state.current;
		const attempt = {
			serial: ++serial, source, sourceId:source.id, sourceGeneration:source.generation,
			phase:'PREPARE', controller:new AbortController(), target:null,
		};
		pending = attempt;
		let view = null;
		let mounts = null;
		let retired = false;
		let swapped = false;
		let locked = false;
		let original = null;
		try {
			assertIdentity(attempt);
			if (targetId === source.id) throw failure('DESIGNER_TARGET_SAME_DOCUMENT');
			original = source.session.snapshot();
			assertSaveStable(source);
			const initialDirty = !sameValue(source.session.project(), source.session.baselineSnapshot());
			const allowed = await authorize(Object.freeze({
				sourceId:source.id, sourceGeneration:source.generation, targetId, dirty:initialDirty,
				signal:attempt.controller.signal, isCurrent:()=>isCurrent(attempt),
			}));
			assertIdentity(attempt);
			if (allowed !== true) throw failure('DESIGNER_TRANSITION_UNAUTHORIZED');
			const loaded = await load(Object.freeze({
				sourceId:source.id, sourceGeneration:source.generation, targetId,
				signal:attempt.controller.signal, isCurrent:()=>isCurrent(attempt),
			}));
			assertIdentity(attempt);
			if (!loaded?.spec || loaded.spec.id !== targetId || !loaded.nodes) {
				throw failure('DESIGNER_TARGET_IDENTITY_INVALID');
			}
			// Build the next canonical session without granting writes.
			attempt.target = authority.stage(loaded.spec);
			const target = attempt.target;
			if (target.status !== 'STAGED' || target.id !== targetId || target.generation <= source.generation) {
				throw failure('DESIGNER_TARGET_IDENTITY_INVALID');
			}
			if (!sameValue(original, source.session.snapshot())) throw failure('DESIGNER_SOURCE_CHANGED');
			const dirty = !sameValue(source.session.project(), source.session.baselineSnapshot());
			const choice = dirty ? await decide(Object.freeze({
				sourceId:source.id, sourceGeneration:source.generation, targetId, dirty:true,
				signal:attempt.controller.signal, isCurrent:()=>isCurrent(attempt),
			})) : 'clean';
			assertIdentity(attempt);
			if (choice === 'cancel') throw failure('DESIGNER_TRANSITION_CANCELLED');
			if (!['clean','save','discard'].includes(choice) || (dirty && choice === 'clean')) {
				throw failure('DESIGNER_DIRTY_DECISION_INVALID');
			}
			if (!sameValue(original, source.session.snapshot())) throw failure('DESIGNER_SOURCE_CHANGED');
			if (dirty && choice === 'save') {
				if (!save) throw failure('DESIGNER_SAVE_UNAVAILABLE');
				const checkpoint = source.session.captureSave();
				// No session/authority is provided to asynchronous persistence.
				// Only Base consumes its verified response and rebases History.
				const result = await save(Object.freeze({
					sourceId:source.id, generation:source.generation, targetId,
					submittedProject:checkpoint.submittedProject,
					signal:attempt.controller.signal, isCurrent:()=>isCurrent(attempt),
				}));
				assertIdentity(attempt);
				if (result?.status !== 'committed' || !result.baselineProject) {
					throw failure('DESIGNER_SAVE_NOT_VERIFIED');
				}
				try {
					source.session.rebase({saveToken:checkpoint.token,baselineProject:result.baselineProject});
				} catch (error) {
					throw failure('DESIGNER_SAVE_REBASE_UNVERIFIED', error);
				}
				if (!sameValue(source.session.project(),source.session.baselineSnapshot())) {
					throw failure('DESIGNER_SAVE_UNSETTLED');
				}
			}
			assertIdentity(attempt);
			if (choice !== 'save' && !sameValue(original, source.session.snapshot())) {
				throw failure('DESIGNER_SOURCE_CHANGED');
			}
			assertSaveStable(source);
			// Full contract preflight occurs while source remains ACTIVE.
			mounts = prepareManagedMountSlots(slots, loaded.nodes);
			view = prepareDesignerViewRebinding({
				shell, selection, sourceSession:source.session, targetSession:target.session,
			});
			const accepted = synchronous(revalidate(Object.freeze({
				sourceId:source.id, sourceGeneration:source.generation, targetId,
				targetGeneration:target.generation, dirty:!sameValue(source.session.project(),source.session.baselineSnapshot()),
				decision:choice, saveState:saveStatus(source),
			})), 'DESIGNER_REVALIDATION_ASYNC');
			assertIdentity(attempt);
			if (accepted !== true) throw failure('DESIGNER_REVALIDATION_REFUSED');
			assertSaveStable(source);
			// Everything after this point runs synchronously, without awaits.
			attempt.phase = 'COMMITTING';
			authority.lock(source);
			locked = true;
			authority.switchTo(source,target);
			swapped = true;
			mounts.commit();
			view.commit();
			if (!authority.isCurrent(target) || source.status !== 'LOCKED'
				|| view.verify() !== true || mounts.verify() !== true
				|| synchronous(verify(Object.freeze({
					sourceId:source.id, sourceGeneration:source.generation,
					targetId:target.id, targetGeneration:target.generation,
					session:target.session,
				})), 'DESIGNER_VERIFICATION_ASYNC') !== true) {
				throw failure('DESIGNER_CONTEXT_VERIFICATION_FAILED');
			}
			authority.retire(source,target);
			retired = true;
			state.current = target;
			// Retirement is irreversible. Cleanup errors must fail closed.
			view.finalize();
			mounts.finalize();
			authority.dispose(source);
			return Object.freeze({status:'committed', id:target.id, generation:target.generation});
		} catch (error) {
			if (retired) {
				markRecovery(attempt);
				return Object.freeze({status:'recovery_required', code:'RECOVERY_REQUIRED'});
			}
			const failures = [];
			if (view) try { view.rollback(); } catch (e) { failures.push(e); }
			if (mounts) try { mounts.rollback(); } catch (e) { failures.push(e); }
			if (swapped) try { authority.rollback(source,attempt.target); } catch (e) { failures.push(e); }
			else if (locked) try { authority.unlock(source); } catch (e) { failures.push(e); }
			if (attempt.target?.status === 'STAGED' || attempt.target?.status === 'INVALID') {
				try { invalidate(attempt.target); } catch (e) { failures.push(e); }
			}
			if (failures.length || error?.code === 'RECOVERY_REQUIRED'
				|| error?.code === 'DESIGNER_SAVE_REBASE_UNVERIFIED') {
				markRecovery(attempt);
				return Object.freeze({status:'recovery_required',code:'RECOVERY_REQUIRED'});
			}
			if (error?.code === 'DESIGNER_TRANSITION_CANCELLED'
				|| attempt.controller.signal.aborted) return Object.freeze({status:'cancelled'});
			return Object.freeze({status:'refused',code:error?.code || 'DESIGNER_TRANSITION_FAILED'});
		} finally {
			if (pending === attempt) pending = null;
		}
	};
	return Object.freeze({transition,cancelPending,status});
};
