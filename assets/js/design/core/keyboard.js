const editableTarget = (target) => {
	if (!target || typeof target !== 'object') return false;
	if (target.isContentEditable === true) return true;
	if (typeof target.closest === 'function' && target.closest('[contenteditable]:not([contenteditable="false"])')) return true;
	const tag = String(target.tagName || '').toLowerCase();
	return ['input', 'textarea', 'select'].includes(tag);
};

export const shortcutForEvent = (event) => {
	if (!event || event.defaultPrevented || event.isComposing === true || event.keyCode === 229
		|| editableTarget(event.target) || event.altKey) return null;
	const key = String(event.key || '').toLowerCase();
	if (key === 'process' || key === 'dead') return null;
	const primaryModifier = Boolean(event.metaKey || event.ctrlKey);
	if (primaryModifier && key === 'z') return event.shiftKey ? 'redo' : 'undo';
	if (primaryModifier && key === 'y' && !event.shiftKey) return 'redo';
	if (!primaryModifier && !event.shiftKey && (key === 'delete' || key === 'backspace')) return 'delete';
	return null;
};

export const handleEditorShortcut = (event, { history = null, onDelete = null } = {}) => {
	const action = shortcutForEvent(event);
	if (!action) return false;
	let handled = false;
	if (action === 'undo') handled = Boolean(history?.undo?.());
	else if (action === 'redo') handled = Boolean(history?.redo?.());
	else if (action === 'delete' && typeof onDelete === 'function') handled = onDelete() === true;
	if (handled && typeof event.preventDefault === 'function') event.preventDefault();
	return handled;
};

/**
 * Controlled text commit: input/compositionchange updates local draft; only
 * the genuine post-composition change/blur event can dispatch a command.
 */
export const createDesignerCompositionGuard = (control, onCommit) => {
	if (!control?.addEventListener || !control?.removeEventListener || typeof onCommit !== 'function') {
		throw new TypeError('Composition guard needs a control and commit callback.');
	}
	let composing = false;
	let disposed = false;
	let pending = false;
	const start = () => { composing = true; };
	const end = () => { composing = false; pending = true; };
	const input = () => { pending = true; };
	const commit = (event) => {
		if (disposed || composing || event?.isComposing) return false;
		if (!pending) return false;
		pending = false;
		onCommit(control.value, event);
		return true;
	};
	control.addEventListener('compositionstart', start);
	control.addEventListener('compositionend', end);
	control.addEventListener('input', input);
	control.addEventListener('change', commit);
	control.addEventListener('blur', commit);
	return Object.freeze({
		get isComposing() { return composing; },
		commit,
		destroy() {
			if (disposed) return;
			disposed = true;
			control.removeEventListener('compositionstart', start);
			control.removeEventListener('compositionend', end);
			control.removeEventListener('input', input);
			control.removeEventListener('change', commit);
			control.removeEventListener('blur', commit);
		},
	});
};

/** Focus token is only a hint for the single Host restoreFocus hook. */
export const captureDesignerControlFocus = (root, keyAttribute = 'data-cb-control-key') => {
	const doc = root?.ownerDocument;
	const active = doc?.activeElement;
	if (!active || !root.contains(active) || !active.hasAttribute(keyAttribute)) return null;
	const key = active.getAttribute(keyAttribute);
	if (!key) return null;
	const token = { key, kind: String(active.tagName || '').toLowerCase() };
	if (typeof active.selectionStart === 'number') {
		token.start = active.selectionStart;
		token.end = active.selectionEnd;
	}
	return Object.freeze(token);
};

export const restoreDesignerControlFocus = (root, token, { reason = 'command', composing = false } = {}) => {
	if (!root || !token?.key || composing || reason === 'context') return false;
	const doc = root.ownerDocument;
	const active = doc?.activeElement;
	if (active && root.contains(active) && active.getAttribute('data-cb-control-key') === token.key) return true;
	const target = [...root.querySelectorAll('[data-cb-control-key]')]
		.find((candidate) => candidate.getAttribute('data-cb-control-key') === token.key);
	if (!target || typeof target.focus !== 'function' || target.disabled || target.hidden) return false;
	target.focus({ preventScroll: true });
	if (Number.isInteger(token.start) && Number.isInteger(token.end) && typeof target.setSelectionRange === 'function') {
		try { target.setSelectionRange(token.start, token.end); } catch (_) { /* control without a caret */ }
	}
	return true;
};
