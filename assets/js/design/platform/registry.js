import { immutableData, exactKeys, assertDigest } from './manifest.js';

const utf8 = new TextEncoder();
const byteCompare = (a, b) => {
	const left = utf8.encode(a), right = utf8.encode(b);
	for (let i = 0; i < Math.min(left.length, right.length); i++) {
		if (left[i] !== right[i]) return left[i] - right[i];
	}
	return left.length - right.length;
};

function encode(value) {
	if (value === null) return 'n';
	if (value === true) return 't';
	if (value === false) return 'f';
	if (typeof value === 'string') return 's' + utf8.encode(value).length + ':' + value;
	if (typeof value === 'number') {
		if (!Number.isFinite(value)) throw new TypeError('REGISTRY_INVALID');
		const repr = String(value);
		return (Number.isInteger(value) ? 'i' : 'd') + utf8.encode(repr).length + ':' + repr;
	}
	if (Array.isArray(value)) return 'a' + value.length + ':' + value.map(encode).join('');
	if (value && typeof value === 'object') {
		const keys = Object.keys(value).sort(byteCompare);
		return 'o' + keys.length + ':' + keys.map((key) => encode(key) + encode(value[key])).join('');
	}
	throw new TypeError('REGISTRY_INVALID');
}

/** Same domain-separated length-prefixed UTF-8 digest as PHP FrozenRegistry. */
export async function canonicalDigest(data, domain) {
	if (!globalThis.crypto?.subtle) throw new TypeError('ARTIFACT_RUNTIME_MISMATCH');
	const bytes = utf8.encode(encode(domain) + encode(data));
	const sum = new Uint8Array(await globalThis.crypto.subtle.digest('SHA-256', bytes));
	return 'sha256:' + Array.from(sum, (part) => part.toString(16).padStart(2, '0')).join('');
}

const withoutFingerprint = (value) => {
	const {fingerprint: _fingerprint, ...rest} = value;
	return rest;
};

/**
 * Accepted read-only profile/field views from ONE PHP FrozenRegistry. Runtime
 * ESM namespaces are supplied separately by the trusted static WP importer.
 */
export function createRegistryView(bootstrap, runtimeModules) {
	const b = immutableData(bootstrap);
	exactKeys(b, ['contractVersion', 'registryFingerprint', 'profileId', 'identity', 'profile', 'schemaSnapshot',
		'persistedFieldFacet', 'modules', 'evidence']);
	if (b.contractVersion !== 1 || b.profileId !== b.profile?.id) throw new TypeError('REGISTRY_INVALID');
	assertDigest(b.registryFingerprint);
	if (!Array.isArray(b.modules) || !b.schemaSnapshot || !b.persistedFieldFacet) throw new TypeError('REGISTRY_INVALID');
	const schema = exactKeys(b.schemaSnapshot, ['contractVersion','schemaGeneration','profileId','projectSchema',
		'providers','designTypes','nodeTypes','domainSchemas','fingerprint']);
	const fields = exactKeys(b.persistedFieldFacet, ['contractVersion','schemaGeneration','profileId',
		'projectSchema','registryFingerprint','projectSchemaFingerprint','fieldDescriptors','fingerprint']);
	if (schema.contractVersion !== 1 || fields.contractVersion !== 1 || fields.schemaGeneration !== 1
		|| schema.schemaGeneration !== 1 || schema.profileId !== b.profileId || fields.profileId !== b.profileId
		|| schema.projectSchema !== b.profile.projectSchema || fields.projectSchema !== schema.projectSchema
		|| fields.registryFingerprint !== b.registryFingerprint || fields.projectSchemaFingerprint !== schema.fingerprint
		|| !Array.isArray(fields.fieldDescriptors)) throw new TypeError('REGISTRY_INVALID');
	assertDigest(schema.fingerprint);
	assertDigest(fields.fingerprint);
	const runtime = new Map();
	const seenPackagePaths = new Set();
	if (!(runtimeModules instanceof Map)) throw new TypeError('HOST_MODULE_MISSING');
	for (const module of b.modules) {
		exactKeys(module, ['id','owner','dependencies','exports','stamp','packagePath']);
		if (typeof module.packagePath !== 'string'
			|| !/^(?:base|consumer)\/(?:[A-Za-z0-9_.@/-]+)\.js$/.test(module.packagePath)
			|| module.packagePath.includes('//')
			|| module.packagePath.split('/').some((part) => part === '.' || part === '..')) {
			throw new TypeError('REGISTRY_INVALID');
		}
		if (runtime.has(module.id) || seenPackagePaths.has(module.packagePath)) throw new TypeError('REGISTRY_COLLISION');
		seenPackagePaths.add(module.packagePath);
		runtime.set(module.id, null);
		if (runtimeModules.has(module.id)) runtime.set(module.id, runtimeModules.get(module.id));
	}
	const view = Object.freeze({
		contractVersion: 1,
		fingerprint: b.registryFingerprint,
		profileId: b.profileId,
		identity: b.identity,
		profile: b.profile,
		modules: b.modules,
		evidence: b.evidence,
		schemaSnapshot: schema,
		persistedFieldFacet(id) { return id === b.profileId ? fields : null; },
		projectSchemaSnapshot(id) { return id === b.profileId ? schema : null; },
		moduleNamespace(id) { return runtime.get(id) ?? null; },
		async verify() {
			if (await canonicalDigest(withoutFingerprint(schema), 'RegistrySnapshotV1') !== schema.fingerprint
				|| await canonicalDigest(withoutFingerprint(fields), 'PersistedFieldFacetV1') !== fields.fingerprint) {
				throw new TypeError('ARTIFACT_RUNTIME_MISMATCH');
			}
			return true;
		},
	});
	return view;
}
