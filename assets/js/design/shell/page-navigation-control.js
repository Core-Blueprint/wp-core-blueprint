import { decorateDesignerControl } from './icons.js';

/** One Base-owned Pages control for native and external Document Designers. */
export const createDesignerPageNavigationControl = doc => {
	if (!doc || typeof doc.createElement !== 'function')
		throw new TypeError('DESIGNER_PAGE_NAVIGATION_DOCUMENT_INVALID');
	const group = doc.createElement('div');
	group.className = 'cb-core-design-shell__toolbar-group cb-core-design-shell__page-navigation';
	group.setAttribute('data-cb-design-shell-page-navigation-group', '');
	group.setAttribute('role', 'group');
	group.setAttribute('aria-label', 'Document pages');
	const trigger = doc.createElement('button');
	trigger.type = 'button';
	trigger.className = 'button cb-core-button';
	trigger.setAttribute('data-cb-design-shell-page-navigation', '');
	trigger.textContent = 'Pages';
	if (!decorateDesignerControl(trigger, 'file-text', {iconOnly: true, label: 'Page navigation unavailable'}))
		throw new TypeError('DESIGNER_PAGE_NAVIGATION_ICON_UNAVAILABLE');
	trigger.disabled = true;
	group.append(trigger);
	return group;
};
