(() => {
	'use strict';

	const config = window.cbCoreDesignerLaunch || {};
	const BOOT_RETRY_DELAY_MS = 50;
	const BOOT_RETRY_LIMIT = 200;
	const DIRECT_ENTER_RETRY_DELAY_MS = 25;
	const DIRECT_ENTER_RETRY_LIMIT = 80;
	const SAVE_EVENT = 'cb:design-shell:savechange';
	const DIRECT_MODE = 'direct';
	const RESPONSIVE_DRAWER_QUERY = '(max-width: 1280px)';

	const sharedShellApi = () => window.cbCore?.designEditor?.shell ?? null;

	const discoverSidebarRoles = (shell) => {
		const roles = {};
		shell.querySelectorAll('[data-cb-design-shell-sidebar-role][data-cb-design-shell-tab]').forEach((tab) => {
			const role = String(tab.dataset.cbDesignShellSidebarRole || '').trim();
			const panelId = String(tab.dataset.cbDesignShellTab || '').trim();
			if (role && panelId) roles[role] = panelId;
		});
		return roles;
	};

	const configureSidebar = (shell, shellApi) => {
		const roles = discoverSidebarRoles(shell);
		const roleNames = Object.keys(roles);
		if (!roleNames.length) return;
		const activeRole = String(config.activeSidebarRole || '').trim()
			|| (roles.inspector ? 'inspector' : roleNames[0]);
		shellApi.configureSidebar(shell, {
			roles,
			labels: config.sidebarLabels || {},
			activeRole,
		});
	};

	const bindSaveState = (shell, save, status) => {
		if (!save && !status) return;
		const saveLabel = String(save?.getAttribute('aria-label') || save?.textContent || 'Save').trim();

		shell.addEventListener(SAVE_EVENT, (event) => {
			const state = String(event.detail?.state || '').trim();
			if (!['saving', 'saved', 'error', 'idle'].includes(state)) return;
			const busy = state === 'saving';
			const message = String(event.detail?.message || '').trim();
			shell.dataset.cbDesignShellSaveState = state;

			if (save) {
				save.disabled = busy;
				if (busy) save.setAttribute('aria-busy', 'true');
				else save.removeAttribute('aria-busy');
			}
			if (status) {
				status.textContent = message || (busy ? `${saveLabel}…` : '');
				status.classList.toggle('is-error', state === 'error');
			}
		});
	};

	const panelToggleLabel = (panel, collapsed) => {
		const verb = String(collapsed ? config.panelLabels?.expand : config.panelLabels?.collapse || '').trim();
		const panelLabel = String(panel?.getAttribute('aria-label') || '').trim();
		return [verb, panelLabel].filter(Boolean).join(' ').trim() || (collapsed ? 'Expand' : 'Collapse');
	};

	const panelHeadingLabel = (panel, side) => String(
		panel?.dataset?.cbDesignShellPanelTitle
		|| panel?.getAttribute?.('aria-label')
		|| (side === 'left' ? 'Panel' : 'Properties')
	).trim();

	const composePanelToggles = (shell, shellApi) => {
		const workspace = shell.querySelector('.cb-core-design-shell__workspace');
		if (!workspace || workspace.dataset.cbDesignShellPanelToggles === 'true') return null;

		const leftPanel = workspace.querySelector(':scope > .cb-core-design-shell__palette');
		const rightPanel = workspace.querySelector(':scope > .cb-core-design-shell__sidebar');
		if (!leftPanel && !rightPanel) return null;

		workspace.dataset.cbDesignShellPanelToggles = 'true';
		workspace.classList.add('cb-core-design-shell__workspace--collapsible');

		const mediaQuery = window.matchMedia(RESPONSIVE_DRAWER_QUERY);
		const records = {};
		let responsiveMode = false;

		const syncRecord = (record) => {
			if (!record) return;
			const collapsed = shell.classList.contains(record.className);
			record.button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			const label = panelToggleLabel(record.panel, collapsed);
			const icon = record.side === 'left'
				? (collapsed ? 'chevron-right' : 'chevron-left')
				: (collapsed ? 'chevron-left' : 'chevron-right');
			shellApi.icons.decorate(record.button, icon, { iconOnly: true, label });

			if (record.launcher) {
				record.launcher.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
				shellApi.icons.decorate(record.launcher, icon, {
					iconOnly: true,
					label,
				});
			}

			if (responsiveMode) {
				record.panel.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
				if (collapsed) record.panel.setAttribute('inert', '');
				else record.panel.removeAttribute('inert');
			} else {
				record.panel.removeAttribute('aria-hidden');
				record.panel.removeAttribute('inert');
			}
		};

		const backdrop = document.createElement('button');
		backdrop.type = 'button';
		backdrop.className = 'cb-core-design-shell__drawer-backdrop';
		backdrop.dataset.cbDesignShellDrawerBackdrop = '';
		backdrop.setAttribute('aria-label', String(config.closeLabel || 'Close').trim());
		backdrop.hidden = true;
		workspace.append(backdrop);

		const syncAll = () => {
			Object.values(records).forEach(syncRecord);
			const anyOpen = responsiveMode && Object.values(records).some(
				(record) => !shell.classList.contains(record.className)
			);
			backdrop.hidden = !anyOpen;
			shell.classList.toggle('has-responsive-drawer-open', anyOpen);
		};

		const focusRecord = (record, target) => {
			const candidate = target === 'launcher' ? record?.launcher : record?.button;
			if (!candidate || typeof candidate.focus !== 'function') return;
			window.requestAnimationFrame(() => candidate.focus());
		};

		const setCollapsed = (record, collapsed, { focusPanel = false, focusLauncher = false } = {}) => {
			if (!record) return false;
			if (responsiveMode && !collapsed) {
				Object.values(records).forEach((candidate) => {
					if (candidate !== record) shell.classList.add(candidate.className);
				});
			}
			shell.classList.toggle(record.className, collapsed);
			syncAll();
			if (!collapsed && focusPanel) focusRecord(record, 'panel');
			if (collapsed && focusLauncher) focusRecord(record, 'launcher');
			return true;
		};

		const closeDrawers = ({ focusLauncher = false } = {}) => {
			const openRecord = Object.values(records).find((record) => !shell.classList.contains(record.className)) ?? null;
			Object.values(records).forEach((record) => shell.classList.add(record.className));
			syncAll();
			if (focusLauncher && openRecord) focusRecord(openRecord, 'launcher');
		};

		const addToggle = (side, panel) => {
			if (!panel) return null;
			const className = side === 'left' ? 'is-left-panel-collapsed' : 'is-right-panel-collapsed';
			const header = document.createElement('div');
			header.className = `cb-core-design-shell__panel-header cb-core-design-shell__panel-header--${side}`;
			header.dataset.cbDesignShellPanelHeader = side;

			const heading = document.createElement('span');
			heading.className = 'cb-core-design-shell__panel-heading';
			heading.dataset.cbDesignShellPanelHeading = '';
			heading.setAttribute('aria-hidden', 'true');
			heading.textContent = panelHeadingLabel(panel, side);

			const button = document.createElement('button');
			button.type = 'button';
			button.className = `cb-core-design-shell__panel-toggle cb-core-design-shell__panel-toggle--${side}`;
			button.dataset.cbDesignShellPanelToggle = side;

			const record = { side, panel, className, button, launcher: null };
			records[side] = record;
			button.addEventListener('click', () => {
				setCollapsed(record, !shell.classList.contains(className), { focusLauncher: responsiveMode });
			});
			header.append(heading, button);
			panel.prepend(header);
			return record;
		};

		const createLauncher = (record) => {
			if (!record) return null;
			const button = document.createElement('button');
			button.type = 'button';
			button.className = `button cb-core-button cb-core-design-shell__drawer-launcher cb-core-design-shell__drawer-launcher--${record.side}`;
			button.dataset.cbDesignShellDrawerToggle = record.side;
			record.launcher = button;
			button.addEventListener('click', () => {
				const collapsed = shell.classList.contains(record.className);
				setCollapsed(record, !collapsed, {
					focusPanel: collapsed,
					focusLauncher: !collapsed,
				});
			});
			return button;
		};

		const left = addToggle('left', leftPanel);
		const right = addToggle('right', rightPanel);
		const leftLauncher = createLauncher(left);
		const rightLauncher = createLauncher(right);

		const syncResponsiveMode = () => {
			responsiveMode = mediaQuery.matches;
			shell.classList.toggle('is-responsive-drawer-mode', responsiveMode);
			if (responsiveMode) {
				Object.values(records).forEach((record) => shell.classList.add(record.className));
			} else {
				Object.values(records).forEach((record) => shell.classList.remove(record.className));
			}
			syncAll();
		};

		backdrop.addEventListener('click', () => closeDrawers({ focusLauncher: true }));
		document.addEventListener('keydown', (event) => {
			if (!responsiveMode || event.defaultPrevented || event.key !== 'Escape') return;
			const anyOpen = Object.values(records).some((record) => !shell.classList.contains(record.className));
			if (!anyOpen) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			closeDrawers({ focusLauncher: true });
		}, true);
		mediaQuery.addEventListener?.('change', syncResponsiveMode);
		syncResponsiveMode();

		return Object.freeze({ leftLauncher, rightLauncher });
	};

	const composeHeader = (root, shell, shellApi, { direct = false, exitUrl = '' } = {}) => {
		const toolbar = shell.querySelector('.cb-core-design-shell__toolbar');
		if (!toolbar || toolbar.dataset.cbDesignShellHeader === 'true') return;

		const historyGroup = toolbar.querySelector('[data-cb-design-shell-undo]')?.closest('.cb-core-design-shell__toolbar-group') ?? null;
		const viewportGroup = toolbar.querySelector('[data-cb-design-shell-viewport]')?.closest('.cb-core-design-shell__toolbar-group') ?? null;
		const fullscreen = toolbar.querySelector('[data-cb-design-shell-fullscreen]');
		const contextSwitcher = toolbar.querySelector('[data-cb-design-shell-context]');
		const save = toolbar.querySelector('[data-cb-design-shell-primary-action]');
		const status = toolbar.querySelector('[data-cb-design-shell-status]');

		if (historyGroup) {
			const heading = historyGroup.querySelector('[data-cb-design-shell-group-label]');
			const historyLabel = String(heading?.textContent || 'History').trim();
			heading?.remove();
			historyGroup.setAttribute('role', 'group');
			historyGroup.setAttribute('aria-label', historyLabel);
			historyGroup.classList.add('cb-core-design-shell__toolbar-group--history');
			const undo = historyGroup.querySelector('[data-cb-design-shell-undo]');
			const redo = historyGroup.querySelector('[data-cb-design-shell-redo]');
			if (undo) {
				const label = String(undo.textContent || 'Undo').trim();
				shellApi.icons.decorate(undo, 'undo-2', { iconOnly: true, label });
			}
			if (redo) {
				const label = String(redo.textContent || 'Redo').trim();
				shellApi.icons.decorate(redo, 'redo-2', { iconOnly: true, label });
			}
		}

		if (viewportGroup) {
			const heading = viewportGroup.querySelector('[data-cb-design-shell-group-label]');
			const viewportLabel = String(heading?.textContent || 'Canvas').trim();
			heading?.remove();
			viewportGroup.setAttribute('aria-label', viewportLabel);
			shellApi.configureViewports(shell);
		}

		if (fullscreen) {
			const enterLabel = String(fullscreen.dataset.cbDesignShellFullscreenEnterLabel || fullscreen.getAttribute('aria-label') || fullscreen.textContent || 'Fullscreen mode').trim();
			const closeLabel = String(fullscreen.dataset.cbDesignShellFullscreenExitLabel || config.closeLabel || 'Close').trim();
			fullscreen.dataset.cbDesignShellFullscreenEnterLabel = enterLabel;
			fullscreen.dataset.cbDesignShellFullscreenExitLabel = closeLabel;
			shellApi.icons.decorate(fullscreen, 'maximize-2', { iconOnly: true, label: enterLabel });
		}
		if (save) {
			const label = String(save.textContent || 'Save').trim();
			shellApi.icons.decorate(save, 'save', { iconOnly: true, label });
		}
		bindSaveState(shell, save, status);
		configureSidebar(shell, shellApi);
		const panelControls = composePanelToggles(shell, shellApi);

		const start = document.createElement('div');
		start.className = 'cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--start';
		const brand = document.createElement('div');
		brand.className = 'cb-core-design-shell__brand';
		const title = String(root.dataset.cbDesignTitle || config.title || 'Designer').trim() || 'Designer';
		brand.setAttribute('aria-label', `${title} — Core Blueprint`);
		const iconUrl = String(config.iconUrl || '').trim();
		if (iconUrl) {
			const markWrap = document.createElement('span');
			markWrap.className = 'cb-core-design-shell__brand-mark';
			const mark = document.createElement('img');
			mark.src = iconUrl;
			mark.alt = '';
			mark.setAttribute('aria-hidden', 'true');
			markWrap.append(mark);
			brand.append(markWrap);
		}
		if (!contextSwitcher) {
			const wordmark = document.createElement('span');
			wordmark.className = 'cb-core-design-shell__brand-wordmark';
			wordmark.textContent = title;
			brand.append(wordmark);
		} else {
			brand.classList.add('cb-core-design-shell__brand--contextual');
			contextSwitcher.classList.add('cb-core-design-shell__toolbar-context');
			contextSwitcher.hidden = false;
			contextSwitcher.removeAttribute('aria-hidden');
		}
		start.append(brand);
		if (contextSwitcher) start.append(contextSwitcher);
		if (panelControls?.leftLauncher) start.append(panelControls.leftLauncher);

		const center = document.createElement('div');
		center.className = 'cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--center';
		if (viewportGroup) center.append(viewportGroup);

		const end = document.createElement('div');
		end.className = 'cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--end';
		if (panelControls?.rightLauncher) end.append(panelControls.rightLauncher);
		if (status) {
			status.classList.add('cb-core-design-shell__toolbar-status');
			end.append(status);
		}
		if (historyGroup) end.append(historyGroup);

		if (direct && fullscreen && exitUrl) {
			const close = document.createElement('button');
			close.type = 'button';
			close.className = 'button cb-core-button cb-core-design-shell__close';
			close.dataset.cbDesignShellClose = '';
			const closeLabel = String(config.closeLabel || 'Close').trim();
			shellApi.icons.decorate(close, 'x', { iconOnly: true, label: closeLabel });
			close.addEventListener('click', () => {
				if (fullscreen.getAttribute('aria-pressed') === 'true') fullscreen.click();
				else window.location.assign(exitUrl);
			});
			end.append(close);
		} else if (fullscreen) {
			end.append(fullscreen);
		}
		if (save) end.append(save);

		toolbar.classList.add('cb-core-design-shell__toolbar--designer');
		toolbar.dataset.cbDesignShellHeader = 'true';
		toolbar.replaceChildren(start, center, end);
	};

	const syncFullscreenIcon = (fullscreen, active, shellApi) => {
		const labelText = active
			? String(fullscreen.dataset.cbDesignShellFullscreenExitLabel || config.closeLabel || 'Close').trim()
			: String(fullscreen.dataset.cbDesignShellFullscreenEnterLabel || fullscreen.getAttribute('aria-label') || 'Fullscreen mode').trim();
		shellApi.icons.decorate(fullscreen, active ? 'x' : 'maximize-2', {
			iconOnly: true,
			label: labelText,
		});
	};

	const directExitUrl = (root) => {
		const raw = String(root.dataset.cbDesignExitUrl || '').trim();
		if (!raw) return '';
		try {
			const url = new URL(raw, window.location.href);
			if (!['http:', 'https:'].includes(url.protocol)) return '';
			if (url.origin !== window.location.origin) return '';
			return url.href;
		} catch (error) {
			return '';
		}
	};

	const initializeDirectLaunch = (root, shell, fullscreen, shellApi, exitUrl) => {
		root.classList.add('is-designer-mode-active');
		shell.hidden = false;

		let attempts = 0;
		let timer = 0;
		let entered = false;

		const stopRetry = () => {
			if (timer) window.clearTimeout(timer);
			timer = 0;
		};

		const attemptEnter = () => {
			if (fullscreen.getAttribute('aria-pressed') === 'true') {
				entered = true;
				stopRetry();
				return;
			}

			fullscreen.click();
			if (fullscreen.getAttribute('aria-pressed') === 'true') {
				entered = true;
				stopRetry();
				return;
			}

			attempts += 1;
			if (attempts >= DIRECT_ENTER_RETRY_LIMIT) return;
			timer = window.setTimeout(attemptEnter, DIRECT_ENTER_RETRY_DELAY_MS);
		};

		shell.addEventListener('cb:design-shell:fullscreenchange', (event) => {
			const active = Boolean(event.detail?.fullscreen);
			if (active) {
				entered = true;
				stopRetry();
				return;
			}
			if (entered) window.location.assign(exitUrl);
		});

		attemptEnter();
	};

	const initializeManualLaunch = (root, shell, fullscreen, context, shellApi) => {
		const wrapper = document.createElement('div');
		wrapper.className = 'cb-core-design-launch-wrap';
		wrapper.dataset.cbDesignLaunch = '';

		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'cb-core-button cb-core-button--primary cb-core-design-launch';
		button.setAttribute('aria-label', String(config.ariaLabel || config.label || 'Design with Core Blueprint'));

		const iconUrl = String(config.iconUrl || '').trim();
		if (iconUrl) {
			const icon = document.createElement('img');
			icon.className = 'cb-core-design-launch__mark';
			icon.src = iconUrl;
			icon.alt = '';
			icon.setAttribute('aria-hidden', 'true');
			button.append(icon);
		}

		const label = document.createElement('span');
		label.className = 'cb-core-design-launch__label';
		label.textContent = String(config.label || 'Design with Core Blueprint');
		button.append(label);

		const setDesignerMode = (active) => {
			root.classList.toggle('is-designer-mode-active', active);
			wrapper.hidden = active;
			shell.hidden = !active;
		};

		button.addEventListener('click', () => {
			setDesignerMode(true);
			if (fullscreen.getAttribute('aria-pressed') !== 'true') fullscreen.click();
		});

		shell.addEventListener('cb:design-shell:fullscreenchange', (event) => {
			const active = Boolean(event.detail?.fullscreen);
			syncFullscreenIcon(fullscreen, active, shellApi);
			setDesignerMode(active);
		});

		wrapper.append(button);
		context.insertAdjacentElement('afterend', wrapper);
		setDesignerMode(false);
	};

	const boot = () => {
		const shellApi = sharedShellApi();
		if (
			!shellApi?.icons?.decorate
			|| typeof shellApi.configureSidebar !== 'function'
			|| typeof shellApi.configureViewports !== 'function'
		) return false;

		document.querySelectorAll('[data-cb-design-launch-root]').forEach((root) => {
			if (root.dataset.cbDesignLaunchInitialized === 'true') return;

			const shell = root.querySelector('[data-cb-design-shell]');
			const fullscreen = shell?.querySelector('[data-cb-design-shell-fullscreen]');
			const context = root.querySelector('[data-cb-design-launch-context]');
			if (!shell || !fullscreen) return;

			const requestedMode = String(root.dataset.cbDesignLaunchMode || '').trim();
			const exitUrl = requestedMode === DIRECT_MODE ? directExitUrl(root) : '';
			const direct = requestedMode === DIRECT_MODE && Boolean(exitUrl);

			if (requestedMode === DIRECT_MODE && !direct) {
				root.removeAttribute('data-cb-design-launch-mode');
				root.removeAttribute('data-cb-design-exit-url');
			}
			if (!direct && (!context || root.querySelector('[data-cb-design-launch]'))) return;

			root.dataset.cbDesignLaunchInitialized = 'true';
			composeHeader(root, shell, shellApi, { direct, exitUrl });

			if (direct) {
				initializeDirectLaunch(root, shell, fullscreen, shellApi, exitUrl);
				return;
			}

			initializeManualLaunch(root, shell, fullscreen, context, shellApi);
		});
		return true;
	};

	const start = () => {
		let attempts = 0;
		let retryTimer = 0;
		let settled = false;

		const stop = () => {
			if (settled) return;
			settled = true;
			if (retryTimer) {
				window.clearTimeout(retryTimer);
				retryTimer = 0;
			}
			window.removeEventListener('cb:design-editor:ready', attemptBoot);
		};

		const attemptBoot = () => {
			if (settled) return;
			if (boot()) {
				stop();
				return;
			}

			attempts += 1;
			if (attempts >= BOOT_RETRY_LIMIT) {
				stop();
				return;
			}

			if (!retryTimer) {
				retryTimer = window.setTimeout(() => {
					retryTimer = 0;
					attemptBoot();
				}, BOOT_RETRY_DELAY_MS);
			}
		};

		window.addEventListener('cb:design-editor:ready', attemptBoot);
		attemptBoot();
	};

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
	else start();
})();