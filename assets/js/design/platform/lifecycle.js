/** One Host-owned queue; callers do not get independent render schedulers. */
export function createRenderQueue() {
	let tail = Promise.resolve();
	let closed = false;
	return Object.freeze({
		push(task) {
			if (closed) return Promise.reject(Object.assign(new Error('Render queue closed'), {code: 'RENDER_STALE'}));
			const result = tail.then(task);
			tail = result.catch(() => {});
			return result;
		},
		drain() { return tail; },
		close() { closed = true; },
	});
}

/** Reverse-order resource cleanup, even when one disposer rejects. */
export function createLifecycleScope() {
	const controller = new AbortController();
	const disposers = [];
	let disposed = false;
	let pending = null;
	return Object.freeze({
		signal: controller.signal,
		get disposed() { return disposed; },
		own(disposer) {
			if (typeof disposer !== 'function' || disposed) throw new TypeError('HOST_CONTEXT_REFUSED');
			disposers.push(disposer);
			return disposer;
		},
		abort() { controller.abort(); },
		dispose() {
			if (pending) return pending;
			disposed = true;
			controller.abort();
			pending = (async () => {
				const failures = [];
				while (disposers.length) {
					try { await disposers.pop()(); } catch (_) { failures.push('HOST_CLEANUP_FAILED'); }
				}
				return Object.freeze({ok: !failures.length, code: failures.length ? 'HOST_CLEANUP_FAILED' : null});
			})();
			return pending;
		},
	});
}
