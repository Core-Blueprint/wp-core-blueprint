const resolveDocument = (documentRef) => {
	const resolved = documentRef ?? (typeof document !== 'undefined' ? document : null);
	if (!resolved || typeof resolved.createElement !== 'function') {
		throw new TypeError('Designer Inspector helpers require a document-like createElement() boundary.');
	}
	return resolved;
};

const text = (value) => String(value ?? '').trim();

/**
 * Canonical selected-item identity for Designer Inspectors.
 *
 * Consumers provide the same semantic label they expose in Layers; Base owns
 * the Inspector title markup and presentation.
 */
export const createDesignerInspectorIdentity = ({
	label = '',
	eyebrow = '',
	context = '',
	compact = false,
	documentRef = null,
} = {}) => {
	const doc = resolveDocument(documentRef);
	const element = doc.createElement('div');
	element.className = 'cb-core-design-shell__inspector-identity';
	if (compact === true) element.classList.add('cb-core-design-shell__inspector-identity--compact');

	if (text(eyebrow)) {
		const meta = doc.createElement('span');
		meta.className = 'cb-core-design-shell__inspector-eyebrow';
		meta.textContent = text(eyebrow);
		element.append(meta);
	}
	const title = doc.createElement('h3');
	title.className = 'cb-core-design-shell__inspector-title';
	title.textContent = text(label);
	element.append(title);
	if (text(context)) {
		const location = doc.createElement('span');
		location.className = 'cb-core-design-shell__inspector-context';
		location.textContent = text(context);
		element.append(location);
	}

	return Object.freeze({
		element,
		title,
		setLabel(nextLabel) {
			title.textContent = text(nextLabel);
		},
	});
};

/**
 * Canonical vertical control stack for Designer Inspectors.
 */
export const createDesignerInspectorControls = ({
	documentRef = null,
} = {}) => {
	const doc = resolveDocument(documentRef);
	const element = doc.createElement('div');
	element.className = 'cb-core-design-shell__inspector-controls';
	return element;
};

/**
 * Canonical boolean Inspector row. Labels and controls are one row; separate
 * settings always remain vertically stacked by the owning controls container.
 */
export const createDesignerInspectorToggle = ({
	label = '',
	checked = false,
	disabled = false,
	documentRef = null,
	dataset = {},
} = {}) => {
	const doc = resolveDocument(documentRef);
	const element = doc.createElement('label');
	element.className = 'cb-core-design-shell__inspector-toggle';

	const labelElement = doc.createElement('span');
	labelElement.className = 'cb-core-design-shell__inspector-toggle-label';
	labelElement.textContent = text(label);

	const control = doc.createElement('input');
	control.type = 'checkbox';
	control.checked = checked === true;
	control.disabled = disabled === true;

	if (dataset && typeof dataset === 'object' && !Array.isArray(dataset)) {
		Object.entries(dataset).forEach(([key, value]) => {
			if (!key || value === undefined || value === null) return;
			control.dataset[key] = String(value);
		});
	}

	element.append(labelElement, control);
	return Object.freeze({ element, label: labelElement, control });
};

/**
 * Optional, session-owned Inspector group. No domain data or open state is
 * persisted by Base. A consumer may restore expanded state on rerender.
 */
export const createDesignerInspectorSection = ({
	title = '',
	summary = '',
	expanded = false,
	onToggle = null,
	documentRef = null,
} = {}) => {
	const doc = resolveDocument(documentRef);
	const element = doc.createElement('details');
	element.className = 'cb-core-design-shell__inspector-section';
	element.open = expanded === true;

	const heading = doc.createElement('summary');
	heading.className = 'cb-core-design-shell__inspector-section-heading';
	const label = doc.createElement('span');
	label.className = 'cb-core-design-shell__inspector-section-label';
	label.textContent = text(title);
	heading.append(label);
	if (text(summary)) {
		const status = doc.createElement('span');
		status.className = 'cb-core-design-shell__inspector-section-status';
		status.textContent = text(summary);
		heading.append(status);
	}

	const body = doc.createElement('div');
	body.className = 'cb-core-design-shell__inspector-section-body';
	element.append(heading, body);
	if (typeof onToggle === 'function') {
		element.addEventListener('toggle', () => onToggle(element.open));
	}
	return Object.freeze({ element, heading, body });
};

/**
 * Opt-in textarea auto-growth, scoped to the supplied control only.
 * The consumer owns its value and change transport; Base only adjusts height.
 */
export const enableDesignerTextareaAutosize = (control) => {
	if (!control || String(control.tagName || '').toUpperCase() !== 'TEXTAREA'
		|| typeof control.addEventListener !== 'function' || !control.style) {
		throw new TypeError('Designer textarea autosize requires a textarea control.');
	}
	const resize = () => {
		control.style.height = 'auto';
		control.style.overflowY = 'hidden';
		if (Number.isFinite(control.scrollHeight) && control.scrollHeight > 0) {
			control.style.height = `${control.scrollHeight}px`;
		}
	};
	control.addEventListener('input', resize);
	if (typeof requestAnimationFrame === 'function') requestAnimationFrame(resize);
	else Promise.resolve().then(resize);
	return resize;
};


/**
 * DOM focus ownership remains with Base. The Host decides when to rerender;
 * the keyed control survives value/status updates and never commits mid-IME.
 */
export const createDesignerInspectorTextControl = ({
	key, label = '', value = '', multiline = false, disabled = false,
	onCommit = null, documentRef = null,
} = {}) => {
	if (typeof key !== 'string' || !key.trim() || (onCommit !== null && typeof onCommit !== 'function')) {
		throw new TypeError('Inspector text control requires a stable key and optional commit callback.');
	}
	const doc = resolveDocument(documentRef);
	const element = doc.createElement('label');
	element.className = 'cb-core-design-shell__inspector-text-control';
	const caption = doc.createElement('span');
	caption.className = 'cb-core-design-shell__inspector-toggle-label';
	caption.textContent = text(label);
	const control = doc.createElement(multiline ? 'textarea' : 'input');
	if (!multiline) control.type = 'text';
	control.dataset.cbControlKey = key.trim();
	control.value = String(value ?? '');
	control.disabled = disabled === true;
	element.append(caption, control);
	let composing = false;
	let dirty = false;
	let destroyed = false;
	const start = () => { composing = true; };
	const end = () => { composing = false; dirty = true; };
	const input = () => { dirty = true; };
	const commit = (event) => {
		if (destroyed || composing || event?.isComposing || !dirty || control.disabled) return;
		dirty = false;
		onCommit?.(control.value, event);
	};
	control.addEventListener('compositionstart', start);
	control.addEventListener('compositionend', end);
	control.addEventListener('input', input);
	control.addEventListener('change', commit);
	control.addEventListener('blur', commit);
	return Object.freeze({
		element, caption, control,
		update({ label: nextLabel = label, value: nextValue = '', disabled: nextDisabled = false } = {}) {
			if (destroyed) return false;
			caption.textContent = text(nextLabel);
			control.disabled = nextDisabled === true;
			// Do not overwrite active drafts, caret or an IME composition.
			if (!composing && !dirty && doc.activeElement !== control) control.value = String(nextValue ?? '');
			return true;
		},
		get isComposing() { return composing; },
		destroy() {
			if (destroyed) return;
			destroyed = true;
			control.removeEventListener('compositionstart', start);
			control.removeEventListener('compositionend', end);
			control.removeEventListener('input', input);
			control.removeEventListener('change', commit);
			control.removeEventListener('blur', commit);
		},
	});
};
