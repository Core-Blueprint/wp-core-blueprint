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
} = {}) => {
	if (
		!session
		|| typeof session.subscribeSelection !== 'function'
		|| typeof session.select !== 'function'
		|| typeof session.clearSelection !== 'function'
		|| typeof session.selection !== 'function'
		|| typeof session.inspector !== 'function'
	) {
		throw new TypeError('Designer selection controller requires a Design Foundation session.');
	}

	const layersAdapter = optionalCallback(renderLayers, 'Layers');
	const inspectorAdapter = optionalCallback(renderInspector, 'Inspector');
	const canvasAdapter = optionalCallback(syncCanvas, 'canvas');
	const panelId = String(inspectorPanel || 'inspector').trim() || 'inspector';
	let destroyed = false;

	const activateInspector = () => {
		if (typeof shell?.activatePanel === 'function') shell.activatePanel(panelId);
	};

	const synchronize = (snapshot, context = {}) => {
		if (destroyed) return;
		const viewContext = Object.freeze({
			selection: snapshot,
			primary: snapshot.primary === null ? null : [...snapshot.primary],
			inspector: context.inspector ?? session.inspector(),
			event: context.event ?? Object.freeze({ source: 'controller', action: 'sync' }),
			profile: context.profile ?? session.profile,
			project: session.project(),
		});

		layersAdapter?.(viewContext);
		inspectorAdapter?.(viewContext);
		canvasAdapter?.(viewContext);

		if (
			openInspectorOnInsert
			&& viewContext.primary !== null
			&& viewContext.event?.source === 'command'
			&& viewContext.event?.action === 'insert-node'
		) {
			activateInspector();
		}
	};

	const unsubscribe = session.subscribeSelection(synchronize, { emitCurrent: true });

	return Object.freeze({
		select(path, {
			additive = false,
			openInspector = true,
			source = 'ui',
		} = {}) {
			if (destroyed) throw new Error('Designer selection controller has been destroyed.');
			const accepted = session.select(path, { additive, source });
			if (accepted && openInspector) activateInspector();
			return accepted;
		},
		clear({ source = 'ui' } = {}) {
			if (destroyed) throw new Error('Designer selection controller has been destroyed.');
			return session.clearSelection({ source });
		},
		refresh() {
			if (destroyed) throw new Error('Designer selection controller has been destroyed.');
			synchronize(session.selection(), {
				event: Object.freeze({ source: 'controller', action: 'refresh' }),
				profile: session.profile,
				inspector: session.inspector(),
			});
		},
		destroy() {
			if (destroyed) return;
			destroyed = true;
			unsubscribe();
		},
	});
};
