/**
 * Optional Base-owned task navigation for the left Designer rail.
 *
 * Consumers own stage definitions, ordering, status meaning and domain routing.
 * Base owns the accessible navigation DOM, active state, scoped target focus
 * and presentation. This is not the Layers object hierarchy.
 */
export const DESIGNER_PALETTE_ROLES = Object.freeze(['navigation', 'elements', 'dynamic-data']);

const STATUS_TONES = new Set(['neutral', 'success', 'warning', 'danger']);

const normalizedItem = (item) => {
	if (!item || typeof item !== 'object' || Array.isArray(item)) {
		throw new TypeError('Designer navigation items must be objects.');
	}
	const id = String(item.id || '').trim();
	const label = String(item.label || '').trim();
	if (!id || !label) {
		throw new TypeError('Designer navigation items require a non-empty id and label.');
	}
	const targetId = String(item.targetId || '').trim();
	const status = item.status && typeof item.status === 'object' && !Array.isArray(item.status)
		? {
			label: String(item.status.label || '').trim(),
			tone: STATUS_TONES.has(item.status.tone) ? item.status.tone : 'neutral',
		}
		: null;
	return Object.freeze({
		id,
		label,
		description: String(item.description || '').trim(),
		step: String(item.step || '').trim(),
		targetId,
		disabled: item.disabled === true,
		status: status?.label ? Object.freeze(status) : null,
	});
};

/**
 * @param {object} options
 * @param {Array<object>} options.items - Stable IDs, translated labels and optional
 *   description, step, targetId, disabled and status: {label, tone}.
 * @param {Element|null} options.root - Designer shell that bounds target lookup.
 * @param {function|null} options.onNavigate - Optional consumer callback receiving
 *   ({item, target, event}). Return false to cancel navigation.
 * @returns {{element: HTMLElement, setActive: function, update: function, destroy: function}}
 */
export const createDesignerNavigation = ({
	items = [],
	activeId = '',
	ariaLabel = 'Navigation',
	root = null,
	onNavigate = null,
	documentRef = typeof document !== 'undefined' ? document : null,
} = {}) => {
	if (!documentRef?.createElement) {
		throw new TypeError('Designer navigation requires a DOM Document.');
	}
	if (root !== null && (typeof root.contains !== 'function' || root.ownerDocument !== documentRef)) {
		throw new TypeError('Designer navigation root must belong to the supplied Document.');
	}
	if (onNavigate !== null && typeof onNavigate !== 'function') {
		throw new TypeError('Designer navigation onNavigate must be a function.');
	}

	const nav = documentRef.createElement('nav');
	nav.className = 'cb-core-design-shell__navigation';
	nav.setAttribute('aria-label', String(ariaLabel || '').trim() || 'Navigation');
	const list = documentRef.createElement('ol');
	list.className = 'cb-core-design-shell__navigation-list';
	nav.append(list);
	const controls = new Map();
	let currentItems = [];
	let currentId = String(activeId || '').trim();
	let destroyed = false;

	const assertActive = () => {
		if (destroyed) throw new Error('Designer navigation has been destroyed.');
	};

	const setActive = (id) => {
		assertActive();
		const key = String(id || '').trim();
		if (key && !controls.has(key)) return false;
		currentId = key;
		controls.forEach((control, controlId) => {
			const selected = controlId === currentId;
			control.classList.toggle('is-active', selected);
			if (selected) control.setAttribute('aria-current', 'location');
			else control.removeAttribute('aria-current');
		});
		return true;
	};

	const findTarget = (item) => {
		if (!item.targetId) return null;
		const scope = root || nav.closest?.('[data-cb-design-shell]');
		const target = documentRef.getElementById?.(item.targetId);
		return scope && target && scope.contains(target) ? target : null;
	};

	const navigate = (item, event) => {
		if (item.disabled || destroyed) return;
		const target = findTarget(item);
		if (typeof onNavigate === 'function') {
			if (onNavigate({ item, target, event }) === false) return;
		} else if (!target) {
			return;
		}
		setActive(item.id);
		if (!target || target.hidden || target.closest?.('[hidden]')) return;
		target.scrollIntoView?.({ block: 'start', behavior: 'auto' });
		if (typeof target.focus === 'function') {
			const added = !target.hasAttribute?.('tabindex');
			if (added) target.setAttribute('tabindex', '-1');
			target.focus({ preventScroll: true });
			if (added) target.addEventListener?.('blur', () => target.removeAttribute('tabindex'), { once: true });
		}
	};

	const update = (nextItems, { activeId: nextActiveId } = {}) => {
		assertActive();
		if (!Array.isArray(nextItems)) throw new TypeError('Designer navigation items must be an array.');
		const prepared = nextItems.map(normalizedItem);
		const known = new Set();
		prepared.forEach((item) => {
			if (known.has(item.id)) throw new RangeError('Duplicate Designer navigation item id: ' + item.id);
			known.add(item.id);
		});
		const focusedKey = [...controls].find(([, control]) => documentRef.activeElement === control)?.[0];
		const fragment = documentRef.createDocumentFragment();
		const nextControls = new Map();
		prepared.forEach((item) => {
			const row = documentRef.createElement('li');
			row.className = 'cb-core-design-shell__navigation-item';
			const control = documentRef.createElement(item.targetId ? 'a' : 'button');
			control.className = 'cb-core-design-shell__navigation-link';
			control.dataset.cbDesignNavigationId = item.id;
			if (item.targetId) control.setAttribute('href', '#' + encodeURIComponent(item.targetId));
			else control.type = 'button';
			if (item.disabled) {
				if (item.targetId) {
					control.setAttribute('aria-disabled', 'true');
					control.tabIndex = -1;
				} else control.disabled = true;
			}
			if (item.step) {
				const number = documentRef.createElement('span');
				number.className = 'cb-core-design-shell__navigation-step';
				number.textContent = item.step;
				number.setAttribute('aria-hidden', 'true');
				control.append(number);
			}
			const copy = documentRef.createElement('span');
			copy.className = 'cb-core-design-shell__navigation-copy';
			const title = documentRef.createElement('span');
			title.className = 'cb-core-design-shell__navigation-label';
			title.textContent = item.label;
			copy.append(title);
			if (item.description) {
				const description = documentRef.createElement('span');
				description.className = 'cb-core-design-shell__navigation-description';
				description.textContent = item.description;
				copy.append(description);
			}
			control.append(copy);
			if (item.status) {
				const status = documentRef.createElement('span');
				status.className = 'cb-core-design-shell__navigation-status';
				status.dataset.tone = item.status.tone;
				status.textContent = item.status.label;
				control.append(status);
			}
			control.addEventListener('click', (event) => {
				event.preventDefault();
				navigate(item, event);
			});
			row.append(control);
			fragment.append(row);
			nextControls.set(item.id, control);
		});
		currentItems = prepared;
		controls.clear();
		nextControls.forEach((control, id) => controls.set(id, control));
		list.replaceChildren(fragment);
		const desired = nextActiveId === undefined ? currentId : String(nextActiveId || '').trim();
		setActive(known.has(desired) ? desired : '');
		if (focusedKey && controls.has(focusedKey)) controls.get(focusedKey).focus();
		return currentItems.length;
	};

	const destroy = () => {
		if (destroyed) return;
		destroyed = true;
		controls.clear();
		currentItems = [];
		list.replaceChildren();
		nav.remove();
	};

	update(items);
	return Object.freeze({ element: nav, setActive, update, destroy });
};
