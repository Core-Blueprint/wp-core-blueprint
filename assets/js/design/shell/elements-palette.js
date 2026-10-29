import { createDesignerIcon, decorateDesignerElement } from './icons.js';

const STATES = new Set(['interactive', 'disabled', 'informational']);
const ACTIONS = new Set(['add', 'edit', 'select', 'copy']);
const safeText = (value) => typeof value === 'string' ? value.trim() : '';
const invalid = () => new TypeError('DESIGNER_ELEMENTS_PALETTE_INVALID');

/**
 * Purely presentational Elements and Dynamic Data controller. No registry,
 * document commands, selection, clipboard, Save or Drag & Drop ownership.
 */
export const createDesignerElementsPalette = ({
	documentRef = typeof document === 'undefined' ? null : document,
	elements = [], dynamicData = [], labels = {}, onIntent,
} = {}) => {
	if (!documentRef || typeof documentRef.createElement !== 'function'
		|| typeof onIntent !== 'function') throw invalid();
	const copy = (source, descriptors) => {
		if (!Array.isArray(descriptors)) throw invalid();
		const ids = new Set();
		return descriptors.map((entry) => {
			if (!entry || typeof entry !== 'object'
				|| !safeText(entry.id) || !safeText(entry.label)
				|| !STATES.has(entry.state) || ids.has(entry.id)) throw invalid();
			ids.add(entry.id);
			const action = entry.action;
			if (source === 'dynamic-data') {
				if (action !== 'copy' || typeof entry.value !== 'string'
					|| !entry.value.trim() || entry.state === 'informational') throw invalid();
			} else if (entry.state === 'informational'
				? action !== null : !ACTIONS.has(action)) throw invalid();
			return Object.freeze({
				id: entry.id, label: entry.label, state: entry.state, action,
				type: safeText(entry.type), icon: safeText(entry.icon),
				value: source === 'dynamic-data' ? entry.value : undefined,
				description: safeText(entry.description),
				disabledReason: safeText(entry.disabledReason),
				selected: entry.selected === true,
			});
		});
	};
	const words = {
		elements: safeText(labels.elements) || 'Elements',
		dynamicData: safeText(labels.dynamicData) || 'Dynamic Data',
		actions: Object.fromEntries(['add', 'edit', 'select', 'copy']
			.map((key) => [key, safeText(labels.actions?.[key]) || ({
				add: 'Add', edit: 'Edit', select: 'Select', copy: 'Copy',
			})[key]])),
	};
	const root = documentRef.createElement('div');
	root.className = 'cb-core-design-shell__elements-palette';
	let disposed = false;
	let generation = 0;
	const makeSection = (title) => {
		const section = documentRef.createElement('section');
		section.className = 'cb-core-design-shell__panel-section';
		const heading = documentRef.createElement('h3');
		heading.className = 'cb-core-design-shell__panel-section-title';
		heading.textContent = title;
		heading.tabIndex = -1;
		section.append(heading);
		return {section, heading};
	};
	const emit = (control, descriptor, source, version) => {
		let pending = false;
		control.addEventListener('click', () => {
			if (disposed || version !== generation || pending
				|| descriptor.state !== 'interactive' || control.disabled) return;
			pending = true;
			control.disabled = true;
			control.setAttribute('aria-busy', 'true');
			const intent = {
				source, id: descriptor.id, action: descriptor.action,
				...(source === 'dynamic-data' ? {value: descriptor.value} : {}),
			};
			const settle = () => {
				if (disposed || version !== generation) return;
				pending = false;
				control.removeAttribute('aria-busy');
				control.disabled = false;
			};
			try {
				const outcome = onIntent(Object.freeze(intent));
				if (outcome && typeof outcome.then === 'function') {
					// Both fulfillment and rejection are observed; the domain
					// adapter owns feedback. No late resolution can re-enable
					// an obsolete or destroyed control.
					Promise.resolve(outcome).then(settle, settle);
				} else settle();
			} catch (_) {
				// Consumer owns domain failure display, while the shared DOM
				// event boundary must not leak an unhandled exception.
				settle();
			}
		});
	};
	const card = (entry, source, version) => {
		const interactive = entry.state !== 'informational';
		const control = documentRef.createElement(interactive ? 'button' : 'span');
		control.className = 'cb-core-design-shell__palette-item'
			+ (source === 'elements' ? ' cb-core-design-shell__palette-item--stacked' : ' cb-core-design-shell__dynamic-item')
			+ (!interactive ? ' cb-core-design-shell__palette-item--informational' : '');
		if (interactive) control.type = 'button';
		control.dataset.cbDesignComponentId = entry.id;
		control.dataset.cbDesignElementSource = source;
		if (interactive) control.dataset.cbDesignElementAction = entry.action;
		const title = interactive ? words.actions[entry.action] + ' ' + entry.label : entry.label;
		if (source === 'elements') {
			decorateDesignerElement(control, entry.type || entry.id, {label: title, icon: entry.icon});
		} else {
			const icon = createDesignerIcon('braces', documentRef);
			if (icon) control.append(icon);
		}
		const label = documentRef.createElement('span');
		label.className = 'cb-core-design-shell__palette-item-label';
		label.textContent = entry.label;
		control.append(label);
		control.title = source === 'dynamic-data' ? entry.value : title;
		if (interactive) {
			control.setAttribute('aria-label', source === 'dynamic-data'
				? title + ' dynamic data token: ' + entry.value : title);
			control.disabled = entry.state === 'disabled';
			if (entry.state === 'disabled' && entry.disabledReason) {
				control.title = entry.disabledReason;
				control.setAttribute('aria-description', entry.disabledReason);
			}
			if (source === 'elements' && entry.selected && (entry.action === 'edit' || entry.action === 'select')) {
				control.classList.add('is-active');
				control.setAttribute('aria-pressed', 'true');
			}
			if (entry.state === 'interactive') emit(control, entry, source, version);
		}
		return control;
	};
	const update = (next) => {
		if (disposed || !next || typeof next !== 'object') throw invalid();
		// Validate the entire snapshot before replacing a single DOM node.
		const items = copy('elements', next.elements);
		const tokens = copy('dynamic-data', next.dynamicData);
		const usable = tokens.some(item => item.state === 'interactive');
		const active = documentRef.activeElement;
		const focused = root.contains(active) && active?.dataset?.cbDesignElementSource
			? {source: active.dataset.cbDesignElementSource,
				id: active.dataset.cbDesignComponentId, action: active.dataset.cbDesignElementAction}
			: null;
		const version = ++generation;
		const section = makeSection(words.elements);
		const grid = documentRef.createElement('div');
		grid.className = 'cb-core-design-shell__palette-grid';
		for (const entry of items) grid.append(card(entry, 'elements', version));
		section.section.append(grid);
		const children = [section.section];
		if (usable) {
			const data = makeSection(words.dynamicData);
			const glyph = createDesignerIcon('braces', documentRef);
			if (glyph) data.heading.prepend(glyph);
			const tokenGrid = documentRef.createElement('div');
			tokenGrid.className = 'cb-core-design-shell__palette-grid cb-core-design-shell__dynamic-grid';
			for (const entry of tokens) tokenGrid.append(card(entry, 'dynamic-data', version));
			data.section.append(tokenGrid);
			children.push(data.section);
		}
		root.replaceChildren(...children);
		if (focused) {
			const current = [...root.querySelectorAll('[data-cb-design-element-source]')]
				.find(item => item.dataset.cbDesignElementSource === focused.source
					&& item.dataset.cbDesignComponentId === focused.id
					&& item.dataset.cbDesignElementAction === focused.action && !item.disabled);
			(current || section.heading).focus();
		}
	};
	update({elements, dynamicData});
	return Object.freeze({
		element: root,
		update,
		destroy: () => {
			if (disposed) return;
			disposed = true;
			generation += 1;
			root.replaceChildren();
		},
	});
};
