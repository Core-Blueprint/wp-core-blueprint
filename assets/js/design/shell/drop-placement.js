/**
 * Shared semantic placement projection for the active DesignProject.
 * Browser DOM paths are never authorizations. This pure helper resolves only
 * palette element insertion; existing-node moves remain a later capability.
 */
const pathOK = path => Array.isArray(path) && path.length <= 64
	&& path.every(i => Number.isSafeInteger(i) && i >= 0);
export const designerDropZoneId = path => {
	if (!pathOK(path)) throw new TypeError('DESIGNER_DROP_PATH_INVALID');
	return path.length ? 'node/' + path.join('.') : 'root';
};
const decodeZone = id => {
	if (id === 'root') return [];
	if (typeof id !== 'string' || !/^node\/(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))*$/.test(id)) return null;
	const path = id.slice(5).split('.').map(Number);
	return pathOK(path) ? path : null;
};
const at = (root, path) => {
	let current = root;
	for (const index of path) {
		if (!current || !Array.isArray(current.children) || index >= current.children.length) return null;
		current = current.children[index];
	}
	return current;
};

/**
 * @param {Object} policy
 * @returns {{commandId:string,input:{parentPath:number[],index:number,type:string}}|null}
 */
export const resolveDesignerDropPlacement = ({
	project, source, zoneId, edge, allowedParentsByType, commandId,
	canInsert = null,
} = {}) => {
	if (!project?.root || !source || source.kind !== 'palette'
		|| typeof source.type !== 'string' || typeof commandId !== 'string' || !commandId
		|| !(allowedParentsByType instanceof Map)
		|| (canInsert !== null && typeof canInsert !== 'function')
		|| !['before', 'inside', 'after'].includes(edge)) return null;
	const targetPath = decodeZone(zoneId);
	if (!targetPath) return null;
	const target = at(project.root, targetPath);
	if (!target || !Array.isArray(target.children)) return null;
	const allowed = allowedParentsByType.get(source.type);
	if (!allowed || !(allowed instanceof Set)) return null;
	// A center drop on a container is a request to insert INSIDE it.
	// Do not silently reinterpret a forbidden nested Section as a sibling
	// on the root. A center drop on a leaf may resolve next to that leaf.
	const insideTarget = edge === 'inside' && allowed.has(target.type);
	if (edge === 'inside' && targetPath.length !== 0 && !insideTarget) {
		const targetCanContainElements = [...allowedParentsByType.values()]
			.some(parents => parents instanceof Set && parents.has(target.type));
		if (targetCanContainElements) return null;
	}
	const parentPath = targetPath.length === 0 || insideTarget
		? targetPath : targetPath.slice(0, -1);
	const parent = at(project.root, parentPath);
	if (!parent || !Array.isArray(parent.children) || !allowed.has(parent.type)) return null;
	const index = targetPath.length === 0 || insideTarget
		? parent.children.length
		: targetPath.at(-1) + (edge === 'after' || edge === 'inside' ? 1 : 0);
	if (!Number.isSafeInteger(index) || index < 0 || index > parent.children.length) return null;
	try {
		if (canInsert && canInsert(project, source.type, parentPath) !== true) return null;
	} catch (_) {
		return null;
	}
	return Object.freeze({
		commandId,
		input: Object.freeze({
			parentPath: Object.freeze([...parentPath]), index, type: source.type,
		}),
	});
};


/**
 * DF-C: canonical click-to-add placement for one active typed selection.
 *
 * Only a candidate for an already registered domain insertion command.
 * A consumer must re-read the current selection/project, enforce its live
 * authorization (including edit lease), then commit through its own History.
 * This shares the exact geometry and domain checks with palette Drag & Drop.
 *
 * selectedRef=null means truly no selection. A stale/invalid selected ref is
 * refused, NEVER silently interpreted as a request for the document root.
 *
 * @returns {{commandId:string,input:{parentPath:number[],index:number,type:string}}|null}
 */
export const resolveDesignerInsertPlacement = ({
	project, selectedRef = null, type, allowedParentsByType, commandId,
	canInsert = null,
} = {}) => {
	if (!project?.root || typeof type !== 'string' || !type
		|| !(allowedParentsByType instanceof Map)
		|| typeof commandId !== 'string' || !commandId) return null;
	let zoneId = 'root';
	let edge = 'inside';
	if (selectedRef !== null) {
		if (!selectedRef || selectedRef.kind !== 'node' || !pathOK(selectedRef.path)) return null;
		const selected = at(project.root, selectedRef.path);
		if (!selected || !Array.isArray(selected.children)) return null;
		zoneId = designerDropZoneId(selectedRef.path);
		// A container remains a container even when *this* child type is
		// forbidden. Do not fall back to its parent or the document root.
		const containerTypes = new Set();
		for (const parents of allowedParentsByType.values()) {
			if (!(parents instanceof Set)) return null;
			for (const parentType of parents) containerTypes.add(parentType);
		}
		edge = containerTypes.has(selected.type) ? 'inside' : 'after';
	}
	return resolveDesignerDropPlacement({
		project, source: {kind: 'palette', type},
		zoneId, edge, allowedParentsByType, commandId, canInsert,
	});
};
