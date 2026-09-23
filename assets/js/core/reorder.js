/**
 * Core Blueprint Reorder Foundation.
 *
 * Generic ordered-list interaction with pointer, keyboard and programmatic
 * movement. Consumers own domain meaning, authorization and persistence.
 *
 * Public module: @cb-core/reorder
 * Public runtime: window.cbCore.reorder
 *
 * @package CB\Core
 */

const hasDocument = typeof document !== 'undefined';
const dataEl = hasDocument ? document.getElementById('wp-script-module-data-@cb-core/reorder') : null;
let data = {};
try {
	data = dataEl ? JSON.parse(dataEl.textContent) : {};
} catch {
	data = {};
}
const i18n = data.i18n || {};

const formatMessage = (template, values) => template.replace(
	/%(\d+)\$[sd]/g,
	(match, index) => {
		const value = values[Number(index) - 1];
		return value === undefined || value === null ? match : String(value);
	}
);

const MAX_IDENTIFIER_BYTES = 191;
const DRAG_THRESHOLD = 6;
const controllers = new WeakMap();

const byteLength = (value) => new TextEncoder().encode(value).length;

const normalizeIdentifier = (value) => {
	if (typeof value !== 'string' && typeof value !== 'number') return '';
	const normalized = String(value).trim();
	if (!normalized || byteLength(normalized) > MAX_IDENTIFIER_BYTES) return '';
	return normalized;
};

const normalizeSnapshot = (snapshot) => {
	if (!Array.isArray(snapshot)) {
		throw new TypeError('Reorder snapshot must be an array.');
	}

	const listIds = new Set();
	const itemIds = new Set();

	return snapshot.map((list) => {
		if (!list || typeof list !== 'object' || Array.isArray(list)) {
			throw new TypeError('Reorder list must be an object.');
		}

		const listId = normalizeIdentifier(list.listId);
		if (!listId) throw new TypeError('Reorder list identifier is required.');
		if (listIds.has(listId)) throw new TypeError('Duplicate list identifier in reorder snapshot.');
		listIds.add(listId);

		if (!Array.isArray(list.itemIds)) {
			throw new TypeError('Reorder itemIds must be an array.');
		}

		const normalizedItems = list.itemIds.map((itemId) => {
			const normalized = normalizeIdentifier(itemId);
			if (!normalized) throw new TypeError('Reorder item identifier is required.');
			if (itemIds.has(normalized)) throw new TypeError('Duplicate item identifier in reorder snapshot.');
			itemIds.add(normalized);
			return normalized;
		});

		return { listId, itemIds: normalizedItems };
	});
};

const cloneSnapshot = (snapshot) => snapshot.map((list) => ({
	listId: list.listId,
	itemIds: [...list.itemIds],
}));

const locateItem = (snapshot, itemId) => {
	for (const list of snapshot) {
		const index = list.itemIds.indexOf(itemId);
		if (index !== -1) return { listId: list.listId, index };
	}
	return null;
};

const applyMove = (snapshot, move) => {
	const next = cloneSnapshot(normalizeSnapshot(snapshot));
	const source = next.find((list) => list.listId === move?.from?.listId);
	const target = next.find((list) => list.listId === move?.to?.listId);
	const itemId = normalizeIdentifier(move?.itemId);

	if (!source || !target || !itemId) return next;

	const sourceIndex = source.itemIds.indexOf(itemId);
	if (sourceIndex === -1) return next;

	source.itemIds.splice(sourceIndex, 1);
	const targetIndex = Math.max(0, Math.min(Number(move.to.index), target.itemIds.length));
	target.itemIds.splice(targetIndex, 0, itemId);
	return next;
};

const planMove = (snapshot, itemIdValue, targetListIdValue, targetIndexValue, options = {}) => {
	let before;
	try {
		before = normalizeSnapshot(snapshot);
	} catch {
		return null;
	}

	const itemId = normalizeIdentifier(itemIdValue);
	const targetListId = normalizeIdentifier(targetListIdValue);
	if (!itemId || !targetListId || !Number.isInteger(targetIndexValue)) return null;

	const from = locateItem(before, itemId);
	const targetList = before.find((list) => list.listId === targetListId);
	if (!from || !targetList) return null;

	const sameList = from.listId === targetListId;
	if (!sameList && options.crossList !== true) return null;

	const maxIndex = sameList ? targetList.itemIds.length - 1 : targetList.itemIds.length;
	if (targetIndexValue < 0 || targetIndexValue > maxIndex) return null;
	if (sameList && from.index === targetIndexValue) return null;

	const draftMove = {
		itemId,
		from: { listId: from.listId, index: from.index },
		to: { listId: targetListId, index: targetIndexValue },
	};

	const after = applyMove(before, draftMove);
	const affectedIds = sameList ? [from.listId] : [from.listId, targetListId];
	const affectedLists = affectedIds.map((listId) => {
		const list = after.find((candidate) => candidate.listId === listId);
		return { listId, itemIds: list ? [...list.itemIds] : [] };
	});

	return {
		...draftMove,
		affectedLists,
	};
};

