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
	const parentPath = edge === 'inside' || targetPath.length === 0
		? targetPath : targetPath.slice(0, -1);
	const parent = at(project.root, parentPath);
	if (!parent || !Array.isArray(parent.children)
		|| !allowedParentsByType.get(source.type)?.has(parent.type)) return null;
	const index = targetPath.length === 0 || edge === 'inside'
		? parent.children.length
		: targetPath.at(-1) + (edge === 'after' ? 1 : 0);
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
