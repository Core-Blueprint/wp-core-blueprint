/**
 * Base Designer Drag & Drop v1.
 *
 * One interaction controller for palette elements and authored nodes. A consumer
 * supplies semantic drop resolution and domain authorization; it NEVER supplies
 * document mutations to this controller. The single synchronous commit callback
 * must dispatch an existing registered command through its active session.
 *
 * Canvas adapters register real DOM zones or positioned proxy elements for an
 * isolated renderer. Neither the DataTransfer payload nor a DOM path is trusted
 * as a project, command, resource identity or authorization.
 */
const IDENTIFIER = /^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$/;
const own = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
const validId = value => typeof value === 'string' && IDENTIFIER.test(value);
const validPath = path => Array.isArray(path) && path.length <= 64
	&& path.every(i => Number.isSafeInteger(i) && i >= 0);
const isElement = (element, doc) => !!element && element.ownerDocument === doc
	&& typeof element.addEventListener === 'function'
	&& typeof element.removeEventListener === 'function';
const isSync = value => !value || typeof value.then !== 'function';

export const createDesignerDragDropController = ({
	root, isReady, getContextKey, resolveIntent, canDrop, commit, onStatus = null,
} = {}) => {
	const doc = root?.ownerDocument;
	if (!isElement(root, doc) || typeof isReady !== 'function'
		|| typeof getContextKey !== 'function' || typeof resolveIntent !== 'function'
		|| typeof canDrop !== 'function' || typeof commit !== 'function'
		|| (onStatus !== null && typeof onStatus !== 'function')) {
		throw new TypeError('DESIGNER_DND_CONFIG_INVALID');
	}
	const sources = new Map();
	const zones = new Map();
	const live = doc.createElement('div');
	live.className = 'cb-core-design-shell__dnd-live';
	live.setAttribute('role', 'status');
	live.setAttribute('aria-live', 'polite');
	live.setAttribute('aria-atomic', 'true');
	// Keep the live region visually hidden, without relying on a theme CSS file.
	Object.assign(live.style, {
		position: 'absolute', width: '1px', height: '1px',
		padding: '0', margin: '-1px', overflow: 'hidden',
		clip: 'rect(0,0,0,0)', whiteSpace: 'nowrap',
	});
	root.append(live);

	let gesture = null;
	let highlighted = null;
	let destroyed = false;
	const emit = (status, message) => {
		live.textContent = message;
		if (onStatus) onStatus(Object.freeze({status, message}));
	};
	const context = () => {
		const value = getContextKey();
		if (!validId(value)) throw new TypeError('DESIGNER_DND_CONTEXT_INVALID');
		return value;
	};
	const ready = () => !destroyed && root.isConnected && isReady() === true;
	const clearHighlight = () => {
		if (highlighted) {
			highlighted.element.removeAttribute('data-cb-design-drop-active');
			highlighted.element.removeAttribute('data-cb-design-drop-edge');
		}
		highlighted = null;
	};
	const cancel = (reason = 'cancelled') => {
		clearHighlight();
		if (!gesture) return;
		gesture = null;
		emit(reason, reason === 'cancelled' ? 'Element placement cancelled.' : 'Element placement unavailable.');
	};
	const validGesture = () => {
		if (!gesture) return false;
		if (!ready() || !root.contains(gesture.origin)) {
			cancel('stale');
			return false;
		}
		try {
			if (context() !== gesture.contextKey) {
				cancel('stale');
				return false;
			}
			return true;
		} catch (_) {
			cancel('stale');
			return false;
		}
	};
	const candidate = (zone, edge) => {
		if (!validGesture() || !root.contains(zone.element) || zones.get(zone.element) !== zone) return null;
		if (!['before', 'inside', 'after'].includes(edge)) return null;
		try {
			const proposed = resolveIntent(Object.freeze({
				source: gesture.source, zoneId: zone.id, edge,
			}));
			if (!proposed || typeof proposed !== 'object' || Array.isArray(proposed)
				|| !isSync(proposed)) return null;
			const intent = Object.freeze({ ...proposed });
			const permitted = canDrop(Object.freeze({source: gesture.source, intent}));
			if (permitted !== true) return null;
			return intent;
		} catch (_) {
			return null;
		}
	};
	const highlight = (zone, edge) => {
		if (highlighted?.element !== zone.element || highlighted.edge !== edge) {
			clearHighlight();
			zone.element.setAttribute('data-cb-design-drop-active', 'true');
			zone.element.setAttribute('data-cb-design-drop-edge', edge);
			highlighted = {element: zone.element, edge};
		}
	};
	const performDrop = (zone, edge) => {
		const intent = candidate(zone, edge);
		if (!intent) return false;
		// Immediately invalidate the gesture. Nested drop events and duplicate
		// event delivery cannot dispatch a second command.
		const source = gesture.source;
		gesture = null;
		clearHighlight();
		try {
			const result = commit(Object.freeze({source, intent}));
			if (!isSync(result)) throw new TypeError('DESIGNER_DND_ASYNC_COMMIT_REFUSED');
			emit('committed', source.kind === 'node' ? 'Element moved.' : 'Element placed.');
			return true;
		} catch (_) {
			emit('refused', 'Element could not be placed.');
			return false;
		}
	};
	const edgeAt = (zone, event) => {
		const rect = zone.element.getBoundingClientRect();
		if (!rect || !Number.isFinite(rect.height) || rect.height <= 0) return 'inside';
		const ratio = (event.clientY - rect.top) / rect.height;
		return ratio < 0.25 ? 'before' : ratio > 0.75 ? 'after' : 'inside';
	};
	const releaseSource = (element) => {
		const record = sources.get(element);
		if (!record) return false;
		if (gesture?.origin === element) cancel();
		for (const [event, listener] of record.listeners) element.removeEventListener(event, listener);
		if (record.draggable === null) element.removeAttribute('draggable');
		else element.setAttribute('draggable', record.draggable);
		if (record.shortcuts === null) element.removeAttribute('aria-keyshortcuts');
		else element.setAttribute('aria-keyshortcuts', record.shortcuts);
		sources.delete(element);
		return true;
	};
	const releaseZone = (element) => {
		const record = zones.get(element);
		if (!record) return false;
		if (highlighted?.element === element) clearHighlight();
		for (const [event, listener] of record.listeners) element.removeEventListener(event, listener);
		if (record.tabindex === null) element.removeAttribute('tabindex');
		else element.setAttribute('tabindex', record.tabindex);
		zones.delete(element);
		return true;
	};
	const registerSource = (element, {id, type, kind = 'palette', path = []} = {}) => {
		if (destroyed || !isElement(element, doc) || sources.has(element)
			|| !root.contains(element) || !validId(id) || !validId(type)
			|| !['palette', 'node'].includes(kind)
			|| !validPath(path) || (kind === 'palette' && path.length !== 0)) {
			throw new TypeError('DESIGNER_DND_SOURCE_INVALID');
		}
		const source = Object.freeze({id, type, kind, path: Object.freeze([...path])});
		const begin = (mode) => {
			if (!ready()) return false;
			try {
				gesture = {source, origin: element, mode, contextKey: context()};
				emit('started', 'Move to a valid location, then drop. Press Escape to cancel.');
				return true;
			} catch (_) {
				cancel('stale');
				return false;
			}
		};
		const dragStart = event => {
			if (gesture) cancel();
			if (!begin('pointer')) {
				event.preventDefault();
				return;
			}
			// Transported text is a non-authoritative marker only. Actual source
			// identity lives in the private gesture, never in DataTransfer.
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'copyMove';
				event.dataTransfer.setData('text/plain', 'cb-designer-element');
			}
		};
		const dragEnd = () => { if (gesture?.origin === element) cancel(); };
		const keydown = event => {
			if (event.altKey && event.key === 'ArrowDown' && !event.repeat) {
				event.preventDefault();
				if (gesture) cancel();
				begin('keyboard');
				const first = [...zones.values()].find(zone => candidate(zone, 'inside'));
				first?.element.focus();
			}
		};
		const listeners = [['dragstart', dragStart], ['dragend', dragEnd], ['keydown', keydown]];
		const draggable = element.getAttribute('draggable');
		const shortcuts = element.getAttribute('aria-keyshortcuts');
		element.setAttribute('draggable', 'true');
		element.setAttribute('aria-keyshortcuts', 'Alt+ArrowDown');
		listeners.forEach(([name, fn]) => element.addEventListener(name, fn));
		sources.set(element, {listeners, draggable, shortcuts});
		return () => releaseSource(element);
	};
	const registerZone = (element, {id} = {}) => {
		if (destroyed || !isElement(element, doc) || zones.has(element)
			|| !root.contains(element) || !validId(id)) {
			throw new TypeError('DESIGNER_DND_ZONE_INVALID');
		}
		const zone = {id, element, edge: 'inside', listeners: [],
			tabindex: element.getAttribute('tabindex')};
		const over = event => {
			if (gesture?.mode !== 'pointer') return;
			const edge = edgeAt(zone, event);
			if (!candidate(zone, edge)) {
				// Never retain an old valid indicator after hovering an invalid
				// edge or after a live domain authorization change.
				clearHighlight();
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			if (event.dataTransfer) event.dataTransfer.dropEffect = gesture.source.kind === 'palette' ? 'copy' : 'move';
			highlight(zone, edge);
		};
		const leave = event => {
			if (highlighted?.element === element && !element.contains(event.relatedTarget)) clearHighlight();
		};
		const drop = event => {
			if (gesture?.mode !== 'pointer') return;
			const edge = edgeAt(zone, event);
			if (!candidate(zone, edge)) { clearHighlight(); return; }
			event.preventDefault();
			event.stopPropagation();
			performDrop(zone, edge);
		};
		const keydown = event => {
			if (gesture?.mode !== 'keyboard') return;
			if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
				event.preventDefault();
				zone.edge = event.key === 'ArrowUp' ? 'before' : 'after';
				if (candidate(zone, zone.edge)) highlight(zone, zone.edge);
				else clearHighlight();
			} else if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				if (candidate(zone, zone.edge)) performDrop(zone, zone.edge);
			}
		};
		const focus = () => {
			if (gesture?.mode === 'keyboard' && candidate(zone, zone.edge)) highlight(zone, zone.edge);
		};
		zone.listeners = [['dragover', over], ['dragleave', leave], ['drop', drop],
			['keydown', keydown], ['focus', focus]];
		if (zone.tabindex === null) element.setAttribute('tabindex', '0');
		zone.listeners.forEach(([name, fn]) => element.addEventListener(name, fn));
		zones.set(element, zone);
		return () => releaseZone(element);
	};
	const escape = event => {
		if (gesture && event.key === 'Escape') {
			event.preventDefault();
			cancel();
		}
	};
	doc.addEventListener('keydown', escape, true);
	const destroy = () => {
		if (destroyed) return;
		cancel();
		destroyed = true;
		doc.removeEventListener('keydown', escape, true);
		for (const element of [...sources.keys()]) releaseSource(element);
		for (const element of [...zones.keys()]) releaseZone(element);
		live.remove();
	};
	return Object.freeze({
		registerSource, registerZone, cancel, destroy,
		get active() { return !!gesture; },
	});
};
