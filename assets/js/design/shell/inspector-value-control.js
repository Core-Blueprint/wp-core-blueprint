import { createDesignerInspectorTextControl } from './inspector.js';

/**
 * One typed Inspector control surface shared by Base Mail and Reports.
 * The schema comes from the verified FrozenRegistry, not DOM or saved JSON.
 * Controls ONLY submit values; Host/SessionBridge remains command authority.
 */
export function createDesignerInspectorValueControl({
	key, label = '', value, schema, multiline = false, onCommit, documentRef,
} = {}) {
	if (typeof key !== 'string' || !key || typeof onCommit !== 'function'
		|| !schema || typeof schema !== 'object' || !documentRef?.createElement) {
		throw new TypeError('INSPECTOR_SCHEMA_INVALID');
	}
	const type = schema.type;
	// HTML color picker only for a declared string field whose current value
	// is a valid hex color and whose control identity describes a color.
	const colorControl = type === 'string' && !Array.isArray(schema.enum)
		&& /(?:color|background|accent|foreground)/i.test(key)
		&& typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value);
	if (type === 'string' && !Array.isArray(schema.enum) && !colorControl) {
		const text = createDesignerInspectorTextControl({
			key, label, value, multiline,
			documentRef,
			onCommit: (next) => {
				if ((schema.minLength === undefined || [...next].length >= schema.minLength)
					&& (schema.maxLength === undefined || [...next].length <= schema.maxLength)) {
					onCommit(next);
				}
			},
		});
		if (Number.isSafeInteger(schema.maxLength)) text.control.maxLength = schema.maxLength;
		return text;
	}
	if (!['string','integer','number','boolean'].includes(type)) {
		throw new TypeError('INSPECTOR_SCHEMA_UNSUPPORTED');
	}
	const labelNode = documentRef.createElement('label');
	labelNode.className = 'cb-core-design-shell__inspector-text-control';
	const caption = documentRef.createElement('span');
	caption.className = 'cb-core-design-shell__inspector-toggle-label';
	caption.textContent = String(label);
	labelNode.append(caption);
	const input = documentRef.createElement(Array.isArray(schema.enum) ? 'select' : 'input');
	input.dataset.cbControlKey = key;
	let destroyed = false;
	let composing = false;
	let dirty = false;
	let currentValue = value;
	if (Array.isArray(schema.enum)) {
		if (!schema.enum.length || !schema.enum.every((v) => typeof v === type || (type === 'integer' && Number.isSafeInteger(v)))) {
			throw new TypeError('INSPECTOR_SCHEMA_INVALID');
		}
		for (const [i, optionValue] of schema.enum.entries()) {
			const option = documentRef.createElement('option');
			option.value = String(i);
			option.textContent = String(optionValue);
			input.append(option);
		}
	} else if (type === 'boolean') {
		input.type = 'checkbox';
	} else if (colorControl) {
		input.type = 'color';
	} else {
		input.type = 'number';
		if (type === 'integer') input.step = '1';
		else input.step = 'any';
		if (typeof schema.minimum === 'number') input.min = String(schema.minimum);
		if (typeof schema.maximum === 'number') input.max = String(schema.maximum);
	}
	const renderValue = (next) => {
		if (Array.isArray(schema.enum)) {
			const i = schema.enum.findIndex((candidate) => Object.is(candidate, next));
			if (i < 0) throw new TypeError('INSPECTOR_SCHEMA_INVALID');
			input.value = String(i);
		} else if (type === 'boolean') input.checked = next === true;
		else input.value = String(next);
	};
	renderValue(value);
	labelNode.append(input);
	const parse = () => {
		if (Array.isArray(schema.enum)) {
			const idx = Number(input.value);
			return Number.isSafeInteger(idx) && idx >= 0 && idx < schema.enum.length
				? schema.enum[idx] : undefined;
		}
		if (type === 'boolean') return input.checked;
		if (colorControl) return /^#[0-9a-f]{6}$/i.test(input.value) ? input.value : undefined;
		if (input.value.trim() === '') return undefined;
		const number = Number(input.value);
		if (!Number.isFinite(number) || (type === 'integer' && !Number.isSafeInteger(number))
			|| (typeof schema.minimum === 'number' && number < schema.minimum)
			|| (typeof schema.maximum === 'number' && number > schema.maximum)) return undefined;
		return number;
	};
	const commit = (event) => {
		if (destroyed || composing || event?.isComposing || !dirty) return;
		const next = parse();
		if (next === undefined) {
			input.setAttribute('aria-invalid', 'true');
			return;
		}
		input.removeAttribute('aria-invalid');
		dirty = false;
		if (!Object.is(currentValue, next)) {
			onCommit(next);
			currentValue = next;
		}
	};
	const onInput = () => { dirty = true; };
	const begin = () => { composing = true; };
	const end = () => { composing = false; dirty = true; };
	input.addEventListener('input', onInput);
	input.addEventListener('change', commit);
	input.addEventListener('blur', commit);
	input.addEventListener('compositionstart', begin);
	input.addEventListener('compositionend', end);
	return Object.freeze({
		element: labelNode,
		control: input,
		update({value: nextValue = currentValue} = {}) {
			if (destroyed) return false;
			if (!dirty && !composing && documentRef.activeElement !== input) {
				currentValue = nextValue;
				renderValue(nextValue);
			}
			return true;
		},
		destroy() {
			if (destroyed) return;
			destroyed = true;
			input.removeEventListener('input', onInput);
			input.removeEventListener('change', commit);
			input.removeEventListener('blur', commit);
			input.removeEventListener('compositionstart', begin);
			input.removeEventListener('compositionend', end);
		},
	});
}
