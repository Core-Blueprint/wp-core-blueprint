import { immutableData, exactKeys, assertDigest } from './manifest.js';

/** Inspect, do not mint, server-issued actor/resource/context identity. */
export function inspectTrustedContext(bootstrap, registryView) {
	const value = immutableData(bootstrap);
	exactKeys(value, ['contractVersion','registryFingerprint','profileId','identity','profile',
		'schemaSnapshot','persistedFieldFacet','modules','evidence']);
	if (value.contractVersion !== 1 || value.registryFingerprint !== registryView.fingerprint
		|| value.profileId !== registryView.profileId || value.profile.id !== value.profileId) {
		throw new TypeError('HOST_CONTEXT_REFUSED');
	}
	const identity = value.identity;
	if (!identity || typeof identity !== 'object' || Array.isArray(identity)) throw new TypeError('HOST_CONTEXT_REFUSED');
	for (const key of ['siteId', 'actorId', 'resourceId', 'contextEpoch']) {
		if (!Object.hasOwn(identity, key) || !['string','number'].includes(typeof identity[key])) {
			throw new TypeError('HOST_CONTEXT_REFUSED');
		}
	}
	if (!Number.isSafeInteger(identity.contextEpoch) || identity.contextEpoch < 0) throw new TypeError('HOST_CONTEXT_REFUSED');
	if (typeof identity.resourceId !== 'string' || !identity.resourceId.length) throw new TypeError('HOST_CONTEXT_REFUSED');
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
