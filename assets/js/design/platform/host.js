import { createLifecycleScope, createRenderQueue } from './lifecycle.js';
import { inspectTrustedContext, contextIdentityKey, nextEpoch } from './context.js';
import { instantiateRenderAdapter } from './render-adapter.js';
import { preflight as verifyRuntime } from './preflight.js';
import { immutableData } from './manifest.js';

const leasedRoots = new WeakMap();
const refusal = (code) => Object.assign(new Error(code), {code});
const required = (object, methods, code) => {
	if (!object || methods.some((name) => typeof object[name] !== 'function')) throw refusal(code);
	return object;
};

/**
 * One root, one live canonical Interaction session, one serialized render
 * queue. All factories are trusted imports supplied by Luna 1's Base wiring;
 * nothing here executes document-provided callbacks, URLs or permissions.
 *
 * The integration factory supplies canvasRoot, resolveRegistryView,
 * createSessionBridge, createSaveCoordinator, readPersistedFields and a
 * createShell({root, editor, context, signal}) function. The shell factory
 * MUST accept a detached offscreen root and MUST NOT claim or mutate the
 * current active shell root until the Host atomically commits staging.
 */
export function createPlatformHost({
	root,
	canvasRoot,
	resolveRegistryView,
	createSessionBridge,
	createSaveCoordinator,
	createShell,
	readPersistedFields,
	onDiagnostic = null,
} = {}) {
	if (!root?.ownerDocument || !canvasRoot || !root.contains(canvasRoot)
		|| ['resolveRegistryView','createSessionBridge','createSaveCoordinator','createShell','readPersistedFields']
			.some((name) => typeof ({resolveRegistryView,createSessionBridge,createSaveCoordinator,createShell,readPersistedFields})[name] !== 'function')) {
		throw new TypeError('HOST_CONTEXT_REFUSED');
	}
	if (leasedRoots.has(root)) throw refusal('HOST_ALREADY_MOUNTED');
	const queue = createRenderQueue();
	let state = 'IDLE';
	let active = null;
	let pendingMount = null;
	let mountingKey = null;
	let transition = null;
	let unmountPromise = null;
	let epoch = -1;
	let ticket = 0;
	let publishedEditor = null;
	const diagnostics = (code) => {
		if (typeof onDiagnostic === 'function') {
			try { onDiagnostic(Object.freeze({code})); } catch (_) { /* isolated observer */ }
		}
	};
	const assertReady = (candidate) => {
		if (state !== 'READY' || candidate !== active || candidate.scope.signal.aborted) {
			throw refusal('HOST_CONTEXT_REFUSED');
		}
	};
	const ensureGate = (action) => {
		const gate = active?.save.transitionGate(action);
		if (!gate || gate.allowed !== true) {
			throw refusal(gate?.code || 'SAVE_PENDING_CONTEXT_BLOCKED');
		}
	};
	const editorFacade = (holder) => Object.freeze({
		project() { return holder.bridge.project(); },
		snapshot() { return holder.bridge.snapshot(); },
		persistedFields() {
			assertReady(holder);
			return immutableData(readPersistedFields(holder.save));
		},
		selection() { return holder.bridge.selection(); },
		select(refs, primary = null) {
			assertReady(holder);
			return holder.bridge.select(refs, primary);
		},
		execute(commandId, input, options = {}) {
			assertReady(holder);
			return holder.bridge.execute(commandId, input, options);
		},
		executeBatch(batch, options = {}) {
			assertReady(holder);
			return holder.bridge.executeBatch(batch, options);
		},
		undo() { assertReady(holder); return holder.bridge.undo(); },
		redo() { assertReady(holder); return holder.bridge.redo(); },
		historyStatus() { return holder.bridge.historyStatus(); },
		subscribe(listener) {
			// The disconnected shell subscribes only while the Host commits
			// its live canvas. Commands, Save and external Editor access still
			// require READY; this is not a second session authority.
			if (state !== 'COMMITTING' || holder !== active || holder.scope.signal.aborted) {
				assertReady(holder);
			}
			return holder.bridge.subscribe(listener);
		},
		save() {
			assertReady(holder);
			return holder.save.save();
		},
	});

	/** Allocate offscreen; before commit, no active DOM/session is replaced. */
	const stage = async (candidate) => {
		const scope = createLifecycleScope();
		const holder = {scope, canvas: null, bridge: null, save: null, adapter: null, editor: null,
			identity: null, profileId: null, lastProject: null, revision: 0, viewport: 'desktop'};
		try {
			if (!candidate || typeof candidate !== 'object' || !candidate.bootstrap) throw refusal('HOST_CONTEXT_REFUSED');
			const registry = await resolveRegistryView(candidate.bootstrap);
			if (scope.signal.aborted) throw refusal('HOST_CONTEXT_REFUSED');
			const identity = inspectTrustedContext(candidate.bootstrap, registry);
			if (epoch >= 0) nextEpoch(epoch, identity.contextEpoch);
			holder.identity = identity;
			holder.profileId = registry.profileId;
			holder.registry = registry;
			if (!registry.profile?.moduleId) throw refusal('HOST_MODULE_MISSING');
			const result = await verifyRuntime({
				evidence: registry.evidence, frozenRegistry: registry, root,
				contextEpoch: identity.contextEpoch, signal: scope.signal,
			});
			if (!result.ready) throw refusal(result.code);
			if (scope.signal.aborted) throw refusal('HOST_CONTEXT_REFUSED');
			const module = registry.moduleNamespace(registry.profile.moduleId);
			if (typeof module?.createRenderAdapter !== 'function') throw refusal('HOST_MODULE_MISSING');
			const canvas = root.ownerDocument.createElement('div');
			canvas.setAttribute('data-cb-platform-canvas', '');
			holder.canvas = canvas;
			const bridge = required(await createSessionBridge(Object.freeze({
				initialProject: candidate.project, context: identity, profile: registry.profile,
				schemaSnapshot: registry.schemaSnapshot, signal: scope.signal,
			})), ['project','snapshot','selection','select','execute','executeBatch',
				'undo','redo','historyStatus','subscribe','dispose'], 'HOST_CAPABILITY_UNAVAILABLE');
			holder.bridge = bridge;
			scope.own(() => bridge.dispose());
			const save = required(await createSaveCoordinator(Object.freeze({
				sessionBridge: bridge, identity, persistedFieldFacet: registry.persistedFieldFacet(registry.profileId),
				initialFields: candidate.persistedFields, evidence: registry.evidence, signal: scope.signal,
			})), ['save','status','transitionGate','subscribe','destroy'], 'HOST_CAPABILITY_UNAVAILABLE');
			holder.save = save;
			scope.own(() => save.destroy());
			holder.editor = editorFacade(holder);
			// Build actual Base chrome in an offscreen canvas first. Profile
			// RenderAdapter owns ONLY the canvas workarea, never Host chrome.
			if (canvas.isConnected) throw refusal('HOST_CONTEXT_REFUSED');
			const shell = required(await createShell(Object.freeze({
				root: canvas, editor: holder.editor, context: identity, signal: scope.signal,
			})), ['destroy','activatePlatformSession'], 'HOST_CAPABILITY_UNAVAILABLE');
			holder.shell = shell;
			scope.own(() => shell.destroy());
			if (canvas.isConnected) throw refusal('HOST_CONTEXT_REFUSED');
			const renderSurface = canvas.querySelector('[data-cb-platform-render-surface]');
			if (!renderSurface || !canvas.contains(renderSurface)
				|| renderSurface.isConnected || renderSurface.childNodes.length) {
				throw refusal('HOST_CAPABILITY_UNAVAILABLE');
			}
			holder.renderSurface = renderSurface;
			const adapter = instantiateRenderAdapter(module.createRenderAdapter, {
				root: renderSurface, document: root.ownerDocument, profileId: holder.profileId,
			});
			holder.adapter = adapter;
			scope.own(() => adapter.destroy());
			await queue.push(async () => {
				if (scope.signal.aborted) throw refusal('RENDER_STALE');
				await adapter.mount({root: renderSurface, editor: holder.editor, context: identity,
					viewport: holder.viewport, signal: scope.signal});
				if (scope.signal.aborted) throw refusal('RENDER_STALE');
				await adapter.update({
					project: bridge.project(), previousProject: null, selection: bridge.selection(),
					changedKeys: Object.freeze([]), viewport: holder.viewport,
					contextEpoch: identity.contextEpoch, revision: holder.revision, signal: scope.signal,
				});
				if (scope.signal.aborted) throw refusal('RENDER_STALE');
				adapter.syncSelection({selection: bridge.selection(), reason: 'context'});
				if (typeof shell.renderInteraction === 'function') {
					await shell.renderInteraction(Object.freeze({
						project: bridge.project(), selection: bridge.selection(),
						context: identity, signal: scope.signal,
					}));
				}
			});
			if (scope.signal.aborted) throw refusal('RENDER_STALE');
			holder.lastProject = bridge.project();
			const unsub = bridge.subscribe(() => {
				if (holder === active && state === 'READY') {
					void refresh('command').catch((error) => diagnostics(error.code || 'HOST_CONTEXT_REFUSED'));
				}
			});
			if (typeof unsub !== 'function') throw refusal('HOST_CAPABILITY_UNAVAILABLE');
			scope.own(unsub);
			return holder;
		} catch (error) {
			scope.abort();
			await queue.drain();
			const result = await scope.dispose();
			if (!result.ok) diagnostics(result.code);
			throw error;
		}
	};

	const commitStage = (holder) => {
		if (holder.scope.signal.aborted) throw refusal('HOST_CONTEXT_REFUSED');
		const previous = active;
		const oldState = state;
		const oldEditor = publishedEditor;
		const oldNodes = [...canvasRoot.childNodes];
		try {
			// This synchronous commit is the ONE place where the active DOM
			// changes. Restore the prior canvas if readiness publication fails.
			state = 'COMMITTING';
			active = holder;
			canvasRoot.replaceChildren(holder.canvas);
			if (holder.shell.activatePlatformSession() !== true) {
				throw refusal('HOST_CAPABILITY_UNAVAILABLE');
			}
			publishedEditor = holder.editor;
			epoch = holder.identity.contextEpoch;
			++ticket;
			state = 'READY';
			return previous;
		} catch (error) {
			canvasRoot.replaceChildren(...oldNodes);
			active = previous;
			publishedEditor = oldEditor;
			state = oldState;
			throw error;
		}
	};

	const refresh = (reason = 'command') => {
		const holder = active;
		assertReady(holder);
		const currentTicket = ++ticket;
		return queue.push(async () => {
			if (holder !== active || holder.scope.signal.aborted || currentTicket !== ticket) return;
			const focus = holder.adapter.captureFocus();
			const project = holder.bridge.project();
			const selection = holder.bridge.selection();
			const previous = holder.lastProject;
			const revision = ++holder.revision;
			await holder.adapter.update({
				project, previousProject: previous, selection, changedKeys: Object.freeze([]),
				viewport: holder.viewport, contextEpoch: holder.identity.contextEpoch, revision,
				signal: holder.scope.signal,
			});
			if (holder !== active || holder.scope.signal.aborted || currentTicket !== ticket) return;
			holder.adapter.syncSelection({selection, reason});
			if (typeof holder.shell?.renderInteraction === 'function') {
				await holder.shell.renderInteraction(Object.freeze({
						project, selection, context: holder.identity, signal: holder.scope.signal,
				}));
			}
			holder.adapter.restoreFocus(focus, {reason: ['command','selection','preview','context'].includes(reason) ? reason : 'command'});
			holder.lastProject = project;
		});
	};

	const host = Object.freeze({
		root,
		get state() { return state; },
		get profileId() { return active?.profileId ?? null; },
		get context() { return active?.identity ?? null; },
		get editor() { return publishedEditor; },
		mount(initialContext) {
			if (state === 'READY' || transition) return Promise.reject(refusal('HOST_ALREADY_MOUNTED'));
			if (pendingMount) {
				let key;
				try { key = contextIdentityKey(initialContext.bootstrap.identity, initialContext.bootstrap.profileId); }
				catch (_) { return Promise.reject(refusal('HOST_CONTEXT_REFUSED')); }
				return key === mountingKey ? pendingMount : Promise.reject(refusal('HOST_ALREADY_MOUNTED'));
			}
			if (state !== 'IDLE') return Promise.reject(refusal('HOST_SWITCH_BUSY'));
			if (leasedRoots.has(root) && leasedRoots.get(root) !== host) return Promise.reject(refusal('HOST_ALREADY_MOUNTED'));
			leasedRoots.set(root, host);
			try {
				mountingKey = contextIdentityKey(initialContext.bootstrap.identity, initialContext.bootstrap.profileId);
			} catch (_) { return Promise.reject(refusal('HOST_CONTEXT_REFUSED')); }
			state = 'MOUNTING';
			pendingMount = (async () => {
				try {
					const holder = await stage(initialContext);
					try {
						commitStage(holder);
					} catch (error) {
						holder.scope.abort();
						await queue.drain();
						const result = await holder.scope.dispose();
						if (!result.ok) diagnostics(result.code);
						throw error;
					}
					return host;
				} catch (error) {
					state = 'IDLE';
					diagnostics(error.code || 'HOST_CONTEXT_REFUSED');
					throw error;
				} finally {
					pendingMount = null;
					mountingKey = null;
				}
			})();
			return pendingMount;
		},
		switchContext(nextContext, options = {}) {
			if (state !== 'READY' || transition || pendingMount) return Promise.reject(refusal('HOST_SWITCH_BUSY'));
			transition = (async () => {
				const previous = active;
				let staged = null;
				try {
					ensureGate('switch');
					// Dirty state requires an explicit user-facing confirmation.
					const saveStatus = previous.save.status();
					if (saveStatus?.dirty !== false) {
						if (typeof options.confirmDiscard !== 'function' || await options.confirmDiscard() !== true) {
							throw refusal('HOST_CONTEXT_REFUSED');
						}
					}
					ensureGate('switch');
					state = 'SWITCHING';
					staged = await stage(nextContext);
					ensureGate('switch');
					const prior = commitStage(staged);
					// Old resources cannot repaint after epoch invalidation.
					prior.scope.abort();
					await queue.drain();
					const outcome = await prior.scope.dispose();
					if (!outcome.ok) diagnostics(outcome.code);
					return host;
				} catch (error) {
					if (staged && staged !== active) {
						staged.scope.abort();
						await queue.drain();
						const result = await staged.scope.dispose();
						if (!result.ok) diagnostics(result.code);
					}
					if (state !== 'READY' || active === previous) state = 'READY';
					diagnostics(error.code || 'HOST_CONTEXT_REFUSED');
					throw error;
				} finally {
					transition = null;
				}
			})();
			return transition;
		},
		refresh,
		unmount() {
			if (unmountPromise) return unmountPromise;
			if (state === 'IDLE') {
				if (leasedRoots.get(root) === host) leasedRoots.delete(root);
				return Promise.resolve(true);
			}
			if (pendingMount || transition || state !== 'READY') return Promise.reject(refusal('HOST_SWITCH_BUSY'));
			try {
				ensureGate('unmount');
				// The parameterless A1 unmount() never silently discards dirty data.
				if (active.save.status()?.dirty !== false) throw refusal('HOST_CONTEXT_REFUSED');
			} catch (error) { return Promise.reject(error); }
			unmountPromise = (async () => {
				const closing = active;
				state = 'DISPOSING';
				++ticket;
				closing.scope.abort();
				await queue.drain();
				const result = await closing.scope.dispose();
				if (closing.canvas.parentNode === canvasRoot) canvasRoot.removeChild(closing.canvas);
				active = null;
				publishedEditor = null;
				state = 'IDLE';
				if (leasedRoots.get(root) === host) leasedRoots.delete(root);
				if (!result.ok) diagnostics(result.code);
				return result.ok;
			})();
			return unmountPromise.finally(() => { unmountPromise = null; });
		},
	});
	leasedRoots.set(root, host);
	return host;
}
