/**
 * Optional Base Designer Canvas Navigation v1.
 * Viewport-only, transient presentation. Never writes domain data or History.
 */
const controllers = new WeakMap();
const INTERACTIVE = 'button, a, input, textarea, select, [contenteditable], [draggable], [data-cb-design-canvas-object], [data-cb-design-canvas-interactive]';
const EDITABLE = 'input, textarea, select, [contenteditable]';
const finite = value => typeof value === 'number' && Number.isFinite(value);
// CN-2 explicit user zoom limits. Auto-fit may choose a smaller scale for
// exceptionally large content; explicit zoom never mutates graph coordinates.
const MIN_ZOOM = 0.1;
const MAX_ZOOM = 4;
const validElement = value => value && typeof value.addEventListener === 'function'
	&& typeof value.getBoundingClientRect === 'function';

export const createDesignerCanvasNavigation = ({
	root, viewport, scene, getContentBounds, getContextKey, isReady,
	onViewChange = null,
} = {}) => {
	if (!validElement(root) || !validElement(viewport) || !validElement(scene)
		|| !root.contains(viewport) || !viewport.contains(scene)
		|| typeof getContentBounds !== 'function'
		|| typeof getContextKey !== 'function' || typeof isReady !== 'function'
		|| (onViewChange !== null && typeof onViewChange !== 'function')
		|| controllers.has(viewport)) {
		throw new TypeError('DESIGNER_CANVAS_NAVIGATION_CONFIG_INVALID');
	}
	const doc = viewport.ownerDocument;
	const win = doc.defaultView || (typeof window !== 'undefined' ? window : null);
	const original = {transform: scene.style.transform, origin: scene.style.transformOrigin};
	const listeners = [];
	let destroyed = false;
	let view = {x: 0, y: 0, scale: 1};
	let gesture = null;
	let spaceHeld = false;
	let autoFit = true;
	let contextKey = getContextKey();
	let lastSize = {width: 0, height: 0};
	if (typeof contextKey !== 'string' || !contextKey.trim()) {
		throw new TypeError('DESIGNER_CANVAS_NAVIGATION_CONTEXT_INVALID');
	}
	const listen = (element, name, handler, options) => {
		element.addEventListener(name, handler, options);
		listeners.push(() => element.removeEventListener(name, handler, options));
	};
	const ensure = () => {
		if (destroyed) throw new Error('DESIGNER_CANVAS_NAVIGATION_DESTROYED');
	};
	const available = () => !destroyed && root.isConnected !== false && isReady() === true;
	const bounds = () => {
		const b = getContentBounds();
		if (!b || ![b.x, b.y, b.width, b.height].every(finite)
			|| b.width <= 0 || b.height <= 0) {
			throw new TypeError('DESIGNER_CANVAS_NAVIGATION_BOUNDS_INVALID');
		}
		return b;
	};
	const size = () => ({
		width: viewport.clientWidth || 0,
		height: viewport.clientHeight || 0,
	});
	const snapshot = () => Object.freeze({
		x: view.x, y: view.y, scale: view.scale,
		scrollLeft: viewport.scrollLeft || 0,
		scrollTop: viewport.scrollTop || 0,
	});
	const publish = () => {
		scene.style.transformOrigin = '0 0';
		scene.style.transform = 'translate(' + view.x + 'px, ' + view.y + 'px) scale(' + view.scale + ')';
		const next = snapshot();
		if (onViewChange) onViewChange(next);
		if (typeof CustomEvent === 'function') {
			viewport.dispatchEvent(new CustomEvent('cb:design-canvas:navigationchange', {
				bubbles: true, detail: next,
			}));
		}
		return next;
	};
	const center = () => {
		const b = bounds(), s = size();
		if (s.width <= 0 || s.height <= 0) return false;
		view.x = (viewport.scrollLeft || 0) + (s.width - b.width * view.scale) / 2 - b.x * view.scale;
		view.y = (viewport.scrollTop || 0) + (s.height - b.height * view.scale) / 2 - b.y * view.scale;
		publish();
		return true;
	};
	const fit = ({padding = 32} = {}) => {
		if (!finite(padding) || padding < 0) throw new TypeError('DESIGNER_CANVAS_NAVIGATION_PADDING_INVALID');
		const b = bounds(), s = size();
		if (s.width <= 0 || s.height <= 0) return false;
		const width = Math.max(1, s.width - 2 * padding);
		const height = Math.max(1, s.height - 2 * padding);
		view.scale = Math.min(1, width / b.width, height / b.height);
		return center();
	};
	const resetContext = () => {
		const current = getContextKey();
		if (typeof current !== 'string' || !current.trim()) {
			throw new TypeError('DESIGNER_CANVAS_NAVIGATION_CONTEXT_INVALID');
		}
		if (current === contextKey) return;
		contextKey = current;
		stopGesture();
		autoFit = true;
		view = {x: 0, y: 0, scale: 1};
		if (available()) fit();
	};
	const check = () => {
		ensure();
		resetContext();
		return available();
	};
	const scaledRect = () => {
		const rect = viewport.getBoundingClientRect();
		return {rect, sx: viewport.clientWidth > 0 ? rect.width / viewport.clientWidth : 1,
			sy: viewport.clientHeight > 0 ? rect.height / viewport.clientHeight : 1};
	};
	const toCanvas = ({clientX, clientY} = {}) => {
		if (!finite(clientX) || !finite(clientY)) throw new TypeError('DESIGNER_CANVAS_NAVIGATION_POINT_INVALID');
		const {rect, sx, sy} = scaledRect();
		return Object.freeze({
			x: ((clientX - rect.left) / (sx || 1) + (viewport.scrollLeft || 0) - view.x) / view.scale,
			y: ((clientY - rect.top) / (sy || 1) + (viewport.scrollTop || 0) - view.y) / view.scale,
		});
	};
	const toClient = ({x, y} = {}) => {
		if (!finite(x) || !finite(y)) throw new TypeError('DESIGNER_CANVAS_NAVIGATION_POINT_INVALID');
		const {rect, sx, sy} = scaledRect();
		return Object.freeze({
			clientX: rect.left + (view.x + x * view.scale - (viewport.scrollLeft || 0)) * (sx || 1),
			clientY: rect.top + (view.y + y * view.scale - (viewport.scrollTop || 0)) * (sy || 1),
		});
	};
	function stopGesture() {
		if (gesture && viewport.hasPointerCapture?.(gesture.id)) viewport.releasePointerCapture(gesture.id);
		gesture = null;
		viewport.classList.remove('cb-core-design-canvas--panning');
	}
	const panBy = (dx, dy) => {
		if (!check()) return false;
		if (!finite(dx) || !finite(dy)) throw new TypeError('DESIGNER_CANVAS_NAVIGATION_DELTA_INVALID');
		view.x += dx;
		view.y += dy;
		autoFit = false;
		publish();
		return true;
	};
	// Zoom around a client-space anchor, preserving its unscaled canvas point.
	// This is transient presentation only: no graph, History, Save or storage.
	const zoomAt = ({scale, clientX = null, clientY = null} = {}) => {
		if (!check()) return false;
		if (!finite(scale) || scale <= 0
			|| (clientX !== null && !finite(clientX))
			|| (clientY !== null && !finite(clientY))) {
			throw new TypeError('DESIGNER_CANVAS_NAVIGATION_ZOOM_INVALID');
		}
		const {rect, sx, sy} = scaledRect();
		if (clientX === null) clientX = rect.left + rect.width / 2;
		if (clientY === null) clientY = rect.top + rect.height / 2;
		const atX = (clientX - rect.left) / (sx || 1) + (viewport.scrollLeft || 0);
		const atY = (clientY - rect.top) / (sy || 1) + (viewport.scrollTop || 0);
		const worldX = (atX - view.x) / view.scale;
		const worldY = (atY - view.y) / view.scale;
		const nextScale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, scale));
		if (Math.abs(nextScale - view.scale) <= 1e-9) return false;
		view.scale = nextScale;
		view.x = atX - worldX * nextScale;
		view.y = atY - worldY * nextScale;
		autoFit = false;
		publish();
		return true;
	};
	const zoomBy = ({factor, clientX = null, clientY = null} = {}) => {
		if (!finite(factor) || factor <= 0) {
			throw new TypeError('DESIGNER_CANVAS_NAVIGATION_ZOOM_INVALID');
		}
		return zoomAt({scale: view.scale * factor, clientX, clientY});
	};
	const onDown = event => {
		if (!available() || gesture || (event.pointerType && event.pointerType !== 'mouse')) return;
		resetContext();
		const target = event.target;
		const editable = target?.closest?.(EDITABLE);
		if (editable) return;
		const middle = event.button === 1;
		const withSpace = spaceHeld && event.button === 0;
		const blank = event.button === 0 && (
			target === viewport || target === scene
			|| target?.closest?.('[data-cb-design-canvas-background]')
		) && !target?.closest?.(INTERACTIVE);
		if (!middle && !withSpace && !blank) return;
		event.preventDefault();
		event.stopPropagation();
		gesture = {id: event.pointerId, x: event.clientX, y: event.clientY, key: contextKey};
		viewport.classList.add('cb-core-design-canvas--panning');
		viewport.setPointerCapture?.(event.pointerId);
	};
	const onMove = event => {
		if (!gesture || event.pointerId !== gesture.id) return;
		if (!available() || getContextKey() !== gesture.key) { stopGesture(); return; }
		const {sx, sy} = scaledRect();
		const dx = (event.clientX - gesture.x) / (sx || 1);
		const dy = (event.clientY - gesture.y) / (sy || 1);
		gesture.x = event.clientX;
		gesture.y = event.clientY;
		if (dx || dy) panBy(dx, dy);
	};
	const onEnd = event => {
		if (gesture && event.pointerId === gesture.id) stopGesture();
	};
	const onKeyDown = event => {
		if ((event.code !== 'Space' && event.key !== ' ') || event.altKey || event.ctrlKey || event.metaKey
			|| event.target?.closest?.(EDITABLE)) return;
		spaceHeld = true;
		event.preventDefault();
	};
	const onKeyUp = event => {
		if (event.code === 'Space' || event.key === ' ') spaceHeld = false;
	};
	const onResize = () => {
		if (destroyed || !available()) return;
		const next = size();
		if (!next.width || !next.height) return;
		if (autoFit || !lastSize.width) fit();
		else {
			view.x += (next.width - lastSize.width) / 2;
			view.y += (next.height - lastSize.height) / 2;
			publish();
		}
		lastSize = next;
	};
	listen(viewport, 'pointerdown', onDown, true);
	listen(viewport, 'pointermove', onMove);
	listen(viewport, 'pointerup', onEnd);
	listen(viewport, 'pointercancel', onEnd);
	listen(root, 'keydown', onKeyDown, true);
	listen(doc, 'keyup', onKeyUp, true);
	if (win) listen(win, 'blur', () => {spaceHeld = false; stopGesture();});
	listen(root, 'cb:design-shell:contextchanged', () => {
		if (destroyed) return;
		resetContext();
	});
	const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(onResize) : null;
	if (observer) observer.observe(viewport);
	else if (win) listen(win, 'resize', onResize);
	controllers.set(viewport, true);
	onResize();
	return Object.freeze({
		panBy,
		zoomAt,
		zoomBy,
		fitToScreen(options) {
			if (!check()) return false;
			autoFit = true;
			return fit(options);
		},
		centerOnContent() {
			if (!check()) return false;
			autoFit = false;
			return center();
		},
		clientToCanvas(point) { check(); return toCanvas(point); },
		canvasToClient(point) { check(); return toClient(point); },
		getView() { check(); return snapshot(); },
		destroy() {
			if (destroyed) return;
			stopGesture();
			destroyed = true;
			observer?.disconnect();
			listeners.splice(0).forEach(off => off());
			scene.style.transform = original.transform;
			scene.style.transformOrigin = original.origin;
			controllers.delete(viewport);
		},
	});
};
