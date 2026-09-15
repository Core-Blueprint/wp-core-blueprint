(() => {
	'use strict';

	const config = window.cbCoreDesignerLaunch || {};
	const REQUEST_EVENT = 'cb:design-shell:contextrequest';
	const CHANGED_EVENT = 'cb:design-shell:contextchanged';
	const boundControls = new WeakSet();
	const activeRoots = new WeakSet();

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

	const hideTransition = (shell, transition, delay = 140) => {
		window.setTimeout(() => {
			transition.classList.remove('is-active');
			window.setTimeout(() => {
				transition.hidden = true;
				shell.classList.remove('is-context-switching');
				transitionHost(shell).removeAttribute('aria-busy');
			}, 160);
		}, delay);
	};

	const bindControl = (control) => {
		if (!(control instanceof HTMLSelectElement) || boundControls.has(control)) return;
		boundControls.add(control);
		control.dataset.cbDesignShellContextValue = String(control.value || '');
	};

	const scan = () => {
		document.querySelectorAll('[data-cb-design-shell-context] select').forEach(bindControl);
	};

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
		if (value === previousValue || activeRoots.has(root)) {
			control.value = previousValue;
			return;
		}

		let responsePromise = null;
		let responded = false;
		const respondWith = (promise) => {
			if (responded) throw new Error('Designer context switch already has a response.');
			responded = true;
			responsePromise = Promise.resolve(promise);
		};

		root.dispatchEvent(new CustomEvent(REQUEST_EVENT, {
			bubbles: true,
			cancelable: false,
			detail: {
				value,
				previousValue,
				control,
				respondWith,
			},
		}));

		if (!responded || !responsePromise) {
			control.value = previousValue;
			return;
		}

		activeRoots.add(root);
		control.disabled = true;
		const transition = setTransition(shell, 'loading', label('loading', 'Loading…'));

		responsePromise.then((result) => {
			control.dataset.cbDesignShellContextValue = value;
			setTransition(shell, 'ready', label('ready', 'Ready'));
			root.dispatchEvent(new CustomEvent(CHANGED_EVENT, {
				bubbles: true,
				detail: { value, previousValue, control, result },
			}));
			hideTransition(shell, transition, 120);
		}).catch((error) => {
			control.value = previousValue;
			setTransition(shell, 'error', error?.message || label('error', 'Could not load selection.'));
			hideTransition(shell, transition, 1100);
		}).finally(() => {
			control.disabled = false;
			activeRoots.delete(root);
		});
	}, true);

	const observer = new MutationObserver(scan);
	observer.observe(document.documentElement, { childList: true, subtree: true });
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan, { once: true });
	else scan();
})();
