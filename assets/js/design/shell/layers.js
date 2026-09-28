import { decorateDesignerControl } from './icons.js';

const config = typeof window !== 'undefined' ? (window.cbCoreDesignerLaunch || {}) : {};

const ACTION_DEFINITIONS = Object.freeze({
	moveUp: Object.freeze({
		icon: 'arrow-up',
		label: 'Move element up',
	}),
	moveDown: Object.freeze({
		icon: 'arrow-down',
		label: 'Move element down',
	}),
	remove: Object.freeze({
		icon: 'trash-2',
		label: 'Remove element',
	}),
});

const normalizeDepth = (value) => {
	const depth = Number(value);
	return Number.isInteger(depth) && depth >= 0 ? depth : 0;
};

const normalizeAction = (value) => {
	if (value === null || value === undefined || value === false) return null;
	if (typeof value === 'function') {
		return Object.freeze({ disabled: false, label: null, onActivate: value });
	}
	if (typeof value !== 'object' || Array.isArray(value)) {
		throw new TypeError('Designer layer actions must be callbacks, configuration objects or false.');
	}
	const onActivate = typeof value.onActivate === 'function' ? value.onActivate : null;
	if (!onActivate && value.disabled !== true) {
		throw new TypeError('Enabled Designer layer actions require onActivate().');
	}
	return Object.freeze({
		disabled: value.disabled === true,
		label: typeof value.label === 'string' ? value.label.trim() || null : null,
		onActivate,
	});
};

const createActionButton = (documentRef, actionName, actionConfig) => {
	const definition = ACTION_DEFINITIONS[actionName];
	if (!definition) throw new RangeError(`Unknown Designer layer action: ${actionName}.`);

	const button = documentRef.createElement('button');
	button.type = 'button';
	button.className = 'button cb-core-button cb-core-design-shell__layer-action';
	button.dataset.cbDesignLayerAction = actionName;
	if (actionName === 'remove') {
		button.classList.add('cb-core-design-shell__layer-action--remove');
	}

	const label = actionConfig.label
		|| String(config.layerActionLabels?.[actionName] || definition.label).trim()
		|| definition.label;
	button.textContent = label;
	button.disabled = actionConfig.disabled;
	decorateDesignerControl(button, definition.icon, {
		iconOnly: true,
		label,
	});

	if (actionConfig.onActivate) {
		button.addEventListener('click', (event) => {
			event.stopPropagation();
			actionConfig.onActivate(event);
		});
	}

	return button;
};

/**
 * Create one canonical Base-owned Layers row.
 *
 * Consumers provide semantic labels, nesting depth and bounded action callbacks.
 * Base owns row DOM, action order, iconography, accessible labels and selection
 * presentation so Designer consumers cannot drift into product-specific Layers UI.
 */
export const createDesignerLayerRow = ({
	documentRef = typeof document !== 'undefined' ? document : null,
	label = 'Element',
	meta = '',
	depth = 0,
	selected = false,
	onSelect = null,
	actions = {},
} = {}) => {
	if (!documentRef?.createElement) {
		throw new TypeError('Designer layer row requires a DOM Document.');
	}
	if (actions === null || typeof actions !== 'object' || Array.isArray(actions)) {
		throw new TypeError('Designer layer row actions must be an object.');
	}

	const row = documentRef.createElement('div');
	row.className = 'cb-core-design-shell__layer-row';
	row.dataset.cbDesignLayerRow = '1';
	row.style.setProperty('--cb-design-layer-depth', String(normalizeDepth(depth)));
	row.classList.toggle('is-selected', selected === true);

	const select = documentRef.createElement('button');
	select.type = 'button';
	select.className = 'cb-core-design-shell__layer-select';
	select.dataset.cbDesignLayerSelect = '1';
	if (selected === true) select.setAttribute('aria-current', 'true');

	const labelNode = documentRef.createElement('span');
	labelNode.className = 'cb-core-design-shell__layer-label';
	labelNode.textContent = String(label || 'Element');
	select.append(labelNode);

	const normalizedMeta = String(meta || '').trim();
	if (normalizedMeta) {
		const metaNode = documentRef.createElement('span');
		metaNode.className = 'cb-core-design-shell__layer-meta';
		metaNode.textContent = normalizedMeta;
		select.append(metaNode);
	}

	if (typeof onSelect === 'function') {
		select.addEventListener('click', (event) => onSelect(event));
	}

	const actionContainer = documentRef.createElement('div');
	actionContainer.className = 'cb-core-design-shell__layer-actions';
	actionContainer.dataset.cbDesignLayerActions = '1';

	for (const actionName of ['moveUp', 'moveDown', 'remove']) {
		const actionConfig = normalizeAction(actions[actionName]);
		if (!actionConfig) continue;
		actionContainer.append(createActionButton(documentRef, actionName, actionConfig));
	}

	if (!actionContainer.children.length) actionContainer.hidden = true;
	row.append(select, actionContainer);

	return Object.freeze({
		row,
		select,
		actions: actionContainer,
		setSelected(nextSelected) {
			const active = nextSelected === true;
			row.classList.toggle('is-selected', active);
			if (active) select.setAttribute('aria-current', 'true');
			else select.removeAttribute('aria-current');
		},
	});
};

export const DESIGNER_LAYER_ACTIONS = Object.freeze(Object.keys(ACTION_DEFINITIONS));
