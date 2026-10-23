import { createDesignerIcon, decorateDesignerControl } from './icons.js';

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
	hide: Object.freeze({
		icon: 'eye-off',
		label: 'Hide element',
	}),
	show: Object.freeze({
		icon: 'eye',
		label: 'Show element',
	}),
	remove: Object.freeze({
		icon: 'trash-2',
		label: 'Delete element',
	}),
	menu: Object.freeze({
		icon: 'ellipsis',
		label: 'More layer actions',
	}),
});

const normalizeDepth = (value) => {
	const depth = Number(value);
	return Number.isInteger(depth) && depth >= 0 ? depth : 0;
};

/**
 * Domain-owned remove semantics, Base-owned accessible confirmation UI.
 * Text is rendered with textContent, never interpreted as HTML. The caller
 * may provide a descriptor or a synchronous descriptor factory; invalid
 * descriptors fail closed before any domain remove command is dispatched.
 */
const resolveRemoveConfirmation = (source, context) => {
	if (source === null || source === undefined) return null;
	const descriptor = typeof source === 'function' ? source(Object.freeze({...context})) : source;
	if (!descriptor || typeof descriptor !== 'object' || Array.isArray(descriptor)) {
		throw new TypeError('Designer remove confirmation requires a descriptor object.');
	}
	const fields = ['title', 'message', 'confirmLabel'];
	const values = fields.map((field) => descriptor[field]);
	if (values.some((value) => typeof value !== 'string' || !value.trim() || value.trim().length > 400)) {
		throw new TypeError('Designer remove confirmation requires bounded nonempty title, message and confirmLabel.');
	}
	return Object.freeze({
		title: values[0].trim(),
		message: values[1].trim(),
		confirmLabel: values[2].trim(),
	});
};

