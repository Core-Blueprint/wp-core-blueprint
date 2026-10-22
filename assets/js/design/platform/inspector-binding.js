import { cloneFrozen } from '../core/value.js';
import { nodeAt, normalizePath } from '../core/tree.js';

const fail = (code) => {
	const error = new Error('Designer inspector binding refused.');
	error.code = code;
	return error;
};
const validPath = (path) => Array.isArray(path) && path.length > 0 && path.length <= 16
	&& path.every((key) => typeof key === 'string' && key.length > 0 && key.length <= 128
		&& !['__proto__', 'constructor', 'prototype'].includes(key));
const ownPath = (root, path) => {
	let current = root;
	for (const key of path) {
		if (!current || typeof current !== 'object' || !Object.hasOwn(current, key)) return { found: false };
		const descriptor = Object.getOwnPropertyDescriptor(current, key);
		if (!descriptor || !Object.hasOwn(descriptor, 'value')) return { found: false };
		current = descriptor.value;
	}
	return { found: true, value: current };
};
const refKey = (ref) => ref?.kind === 'node'
	? 'node:' + JSON.stringify(ref.path)
	: ref?.kind === 'entity' ? 'entity:' + JSON.stringify([ref.type, ref.id]) : '';
const SOURCE_NAMES = new Set(['control.value', 'selection.entityId', 'selection.nodePath']);

/**
 * Inspector BindingV1 maps registered declarative field paths to trusted,
 * already-registered commands. This does not mutate the project or schedule
 * a render. Host must supply the frozen schema/capability authorization.
 */
export const createDesignerInspectorBinding = ({
	control, editor, resolveEntity, authorizeTarget, contextEpoch,
} = {}) => {
	if (control?.contractVersion !== 1 || typeof control.id !== 'string' || !control.id
		|| !control.target || !['node-property', 'entity-field'].includes(control.target.kind)
		|| !validPath(control.target.path) || typeof editor?.project !== 'function'
		|| typeof editor?.selection !== 'function' || typeof authorizeTarget !== 'function'
		|| !Number.isSafeInteger(contextEpoch)) throw fail('CONTROL_BINDING_INVALID');
	const readonly = control.readonly === true;
	if (readonly ? (control.write !== undefined || control.keyboardCommit !== undefined)
		: (!control.write || control.keyboardCommit !== true)) throw fail('CONTROL_BINDING_INVALID');
	const mapping = readonly ? [] : control.write.mapping;
	if (!readonly && (typeof control.write.commandId !== 'string' || !control.write.commandId
		|| !Array.isArray(mapping) || !mapping.length || mapping.length > 16
		|| mapping.some((pair) => !pair || typeof pair.key !== 'string' || !pair.key
			|| ['__proto__', 'constructor', 'prototype'].includes(pair.key)
			|| !SOURCE_NAMES.has(pair.source))
		|| new Set(mapping.map((pair) => pair.key)).size !== mapping.length
		|| typeof editor.execute !== 'function')) throw fail('CONTROL_BINDING_INVALID');

	const targetFor = (snapshot) => {
		if (!snapshot || snapshot.contextEpoch !== contextEpoch || !Array.isArray(snapshot.refs)) {
			throw fail('CONTROL_TARGET_MISSING');
		}
		const primary = snapshot.primary;
		if (!primary || !snapshot.refs.some((ref) => refKey(ref) === refKey(primary))) throw fail('CONTROL_TARGET_MISSING');
		if ((control.target.kind === 'node-property' && primary.kind !== 'node')
			|| (control.target.kind === 'entity-field' && primary.kind !== 'entity')) throw fail('CONTROL_TARGET_MISSING');
		const project = editor.project();
		if (authorizeTarget(control, primary, project) !== true) throw fail('COMMAND_REFUSED');
		let target;
		if (primary.kind === 'node') {
			target = nodeAt(project.root, normalizePath(primary.path))?.properties;
		} else if (typeof resolveEntity === 'function') {
			target = resolveEntity(primary, project);
		}
		if (!target || typeof target !== 'object') throw fail('CONTROL_TARGET_MISSING');
		return { primary, project, value: ownPath(target, control.target.path) };
	};

	return Object.freeze({
		key: control.id,
		read(snapshot = editor.selection()) {
			const { value } = targetFor(snapshot);
			if (!value.found) throw fail('CONTROL_TARGET_MISSING');
			return cloneFrozen(value.value);
		},
		commit(value, { source = 'inspector' } = {}) {
			if (readonly) throw fail('COMMAND_REFUSED');
			const before = editor.selection();
			const { primary, value: current } = targetFor(before);
			if (!current.found) throw fail('CONTROL_TARGET_MISSING');
			const nextInput = Object.create(null);
			for (const pair of mapping) {
				if (pair.source === 'control.value') nextInput[pair.key] = value;
				else if (pair.source === 'selection.entityId') {
					if (primary.kind !== 'entity') throw fail('CONTROL_BINDING_INVALID');
					nextInput[pair.key] = primary.id;
				} else if (pair.source === 'selection.nodePath') {
					if (primary.kind !== 'node') throw fail('CONTROL_BINDING_INVALID');
					nextInput[pair.key] = primary.path;
				}
			}
			// Reread current selection before dispatch; no stale-target writes.
			const now = editor.selection();
			if (now.contextEpoch !== contextEpoch || refKey(now.primary) !== refKey(primary)) {
				throw fail('CONTROL_TARGET_MISSING');
			}
			return editor.execute(control.write.commandId, cloneFrozen(nextInput), Object.freeze({ source, contextEpoch }));
		},
	});
};
