import {
	DESIGNER_ICON_NAMES,
	createDesignerIcon,
	decorateDesignerControl,
	decorateDesignerElement,
} from './icons.js';
import {
	DESIGNER_LAYER_ACTIONS,
	createDesignerLayerRow,
	createDesignerLayerTree,
} from './layers.js';
import { DESIGNER_PALETTE_ROLES, createDesignerNavigation } from './navigation.js';
import { createDesignerInspectorValueControl } from './inspector-value-control.js';
import { createDesignerSelectionController } from './selection-controller.js';
import {
	createDesignerInspectorControls,
	createDesignerInspectorTextControl,
	createDesignerInspectorValueControl,
	createDesignerInspectorIdentity,
	createDesignerInspectorSection,
	createDesignerInspectorToggle,
	enableDesignerTextareaAutosize,
} from './inspector.js';

const element = (root, selector) => root?.querySelector?.(selector) ?? null;
const elements = (root, selector) => Array.from(root?.querySelectorAll?.(selector) ?? []);
const DEFAULT_GROUP = 'default';
const FULLSCREEN_ROOT_CLASS = 'is-fullscreen';
const FULLSCREEN_DOCUMENT_CLASS = 'cb-core-design-shell-focus-mode';
const FULLSCREEN_EVENT = 'cb:design-shell:fullscreenchange';
const FULLSCREEN_BEFORE_EXIT_EVENT = 'cb:design-shell:beforeexit';
const SHELL_READY_EVENT = 'cb:design-shell:ready';
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

export const DESIGNER_SIDEBAR_ROLES = Object.freeze(['inspector', 'settings']);

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
const shellSessions = new WeakMap();
const pendingSidebarConfigs = new WeakMap();
// Exclusive Host ownership of the EXISTING shell. No second state or renderer.
const platformRootLeases = new WeakMap();

/**
 * Claim the canonical shell root for one statically integrated A2 Host.
 * The Host must finish guarded unmount and shell.destroy() before release.
 */
