/**
 * Data-only trusted server bootstrap transport. This module never registers
 * profiles, loads URLs, or confers permissions from project JSON.
 */
export function immutableData(input) {
	const seen = new WeakSet();
	const visit = (value, depth) => {
		if (depth > 64) throw new TypeError('REGISTRY_INVALID');
		if (value === null || typeof value === 'string' || typeof value === 'boolean'
			|| (typeof value === 'number' && Number.isFinite(value))) return value;
		if (!value || typeof value !== 'object' || seen.has(value)
			|| (Object.getPrototypeOf(value) !== Object.prototype && Object.getPrototypeOf(value) !== null && !Array.isArray(value))) {
			throw new TypeError('REGISTRY_INVALID');
		}
		seen.add(value);
		const descriptors = Object.getOwnPropertyDescriptors(value);
		const result = Array.isArray(value) ? [] : Object.create(null);
		for (const [key, descriptor] of Object.entries(descriptors)) {
			if (key === '__proto__' || key === 'constructor' || key === 'prototype'
				|| !Object.hasOwn(descriptor, 'value') || typeof descriptor.value === 'function') {
				throw new TypeError('REGISTRY_INVALID');
			}
			if (Array.isArray(value) && key === 'length') continue;
			if (Array.isArray(value) && !/^(0|[1-9][0-9]*)$/.test(key)) throw new TypeError('REGISTRY_INVALID');
			Object.defineProperty(result, key, {value: visit(descriptor.value, depth + 1), enumerable: true, writable: false, configurable: false});
		}
		if (Array.isArray(value) && Object.keys(result).length !== value.length) throw new TypeError('REGISTRY_INVALID');
		seen.delete(value);
		return Object.freeze(result);
	};
	return visit(input, 0);
}

export function exactKeys(value, required, optional = []) {
	if (!value || typeof value !== 'object' || Array.isArray(value)) throw new TypeError('REGISTRY_INVALID');
	const keys = Object.keys(value);
	if (required.some((key) => !Object.hasOwn(value, key))
		|| keys.some((key) => !required.includes(key) && !optional.includes(key))) throw new TypeError('REGISTRY_INVALID');
	return value;
}

export const digestPattern = /^sha256:[0-9a-f]{64}$/;
export function assertDigest(value) {
	if (typeof value !== 'string' || !digestPattern.test(value)) throw new TypeError('ARTIFACT_RUNTIME_MISMATCH');
	return value;
}
