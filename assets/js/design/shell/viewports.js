import { decorateDesignerControl } from './icons.js';

export const DESIGNER_VIEWPORT_ORDER = Object.freeze(['mobile', 'tablet', 'desktop']);

const VIEWPORT_EVENT = 'cb:design-shell:viewportchange';
const VIEWPORT_ICONS = Object.freeze({
	mobile: 'smartphone',
	tablet: 'tablet',
	desktop: 'monitor',
});
const viewportControllers = new WeakMap();
let nextViewportPopoverId = 0;

const controlsFor = (root) => Array.from(root.querySelectorAll('[data-cb-design-shell-viewport]'));

/**
 * Configure Base-owned viewport controls on any Designer Shell that declares
 * data-cb-design-shell-viewport. The same three button nodes serve the inline
 * and compact presentations: state, events and consumer authority stay shared.
 */
export const configureDesignerViewports = (root, {
	active = null,
	onChange = null,
	signal = null,
} = {}) => {
	if (!(root instanceof Element)) {
		throw new TypeError('Designer viewport configuration requires a shell root Element.');
	}
	if (signal?.aborted) {
		throw new TypeError('Designer viewport context has already ended.');
	}

	const existing = viewportControllers.get(root);
	if (existing) {
		existing.configure({ active, onChange });
		return existing;
	}

	const controls = controlsFor(root);
	if (!controls.length) return null;
	const group = controls[0]?.closest?.('[data-cb-design-shell-viewport-group], .cb-core-design-shell__toolbar-group') ?? null;
	const toolbar = group?.closest?.('.cb-core-design-shell__toolbar--designer') ?? null;
	const doc = root.ownerDocument;
	const view = doc?.defaultView;
	const local = new AbortController();
	let destroyed = false;
	let activeViewport = '';
	let changeHandler = typeof onChange === 'function' ? onChange : null;
	let compact = false;
	let open = false;

	if (group) {
		group.setAttribute('role', 'group');
		group.classList.add('cb-core-design-shell__toolbar-group--viewport');
	}

	controls.forEach((control) => {
		const viewport = String(control.dataset.cbDesignShellViewport || '').trim();
		const label = String(control.textContent || viewport).trim();
		const icon = String(control.dataset.cbDesignShellIcon || VIEWPORT_ICONS[viewport] || '').trim();
		if (icon) decorateDesignerControl(control, icon, { iconOnly: true, label });
	});

	const orderIndex = (control) => {
		const viewport = String(control.dataset.cbDesignShellViewport || '').trim();
		const index = DESIGNER_VIEWPORT_ORDER.indexOf(viewport);
		return index < 0 ? DESIGNER_VIEWPORT_ORDER.length : index;
	};
	controls.sort((left, right) => orderIndex(left) - orderIndex(right));

	// A single component owns the presentation. Never clone viewport buttons:
	// cloning would duplicate their event and aria-pressed authorities.
	const trigger = toolbar && group ? doc.createElement('button') : null;
	const options = toolbar && group ? doc.createElement('div') : null;
	if (trigger && options) {
		const id = 'cb-design-viewport-options-' + (++nextViewportPopoverId);
		trigger.type = 'button';
		trigger.className = 'button cb-core-button cb-core-design-shell__viewport-trigger';
		trigger.setAttribute('data-cb-design-shell-viewport-trigger', '');
		trigger.setAttribute('aria-controls', id);
		trigger.setAttribute('aria-expanded', 'false');
		decorateDesignerControl(trigger, 'monitor-smartphone', {
			iconOnly: true, label: 'Choose preview width',
		});
		options.id = id;
		options.className = 'cb-core-design-shell__viewport-options cb-core-design-shell__compact-menu-popover';
		options.setAttribute('role', 'group');
		options.setAttribute('aria-label', 'Preview sizes');
		options.append(...controls);
		group.classList.add('has-adaptive-viewport-switcher');
		group.append(trigger, options);
	} else if (group) {
		group.append(...controls);
	}

	const positionPopover = () => {
		if (!options || !view || !open) return;
		// Keep the popup within the visible viewport even when the toolbar's
		// center track is squeezed toward an edge by long consumer controls.
		options.style.setProperty('--cb-viewport-popover-shift', '0px');
		const rect = options.getBoundingClientRect();
		const rightEdge = doc.documentElement?.clientWidth || view.innerWidth;
		const inset = 8;
		const shift = Math.max(0, inset - rect.left)
			- Math.max(0, rect.right - (rightEdge - inset));
		options.style.setProperty('--cb-viewport-popover-shift', shift + 'px');
	};

	const setOpen = (next, { restoreFocus = false } = {}) => {
		if (!trigger || !options || destroyed) return;
		open = Boolean(next && compact);
		trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
		options.hidden = compact && !open;
		group.classList.toggle('is-viewport-popover-open', open);
		toolbar.classList.toggle('has-viewport-popover-open', open);
		if (open) positionPopover();
		else options.style.setProperty('--cb-viewport-popover-shift', '0px');
		if (restoreFocus && compact) trigger.focus({ preventScroll: true });
	};

	const activate = (viewport, { emit = true } = {}) => {
		if (destroyed) return false;
		const value = String(viewport || '').trim();
		if (!value) return false;
		const target = controls.find((control) => control.dataset.cbDesignShellViewport === value);
		if (!target) return false;
		activeViewport = value;
		root.dataset.cbDesignShellViewport = value;
		controls.forEach((control) => {
			const selected = control === target;
			control.classList.toggle('is-active', selected);
			control.setAttribute('aria-pressed', selected ? 'true' : 'false');
		});
		if (trigger) {
			const label = String(target.getAttribute('aria-label') || value).trim();
			trigger.setAttribute('aria-label', 'Preview width: ' + label);
			trigger.setAttribute('title', 'Preview width: ' + label);
		}
		if (emit) {
			if (changeHandler) changeHandler(value, target);
			root.dispatchEvent(new CustomEvent(VIEWPORT_EVENT, {
				bubbles: true,
				detail: Object.freeze({ viewport: value }),
			}));
		}
		if (open) setOpen(false, { restoreFocus: true });
		return true;
	};

	controls.forEach((control) => {
		control.addEventListener('click', () => activate(control.dataset.cbDesignShellViewport), {
			signal: local.signal,
		});
	});

	const initial = String(active || '').trim()
		|| controls.find((control) => control.getAttribute('aria-pressed') === 'true')?.dataset.cbDesignShellViewport
		|| controls.find((control) => control.classList.contains('is-active'))?.dataset.cbDesignShellViewport
		|| controls[0]?.dataset.cbDesignShellViewport
		|| '';
	if (initial) activate(initial, { emit: false });

	const syncAdaptive = () => {
		if (destroyed || !trigger || !options || !view || !toolbar.isConnected) return;
		const toolbarWidth = toolbar.clientWidth;
		if (!(toolbarWidth > 0)) return;
		const start = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--start');
		const end = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--end');
		const style = view.getComputedStyle(toolbar);
		const gap = Number.parseFloat(style.columnGap) || 8;
		const padding = (Number.parseFloat(style.paddingLeft) || 0)
			+ (Number.parseFloat(style.paddingRight) || 0);
		const widthOf = (element) => element
			? Math.max(element.scrollWidth || 0, element.getBoundingClientRect().width || 0) : 0;
		const controlSize = Number.parseFloat(view.getComputedStyle(controls[0]).width) || 44;
		const optionGap = Number.parseFloat(view.getComputedStyle(options).columnGap) || 8;
		const inlineWidth = (controlSize * controls.length) + (optionGap * Math.max(0, controls.length - 1));
		const available = toolbarWidth - padding - widthOf(start) - widthOf(end) - (2 * gap);
		// Account for breathing room around the center controls, with 20px of
		// hysteresis after collapsing so font/resize observers cannot oscillate.
		const next = toolbar.classList.contains('is-compact')
			|| available < inlineWidth + (compact ? 36 : 16);
		if (compact === next) {
			if (open) positionPopover();
			return;
		}
		compact = next;
		group.classList.toggle('is-viewport-compact', compact);
		setOpen(false);
	};

	let resize = null;
	if (trigger && options) {
		trigger.addEventListener('click', () => setOpen(!open), { signal: local.signal });
		group.addEventListener('keydown', (event) => {
			if (!open) return;
			if (event.key === 'Escape') {
				event.preventDefault();
				event.stopPropagation();
				setOpen(false, { restoreFocus: true });
			} else if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)
					&& controls.includes(doc.activeElement)) {
				event.preventDefault();
				const index = controls.indexOf(doc.activeElement);
				const next = event.key === 'Home' ? 0
					: event.key === 'End' ? controls.length - 1
						: (index + (event.key === 'ArrowRight' ? 1 : -1) + controls.length) % controls.length;
				controls[next].focus({ preventScroll: true });
			}
		}, { signal: local.signal });
		doc.addEventListener('pointerdown', (event) => {
			if (open && !group.contains(event.target)) setOpen(false);
		}, { signal: local.signal });
		doc.addEventListener('focusin', (event) => {
			if (open && !group.contains(event.target)) setOpen(false);
		}, { signal: local.signal });
		if (typeof view?.ResizeObserver === 'function') {
			resize = new view.ResizeObserver(syncAdaptive);
			resize.observe(toolbar);
			const start = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--start');
			const end = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--end');
			if (start) resize.observe(start);
			if (end) resize.observe(end);
		}
		view?.addEventListener?.('resize', syncAdaptive, { signal: local.signal, passive: true });
		view?.document?.fonts?.ready?.then(() => {
			if (!destroyed) syncAdaptive();
		});
		syncAdaptive();
	}

	const destroy = () => {
		if (destroyed) return;
		setOpen(false);
		destroyed = true;
		resize?.disconnect();
		local.abort();
		viewportControllers.delete(root);
	};
	signal?.addEventListener('abort', destroy, { once: true });

	const controller = Object.freeze({
		root,
		controls: Object.freeze([...controls]),
		activate,
		configure(configuration = {}) {
			if (destroyed) return false;
			if (Object.hasOwn(configuration, 'onChange')) {
				changeHandler = typeof configuration.onChange === 'function' ? configuration.onChange : null;
			}
			if (configuration.active) activate(configuration.active, { emit: false });
			return true;
		},
		destroy,
		get activeViewport() { return activeViewport; },
	});
	viewportControllers.set(root, controller);
	return controller;
};
