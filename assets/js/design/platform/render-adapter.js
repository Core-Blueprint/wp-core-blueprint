/**
 * Strict A1 rc3 §7.1 ABI boundary. A trusted profile module owns its
 * createRenderAdapter(RenderFactoryContext) implementation and canvas subtree.
 * The Host alone owns its render queue, invalidation and focus coordination.
 */
export function instantiateRenderAdapter(createRenderAdapter, factoryContext) {
	if (typeof createRenderAdapter !== 'function'
		|| !factoryContext?.root || !factoryContext?.document || typeof factoryContext?.profileId !== 'string') {
		throw new TypeError('HOST_MODULE_MISSING');
	}
	const instance = createRenderAdapter(Object.freeze({
		root: factoryContext.root,
		document: factoryContext.document,
		profileId: factoryContext.profileId,
	}));
	if (!instance || typeof instance !== 'object' || instance.contractVersion !== 1
		|| ['mount', 'update', 'syncSelection', 'captureFocus', 'restoreFocus', 'destroy']
			.some((name) => typeof instance[name] !== 'function')) {
		throw new TypeError('HOST_CAPABILITY_UNAVAILABLE');
	}
	let destroyed = false;
	let destroyPromise = null;
	return Object.freeze({
		contractVersion: 1,
		mount(args) {
			if (destroyed) throw new TypeError('RENDER_STALE');
			return instance.mount(Object.freeze({...args}));
		},
		update(args) {
			if (destroyed || args?.signal?.aborted) throw new TypeError('RENDER_STALE');
			return instance.update(Object.freeze({...args}));
		},
		syncSelection(args) {
			if (!destroyed) return instance.syncSelection(Object.freeze({...args}));
		},
		captureFocus() {
			return destroyed ? null : instance.captureFocus();
		},
		restoreFocus(token, args) {
			if (!destroyed) return instance.restoreFocus(token, Object.freeze({...args}));
		},
		destroy() {
			if (destroyPromise) return destroyPromise;
			destroyed = true;
			try {
				destroyPromise = Promise.resolve(instance.destroy());
			} catch (error) {
				destroyPromise = Promise.reject(error);
			}
			return destroyPromise;
		},
	});
}
