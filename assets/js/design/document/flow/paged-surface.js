/**
 * DP-NAV-02C Gate 3B: optional Base-owned visual pages and semantic overlays.
 *
 * A trusted PDF adapter supplies physical pages from the SAME authoritative
 * PDF as the server's region projection. This module neither parses PDF nor
 * guesses page breaks from HTML. Neither a click nor a page jump grants a
 * document mutation. No consumer or WordPress browser bundle imports it yet.
 */
const REGION_ID = /^r(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*)){0,7}$/;
const MAX_PAGES = 500;
const MAX_REGIONS = 500;
const MAX_FRAGMENTS = 5000;
const MAX_PAGE_PIXELS = 8000000;
const MAX_RENDERED_PAGES = 3;
const PAGE_COORDINATE_TOLERANCE = 0.05;

const finite = value => typeof value === 'number' && Number.isFinite(value);
const bounded = (value, min, max) => finite(value) && value >= min && value <= max;

export const validateFlowPageProjection = projection => {
	if (!projection || typeof projection !== 'object' || Array.isArray(projection)
		|| !Number.isSafeInteger(projection.page_count)
		|| projection.page_count < 1 || projection.page_count > MAX_PAGES
		|| !bounded(projection.page_width_pt, 1, 6000)
		|| !bounded(projection.page_height_pt, 1, 6000)
		|| !projection.regions || typeof projection.regions !== 'object'
		|| Array.isArray(projection.regions)) {
		throw new TypeError('FLOW_PAGED_PROJECTION_INVALID');
	}
	const ids = Object.keys(projection.regions);
	if (ids.length === 0 || ids.length > MAX_REGIONS) {
		throw new TypeError('FLOW_PAGED_PROJECTION_REGIONS_INVALID');
	}
	let total = 0;
	const regions = {};
	const membership = new Map();
	for (const id of ids) {
		if (!REGION_ID.test(id) || id.length > 64
			|| !id.slice(1).split('.').every(part => Number.isSafeInteger(Number(part)))) {
			throw new TypeError('FLOW_PAGED_PROJECTION_REGION_ID_INVALID');
		}
		const fragments = projection.regions[id];
		if (!Array.isArray(fragments) || fragments.length === 0
			|| (total += fragments.length) > MAX_FRAGMENTS) {
			throw new TypeError('FLOW_PAGED_PROJECTION_FRAGMENTS_INVALID');
		}
		regions[id] = Object.freeze(fragments.map(fragment => {
			if (!fragment || typeof fragment !== 'object' || Array.isArray(fragment)
				|| !Number.isSafeInteger(fragment.page)
				|| fragment.page < 1 || fragment.page > projection.page_count
				|| !bounded(fragment.x, 0, projection.page_width_pt)
				|| !bounded(fragment.y, 0, projection.page_height_pt)
				|| !bounded(fragment.width, Number.EPSILON, projection.page_width_pt)
				|| !bounded(fragment.height, Number.EPSILON, projection.page_height_pt)
				|| fragment.x + fragment.width > projection.page_width_pt + PAGE_COORDINATE_TOLERANCE
				|| fragment.y + fragment.height > projection.page_height_pt + PAGE_COORDINATE_TOLERANCE) {
				throw new TypeError('FLOW_PAGED_PROJECTION_RECTANGLE_INVALID');
			}
			const known = membership.get(fragment.page) || new Set();
			known.add(id);
			membership.set(fragment.page, known);
			return Object.freeze({
				page: fragment.page, x: fragment.x, y: fragment.y,
				width: fragment.width, height: fragment.height,
			});
		}));
	}
	return Object.freeze({
		page_count: projection.page_count,
		page_width_pt: projection.page_width_pt,
		page_height_pt: projection.page_height_pt,
		regions: Object.freeze(regions),
	});
};

const selectedRegion = regionId => regionId === null
	|| (typeof regionId === 'string' && REGION_ID.test(regionId) && regionId.length <= 64);

const pageView = (doc, index, count, projection, labels, onActivate) => {
	const section = doc.createElement('section');
	section.className = 'cb-core-flow-paged__page';
	section.setAttribute('data-cb-flow-paged-page', String(index));
	section.setAttribute('role', 'group');
	section.setAttribute('aria-label', 'Page ' + index + ' of ' + count);
	section.style.aspectRatio = projection.page_width_pt + ' / ' + projection.page_height_pt;
	const visual = doc.createElement('div');
	visual.className = 'cb-core-flow-paged__visual';
	visual.setAttribute('aria-hidden', 'true');
	const overlay = doc.createElement('div');
	overlay.className = 'cb-core-flow-paged__overlays';
	section.append(visual, overlay);
	const controls = new Map();
	for (const [id, fragments] of Object.entries(projection.regions)) {
		const onPage = fragments.filter(fragment => fragment.page === index);
		for (const fragment of onPage) {
			const action = doc.createElement('button');
			action.type = 'button';
			action.className = 'cb-core-flow-paged__region';
			action.dataset.cbFlowPagedRegion = id;
			action.style.left = (100 * fragment.x / projection.page_width_pt) + '%';
			action.style.top = (100 * fragment.y / projection.page_height_pt) + '%';
			action.style.width = (100 * fragment.width / projection.page_width_pt) + '%';
			action.style.height = (100 * fragment.height / projection.page_height_pt) + '%';
			const name = typeof labels?.[id] === 'string' && labels[id].trim()
				? labels[id].trim().slice(0, 100) : 'document element';
			action.setAttribute('aria-label', 'Select ' + name + ' on page ' + index);
			action.setAttribute('aria-pressed', 'false');
			action.disabled = typeof onActivate !== 'function';
			if (!action.disabled) action.addEventListener('click', () =>
				onActivate(Object.freeze({regionId: id, page: index})));
			overlay.append(action);
			const buttons = controls.get(id) || [];
			buttons.push(action);
			controls.set(id, buttons);
		}
	}
	return {section, visual, controls};
};

