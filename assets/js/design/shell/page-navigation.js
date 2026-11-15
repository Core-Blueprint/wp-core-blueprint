/**
 * DP-NAV-01 — Base-owned, presentation-only document page navigation.
 *
 * A trusted consumer supplies the actual pages and navigation callback. Flow
 * HTML previews are continuous: Base must never infer PDF pages from height.
 * This controller never changes document width, History, Save or iframe data.
 */
const controllers = new WeakMap();
const PAGE_ID = /^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/;
const MAX_PAGES = 500;
let sequence = 0;

const normalize = ({pages = null, activePageId = '', onNavigate = null} = {}) => {
	if (pages !== null && (!Array.isArray(pages) || pages.length > MAX_PAGES)) {
		throw new TypeError('DESIGNER_PAGE_NAVIGATION_PAGES_INVALID');
	}
	if (onNavigate !== null && typeof onNavigate !== 'function') {
		throw new TypeError('DESIGNER_PAGE_NAVIGATION_CALLBACK_INVALID');
	}
	if (typeof activePageId !== 'string') {
		throw new TypeError('DESIGNER_PAGE_NAVIGATION_ACTIVE_INVALID');
	}
	const seen = new Set();
	const prepared = pages?.map(page => {
		if (!page || typeof page !== 'object' || Array.isArray(page)
			|| typeof page.id !== 'string' || !PAGE_ID.test(page.id)
			|| typeof page.label !== 'string' || !page.label.trim()
			|| page.label.length > 120 || /[\x00-\x1f\x7f]/.test(page.label)
			|| seen.has(page.id)) {
			throw new TypeError('DESIGNER_PAGE_NAVIGATION_PAGE_INVALID');
		}
		seen.add(page.id);
		return Object.freeze({id: page.id, label: page.label.trim()});
	}) ?? null;
	if (prepared?.length ? !seen.has(activePageId) : activePageId !== '') {
		throw new TypeError('DESIGNER_PAGE_NAVIGATION_ACTIVE_INVALID');
	}
	return Object.freeze({
		pages: prepared === null ? null : Object.freeze(prepared),
		activePageId, onNavigate,
	});
};

/**
 * Configure a document toolbar already owned by the shared Designer Shell.
 * Pages are trusted, bounded page descriptors, NOT derived screen heights.
 *
 * @param {Element} root - Active Designer Shell root.
 * @param {{pages?:null|Array<{id:string,label:string}>,activePageId?:string,
 *          onNavigate?:null|function,signal?:AbortSignal}} options
 * @returns {null|{update:function,setActivePage:function,destroy:function,pages:?,activePageId:string}}
 */
