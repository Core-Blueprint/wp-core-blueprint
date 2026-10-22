const MAX_VALUE_DEPTH = 128;
const FORBIDDEN_KEYS = new Set(['__proto__', 'prototype', 'constructor']);
const own = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
const segment = (value) => String(value).replace(/~/g, '~0').replace(/\//g, '~1');

export class InvalidDesignValueError extends TypeError {
	constructor(pointer, reason = 'invalid') {
		super('Design values must be safe JSON-compatible data.');
		this.name = 'InvalidDesignValueError';
		this.pointer = pointer;
		this.reason = reason;
	}
}

export const isPlainObject = (value) => {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
	const prototype = Object.getPrototypeOf(value);
	return prototype === Object.prototype || prototype === null;
};

/**
 * Traverse every own descriptor before a single value is cloned or exposed.
 * Descriptors, rather than Object.entries/getters, are the source of values.
 * Untrusted Proxies may run traps in the JS language itself; callers must only
 * accept registry data from the verified Host bootstrap, never a project map.
 */
export const inspectJsonValue = (value, pointer = '') => {
	const active = new WeakSet();
	const visit = (candidate, path, depth) => {
		if (depth > MAX_VALUE_DEPTH) throw new InvalidDesignValueError(path, 'limit');
		if (candidate === null || typeof candidate === 'string' || typeof candidate === 'boolean') return;
		if (typeof candidate === 'number') {
			if (!Number.isFinite(candidate)) throw new InvalidDesignValueError(path);
			if (Number.isInteger(candidate) && !Number.isSafeInteger(candidate)) {
				throw new InvalidDesignValueError(path, 'limit');
			}
			return;
		}
		if (typeof candidate !== 'object' || (!Array.isArray(candidate) && !isPlainObject(candidate))) {
			throw new InvalidDesignValueError(path);
		}
		if (active.has(candidate)) throw new InvalidDesignValueError(path);
		active.add(candidate);
		try {
			const descriptors = Object.getOwnPropertyDescriptors(candidate);
			if (Reflect.ownKeys(candidate).some((key) => typeof key === 'symbol')) {
				throw new InvalidDesignValueError(path);
			}
			if (Array.isArray(candidate)) {
				const length = Object.getOwnPropertyDescriptor(candidate, 'length');
				if (!length || !Number.isSafeInteger(length.value)) throw new InvalidDesignValueError(path, 'limit');
				if (Object.keys(descriptors).length !== length.value + 1) {
					throw new InvalidDesignValueError(path);
				}
				for (const key of Object.keys(descriptors)) {
					if (key === 'length') continue;
					if (!/^(0|[1-9][0-9]*)$/.test(key) || Number(key) >= length.value) {
						throw new InvalidDesignValueError(`${path}/${segment(key)}`);
					}
				}
				for (let i = 0; i < length.value; i += 1) {
					const descriptor = descriptors[String(i)];
					if (!descriptor || !own(descriptor, 'value')) {
						throw new InvalidDesignValueError(`${path}/${i}`);
					}
					visit(descriptor.value, `${path}/${i}`, depth + 1);
				}
			} else {
				for (const key of Object.keys(descriptors)) {
					const child = `${path}/${segment(key)}`;
					if (FORBIDDEN_KEYS.has(key) || !own(descriptors[key], 'value')) {
						throw new InvalidDesignValueError(child);
					}
					visit(descriptors[key].value, child, depth + 1);
				}
			}
		} finally {
			active.delete(candidate);
		}
	};
	visit(value, pointer, 0);
};

const copyChecked = (value) => {
	if (value === null || typeof value !== 'object') return value;
	if (Array.isArray(value)) {
		return Array.from({ length: value.length }, (_, index) => copyChecked(
			Object.getOwnPropertyDescriptor(value, String(index)).value
		));
	}
	const next = {};
	const descriptors = Object.getOwnPropertyDescriptors(value);
	for (const key of Object.keys(descriptors)) {
		Object.defineProperty(next, key, {
			value: copyChecked(descriptors[key].value),
			enumerable: true, configurable: true, writable: true,
		});
	}
	return next;
};

export const cloneValue = (value, depth = 0) => {
	if (!Number.isSafeInteger(depth) || depth < 0 || depth > MAX_VALUE_DEPTH) {
		throw new InvalidDesignValueError('', 'limit');
	}
	inspectJsonValue(value);
	return copyChecked(value);
};

const freezeChecked = (value) => {
	if (value && typeof value === 'object') {
		const descriptors = Object.getOwnPropertyDescriptors(value);
		for (const key of Object.keys(descriptors)) {
			if (key !== 'length') freezeChecked(descriptors[key].value);
		}
		Object.freeze(value);
	}
	return value;
};

export const freezeValue = (value) => {
	inspectJsonValue(value);
	return freezeChecked(value);
};

export const cloneFrozen = (value) => freezeChecked(cloneValue(value));

/** Object insertion order is immaterial; JSON arrays retain order. */
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
