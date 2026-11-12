import {decorateDesignerControl} from './icons.js';

const controllers = new WeakMap();
let nextPopoverId = 0;

/**
 * Shared Base toolbar presentation. Never creates a second command or button:
 * the caller's real buttons remain the only action/disabled/pressed authority.
 * Desktop (>=1281px) is inline; tablet and mobile (<=1280px) are a popover.
 */
export const configureDesignerToolbarPopover = (root, {
	group,
	controls,
	kind,
	icon,
	label,
	signal = null,
} = {}) => {
	if (!(root instanceof Element) || !group || !Array.isArray(controls) ||
		!controls.length || !['viewport', 'history'].includes(kind)) {
		throw new TypeError('Designer adaptive toolbar requires a root, group and actual controls.');
	}
	if (signal?.aborted) throw new TypeError('Designer toolbar context has already ended.');
	const existing = controllers.get(group);
	if (existing) return existing;
	const toolbar = group.closest('.cb-core-design-shell__toolbar--designer');
	const doc = root.ownerDocument;
	const view = doc?.defaultView;
	if (!toolbar || !view) return null;
	const local = new AbortController();
	const id = 'cb-design-toolbar-popover-' + (++nextPopoverId);
	const trigger = doc.createElement('button');
	trigger.type = 'button';
	trigger.className = 'button cb-core-button cb-core-design-shell__adaptive-trigger cb-core-design-shell__' + kind + '-trigger';
	trigger.setAttribute('data-cb-design-shell-' + kind + '-trigger', '');
	trigger.setAttribute('aria-controls', id);
	trigger.setAttribute('aria-expanded', 'false');
	decorateDesignerControl(trigger, icon, {iconOnly: true, label});
	const options = doc.createElement('div');
	options.id = id;
	options.className = 'cb-core-design-shell__adaptive-options cb-core-design-shell__' + kind + '-options cb-core-design-shell__compact-menu-popover';
	options.setAttribute('role', 'group');
	options.setAttribute('aria-label', label);
	options.append(...controls);
	group.classList.add('cb-core-design-shell__toolbar-group--adaptive', 'has-adaptive-' + kind + '-switcher');
	group.append(trigger, options);

	let destroyed = false;
	let compact = false;
	let open = false;
	const media = view.matchMedia?.('(max-width: 1280px)') ?? null;
	const narrowMedia = view.matchMedia?.('(max-width: 700px)') ?? null;
	const position = () => {
		if (!open) return;
		options.style.setProperty('--cb-toolbar-popover-shift', '0px');
		// On extremely narrow screens, the single-row toolbar is horizontally
		// scrollable. Anchor the popup to the *visible* trigger in viewport
		// coordinates so the menu cannot be clipped by the scroll container.
		if (narrowMedia?.matches) {
			const anchor = trigger.getBoundingClientRect();
			const bounds = options.getBoundingClientRect();
			const width = doc.documentElement?.clientWidth || view.innerWidth;
			const height = doc.documentElement?.clientHeight || view.innerHeight;
			const inset = 8;
			const left = Math.max(inset, Math.min(
				anchor.left + (anchor.width - bounds.width) / 2, width - bounds.width - inset,
			));
			const top = Math.max(inset, Math.min(anchor.bottom + inset, height - bounds.height - inset));
			options.style.setProperty('--cb-toolbar-popover-fixed-left', left + 'px');
			options.style.setProperty('--cb-toolbar-popover-fixed-top', top + 'px');
			return;
		}
		const bounds = options.getBoundingClientRect();
		const width = doc.documentElement?.clientWidth || view.innerWidth;
		const inset = 8;
		const shift = Math.max(0, inset - bounds.left) - Math.max(0, bounds.right - (width - inset));
		options.style.setProperty('--cb-toolbar-popover-shift', shift + 'px');
	};
	const setOpen = (next, {restoreFocus = false} = {}) => {
		if (destroyed) return;
		open = Boolean(next && compact);
		trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
		options.hidden = compact && !open;
		group.classList.toggle('is-adaptive-popover-open', open);
		toolbar.classList.toggle('has-' + kind + '-popover-open', open);
		if (open) position();
		else options.style.setProperty('--cb-toolbar-popover-shift', '0px');
		if (restoreFocus && compact) trigger.focus({preventScroll: true});
	};
	const sync = () => {
		if (destroyed || !media) return;
		const next = media.matches;
		if (compact === next) {
			if (open) position();
			return;
		}
		compact = next;
		group.classList.toggle('is-adaptive-compact', compact);
		setOpen(false);
	};
	trigger.addEventListener('click', () => setOpen(!open), {signal: local.signal});
	for (const control of controls) {
		// The original command listener remains the only source of mutation.
		// Disabled buttons never fire click, and do not close the menu.
		control.addEventListener('click', () => {
			if (open) setOpen(false, {restoreFocus: true});
		}, {signal: local.signal});
	}
	group.addEventListener('keydown', (event) => {
		if (!open) return;
		if (event.key === 'Escape') {
			event.preventDefault();
			event.stopPropagation();
			setOpen(false, {restoreFocus: true});
			return;
		}
		if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) ||
			!controls.includes(doc.activeElement)) return;
		event.preventDefault();
		const index = controls.indexOf(doc.activeElement);
		const step = event.key === 'ArrowRight' ? 1 : -1;
		const target = event.key === 'Home' ? 0 : event.key === 'End'
			? controls.length - 1 : (index + step + controls.length) % controls.length;
		// Skip unavailable History actions but keep their disabled state visible.
		for (let i = 0; i < controls.length; i++) {
			const candidate = controls[(target + i * (step === 1 ? 1 : -1) + controls.length * 2) % controls.length];
			if (!candidate.disabled) {
				candidate.focus({preventScroll: true});
				break;
			}
		}
	}, {signal: local.signal});
	doc.addEventListener('pointerdown', (event) => {
		if (open && !group.contains(event.target)) setOpen(false);
	}, {signal: local.signal});
	doc.addEventListener('focusin', (event) => {
		if (open && !group.contains(event.target)) setOpen(false);
	}, {signal: local.signal});
	media?.addEventListener?.('change', sync, {signal: local.signal});
	const observer = typeof view.ResizeObserver === 'function'
		? new view.ResizeObserver(sync) : null;
	observer?.observe(toolbar);
	view.addEventListener?.('resize', sync, {signal: local.signal, passive: true});
	toolbar.addEventListener('scroll', position, {signal: local.signal, passive: true});
	sync();

	const destroy = () => {
		if (destroyed) return;
		setOpen(false);
		destroyed = true;
		observer?.disconnect();
		local.abort();
		controllers.delete(group);
		group.append(...controls);
		options.remove();
		trigger.remove();
		group.classList.remove('cb-core-design-shell__toolbar-group--adaptive', 'has-adaptive-' + kind + '-switcher',
			'is-adaptive-compact', 'is-adaptive-popover-open');
	};
	signal?.addEventListener('abort', destroy, {once: true});
	const controller = Object.freeze({
		root, group, controls: Object.freeze([...controls]), trigger, options,
		setOpen, destroy,
		get compact() {return compact;},
		get open() {return open;},
	});
	controllers.set(group, controller);
	return controller;
};
