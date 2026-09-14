import {
	DESIGNER_ICON_NAMES,
	createDesignerIcon,
	decorateDesignerControl,
} from './icons.js';

const element = (root, selector) => root?.querySelector?.(selector) ?? null;
const elements = (root, selector) => Array.from(root?.querySelectorAll?.(selector) ?? []);
const DEFAULT_GROUP = 'default';
const FULLSCREEN_ROOT_CLASS = 'is-fullscreen';
const FULLSCREEN_DOCUMENT_CLASS = 'cb-core-design-shell-focus-mode';
const FULLSCREEN_EVENT = 'cb:design-shell:fullscreenchange';
const FULLSCREEN_ENTER_CLASS = 'is-entering';
const FULLSCREEN_EXIT_CLASS = 'is-exiting';
const FULLSCREEN_EXIT_MS = 130;
const FOCUSABLE_SELECTOR = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'iframe',
	'[tabindex]:not([tabindex="-1"])',
].join(',');

export const DESIGNER_SIDEBAR_ROLES = Object.freeze(['inspector', 'layers', 'settings']);

const SIDEBAR_ROLE_LABELS = Object.freeze({
	inspector: 'Inspector',
	layers: 'Layers',
	settings: 'Settings',
});

const SIDEBAR_ROLE_ICONS = Object.freeze({
	inspector: 'sliders-horizontal',
	layers: 'layers',
	settings: 'settings-2',
});

let activeFullscreenExit = null;
const shellControllers = new WeakMap();
const pendingSidebarConfigs = new WeakMap();

const normalizeGroupId = (value) => String(value || DEFAULT_GROUP).trim() || DEFAULT_GROUP;

const focusableElements = (root) => elements(root, FOCUSABLE_SELECTOR).filter((candidate) => {
	if (candidate.disabled === true || candidate.hidden === true) return false;
	if (candidate.getAttribute?.('aria-hidden') === 'true') return false;
	if (candidate.closest?.('[hidden]')) return false;
	if (typeof candidate.getClientRects === 'function' && candidate.getClientRects().length === 0) return false;
	return typeof candidate.focus === 'function';
});

const focusTargetIsUsable = (target) => (
	target
	&& typeof target.focus === 'function'
	&& target.isConnected !== false
);

const motionEnabled = () => {
	if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
	return window.matchMedia('(prefers-reduced-motion: reduce)').matches !== true;
};

const scheduleAnimationFrame = (callback) => {
	if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
		return window.requestAnimationFrame(callback);
	}
	callback();
	return null;
};

/**
 * Apply the canonical Designer sidebar roles to a shell root.
 *
 * Consumers map their own panel identifiers onto the shared semantic roles;
 * Base owns order, iconography, labels and keyboard order.
 */
export const configureDesignerSidebar = (root, configuration = {}) => {
	if (!(root instanceof Element)) {
		throw new TypeError('Designer sidebar configuration requires a shell root Element.');
	}
	const controller = shellControllers.get(root);
	if (controller?.configureSidebar) return controller.configureSidebar(configuration);
	pendingSidebarConfigs.set(root, configuration);
	return true;
};

/**
 * Shared Core Blueprint Designer shell.
 *
 * Owns only editor chrome/session presentation: tab groups, history controls,
 * fullscreen/focus-mode UI state and shell-level keyboard delegation. Profiles
 * and consumers keep ownership of canvas semantics, palettes, inspectors,
 * persistence and domain behaviour.
 */