/**
 * @param {object} options
 * @param {Element} options.root Dedicated document canvas mount.
 * @param {object} options.pageSource PDF.js-like source: numPages, getPage(n).
 * @param {object} options.projection Result from the same PDF render.
 * @param {object?} options.navigation Optional Base page-navigation controller.
 * @param {function?} options.onActivate Verified semantic selection intent.
 * @param {AbortSignal?} options.signal Session lifecycle.
 * @param {number} options.zoom PDF-points -> CSS pixels, independent of PDF raster DPR.
 */
export const createFlowPagedSurface = ({
	root, pageSource, projection, navigation = null, onActivate = null,
	onPageError = null, labels = null, signal = null, zoom = 1,
} = {}) => {
	if (!root || typeof root.replaceChildren !== 'function' || !root.ownerDocument?.createElement
		|| !pageSource || typeof pageSource.getPage !== 'function'
		|| !Number.isSafeInteger(pageSource.numPages)
		|| (navigation !== null && typeof navigation.update !== 'function')
		|| (onActivate !== null && typeof onActivate !== 'function')
		|| (onPageError !== null && typeof onPageError !== 'function')
		|| (labels !== null && (typeof labels !== 'object' || Array.isArray(labels)))
		|| !bounded(zoom, 0.25, 2) || signal?.aborted) {
		throw new TypeError('FLOW_PAGED_SURFACE_OPTIONS_INVALID');
	}
	const descriptor = validateFlowPageProjection(projection);
	if (descriptor.page_count !== pageSource.numPages) {
		throw new TypeError('FLOW_PAGED_PAGE_COUNT_MISMATCH');
	}
	const pixelRatio = Math.min(2, Math.max(1, root.ownerDocument.defaultView?.devicePixelRatio || 1));
	const cssScale = (96 / 72) * zoom;
	const expectedWidth = descriptor.page_width_pt * cssScale;
	const expectedHeight = descriptor.page_height_pt * cssScale;
	if (expectedWidth * expectedHeight * pixelRatio * pixelRatio > MAX_PAGE_PIXELS
		|| expectedWidth * pixelRatio > 4000 || expectedHeight * pixelRatio > 4000) {
		throw new RangeError('FLOW_PAGED_SURFACE_CANVAS_BUDGET_EXCEEDED');
	}

	const doc = root.ownerDocument;
	const views = [];
	const pages = [];
	const rendering = new Map();
	const queue = [];
	const requested = new Set();
	const visible = new Set();
	const rendered = new Map();
	let activePage = 1;
	let selection = null;
	let destroyed = false;
	let observer = null;
	let pumpRunning = false;
	let generation = 1;

	for (let index = 1; index <= descriptor.page_count; index++) {
		const activate = onActivate === null ? null : intent => {
			if (!destroyed) onActivate(intent);
		};
		const view = pageView(doc, index, descriptor.page_count, descriptor, labels, activate);
		view.section.style.width = expectedWidth + 'px';
		// CSS aspect-ratio owns height as the sheet shrinks on narrow screens.
		view.section.style.maxWidth = '100%';
		views.push(view);
		pages.push({id: 'pdf-page-' + index, label: 'Page ' + index});
	}
	root.replaceChildren(...views.map(view => view.section));
	root.classList?.add('cb-core-flow-paged');
	const evict = () => {
		if (rendered.size <= MAX_RENDERED_PAGES) return;
		const candidates = [...rendered.keys()].filter(index => index !== activePage);
		// Prefer offscreen eviction, but enforce the memory cap even when
		// more than three pages are simultaneously observed as visible.
		candidates.sort((left, right) => Number(visible.has(left)) - Number(visible.has(right)));
		for (const index of candidates) {
			if (rendered.size <= MAX_RENDERED_PAGES) break;
			views[index - 1].visual.replaceChildren();
			rendered.delete(index);
		}
	};
	const reportError = error => {
		if (destroyed) return;
		try { onPageError?.(error); } catch (_) { /* Status cannot change authority. */ }
	};
	const draw = async index => {
		const view = views[index - 1];
		const token = generation;
		let task = null;
		try {
			const page = await pageSource.getPage(index);
			if (destroyed || generation !== token) return;
			const intrinsic = page.getViewport({scale: 1});
			if (!finite(intrinsic.width) || !finite(intrinsic.height)
				|| Math.abs(intrinsic.width - descriptor.page_width_pt) > 1
				|| Math.abs(intrinsic.height - descriptor.page_height_pt) > 1) {
				throw new TypeError('FLOW_PAGED_PAGE_DIMENSIONS_MISMATCH');
			}
			const viewport = page.getViewport({scale: cssScale * pixelRatio});
			const width = Math.ceil(viewport.width);
			const height = Math.ceil(viewport.height);
			if (width * height > MAX_PAGE_PIXELS || width > 4000 || height > 4000) {
				throw new RangeError('FLOW_PAGED_CANVAS_BUDGET_EXCEEDED');
			}
			const canvas = doc.createElement('canvas');
			canvas.width = width;
			canvas.height = height;
			canvas.style.width = expectedWidth + 'px';
			canvas.style.height = expectedHeight + 'px';
			const context = canvas.getContext('2d', {alpha: false});
			if (!context) throw new Error('FLOW_PAGED_CANVAS_CONTEXT_UNAVAILABLE');
			task = page.render({canvasContext: context, viewport});
			if (!task || typeof task.promise?.then !== 'function') {
				throw new TypeError('FLOW_PAGED_RENDER_TASK_INVALID');
			}
			rendering.set(index, task);
			await task.promise;
			if (destroyed || generation !== token) return;
			view.visual.replaceChildren(canvas);
			view.section.dataset.cbFlowPagedStatus = 'ready';
			rendered.delete(index);
			rendered.set(index, true);
			evict();
		} catch (error) {
			if (!destroyed && generation === token) {
				view.section.dataset.cbFlowPagedStatus = 'failed';
				view.visual.replaceChildren();
				for (const buttons of view.controls.values()) for (const button of buttons) button.disabled = true;
				reportError(error);
			}
		} finally {
			if (rendering.get(index) === task) rendering.delete(index);
		}
	};
	const pump = async () => {
		if (pumpRunning || destroyed) return;
		pumpRunning = true;
		try {
			while (!destroyed && queue.length) {
				const next = queue.shift();
				requested.delete(next);
				if (rendered.has(next)) continue;
				await draw(next);
			}
		} finally {
			pumpRunning = false;
		}
	};
	const request = index => {
		if (!Number.isSafeInteger(index) || index < 1 || index > descriptor.page_count
			|| destroyed || rendered.has(index) || requested.has(index)) return false;
		requested.add(index);
		queue.push(index);
		void pump();
		return true;
	};
	const setSelection = id => {
		if (destroyed || !selectedRegion(id)) throw new TypeError('FLOW_PAGED_SELECTION_INVALID');
		selection = id;
		views.forEach(view => view.controls.forEach((buttons, key) =>
			buttons.forEach(button => {
				button.setAttribute('aria-pressed', key === id ? 'true' : 'false');
				button.classList.toggle('is-selected', key === id);
			})));
		return true;
	};
	const goToPage = index => {
		if (destroyed || !Number.isSafeInteger(index) || index < 1 || index > descriptor.page_count) return false;
		activePage = index;
		request(index);
		views[index - 1].section.scrollIntoView?.({block: 'start', behavior: 'auto'});
		navigation?.setActivePage?.('pdf-page-' + index);
		evict();
		return true;
	};
	if (navigation) navigation.update({
		pages, activePageId: 'pdf-page-1',
		onNavigate: intent => goToPage(intent.pageIndex + 1),
	});
	const rootObserver = doc.defaultView?.IntersectionObserver;
	if (typeof rootObserver === 'function') {
		observer = new rootObserver(entries => {
			if (destroyed) return;
			for (const entry of entries) {
				const index = Number(entry.target?.dataset?.cbFlowPagedPage);
				if (!Number.isSafeInteger(index) || index < 1 || index > views.length) continue;
				if (entry.isIntersecting) {
					visible.add(index);
					request(index);
				} else visible.delete(index);
			}
			evict();
		}, {root: null, rootMargin: '600px 0px', threshold: 0});
		views.forEach(view => observer.observe(view.section));
	}
	request(1);
	const destroy = () => {
		if (destroyed) return;
		destroyed = true;
		generation++;
		observer?.disconnect();
		queue.length = 0;
		requested.clear();
		for (const task of rendering.values()) {
			try { task.cancel?.(); } catch (_) { /* Best effort. */ }
		}
		rendering.clear();
		rendered.clear();
		root.replaceChildren();
		root.classList?.remove('cb-core-flow-paged');
		try { Promise.resolve(pageSource.destroy?.()).catch(() => {}); }
		catch (_) { /* Best effort. */ }
		// The owning Designer Session disposes its page-navigation controller.
	};
	signal?.addEventListener('abort', destroy, {once: true});
	return Object.freeze({
		goToPage, setSelection, destroy,
		get activePage() { return activePage; },
		get pageCount() { return descriptor.page_count; },
		get selectedRegion() { return selection; },
	});
};
