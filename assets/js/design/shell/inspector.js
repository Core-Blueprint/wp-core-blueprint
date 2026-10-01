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
	documentRef = null,
} = {}) => {
	const doc = resolveDocument(documentRef);
	const element = doc.createElement('div');
	element.className = 'cb-core-design-shell__inspector-identity';

	const title = doc.createElement('h3');
	title.className = 'cb-core-design-shell__inspector-title';
	title.textContent = text(label);
	element.append(title);

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
