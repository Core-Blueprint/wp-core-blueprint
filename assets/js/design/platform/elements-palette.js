import { cloneFrozen } from '../core/value.js';

const refuse = (code) => {
	const error = new Error('Designer Elements creation was refused.');
	error.code = code;
	return error;
};
const SOURCES = new Set([
	'palette.componentId', 'selection.entityId', 'selection.nodePath', 'generated.entityId',
]);

/**
 * Pure projection of trusted registered component definitions; not a registry
 * or a DOM controller. Only real user actions invoke dispatchPaletteCreate.
 */
export const projectDesignerElementsPalette = (components) => {
	if (!Array.isArray(components)) throw refuse('ELEMENT_CREATE_BINDING_INVALID');
	const ids = new Set();
	return Object.freeze(components.map((component) => {
		if (!component || typeof component.id !== 'string' || !component.id || ids.has(component.id)
			|| typeof component.label !== 'string' || !component.label
			|| typeof component.create?.commandId !== 'string') throw refuse('ELEMENT_CREATE_BINDING_INVALID');
		ids.add(component.id);
		return cloneFrozen({
			id: component.id, label: component.label,
			description: String(component.description || ''),
			disabled: component.disabled === true,
		});
	}));
};

/**
 * IDs are allocated before calling a pure registered command. A server ID
 * reservation must be explicitly authoritative; ambiguous outcomes fail
 * without entering History. No optimistic Save or implicit publish.
 */
export const dispatchDesignerElementCreate = async ({
	component, editor, contextEpoch, authorize, entityIdExists,
	reserveEntityId = null, maxAttempts = 8, signal = null,
} = {}) => {
	if (!component || typeof component.id !== 'string' || !component.id
		|| !component.create || typeof component.create.commandId !== 'string'
		|| !Array.isArray(component.create.mapping) || !component.create.mapping.length
		|| component.create.mapping.length > 16 || typeof editor?.execute !== 'function'
		|| typeof editor?.selection !== 'function' || typeof editor?.project !== 'function' || typeof authorize !== 'function'
		|| !Number.isSafeInteger(contextEpoch)) throw refuse('ELEMENT_CREATE_BINDING_INVALID');
	const mapping = component.create.mapping;
	if (mapping.some((item) => !item || typeof item.key !== 'string'
		|| !item.key || ['__proto__', 'constructor', 'prototype'].includes(item.key)
		|| !SOURCES.has(item.source))
		|| new Set(mapping.map((item) => item.key)).size !== mapping.length) {
		throw refuse('ELEMENT_CREATE_BINDING_INVALID');
	}
	const initial = editor.selection();
	if (initial.contextEpoch !== contextEpoch || signal?.aborted
		|| authorize(component, editor.project?.(), initial) !== true) throw refuse('COMMAND_REFUSED');
	const usesId = mapping.some((item) => item.source === 'generated.entityId');
	let id = null;
	if (usesId) {
		if (typeof entityIdExists !== 'function') throw refuse('ENTITY_ID_ALLOCATION_FAILED');
		const mode = component.create.idAllocation;
		if (mode === 'host-uuid-v4') {
			const cryptoRef = globalThis.crypto;
			if (typeof cryptoRef?.randomUUID !== 'function') throw refuse('ENTITY_ID_ALLOCATION_FAILED');
			for (let attempt = 0; attempt < Math.min(16, maxAttempts); attempt += 1) {
				const candidate = cryptoRef.randomUUID();
				if (typeof candidate === 'string' && !entityIdExists(component, candidate, editor.project())) {
					id = candidate;
					break;
				}
			}
		} else if (mode === 'server-reserved' && typeof reserveEntityId === 'function') {
			const reservation = await reserveEntityId(component, { signal, contextEpoch });
			if (reservation?.confirmed === true && typeof reservation.id === 'string'
				&& reservation.id && !entityIdExists(component, reservation.id, editor.project())) {
				id = reservation.id;
			}
		}
		if (!id) throw refuse('ENTITY_ID_ALLOCATION_FAILED');
	}
	const current = editor.selection();
	if (signal?.aborted || current.contextEpoch !== contextEpoch
		|| JSON.stringify(current) !== JSON.stringify(initial)
		|| authorize(component, editor.project?.(), current) !== true) throw refuse('COMMAND_REFUSED');
	const input = Object.create(null);
	for (const item of mapping) {
		if (item.source === 'palette.componentId') input[item.key] = component.id;
		else if (item.source === 'generated.entityId') input[item.key] = id;
		else if (item.source === 'selection.entityId') {
			if (current.primary?.kind !== 'entity') throw refuse('ELEMENT_CREATE_BINDING_INVALID');
			input[item.key] = current.primary.id;
		} else if (item.source === 'selection.nodePath') {
			if (current.primary?.kind !== 'node') throw refuse('ELEMENT_CREATE_BINDING_INVALID');
			input[item.key] = current.primary.path;
		}
	}
	return editor.execute(component.create.commandId, cloneFrozen(input), Object.freeze({ contextEpoch, source: 'elements' }));
};