export const configureDesignerPageNavigation = (root, {
	pages = null, activePageId = '', onNavigate = null, signal = null,
} = {}) => {
	if (!(root instanceof Element) || signal?.aborted) {
		throw new TypeError('DESIGNER_PAGE_NAVIGATION_ROOT_INVALID');
	}
	const initial = normalize({pages, activePageId, onNavigate});
	const existing = controllers.get(root);
	if (existing) {
		if (pages !== null || activePageId !== '' || onNavigate !== null) existing.update(initial);
		return existing;
	}
	const trigger = root.querySelector('[data-cb-design-shell-page-navigation]');
	if (!trigger) return null;
	const group = trigger.parentElement;
	const doc = root.ownerDocument;
	const view = doc?.defaultView;
	if (!group || !root.contains(group) || !doc?.createElement || !view) {
		throw new TypeError('DESIGNER_PAGE_NAVIGATION_ROOT_INVALID');
	}
	const toolbar = trigger.closest('.cb-core-design-shell__toolbar--designer');
	const menu = doc.createElement('div');
	menu.className = 'cb-core-design-shell__page-menu';
	menu.id = 'cb-core-design-pages-' + (++sequence);
	menu.hidden = true;
	menu.setAttribute('role', 'menu');
	menu.setAttribute('aria-label', 'Document pages');
	menu.setAttribute('data-cb-design-shell-page-menu', '');
	group.append(menu);
	trigger.setAttribute('aria-haspopup', 'menu');
	trigger.setAttribute('aria-controls', menu.id);
	trigger.setAttribute('aria-expanded', 'false');
	const local = new AbortController();
	let current = initial;
	let items = [];
	let generation = 0;
	let opened = false;
	let destroyed = false;

	const setActiveLabel = () => {
		const count = current.pages?.length || 0;
		const active = current.pages?.find(page => page.id === current.activePageId);
		const label = count === 0 ? 'Page navigation unavailable'
			: count === 1 ? 'Page 1 of 1'
			: 'Pages: ' + (active?.label || '');
		trigger.setAttribute('aria-label', label);
		trigger.setAttribute('title', label);
		items.forEach(({page, button}) => button.setAttribute(
			'aria-checked', page.id === current.activePageId ? 'true' : 'false'
		));
	};
	const close = ({restoreFocus = false} = {}) => {
		if (!opened) return;
		opened = false;
		menu.hidden = true;
		trigger.setAttribute('aria-expanded', 'false');
		toolbar?.classList.remove('has-page-navigation-open');
		if (restoreFocus && trigger.isConnected && !trigger.disabled) trigger.focus({preventScroll: true});
	};
	const position = () => {
		if (!opened) return;
		const anchor = trigger.getBoundingClientRect();
		const bounds = menu.getBoundingClientRect();
		const width = doc.documentElement?.clientWidth || view.innerWidth || 0;
		const height = doc.documentElement?.clientHeight || view.innerHeight || 0;
		if (!width || !height) return;
		const inset = 8;
		const left = Math.max(inset, Math.min(anchor.left + (anchor.width - bounds.width) / 2, width - bounds.width - inset));
		const below = anchor.bottom + inset;
		const above = anchor.top - bounds.height - inset;
		const top = below + bounds.height <= height - inset ? below
			: above >= inset ? above : Math.max(inset, height - bounds.height - inset);
		menu.style.setProperty('--cb-design-page-menu-left', left + 'px');
		menu.style.setProperty('--cb-design-page-menu-top', top + 'px');
	};
	const open = () => {
		if (destroyed || opened || trigger.disabled || items.length < 2) return;
		opened = true;
		menu.hidden = false;
		trigger.setAttribute('aria-expanded', 'true');
		toolbar?.classList.add('has-page-navigation-open');
		position();
		(items.find(item => item.page.id === current.activePageId) || items[0]).button.focus({preventScroll: true});
	};
	const setActivePage = id => {
		if (destroyed || !current.pages?.some(page => page.id === id)) return false;
		current = Object.freeze({...current, activePageId: id});
		setActiveLabel();
		return true;
	};
	const update = config => {
		if (destroyed) return false;
		const next = normalize(config);
		close({restoreFocus: true});
		generation += 1;
		current = next;
		const version = generation;
		items = [];
		const fragment = doc.createDocumentFragment();
		next.pages?.forEach((page, index) => {
			const button = doc.createElement('button');
			button.type = 'button';
			button.className = 'cb-core-design-shell__page-menu-item';
			button.setAttribute('role', 'menuitemradio');
			button.setAttribute('aria-checked', page.id === next.activePageId ? 'true' : 'false');
			button.textContent = page.label;
			button.addEventListener('click', () => {
				if (destroyed || generation !== version || !opened || !current.pages
					|| current.pages[index]?.id !== page.id || typeof current.onNavigate !== 'function') return;
				const intent = Object.freeze({pageId: page.id, pageIndex: index, totalPages: current.pages.length});
				let result;
				try {result = current.onNavigate(intent);}
				catch (_) {return;}
				if (result === false) return;
				// Only the consumer can confirm the new active page via update/setActivePage.
				if (result && typeof result.then === 'function') void Promise.resolve(result).catch(() => {});
				close({restoreFocus: true});
			});
			// Detached items must not remain retained by the Host abort signal.
			fragment.append(button);
			items.push({page, button});
		});
		menu.replaceChildren(fragment);
		trigger.disabled = !next.pages || next.pages.length < 2 || typeof next.onNavigate !== 'function';
		setActiveLabel();
		return true;
	};
	trigger.addEventListener('click', () => {
		if (opened) close({restoreFocus: true});
		else open();
	}, {signal: local.signal});
	trigger.addEventListener('keydown', event => {
		if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !trigger.disabled) {
			event.preventDefault();
			open();
		}
	}, {signal: local.signal});
	menu.addEventListener('keydown', event => {
		if (!opened) return;
		if (event.key === 'Escape') {
			event.preventDefault();
			event.stopPropagation();
			close({restoreFocus: true});
			return;
		}
		if (event.key === 'Tab') {
			close();
			return;
		}
		if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
		event.preventDefault();
		const index = items.findIndex(item => item.button === doc.activeElement);
		const target = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
			: (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
		items[target]?.button.focus({preventScroll: true});
	}, {signal: local.signal});
	doc.addEventListener('pointerdown', event => {
		if (opened && !group.contains(event.target)) close();
	}, {signal: local.signal});
	doc.addEventListener('focusin', event => {
		if (opened && !group.contains(event.target)) close();
	}, {signal: local.signal});
	doc.addEventListener('scroll', () => close(), {signal: local.signal, capture: true});
	view.addEventListener('resize', position, {signal: local.signal, passive: true});
	toolbar?.addEventListener('scroll', position, {signal: local.signal, passive: true});
	const destroy = () => {
		if (destroyed) return;
		close();
		destroyed = true;
		local.abort();
		menu.remove();
		trigger.removeAttribute('aria-controls');
		trigger.removeAttribute('aria-haspopup');
		trigger.removeAttribute('aria-expanded');
		trigger.disabled = true;
		controllers.delete(root);
	};
	signal?.addEventListener('abort', destroy, {once: true});
	const controller = Object.freeze({
		update, setActivePage, destroy,
		get pages() {return current.pages;},
		get activePageId() {return current.activePageId;},
		get open() {return opened;},
	});
	controllers.set(root, controller);
	update(initial);
	return controller;
};
