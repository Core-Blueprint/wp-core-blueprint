import {configureDesignerToolbarPopover} from './toolbar-popover.js';

/**
 * History is still exclusively owned by the canonical Designer Shell/session.
 * This component only moves its existing Undo/Redo buttons between toolbar and
 * responsive popover; it neither creates commands nor alters disabled state.
 */
export const configureDesignerHistory = (root, {signal = null} = {}) => {
	if (!(root instanceof Element)) {
		throw new TypeError('Designer history requires a shell root Element.');
	}
	const undo = root.querySelector('[data-cb-design-shell-undo]');
	const redo = root.querySelector('[data-cb-design-shell-redo]');
	if (!undo || !redo) return null;
	const group = undo.closest('.cb-core-design-shell__toolbar-group');
	if (!group || redo.closest('.cb-core-design-shell__toolbar-group') !== group) {
		throw new TypeError('Undo and Redo must share one canonical History group.');
	}
	group.classList.add('cb-core-design-shell__toolbar-group--history');
	return configureDesignerToolbarPopover(root, {
		group,
		controls: [undo, redo],
		kind: 'history',
		icon: 'history',
		label: 'History actions',
		signal,
	});
};
