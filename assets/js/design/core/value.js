const MAX_VALUE_DEPTH = 128;

export const isPlainObject = (value) => {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
	const prototype = Object.getPrototypeOf(value);
	return prototype === Object.prototype || prototype === null;
};

export const cloneValue = (value, depth = 0) => {
	if (depth > MAX_VALUE_DEPTH) throw new TypeError('Design value exceeds the supported nesting depth.');
	if (value === null || typeof value === 'string' || typeof value === 'boolean') return value;
	if (typeof value === 'number') {
		if (!Number.isFinite(value)) throw new TypeError('Design values must contain finite numbers.');
		return value;
	}
	if (Array.isArray(value)) return value.map((item) => cloneValue(item, depth + 1));
	if (!isPlainObject(value)) throw new TypeError('Design values must be JSON-compatible plain values.');
	return Object.fromEntries(
		Object.entries(value).map(([key, item]) => [key, cloneValue(item, depth + 1)])
	);
};

export const freezeValue = (value) => {
	if (Array.isArray(value)) {
		value.forEach(freezeValue);
		return Object.freeze(value);
	}
	if (isPlainObject(value)) {
		Object.values(value).forEach(freezeValue);
		return Object.freeze(value);
	}
	return value;
};

export const cloneFrozen = (value) => freezeValue(cloneValue(value));

/**
 * Stable equality for supported plain JSON values. Object key insertion order
 * is not a user edit; arrays preserve their authorial ordering.
 */
export const sameValue = (left, right) => {
	if (Object.is(left, right)) return true;
	if (Array.isArray(left) || Array.isArray(right)) {
		return Array.isArray(left) && Array.isArray(right)
			&& left.length === right.length
			&& left.every((entry, index) => sameValue(entry, right[index]));
	}
	if (!isPlainObject(left) || !isPlainObject(right)) return false;
	const leftKeys = Object.keys(left).sort();
	const rightKeys = Object.keys(right).sort();
	return leftKeys.length === rightKeys.length
		&& leftKeys.every((key, index) => key === rightKeys[index] && sameValue(left[key], right[key]));
};
