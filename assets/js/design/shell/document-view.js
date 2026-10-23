/**
 * Canonical Base document-view toolbar state. Flow preview is continuous HTML:
 * this controls only width, never PDF pagination, content or Save authority.
 * Other document consumers can reuse the same mode event and UI primitive.
 */
export const DESIGNER_DOCUMENT_VIEW_MODES = Object.freeze(['fit', 'fixed']);
export const DESIGNER_DOCUMENT_VIEW_EVENT = 'cb:design-shell:documentviewchange';

const controllers = new WeakMap();

export const configureDesignerDocumentView = (root, {mode = 'fit', signal = null} = {}) => {
	if (!(root instanceof Element)) {
		throw new TypeError('Designer document view requires a shell root Element.');
	}
	if (!DESIGNER_DOCUMENT_VIEW_MODES.includes(mode) || signal?.aborted) {
		throw new TypeError('Invalid Designer document view context.');
	}
	const existing = controllers.get(root);
	if (existing) {
		existing.setMode(mode, {emit: false});
		return existing;
	}
	const button = root.querySelector('[data-cb-design-shell-document-view-toggle]');
	if (!button) return null;
	let current = '';
	let destroyed = false;
	const setMode = (next, {emit = true} = {}) => {
		if (destroyed || !DESIGNER_DOCUMENT_VIEW_MODES.includes(next)) return false;
		if (next === current) return false;
		current = next;
		root.dataset.cbDesignShellDocumentView = next;
		const fit = next === 'fit';
		button.setAttribute('aria-pressed', fit ? 'true' : 'false');
		const label = fit ? 'Switch to fixed document width' : 'Fit document to canvas';
		button.setAttribute('aria-label', label);
		button.setAttribute('title', label);
		button.classList.toggle('is-active', fit);
		if (emit) {
			root.dispatchEvent(new CustomEvent(DESIGNER_DOCUMENT_VIEW_EVENT, {
				bubbles: true, detail: Object.freeze({mode: next}),
			}));
		}
		return true;
	};
	const onClick = () => setMode(current === 'fit' ? 'fixed' : 'fit');
	button.addEventListener('click', onClick);
	const destroy = () => {
		if (destroyed) return;
		destroyed = true;
		button.removeEventListener('click', onClick);
		controllers.delete(root);
	};
	signal?.addEventListener('abort', destroy, {once: true});
	const controller = Object.freeze({
		setMode,
		destroy,
		get mode() { return current; },
	});
	controllers.set(root, controller);
	setMode(mode, {emit: false});
	return controller;
};
