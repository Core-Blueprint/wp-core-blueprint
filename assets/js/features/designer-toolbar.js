(() => {
	'use strict';

	const config = window.cbCoreDesignerLaunch || {};
	const COMPACT_WIDTH = 800;
	const READY_RETRY_DELAY_MS = 50;
	const READY_RETRY_LIMIT = 200;
	const initialized = new WeakSet();
	let sequence = 0;

	const sharedShellApi = () => window.cbCore?.designEditor?.shell ?? null;
	const labelFor = (control) => String(
		control?.getAttribute?.('aria-label')
		|| control?.getAttribute?.('title')
		|| control?.textContent
		|| ''
	).trim();
	const iconFor = (control) => String(
		control?.dataset?.cbDesignShellIcon
		|| control?.querySelector?.('[data-cb-design-shell-lucide-icon]')?.dataset?.cbDesignShellLucideIcon
		|| ''
	).trim();
	const uniqueControls = (controls) => [...new Set(controls.filter(Boolean))];
	const controlsInCompactGroup = (toolbar, group) => {
		const selector = `[data-cb-design-shell-compact-group="${group}"]`;
		return uniqueControls(Array.from(toolbar.querySelectorAll(selector)).flatMap((node) => {
			if (node instanceof HTMLButtonElement) return [node];
			return Array.from(node.querySelectorAll('button'));
		}));
	};
	const controlsInExtension = (shell, group) => {
		const selector = `[data-cb-design-shell-toolbar-extension="${group}"]`;
		return uniqueControls(Array.from(shell.querySelectorAll(selector)).flatMap((host) => {
			host.hidden = true;
			host.setAttribute('aria-hidden', 'true');
			return Array.from(host.querySelectorAll('button'));
		}));
	};

	const composeCompactToolbar = (shell, shellApi) => {
		if (!(shell instanceof Element) || initialized.has(shell)) return false;
		const toolbar = shell.querySelector('.cb-core-design-shell__toolbar--designer[data-cb-design-shell-header="true"]');
		if (!toolbar) return false;

		const start = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--start');
		const center = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--center');
		const end = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--end');
		const save = toolbar.querySelector('[data-cb-design-shell-primary-action]');
		const closeControl = toolbar.querySelector('[data-cb-design-shell-close], [data-cb-design-shell-fullscreen]');
		const actionAnchor = closeControl || save;
		if (!start || !center || !end) return false;

		initialized.add(shell);
		toolbar.dataset.cbDesignShellCompactToolbar = 'true';

		const viewportControls = Array.from(toolbar.querySelectorAll('[data-cb-design-shell-viewport]'));
		const extensionViewControls = controlsInExtension(shell, 'view');
		const extensionActionControls = controlsInExtension(shell, 'actions')
			.filter((control) => !control.hasAttribute('data-cb-design-shell-primary-action'));
		const viewControls = uniqueControls([
			...viewportControls,
			...controlsInCompactGroup(toolbar, 'view'),
			...extensionViewControls,
		]);
		const actionControls = uniqueControls([
			toolbar.querySelector('[data-cb-design-shell-undo]'),
			toolbar.querySelector('[data-cb-design-shell-redo]'),
			...controlsInCompactGroup(toolbar, 'actions'),
			...extensionActionControls,
		]).filter((control) => !control.hasAttribute('data-cb-design-shell-primary-action'));

		let openRecord = null;
		const menuRecords = [];
		const closeMenu = (record = openRecord, { restoreFocus = false } = {}) => {
			if (!record) return;
			record.panel.hidden = true;
			record.trigger.setAttribute('aria-expanded', 'false');
			if (openRecord === record) openRecord = null;
			if (restoreFocus) record.trigger.focus();
		};
		const closeAll = (options = {}) => {
			menuRecords.forEach((record) => closeMenu(record, options));
		};

		const syncProxy = (record) => {
			const { source, proxy, iconOnly = false } = record;
			const label = labelFor(source) || String(config.toolbarLabels?.action || 'Action').trim();
			const icon = iconFor(source);
			proxy.textContent = label;
			if (icon) shellApi.icons.decorate(proxy, icon, { iconOnly, label });
			else proxy.setAttribute('aria-label', label);
			proxy.disabled = source.disabled === true;
			const pressed = source.getAttribute('aria-pressed');
			if (pressed === 'true' || pressed === 'false') proxy.setAttribute('aria-pressed', pressed);
			else proxy.removeAttribute('aria-pressed');
			proxy.classList.toggle('is-active', source.classList.contains('is-active') || pressed === 'true');
		};

		const bindProxy = (source, proxy, { iconOnly = false, afterActivate = null } = {}) => {
			const record = { source, proxy, iconOnly };
			syncProxy(record);
			new MutationObserver(() => syncProxy(record)).observe(source, {
				attributes: true,
				childList: true,
				characterData: true,
				subtree: true,
				attributeFilter: ['aria-pressed', 'aria-label', 'title', 'class', 'disabled', 'data-cb-design-shell-icon'],
			});
			proxy.addEventListener('click', () => {
				if (proxy.disabled) return;
				source.click();
				window.requestAnimationFrame(() => syncProxy(record));
				if (typeof afterActivate === 'function') afterActivate();
			});
			return proxy;
		};

		const createCompactProxy = (source, menuRecord) => {
			const proxy = document.createElement('button');
			proxy.type = 'button';
			proxy.className = 'cb-core-design-shell__compact-menu-item';
			return bindProxy(source, proxy, {
				afterActivate: () => closeMenu(menuRecord, { restoreFocus: true }),
			});
		};

		const createExtensionGroup = ({ controls, group, mount, before = null }) => {
			if (!controls.length || !mount) return null;
			const wrapper = document.createElement('div');
			wrapper.className = 'cb-core-design-shell__toolbar-group cb-core-design-shell__toolbar-group--extension';
			wrapper.dataset.cbDesignShellCompactGroup = group;
			controls.forEach((source) => {
				const proxy = document.createElement('button');
				proxy.type = 'button';
				proxy.className = 'button cb-core-button cb-core-design-shell__toolbar-extension-action';
				bindProxy(source, proxy, { iconOnly: Boolean(iconFor(source)) });
				wrapper.append(proxy);
			});
			if (before && before.parentElement === mount) mount.insertBefore(wrapper, before);
			else mount.append(wrapper);
			return wrapper;
		};

		createExtensionGroup({ controls: extensionViewControls, group: 'view', mount: center });
		createExtensionGroup({ controls: extensionActionControls, group: 'actions', mount: end, before: actionAnchor });

		const createMenu = ({ name, label, icon, controls, mount, before = null }) => {
			if (!controls.length || !mount) return null;
			const wrapper = document.createElement('div');
			wrapper.className = `cb-core-design-shell__compact-menu cb-core-design-shell__compact-menu--${name}`;
			wrapper.dataset.cbDesignShellCompactMenu = name;

			const trigger = document.createElement('button');
			trigger.type = 'button';
			trigger.className = 'button cb-core-button cb-core-design-shell__compact-menu-trigger';
			trigger.dataset.cbDesignShellCompactMenuTrigger = name;
			trigger.setAttribute('aria-expanded', 'false');
			shellApi.icons.decorate(trigger, icon, { iconOnly: true, label });

			const panel = document.createElement('div');
			panel.className = 'cb-core-design-shell__compact-menu-popover';
			panel.dataset.cbDesignShellCompactMenuPopover = name;
			panel.hidden = true;
			panel.setAttribute('role', 'group');
			panel.setAttribute('aria-label', label);
			sequence += 1;
			panel.id = `cb-core-design-shell-compact-menu-${name}-${sequence}`;
			trigger.setAttribute('aria-controls', panel.id);

			const record = { name, wrapper, trigger, panel, controls };
			controls.forEach((control) => panel.append(createCompactProxy(control, record)));
			trigger.addEventListener('click', () => {
				const opening = panel.hidden;
				closeAll();
				if (!opening) return;
				panel.hidden = false;
				trigger.setAttribute('aria-expanded', 'true');
				openRecord = record;
				panel.querySelector('button:not([disabled])')?.focus();
			});
			wrapper.append(trigger, panel);
			if (before && before.parentElement === mount) mount.insertBefore(wrapper, before);
			else mount.append(wrapper);
			menuRecords.push(record);
			return record;
		};

		const viewLabel = String(config.toolbarLabels?.view || 'View').trim();
		const actionsLabel = String(config.toolbarLabels?.actions || 'Actions').trim();
		const viewMenu = createMenu({ name: 'view', label: viewLabel, icon: 'monitor', controls: viewControls, mount: center });
		createMenu({ name: 'actions', label: actionsLabel, icon: 'ellipsis', controls: actionControls, mount: end, before: actionAnchor });

		const syncViewTrigger = () => {
			if (!viewMenu) return;
			const active = viewportControls.find((control) => control.getAttribute('aria-pressed') === 'true')
				|| viewportControls.find((control) => control.classList.contains('is-active'))
				|| viewportControls[0]
				|| null;
			const icon = iconFor(active) || 'monitor';
			shellApi.icons.decorate(viewMenu.trigger, icon, { iconOnly: true, label: viewLabel });
		};
		viewportControls.forEach((control) => {
			new MutationObserver(syncViewTrigger).observe(control, {
				attributes: true,
				attributeFilter: ['aria-pressed', 'class'],
			});
		});
		syncViewTrigger();

		const applyCompactState = () => {
			const compact = toolbar.getBoundingClientRect().width <= COMPACT_WIDTH;
			toolbar.classList.toggle('is-compact', compact);
			if (!compact) closeAll();
		};
		if (typeof ResizeObserver === 'function') {
			new ResizeObserver(applyCompactState).observe(toolbar);
		} else {
			window.addEventListener('resize', applyCompactState, { passive: true });
		}
		shell.addEventListener('cb:design-shell:fullscreenchange', () => window.requestAnimationFrame(applyCompactState));
		applyCompactState();

		window.addEventListener('keydown', (event) => {
			if (!openRecord || event.defaultPrevented || event.key !== 'Escape') return;
			event.preventDefault();
			event.stopImmediatePropagation();
			closeMenu(openRecord, { restoreFocus: true });
		}, true);
		document.addEventListener('pointerdown', (event) => {
			if (!openRecord || openRecord.wrapper.contains(event.target)) return;
			closeMenu(openRecord);
		}, true);
		document.addEventListener('focusin', (event) => {
			if (!openRecord || openRecord.wrapper.contains(event.target)) return;
			closeMenu(openRecord);
		}, true);
		return true;
	};

	const boot = () => {
		const shellApi = sharedShellApi();
		if (!shellApi?.icons?.decorate) return false;
		let pending = false;
		document.querySelectorAll('[data-cb-design-launch-root] [data-cb-design-shell]').forEach((shell) => {
			if (initialized.has(shell)) return;
			if (!composeCompactToolbar(shell, shellApi)) pending = true;
		});
		return !pending;
	};

	const start = () => {
		let attempts = 0;
		const attempt = () => {
			if (boot()) return;
			attempts += 1;
			if (attempts >= READY_RETRY_LIMIT) return;
			window.setTimeout(attempt, READY_RETRY_DELAY_MS);
		};
		attempt();
	};

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
	else start();
})();