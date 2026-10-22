const optionalCallback = (callback, label) => {
	if (callback === null || callback === undefined) return null;
	if (typeof callback !== 'function') {
		throw new TypeError(`Designer selection ${label} adapter must be a function or null.`);
	}
	return callback;
};

/**
 * Bind the canonical Designer selection lifecycle to consumer-rendered views.
 *
 * Base owns lifecycle and deterministic synchronization order. Consumers supply
 * only domain render adapters for Layers, Inspector and canvas semantics.
 */
export const createDesignerSelectionController = ({
	session,
	shell = null,
	renderLayers = null,
	renderInspector = null,
	syncCanvas = null,
	inspectorPanel = 'inspector',
	openInspectorOnInsert = true,
	typed = false,
	requestInvalidation = null,
} = {}) => {
	if (
		!session
		|| typeof session.subscribeSelection !== 'function'
		|| typeof session.select !== 'function'
		|| (!typed && typeof session.clearSelection !== 'function')
		|| typeof session.selection !== 'function'
		|| (!typed && typeof session.inspector !== 'function')
		|| (typed && typeof session.subscribe !== 'function')
	) {
		throw new TypeError('Designer selection controller requires a Design Foundation session.');
	}

	const layersAdapter = optionalCallback(renderLayers, 'Layers');
	const inspectorAdapter = optionalCallback(renderInspector, 'Inspector');
	const canvasAdapter = optionalCallback(syncCanvas, 'canvas');
	const invalidate = optionalCallback(requestInvalidation, 'Host invalidation');
	const panelId = String(inspectorPanel || 'inspector').trim() || 'inspector';
	let destroyed = false;

	const activateInspector = () => {
		if (typeof shell?.activatePanel === 'function') shell.activatePanel(panelId);
	};

	const synchronize = (snapshot, context = {}) => {
		if (destroyed) return;
		const viewContext = Object.freeze({
			selection: snapshot,
			primary: typed
				? (snapshot.primary ?? null)
				: (snapshot.primary === null ? null : [...snapshot.primary]),
			inspector: context.inspector ?? (typeof session.inspector === 'function' ? session.inspector() : null),
			event: context.event ?? Object.freeze({ source: 'controller', action: 'sync' }),
			profile: context.profile ?? session.profile,
			project: session.project(),
		});

		layersAdapter?.(viewContext);
		inspectorAdapter?.(viewContext);
		// Host owns the only render queue when its invalidate bridge exists.
		if (invalidate) invalidate(viewContext);
		else canvasAdapter?.(viewContext);

		if (
			openInspectorOnInsert
			&& viewContext.primary !== null
			&& viewContext.event?.source === 'command'
			&& viewContext.event?.action === 'insert-node'
		) {
			activateInspector();
		}
	};

	const subscribe = typed ? session.subscribe : session.subscribeSelection;
	if (typeof subscribe !== 'function') throw new TypeError('Typed Host facade requires subscribe().');
	const unsubscribe = typed
		? subscribe((event) => synchronize(session.selection(), { event }))
		: subscribe(synchronize, { emitCurrent: true });
	if (typed) synchronize(session.selection(), {
		event: Object.freeze({ source: 'controller', action: 'sync' }),
	});

	return Object.freeze({
		select(path, {
			additive = false,
			openInspector = true,
			source = 'ui',
		} = {}) {
			if (destroyed) throw new Error('Designer selection controller has been destroyed.');
			const ref = typed && Array.isArray(path) ? Object.freeze({ kind: 'node', path: [...path] }) : path;
			const accepted = typed
				? session.select(
					additive
						? [...(session.selection().refs || []).filter((candidate) => JSON.stringify(candidate) !== JSON.stringify(ref)), ref]
						: [path],
					path,
				)
				: session.select(path, { additive, source });
			if (accepted && openInspector) activateInspector();
			return accepted;
		},
		clear({ source = 'ui' } = {}) {
			if (destroyed) throw new Error('Designer selection controller has been destroyed.');
			return typed ? session.select([], null) : session.clearSelection({ source });
		},
		refresh() {
			if (destroyed) throw new Error('Designer selection controller has been destroyed.');
			synchronize(session.selection(), {
				event: Object.freeze({ source: 'controller', action: 'refresh' }),
				profile: session.profile,
				inspector: typeof session.inspector === 'function' ? session.inspector() : null,
			});
		},
		destroy() {
			if (destroyed) return;
			destroyed = true;
			unsubscribe();
		},
	});
};
