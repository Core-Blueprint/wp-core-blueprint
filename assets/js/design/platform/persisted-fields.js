import { validatePersistedFieldsV1 } from '../core/stable-schema.js';

/**
 * Canonical UTF-8 JSON for the pre-frozen integer-safe numeric profile.
 * PHP SaveEnvelope uses the same sorted UTF-8 key and JSON scalar encoding.
 * Fractional/exponent numeric parity and measured field budgets remain an
 * explicit StableSchemaFieldsV1 integration dependency, never silently guessed.
 */
const encoder = new TextEncoder();
const utf8Compare = (a, b) => {
	const left = encoder.encode(a);
	const right = encoder.encode(b);
	for (let i = 0; i < Math.min(left.length, right.length); i += 1) {
		if (left[i] !== right[i]) return left[i] - right[i];
	}
	return left.length - right.length;
};

const forbidden = new Set(['__proto__', 'constructor', 'prototype']);

export function strictJsonCopy(input, depth = 0, seen = new WeakSet()) {
	if (depth > 64) throw new RangeError('SCHEMA_LIMIT_EXCEEDED: field JSON nesting.');
	if (input === null || typeof input === 'string' || typeof input === 'boolean') return input;
	if (typeof input === 'number') {
		if (!Number.isSafeInteger(input)) throw new TypeError('SCHEMA_DOMAIN_INVALID: numeric ABI not frozen.');
		return input;
	}
	if (!input || typeof input !== 'object') throw new TypeError('SCHEMA_DOMAIN_INVALID: non-JSON field value.');
	if (seen.has(input)) throw new TypeError('SCHEMA_DOMAIN_INVALID: circular field value.');
	const prototype = Object.getPrototypeOf(input);
	if (!Array.isArray(input) && prototype !== Object.prototype && prototype !== null) {
		throw new TypeError('SCHEMA_DOMAIN_INVALID: non-plain JSON object.');
	}
	seen.add(input);
	const descriptors = Object.getOwnPropertyDescriptors(input);
	let result;
	if (Array.isArray(input)) {
		if (Object.keys(descriptors).some((key) => key !== 'length' && (!/^(0|[1-9]\d*)$/.test(key) || +key >= input.length))) {
			throw new TypeError('SCHEMA_DOMAIN_INVALID: non-JSON array property.');
		}
		result = [];
		for (let i = 0; i < input.length; i += 1) {
			const descriptor = descriptors[String(i)];
			if (!descriptor || !Object.hasOwn(descriptor, 'value')) {
				throw new TypeError('SCHEMA_DOMAIN_INVALID: sparse/accessor array.');
			}
			result.push(strictJsonCopy(descriptor.value, depth + 1, seen));
		}
	} else {
		result = Object.create(null);
		for (const key of Object.keys(descriptors).sort(utf8Compare)) {
			if (forbidden.has(key)) throw new TypeError('SCHEMA_DOMAIN_INVALID: forbidden JSON key.');
			const descriptor = descriptors[key];
			if (!descriptor.enumerable || !Object.hasOwn(descriptor, 'value')) {
				throw new TypeError('SCHEMA_DOMAIN_INVALID: hidden/accessor JSON key.');
			}
			result[key] = strictJsonCopy(descriptor.value, depth + 1, seen);
		}
	}
	seen.delete(input);
	return result;
}

function stringifyCanonical(value) {
	if (value === null || typeof value !== 'object') return JSON.stringify(value);
	if (Array.isArray(value)) return '[' + value.map(stringifyCanonical).join(',') + ']';
	return '{' + Object.keys(value).sort(utf8Compare)
		.map((key) => JSON.stringify(key) + ':' + stringifyCanonical(value[key]))
		.join(',') + '}';
}

export function canonicalSaveJSON(value) {
	return stringifyCanonical(strictJsonCopy(value));
}

const freezeDeep = (value) => {
	if (value && typeof value === 'object') {
		for (const key of Object.keys(value)) freezeDeep(value[key]);
		Object.freeze(value);
	}
	return value;
};

const validated = (values, { profileId, projectSchema, registrySnapshot, pointerBase }) => {
	const result = validatePersistedFieldsV1(values, {
		profileId, projectSchema, registrySnapshot, pointerBase,
	});
	if (result?.valid !== true || !Array.isArray(result.diagnostics) || result.diagnostics.length !== 0) {
		throw new TypeError('SCHEMA_DOMAIN_INVALID: complete persisted field validation failed.');
	}
};