export const createDesignerShell = (root, {
	session = null,
	defaultPanel = null,
	defaultPanels = {},
	onPanelChange = null,
} = {}) => {
	if (!(root instanceof Element)) {
		throw new TypeError('Designer shell requires a root Element.');
	}

	const undo = element(root, '[data-cb-design-shell-undo]');
	const redo = element(root, '[data-cb-design-shell-redo]');
	const fullscreen = element(root, '[data-cb-design-shell-fullscreen]');
	const fullscreenLabel = element(fullscreen, '[data-cb-design-shell-fullscreen-label]');
	const tabs = elements(root, '[data-cb-design-shell-tab]');
	const panels = elements(root, '[data-cb-design-shell-panel]');
	const frames = elements(root, 'iframe');
	const groups = new Map();
	let fullscreenState = false;
	let exitPending = false;
	let exitTimer = null;
	let enterFrame = null;
	let focusReturnTarget = null;
	let temporaryRootTabIndex = null;
	let initialized = false;
	let sidebarDefaultPanel = null;

	const groupIdFor = (node) => normalizeGroupId(node?.dataset?.cbDesignShellGroup);
	const group = (groupId = DEFAULT_GROUP) => {
		const id = normalizeGroupId(groupId);
		if (!groups.has(id)) {
			groups.set(id, {
				id,
				tabs: [],
				panels: [],
				activePanel: null,
			});
		}
		return groups.get(id);
	};

	tabs.forEach((tab) => group(groupIdFor(tab)).tabs.push(tab));
	panels.forEach((panel) => group(groupIdFor(panel)).panels.push(panel));

	const syncHistory = () => {
		if (!session?.history) return;
		if (undo instanceof HTMLButtonElement) undo.disabled = !session.history.canUndo;
		if (redo instanceof HTMLButtonElement) redo.disabled = !session.history.canRedo;
	};

	const scheduleHistorySync = () => {
		if (!session?.history) return;
		if (typeof queueMicrotask === 'function') queueMicrotask(syncHistory);
		else Promise.resolve().then(syncHistory);
	};
	// ProjectState emits during CommandHistory.execute(), before the new history
	// entry is pushed. Defer one microtask so chrome reflects the committed stack.
	session?.projectState?.subscribe?.(scheduleHistorySync);

	const syncFullscreenControl = () => {
		if (!fullscreen) return;
		fullscreen.setAttribute('aria-pressed', fullscreenState ? 'true' : 'false');
		const label = fullscreenState
			? fullscreen.dataset?.cbDesignShellFullscreenExitLabel
			: fullscreen.dataset?.cbDesignShellFullscreenEnterLabel;
		if (!label) return;
		fullscreen.setAttribute('aria-label', label);
		if (fullscreenLabel) fullscreenLabel.textContent = label;
	};

	const dispatchFullscreenChange = () => {
		root.dispatchEvent(new CustomEvent(FULLSCREEN_EVENT, {
			bubbles: true,
			detail: Object.freeze({ fullscreen: fullscreenState }),
		}));
	};

	const focusInside = () => {
		const active = document.activeElement;
		if (active && root.contains(active)) return;
		const candidates = focusableElements(root);
		if (candidates.length) {
			candidates[0].focus();
			return;
		}
		if (!root.hasAttribute('tabindex')) {
			temporaryRootTabIndex = true;
			root.setAttribute('tabindex', '-1');
		}
		root.focus?.();
	};

	const restoreRootTabIndex = () => {
		if (temporaryRootTabIndex !== true) return;
		root.removeAttribute('tabindex');
		temporaryRootTabIndex = null;
	};

	const clearExitTimer = () => {
		if (null === exitTimer) return;
		if (typeof window !== 'undefined' && typeof window.clearTimeout === 'function') window.clearTimeout(exitTimer);
		else clearTimeout(exitTimer);
		exitTimer = null;
	};

	const clearEnterMotion = () => {
		if (null !== enterFrame && typeof window !== 'undefined' && typeof window.cancelAnimationFrame === 'function') {
			window.cancelAnimationFrame(enterFrame);
		}
		enterFrame = null;
		root.classList.remove(FULLSCREEN_ENTER_CLASS);
	};

	const finalizeExit = ({ restoreFocus = true } = {}) => {
		clearExitTimer();
		exitPending = false;
		clearEnterMotion();
		root.classList.remove(FULLSCREEN_EXIT_CLASS, FULLSCREEN_ROOT_CLASS);
		document.documentElement?.classList?.remove(FULLSCREEN_DOCUMENT_CLASS);
		restoreRootTabIndex();
		if (activeFullscreenExit === exitFullscreenInternal) activeFullscreenExit = null;
		dispatchFullscreenChange();

		const returnTarget = focusReturnTarget;
		focusReturnTarget = null;
		if (restoreFocus && focusTargetIsUsable(returnTarget)) returnTarget.focus();
	};

	const exitFullscreenInternal = ({ restoreFocus = true, immediate = false } = {}) => {
		if (exitPending) {
			if (immediate) {
				finalizeExit({ restoreFocus });
				return true;
			}
			return false;
		}
		if (!fullscreenState) return false;

		fullscreenState = false;
		exitPending = true;
		document.removeEventListener('keydown', handleFullscreenKeydown, true);
		document.removeEventListener('focusin', handleFullscreenFocusin, true);
		clearEnterMotion();
		root.classList.add(FULLSCREEN_EXIT_CLASS);
		syncFullscreenControl();

		if (immediate || !motionEnabled()) {
			finalizeExit({ restoreFocus });
			return true;
		}

		const finish = () => finalizeExit({ restoreFocus });
		exitTimer = typeof window !== 'undefined' && typeof window.setTimeout === 'function'
			? window.setTimeout(finish, FULLSCREEN_EXIT_MS)
			: setTimeout(finish, FULLSCREEN_EXIT_MS);
		return true;
	};

	const enterFullscreen = () => {
		if (fullscreenState) return false;
		if (exitPending) exitFullscreenInternal({ restoreFocus: false, immediate: true });
		if (activeFullscreenExit && activeFullscreenExit !== exitFullscreenInternal) {
			activeFullscreenExit({ restoreFocus: false, immediate: true });
		}

		focusReturnTarget = focusTargetIsUsable(document.activeElement) ? document.activeElement : null;
		fullscreenState = true;
		activeFullscreenExit = exitFullscreenInternal;
		root.classList.remove(FULLSCREEN_EXIT_CLASS);
		root.classList.add(FULLSCREEN_ROOT_CLASS, FULLSCREEN_ENTER_CLASS);
		document.documentElement?.classList?.add(FULLSCREEN_DOCUMENT_CLASS);
		document.addEventListener('keydown', handleFullscreenKeydown, true);
		document.addEventListener('focusin', handleFullscreenFocusin, true);
		syncFullscreenControl();
		focusInside();
		dispatchFullscreenChange();

		if (!motionEnabled()) {
			root.classList.remove(FULLSCREEN_ENTER_CLASS);
		} else {
			enterFrame = scheduleAnimationFrame(() => {
				enterFrame = scheduleAnimationFrame(() => {
					enterFrame = null;
					root.classList.remove(FULLSCREEN_ENTER_CLASS);
				});
			});
		}
		return true;
	};

	const exitFullscreen = () => exitFullscreenInternal();
	const toggleFullscreen = () => fullscreenState ? exitFullscreen() : enterFullscreen();
	const isFullscreen = () => fullscreenState;

	function handleFullscreenFocusin() {
		if (!fullscreenState) return;
		const active = document.activeElement;
		if (active && root.contains(active)) return;
		focusInside();
	}

	function handleFullscreenKeydown(event) {
		if (!fullscreenState || event.defaultPrevented) return;
		if (event.key === 'Escape') {
			event.preventDefault();
			exitFullscreen();
			return;
		}
		if (event.key !== 'Tab') return;

		const candidates = focusableElements(root);
		if (!candidates.length) {
			event.preventDefault();
			focusInside();
			return;
		}
		const first = candidates[0];
		const last = candidates.at(-1);
		const active = document.activeElement;
		if (!root.contains(active)) {
			event.preventDefault();
			(event.shiftKey ? last : first).focus();
		} else if (event.shiftKey && active === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && active === last) {
			event.preventDefault();
			first.focus();
		}
	}

	function handleFrameFullscreenKeydown(event) {
		if (!fullscreenState || event.defaultPrevented || event.key !== 'Escape') return;
		event.preventDefault();
		exitFullscreen();
	}

	const bindFrameKeyboard = (frame) => {
		let boundDocument = null;
		const bind = () => {
			let nextDocument = null;
			try {
				nextDocument = frame.contentDocument;
			} catch (error) {
				return;
			}
			if (!nextDocument || nextDocument === boundDocument) return;
			boundDocument?.removeEventListener?.('keydown', handleFrameFullscreenKeydown, true);
			boundDocument = nextDocument;
			boundDocument.addEventListener?.('keydown', handleFrameFullscreenKeydown, true);
		};
		frame.addEventListener?.('load', bind);
		bind();
	};

	const activatePanel = (panelId, { focus = false, group: requestedGroup = DEFAULT_GROUP } = {}) => {
		const id = String(panelId || '').trim();
		if (!id) return false;
		const state = group(requestedGroup);
		const target = state.panels.find((panel) => panel.dataset.cbDesignShellPanel === id);
		if (!target) return false;

		state.activePanel = id;
		state.tabs.forEach((tab) => {
			const active = tab.dataset.cbDesignShellTab === id;
			tab.classList.toggle('is-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
			tab.tabIndex = active ? 0 : -1;
			if (active && focus) tab.focus();
		});
		state.panels.forEach((panel) => {
			panel.hidden = panel !== target;
		});
		if (typeof onPanelChange === 'function') onPanelChange(id, target, state.id);
		return true;
	};

	const applySidebarConfiguration = ({
		roles = {},
		labels = {},
		activeRole = 'inspector',
	} = {}) => {
		const state = group(DEFAULT_GROUP);
		const records = DESIGNER_SIDEBAR_ROLES.map((role) => {
			const panelId = String(roles?.[role] || role).trim();
			const tab = state.tabs.find((candidate) => candidate.dataset.cbDesignShellTab === panelId) ?? null;
			const panel = state.panels.find((candidate) => candidate.dataset.cbDesignShellPanel === panelId) ?? null;
			return { role, panelId, tab, panel };
		}).filter((record) => record.tab && record.panel);
		if (!records.length) return false;

		const roleTabs = records.map((record) => record.tab);
		const rolePanels = records.map((record) => record.panel);
		const remainingTabs = state.tabs.filter((tab) => !roleTabs.includes(tab));
		const remainingPanels = state.panels.filter((panel) => !rolePanels.includes(panel));
		state.tabs.splice(0, state.tabs.length, ...roleTabs, ...remainingTabs);
		state.panels.splice(0, state.panels.length, ...rolePanels, ...remainingPanels);

		const tabParent = roleTabs[0]?.parentElement;
		if (tabParent && roleTabs.every((tab) => tab.parentElement === tabParent)) {
			tabParent.append(...roleTabs);
		}
		const panelParent = rolePanels[0]?.parentElement;
		if (panelParent && rolePanels.every((panel) => panel.parentElement === panelParent)) {
			panelParent.append(...rolePanels);
		}

		records.forEach(({ role, tab, panel }) => {
			const label = String(labels?.[role] || SIDEBAR_ROLE_LABELS[role] || role).trim();
			tab.dataset.cbDesignShellSidebarRole = role;
			panel.dataset.cbDesignShellSidebarRole = role;
			tab.textContent = label;
			decorateDesignerControl(tab, SIDEBAR_ROLE_ICONS[role], { label });
		});

		const active = records.find((record) => record.role === activeRole) ?? records[0];
		sidebarDefaultPanel = active?.panelId || null;
		if (initialized && sidebarDefaultPanel) activatePanel(sidebarDefaultPanel);
		return true;
	};

	const moveTabFocus = (current, direction) => {
		const state = group(groupIdFor(current));
		if (!state.tabs.length) return;
		const index = state.tabs.indexOf(current);
		if (index < 0) return;
		const next = state.tabs[(index + direction + state.tabs.length) % state.tabs.length];
		activatePanel(next.dataset.cbDesignShellTab, { focus: true, group: state.id });
	};

	tabs.forEach((tab) => {
		const state = group(groupIdFor(tab));
		tab.addEventListener('click', () => activatePanel(tab.dataset.cbDesignShellTab, { group: state.id }));
		tab.addEventListener('keydown', (event) => {
			if (event.key === 'ArrowLeft') {
				event.preventDefault();
				moveTabFocus(tab, -1);
			} else if (event.key === 'ArrowRight') {
				event.preventDefault();
				moveTabFocus(tab, 1);
			} else if (event.key === 'Home') {
				event.preventDefault();
				activatePanel(state.tabs[0]?.dataset.cbDesignShellTab, { focus: true, group: state.id });
			} else if (event.key === 'End') {
				event.preventDefault();
				activatePanel(state.tabs.at(-1)?.dataset.cbDesignShellTab, { focus: true, group: state.id });
			}
		});
	});

	undo?.addEventListener('click', () => {
		if (!session?.undo) return;
		session.undo();
		syncHistory();
	});
	redo?.addEventListener('click', () => {
		if (!session?.redo) return;
		session.redo();
		syncHistory();
	});
	fullscreen?.addEventListener('click', toggleFullscreen);
	frames.forEach(bindFrameKeyboard);

	const pendingSidebarConfiguration = pendingSidebarConfigs.get(root);
	if (pendingSidebarConfiguration) {
		applySidebarConfiguration(pendingSidebarConfiguration);
		pendingSidebarConfigs.delete(root);
	}

	groups.forEach((state) => {
		const requested = state.id === DEFAULT_GROUP
			? (sidebarDefaultPanel || defaultPanel)
			: defaultPanels?.[state.id];
		const initialPanel = String(
			requested
			|| state.tabs.find((tab) => tab.classList.contains('is-active'))?.dataset.cbDesignShellTab
			|| state.tabs[0]?.dataset.cbDesignShellTab
			|| ''
		).trim();
		if (initialPanel) activatePanel(initialPanel, { group: state.id });
	});
	initialized = true;
	syncHistory();
	syncFullscreenControl();

	const controller = Object.freeze({
		root,
		activatePanel,
		syncHistory,
		configureSidebar: applySidebarConfiguration,
		isFullscreen,
		enterFullscreen,
		exitFullscreen,
		toggleFullscreen,
		get activePanel() {
			return group(DEFAULT_GROUP).activePanel;
		},
		activePanelFor(groupId) {
			return group(groupId).activePanel;
		},
		panel(id, groupId = DEFAULT_GROUP) {
			return group(groupId).panels.find((candidate) => candidate.dataset.cbDesignShellPanel === String(id || '')) ?? null;
		},
	});
	shellControllers.set(root, controller);
	return controller;
};

export {
	DESIGNER_ICON_NAMES,
	createDesignerIcon,
	decorateDesignerControl,
};
