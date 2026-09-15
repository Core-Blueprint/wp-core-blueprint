(() => {
	'use strict';

	const config = window.cbCoreDesignerLaunch || {};
	const PALETTE_ROLE_ORDER = Object.freeze(['elements', 'dynamic-data']);
	const observedRoots = new WeakSet();
	const pendingRoots = new WeakSet();

	const canonicalPaletteLabel = (role) => String(
		config.paletteLabels?.[role]
		|| (role === 'dynamic-data' ? 'Dynamic data' : 'Elements')
	).trim();

	const orderedSubset = (parent, nodes) => {
		if (!parent || nodes.length < 2) return;
		const current = Array.from(parent.children).filter((child) => nodes.includes(child));
		const differs = current.length !== nodes.length || nodes.some((node, index) => current[index] !== node);
		if (differs) parent.append(...nodes);
	};

	const normalizeWorkspace = (shell) => {
		const workspace = shell.querySelector('.cb-core-design-shell__workspace');
		if (!workspace) return;

		const palette = workspace.querySelector(':scope > .cb-core-design-shell__palette');
		const canvas = workspace.querySelector(':scope > .cb-core-design-shell__canvas');
		const sidebar = workspace.querySelector(':scope > .cb-core-design-shell__sidebar');
		const canonical = [palette, canvas, sidebar].filter(Boolean);

		palette?.classList.add('cb-core-design-shell__palette--composed');
		canvas?.classList.add('cb-core-design-shell__canvas--composed');
		sidebar?.classList.add('cb-core-design-shell__sidebar--composed');

		if (canonical.length > 1) {
			const current = Array.from(workspace.children).filter((child) => canonical.includes(child));
			const differs = current.length !== canonical.length || canonical.some((node, index) => current[index] !== node);
			if (differs) {
				const anchor = workspace.firstElementChild;
				if (anchor && !canonical.includes(anchor)) {
					canonical.forEach((node) => workspace.insertBefore(node, anchor));
				} else {
					canonical.forEach((node) => workspace.append(node));
				}
			}
		}

		workspace.dataset.cbDesignShellLayout = 'canonical';
	};

	const normalizePalette = (shell) => {
		const palette = shell.querySelector('.cb-core-design-shell__workspace > .cb-core-design-shell__palette');
		if (!palette) return;

		const tablist = palette.querySelector(':scope > .cb-core-design-shell__tabs');
		const panels = Array.from(palette.querySelectorAll(':scope > [data-cb-design-shell-panel]'));
		if (!tablist || !panels.length) return;

		const tabs = Array.from(tablist.querySelectorAll('[data-cb-design-shell-tab]'));
		const records = PALETTE_ROLE_ORDER.map((role) => {
			const panel = panels.find((candidate) => {
				const explicitRole = String(candidate.dataset.cbDesignShellPaletteRole || '').trim();
				const panelId = String(candidate.dataset.cbDesignShellPanel || '').trim();
				return explicitRole === role || panelId === role;
			}) || null;
			if (!panel) return null;
			const panelId = String(panel.dataset.cbDesignShellPanel || '').trim();
			const tab = tabs.find((candidate) => String(candidate.dataset.cbDesignShellTab || '').trim() === panelId) || null;
			return tab ? { role, panelId, panel, tab } : null;
		}).filter(Boolean);

		if (!records.length) return;

		records.forEach(({ role, panel, tab }) => {
			panel.dataset.cbDesignShellPaletteRole = role;
			panel.dataset.cbDesignShellGroup = 'palette';
			tab.dataset.cbDesignShellPaletteRole = role;
			tab.dataset.cbDesignShellGroup = 'palette';
			const label = canonicalPaletteLabel(role);
			if (tab.textContent?.trim() !== label) tab.textContent = label;
		});

		orderedSubset(tablist, records.map(({ tab }) => tab));
		orderedSubset(palette, records.map(({ panel }) => panel));
		palette.classList.add('cb-core-design-shell__palette--tabbed', 'cb-core-design-shell__palette--composed');
		palette.dataset.cbDesignShellRail = 'left';
	};

	const positionContextSelector = (root, shell) => {
		const toolbar = shell.querySelector('.cb-core-design-shell__toolbar--designer');
		const start = toolbar?.querySelector('.cb-core-design-shell__toolbar-zone--start');
		const brand = start?.querySelector('.cb-core-design-shell__brand');
		if (!toolbar || !start || !brand) return;

		const context = root.querySelector('[data-cb-design-shell-context]');
		if (!context) return;

		brand.classList.add('cb-core-design-shell__brand--contextual');
		brand.querySelector('.cb-core-design-shell__brand-wordmark')?.remove();
		context.classList.add('cb-core-design-shell__toolbar-context');
		context.hidden = false;
		context.removeAttribute('aria-hidden');

		if (brand.nextElementSibling !== context || context.parentElement !== start) {
			start.insertBefore(context, brand.nextSibling);
		}
		context.dataset.cbDesignShellContextPosition = 'canonical';
	};

	const normalizeRoot = (root) => {
		const shell = root.querySelector('[data-cb-design-shell]');
		if (!shell) return;
		normalizeWorkspace(shell);
		normalizePalette(shell);
		positionContextSelector(root, shell);
	};

	const scheduleNormalize = (root) => {
		if (pendingRoots.has(root)) return;
		pendingRoots.add(root);
		queueMicrotask(() => {
			pendingRoots.delete(root);
			normalizeRoot(root);
		});
	};

	const observeRoot = (root) => {
		if (!(root instanceof Element) || observedRoots.has(root)) return;
		observedRoots.add(root);
		normalizeRoot(root);

		const observer = new MutationObserver(() => scheduleNormalize(root));
		observer.observe(root, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [
				'data-cb-design-shell-context',
				'data-cb-design-shell-panel',
				'data-cb-design-shell-palette-role',
			],
		});
	};

	const scan = () => {
		document.querySelectorAll('[data-cb-design-launch-root]').forEach(observeRoot);
	};

	const documentObserver = new MutationObserver(scan);
	documentObserver.observe(document.documentElement, { childList: true, subtree: true });

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan, { once: true });
	else scan();
})();