/**
 * One context-bound persisted field store; never a second registry or history.
 *
 * registrySnapshot must be Luna 3's trusted, frozen field-facet validation
 * context from its single FrozenRegistry, not a browser-created descriptor map.
 */
export function createPersistedFields({
	profileId,
	projectSchema,
	projectRegistrySnapshot,
	fieldValidationContext,
	fieldFacet,
	initialFields,
}) {
	if (!fieldFacet || !Object.isFrozen(fieldFacet)
		|| fieldFacet.contractVersion !== 1
		|| fieldFacet.profileId !== profileId
		|| fieldFacet.projectSchema !== projectSchema
		|| !Array.isArray(fieldFacet.fieldDescriptors)
		|| fieldFacet.schemaGeneration !== 1
		|| !/^[a-f0-9]{64}$/.test(fieldFacet.fingerprint || '')
		|| !/^[a-f0-9]{64}$/.test(fieldFacet.registryFingerprint || '')
		|| !projectRegistrySnapshot || !Object.isFrozen(projectRegistrySnapshot)
		|| fieldFacet.projectSchemaFingerprint !== projectRegistrySnapshot.fingerprint
		|| !fieldValidationContext || !Object.isFrozen(fieldValidationContext)) {
		throw new TypeError('REGISTRY_INVALID: authoritative field facet required.');
	}
	const saveKeys = fieldFacet.fieldDescriptors.map((definition) => definition.saveKey);
	if (new Set(saveKeys).size !== saveKeys.length) {
		throw new TypeError('REGISTRY_COLLISION: duplicate persisted saveKey.');
	}
	const options = { profileId, projectSchema, registrySnapshot: fieldValidationContext };
	const normalize = (source, pointerBase) => {
		const map = strictJsonCopy(source);
		if (Array.isArray(map) || map === null || typeof map !== 'object'
			|| Object.keys(map).length !== saveKeys.length
			|| saveKeys.some((key) => !Object.hasOwn(map, key))) {
			throw new TypeError('SCHEMA_DOMAIN_INVALID: incomplete persisted fields.');
		}
		validated(map, { ...options, pointerBase });
		return freezeDeep(map);
	};
	let current = normalize(initialFields, '/persistedFields');
	let baseline = current;
	let fieldRevision = 0;
	let destroyed = false;
	const active = () => {
		if (destroyed) throw new Error('SAVE_CONTEXT_CHANGED');
	};
	return Object.freeze({
		get fieldRevision() { return fieldRevision; },
		get registryFingerprint() { return fieldFacet.registryFingerprint; },
		get fieldFacetFingerprint() { return fieldFacet.fingerprint; },
		snapshot() {
			active();
			return Object.freeze({
				fields: current,
				fieldRevision,
				acceptedFieldBaseline: baseline,
				dirty: canonicalSaveJSON(current) !== canonicalSaveJSON(baseline),
			});
		},
		set(saveKey, value) {
			active();
			if (!saveKeys.includes(saveKey)) throw new TypeError('SCHEMA_DOMAIN_INVALID: unregistered persisted field.');
			if (fieldRevision >= Number.MAX_SAFE_INTEGER) throw new RangeError('SCHEMA_LIMIT_EXCEEDED: field revision overflow.');
			const staged = strictJsonCopy(current);
			staged[saveKey] = strictJsonCopy(value);
			const next = normalize(staged, '/persistedFields');
			if (canonicalSaveJSON(next) === canonicalSaveJSON(current)) return false;
			current = next;
			fieldRevision += 1;
			return true;
		},
		validateAccepted(acceptedFields) {
			active();
			return normalize(acceptedFields, '/acceptedFields');
		},
		commitBaseline(validatedAcceptedFields) {
			active();
			// Prevalidated, complete accepted map. Never rewrite newer current fields.
			const accepted = normalize(validatedAcceptedFields, '/acceptedFields');
			baseline = accepted;
			return canonicalSaveJSON(current) !== canonicalSaveJSON(baseline);
		},
		destroy() { destroyed = true; },
	});
}
