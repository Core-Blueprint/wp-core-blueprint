/**
 * DF-D3: Base-owned transient interaction presentation.
 * One visual rectangle per semantic node, regardless of native or iframe-proxy DOM.
 * Consumers provide read-only semantic node paths and bounded geometry through
 * their existing render surfaces. No project, selection or command authority here.
 */
const finite = n => typeof n === 'number' && Number.isFinite(n);
const nodeKey = node => node?.dataset?.cbDesignNodePath || null;
const validKey = key => {
	try {
		const path = JSON.parse(key);
		return Array.isArray(path) && path.length > 0 && path.length <= 64
			&& path.every(i => Number.isSafeInteger(i) && i >= 0);
	} catch (_) { return false; }
};
const zoneKey = id => {
	if (typeof id !== 'string' || !/^node\/(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))*$/.test(id)) return null;
	return JSON.stringify(id.slice(5).split('.').map(Number));
};
const containsPoint = (rect, point) => point && rect.width > 0 && rect.height > 0
	&& point.x >= rect.left && point.x <= rect.right
	&& point.y >= rect.top && point.y <= rect.bottom;

export const createDesignerInteractionPresentation = ({
	root, surface, getSelection, isDragging,
} = {}) => {
	const doc = surface?.ownerDocument;
	const win = doc?.defaultView;
	if (!root || !surface || !win || !root.contains(surface)
		|| typeof getSelection !== 'function' || typeof isDragging !== 'function'
		|| surface.querySelector('[data-cb-design-interaction-layer]')) {
		throw new TypeError('DESIGNER_INTERACTION_CONFIG_INVALID');
	}
	const layer = doc.createElement('div');
	layer.className = 'cb-core-design-interaction-layer';
	layer.dataset.cbDesignInteractionLayer = '1';
	layer.setAttribute('aria-hidden', 'true');
	surface.append(layer);
	const oldAttribute = root.getAttribute('data-cb-design-interaction-overlay');
	root.setAttribute('data-cb-design-interaction-overlay', 'true');
	let destroyed = false, pointer = null, scheduled = 0;
	const listeners = [];
	const listen = (target, type, fn, options) => {
		target.addEventListener(type, fn, options);
		listeners.push(() => target.removeEventListener(type, fn, options));
	};
	const bounds = element => {
		const rect = element.getBoundingClientRect();
		return [rect.left, rect.top, rect.width, rect.height].every(finite)
			&& rect.width > 0 && rect.height > 0 ? rect : null;
	};
	const refresh = () => {
		if (destroyed || !root.isConnected) return;
		const nodes = [...surface.querySelectorAll('[data-cb-design-node-path]')]
			.filter(n => validKey(nodeKey(n)) && bounds(n));
		let selected, dragging;
		try {
			selected = getSelection();
			dragging = isDragging() === true;
		} catch (_) {
			// A disposed/changed Host must never project stale selection or throw
			// from a delayed render/drag callback during context teardown.
			layer.replaceChildren();
			return;
		}
		const selectedKey = selected?.kind === 'node' && Array.isArray(selected.path)
			? JSON.stringify(selected.path) : null;
		const active = doc.activeElement;
		const state = new Map();
		const put = (key, element, kind, edge = null, priority = 0) => {
			if (!element || !bounds(element) || (state.get(key)?.priority ?? -1) > priority) return;
			state.set(key, {element, kind, edge, priority});
		};
		if (!dragging && pointer) {
			// The deepest semantic node wins even when iframe proxy rectangles overlap.
			const under = nodes.filter(n => containsPoint(bounds(n), pointer))
				.sort((a, b) => JSON.parse(nodeKey(b)).length - JSON.parse(nodeKey(a)).length)[0];
			if (under) put(nodeKey(under), under, 'hover', null, 1);
		}
		if (!dragging && active && surface.contains(active)) {
			const focus = nodes.filter(n => n === active || n.contains(active))
				.sort((a, b) => JSON.parse(nodeKey(b)).length - JSON.parse(nodeKey(a)).length)[0];
			if (focus) {
				const editing = active.closest?.('input,textarea,[contenteditable],[role="textbox"]');
				put(nodeKey(focus), focus, editing ? 'editing' : 'focus',
					null, editing ? 4 : 2);
			}
		}
		if (selectedKey) {
			const selectedNode = nodes.find(n => nodeKey(n) === selectedKey);
			if (selectedNode) put(selectedKey, selectedNode, dragging ? 'dragging' : 'selected', null, 3);
		}
		if (dragging) {
			for (const zone of surface.querySelectorAll('[data-cb-design-drop-active="true"]')) {
				const id = zone.dataset.cbDesignDropZoneId;
				// Direct node drop-targets have a semantic node path, not a
				// separate proxy-zone id. Resolve both to the same key so a
				// selected/dragged node receives ONE canonical drop outline.
				const key = zoneKey(id)
					|| (validKey(nodeKey(zone)) ? nodeKey(zone) : 'drop:' + id);
				put(key, zone, 'drop', zone.dataset.cbDesignDropEdge || 'inside', 5);
			}
		}
		const layerRect = layer.getBoundingClientRect();
		const sx = layer.offsetWidth > 0 ? layerRect.width / layer.offsetWidth : 1;
		const sy = layer.offsetHeight > 0 ? layerRect.height / layer.offsetHeight : 1;
		if (!sx || !sy || !finite(sx) || !finite(sy)) { layer.replaceChildren(); return; }
		const outlines = [];
		for (const record of state.values()) {
			const rect = bounds(record.element);
			if (!rect) continue;
			const marker = doc.createElement('div');
			marker.className = 'cb-core-design-interaction-indicator';
			marker.dataset.state = record.kind;
			if (record.edge) marker.dataset.edge = record.edge;
			marker.style.left = ((rect.left - layerRect.left) / sx) + 'px';
			marker.style.top = ((rect.top - layerRect.top) / sy) + 'px';
			marker.style.width = (rect.width / sx) + 'px';
			marker.style.height = (rect.height / sy) + 'px';
			outlines.push(marker);
		}
		layer.replaceChildren(...outlines);
	};
	const schedule = () => {
		if (destroyed || scheduled) return;
		scheduled = win.requestAnimationFrame(() => { scheduled = 0; refresh(); });
	};
	let observer = null;
	try {
		listen(surface, 'pointermove', event => {
			if (!finite(event.clientX) || !finite(event.clientY)) return;
			pointer = {x:event.clientX, y:event.clientY};
			schedule();
		}, {passive:true});
		listen(surface, 'pointerleave', () => { pointer = null; schedule(); });
		listen(root, 'focusin', schedule);
		listen(root, 'focusout', schedule);
		listen(root, 'scroll', schedule, true);
		listen(root, 'cb:design-canvas:navigationchange', schedule);
		listen(root, 'cb:designer-dropzones-changed', schedule);
		listen(win, 'resize', schedule, {passive:true});
		observer = typeof win.MutationObserver === 'function'
			? new win.MutationObserver(mutations => {
				if (mutations.some(m => m.target !== layer && !layer.contains(m.target))) schedule();
			}) : null;
		observer?.observe(surface, {
			subtree:true, childList:true, attributes:true,
			attributeFilter:['data-cb-design-drop-active','data-cb-design-drop-edge'],
		});
		refresh();
	} catch (error) {
		// Failure after DOM insertion must not leave a poisoned Host surface
		// or orphan listeners when post-commit activation is retried.
		if (scheduled) win.cancelAnimationFrame(scheduled);
		observer?.disconnect();
		listeners.splice(0).forEach(off => off());
		layer.remove();
		if (oldAttribute === null) root.removeAttribute('data-cb-design-interaction-overlay');
		else root.setAttribute('data-cb-design-interaction-overlay', oldAttribute);
		throw error;
	}
	return Object.freeze({
		refresh,
		destroy() {
			if (destroyed) return;
			destroyed = true;
			if (scheduled) win.cancelAnimationFrame(scheduled);
			observer?.disconnect();
			listeners.splice(0).forEach(off => off());
			layer.remove();
			if (oldAttribute === null) root.removeAttribute('data-cb-design-interaction-overlay');
			else root.setAttribute('data-cb-design-interaction-overlay', oldAttribute);
		},
	});
};
