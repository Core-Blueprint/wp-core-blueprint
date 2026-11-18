/**
 * Base-owned progressive-enhancement controller for generic row action menus.
 * No business requests, permission decisions, mutation or confirmation logic.
 * Panels are portaled to body while open to escape scrollable table clipping.
 */
let active = null;
const selector = '[data-cb-core-row-menu]';
const triggerSelector = '[data-cb-core-row-menu-trigger]';
const panelSelector = '[data-cb-core-row-menu-panel]';

const triggerFor = root => root?.querySelector(triggerSelector);
const panelFor = root => root?.querySelector(panelSelector);
const itemsFor = panel => Array.from(panel?.querySelectorAll('[role="menuitem"]') ?? [])
	.filter(item => !item.disabled && item.getAttribute('aria-disabled') !== 'true');

function enhance() {
	document.querySelectorAll(selector).forEach(root => {
		const trigger = triggerFor(root);
		if (trigger && panelFor(root)) trigger.hidden = false;
	});
}

function close(restoreFocus = false) {
	if (!active) return;
	const {root, trigger, panel} = active;
	active = null;
	panel.hidden = true;
	panel.style.removeProperty('left');
	panel.style.removeProperty('top');
	root.appendChild(panel);
	root.classList.remove('is-open');
	trigger.setAttribute('aria-expanded', 'false');
	if (restoreFocus && trigger.isConnected) trigger.focus();
}

function place() {
	if (!active) return;
	const {trigger, panel} = active;
	if (!trigger.isConnected || !panel.isConnected) {
		close();
		return;
	}
	const rect = trigger.getBoundingClientRect();
	if (rect.bottom < 0 || rect.top > window.innerHeight) {
		close();
		return;
	}
	const margin = 8;
	const width = panel.offsetWidth;
	const height = panel.offsetHeight;
	const left = Math.max(margin, Math.min(rect.right - width, window.innerWidth - width - margin));
	const below = rect.bottom + 6;
	const top = below + height <= window.innerHeight - margin
		? below
		: Math.max(margin, rect.top - height - 6);
	panel.style.left = Math.round(left) + 'px';
	panel.style.top = Math.round(top) + 'px';
}

function open(root, focusFirst = false) {
	const trigger = triggerFor(root);
	const panel = panelFor(root);
	if (!trigger || !panel || trigger.hidden) return;
	close();
	root.classList.add('is-open');
	trigger.setAttribute('aria-expanded', 'true');
	document.body.appendChild(panel);
	panel.hidden = false;
	active = {root, trigger, panel};
	place();
	if (focusFirst) itemsFor(panel)[0]?.focus();
}

document.addEventListener('click', event => {
	const trigger = event.target.closest?.(triggerSelector);
	if (trigger) {
		const root = trigger.closest(selector);
		if (!root) return;
		event.preventDefault();
		const alreadyOpen = active?.root === root;
		if (alreadyOpen) close();
		else open(root);
		return;
	}
	if (!active) return;
	if (active.panel.contains(event.target)) {
		const item = event.target.closest?.('[role="menuitem"]');
		if (item) close();
		return;
	}
	close();
});

document.addEventListener('keydown', event => {
	if (!active) {
		const trigger = event.target.closest?.(triggerSelector);
		if (trigger && ['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) {
			event.preventDefault();
			open(trigger.closest(selector), true);
		}
		return;
	}
	const {trigger, panel} = active;
	if (event.key === 'Escape') {
		event.preventDefault();
		close(true);
		return;
	}
	if (event.target !== trigger && !panel.contains(event.target)) return;
	const items = itemsFor(panel);
	if (!items.length) return;
	if (event.target === trigger && ['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) {
		event.preventDefault();
		items[event.key === 'ArrowUp' ? items.length - 1 : 0].focus();
		return;
	}
	const index = items.indexOf(document.activeElement);
	if (index < 0) return;
	let next = index;
	if (event.key === 'ArrowDown') next = (index + 1) % items.length;
	else if (event.key === 'ArrowUp') next = (index - 1 + items.length) % items.length;
	else if (event.key === 'Home') next = 0;
	else if (event.key === 'End') next = items.length - 1;
	else return;
	event.preventDefault();
	items[next].focus();
});

document.addEventListener('focusin', event => {
	if (active && !active.panel.contains(event.target) && event.target !== active.trigger) {
		close();
	}
});
document.addEventListener('scroll', () => { if (active) place(); }, true);
window.addEventListener('resize', () => { if (active) place(); });
document.addEventListener('cb:management-workspace:rendered', enhance);
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', enhance, {once: true});
} else {
	enhance();
}