const normalizeAction = (value) => {
	if (value === null || value === undefined || value === false) return null;
	if (typeof value === 'function') {
		return Object.freeze({ disabled: false, label: null, onActivate: value, confirmation: null });
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
		confirmation: Object.hasOwn(value, 'confirmation') && value.confirmation !== undefined
			? value.confirmation : null,
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
	onOpenMenu = null,
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

	// The tree opts in to the context menu. Keep the public standalone
	// row's existing callbacks usable for consumers without that controller.
	const quickActions = typeof onOpenMenu === 'function'
		? ['hide', 'show'] : ['moveUp', 'moveDown', 'hide', 'show', 'remove'];
	for (const actionName of quickActions) {
		const actionConfig = normalizeAction(actions[actionName]);
		if (!actionConfig) continue;
		actionContainer.append(createActionButton(documentRef, actionName, actionConfig));
	}
	const hasMenu = ['moveUp', 'moveDown', 'remove'].some((name) => normalizeAction(actions[name]));
	if (hasMenu && typeof onOpenMenu === 'function') {
		const menuTrigger = createActionButton(documentRef, 'menu', {
			disabled: false,
			label: 'More actions for ' + normalizedLabel,
			onActivate: () => onOpenMenu(menuTrigger),
		});
		menuTrigger.dataset.cbDesignLayerMenuTrigger = '1';
		menuTrigger.setAttribute('aria-haspopup', 'menu');
		menuTrigger.setAttribute('aria-expanded', 'false');
		actionContainer.append(menuTrigger);
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

/**
 * Shared Foundation projection of trusted profile layer capabilities.
 *
 * The profile declares *which* commands are meaningful for a node and their
 * legal bounds. Base owns affordances, lifecycle guards and the one canonical
 * command dispatch. No DOM, profile names or document mutation lives here.
 */
export const projectDesignerLayerInteractions = ({
	node, parent = null, path, policy, commandIds, execute, isReady,
} = {}) => {
	if (!node || typeof node !== 'object' || !Array.isArray(node.children)
		|| !Array.isArray(path) || path.some((i) => !Number.isSafeInteger(i) || i < 0)
		|| !policy || typeof policy !== 'object' || Array.isArray(policy)
		|| typeof policy.remove !== 'boolean'
		|| typeof execute !== 'function' || typeof isReady !== 'function'
		|| !commandIds || ['remove', 'reorder', 'set'].some(
			(name) => typeof commandIds[name] !== 'string' || !commandIds[name]
		)) throw new TypeError('DESIGNER_LAYER_POLICY_INVALID');

	const actions = {};
	const parentPath = path.slice(0, -1);
	const index = path.at(-1);
	if (policy.remove) {
		if (!parent || path.length === 0) throw new TypeError('DESIGNER_LAYER_POLICY_INVALID');
		actions.remove = {
			onActivate: () => {
				if (isReady()) execute(commandIds.remove, {path: [...path]});
			},
		};
	}

	let hidden = null;
	if (policy.visibility !== null && policy.visibility !== undefined) {
		const visible = policy.visibility;
		if (!visible || typeof visible !== 'object' || Array.isArray(visible)
			|| typeof visible.key !== 'string' || !visible.key
			|| !Object.hasOwn(node.properties || {}, visible.key)
			|| typeof visible.shownValue !== 'string' || typeof visible.hiddenValue !== 'string'
			|| visible.shownValue === visible.hiddenValue
			|| ![visible.shownValue, visible.hiddenValue].includes(node.properties[visible.key])) {
			throw new TypeError('DESIGNER_LAYER_POLICY_INVALID');
		}
		hidden = node.properties[visible.key] === visible.hiddenValue;
		actions[hidden ? 'show' : 'hide'] = {
			onActivate: () => {
				if (isReady()) execute(commandIds.set, {
					path: [...path], key: visible.key,
					value: hidden ? visible.shownValue : visible.hiddenValue,
				});
			},
		};
	}

	let reorder = null;
	if (policy.reorder !== null && policy.reorder !== undefined) {
		const range = policy.reorder;
		if (!parent || !Array.isArray(parent.children) || path.length === 0
			|| !range || typeof range !== 'object' || Array.isArray(range)
			|| !Number.isSafeInteger(range.minIndex) || !Number.isSafeInteger(range.maxIndex)
			|| range.minIndex < 0 || range.maxIndex >= parent.children.length
			|| index < range.minIndex || index > range.maxIndex) {
			throw new TypeError('DESIGNER_LAYER_POLICY_INVALID');
		}
		if (range.maxIndex > range.minIndex) {
			reorder = {
				index, minIndex: range.minIndex, maxIndex: range.maxIndex,
				onMove: (toIndex) => {
					if (isReady() && Number.isSafeInteger(toIndex)
						&& toIndex >= range.minIndex && toIndex <= range.maxIndex
						&& toIndex !== index) {
						execute(commandIds.reorder, {
							parentPath: [...parentPath], fromIndex: index, toIndex,
						});
					}
				},
			};
		}
	}
	return Object.freeze({
		actions: Object.freeze(actions),
		reorder: reorder ? Object.freeze(reorder) : null,
		hidden,
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
 * Number of descendants that will be removed with a parent node.
 * The menu does not infer domain permissions: it only invokes an already
 * registered remove callback after an explicit confirmation.
 */
const countDescendants = (children) => normalizeTreeItems(children).reduce(
	(count, child) => count + 1 + countDescendants(child.children || []), 0
);

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
	let activeMenu = null;

	// A single body-level menu avoids clipping by either scrolling Designer rail.
	// No document edits originate from the menu itself.
	const closeMenu = ({ restoreFocus = false } = {}) => {
		if (!activeMenu) return;
		const { popup, trigger, returnFocus, outside, dismissOnViewport } = activeMenu;
		activeMenu = null;
		documentRef.removeEventListener('pointerdown', outside, true);
		documentRef.defaultView?.removeEventListener('resize', dismissOnViewport);
		documentRef.defaultView?.removeEventListener('scroll', dismissOnViewport, true);
		trigger?.setAttribute('aria-expanded', 'false');
		popup.remove();
		if (restoreFocus && returnFocus?.isConnected) returnFocus.focus();
	};

	const openMenu = ({ actions, children, label, anchor, returnFocus, x = null, y = null }) => {
		const entries = ['moveUp', 'moveDown', 'hide', 'show', 'remove']
			.map((name) => ({ name, config: normalizeAction(actions[name]) }))
			.filter(({ config }) => config !== null);
		if (!entries.length || !documentRef.body) return;
		closeMenu();

		const popup = documentRef.createElement('div');
		popup.className = 'cb-core-design-shell__layer-context-menu';
		popup.dataset.cbDesignLayerContextMenu = '1';
		popup.setAttribute('role', 'menu');
		popup.setAttribute('aria-label', 'Actions for ' + label);

		const outside = (event) => {
			if (!popup.contains(event.target) && !anchor.contains(event.target)) closeMenu();
		};
		const dismissOnViewport = () => closeMenu();
		activeMenu = { popup, trigger: anchor.matches?.('[data-cb-design-layer-menu-trigger]') ? anchor : null,
			returnFocus, outside, dismissOnViewport };
		if (activeMenu.trigger) activeMenu.trigger.setAttribute('aria-expanded', 'true');

		const confirmChildrenDelete = (action) => {
			const descendants = countDescendants(children);
			const explicit = resolveRemoveConfirmation(action.confirmation, {
				label: String(label || 'Element'),
				descendantCount: descendants,
			});
			const confirmation = explicit || {
				title: 'Delete ' + label + '?',
				message: 'This action affects ' + descendants + ' nested element'
					+ (descendants === 1 ? '' : 's') + '. You can undo this change.',
				confirmLabel: 'Confirm delete',
			};
			popup.replaceChildren();
			popup.setAttribute('role', 'alertdialog');
			popup.setAttribute('aria-label', 'Confirm deletion of ' + label);

			const heading = documentRef.createElement('strong');
			heading.className = 'cb-core-design-shell__layer-confirm-heading';
			heading.textContent = confirmation.title;
			const message = documentRef.createElement('p');
			message.className = 'cb-core-design-shell__layer-confirm-message';
			message.textContent = confirmation.message;
			const buttons = documentRef.createElement('div');
			buttons.className = 'cb-core-design-shell__layer-confirm-actions';
			const cancel = documentRef.createElement('button');
			cancel.type = 'button';
			cancel.className = 'button';
			cancel.textContent = 'Cancel';
			cancel.addEventListener('click', () => closeMenu({ restoreFocus: true }));
			const remove = documentRef.createElement('button');
			remove.type = 'button';
			remove.className = 'button cb-core-design-shell__layer-confirm-delete';
			remove.textContent = confirmation.confirmLabel;
			remove.addEventListener('click', () => {
				if (activeMenu?.popup !== popup || action.disabled) return;
				closeMenu({ restoreFocus: true });
				action.onActivate();
			});
			buttons.append(cancel, remove);
			popup.append(heading, message, buttons);
			positionPopup();
			cancel.focus();
		};

		for (const { name, config } of entries) {
			if (name === 'remove') {
				const separator = documentRef.createElement('div');
				separator.className = 'cb-core-design-shell__layer-menu-separator';
				separator.setAttribute('role', 'separator');
				popup.append(separator);
			}
			const button = documentRef.createElement('button');
			button.type = 'button';
			button.className = 'cb-core-design-shell__layer-menu-item';
			button.dataset.cbDesignLayerContextAction = name;
			button.setAttribute('role', 'menuitem');
			const definition = ACTION_DEFINITIONS[name];
			if (name === 'remove') button.classList.add('is-destructive');
			const icon = createDesignerIcon(definition.icon, documentRef);
			const caption = documentRef.createElement('span');
			caption.textContent = config.label || String(configLabels(name));
			if (icon) button.append(icon);
			button.append(caption);
			button.disabled = config.disabled;
			button.addEventListener('click', () => {
				if (config.disabled || typeof config.onActivate !== 'function') return;
				if (name === 'remove'
					&& (countDescendants(children) > 0 || config.confirmation !== null)) {
					// Invalid consumer semantics must never trigger a remove.
					// The menu stays open for cancellation when validation fails.
					try { confirmChildrenDelete(config); }
					catch (_) { closeMenu({ restoreFocus: true }); }
					return;
				}
				closeMenu({ restoreFocus: true });
				config.onActivate();
			});
			popup.append(button);
		}

		function positionPopup() {
			const bounds = anchor.getBoundingClientRect();
			const viewport = documentRef.defaultView;
			const width = viewport?.innerWidth || documentRef.documentElement.clientWidth;
			const height = viewport?.innerHeight || documentRef.documentElement.clientHeight;
			const left = x === null ? bounds.right : x;
			const top = y === null ? bounds.bottom : y;
			popup.style.left = Math.round(Math.max(8, Math.min(left, width - popup.offsetWidth - 8))) + 'px';
			popup.style.top = Math.round(Math.max(8, Math.min(top, height - popup.offsetHeight - 8))) + 'px';
		}
		popup.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				closeMenu({ restoreFocus: true });
				return;
			}
			if (popup.getAttribute('role') === 'alertdialog') {
				if (event.key !== 'Tab') return;
				const buttons = [...popup.querySelectorAll('button:not(:disabled)')];
				if (!buttons.length) return;
				event.preventDefault();
				const index = buttons.indexOf(documentRef.activeElement);
				buttons[(index + (event.shiftKey ? buttons.length - 1 : 1)) % buttons.length].focus();
				return;
			}
			const items = [...popup.querySelectorAll('[role="menuitem"]:not(:disabled)')];
			const index = items.indexOf(documentRef.activeElement);
			let next = -1;
			if (event.key === 'ArrowDown') next = (index + 1) % items.length;
			else if (event.key === 'ArrowUp') next = (index - 1 + items.length) % items.length;
			else if (event.key === 'Home') next = 0;
			else if (event.key === 'End') next = items.length - 1;
			else if (event.key === 'Tab') {
				closeMenu({ restoreFocus: true });
				return;
			}
			if (next >= 0 && items.length) {
				event.preventDefault();
				items[next].focus();
			}
		});
		documentRef.body.append(popup);
		positionPopup();
		documentRef.addEventListener('pointerdown', outside, true);
		documentRef.defaultView?.addEventListener('resize', dismissOnViewport);
		documentRef.defaultView?.addEventListener('scroll', dismissOnViewport, true);
		popup.querySelector('[role="menuitem"]:not(:disabled)')?.focus();
	};
	const configLabels = (name) => config.layerActionLabels?.[name] || ACTION_DEFINITIONS[name].label;

	const focusKey = (key) => {
		const record = rowsByKey.get(String(key || ''));
		if (!record?.select || typeof record.select.focus !== 'function') return false;
		record.select.focus();
		focusedKey = record.key;
		return true;
	};

	const visibleItems = (items, prefix = '', output = []) => {
		normalizeTreeItems(items).forEach((item, index) => {
			const key = normalizeTreeKey(item, (prefix || 'root') + ':' + index);
			output.push({ key, item });
			if (!collapsedKeys.has(key)) visibleItems(item.children || [], key, output);
		});
		return output;
	};

	const patch = (items) => {
		const next = visibleItems(items);
		const oldOrder = [...rowsByKey.keys()];
		if (next.length !== oldOrder.length
			|| next.some(({ key, item }, index) => key !== oldOrder[index] || item.reorder || item.actions)) return false;
		currentItems = normalizeTreeItems(items);
		for (const { key, item } of next) {
			const row = rowsByKey.get(key)?.row;
			updateDesignerLayerRow(row, { label: item.label, selected: item.selected === true });
		}
		return true;
	};

	const render = (items = currentItems, { focus = '' } = {}) => {
		currentItems = normalizeTreeItems(items);
		closeMenu();
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

				const itemPath = item.ref?.kind ? item.ref : (Array.isArray(item.path) ? [...item.path] : null);
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
					onOpenMenu: (anchor) => openMenu({
						actions, children, label: item.label, anchor, returnFocus: anchor,
					}),
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
					if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
						if (['moveUp', 'moveDown', 'remove'].some((name) => normalizeAction(actions[name]))) {
							event.preventDefault();
							openMenu({ actions, children, label: item.label,
								anchor: row, returnFocus: layer.select });
						}
						return;
					}
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

				row.addEventListener('contextmenu', (event) => {
					if (!['moveUp', 'moveDown', 'remove'].some((name) => normalizeAction(actions[name]))) return;
					event.preventDefault();
					event.stopPropagation();
					openMenu({ actions, children, label: item.label, anchor: row,
						returnFocus: layer.select, x: event.clientX, y: event.clientY });
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
						row.classList.add('is-drop-target');
						if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
					});
					row.addEventListener('dragleave', (event) => {
						if (!row.contains(event.relatedTarget)) row.classList.remove('is-drop-target');
					});
					row.addEventListener('drop', (event) => {
						if (!dragRecord || dragRecord.parentKey !== parentKey) return;
						event.preventDefault();
						row.classList.remove('is-drop-target');
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
					row.addEventListener('dragend', () => {
						dragRecord = null;
						for (const target of element.querySelectorAll('.is-drop-target')) {
							target.classList.remove('is-drop-target');
						}
					});
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
		patch,
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


const safeLayerPath = (path) => {
	if (!Array.isArray(path) || path.length < 1 || path.length > 12
		|| path.some((key) => typeof key !== 'string' || !key || ['__proto__', 'prototype', 'constructor'].includes(key))) {
		throw new TypeError('Layer title path must be a registered safe field path.');
	}
	return path;
};
const readLayerTitle = (entity, path) => {
	let cursor = entity;
	for (const key of safeLayerPath(path)) {
		if (!cursor || typeof cursor !== 'object' || !Object.hasOwn(cursor, key)) return null;
		cursor = cursor[key];
	}
	return typeof cursor === 'string' ? cursor : null;
};

/**
 * Pure entity Layers projection. Registered collection resolvers supply
 * identity/order; DOM strings never decide entity identity or reordering.
 */
export const projectDesignerEntityLayers = ({
	project, selection, definitions, resolveCollection,
} = {}) => {
	if (!project || !Array.isArray(definitions) || typeof resolveCollection !== 'function') {
		throw new TypeError('Entity Layers require registered definitions and collection resolution.');
	}
	const selected = new Set((selection?.refs ?? []).filter((ref) => ref?.kind === 'entity')
		.map((ref) => JSON.stringify([ref.type, ref.id])));
	const keys = new Set();
	const rows = [];
	for (const descriptor of definitions) {
		if (!descriptor || typeof descriptor.type !== 'string' || !descriptor.type
			|| typeof descriptor.identityField !== 'string' || !descriptor.identityField
			|| !Array.isArray(descriptor.titlePath)) throw new TypeError('Invalid registered Layers descriptor.');
		const collection = resolveCollection(descriptor, project);
		if (!Array.isArray(collection)) throw new TypeError('Registered Layers collection must be ordered.');
		const maxLength = Number.isSafeInteger(descriptor.maxLength) && descriptor.maxLength > 0
			? Math.min(256, descriptor.maxLength) : 128;
		for (const entity of collection) {
			const id = entity?.[descriptor.identityField];
			if (typeof id !== 'string' || !id || id.length > 128) throw new TypeError('Layer entity lacks its registered identity.');
			const key = JSON.stringify([descriptor.type, id]);
			if (keys.has(key)) throw new TypeError('Duplicate registered Layers entity identity.');
			keys.add(key);
			const title = readLayerTitle(entity, descriptor.titlePath);
			rows.push(Object.freeze({
				key: 'entity:' + key,
				ref: Object.freeze({ kind: 'entity', type: descriptor.type, id }),
				label: (title?.trim() || String(descriptor.fallbackTitle || 'Element')).slice(0, maxLength),
				selected: selected.has(key),
				// No reorder unless provided by an explicitly registered command.
			}));
		}
	}
	return Object.freeze(rows);
};

/**
 * Text-only keyed row updates avoid destructive full-tree replacement for
 * title changes while preserving focus, selected state and current position.
 */
export const updateDesignerLayerRow = (row, { label, selected } = {}) => {
	if (!row?.querySelector) return false;
	const title = row.querySelector('.cb-core-design-shell__layer-label');
	const select = row.querySelector('[data-cb-design-layer-select]');
	if (!title || !select) return false;
	const next = String(label ?? '').replace(/\s+/g, ' ').trim();
	if (title.textContent !== next) title.textContent = next;
	const active = selected === true;
	row.classList.toggle('is-selected', active);
	row.setAttribute('aria-selected', active ? 'true' : 'false');
	if (active) select.setAttribute('aria-current', 'true');
	else select.removeAttribute('aria-current');
	return true;
};

export const DESIGNER_LAYER_ACTIONS = Object.freeze(Object.keys(ACTION_DEFINITIONS));
