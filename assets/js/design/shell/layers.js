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

const disclosureLabel = (label, expanded) => {
	const verb = String(
		expanded
			? (config.panelLabels?.collapse || 'Collapse')
			: (config.panelLabels?.expand || 'Expand')
	).trim();
	return [verb, String(label || '').trim()].filter(Boolean).join(' ');
};

/**
 * Create one canonical Base-owned Layers row.
 *
 * Consumers provide semantic labels and bounded callbacks. Base owns row DOM,
 * disclosure/action iconography, accessible labels and selection presentation.
 * Tree composition belongs to createDesignerLayerTree().
 */
export const createDesignerLayerRow = ({
	documentRef = typeof document !== 'undefined' ? document : null,
	label = 'Element',
	meta = '',
	depth = 0,
	selected = false,
	onSelect = null,
	actions = {},
	expandable = false,
	expanded = true,
	onToggle = null,
	level = null,
} = {}) => {
	if (!documentRef?.createElement) {
		throw new TypeError('Designer layer row requires a DOM Document.');
	}
	if (actions === null || typeof actions !== 'object' || Array.isArray(actions)) {
		throw new TypeError('Designer layer row actions must be an object.');
	}

	const normalizedLabel = String(label || 'Element').trim() || 'Element';
	const row = documentRef.createElement('div');
	row.className = 'cb-core-design-shell__layer-row';
	row.dataset.cbDesignLayerRow = '1';
	row.style.setProperty('--cb-design-layer-depth', String(normalizeDepth(depth)));
	row.classList.toggle('is-selected', selected === true);

	if (Number.isInteger(Number(level)) && Number(level) > 0) {
		row.setAttribute('role', 'treeitem');
		row.setAttribute('aria-level', String(Number(level)));
		row.setAttribute('aria-selected', selected === true ? 'true' : 'false');
	}

	let toggle = null;
	if (expandable === true) {
		toggle = documentRef.createElement('button');
		toggle.type = 'button';
		toggle.className = 'cb-core-design-shell__layer-toggle';
		toggle.dataset.cbDesignLayerToggle = '1';
		toggle.setAttribute('aria-expanded', expanded === true ? 'true' : 'false');
		toggle.classList.toggle('is-expanded', expanded === true);
		decorateDesignerControl(toggle, 'chevron-right', {
			iconOnly: true,
			label: disclosureLabel(normalizedLabel, expanded === true),
		});
		if (typeof onToggle === 'function') {
			toggle.addEventListener('click', (event) => {
				event.preventDefault();
				event.stopPropagation();
				onToggle(event);
			});
		}
		row.setAttribute('aria-expanded', expanded === true ? 'true' : 'false');
	} else {
		toggle = documentRef.createElement('span');
		toggle.className = 'cb-core-design-shell__layer-toggle-spacer';
		toggle.setAttribute('aria-hidden', 'true');
	}

	const select = documentRef.createElement('button');
	select.type = 'button';
	select.className = 'cb-core-design-shell__layer-select';
	select.dataset.cbDesignLayerSelect = '1';
	if (selected === true) select.setAttribute('aria-current', 'true');

	const labelNode = documentRef.createElement('span');
	labelNode.className = 'cb-core-design-shell__layer-label';
	labelNode.textContent = normalizedLabel;
	select.append(labelNode);

	const normalizedMeta = String(meta || '').replace(/\s+/g, ' ').trim();
	if (normalizedMeta && normalizedMeta.toLocaleLowerCase() !== normalizedLabel.toLocaleLowerCase()) {
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

	const actionCount = actionContainer.children.length;
	row.style.setProperty('--cb-design-layer-action-count', String(actionCount));
	row.classList.toggle('has-actions', actionCount > 0);
	if (!actionCount) actionContainer.hidden = true;
	row.append(toggle, select, actionContainer);

	return Object.freeze({
		row,
		select,
		toggle: expandable === true ? toggle : null,
		actions: actionContainer,
		setSelected(nextSelected) {
			const active = nextSelected === true;
			row.classList.toggle('is-selected', active);
			if (active) select.setAttribute('aria-current', 'true');
			else select.removeAttribute('aria-current');
			if (row.getAttribute('role') === 'treeitem') {
				row.setAttribute('aria-selected', active ? 'true' : 'false');
			}
		},
	});
};

const normalizeTreeItems = (items) => {
	if (!Array.isArray(items)) throw new TypeError('Designer layer tree items must be an array.');
	return items.filter((item) => item && typeof item === 'object' && !Array.isArray(item));
};

const normalizeReorder = (reorder, fallbackIndex, siblingCount) => {
	if (!reorder || typeof reorder !== 'object' || Array.isArray(reorder)) return null;
	if (typeof reorder.onMove !== 'function') return null;

	const index = Number.isInteger(Number(reorder.index)) ? Number(reorder.index) : fallbackIndex;
	const minIndex = Number.isInteger(Number(reorder.minIndex)) ? Number(reorder.minIndex) : 0;
	const maxIndex = Number.isInteger(Number(reorder.maxIndex)) ? Number(reorder.maxIndex) : Math.max(0, siblingCount - 1);
	if (index < minIndex || index > maxIndex || maxIndex <= minIndex) return null;

	return Object.freeze({
		index,
		minIndex,
		maxIndex,
		onMove: reorder.onMove,
	});
};

const normalizeTreeKey = (item, fallback) => {
	const raw = item?.key ?? item?.path ?? fallback;
	const key = Array.isArray(raw) ? JSON.stringify(raw) : String(raw ?? '').trim();
	if (!key) throw new TypeError('Designer layer tree items require a stable key.');
	return key;
};

/**
 * Create one canonical Base-owned hierarchical Layers tree.
 *
 * Consumers describe semantic nodes. Base owns recursive composition, transient
 * collapse state, sibling drag/reorder affordances, disclosure controls, ARIA
 * tree metadata and keyboard navigation. Collapse never mutates project state.
 */
export const createDesignerLayerTree = ({
	documentRef = typeof document !== 'undefined' ? document : null,
	ariaLabel = 'Layers',
	emptyMessage = '',
	onSelectItem = null,
} = {}) => {
	if (!documentRef?.createElement) {
		throw new TypeError('Designer layer tree requires a DOM Document.');
	}
	if (onSelectItem !== null && onSelectItem !== undefined && typeof onSelectItem !== 'function') {
		throw new TypeError('Designer layer tree onSelectItem adapter must be a function or null.');
	}

	const selectionAdapter = typeof onSelectItem === 'function' ? onSelectItem : null;
	const element = documentRef.createElement('div');
	element.className = 'cb-core-design-shell__layer-list cb-core-design-shell__layer-tree';
	element.dataset.cbDesignLayerTree = '1';
	element.setAttribute('role', 'tree');
	element.setAttribute('aria-label', String(ariaLabel || 'Layers').trim() || 'Layers');

	const collapsedKeys = new Set();
	const rowsByKey = new Map();
	let currentItems = [];
	let focusedKey = '';
	let dragRecord = null;

	const focusKey = (key) => {
		const record = rowsByKey.get(String(key || ''));
		if (!record?.select || typeof record.select.focus !== 'function') return false;
		record.select.focus();
		focusedKey = record.key;
		return true;
	};

	const render = (items = currentItems, { focus = '' } = {}) => {
		currentItems = normalizeTreeItems(items);
		const hadFocus = typeof element.contains === 'function' && element.contains(documentRef.activeElement);
		const requestedFocus = String(focus || (hadFocus ? focusedKey : '') || '');
		rowsByKey.clear();
		dragRecord = null;
		element.replaceChildren();

		const visibleKeys = [];

		const renderLevel = (levelItems, parentGroup, depth, parentKey) => {
			const normalized = normalizeTreeItems(levelItems);
			normalized.forEach((item, siblingIndex) => {
				const key = normalizeTreeKey(item, `${parentKey || 'root'}:${siblingIndex}`);
				const children = normalizeTreeItems(item.children || []);
				const expandable = children.length > 0;
				const expanded = expandable && !collapsedKeys.has(key);
				const reorder = normalizeReorder(item.reorder, siblingIndex, normalized.length);
				const actions = { ...(item.actions || {}) };

				if (reorder) {
					actions.moveUp = {
						disabled: reorder.index <= reorder.minIndex,
						onActivate: () => reorder.onMove(reorder.index - 1),
					};
					actions.moveDown = {
						disabled: reorder.index >= reorder.maxIndex,
						onActivate: () => reorder.onMove(reorder.index + 1),
					};
				}

				const itemPath = Array.isArray(item.path) ? [...item.path] : null;
				const itemSelect = selectionAdapter && itemPath
					? () => selectionAdapter(itemPath, Object.freeze({
						source: 'layers',
						openInspector: false,
						key,
					}))
					: item.onSelect;

				const layer = createDesignerLayerRow({
					documentRef,
					label: item.label,
					meta: item.meta,
					selected: item.selected === true,
					onSelect: itemSelect,
					actions,
					expandable,
					expanded,
					level: depth + 1,
					onToggle: expandable ? () => {
						if (collapsedKeys.has(key)) collapsedKeys.delete(key);
						else collapsedKeys.add(key);
						render(currentItems, { focus: key });
					} : null,
				});

				const row = layer.row;
				row.dataset.cbDesignLayerKey = key;
				row.setAttribute('aria-posinset', String(siblingIndex + 1));
				row.setAttribute('aria-setsize', String(normalized.length));
				if (item.dataset && typeof item.dataset === 'object' && !Array.isArray(item.dataset)) {
					Object.entries(item.dataset).forEach(([name, value]) => {
						if (value !== null && value !== undefined) row.dataset[name] = String(value);
					});
				}

				const record = Object.freeze({
					key,
					parentKey,
					row,
					select: layer.select,
					expandable,
					expanded,
					children,
					reorder,
				});
				rowsByKey.set(key, record);
				visibleKeys.push(key);

				layer.select.addEventListener('focus', () => { focusedKey = key; });
				layer.select.addEventListener('keydown', (event) => {
					const position = visibleKeys.indexOf(key);
					if (event.key === 'ArrowDown' && position >= 0 && position < visibleKeys.length - 1) {
						event.preventDefault();
						focusKey(visibleKeys[position + 1]);
						return;
					}
					if (event.key === 'ArrowUp' && position > 0) {
						event.preventDefault();
						focusKey(visibleKeys[position - 1]);
						return;
					}
					if (event.key === 'Home' && visibleKeys.length) {
						event.preventDefault();
						focusKey(visibleKeys[0]);
						return;
					}
					if (event.key === 'End' && visibleKeys.length) {
						event.preventDefault();
						focusKey(visibleKeys.at(-1));
						return;
					}
					if (event.key === 'ArrowRight' && expandable) {
						event.preventDefault();
						if (!expanded) {
							collapsedKeys.delete(key);
							render(currentItems, { focus: key });
							return;
						}
						const firstChild = rowsByKey.get(normalizeTreeKey(children[0], `${key}:0`));
						if (firstChild) focusKey(firstChild.key);
						return;
					}
					if (event.key === 'ArrowLeft') {
						if (expandable && expanded) {
							event.preventDefault();
							collapsedKeys.add(key);
							render(currentItems, { focus: key });
							return;
						}
						if (parentKey) {
							event.preventDefault();
							focusKey(parentKey);
						}
					}
				});

				if (reorder) {
					row.draggable = true;
					row.addEventListener('dragstart', (event) => {
						dragRecord = { key, parentKey, reorder };
						if (event.dataTransfer) {
							event.dataTransfer.effectAllowed = 'move';
							event.dataTransfer.setData('text/plain', key);
						}
					});
					row.addEventListener('dragover', (event) => {
						if (!dragRecord || dragRecord.parentKey !== parentKey) return;
						if (siblingIndex < dragRecord.reorder.minIndex || siblingIndex > dragRecord.reorder.maxIndex) return;
						event.preventDefault();
						if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
					});
					row.addEventListener('drop', (event) => {
						if (!dragRecord || dragRecord.parentKey !== parentKey) return;
						event.preventDefault();
						const targetIndex = siblingIndex;
						if (
							targetIndex >= dragRecord.reorder.minIndex
							&& targetIndex <= dragRecord.reorder.maxIndex
							&& targetIndex !== dragRecord.reorder.index
						) {
							dragRecord.reorder.onMove(targetIndex);
						}
						dragRecord = null;
					});
					row.addEventListener('dragend', () => { dragRecord = null; });
				}

				const node = documentRef.createElement('div');
				node.className = 'cb-core-design-shell__layer-node';
				node.dataset.cbDesignLayerNode = key;
				node.append(row);

				if (expandable && expanded) {
					const group = documentRef.createElement('div');
					group.className = 'cb-core-design-shell__layer-group';
					group.dataset.cbDesignLayerGroup = key;
					group.setAttribute('role', 'group');
					node.append(group);
					renderLevel(children, group, depth + 1, key);
				}

				parentGroup.append(node);
			});
		};

		if (currentItems.length) {
			renderLevel(currentItems, element, 0, '');
		} else if (String(emptyMessage || '').trim()) {
			const empty = documentRef.createElement('p');
			empty.className = 'cb-core-design-shell__layer-empty';
			empty.textContent = String(emptyMessage).trim();
			element.append(empty);
		}

		if (requestedFocus) focusKey(requestedFocus);
		return element;
	};

	return Object.freeze({
		element,
		render,
		row(key) {
			return rowsByKey.get(String(key || ''))?.row ?? null;
		},
		isCollapsed(key) {
			return collapsedKeys.has(String(key || ''));
		},
		collapse(key) {
			const normalized = String(key || '');
			if (!rowsByKey.get(normalized)?.expandable) return false;
			collapsedKeys.add(normalized);
			render(currentItems, { focus: normalized });
			return true;
		},
		expand(key) {
			const normalized = String(key || '');
			if (!collapsedKeys.has(normalized)) return false;
			collapsedKeys.delete(normalized);
			render(currentItems, { focus: normalized });
			return true;
		},
		reset() {
			collapsedKeys.clear();
			render(currentItems);
		},
	});
};

export const DESIGNER_LAYER_ACTIONS = Object.freeze(Object.keys(ACTION_DEFINITIONS));