export const claimDesignerHostRoot = (root) => {
	if (!(root instanceof Element) || !root.matches('[data-cb-design-shell]')) {
		throw new TypeError('Designer Host requires a canonical shell root Element.');
	}
	if (platformRootLeases.has(root) || shellControllers.has(root)) {
		throw new Error('HOST_ALREADY_MOUNTED');
	}
	let released = false;
	const lease = Object.freeze({
		root,
		release() {
			if (released) return true;
			if (platformRootLeases.get(root) !== lease || shellControllers.has(root)) return false;
			platformRootLeases.delete(root);
			released = true;
			return true;
		},
	});
	platformRootLeases.set(root, lease);
	return lease;
};

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
	platformLease = null,
	platformStaged = false,
	platformSignal = null,
	defaultPanel = null,
	defaultPanels = {},
	onPanelChange = null,
} = {}) => {
	if (!(root instanceof Element)) {
		throw new TypeError('Designer shell requires a root Element.');
	}
	// Prevent legacy sessions from racing a leased Host on the same root.
	if ((platformRootLeases.get(root) ?? null) !== platformLease) {
		throw new Error('HOST_ALREADY_MOUNTED');
	}
	// A Host stage is a detached, single-owner canvas. It never reuses the
	// active Base shell root or obtains a competing canonical root lease.
	if (platformStaged && (!root.matches('[data-cb-platform-canvas]')
		|| root.isConnected || platformLease !== null
		|| !platformSignal || platformSignal.aborted !== false)) {
		throw new Error('HOST_CONTEXT_REFUSED');
	}
	const existing = shellControllers.get(root);
	if (existing) {
		if (shellSessions.get(root) !== session) {
			throw new Error('Designer shell root is already initialized with a different session.');
		}
		return existing;
	}

	const lifecycle = new AbortController();
	const { signal } = lifecycle;
	const frameCleanups = [];
	const undo = element(root, '[data-cb-design-shell-undo]');
	const redo = element(root, '[data-cb-design-shell-redo]');
	const fullscreen = element(root, '[data-cb-design-shell-fullscreen]');
	const fullscreenLabel = element(fullscreen, '[data-cb-design-shell-fullscreen-label]');
	const tabs = elements(root, '[data-cb-design-shell-tab]');
	const panels = elements(root, '[data-cb-design-shell-panel]');
	// Preserve the full label for keyboard, assistive technology and hover
	// even when a consumer supplies an overly long translated tab label.
	tabs.forEach((tab) => {
		const label = String(tab.getAttribute('aria-label') || tab.textContent || '').trim();
		if (label) {
			if (!tab.getAttribute('aria-label')) tab.setAttribute('aria-label', label);
			if (!tab.getAttribute('title')) tab.setAttribute('title', label);
		}
	});
	// Left palette tabs are independent of right Inspector/Settings navigation.
	// No Layers role means no Layers tab is introduced by Base.
	tabs.filter((tab) => tab.dataset.cbDesignShellGroup === 'palette').forEach((tab) => {
		const role = String(tab.dataset.cbDesignShellPaletteRole || tab.dataset.cbDesignShellTab || '').trim();
		const iconName = role === 'elements' ? 'shapes' : role === 'layers' ? 'layers' : role === 'navigation' ? 'list-tree' : '';
		if (iconName) decorateDesignerControl(tab, iconName, { label: tab.textContent });
	});
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
	let destroyed = false;
	let platformActivated = false;

	const assertActive = () => {
		if (destroyed) throw new Error('Designer shell has been destroyed.');
	};

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

	// Existing consumer sessions retain v2 subscriptions. A staged Host
	// passes its canonical EditorFacade directly, whose subscribe() cannot
	// run before Host READY; its activation is therefore explicitly deferred.
	const requiredSessionMethods = platformStaged
		? ['historyStatus', 'subscribe', 'undo', 'redo']
		: ['historyStatus', 'subscribeHistory', 'subscribeDiagnostics', 'undo', 'redo'];
	if (session !== null && requiredSessionMethods.some(
		(method) => typeof session?.[method] !== 'function'
	)) {
		throw new TypeError('Designer Shell requires its canonical session interface.');
	}
	const syncHistory = () => {
		if (destroyed || session === null) return;
		const status = session.historyStatus();
		if (undo instanceof HTMLButtonElement) undo.disabled = !status.canUndo;
		if (redo instanceof HTMLButtonElement) redo.disabled = !status.canRedo;
	};

	let diagnosticNode = null;
	const onDiagnostic = (diagnostic) => {
		if (destroyed || diagnostic?.code !== 'CB_DESIGNER_OBSERVER_FAILED') return;
		if (!diagnosticNode && root.ownerDocument?.createElement) {
			diagnosticNode = root.ownerDocument.createElement('div');
			diagnosticNode.setAttribute('role', 'status');
			diagnosticNode.setAttribute('aria-live', 'polite');
			diagnosticNode.dataset.cbDesignShellDiagnostic = '1';
			const labels = typeof window !== 'undefined'
				? (window.cbCoreDesignerLaunch?.diagnosticLabels ?? {})
				: {};
			diagnosticNode.textContent = String(
				root.dataset.cbDesignShellDiagnosticMessage
				|| labels.message
				|| 'The editor view needs refreshing. Your changes are preserved.',
			);
			const retry = root.ownerDocument.createElement('button');
			retry.type = 'button';
			retry.textContent = String(
				root.dataset.cbDesignShellDiagnosticRetry
				|| labels.retry
				|| 'Refresh editor view',
			);
			retry.addEventListener('click', () => {
				let response = null;
				let accepted = false;
				let closed = false;
				const respondWith = (promise) => {
					if (closed || accepted) throw new Error('Designer refresh response is already settled.');
					accepted = true;
					response = Promise.resolve(promise);
				};
				root.dispatchEvent(new CustomEvent('cb:design-shell:refreshrequest', {
					bubbles: true,
					detail: Object.freeze({ respondWith }),
				}));
				closed = true;
				if (response) response.then(() => {
					diagnosticNode?.remove?.();
					diagnosticNode = null;
				}).catch(() => {
					// A failed repaint keeps the diagnostic and retry available.
				});
			}, { signal });
			diagnosticNode.append(retry);
			root.append(diagnosticNode);
		}
		root.dispatchEvent(new CustomEvent('cb:design-shell:diagnostic', {
			bubbles: true,
			detail: diagnostic,
		}));
	};
	// The Host owns project events. Subscribing to EditorFacade during
	// offscreen staging is forbidden by Host's assertReady() gate.
	let unsubscribeHistory = session === null || platformStaged
		? null
		: session.subscribeHistory(syncHistory);
	const unsubscribeDiagnostics = session === null || platformStaged
		? null
		: session.subscribeDiagnostics(onDiagnostic);

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
		// Base launch owns the exit decision. Every user-initiated exit path,
		// including Escape and a detached fullscreen control, must be guardable.
		// Forced internal teardown (immediate) is not an ordinary close intent.
		if (!immediate && !root.dispatchEvent(new CustomEvent(FULLSCREEN_BEFORE_EXIT_EVENT, {
			bubbles: true,
			cancelable: true,
			detail: Object.freeze({ reason: 'close' }),
		}))) return false;

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
		assertActive();
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

	const exitFullscreen = () => {
		assertActive();
		return exitFullscreenInternal();
	};
	const toggleFullscreen = () => {
		assertActive();
		return fullscreenState ? exitFullscreenInternal() : enterFullscreen();
	};
	const isFullscreen = () => fullscreenState;

	function handleFullscreenFocusin() {
		if (!fullscreenState) return;
		const active = document.activeElement;
		// Base Modal Foundation dialogs live at document.body level. They own
		// focus while open; the Designer shell must not pull focus out of one.
		if (document.querySelector?.('dialog[open]')?.contains?.(active)) return;
		if (active && root.contains(active)) return;
		focusInside();
	}

	function handleFullscreenKeydown(event) {
		if (!fullscreenState || event.defaultPrevented) return;
		// Native dialog owns Tab focus trapping and Escape cancellation.
		if (document.querySelector?.('dialog[open]')) return;
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
		if (document.querySelector?.('dialog[open]')) return;
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
		frame.addEventListener?.('load', bind, { signal });
		frameCleanups.push(() => boundDocument?.removeEventListener?.('keydown', handleFrameFullscreenKeydown, true));
		bind();
	};

	const activatePanel = (panelId, { focus = false, group: requestedGroup = DEFAULT_GROUP } = {}) => {
		assertActive();
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
		assertActive();
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
		tab.addEventListener('click', () => activatePanel(tab.dataset.cbDesignShellTab, { group: state.id }), { signal });
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
		}, { signal });
	});

	undo?.addEventListener('click', () => {
		if (!session?.undo) return;
		session.undo();
	}, { signal });
	redo?.addEventListener('click', () => {
		if (!session?.redo) return;
		session.redo();
	}, { signal });
	fullscreen?.addEventListener('click', toggleFullscreen, { signal });
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

	// The authorized integration must call this only AFTER Host committed the
	// offscreen stage, completed preflight and entered READY. Never publish a
	// detached staged shell or subscribe to an unready EditorFacade.
	const activatePlatformSession = () => {
		if (!platformStaged || destroyed || !root.isConnected) return false;
		if (platformActivated) return true;
		unsubscribeHistory = session.subscribe(syncHistory);
		platformActivated = true;
		syncHistory();
		root.dataset.cbDesignShellInitialized = 'true';
		root.dispatchEvent(new CustomEvent(SHELL_READY_EVENT, {
			bubbles: true,
			detail: Object.freeze({ initialized: true }),
		}));
		return true;
	};

	const destroy = () => {
		if (destroyed) return;
		// Ordinary programmatic teardown cannot bypass an unresolved Save or
		// unverified domain authority. Forced page unload is outside browser control.
		// Detached Host canvas cleanup is allowed only after its Host scope
		// was aborted (failed staging or a Save-gated context switch). Any
		// still-attached active Designer retains the original Exit Guard.
		const discardedStage = platformStaged && platformSignal?.aborted === true;
		if (!discardedStage && typeof window !== 'undefined'
			&& window.cbCore?.designerExitGuard?.canTransition?.(root) !== true) {
			// Host must receive cleanup failures rather than silently discard
			// its Save-guarded active root; legacy shell behavior stays unchanged.
			if (platformStaged) throw new Error('HOST_CLEANUP_FAILED');
			return false;
		}
		if (fullscreenState || exitPending) {
			exitFullscreenInternal({ restoreFocus: false, immediate: true });
		}
		clearExitTimer();
		clearEnterMotion();
		lifecycle.abort();
		frameCleanups.splice(0).forEach((cleanup) => cleanup());
		unsubscribeHistory?.();
		unsubscribeDiagnostics?.();
		diagnosticNode?.remove?.();
		pendingSidebarConfigs.delete(root);
		shellControllers.delete(root);
		shellSessions.delete(root);
		delete root.dataset.cbDesignShellInitialized;
		destroyed = true;
	};

	const controller = Object.freeze({
		root,
		activatePanel,
		syncHistory,
		activatePlatformSession,
		configureSidebar: applySidebarConfiguration,
		isFullscreen,
		enterFullscreen,
		exitFullscreen,
		toggleFullscreen,
		destroy,
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
	shellSessions.set(root, session);
	// Offscreen candidates remain unpublished until explicit Host READY.
	if (!platformStaged) {
		root.dataset.cbDesignShellInitialized = 'true';
		root.dispatchEvent(new CustomEvent(SHELL_READY_EVENT, {
			bubbles: true,
			detail: Object.freeze({ initialized: true }),
		}));
	}
	return controller;
};

export {
	DESIGNER_PALETTE_ROLES,
	createDesignerNavigation,
	DESIGNER_ICON_NAMES,
	DESIGNER_LAYER_ACTIONS,
	createDesignerIcon,
	createDesignerInspectorControls,
	createDesignerInspectorTextControl,
	createDesignerInspectorIdentity,
	createDesignerInspectorSection,
	createDesignerInspectorToggle,
	enableDesignerTextareaAutosize,
	createDesignerLayerRow,
	createDesignerLayerTree,
	createDesignerSelectionController,
	decorateDesignerControl,
	decorateDesignerElement,
};
