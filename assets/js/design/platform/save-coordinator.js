import { canonicalSaveJSON, deepFreezeSaveJSON, strictJsonCopy } from './persisted-fields.js';

const hex = (bytes) => [...new Uint8Array(bytes)]
	.map((byte) => byte.toString(16).padStart(2, '0')).join('');

async function sha256(value) {
	if (!globalThis.crypto?.subtle) throw new Error('SAVE_CRYPTO_UNAVAILABLE');
	return hex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonicalSaveJSON(value))));
}

const revision = (value) => typeof value === 'string'
	&& /^(0|[1-9]\d*)$/.test(value)
	&& (value.length < 19 || (value.length === 19 && value <= '9223372036854775807'));

const equal = (a, b) => canonicalSaveJSON(a) === canonicalSaveJSON(b);
const requireSnapshot = (host, fields, revisionOverride = null) => {
	const context = host.saveContext();
	if (!context || context.ready !== true || context.canSave !== true
		|| !revision(context.storeRevision) || !Number.isSafeInteger(context.contextEpoch)
		|| !Number.isSafeInteger(context.blogId) || context.blogId < 1
		|| !Number.isSafeInteger(context.actorId) || context.actorId < 1
		|| !/^[a-f0-9]{64}$/.test(context.registryFingerprint || '')) {
		throw new Error('SAVE_NOT_READY');
	}
	const captured = host.captureSave();
	if (!captured || !captured.token || !captured.submittedProject || !Number.isSafeInteger(captured.revision)) {
		throw new Error('SAVE_INVALID');
	}
	const fieldCheckpoint = fields.snapshot();
	if (context.registryFingerprint !== fields.registryFingerprint) {
		throw new Error('SAVE_CONTEXT_CHANGED');
	}
	return Object.freeze({
		saveToken: captured.token, // PRIVATE: never passed to serialize/submit.
		project: deepFreezeSaveJSON(captured.submittedProject),
		projectRevision: captured.revision,
		fields: fieldCheckpoint.fields,
		fieldRevision: fieldCheckpoint.fieldRevision,
		context: Object.freeze({ ...context, storeRevision: revisionOverride ?? context.storeRevision }),
	});
};

function correlation(receipt, evidence) {
	return receipt && receipt.contractVersion === 1 && receipt.status === 'committed'
		&& receipt.operationId === evidence.operationId
		&& receipt.blogId === String(evidence.blogId)
		&& receipt.actorId === String(evidence.actorId)
		&& receipt.resourceType === evidence.resourceType
		&& receipt.resourceKey === evidence.resourceKey
		&& receipt.profileId === evidence.profileId
		&& receipt.projectSchema === evidence.projectSchema
		&& receipt.registryFingerprint === evidence.registryFingerprint
		&& receipt.previousStoreRevision === evidence.expectedStoreRevision
		&& receipt.requestDigest === evidence.requestDigest
		&& receipt.submittedProjectDigest === evidence.submittedProjectDigest
		&& receipt.submittedFieldsDigest === evidence.submittedFieldsDigest
		&& revision(receipt.acceptedStoreRevision) && receipt.acceptedStoreRevision !== '0';
}

/**
 * Sole A2-06 Save coordinator. Host retains lifecycle/command/history/renderer.
 * The adapter is trusted transport only; server retains all authority.
 *
 * Required private Host bridge:
 * saveContext(), captureSave(), rebase({saveToken,baselineProject,source}),
 * validateAcceptedProject(project), isProjectDirty().
 *
 * SaveAdapterV1:
 * serialize(frozenCheckpoint), submit(payload,opts), lookupOperation(evidence,opts),
 * verifyReceipt(receipt,evidence), canSave(), destroy().
 */