const closestOwnedRoot = (element) => element?.closest?.('[data-cb-core-reorder]') || null;
const closestOwnedList = (element, root) => {
	const list = element?.closest?.('[data-cb-core-reorder-list]') || null;
	return list && closestOwnedRoot(list) === root ? list : null;
};

const ownedLists = (root) => [...root.querySelectorAll('[data-cb-core-reorder-list]')]
	.filter((list) => closestOwnedRoot(list) === root);

const ownedItems = (list, root) => [...list.querySelectorAll('[data-cb-core-reorder-item]')]
	.filter((item) => closestOwnedRoot(item) === root && closestOwnedList(item, root) === list);

const snapshotFromDom = (root) => normalizeSnapshot(ownedLists(root).map((list) => ({
	listId: list.dataset.cbCoreReorderList,
	itemIds: ownedItems(list, root).map((item) => item.dataset.cbCoreReorderItem),
})));

const itemElement = (root, itemId) => [...root.querySelectorAll('[data-cb-core-reorder-item]')]
	.find((item) => closestOwnedRoot(item) === root && normalizeIdentifier(item.dataset.cbCoreReorderItem) === itemId) || null;

const listElement = (root, listId) => ownedLists(root)
	.find((list) => normalizeIdentifier(list.dataset.cbCoreReorderList) === listId) || null;

const itemLabel = (root, itemId) => {
	const item = itemElement(root, itemId);
	return String(item?.dataset?.cbCoreReorderLabel || itemId);
};

const listLabel = (root, listId) => {
	const list = listElement(root, listId);
	return String(list?.dataset?.cbCoreReorderListLabel || listId);
};

const applyDomSnapshot = (root, snapshot) => {
	for (const listState of snapshot) {
		const list = listElement(root, listState.listId);
		if (!list) continue;
		for (const itemId of listState.itemIds) {
			const item = itemElement(root, itemId);
			if (item) list.appendChild(item);
		}
	}
};

const makeMoveDetail = (move, input) => ({
	itemId: move.itemId,
	from: { ...move.from },
	to: { ...move.to },
	affectedLists: move.affectedLists.map((list) => ({
		listId: list.listId,
		itemIds: [...list.itemIds],
	})),
	input,
});

const createStatus = (root) => {
	const status = document.createElement('div');
	status.className = 'cb-core-reorder__status';
	status.setAttribute('role', 'status');
	status.setAttribute('aria-live', 'polite');
	status.setAttribute('aria-atomic', 'true');
	root.appendChild(status);
	return status;
};

const createMarker = () => {
	const marker = document.createElement('div');
	marker.className = 'cb-core-reorder__drop-marker';
	marker.setAttribute('aria-hidden', 'true');
	return marker;
};

