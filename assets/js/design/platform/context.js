import { immutableData, exactKeys, assertDigest } from './manifest.js';

/** Inspect, do not mint, server-issued actor/resource/context identity. */
export function inspectTrustedContext(bootstrap, registryView) {
	const value = immutableData(bootstrap);
	exactKeys(value, ['contractVersion','registryFingerprint','profileId','identity','profile',
		'schemaSnapshot','persistedFieldFacet','components','controls','modules','evidence']);
	if (value.contractVersion !== 1 || !Array.isArray(value.components) || !Array.isArray(value.controls)
		|| value.registryFingerprint !== registryView.fingerprint
		|| value.profileId !== registryView.profileId || value.profile.id !== value.profileId) {
		throw new TypeError('HOST_CONTEXT_REFUSED');
	}
	const identity = value.identity;
	if (!identity || typeof identity !== 'object' || Array.isArray(identity)) throw new TypeError('HOST_CONTEXT_REFUSED');
	for (const key of ['siteId', 'actorId', 'resourceType', 'resourceId', 'contextEpoch']) {
		if (!Object.hasOwn(identity, key) || !['string','number'].includes(typeof identity[key])) {
			throw new TypeError('HOST_CONTEXT_REFUSED');
		}
	}
	if (!Number.isSafeInteger(identity.contextEpoch) || identity.contextEpoch < 0
		|| !Number.isSafeInteger(identity.siteId) || identity.siteId < 1
		|| !Number.isSafeInteger(identity.actorId) || identity.actorId < 1
		|| typeof identity.resourceType !== 'string' || !identity.resourceType
		|| typeof identity.resourceId !== 'string' || !identity.resourceId) throw new TypeError('HOST_CONTEXT_REFUSED');
	const context = value.evidence?.context;
	if (!context || typeof context !== 'object' || context.siteBlogId !== identity.siteId
		|| context.actorId !== identity.actorId || context.resourceType !== identity.resourceType
		|| context.resourceKey !== identity.resourceId || context.profileId !== value.profileId
		|| context.registryFingerprint !== value.registryFingerprint) {
		throw new TypeError('HOST_CONTEXT_REFUSED');
	}
	assertDigest(value.registryFingerprint);
	return identity;
}

export function contextIdentityKey(identity, profileId) {
	return JSON.stringify([String(identity.siteId), String(identity.actorId),
		String(identity.resourceId), identity.contextEpoch, profileId]);
}

export function nextEpoch(current, candidate) {
	if (!Number.isSafeInteger(candidate) || candidate <= current) throw new TypeError('HOST_CONTEXT_REFUSED');
	return candidate;
}
