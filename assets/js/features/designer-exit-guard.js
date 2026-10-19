(() => {
	'use strict';

	// Base owns the exit decision and shared Modal. Consumers supply only
	// domain dirty state, a verified Save adapter and localized labels.
	const providers = new WeakMap();
	const registered = new Set();
	const approved = new WeakSet();
	const pending = new WeakSet();
	const defaults = Object.freeze({
		cleanTitle: 'Close Designer?',
		cleanDescription: 'Return to the previous page?',
		unknownDescription: 'Changes may not be saved. Close Designer?',
		dirtyTitle: 'Unsaved changes',
		dirtyDescription: 'Save or discard your changes before closing the Designer.',
		choiceTitle: 'What should happen to your changes?',
		save: 'Save changes',
		discard: 'Discard changes',
		close: 'Close Designer',
		cancel: 'Cancel',
		busy: 'Wait for the current save to finish.',
		unavailable: 'The Designer cannot be closed safely. Try again.',
		saveFailure: 'Your changes could not be verified as saved. The Designer remains open.',
		// PHP Base owns the canonical translation strings and locale resolution.
		// Consumer labels may still override these defaults for domain-specific copy.
		...(window.cbCoreDesignerExitGuardLabels || {}),
	});

	const notify = (variant, message) => {
		window.cbCore?.toast?.[variant]?.(String(message || ''));
	};

	const register = (shell, adapter) => {
		if (!(shell instanceof Element) || !adapter || typeof adapter.isDirty !== 'function') {
			throw new TypeError('Designer Exit Guard requires a shell and isDirty() adapter.');
		}
		if (adapter.save !== undefined && typeof adapter.save !== 'function') {
			throw new TypeError('Designer Exit Guard save must be a function.');
		}
		const record = Object.freeze({
			isDirty: adapter.isDirty,
			save: adapter.save || null,
			labels: Object.freeze({ ...defaults, ...(adapter.labels || {}) }),
		});
		providers.set(shell, record);
		registered.add(shell);
		return () => {
			if (providers.get(shell) !== record) return;
			providers.delete(shell);
			registered.delete(shell);
		};
	};

	window.addEventListener('beforeunload', (event) => {
		for (const shell of registered) {
			const provider = providers.get(shell);
			if (!provider || approved.has(shell)) continue;
			try {
				if (!provider.isDirty()) continue;
			} catch (_error) {
				// Missing dirty authority must never silently discard authoring.
			}
			event.preventDefault();
			event.returnValue = '';
			return;
		}
	});

	const buildDirtyBody = (labels, hasSave) => {
		const body = document.createElement('div');
		const description = document.createElement('p');
		description.textContent = labels.dirtyDescription;
		body.append(description);
		const group = document.createElement('fieldset');
		group.className = 'cb-core-designer-exit-choices';
		const legend = document.createElement('legend');
		legend.textContent = labels.choiceTitle;
		group.append(legend);
		const choices = [];
		for (const [value, caption] of [
			...(hasSave ? [['save', labels.save]] : []),
			['discard', labels.discard],
		]) {
			const label = document.createElement('label');
			label.className = 'cb-core-designer-exit-choice';
			const input = document.createElement('input');
			input.type = 'radio';
			input.name = 'cb-core-designer-exit-action';
			input.value = value;
			input.checked = value === (hasSave ? 'save' : 'discard');
			label.append(input, document.createTextNode(caption));
			group.append(label);
			choices.push(input);
		}
		body.append(group);
		const error = document.createElement('p');
		error.className = 'cb-core-designer-exit-error';
		error.setAttribute('role', 'alert');
		error.hidden = true;
		body.append(error);
		return { body, choices, error };
	};

	const request = async (shell, exit) => {
		if (!(shell instanceof Element) || typeof exit !== 'function' || pending.has(shell)) return false;
		const adapter = providers.get(shell) || null;
		const labels = adapter?.labels || defaults;
		if (shell.hasAttribute('aria-busy') || shell.dataset.cbDesignShellSaveState === 'saving') {
			notify('warning', labels.busy);
			return false;
		}
		const modal = window.cbCore?.modal?.show;
		if (typeof modal !== 'function') {
			notify('error', labels.unavailable);
			return false;
		}
		pending.add(shell);
		try {
			let dirty = null;
			if (adapter) {
				try { dirty = Boolean(adapter.isDirty()); }
				catch (_error) {
					notify('error', labels.unavailable);
					return false;
				}
			}
			let confirmed;
			let action = null;
			if (dirty === true) {
				const { body, choices, error } = buildDirtyBody(labels, Boolean(adapter.save));
				confirmed = await modal({
					title: labels.dirtyTitle,
					body,
					confirmLabel: labels.close,
					cancelLabel: labels.cancel,
					onConfirm: async () => {
						const choice = choices.find((input) => input.checked)?.value;
						if (!choice) return false;
						if (choice === 'save') {
							choices.forEach((input) => { input.disabled = true; });
							try {
								// Success requires a verified persistence receipt AND no newer edits.
								if (await adapter.save() !== true || adapter.isDirty()) {
									throw new Error(labels.saveFailure);
								}
							} catch (failure) {
								error.textContent = failure instanceof Error ? failure.message : labels.saveFailure;
								error.hidden = false;
								return false;
							} finally {
								choices.forEach((input) => { input.disabled = false; });
							}
						}
						// Only a verified Save or an explicit discard may authorize close.
						action = choice;
						return choice === 'save' || choice === 'discard';
					},
				});
			} else {
				confirmed = await modal({
					title: labels.cleanTitle,
					body: dirty === null ? labels.unknownDescription : labels.cleanDescription,
					confirmLabel: labels.close,
					cancelLabel: labels.cancel,
				});
			}
			if (confirmed !== true) return false;
			// A clean confirmation cannot race newer edits into an unsafe exit.
			// If dirty turns true after the modal opened, keep the Designer open.
			if (dirty === false && adapter.isDirty()) return false;
			if (dirty === true && action === 'save' && adapter.isDirty()) return false;
			approved.add(shell);
			if (exit() !== true) {
				approved.delete(shell);
				notify('error', labels.unavailable);
				return false;
			}
			return true;
		} catch (_error) {
			approved.delete(shell);
			notify('error', labels.unavailable);
			return false;
		} finally {
			pending.delete(shell);
		}
	};

	// Escape is intercepted by the Base launch capture listener before
	// the low-level fullscreen engine; an active modal owns its own Escape.
	const modalIsOpen = () => Boolean(document.querySelector('dialog.cb-core-modal[open]'));
	const isApproved = (shell) => approved.has(shell);
	const resetApproval = (shell) => approved.delete(shell);

	window.cbCore = window.cbCore || {};
	window.cbCore.designerExitGuard = Object.freeze({ register, request, modalIsOpen, isApproved, resetApproval });
})();
