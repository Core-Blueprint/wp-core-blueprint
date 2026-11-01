// Internal to Base; the public controller cannot rebind itself.
const selectionSessionRebinding = new WeakMap();
export const prepareDesignerSelectionRebinding = (controller, nextSession, expectedSession) => {
	const prepare = selectionSessionRebinding.get(controller);
	if (!prepare) throw new Error('DESIGNER_SELECTION_REBIND_REFUSED');
	return prepare(nextSession, expectedSession);
};

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
	let activeGeneration = 0;
	let nextSessionGeneration = 0;
	let pendingReceipt = null;

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


	// Each context generation has independent subscriptions and correlation state.
	// Observers retained or invoked late after rebinding are inert.
	const attach = (candidate, generation) => {
		const off = [];
		const live = () => !destroyed && generation === activeGeneration && candidate === session;
		const publish = (snapshot, context = {}) => { if (live()) synchronize(snapshot, context); };
		let pending = () => false;
		try {
			if (nativeHost) {
				off.push(candidate.subscribe((event) => {
					if (live()) publish(candidate.selection(), {event});
				}));
			} else if (typedDomain) {
				let pendingProject = false;
				let pendingEvent = null;
				let pendingRevision = null;
				let pendingInvalid = false;
				let lastHistoryRevision = null;
				pending = () => pendingProject || pendingRevision !== null || pendingInvalid;
				off.push(candidate.subscribe((_project, event) => {
					if (!live()) return;
					pendingProject = true;
					pendingEvent = event;
				}));
				off.push(candidate.subscribeSelection((snapshot, context) => {
					if (!live()) return;
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
						pendingInvalid = true;
						return;
					}
					publish(snapshot, {event});
				}));
				off.push(candidate.subscribeHistory((historyEvent) => {
					if (!live()) return;
					const revision = historyEvent.historyRevision;
					const correlated = Number.isSafeInteger(revision) && revision > 0
						&& (lastHistoryRevision === null || revision > lastHistoryRevision)
						&& !pendingInvalid
						&& (pendingRevision === null || pendingRevision === revision);
					const event = pendingEvent ?? historyEvent;
					pendingProject = false;
					pendingEvent = null;
					pendingRevision = null;
					pendingInvalid = false;
					if (!correlated) throw new Error('Designer Selection History correlation failed.');
					lastHistoryRevision = revision;
					publish(candidate.selection(), {event});
				}));
			} else {
				// Prepare must not render a STAGED selection via emitCurrent.
				off.push(candidate.subscribeSelection((snapshot, context) => {
					publish(snapshot, context);
				}, {emitCurrent: false}));
			}
		} catch (error) {
			for (const release of off.splice(0)) release();
			throw error;
		}
		return Object.freeze({
			unsubscribe() { for (const release of off.splice(0)) release(); },
			refresh(action = 'refresh') {
				if (!live()) throw new Error('DESIGNER_SELECTION_REBIND_REFUSED');
				if (typedDomain && pending()) {
					throw new Error('Designer Selection view synchronization is pending History.');
				}
				publish(candidate.selection(), {
					event: Object.freeze({source: 'controller', action}),
					profile: candidate.profile,
					inspector: typeof candidate.inspector === 'function' ? candidate.inspector() : null,
				});
			},
		});
	};
	const assertSessionInterface = (candidate) => {
		if (!candidate || typeof candidate.select !== 'function'
			|| typeof candidate.selection !== 'function'
			|| typeof candidate.project !== 'function'
			|| (!nativeHost && typeof candidate.subscribeSelection !== 'function')
			|| (!nativeHost && !typedDomain && (typeof candidate.clearSelection !== 'function'
				|| typeof candidate.inspector !== 'function'))
			|| (nativeHost && typeof candidate.subscribe !== 'function')
			|| (typedDomain && (typeof candidate.subscribe !== 'function'
				|| typeof candidate.subscribeHistory !== 'function'))) {
			throw new TypeError('Designer selection controller requires a Design Foundation session.');
		}
	};
	let currentSubscription = attach(session, activeGeneration);
	currentSubscription.refresh('sync');

	const prepareRebinding = (nextSession, expectedSession) => {
		if (destroyed || pendingReceipt || nextSession === session || session !== expectedSession) {
			throw new Error('DESIGNER_SELECTION_REBIND_REFUSED');
		}
		assertSessionInterface(nextSession);
		if (!Number.isSafeInteger(nextSessionGeneration + 1)) {
			throw new RangeError('Designer Selection binding generation exhausted.');
		}
		const previousSession = session;
		const previousGeneration = activeGeneration;
		const previousSubscription = currentSubscription;
		const nextGeneration = ++nextSessionGeneration;
		const nextSubscription = attach(nextSession, nextGeneration);
		try {
			nextSession.project();
			nextSession.selection();
		} catch (error) {
			nextSubscription.unsubscribe();
			throw error;
		}
		let phase = 'PREPARED';
		const restore = () => {
			session = previousSession;
			activeGeneration = previousGeneration;
			currentSubscription = previousSubscription;
			nextSubscription.unsubscribe();
			phase = 'ROLLED_BACK';
			pendingReceipt = null;
			previousSubscription.refresh();
		};
		const receipt = Object.freeze({
			verify() {
				return phase === 'COMMITTED' && !destroyed && session === nextSession
					&& activeGeneration === nextGeneration && currentSubscription === nextSubscription;
			},
			commit() {
				if (phase !== 'PREPARED' || destroyed) throw new Error('DESIGNER_SELECTION_REBIND_REFUSED');
				session = nextSession;
				activeGeneration = nextGeneration;
				currentSubscription = nextSubscription;
				phase = 'COMMITTED';
				try { nextSubscription.refresh(); }
				catch (error) {
					try { restore(); }
					catch (rollbackError) { error.rollbackError = rollbackError; }
					throw error;
				}
				return true;
			},
			rollback() {
				if (phase === 'ROLLED_BACK') return true;
				if (phase === 'FINALIZED') throw new Error('DESIGNER_SELECTION_REBIND_FINALIZED');
				if (phase === 'PREPARED') {
					nextSubscription.unsubscribe();
					phase = 'ROLLED_BACK';
					pendingReceipt = null;
					return true;
				}
				restore();
				return true;
			},
			finalize() {
				if (phase !== 'COMMITTED') throw new Error('DESIGNER_SELECTION_REBIND_REFUSED');
				previousSubscription.unsubscribe();
				phase = 'FINALIZED';
				pendingReceipt = null;
				return true;
			},
		});
		pendingReceipt = receipt;
		return receipt;
	};

	const controller = Object.freeze({
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
			currentSubscription.refresh();
		},
		destroy() {
			if (destroyed) return;
			// Only the Base coordinator may settle a prepared/committed binding.
			if (pendingReceipt) throw new Error('DESIGNER_SELECTION_REBIND_PENDING');
			destroyed = true;
			currentSubscription.unsubscribe();
			selectionSessionRebinding.delete(controller);
		},
	});
	selectionSessionRebinding.set(controller, prepareRebinding);
	return controller;
};
