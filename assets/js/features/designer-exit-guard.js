(() => {
	'use strict';

	// Base owns the exit decision and shared Modal. Consumers supply only
	// domain dirty state, a verified Save adapter and localized labels.
	const providers = new WeakMap();
	const registered = new Set();
	const observed = new Set();
	const approved = new WeakSet();
	const pending = new WeakSet();
	// Managed v4 coordinators stay outside the public Guard API.
	const managed = new WeakMap();
	const internalUrl = document.currentScript?.src
		? new URL('../design/core/context-v4.js', document.currentScript.src).href : null;
	const defaults = Object.freeze({
		cleanTitle: 'Close Designer?',
		cleanDescription: 'Return to the previous page?',
		unknownDescription: 'Changes may not be saved. Close Designer?',
		dirtyTitle: 'Unsaved changes',
		dirtyDescription: 'Save or discard your changes before closing the Designer.',
		choiceTitle: 'What should happen to your changes?',
		save: 'Save changes',
		discard: 'Discard changes',
		transitionConfirm: 'Switch design',
		transitionDirtyDescription: 'Save or discard your changes before switching designs.',
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

	const observe = (shell) => {
		if (!(shell instanceof Element)) return false;
		observed.add(shell);
		return true;
	};
	const unresolved = (shell, adapter) => {
		if (shell.dataset?.cbDesignShellSaveOutcomeUnknown === 'true'
			|| shell.dataset?.cbDesignShellSaveState === 'unknown') return true;
		if (adapter?.isUncertain) return adapter.isUncertain() !== false;
		return false;
	};
	const isBusy = (shell) => shell.hasAttribute('aria-busy')
		|| shell.dataset.cbDesignShellSaveState === 'saving';
	const canTransition = (shell) => {
		if (!(shell instanceof Element) || pending.has(shell)) return false;
		if (isBusy(shell)) return false;
		const adapter = providers.get(shell);
		if (!adapter) return false;
		try { return !unresolved(shell, adapter) && adapter.isDirty() === false; }
		catch (_error) { return false; }
	};
	const register = (shell, adapter) => {
		if (!(shell instanceof Element) || !adapter || typeof adapter.isDirty !== 'function') {
			throw new TypeError('Designer Exit Guard requires a shell and isDirty() adapter.');
		}
		if (adapter.isUncertain !== undefined && typeof adapter.isUncertain !== 'function') {
			throw new TypeError('Designer Exit Guard isUncertain must be a function.');
		}
		if (adapter.save !== undefined && typeof adapter.save !== 'function') {
			throw new TypeError('Designer Exit Guard save must be a function.');
		}
		if (typeof adapter.discard !== 'function') {
			throw new TypeError('Designer Exit Guard requires a synchronous discard() adapter.');
		}
		// A managed Shell can only have one live registration and authority.
		if (providers.get(shell)?.context) throw new Error('DESIGNER_V4_ALREADY_REGISTERED');
		const context = adapter.context === undefined ? null : adapter.context;
		if (context !== null && (typeof context !== 'object'
			|| context.initial?.kind !== 'domain' || !context.mountSlot
			|| typeof context.prepare !== 'function'
			|| (context.save !== undefined && typeof context.save !== 'function')
			|| (context.cleanup !== undefined && typeof context.cleanup !== 'function'))) {
			throw new TypeError('Designer v4 context requires a domain initial, mountSlot and prepare.');
		}
		const record = Object.freeze({
			isDirty: adapter.isDirty,
			save: adapter.save || null,
			discard: adapter.discard,
			isUncertain: adapter.isUncertain || null,
			labels: Object.freeze({...defaults,...(adapter.labels || {})}),
			context: context === null ? null : Object.freeze({...context}),
		});
		observe(shell);
		providers.set(shell,record);
		registered.add(shell);
		if (record.context) managed.set(record,initializeManaged(shell,record));
		const unregister = () => {
			if (providers.get(shell) !== record) return false;
			if (record.context) {
				// A dirty/UNKNOWN context cannot be silently revoked.
				if (pending.has(shell) || unresolved(shell,record) || isBusy(shell)
					|| record.isDirty() !== false) return false;
			}
			providers.delete(shell);
			registered.delete(shell);
			void managed.get(record)?.then((driver) => driver?.close());
			if (shell.isConnected === false) {
				observed.delete(shell);
				approved.delete(shell);
			}
			return true;
		};
		unregister.ready = record.context
			? managed.get(record).then((driver) => driver !== null) : Promise.resolve(true);
		unregister.current = () => null;
		if (record.context) {
			void managed.get(record).then((driver) => {
				if (driver && providers.get(shell) === record) {
					unregister.current = () => providers.get(shell) === record
						? driver.current() : null;
				}
			});
		}
		return unregister;
	};

	window.addEventListener('beforeunload', (event) => {
		for (const shell of observed) {
			if (shell.isConnected === false) {
				observed.delete(shell);
				registered.delete(shell);
				providers.delete(shell);
				approved.delete(shell);
				continue;
			}
			const provider = providers.get(shell);
			try {
				if (approved.has(shell) && !unresolved(shell, provider)) continue;
				if (provider && !unresolved(shell, provider) && provider.isDirty() === false) continue;
			} catch (_error) {
				// Unavailable dirty/UNKNOWN evidence must fail closed.
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


	/**
	 * One shared Save/Discard/Cancel decision for exit and context changes.
	 * Consumers confirm persistence; Base alone decides whether to proceed.
	 */
	const confirmDirty = async (shell, adapter, labels, confirmLabel, {deferSave = false} = {}) => {
		const modal = window.cbCore?.modal?.show;
		if (typeof modal !== 'function') return { confirmed: false, action: null };
		const { body, choices, error } = buildDirtyBody(labels, Boolean(adapter.save));
		let action = null;
		let confirming = false;
		const confirmed = await modal({
			title: labels.dirtyTitle,
			body,
			confirmLabel,
			cancelLabel: labels.cancel,
			onConfirm: async () => {
				if (confirming || unresolved(shell, adapter) || isBusy(shell)) return false;
				const choice = choices.find((input) => input.checked)?.value;
				if (choice !== 'save' && choice !== 'discard') return false;
				confirming = true;
				choices.forEach((input) => { input.disabled = true; });
				try {
					if (choice === 'save' && !deferSave) {
						// Consumer true means verified persisted receipt, not HTTP 200.
						if (!adapter.save || await adapter.save() !== true
							|| unresolved(shell, adapter) || adapter.isDirty() !== false || isBusy(shell)) {
							throw new Error(labels.saveFailure);
						}
					}
					action = choice;
					return true;
				} catch (failure) {
					error.textContent = failure instanceof Error ? failure.message : labels.saveFailure;
					error.hidden = false;
					return false;
				} finally {
					choices.forEach((input) => { input.disabled = false; });
					confirming = false;
				}
			},
		});
		return { confirmed: confirmed === true, action };
	};

	const request = async (shell, exit) => {
		if (!(shell instanceof Element) || typeof exit !== 'function' || pending.has(shell)) return false;
		const adapter = providers.get(shell) || null;
		const labels = adapter?.labels || defaults;
		// Missing authority or an ambiguous write cannot be authorized by a modal.
		if (!adapter) { notify('error', labels.unavailable); return false; }
		try { if (unresolved(shell, adapter)) { notify('error', labels.saveFailure); return false; } }
		catch (_error) { notify('error', labels.unavailable); return false; }
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
		// The modal lives outside the shell: suppress conflicting UI edits.
		const wasInert = shell.inert === true;
		shell.inert = true;
		try {
			let dirty = null;
			if (adapter) {
				try {
					const observed = adapter.isDirty();
					if (typeof observed !== 'boolean') {
						notify('error', labels.unavailable);
						return false;
					}
					dirty = observed;
				}
				catch (_error) {
					notify('error', labels.unavailable);
					return false;
				}
			}
			let confirmed;
			let action = null;
			if (dirty === true) {
				const choice = await confirmDirty(shell, adapter, labels, labels.close);
				confirmed = choice.confirmed;
				action = choice.action;
			} else {
				confirmed = await modal({
					title: labels.cleanTitle,
					body: dirty === null ? labels.unknownDescription : labels.cleanDescription,
					confirmLabel: labels.close,
					cancelLabel: labels.cancel,
				});
			}
			if (confirmed !== true || unresolved(shell, adapter)) return false;
			// A clean confirmation cannot race newer edits into an unsafe exit.
			// If dirty turns true after the modal opened, keep the Designer open.
			const latestDirty = adapter.isDirty();
			if (typeof latestDirty !== 'boolean') return false;
			if (dirty === false && latestDirty) return false;
			if (dirty === true && action === 'save' && latestDirty) return false;
			if (unresolved(shell, adapter)) return false;
			// Final synchronous Save fence: the modal might have remained
			// pending while another Save began. Clean and Discard are alike.
			if (shell.hasAttribute('aria-busy') || shell.dataset.cbDesignShellSaveState === 'saving') {
				notify('warning', labels.busy);
				return false;
			}
			if (shell.isConnected === false || providers.get(shell) !== adapter) return false;
			// The modal decision may be asynchronous, but restoration is not.
			// No await is permitted between the final Save fence, Discard and exit.
			if (dirty === true && action === 'discard') {
				if (adapter.discard() !== true || unresolved(shell, adapter)
					|| isBusy(shell) || adapter.isDirty() !== false) {
					throw new Error(labels.unavailable);
				}
			}
			// The final exit dispatch is synchronous and must allow the
			// authorized fullscreen control to receive its programmatic click.
			if (!wasInert) shell.inert = false;
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
			if (!wasInert) shell.inert = false;
			pending.delete(shell);
		}
	};



	/**
	 * The Base-only managed factory performs authoritative context preparation,
	 * Save receipts, atomic view rebinding and retirement. No consumer callback
	 * can call commit or receive private lifecycle authority.
	 */
	const initializeManaged = (shell, record) => {
		if (!internalUrl) return Promise.resolve(null);
		return import(internalUrl).then(({createManagedV4Context}) => createManagedV4Context({
			shell,context:record.context,
			isRegistered:() => providers.get(shell) === record && shell.isConnected !== false,
			isSettled:() => {
				try { return !unresolved(shell,record) && !isBusy(shell); }
				catch (_) { return false; }
			},
			isDirty:() => record.isDirty(),
			decide:async () => {
				if (providers.get(shell) !== record) return 'cancel';
				const labels = {
					...record.labels,
					dirtyDescription:record.labels.transitionDirtyDescription,
				};
				const choice = await confirmDirty(shell, {
					...record,
					save:typeof record.context.save === 'function' ? record.context.save : null,
				},labels,labels.transitionConfirm,{deferSave:true});
				return choice.confirmed ? choice.action : 'cancel';
			},
		})).catch(() => null);
	};

	// Public v4 action. The coordinator holds the only transition authority.
	const transition = async (shell, {targetId} = {}) => {
		if (!(shell instanceof Element) || pending.has(shell)) {
			return Object.freeze({status:'blocked'});
		}
		const record = providers.get(shell);
		if (!record?.context || shell.isConnected === false
			|| typeof targetId !== 'string' || !targetId.trim()) {
			return Object.freeze({status:'blocked'});
		}
		try { if (unresolved(shell,record) || isBusy(shell)) return Object.freeze({status:'blocked'}); }
		catch (_) { return Object.freeze({status:'blocked'}); }
		pending.add(shell);
		const wasInert = shell.inert === true;
		shell.inert = true;
		try {
			const driver = await managed.get(record);
			if (!driver || providers.get(shell) !== record || shell.isConnected === false
				|| unresolved(shell,record) || isBusy(shell)) {
				return Object.freeze({status:'blocked'});
			}
			const outcome = await driver.transition(targetId);
			if (outcome?.status === 'committed') {
				return Object.freeze({status:'applied',id:outcome.id,generation:outcome.generation});
			}
			if (outcome?.status === 'cancelled') return Object.freeze({status:'cancelled'});
			if (outcome?.status === 'recovery_required') {
				return Object.freeze({status:'recovery-required',code:'RECOVERY_REQUIRED'});
			}
			if (outcome?.status === 'refused') {
				const codes = ['DESIGNER_TRANSITION_BUSY','DESIGNER_SOURCE_CHANGED',
					'DESIGNER_SAVE_UNSETTLED','DESIGNER_TRANSITION_UNAUTHORIZED',
					'DESIGNER_DIRTY_DECISION_INVALID','DESIGNER_V4_CLOSED'];
				return Object.freeze({
					status:codes.includes(outcome.code) ? 'blocked' : 'failed',code:outcome.code,
				});
			}
			return Object.freeze({status:'failed',code:'DESIGNER_TRANSITION_FAILED'});
		} catch (_) {
			return Object.freeze({status:'failed',code:'DESIGNER_TRANSITION_FAILED'});
		} finally {
			if (!wasInert) shell.inert = false;
			pending.delete(shell);
		}
	};

	// Escape is intercepted by the Base launch capture listener before
	// the low-level fullscreen engine; an active modal owns its own Escape.
	const modalIsOpen = () => Boolean(document.querySelector('dialog.cb-core-modal[open]'));
	const isApproved = (shell) => {
		if (!approved.has(shell)) return false;
		try { return !unresolved(shell, providers.get(shell)); }
		catch (_error) { return false; }
	};
	const resetApproval = (shell) => approved.delete(shell);

	window.cbCore = window.cbCore || {};
	window.cbCore.designerExitGuard = Object.freeze({contractVersion:4,observe,register,request,transition,canTransition,modalIsOpen,isApproved,resetApproval});
})();
