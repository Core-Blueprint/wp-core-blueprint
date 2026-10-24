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
	const nativeHost = typed === true;
	const typedDomain = typed === 'domain';
	if (typed !== false && !nativeHost && !typedDomain) {
		throw new TypeError('Unknown Designer selection strategy.');
	}
	if (
		!session
		|| (!nativeHost && typeof session.subscribeSelection !== 'function')
		|| typeof session.select !== 'function'
		|| (!nativeHost && !typedDomain && typeof session.clearSelection !== 'function')
		|| typeof session.selection !== 'function'
		|| (!nativeHost && !typedDomain && typeof session.inspector !== 'function')
		|| (nativeHost && typeof session.subscribe !== 'function')
		|| (typedDomain && (typeof session.subscribe !== 'function'
			|| typeof session.subscribeHistory !== 'function'))
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
			primary: (nativeHost || typedDomain)
				? (snapshot.primary ?? null)
				: (snapshot.primary === null ? null : [...snapshot.primary]),
			inspector: context.inspector ?? (typeof session.inspector === 'function' ? session.inspector() : null),
			event: context.event ?? Object.freeze({ source: 'controller', action: 'sync' }),
			profile: context.profile ?? session.profile,
			project: session.project(),
		});

		// Typed Host invalidation is the only render scheduling entrypoint.
		if (nativeHost && invalidate) {
			invalidate(viewContext);
		} else {
			layersAdapter?.(viewContext);
			inspectorAdapter?.(viewContext);
			if (invalidate) invalidate(viewContext);
			else canvasAdapter?.(viewContext);
		}

		if (
			openInspectorOnInsert
			&& viewContext.primary !== null
			&& viewContext.event?.source === 'command'
			&& viewContext.event?.action === 'insert-node'
		) {
			activateInspector();
		}
	};


	const unsubscribers = [];
	let domainCommitPending = () => false;
	if (nativeHost) {
		unsubscribers.push(session.subscribe((event) => synchronize(session.selection(), { event })));
		synchronize(session.selection(), {
			event: Object.freeze({ source: 'controller', action: 'sync' }),
		});
	} else if (typedDomain) {
		// Canonical History dispatches Project, commit-marked Selection and
		// finally History. A same-project context reset has no Project event.
		// The History revision marker, never consumer-supplied source labels,
		// separates commit Selection from independent select/clear.
		let pendingProject = false;
		let pendingEvent = null;
		let pendingRevision = null;
		let pendingInvalid = false;
		let lastHistoryRevision = null;
		domainCommitPending = () => pendingProject || pendingRevision !== null || pendingInvalid;
		unsubscribers.push(session.subscribe((_project, event) => {
			pendingProject = true;
			pendingEvent = event;
		}));
		unsubscribers.push(session.subscribeSelection((snapshot, context) => {
			const event = context.event;
			if (Object.hasOwn(event, 'historyRevision')) {
				const revision = event.historyRevision;
				if (!Number.isSafeInteger(revision) || revision < 1
					|| pendingRevision !== null
					|| (lastHistoryRevision !== null && revision <= lastHistoryRevision)) {
					pendingInvalid = true;
				}
				pendingRevision = revision;
				pendingEvent = event;
				return;
			}
			if (pendingProject || pendingRevision !== null || pendingInvalid) {
				// Unmarked Selection within a pending commit has no proof of
				// matching History. Never publish an unverified final view.
				pendingInvalid = true;
				return;
			}
			// Independent select/clear has no History event and renders now.
			synchronize(snapshot, { event });
		}));
		unsubscribers.push(session.subscribeHistory((historyEvent) => {
			const revision = historyEvent.historyRevision;
			const correlated = Number.isSafeInteger(revision) && revision > 0
				&& (lastHistoryRevision === null || revision > lastHistoryRevision)
				&& !pendingInvalid
				&& (pendingRevision === null || pendingRevision === revision);
			const event = pendingEvent ?? historyEvent;
			// Clear BEFORE invoking adapters: an exception is a diagnostic, not
			// a stale pending view or justification to repeat a committed edit.
			pendingProject = false;
			pendingEvent = null;
			pendingRevision = null;
			pendingInvalid = false;
			if (!correlated) {
				throw new Error('Designer Selection History correlation failed.');
			}
			lastHistoryRevision = revision;
			// History-only clear/rebase also requires exactly one final view.
			synchronize(session.selection(), { event });
		}));
		synchronize(session.selection(), {
			event: Object.freeze({ source: 'controller', action: 'sync' }),
		});
	} else {
		unsubscribers.push(session.subscribeSelection(synchronize, { emitCurrent: true }));
	}
	const unsubscribe = () => { for (const release of unsubscribers.splice(0)) release(); };

	return Object.freeze({
		select(path, {
			additive = false,
			openInspector = true,
			source = 'ui',
			expectedEpoch,
		} = {}) {
			if (destroyed) throw new Error('Designer selection controller has been destroyed.');
			const ref = (nativeHost || typedDomain) && Array.isArray(path)
				? Object.freeze({ kind: 'node', path: [...path] }) : path;
			const accepted = nativeHost || typedDomain
				? session.select(
					additive
						? [...(session.selection().refs || []).filter((candidate) => JSON.stringify(candidate) !== JSON.stringify(ref)), ref]
						: [ref],
					ref,
					...(typedDomain ? [{ expectedEpoch, source }] : []),
				)
				: session.select(path, { additive, source });
			if (accepted && openInspector) activateInspector();
			return accepted;
		},
		clear({ source = 'ui', expectedEpoch } = {}) {
			if (destroyed) throw new Error('Designer selection controller has been destroyed.');
			return nativeHost ? session.select([], null)
				: typedDomain ? session.select([], null, { expectedEpoch, source })
					: session.clearSelection({ source });
		},
		refresh() {
			if (destroyed) throw new Error('Designer selection controller has been destroyed.');
			if (typedDomain && domainCommitPending()) {
				throw new Error('Designer Selection view synchronization is pending History.');
			}
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