const enhance = (root, options = {}) => {
	if (!(root instanceof Element)) {
		throw new TypeError('Core Blueprint Reorder requires a root Element.');
	}
	if (controllers.has(root)) return controllers.get(root);

	snapshotFromDom(root);

	const settings = {
		crossList: options.crossList === true,
		canMove: typeof options.canMove === 'function' ? options.canMove : null,
		onMove: typeof options.onMove === 'function' ? options.onMove : null,
	};

	const abort = new AbortController();
	const { signal } = abort;
	const status = createStatus(root);
	const marker = createMarker();
	let busy = false;
	let destroyed = false;
	let pointerState = null;

	root.dataset.cbCoreReorderReady = '1';

	const announce = (message) => {
		status.textContent = '';
		window.setTimeout(() => {
			if (!destroyed) status.textContent = message;
		}, 0);
	};

	const focusItem = (itemId) => {
		const item = itemElement(root, itemId);
		const handle = item?.querySelector?.('[data-cb-core-reorder-handle]');
		if (handle instanceof HTMLElement) handle.focus();
	};

	const announceSuccess = (detail) => {
		const snapshot = snapshotFromDom(root);
		const target = snapshot.find((list) => list.listId === detail.to.listId);
		const position = (target?.itemIds.indexOf(detail.itemId) ?? -1) + 1;
		const total = target?.itemIds.length ?? 0;
		const label = itemLabel(root, detail.itemId);
		if (detail.from.listId === detail.to.listId) {
			announce(formatMessage(i18n.movedWithin || '%1$s moved to position %2$d of %3$d.', [label, position, total]));
			return;
		}
		announce(formatMessage(i18n.movedAcross || '%1$s moved to %2$s, position %3$d of %4$d.', [label, listLabel(root, detail.to.listId), position, total]));
	};

	const dispatch = (name, detail) => {
		root.dispatchEvent(new CustomEvent(name, {
			bubbles: true,
			detail,
		}));
	};

	const performMove = async (itemId, targetListId, targetIndex, input = 'programmatic') => {
		if (busy || destroyed) return false;

		const before = snapshotFromDom(root);
		const move = planMove(before, itemId, targetListId, targetIndex, {
			crossList: settings.crossList,
		});
		if (!move) return false;

		const detail = makeMoveDetail(move, input);
		if (settings.canMove && settings.canMove(detail) !== true) return false;

		const after = applyMove(before, move);
		applyDomSnapshot(root, after);
		busy = true;
		root.dataset.cbCoreReorderPending = 'true';
		root.setAttribute('aria-busy', 'true');

		try {
			const result = settings.onMove ? await settings.onMove(detail) : true;
			if (result === false) throw new Error('Reorder move was rejected by the consumer.');
			announceSuccess(detail);
			dispatch('cb:reorder:change', detail);
			return true;
		} catch (error) {
			applyDomSnapshot(root, before);
			focusItem(detail.itemId);
			announce(i18n.rollback || 'Move could not be completed. The previous position was restored.');
			dispatch('cb:reorder:error', { ...detail, error });
			return false;
		} finally {
			busy = false;
			delete root.dataset.cbCoreReorderPending;
			root.removeAttribute('aria-busy');
		}
	};

	const move = (itemId, targetListId, targetIndex) => performMove(
		normalizeIdentifier(itemId),
		normalizeIdentifier(targetListId),
		targetIndex,
		'programmatic'
	);

	const moveRelative = (itemIdValue, delta, input = 'programmatic') => {
		const itemId = normalizeIdentifier(itemIdValue);
		const snapshot = snapshotFromDom(root);
		const current = locateItem(snapshot, itemId);
		if (!current) return Promise.resolve(false);
		const list = snapshot.find((candidate) => candidate.listId === current.listId);
		if (!list) return Promise.resolve(false);
		const targetIndex = current.index + delta;
		if (targetIndex < 0 || targetIndex >= list.itemIds.length) return Promise.resolve(false);
		return performMove(itemId, current.listId, targetIndex, input);
	};

	const clearPointerPresentation = () => {
		marker.remove();
		if (pointerState?.item instanceof Element) pointerState.item.classList.remove('is-dragging');
		if (
			pointerState?.handle instanceof Element
			&& pointerState.handle.hasPointerCapture?.(pointerState.pointerId)
		) {
			pointerState.handle.releasePointerCapture?.(pointerState.pointerId);
		}
		root.classList.remove('is-reordering');
		pointerState = null;
	};

	const pointerCandidate = (event) => {
		const element = document.elementFromPoint(event.clientX, event.clientY);
		const list = closestOwnedList(element, root);
		if (!list) return null;

		const listId = normalizeIdentifier(list.dataset.cbCoreReorderList);
		if (!listId) return null;

		const draggingId = pointerState?.itemId || '';
		const items = ownedItems(list, root).filter((item) =>
			normalizeIdentifier(item.dataset.cbCoreReorderItem) !== draggingId
		);

		if (!items.length) {
			list.appendChild(marker);
			return { listId, index: 0 };
		}

		let targetIndex = items.length;
		let inserted = false;
		for (let index = 0; index < items.length; index += 1) {
			const rect = items[index].getBoundingClientRect();
			if (event.clientY < rect.top + rect.height / 2) {
				list.insertBefore(marker, items[index]);
				targetIndex = index;
				inserted = true;
				break;
			}
		}
		if (!inserted) list.appendChild(marker);

		const preview = planMove(
			snapshotFromDom(root),
			draggingId,
			listId,
			targetIndex,
			{ crossList: settings.crossList }
		);
		if (!preview) {
			marker.remove();
			return null;
		}
		if (settings.canMove && settings.canMove(makeMoveDetail(preview, 'pointer')) !== true) {
			marker.remove();
			return null;
		}

		return { listId, index: targetIndex };
	};

	const onPointerDown = (event) => {
		if (busy || event.button !== 0) return;
		const handle = event.target.closest?.('[data-cb-core-reorder-handle]');
		if (!(handle instanceof Element) || closestOwnedRoot(handle) !== root) return;

		const item = handle.closest('[data-cb-core-reorder-item]');
		const list = closestOwnedList(item, root);
		if (!item || !list) return;

		const itemId = normalizeIdentifier(item.dataset.cbCoreReorderItem);
		if (!itemId) return;

		pointerState = {
			pointerId: event.pointerId,
			handle,
			item,
			itemId,
			startX: event.clientX,
			startY: event.clientY,
			active: false,
			candidate: null,
		};

		handle.setPointerCapture?.(event.pointerId);
	};

	const autoScroll = (clientY) => {
		const edge = 48;
		const maxStep = 18;
		if (clientY < edge) {
			const strength = Math.max(0, Math.min(1, (edge - clientY) / edge));
			window.scrollBy({ top: -Math.ceil(maxStep * strength), behavior: 'auto' });
			return;
		}
		const lowerEdge = window.innerHeight - edge;
		if (clientY > lowerEdge) {
			const strength = Math.max(0, Math.min(1, (clientY - lowerEdge) / edge));
			window.scrollBy({ top: Math.ceil(maxStep * strength), behavior: 'auto' });
		}
	};

	const onPointerMove = (event) => {
		if (!pointerState || pointerState.pointerId !== event.pointerId || busy) return;
		const distance = Math.hypot(event.clientX - pointerState.startX, event.clientY - pointerState.startY);
		if (!pointerState.active && distance < DRAG_THRESHOLD) return;

		if (!pointerState.active) {
			pointerState.active = true;
			pointerState.item.classList.add('is-dragging');
			root.classList.add('is-reordering');
		}

		event.preventDefault();
		autoScroll(event.clientY);
		pointerState.candidate = pointerCandidate(event);
	};

	const onPointerUp = (event) => {
		if (!pointerState || pointerState.pointerId !== event.pointerId) return;
		const state = pointerState;
		const candidate = state.candidate;
		const wasActive = state.active;
		clearPointerPresentation();

		if (!wasActive || !candidate) return;
		void performMove(state.itemId, candidate.listId, candidate.index, 'pointer');
	};

	const onPointerCancel = (event) => {
		if (!pointerState || pointerState.pointerId !== event.pointerId) return;
		clearPointerPresentation();
	};

	const onKeyDown = (event) => {
		if (pointerState && event.key === 'Escape') {
			event.preventDefault();
			clearPointerPresentation();
			announce(i18n.cancelled || 'Move cancelled.');
			return;
		}
		if (busy || !event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;
		const handle = event.target.closest?.('[data-cb-core-reorder-handle]');
		if (!(handle instanceof Element) || closestOwnedRoot(handle) !== root) return;
		const item = handle.closest('[data-cb-core-reorder-item]');
		const itemId = normalizeIdentifier(item?.dataset?.cbCoreReorderItem);
		if (!itemId) return;

		event.preventDefault();
		const delta = event.key === 'ArrowUp' ? -1 : 1;
		void moveRelative(itemId, delta, 'keyboard').then((changed) => {
			if (changed) focusItem(itemId);
		});
	};

	root.addEventListener('pointerdown', onPointerDown, { signal });
	root.addEventListener('pointermove', onPointerMove, { signal });
	root.addEventListener('pointerup', onPointerUp, { signal });
	root.addEventListener('pointercancel', onPointerCancel, { signal });
	root.addEventListener('keydown', onKeyDown, { signal });

	const controller = Object.freeze({
		snapshot: () => snapshotFromDom(root),
		move,
		moveUp: (itemId) => moveRelative(itemId, -1),
		moveDown: (itemId) => moveRelative(itemId, 1),
		refresh: () => snapshotFromDom(root),
		destroy: () => {
			if (destroyed) return;
			destroyed = true;
			abort.abort();
			clearPointerPresentation();
			status.remove();
			delete root.dataset.cbCoreReorderReady;
			delete root.dataset.cbCoreReorderPending;
			root.removeAttribute('aria-busy');
			controllers.delete(root);
		},
	});

	controllers.set(root, controller);
	return controller;
};

const isEnhanced = (root) => controllers.has(root);

if (typeof window !== 'undefined') {
	window.cbCore = window.cbCore || {};
	window.cbCore.reorder = Object.freeze({ enhance, isEnhanced });
}

export {
	applyMove,
	enhance,
	isEnhanced,
	normalizeIdentifier,
	normalizeSnapshot,
	planMove,
};
