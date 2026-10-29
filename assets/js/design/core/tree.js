import { cloneValue, isPlainObject } from './value.js';

const FORBIDDEN_PROPERTY_KEYS = new Set(['__proto__', 'prototype', 'constructor']);

export const normalizePath = (path) => {
	if (!Array.isArray(path) || path.some((index) => !Number.isInteger(index) || index < 0)) {
		throw new TypeError('Tree paths must be arrays of non-negative integers.');
	}
	return [...path];
};

export const pathKey = (path) => JSON.stringify(normalizePath(path));

export const locationForPath = (path) => normalizePath(path).reduce(
	(location, index) => `${location}.children.${index}`,
	'root'
);

export const isNode = (value) => isPlainObject(value)
	&& typeof value.type === 'string'
	&& typeof value.provider === 'string'
	&& isPlainObject(value.properties)
	&& Array.isArray(value.children);

export const nodeAt = (root, path) => {
	const normalized = normalizePath(path);
	let current = root;
	if (!isNode(current)) return null;
	for (const index of normalized) {
		if (!Array.isArray(current.children) || !isNode(current.children[index])) return null;
		current = current.children[index];
	}
	return current;
};

const mutableNodeAt = (root, path) => {
	let current = root;
	for (const index of path) {
		current = current.children[index];
	}
	return current;
};

export const insertChild = (root, parentPath, index, node) => {
	const parent = nodeAt(root, parentPath);
	if (!parent || !isNode(node) || !Number.isInteger(index) || index < 0 || index > parent.children.length) {
		throw new RangeError('Unable to insert node at the requested tree location.');
	}
	const nextRoot = cloneValue(root);
	mutableNodeAt(nextRoot, normalizePath(parentPath)).children.splice(index, 0, cloneValue(node));
	return nextRoot;
};

export const removeNode = (root, path) => {
	const normalized = normalizePath(path);
	if (normalized.length === 0) throw new RangeError('The root node cannot be deleted by the shared editor core.');
	if (!nodeAt(root, normalized)) throw new RangeError('Unable to delete an unknown tree node.');
	const nextRoot = cloneValue(root);
	const parentPath = normalized.slice(0, -1);
	const index = normalized.at(-1);
	mutableNodeAt(nextRoot, parentPath).children.splice(index, 1);
	return nextRoot;
};

export const reorderChild = (root, parentPath, fromIndex, toIndex) => {
	const parent = nodeAt(root, parentPath);
	if (
		!parent
		|| !Number.isInteger(fromIndex)
		|| !Number.isInteger(toIndex)
		|| fromIndex < 0
		|| toIndex < 0
		|| fromIndex >= parent.children.length
		|| toIndex >= parent.children.length
	) {
		throw new RangeError('Unable to reorder node at the requested tree location.');
	}
	if (fromIndex === toIndex) return cloneValue(root);
	const nextRoot = cloneValue(root);
	const nextParent = mutableNodeAt(nextRoot, normalizePath(parentPath));
	const [node] = nextParent.children.splice(fromIndex, 1);
	nextParent.children.splice(toIndex, 0, node);
	return nextRoot;
};

export const setNodeProperty = (root, nodePath, propertyPath, value) => {
	const normalizedNodePath = normalizePath(nodePath);
	if (!nodeAt(root, normalizedNodePath)) throw new RangeError('Unable to edit an unknown tree node.');
	if (
		!Array.isArray(propertyPath)
		|| propertyPath.length === 0
		|| propertyPath.some((key) => typeof key !== 'string' || key === '' || FORBIDDEN_PROPERTY_KEYS.has(key))
	) {
		throw new TypeError('Property paths must use safe non-empty string keys.');
	}

	const nextRoot = cloneValue(root);
	const node = mutableNodeAt(nextRoot, normalizedNodePath);
	let cursor = node.properties;
	for (let index = 0; index < propertyPath.length - 1; index += 1) {
		const key = propertyPath[index];
		if (!isPlainObject(cursor[key])) cursor[key] = {};
		cursor = cursor[key];
	}
	cursor[propertyPath.at(-1)] = cloneValue(value);
	return nextRoot;
};

const startsWithPath = (path, prefix) => prefix.every((value, index) => path[index] === value);

export const remapPathAfterInsertion = (path, parentPath, index) => {
	const target = normalizePath(path);
	const parent = normalizePath(parentPath);
	if (!startsWithPath(target, parent) || target.length <= parent.length) return target;
	const childIndex = target[parent.length];
	if (childIndex >= index) target[parent.length] = childIndex + 1;
	return target;
};

export const remapPathAfterRemoval = (path, removedPath) => {
	const target = normalizePath(path);
	const removed = normalizePath(removedPath);
	if (startsWithPath(target, removed)) return null;
	const parent = removed.slice(0, -1);
	if (!startsWithPath(target, parent) || target.length <= parent.length) return target;
	const removedIndex = removed.at(-1);
	const childIndex = target[parent.length];
	if (childIndex > removedIndex) target[parent.length] = childIndex - 1;
	return target;
};

export const remapPathAfterReorder = (path, parentPath, fromIndex, toIndex) => {
	const target = normalizePath(path);
	const parent = normalizePath(parentPath);
	if (!startsWithPath(target, parent) || target.length <= parent.length || fromIndex === toIndex) return target;

	const childIndex = target[parent.length];
	if (childIndex === fromIndex) {
		target[parent.length] = toIndex;
	} else if (fromIndex < toIndex && childIndex > fromIndex && childIndex <= toIndex) {
		target[parent.length] = childIndex - 1;
	} else if (toIndex < fromIndex && childIndex >= toIndex && childIndex < fromIndex) {
		target[parent.length] = childIndex + 1;
	}
	return target;
};

/** Atomic subtree move: destination is indexed in the tree AFTER removal. */
export const moveNode = (root, fromPath, parentPath, index) => {
 const from=normalizePath(fromPath), parent=normalizePath(parentPath);
 if(!from.length || !nodeAt(root,from) || !Number.isSafeInteger(index) || index<0)
  throw new RangeError('Invalid node move source or index.');
 const next=cloneValue(root);
 const [moving]=mutableNodeAt(next,from.slice(0,-1)).children.splice(from.at(-1),1);
 const destination=nodeAt(next,parent);
 if(!destination || index>destination.children.length)
  throw new RangeError('Invalid node move destination.');
 if(from.length===parent.length+1 && from.slice(0,-1).every((v,i)=>v===parent[i])
   && from.at(-1)===index) throw new RangeError('Node move is a no-op.');
 mutableNodeAt(next,parent).children.splice(index,0,moving);
 return next;
};
/** Remap all selected paths, including the moved subtree's descendants. */
export const remapPathAfterMove = (path, fromPath, parentPath, index) => {
 const current=normalizePath(path),from=normalizePath(fromPath),parent=normalizePath(parentPath);
 if(!from.length || !Number.isSafeInteger(index) || index<0)
  throw new TypeError('Invalid move path mapping.');
 if(current.length>=from.length && from.every((v,i)=>current[i]===v))
  return [...parent,index,...current.slice(from.length)];
 const removed=remapPathAfterRemoval(current,from);
 return removed===null?null:remapPathAfterInsertion(removed,parent,index);
};
