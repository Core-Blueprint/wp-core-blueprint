/**
 * Core Blueprint Admin Theme browser API.
 *
 * CSS tokens remain the primary integration contract. This runtime exists for
 * interfaces that must redraw non-CSS surfaces and for synchronising theme state
 * into Gutenberg's same-origin editor iframe during live HUD changes.
 *
 * Public API:
 *   window.cbAdminTheme.theme()
 *   window.cbAdminTheme.mode()
 *   window.cbAdminTheme.state()
 *
 * Events:
 *   cb:admin-theme-ready
 *   cb:admin-theme-change
 *
 * @package CoreBlueprint\Core
 * @since   1.0.0
 */

(function () {
	'use strict';

	const root = document.documentElement;
	const adapterLinkSelector = [
		'link#cb-core-css-admin-theme-css',
		'link[id^="cb-core-css-admin-theme-"]',
	].join(',');
	const editorFrameSelector = [
		'iframe[name="editor-canvas"]',
		'.block-editor-iframe__container iframe',
	].join(',');

	let lastState = readState();
	let pending = false;
	let frameSyncPending = false;

	function readState() {
		return {
			theme: root.getAttribute('data-cb-theme') || '',
			mode: root.getAttribute('data-cb-mode') || '',
		};
	}

	function state() {
		const current = readState();
		return { theme: current.theme, mode: current.mode };
	}

	function setState(target, nextState) {
		if (!target) {
			return;
		}

		if (nextState.theme) {
			target.setAttribute('data-cb-theme', nextState.theme);
		} else {
			target.removeAttribute('data-cb-theme');
		}

		if (nextState.mode) {
			target.setAttribute('data-cb-mode', nextState.mode);
		} else {
			target.removeAttribute('data-cb-mode');
		}
	}

	function syncAdapterMediaInDocument(doc, mode) {
		const targetMedia = mode === 'dark' ? 'all' : 'not all';
		const links = doc.querySelectorAll(adapterLinkSelector);

		for (const link of links) {
			if (link instanceof HTMLLinkElement || link.tagName === 'LINK') {
				link.media = targetMedia;
			}
		}
	}

	function syncEditorFrame(frame, nextState) {
		try {
			const doc = frame.contentDocument;
			if (!doc || !doc.documentElement) {
				return;
			}

			setState(doc.documentElement, nextState);
			setState(doc.body, nextState);
			syncAdapterMediaInDocument(doc, nextState.mode);
		} catch (error) {
			// Gutenberg's editor iframe is same-origin. Ignore unrelated/cross-origin
			// frames defensively rather than treating them as theme integration bugs.
		}
	}

	function syncEditorFrames(nextState) {
		const frames = document.querySelectorAll(editorFrameSelector);
		for (const frame of frames) {
			if (frame instanceof HTMLIFrameElement) {
				syncEditorFrame(frame, nextState);
			}
		}
	}

	function scheduleFrameSync() {
		if (frameSyncPending) {
			return;
		}

		frameSyncPending = true;
		requestAnimationFrame(function () {
			frameSyncPending = false;
			syncEditorFrames(readState());
		});
	}

	window.cbAdminTheme = Object.freeze({
		theme: function () { return readState().theme; },
		mode: function () { return readState().mode; },
		state: state,
	});

	function dispatch(name, detail) {
		document.dispatchEvent(new CustomEvent(name, { detail: detail }));
	}

	function flushChange() {
		pending = false;
		const nextState = readState();
		if (nextState.theme === lastState.theme && nextState.mode === lastState.mode) {
			return;
		}

		const previous = lastState;
		lastState = nextState;
		syncAdapterMediaInDocument(document, nextState.mode);
		syncEditorFrames(nextState);
		dispatch('cb:admin-theme-change', {
			theme: nextState.theme,
			mode: nextState.mode,
			previousTheme: previous.theme,
			previousMode: previous.mode,
		});
	}

	const themeObserver = new MutationObserver(function (mutations) {
		for (const mutation of mutations) {
			if (mutation.type === 'attributes' && (mutation.attributeName === 'data-cb-theme' || mutation.attributeName === 'data-cb-mode')) {
				if (!pending) {
					pending = true;
					queueMicrotask(flushChange);
				}
				break;
			}
		}
	});

	themeObserver.observe(root, {
		attributes: true,
		attributeFilter: ['data-cb-theme', 'data-cb-mode'],
	});

	if (document.body) {
		const frameObserver = new MutationObserver(function (mutations) {
			for (const mutation of mutations) {
				for (const node of mutation.addedNodes) {
					if (!(node instanceof Element)) {
						continue;
					}

					if ((node instanceof HTMLIFrameElement && node.matches(editorFrameSelector)) || node.querySelector(editorFrameSelector)) {
						scheduleFrameSync();
						return;
					}
				}
			}
		});
		frameObserver.observe(document.body, { childList: true, subtree: true });
	}

	document.addEventListener('load', function (event) {
		const target = event.target;
		if (target instanceof HTMLIFrameElement && target.matches(editorFrameSelector)) {
			syncEditorFrame(target, readState());
		}
	}, true);

	// Align server-rendered media attributes with the browser-resolved mode and
	// mirror the resolved state into Gutenberg's iframe on initial load.
	syncAdapterMediaInDocument(document, lastState.mode);
	syncEditorFrames(lastState);
	dispatch('cb:admin-theme-ready', state());
})();
