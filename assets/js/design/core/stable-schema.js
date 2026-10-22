import { inspectJsonValue, InvalidDesignValueError, isPlainObject } from './value.js';

const CODES = Object.freeze({
	generation: 'SCHEMA_UNSUPPORTED_GENERATION',
	provider: 'SCHEMA_PROVIDER_UNKNOWN',
	node: 'SCHEMA_NODE_INVALID',
	domain: 'SCHEMA_DOMAIN_INVALID',
	limit: 'SCHEMA_LIMIT_EXCEEDED',
});
const PROVIDER = /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)+$/;
const NODE_TYPE = /^[a-z][a-z0-9_]*(?:(?:-|\.)[a-z0-9_]+)*$/;
const escapeSegment = (segment) => String(segment).replace(/~/g, '~0').replace(/\//g, '~1');
const own = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
const ownValue = (object, key) => own(object, key) ? Object.getOwnPropertyDescriptor(object, key).value : undefined;
const keysMatch = (object, keys) => isPlainObject(object)
	&& Object.keys(object).length === keys.length && keys.every((key) => own(object, key));
const sorted = (entries) => entries.every((entry, index) => isPlainObject(entry)
	&& typeof ownValue(entry, 'id') === 'string'
	&& (index === 0 || ownValue(entries[index - 1], 'id') < ownValue(entry, 'id')));
const schemaKeys = new Set([
	'type', 'properties', 'required', 'additionalProperties', 'items', 'enum',
	'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength',
]);

const jsonEqual = (left, right, depth = 0) => {
	if (depth > 64) return false;
	if (left === right) return true;
	if (Array.isArray(left) || Array.isArray(right)) {
		return Array.isArray(left) && Array.isArray(right)
			&& left.length === right.length
			&& left.every((item, index) => jsonEqual(item, right[index], depth + 1));
	}
	if (!isPlainObject(left) || !isPlainObject(right)) return false;
	const a = Object.keys(left).sort();
	const b = Object.keys(right).sort();
	return a.length === b.length && a.every((key, index) =>
		key === b[index] && jsonEqual(ownValue(left, key), ownValue(right, key), depth + 1));
};

const frozenTree = (value) => {
	if (value === null || typeof value !== 'object') return true;
	if (!Object.isFrozen(value)) return false;
	return Object.getOwnPropertyNames(value).every((key) => frozenTree(
		Object.getOwnPropertyDescriptor(value, key).value
	));
};

/**
 * A project-only view of the ONE server-owned frozen registry. A snapshot
 * fingerprint format is not a proof of provenance: the Host MUST verify it
 * against the installed bootstrap before exposing an editable session.
 */
const readSnapshot = (context) => {
	if (!keysMatch(context, ['profileId', 'projectSchema', 'registrySnapshot'])) return null;
	const { profileId, projectSchema, registrySnapshot: snapshot } = context;
	if (!keysMatch(snapshot, [
		'contractVersion', 'schemaGeneration', 'profileId', 'projectSchema',
		'providers', 'designTypes', 'nodeTypes', 'domainSchemas', 'fingerprint',
	]) || snapshot.contractVersion !== 1 || snapshot.schemaGeneration !== 1
		|| typeof profileId !== 'string' || !profileId || typeof projectSchema !== 'string' || !projectSchema
		|| snapshot.profileId !== profileId || snapshot.projectSchema !== projectSchema
		|| typeof snapshot.fingerprint !== 'string' || !/^sha256:[a-f0-9]{64}$/.test(snapshot.fingerprint)
		|| !Array.isArray(snapshot.providers) || !Array.isArray(snapshot.designTypes)
		|| !Array.isArray(snapshot.nodeTypes) || !Array.isArray(snapshot.domainSchemas)
		|| !frozenTree(snapshot)) return null;
	const providers = snapshot.providers;
	if (!providers.every((id, index) => typeof id === 'string' && PROVIDER.test(id)
		&& (index === 0 || providers[index - 1] < id))) return null;
	const canonical = new Set(providers);
	if (!sorted(snapshot.designTypes) || !sorted(snapshot.nodeTypes) || !sorted(snapshot.domainSchemas)) return null;
	if (!snapshot.designTypes.every((item) => keysMatch(item, ['id', 'ownerProvider', 'allowSitePresentational'])
		&& typeof item.id === 'string' && PROVIDER.test(item.id) && canonical.has(item.ownerProvider)
		&& typeof item.allowSitePresentational === 'boolean')) return null;
	const domains = new Map();
	for (const item of snapshot.domainSchemas) {
		if (!keysMatch(item, ['id', 'ownerProvider', 'schema'])
			|| typeof item.id !== 'string' || !item.id || !canonical.has(item.ownerProvider)
			|| !isPlainObject(item.schema)) return null;
		domains.set(item.id, item);
	}
	const nodes = new Map();
	for (const item of snapshot.nodeTypes) {
		if (!keysMatch(item, ['id', 'provider', 'allowedParents', 'propertiesSchemaId'])
			|| typeof item.id !== 'string' || !NODE_TYPE.test(item.id) || !canonical.has(item.provider)
			|| !Array.isArray(item.allowedParents)
			|| !item.allowedParents.every((id) => typeof id === 'string' && NODE_TYPE.test(id))
			|| !(item.propertiesSchemaId === null || typeof item.propertiesSchemaId === 'string')) return null;
		if (item.propertiesSchemaId !== null) {
			const domain = domains.get(item.propertiesSchemaId);
			if (!domain || domain.ownerProvider !== item.provider) return null;
		}
		nodes.set(item.id, item);
	}
	if ([...nodes.values()].some((item) => item.allowedParents.some((id) => !nodes.has(id)))) return null;
	return { canonical, nodes, domains,
		designTypes: new Map(snapshot.designTypes.map((item) => [item.id, item])) };
};

/** The exact v1 return is frozen and contains only code/pointer pairs. */
export function validateStableProjectV1(project, context) {
	const errors = new Map();
	const add = (code, pointer) => errors.set(pointer + '\0' + code, Object.freeze({ code, pointer }));
	const finish = () => {
		const pairs = [...errors.values()].sort((a, b) =>
			(a.pointer < b.pointer ? -1 : a.pointer > b.pointer ? 1 : 0)
			|| (a.code < b.code ? -1 : a.code > b.code ? 1 : 0));
		return Object.freeze({ valid: pairs.length === 0, diagnostics: Object.freeze(pairs) });
	};
	try {
		// No object/property access, cloning or observer effect before this preflight.
		inspectJsonValue(project);
		inspectJsonValue(context);
	} catch (error) {
		add(error instanceof InvalidDesignValueError && error.reason === 'limit' ? CODES.limit : CODES.node,
			error instanceof InvalidDesignValueError ? error.pointer : '');
		return finish();
	}
	let registry;
	try { registry = readSnapshot(context); } catch { registry = null; }
	if (!registry) {
		add(CODES.domain, '');
		return finish();
	}
	if (!isPlainObject(project)) {
		add(CODES.node, '');
		return finish();
	}
	for (const key of ['schema_version', 'design_type', 'root']) {
		if (!own(project, key)) add(CODES.node, '/' + key);
	}
	for (const key of Object.keys(project)) {
		if (!['schema_version', 'design_type', 'root'].includes(key)) add(CODES.node, '/' + escapeSegment(key));
	}
	const projectVersion = ownValue(project, 'schema_version');
	const projectDesignType = ownValue(project, 'design_type');
	const projectRoot = ownValue(project, 'root');
	if (projectVersion !== 1) add(CODES.generation, '/schema_version');
	const designType = typeof projectDesignType === 'string' ? registry.designTypes.get(projectDesignType) : null;
	if (!designType) add(CODES.node, '/design_type');
	if (!isPlainObject(projectRoot)) {
		add(CODES.node, '/root');
		return finish();
	}
	if (projectVersion !== 1) return finish();

	const domain = (value, schema, pointer, depth) => {
		if (depth > 64) { add(CODES.limit, pointer); return; }
		if (!isPlainObject(schema) || Object.keys(schema).some((key) => !schemaKeys.has(key))) {
			add(CODES.domain, pointer); return;
		}
		const typeDeclaration = ownValue(schema, 'type');
		const types = typeof typeDeclaration === 'string' ? [typeDeclaration] : typeDeclaration;
		const checks = {
			object: () => isPlainObject(value),
			array: () => Array.isArray(value),
			string: () => typeof value === 'string',
			number: () => typeof value === 'number',
			integer: () => typeof value === 'number' && Number.isSafeInteger(value),
			boolean: () => typeof value === 'boolean',
			null: () => value === null,
		};
		if (!Array.isArray(types) || !types.length || new Set(types).size !== types.length
			|| !types.every((type) => typeof type === 'string' && own(checks, type))
			|| !types.some((type) => checks[type]())) { add(CODES.domain, pointer); return; }
		if (own(schema, 'enum') && (!Array.isArray(schema.enum)
			|| !schema.enum.some((entry) => jsonEqual(entry, value)))) {
			add(CODES.domain, pointer);
		}
		if (typeof value === 'string') {
			const len = [...value].length;
			if ((own(schema, 'minLength') && (!Number.isSafeInteger(schema.minLength) || len < schema.minLength))
				|| (own(schema, 'maxLength') && (!Number.isSafeInteger(schema.maxLength) || len > schema.maxLength))) {
				add(CODES.domain, pointer);
			}
		}
		if (typeof value === 'number') {
			if ((own(schema, 'minimum') && (typeof schema.minimum !== 'number' || value < schema.minimum))
				|| (own(schema, 'maximum') && (typeof schema.maximum !== 'number' || value > schema.maximum))) {
				add(CODES.domain, pointer);
			}
		}
		if (Array.isArray(value)) {
			if ((own(schema, 'minItems') && (!Number.isSafeInteger(schema.minItems) || value.length < schema.minItems))
				|| (own(schema, 'maxItems') && (!Number.isSafeInteger(schema.maxItems) || value.length > schema.maxItems))) {
				add(CODES.domain, pointer);
			}
			const itemsSchema = ownValue(schema, 'items');
			if (!isPlainObject(itemsSchema)) { add(CODES.domain, pointer); return; }
			value.forEach((entry, i) => domain(entry, itemsSchema, pointer + '/' + i, depth + 1));
		}
		if (isPlainObject(value)) {
			const properties = own(schema, 'properties') ? schema.properties : {};
			const required = own(schema, 'required') ? schema.required : [];
			if (!isPlainObject(properties) || !Array.isArray(required)
				|| !required.every((key) => typeof key === 'string')
				|| (own(schema, 'additionalProperties') && typeof schema.additionalProperties !== 'boolean')) {
				add(CODES.domain, pointer); return;
			}
			for (const key of required) if (!own(value, key)) add(CODES.domain, pointer + '/' + escapeSegment(key));
			for (const key of Object.keys(value)) {
				const at = pointer + '/' + escapeSegment(key);
				if (own(properties, key) && isPlainObject(properties[key])) domain(value[key], properties[key], at, depth + 1);
				else if (!(own(schema, 'additionalProperties') && schema.additionalProperties === true)) add(CODES.domain, at);
			}
		}
	};

	let count = 0;
	const node = (value, pointer, parentType, depth) => {
		if (depth > 64 || ++count > 100000) { add(CODES.limit, pointer); return; }
		for (const key of ['type', 'provider', 'properties', 'children']) {
			if (!own(value, key)) add(CODES.node, pointer + '/' + key);
		}
		for (const key of Object.keys(value)) {
			if (!['type', 'provider', 'properties', 'children'].includes(key)) add(CODES.node, pointer + '/' + escapeSegment(key));
		}
		const type = ownValue(value, 'type');
		const provider = ownValue(value, 'provider');
		const properties = ownValue(value, 'properties');
		const children = ownValue(value, 'children');
		const definition = typeof type === 'string' ? registry.nodes.get(type) : null;
		if (!definition || (parentType === null && type !== projectDesignType)
			|| (parentType !== null && !definition.allowedParents.includes(parentType))) {
			add(CODES.node, pointer + '/type');
		}
		if (typeof provider !== 'string' || !PROVIDER.test(provider)
			|| !registry.canonical.has(provider)) add(CODES.provider, pointer + '/provider');
		else if ((definition && definition.provider !== provider)
			|| (parentType === null && designType && designType.ownerProvider !== provider)) {
			add(CODES.node, pointer + '/provider');
		}
		if (!isPlainObject(properties)) add(CODES.node, pointer + '/properties');
		else if (definition && definition.propertiesSchemaId !== null) {
			const entry = registry.domains.get(definition.propertiesSchemaId);
			if (!entry) add(CODES.domain, pointer + '/properties');
			else domain(properties, entry.schema, pointer + '/properties', 0);
		} else if (definition && Object.keys(properties).length > 0) add(CODES.domain, pointer + '/properties');
		if (!Array.isArray(children)) { add(CODES.node, pointer + '/children'); return; }
		if (!definition || typeof type !== 'string') return;
		children.forEach((child, index) => {
			const at = pointer + '/children/' + index;
			if (!isPlainObject(child)) add(CODES.node, at);
			else node(child, at, typeof type === 'string' ? type : null, depth + 1);
		});
	};
	node(projectRoot, '/root', null, 0);
	return finish();
}
