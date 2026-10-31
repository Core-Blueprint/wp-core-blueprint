(() => {
	'use strict';

	const config = window.cbCoreDesignerLaunch || {};
	const REQUEST_EVENT = 'cb:design-shell:contextrequest';
	const CHANGED_EVENT = 'cb:design-shell:contextchanged';
	const boundControls = new WeakSet();
	const activeRoots = new WeakSet();
	const transitionGenerations = new WeakMap();

	const label = (key, fallback) => String(config.contextLabels?.[key] || fallback).trim();

	const transitionHost = (shell) => (
		shell.querySelector('.cb-core-design-shell__canvas-workarea')
		|| shell.querySelector('.cb-core-design-shell__canvas')
		|| shell
	);

	const ensureTransition = (shell) => {
		const host = transitionHost(shell);
		let transition = host.querySelector(':scope > [data-cb-design-shell-context-transition]');
		if (transition) return transition;

		transition = document.createElement('div');
		transition.className = 'cb-core-design-shell__context-transition';
		transition.dataset.cbDesignShellContextTransition = '';
		transition.setAttribute('role', 'status');
		transition.setAttribute('aria-live', 'polite');
		transition.hidden = true;

		const card = document.createElement('div');
		card.className = 'cb-core-design-shell__context-transition-card';
		const spinner = document.createElement('span');
		spinner.className = 'cb-core-design-shell__context-transition-spinner';
		spinner.setAttribute('aria-hidden', 'true');
		const message = document.createElement('span');
		message.dataset.cbDesignShellContextTransitionMessage = '';
		card.append(spinner, message);
		transition.append(card);
		host.append(transition);
		return transition;
	};

	const setTransition = (shell, state, message) => {
		const transition = ensureTransition(shell);
		const target = transition.querySelector('[data-cb-design-shell-context-transition-message]');
		transition.dataset.state = state;
		if (target) target.textContent = message;
		transition.hidden = false;
		shell.classList.add('is-context-switching');
		transitionHost(shell).setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
		window.requestAnimationFrame(() => transition.classList.add('is-active'));
		return transition;
	};

	const hideTransition = (shell, transition, generation, delay = 140) => {
		window.setTimeout(() => {
			if (transitionGenerations.get(shell) !== generation) return;
			transition.classList.remove('is-active');
			window.setTimeout(() => {
				if (transitionGenerations.get(shell) !== generation) return;
				transition.hidden = true;
				shell.classList.remove('is-context-switching');
				transitionHost(shell).removeAttribute('aria-busy');
			}, 160);
		}, delay);
	};

	// Repaint only: this custom event never triggers another context request.
	const syncPicker = (control) => {
		if (control.dataset.cbCoreSelectPickerReady === '1') {
			control.dispatchEvent(new Event('cb:select-picker-sync'));
		}
	};

	const bindControl = (control) => {
		if (!(control instanceof HTMLSelectElement) || boundControls.has(control)) return;
		boundControls.add(control);
		control.dataset.cbDesignShellContextValue = String(control.value || '');
	};

	const scan = () => {
		document.querySelectorAll('[data-cb-design-shell-context] select').forEach(bindControl);
	};

	// The only context-change capture handler. A consumer never receives the
	// request event until the shared Exit Guard has authorized Save/Discard.
	document.addEventListener('change', (event) => {
		const control = event.target instanceof HTMLSelectElement ? event.target : null;
		if (!control || !control.closest('[data-cb-design-shell-context]')) return;
		const context = control.closest('[data-cb-design-shell-context]');
		const root = context?.closest('[data-cb-design-launch-root]');
		const shell = root?.querySelector('[data-cb-design-shell]');
		if (!root || !shell) return;

		event.preventDefault();
		event.stopPropagation();
		event.stopImmediatePropagation();

		const previousValue = String(control.dataset.cbDesignShellContextValue ?? '');
		const value = String(control.value || '');
		control.value = previousValue; // Never display an unapplied target as current.
		syncPicker(control);
		if (value === previousValue || activeRoots.has(root)) return;

		const guard = window.cbCore?.designerExitGuard;
		if (typeof guard?.requestTransition !== 'function') {
			window.cbCore?.toast?.error?.(label('error', 'Could not load selection.'));
			return;
		}

		activeRoots.add(root); // Synchronous lock, including modal and loading.
		control.disabled = true;
		syncPicker(control); // Closes an open popover before asynchronous authorization.
		let generation = 0;
		let transition = null;
		let wasInert = false;
		let previousBusy = null;
		const apply = () => {
			let responded = false;
			let responsePromise = null;
			const respondWith = (promise) => {
				if (responded) throw new Error('Designer context switch already has a response.');
				responded = true;
				if (!promise || typeof promise.then !== 'function') {
					throw new TypeError('Designer context provider must return a promise.');
				}
				responsePromise = Promise.resolve(promise);
			};
			// Exactly one consumer event inside the Guard's synchronous dispatch
			// window. No synthetic re-dispatch and no second authorization.
			root.dispatchEvent(new CustomEvent(REQUEST_EVENT, {
				bubbles: true,
				cancelable: false,
				detail: { value, previousValue, control, respondWith },
			}));
			if (!responded || !responsePromise) {
				throw new Error('Designer context provider did not accept the request.');
			}
			generation = (transitionGenerations.get(shell) || 0) + 1;
			transitionGenerations.set(shell, generation);
			transition = setTransition(shell, 'loading', label('loading', 'Loading…'));
			wasInert = shell.hasAttribute('inert');
			previousBusy = shell.getAttribute('aria-busy');
			shell.setAttribute('inert', '');
			shell.setAttribute('aria-busy', 'true');
			return responsePromise;
		};

		void guard.requestTransition(shell, apply).then((outcome) => {
			if (outcome?.status === 'applied') {
				control.value = value;
				control.dataset.cbDesignShellContextValue = value;
				syncPicker(control);
				if (transition) {
					setTransition(shell, 'ready', label('ready', 'Ready'));
					hideTransition(shell, transition, generation, 120);
				}
				root.dispatchEvent(new CustomEvent(CHANGED_EVENT, {
					bubbles: true,
					detail: { value, previousValue, control, result: outcome.result },
				}));
			} else {
				control.value = previousValue;
				syncPicker(control);
				if (transition) hideTransition(shell, transition, generation, 0);
				if (outcome?.status === 'blocked' || outcome?.status === 'failed') {
					// Never surface raw consumer errors, URLs, form values or REST payloads.
					window.cbCore?.toast?.error?.(label('error', 'Could not load selection.'));
				}
				// Cancel is intentionally silent. Save errors are shown inside
				// the canonical Modal and by the consumer's existing Save toast.
			}
		}).catch(() => {
			control.value = previousValue;
			syncPicker(control);
			if (transition) hideTransition(shell, transition, generation, 0);
			window.cbCore?.toast?.error?.(label('error', 'Could not load selection.'));
		}).finally(() => {
			if (transition) {
				if (!wasInert) shell.removeAttribute('inert');
				if (previousBusy === null) shell.removeAttribute('aria-busy');
				else shell.setAttribute('aria-busy', previousBusy);
			}
			control.disabled = false;
			syncPicker(control);
			activeRoots.delete(root);
		});
	}, true);

	const observer = new MutationObserver(scan);
	observer.observe(document.documentElement, { childList: true, subtree: true });
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan, { once: true });
	else scan();
})();