export function createSaveCoordinator({ host, fields, adapter }) {
	if (!host || !fields || !adapter
		|| ['saveContext', 'captureSave', 'rebase', 'validateAcceptedProject', 'isProjectDirty']
			.some((key) => typeof host[key] !== 'function')
		|| ['serialize', 'submit', 'lookupOperation', 'verifyReceipt', 'canSave', 'destroy']
			.some((key) => typeof adapter[key] !== 'function')) {
		throw new TypeError('SAVE_INVALID: trusted Host/SaveAdapterV1 required.');
	}
	let state = 'READY_CLEAN';
	let pending = null;
	let flight = null;
	let destroyed = false;
	let acceptedStoreRevision = null;
	const listeners = new Set();
	const lifecycle = () => Object.freeze({
		state,
		dirty: host.isProjectDirty() || fields.snapshot().dirty,
		operationId: pending?.evidence.operationId ?? null,
		blocked: blocksContextTransition(),
	});
	const notify = (next) => {
		state = next;
		for (const listener of [...listeners]) {
			if (!listeners.has(listener)) continue;
			try { listener(lifecycle()); } catch (_) { /* Observers cannot mutate Save truth. */ }
		}
	};
	const blocksContextTransition = () => [
		'CHECKPOINTED', 'SUBMITTING', 'RECEIPT_VERIFIED',
		'UNKNOWN', 'RECONCILING', 'PERSISTED_UNSYNCHRONIZED',
	].includes(state);

	const verifyCommitted = async (receipt, evidence) => {
		if (!correlation(receipt, evidence)) throw new Error('SAVE_RECEIPT_INVALID');
		if (!receipt.acceptedProject || !receipt.acceptedFields) throw new Error('SAVE_RECEIPT_INVALID');
		const acceptedProject = strictJsonCopy(receipt.acceptedProject);
		const acceptedFields = fields.validateAccepted(receipt.acceptedFields);
		host.validateAcceptedProject(acceptedProject);
		const [acceptedProjectDigest, acceptedFieldsDigest, acceptedDigest] = await Promise.all([
			sha256(acceptedProject), sha256(acceptedFields),
			sha256({
				contractVersion: 1, actorId: String(evidence.actorId), blogId: String(evidence.blogId),
				expectedStoreRevision: evidence.expectedStoreRevision,
				fields: acceptedFields, operationId: evidence.operationId,
				profileId: evidence.profileId, project: acceptedProject,
				projectSchema: evidence.projectSchema, registryFingerprint: evidence.registryFingerprint,
				resourceKey: evidence.resourceKey, resourceType: evidence.resourceType,
			}),
		]);
		if (acceptedProjectDigest !== receipt.acceptedProjectDigest
			|| acceptedFieldsDigest !== receipt.acceptedFieldsDigest
			|| acceptedDigest !== receipt.acceptedDigest
			|| adapter.verifyReceipt(receipt, evidence) !== true) {
			throw new Error('SAVE_RECEIPT_INVALID');
		}
		return { acceptedProject, acceptedFields };
	};

	const accept = async (receipt) => {
		const operation = pending;
		if (!operation) throw new Error('SAVE_INVALID');
		const { checkpoint, evidence } = operation;
		const { acceptedProject, acceptedFields } = await verifyCommitted(receipt, evidence);
		// No async boundary between canonical rebase and baseline promotion.
		notify('RECEIPT_VERIFIED');
		try {
			if (host.saveContext()?.contextEpoch !== checkpoint.context.contextEpoch) {
				throw new Error('SAVE_CONTEXT_CHANGED');
			}
			host.rebase({
				saveToken: checkpoint.saveToken,
				baselineProject: acceptedProject,
				source: 'save-baseline',
			});
		} catch (error) {
			pending = Object.freeze({ ...operation, durableReceipt: receipt });
			notify('PERSISTED_UNSYNCHRONIZED');
			return Object.freeze({ status: 'persisted-unsynchronized', reason: error?.code || 'SAVE_REPLAY_CONFLICT' });
		}
		fields.commitBaseline(acceptedFields);
		acceptedStoreRevision = receipt.acceptedStoreRevision;
		pending = null;
		const dirty = host.isProjectDirty() || fields.snapshot().dirty;
		notify(dirty ? 'READY_DIRTY' : 'READY_CLEAN');
		return Object.freeze({ status: 'committed', dirty, storeRevision: acceptedStoreRevision });
	};

	const performSave = async () => {
		if (destroyed || blocksContextTransition() || pending) return Object.freeze({ status: 'refused', code: 'SAVE_PENDING_CONTEXT_BLOCKED' });
		if (adapter.canSave() !== true) return Object.freeze({ status: 'refused' });
		const checkpoint = requireSnapshot(host, fields, acceptedStoreRevision); // One synchronous joint capture.
		const { context } = checkpoint;
		const operationId = crypto.randomUUID().replaceAll('-', '');
		const fieldsJSON = checkpoint.fields;
		const projectJSON = checkpoint.project;
		const request = {
			contractVersion: 1,
			actorId: String(context.actorId), blogId: String(context.blogId),
			expectedStoreRevision: context.storeRevision,
			fields: fieldsJSON, operationId, profileId: context.profileId,
			project: projectJSON, projectSchema: context.projectSchema,
			registryFingerprint: context.registryFingerprint,
			resourceKey: context.resourceKey, resourceType: context.resourceType,
		};
		const frozen = Object.freeze({
			context: checkpoint.context,
			project: checkpoint.project, fields: checkpoint.fields,
			projectRevision: checkpoint.projectRevision, fieldRevision: checkpoint.fieldRevision,
			operationId, expectedStoreRevision: context.storeRevision,
		});
		notify('CHECKPOINTED');
		const [requestDigest, submittedProjectDigest, submittedFieldsDigest] = await Promise.all([
			sha256(request), sha256(projectJSON), sha256(fieldsJSON),
		]);
		if (host.saveContext()?.contextEpoch !== context.contextEpoch) {
			notify('READY_DIRTY');
			return Object.freeze({ status: 'refused', code: 'SAVE_CONTEXT_CHANGED' });
		}
		const evidence = Object.freeze({
			operationId, blogId: context.blogId, actorId: context.actorId,
			resourceType: context.resourceType, resourceKey: context.resourceKey,
			profileId: context.profileId, projectSchema: context.projectSchema,
			registryFingerprint: context.registryFingerprint,
			expectedStoreRevision: context.storeRevision,
			requestDigest, submittedProjectDigest, submittedFieldsDigest,
		});
		const payload = adapter.serialize(frozen);
		if (!payload || !equal(payload.project, projectJSON) || !equal(payload.fields, fieldsJSON)
			|| payload.operationId !== operationId
			|| payload.expectedStoreRevision !== context.storeRevision) {
			notify('READY_DIRTY');
			throw new Error('SAVE_INVALID: adapter serialization changed checkpoint.');
		}
		pending = Object.freeze({ checkpoint, evidence });
		notify('SUBMITTING');
		let response;
		try {
			response = await adapter.submit(payload, {
				expectedStoreRevision: context.storeRevision,
				idempotencyKey: operationId,
				signal: new AbortController().signal,
			});
		} catch (_) {
			notify('UNKNOWN');
			return Object.freeze({ status: 'unknown', operationId });
		}
		if (response?.status === 'committed') {
			try { return await accept(response.receipt ?? response); }
			catch (_) {
				// HTTP 200 and malformed receipt cannot imply a clean baseline.
				notify('UNKNOWN');
				return Object.freeze({ status: 'unknown', reason: 'SAVE_RECEIPT_INVALID', operationId });
			}
		}
		if (response?.status === 'refused' || response?.status === 'conflict'
			|| response?.status === 'idempotency_reused') {
			pending = null; // Authenticated server reported a rolled-back no-write.
			notify('READY_DIRTY');
			return Object.freeze({ status: response.status });
		}
		notify('UNKNOWN');
		return Object.freeze({ status: 'unknown', operationId });
	};

	const recover = async () => {
		if (destroyed || state !== 'UNKNOWN' || !pending) return Object.freeze({ status: 'refused', code: 'SAVE_PENDING_CONTEXT_BLOCKED' });
		notify('RECONCILING');
		let response;
		try {
			response = await adapter.lookupOperation(pending.evidence, {
				signal: new AbortController().signal,
			});
		} catch (_) {
			notify('UNKNOWN');
			return Object.freeze({ status: 'unknown' });
		}
		if (response?.status === 'committed') {
			try { return await accept(response.receipt ?? response); }
			catch (_) {
				notify('UNKNOWN');
				return Object.freeze({ status: 'unknown', reason: 'SAVE_RECEIPT_INVALID' });
			}
		}
		// Absence/missing row/HTTP 404/timeouts do not establish no-write.
		notify('UNKNOWN');
		return Object.freeze({ status: 'unknown' });
	};

	// Exact countersigned SaveCoordinatorV1 Host-private callable surface.
	return Object.freeze({
		async save() {
			if (flight) return Object.freeze({ status: 'refused', code: 'SAVE_PENDING_CONTEXT_BLOCKED' });
			// An UNKNOWN Save may only lookup its ORIGINAL operation, never
			// generate another ID or re-submit automatically.
			flight = state === 'UNKNOWN' && pending ? recover() : performSave();
			try {
				return await flight;
			} catch (error) {
				if (pending) {
					notify('UNKNOWN');
					return Object.freeze({ status: 'unknown', code: 'SAVE_UNKNOWN' });
				}
				notify('READY_DIRTY');
				return Object.freeze({ status: 'refused', code: error?.code || 'SAVE_REFUSED' });
			} finally {
				flight = null;
			}
		},
		status() { return lifecycle(); },
		transitionGate(action) {
			if (action !== 'switch' && action !== 'unmount') {
				return Object.freeze({ allowed: false, state, code: 'SAVE_PENDING_CONTEXT_BLOCKED' });
			}
			if (blocksContextTransition() || flight || pending) {
				return Object.freeze({ allowed: false, state, code: 'SAVE_PENDING_CONTEXT_BLOCKED' });
			}
			// Dirty-but-known user confirmation remains the Host's responsibility.
			return Object.freeze({ allowed: true, state });
		},
		subscribe(listener) {
			if (destroyed || typeof listener !== 'function') throw new TypeError('SAVE_INVALID');
			listeners.add(listener);
			try { listener(lifecycle()); } catch (_) { /* notification never changes state */ }
			return () => { listeners.delete(listener); };
		},
		destroy() {
			if (blocksContextTransition() || flight || pending) {
				const error = new Error('SAVE_PENDING_CONTEXT_BLOCKED');
				error.code = 'SAVE_PENDING_CONTEXT_BLOCKED';
				throw error;
			}
			destroyed = true;
			listeners.clear();
			adapter.destroy();
			fields.destroy();
		},
	});
}
