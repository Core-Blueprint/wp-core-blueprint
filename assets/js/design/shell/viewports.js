import {decorateDesignerControl} from './icons.js';
import {configureDesignerToolbarPopover} from './toolbar-popover.js';

export const DESIGNER_VIEWPORT_ORDER = Object.freeze(['mobile', 'tablet', 'desktop']);

const VIEWPORT_EVENT = 'cb:design-shell:viewportchange';
const VIEWPORT_ICONS = Object.freeze({
	mobile: 'smartphone',
	tablet: 'tablet',
	desktop: 'monitor',
});
const viewportControllers = new WeakMap();
const controlsFor = (root) => Array.from(root.querySelectorAll('[data-cb-design-shell-viewport]'));

/**
 * One Base viewport state and one set of physical buttons for every consumer.
 * The responsive toolbar primitive controls presentation only.
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
		existing.configure({active, onChange});
		return existing;
	}
	const controls = controlsFor(root);
	if (!controls.length) return null;
	const group = controls[0]?.closest?.('[data-cb-design-shell-viewport-group], .cb-core-design-shell__toolbar-group') ?? null;
	const local = new AbortController();
	let destroyed = false;
	let activeViewport = '';
	let changeHandler = typeof onChange === 'function' ? onChange : null;

	if (group) {
		group.setAttribute('role', 'group');
		group.classList.add('cb-core-design-shell__toolbar-group--viewport');
	}
	controls.forEach((control) => {
		const viewport = String(control.dataset.cbDesignShellViewport || '').trim();
		const label = String(control.textContent || viewport).trim();
		const icon = String(control.dataset.cbDesignShellIcon || VIEWPORT_ICONS[viewport] || '').trim();
		if (icon) decorateDesignerControl(control, icon, {iconOnly: true, label});
	});
	const orderIndex = (control) => {
		const index = DESIGNER_VIEWPORT_ORDER.indexOf(String(control.dataset.cbDesignShellViewport || '').trim());
		return index < 0 ? DESIGNER_VIEWPORT_ORDER.length : index;
	};
	controls.sort((a, b) => orderIndex(a) - orderIndex(b));
	const popover = group ? configureDesignerToolbarPopover(root, {
		group, controls, kind: 'viewport', icon: 'monitor-smartphone',
		label: 'Preview sizes', signal,
	}) : null;
	if (!popover && group) group.append(...controls);
	const trigger = popover?.trigger ?? null;

	const activate = (viewport, {emit = true} = {}) => {
		if (destroyed) return false;
		const value = String(viewport || '').trim();
		const target = controls.find(control => control.dataset.cbDesignShellViewport === value);
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
				detail: Object.freeze({viewport: value}),
			}));
		}
		return true;
	};
	controls.forEach(control => {
		control.addEventListener('click', () => activate(control.dataset.cbDesignShellViewport), {
			signal: local.signal,
		});
	});
	const initial = String(active || '').trim()
		|| controls.find(control => control.getAttribute('aria-pressed') === 'true')?.dataset.cbDesignShellViewport
		|| controls.find(control => control.classList.contains('is-active'))?.dataset.cbDesignShellViewport
		|| controls[0]?.dataset.cbDesignShellViewport || '';
	if (initial) activate(initial, {emit: false});

	const destroy = () => {
		if (destroyed) return;
		destroyed = true;
		popover?.destroy();
		local.abort();
		viewportControllers.delete(root);
	};
	signal?.addEventListener('abort', destroy, {once: true});
	const controller = Object.freeze({
		root, controls: Object.freeze([...controls]), activate,
		configure(configuration = {}) {
			if (destroyed) return false;
			if (Object.hasOwn(configuration, 'onChange')) {
				changeHandler = typeof configuration.onChange === 'function' ? configuration.onChange : null;
			}
			if (configuration.active) activate(configuration.active, {emit: false});
			return true;
		},
		destroy,
		get activeViewport() {return activeViewport;},
	});
	viewportControllers.set(root, controller);
	return controller;
};
