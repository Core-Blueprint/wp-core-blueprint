/**
 * Optional Base Designer Context Menu v1.
 * Flat, accessible transient action presenter; never grants domain authority.
 */
const activeMenus = new WeakMap();
const EDITABLE = 'input, textarea, select, [contenteditable]';
const ID = /^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$/;
const isElement = (el, doc) => el && el.ownerDocument === doc
	&& typeof el.addEventListener === 'function' && typeof el.contains === 'function';

export const createDesignerContextMenu = ({
	root, viewport, getContextKey, getItems, canInvoke, onInvoke,
	ariaLabel = 'Canvas actions',
} = {}) => {
	const doc = root?.ownerDocument;
	if (!isElement(root, doc) || !isElement(viewport, doc)
		|| !root.contains(viewport) || !doc?.body
		|| typeof getContextKey !== 'function' || typeof getItems !== 'function'
		|| typeof canInvoke !== 'function' || typeof onInvoke !== 'function'
		|| activeMenus.has(viewport)) {
		throw new TypeError('DESIGNER_CONTEXT_MENU_CONFIG_INVALID');
	}
	const win = doc.defaultView;
	const targets = new Map();
	const listeners = [];
	const menu = doc.createElement('div');
	menu.className = 'cb-core-design-context-menu';
	menu.setAttribute('role', 'menu');
	menu.setAttribute('aria-label', String(ariaLabel || 'Canvas actions'));
	menu.hidden = true;
	let destroyed = false;
	let opened = null;
	let items = [];
	let activeIndex = 0;
	const listen = (element, name, handler, options) => {
		element.addEventListener(name, handler, options);
		listeners.push(() => element.removeEventListener(name, handler, options));
	};
	const assertActive = () => {
		if (destroyed) throw new Error('DESIGNER_CONTEXT_MENU_DESTROYED');
	};
	const currentContext = () => {
		const key = getContextKey();
		if (typeof key !== 'string' || !key.trim()) {
			throw new TypeError('DESIGNER_CONTEXT_MENU_CONTEXT_INVALID');
		}
		return key;
	};
	const registeredFor = element => {
		let node = element;
		while (node && node !== viewport) {
			if (targets.has(node)) {
				return {element: node, target: targets.get(node)};
			}
			node = node.parentElement;
		}
		return null;
	};
	const isBackground = element => element === viewport
		|| element?.closest?.('[data-cb-design-canvas-background]')
		|| element?.closest?.('[data-cb-design-canvas-scene]') === element;
	const normalizedItems = target => {
		const supplied = getItems(Object.freeze({target}));
		if (!Array.isArray(supplied) || supplied.length > 50) return [];
		const seen = new Set();
		const prepared = [];
		for (const item of supplied) {
			if (!item || typeof item !== 'object' || !ID.test(item.id)
				|| typeof item.label !== 'string' || !item.label.trim()
				|| seen.has(item.id) || item.children !== undefined) return [];
			seen.add(item.id);
			prepared.push(Object.freeze({id: item.id, label: item.label.trim(), disabled: item.disabled === true}));
		}
		return prepared;
	};
	const focusAt = index => {
		if (!items.length) return;
		activeIndex = (index + items.length) % items.length;
		Array.from(menu.children).forEach((button, i) => {
			button.tabIndex = i === activeIndex ? 0 : -1;
		});
		menu.children[activeIndex]?.focus();
	};
	const close = ({restoreFocus = true} = {}) => {
		if (!opened) return false;
		const previous = opened;
		opened = null;
		items = [];
		menu.hidden = true;
		menu.replaceChildren();
		if (restoreFocus) {
			const target = previous.invoker?.isConnected !== false
				? previous.invoker : viewport;
			(target?.focus || viewport.focus)?.call(target || viewport, {preventScroll: true});
		}
		if (typeof CustomEvent === 'function') {
			root.dispatchEvent(new CustomEvent('cb:design-context-menu:close', {bubbles: true}));
		}
		return true;
	};
	const invoke = actionId => {
		if (!opened) return false;
		const entry = opened;
		let allowed = false;
		try {
			const validTarget = entry.element === null
				|| (entry.element.isConnected !== false
					&& viewport.contains(entry.element)
					&& targets.get(entry.element) === entry.target);
			if (validTarget && currentContext() === entry.context) {
				const fresh = normalizedItems(entry.target).find(item => item.id === actionId);
				if (fresh && !fresh.disabled) {
					allowed = canInvoke(Object.freeze({actionId, target: entry.target})) === true;
				}
			}
		} catch (_) {
			allowed = false;
		}
		close();
		if (!allowed) return false;
		try {
			onInvoke(Object.freeze({actionId, target: entry.target}));
			return true;
		} catch (_) {
			// Consumer is responsible for reporting its own command error.
			return false;
		}
	};
	const open = ({target = null, clientX = null, clientY = null, invoker = null} = {}) => {
		assertActive();
		close({restoreFocus: false});
		const record = target && typeof target === 'object' ? target : Object.freeze({kind: 'canvas', id: 'canvas'});
		const match = [...targets].find(([, value]) => value === record);
		const element = match?.[0] || null;
		if (target && !element && record.kind !== 'canvas') return false;
		let supplied;
		let context;
		try {
			context = currentContext();
			supplied = normalizedItems(record);
		} catch (_) {
			return false;
		}
		if (!supplied.length) return false;
		const candidateInvoker = invoker?.isConnected !== false && typeof invoker?.focus === 'function'
			? invoker : element || viewport;
		opened = {target: record, element, context, invoker: candidateInvoker};
		items = supplied;
		const fragment = doc.createDocumentFragment();
		for (const item of supplied) {
			const button = doc.createElement('button');
			button.type = 'button';
			button.className = 'cb-core-design-context-menu__item';
			button.setAttribute('role', 'menuitem');
			button.dataset.cbDesignContextAction = item.id;
			button.textContent = item.label;
			button.tabIndex = -1;
			if (item.disabled) button.setAttribute('aria-disabled', 'true');
			button.addEventListener('click', () => invoke(item.id));
			fragment.append(button);
		}
		menu.replaceChildren(fragment);
		menu.hidden = false;
		menu.style.visibility = 'hidden';
		menu.style.left = '0px';
		menu.style.top = '0px';
		const anchor = candidateInvoker?.getBoundingClientRect?.() || viewport.getBoundingClientRect();
		const x = Number.isFinite(clientX) ? clientX : anchor.left;
		const y = Number.isFinite(clientY) ? clientY : anchor.bottom;
		const box = menu.getBoundingClientRect();
		const width = win?.innerWidth || doc.documentElement.clientWidth || 1024;
		const height = win?.innerHeight || doc.documentElement.clientHeight || 768;
		menu.style.left = Math.max(4, Math.min(x, width - box.width - 4)) + 'px';
		menu.style.top = Math.max(4, Math.min(y, height - box.height - 4)) + 'px';
		menu.style.visibility = '';
		const index = items.findIndex(item => !item.disabled);
		focusAt(index >= 0 ? index : 0);
		if (typeof CustomEvent === 'function') {
			root.dispatchEvent(new CustomEvent('cb:design-context-menu:open', {
				bubbles: true, detail: Object.freeze({target: record}),
			}));
		}
		return true;
	};
	const registerTarget = (element, {kind, id} = {}) => {
		assertActive();
		if (!isElement(element, doc) || !viewport.contains(element)
			|| targets.has(element) || !ID.test(kind) || !ID.test(id)) {
			throw new TypeError('DESIGNER_CONTEXT_MENU_TARGET_INVALID');
		}
		const descriptor = Object.freeze({kind, id});
		targets.set(element, descriptor);
		return () => {
			if (!targets.has(element)) return false;
			if (opened?.element === element) close();
			targets.delete(element);
			return true;
		};
	};
	const onContextMenu = event => {
		if (destroyed || event.target?.closest?.(EDITABLE)) return;
		const matched = registeredFor(event.target);
		if (!matched && !isBackground(event.target)) return;
		const invoked = matched
			? open({target: matched.target, clientX: event.clientX, clientY: event.clientY,
				invoker: event.target?.focus ? event.target : matched.element})
			: open({clientX: event.clientX, clientY: event.clientY, invoker: viewport});
		if (invoked) event.preventDefault();
	};
	const onRootKeyDown = event => {
		if (event.key !== 'F10' || !event.shiftKey || event.altKey || event.ctrlKey || event.metaKey
			|| event.target?.closest?.(EDITABLE) || !viewport.contains(event.target) && event.target !== viewport) return;
		const matched = registeredFor(event.target);
		if (open({target: matched?.target || null, invoker: event.target})) event.preventDefault();
	};
	const onMenuKeyDown = event => {
		if (!opened) return;
		let index = activeIndex;
		if (event.key === 'Escape') {
			event.preventDefault();
			close();
			return;
		}
		if (event.key === 'ArrowDown') index++;
		else if (event.key === 'ArrowUp') index--;
		else if (event.key === 'Home') index = 0;
		else if (event.key === 'End') index = items.length - 1;
		else if (event.key === 'Enter' || event.key === ' ') {
			event.preventDefault();
			invoke(items[activeIndex]?.id);
			return;
		} else if (event.key === 'Tab') {
			close({restoreFocus: false});
			return;
		} else return;
		event.preventDefault();
		focusAt(index);
	};
	const onDocumentPointer = event => {
		if (opened && !menu.contains(event.target)) close({restoreFocus: false});
	};
	const onFocusOutside = event => {
		if (opened && !menu.contains(event.target)) close({restoreFocus: false});
	};
	listen(viewport, 'contextmenu', onContextMenu);
	listen(root, 'keydown', onRootKeyDown);
	listen(menu, 'keydown', onMenuKeyDown);
	listen(doc, 'pointerdown', onDocumentPointer, true);
	listen(doc, 'focusin', onFocusOutside, true);
	listen(root, 'cb:design-shell:contextchanged', () => close());
	doc.body.append(menu);
	activeMenus.set(viewport, true);
	return Object.freeze({
		registerTarget, open, close,
		destroy() {
			if (destroyed) return;
			close({restoreFocus: false});
			destroyed = true;
			listeners.splice(0).forEach(off => off());
			targets.clear();
			menu.remove();
			activeMenus.delete(viewport);
		},
	});
};
