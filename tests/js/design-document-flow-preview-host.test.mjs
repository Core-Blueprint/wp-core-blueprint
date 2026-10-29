import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

import { createFlowPreviewHost } from '../../assets/js/design/document/flow/preview-host.js';

const canonicalHtml = () => '<!doctype html><html lang="en-GB" data-cb-core-flow-preview-protocol="1" data-cb-core-flow-preview-generation="0"><head><meta charset="utf-8"><meta name="cb-core-flow-preview-protocol" content="1"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; script-src &#39;sha256-test&#39;;"><style>body{margin:0}</style></head><body><div data-cb-flow-preview-root="1">Preview</div><script data-cb-core-flow-preview-sizing="1">void 0;</script></body></html>';

class FakeElement {
	constructor(tagName, ownerDocument) {
		this.tagName = tagName.toUpperCase();
		this.ownerDocument = ownerDocument;
		this.attributes = new Map();
		this.eventListeners = new Map();
		this.parentNode = null;
		this.hidden = false;
		this.textContent = '';
		this.className = '';
		this.style = {};
	}

	setAttribute(name, value) {
		this.attributes.set(name, String(value));
	}

	removeAttribute(name) {
		this.attributes.delete(name);
	}

	getAttribute(name) {
		return this.attributes.has(name) ? this.attributes.get(name) : null;
	}

	addEventListener(type, handler, options = undefined) {
		const entries = this.eventListeners.get(type) || [];
		entries.push({ handler, once: Boolean(options && typeof options === 'object' && options.once) });
		this.eventListeners.set(type, entries);
	}

	removeEventListener(type, handler) {
		const entries = this.eventListeners.get(type) || [];
		this.eventListeners.set(type, entries.filter((entry) => entry.handler !== handler));
	}

	dispatchEvent(event) {
		const entries = [...(this.eventListeners.get(event.type) || [])];
		for (const entry of entries) {
			if (entry.once) {
				this.removeEventListener(event.type, entry.handler);
			}
			entry.handler.call(this, event);
		}
		return true;
	}

	eventListenerCount(type) {
		return (this.eventListeners.get(type) || []).length;
	}
}

const harness = () => {
	const listeners = new Map();
	const timers = new Map();
	const postedMessages = [];
	let timerId = 0;
	const windowRef = {
		addEventListener(type, handler) {
			listeners.set(type, handler);
		},
		removeEventListener(type, handler) {
			if (listeners.get(type) === handler) {
				listeners.delete(type);
			}
		},
		setTimeout(callback) {
			timerId += 1;
			timers.set(timerId, callback);
			return timerId;
		},
		clearTimeout(id) {
			timers.delete(id);
		},
	};
	const documentRef = {
		defaultView: windowRef,
		createElement(tagName) {
			return new FakeElement(tagName, documentRef);
		},
	};
	const iframe = new FakeElement('iframe', documentRef);
	let heightWrites = 0;
	let height = '';
	iframe.style = {
		get height() {
			return height;
		},
		set height(value) {
			heightWrites += 1;
			height = value;
		},
		minHeight: '',
		visibility: '',
	};
	iframe.contentWindow = {
		postMessage(data, targetOrigin) {
			postedMessages.push({ data, targetOrigin });
		},
	};
	iframe.srcdoc = '';
	iframe.setAttribute('scrolling', 'no');
	iframe.setAttribute('height', '900');

	const parent = {
		children: [iframe],
		insertBefore(node, reference) {
			const index = this.children.indexOf(reference);
			this.children.splice(index < 0 ? this.children.length : index, 0, node);
			node.parentNode = this;
		},
		removeChild(node) {
			const index = this.children.indexOf(node);
			if (index >= 0) {
				this.children.splice(index, 1);
			}
			node.parentNode = null;
		},
	};
	iframe.parentNode = parent;

	return {
		iframe,
		parent,
		windowRef,
		heightWrites: () => heightWrites,
		postedMessages: () => postedMessages,
		dispatchLoad() {
			iframe.dispatchEvent({ type: 'load', target: iframe });
		},
		dispatch(source, data) {
			listeners.get('message')?.({ source, data });
		},
		runTimers() {
			const callbacks = [...timers.values()];
			timers.clear();
			for (const callback of callbacks) {
				callback();
			}
		},
		listenerCount: () => listeners.size,
	};
};

const sizeMessage = (generation, height) => ({
	type: 'cb-core-flow-preview-size',
	version: 1,
	generation,
	height,
});

const sizingBridge = () => {
	const source = readFileSync(new URL('../../src/Design/Profile/Document/Flow/HtmlRenderer.php', import.meta.url), 'utf8');
	const match = source.match(/private const PREVIEW_SIZING_BRIDGE = "((?:\\.|[^"\\])*)";/);
	assert.ok(match, 'Expected PREVIEW_SIZING_BRIDGE in HtmlRenderer.php.');
	return JSON.parse(`"${match[1]}"`);
};

test('host owns opaque sandbox and pre-first-size state without fixed-height fallback', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	assert.equal(env.iframe.getAttribute('sandbox'), 'allow-scripts');
	assert.equal(env.iframe.getAttribute('scrolling'), null);
	assert.equal(env.iframe.getAttribute('height'), null);
	assert.equal(env.iframe.style.height, '0px');
	assert.equal(env.iframe.style.minHeight, '0px');
	assert.equal(env.iframe.style.visibility, 'hidden');
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), null);

	assert.equal(host.render(canonicalHtml()), true);
	assert.match(env.iframe.srcdoc, /data-cb-core-flow-preview-generation="1"/);
	assert.equal(env.iframe.style.height, '0px');
	assert.equal(env.iframe.style.visibility, 'hidden');
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'loading');
	assert.equal(env.parent.children[0].textContent, 'Loading preview…');
});

test('iframe load sends exactly one canonical measure request for the active generation', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	assert.equal(host.render(canonicalHtml()), true);
	assert.equal(env.iframe.eventListenerCount('load'), 1);
	assert.equal(env.postedMessages().length, 0);

	env.dispatchLoad();
	assert.equal(env.iframe.eventListenerCount('load'), 0);
	assert.equal(env.postedMessages().length, 2);
	assert.deepEqual(env.postedMessages()[0], {
		data: {
			type: 'cb-core-flow-preview-measure',
			version: 1,
			generation: 1,
		},
		targetOrigin: '*',
	});
	assert.deepEqual(env.postedMessages()[1], {
		data: {
			type: 'cb-core-flow-preview-selection',
			version: 1,
			generation: 1,
			region: null,
		},
		targetOrigin: '*',
	});

	env.dispatchLoad();
	assert.equal(env.postedMessages().length, 2);
});

test('new render replaces pending load handshake and stale generation cannot leak', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	host.render(canonicalHtml());
	assert.equal(env.iframe.eventListenerCount('load'), 1);

	host.render(canonicalHtml());
	assert.equal(env.iframe.eventListenerCount('load'), 1);
	env.dispatchLoad();

	assert.deepEqual(env.postedMessages().map(({ data }) => [data.type, data.generation]), [
		['cb-core-flow-preview-measure', 2],
		['cb-core-flow-preview-selection', 2],
	]);
});


test('first-size timeout callback fires only for an unacknowledged canonical render', () => {
	const env = harness();
	let timeouts = 0;
	assert.throws(
		() => createFlowPreviewHost(env.iframe, { onFirstSizeTimeout: true }),
		/timeout callback/
	);
	const host = createFlowPreviewHost(env.iframe, {
		onFirstSizeTimeout: () => { timeouts += 1; },
	});
	assert.equal(host.render('<html>invalid</html>'), false);
	env.runTimers();
	assert.equal(timeouts, 0, 'Rejected HTML must never trigger recovery.');

	assert.equal(host.render(canonicalHtml()), true);
	env.dispatch(env.iframe.contentWindow, sizeMessage(2, 1000));
	env.runTimers();
	assert.equal(timeouts, 0, 'An acknowledged first size must cancel timeout recovery.');

	assert.equal(host.render(canonicalHtml()), true);
	env.runTimers();
	assert.equal(timeouts, 1, 'Missing size can request one fresh consumer render.');
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'failure');
	env.dispatch(env.iframe.contentWindow, sizeMessage(3, 1100));
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'failure');
	env.runTimers();
	assert.equal(timeouts, 1, 'Failed generation must not fire additional callbacks.');
	host.destroy();
});

test('Reports allows only one preview timeout recovery per Designer opening', () => {
	const reports = readFileSync(new URL('../../assets/js/features/reports-preferences.js', import.meta.url), 'utf8');
	assert.match(reports, /onFirstSizeTimeout: \(\) => \{/);
	assert.match(reports, /if \( previewRecoveryAttempted \|\| ! designerShell\?\.isFullscreen\(\) \) return;/);
	assert.match(reports, /previewRecoveryAttempted = true;\s*schedulePreview\(\);/);
	assert.match(reports, /else previewRecoveryAttempted = false;/);
});

test('semantic selection survives rerender and can be cleared without another navigation', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	host.setSelection('invoice-summary');
	assert.equal(env.postedMessages().length, 0);
	host.render(canonicalHtml());
	env.dispatchLoad();
	assert.equal(env.postedMessages().at(-1).data.region, 'invoice-summary');
	host.render(canonicalHtml());
	env.dispatchLoad();
	assert.equal(env.postedMessages().at(-1).data.generation, 2);
	assert.equal(env.postedMessages().at(-1).data.region, 'invoice-summary');
	const document = env.iframe.srcdoc;
	host.setSelection(null);
	assert.equal(env.postedMessages().at(-1).data.region, null);
	assert.equal(env.iframe.srcdoc, document);
	host.destroy();
	assert.throws(() => host.setSelection('invoice-summary'), /destroyed/);
});

test('canonical first size reveals current preview and later resize is deduplicated', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	host.render(canonicalHtml());
	const beforeFirstSize = env.heightWrites();

	env.dispatch(env.iframe.contentWindow, sizeMessage(1, 1123));
	assert.equal(env.iframe.style.height, '1123px');
	assert.equal(env.iframe.style.visibility, 'visible');
	assert.equal(env.iframe.getAttribute('aria-hidden'), null);
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'ready');
	assert.equal(env.parent.children[0].hidden, true);
	const afterFirstSize = env.heightWrites();
	assert.ok(afterFirstSize > beforeFirstSize);

	env.dispatch(env.iframe.contentWindow, sizeMessage(1, 1123));
	assert.equal(env.heightWrites(), afterFirstSize);

	env.dispatch(env.iframe.contentWindow, sizeMessage(1, 1640));
	assert.equal(env.iframe.style.height, '1640px');
	assert.equal(env.heightWrites(), afterFirstSize + 1);
});

test('wrong source malformed and invalid heights fail closed', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	host.render(canonicalHtml());

	env.dispatch({ other: true }, sizeMessage(1, 1000));
	env.dispatch(env.iframe.contentWindow, null);
	env.dispatch(env.iframe.contentWindow, { ...sizeMessage(1, 1000), extra: true });
	env.dispatch(env.iframe.contentWindow, sizeMessage(1, -1));
	env.dispatch(env.iframe.contentWindow, sizeMessage(1, Number.NaN));
	env.dispatch(env.iframe.contentWindow, sizeMessage(1, 100001));
	assert.equal(env.iframe.style.height, '0px');
	assert.equal(env.iframe.style.visibility, 'hidden');
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'loading');

	env.runTimers();
	assert.equal(env.iframe.style.height, '0px');
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'failure');
	assert.equal(env.parent.children[0].textContent, 'Preview unavailable.');
});

test('stale generation cannot win first-size race across sequential renders', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	host.render(canonicalHtml());
	host.render(canonicalHtml());
	assert.match(env.iframe.srcdoc, /data-cb-core-flow-preview-generation="2"/);

	env.dispatch(env.iframe.contentWindow, sizeMessage(1, 1500));
	assert.equal(env.iframe.style.height, '0px');
	assert.equal(env.iframe.style.visibility, 'hidden');

	env.dispatch(env.iframe.contentWindow, sizeMessage(2, 1300));
	assert.equal(env.iframe.style.height, '1300px');
	assert.equal(env.iframe.style.visibility, 'visible');
});

test('host rejects non-canonical malformed missing and duplicate Flow protocol markers', () => {
	const mutations = [
		'<html><body>arbitrary</body></html>',
		canonicalHtml().replace('<meta name="cb-core-flow-preview-protocol" content="1">', ''),
		canonicalHtml().replace('content="1">', 'content="2">'),
		canonicalHtml().replace('</head>', '<meta name="cb-core-flow-preview-protocol" content="1"></head>'),
		canonicalHtml().replace('data-cb-flow-preview-root="1"', 'data-cb-flow-preview-root="2"'),
	];

	for (const html of mutations) {
		const env = harness();
		const host = createFlowPreviewHost(env.iframe);
		assert.equal(host.render(html), false);
		assert.equal(env.iframe.srcdoc, '');
		assert.equal(env.iframe.style.height, '0px');
		assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'failure');
	}
});

test('late same-generation size after first-size timeout cannot change failed layout', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	host.render(canonicalHtml());
	assert.equal(env.iframe.eventListenerCount('load'), 1);
	env.runTimers();
	assert.equal(env.iframe.eventListenerCount('load'), 0);
	assert.equal(env.iframe.style.height, '0px');
	assert.equal(env.iframe.style.visibility, 'hidden');
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'failure');
	assert.equal(env.parent.children[0].textContent, 'Preview unavailable.');

	env.dispatchLoad();
	assert.equal(env.postedMessages().length, 0);

	const writesAfterFailure = env.heightWrites();
	env.dispatch(env.iframe.contentWindow, sizeMessage(1, 1200));
	assert.equal(env.heightWrites(), writesAfterFailure);
	assert.equal(env.iframe.style.height, '0px');
	assert.equal(env.iframe.style.visibility, 'hidden');
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'failure');
	assert.equal(env.parent.children[0].textContent, 'Preview unavailable.');
});

test('new render after failure gets a new generation and can become ready', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	host.render(canonicalHtml());
	env.runTimers();
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'failure');

	assert.equal(host.render(canonicalHtml()), true);
	assert.match(env.iframe.srcdoc, /data-cb-core-flow-preview-generation="2"/);
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'loading');
	assert.equal(env.iframe.style.height, '0px');
	assert.equal(env.iframe.style.visibility, 'hidden');

	env.dispatch(env.iframe.contentWindow, sizeMessage(2, 1250));
	assert.equal(env.iframe.style.height, '1250px');
	assert.equal(env.iframe.style.visibility, 'visible');
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'ready');
});

test('child measure request is source version and generation bound and reports directly', () => {
	const bridge = sizingBridge();
	let messageHandler = null;
	let height = 0;
	const sent = [];
	const parentWindow = {
		postMessage(data, targetOrigin) {
			sent.push({ data, targetOrigin });
		},
	};
	const root = {
		get scrollHeight() {
			return height;
		},
		get offsetHeight() {
			return height;
		},
		getBoundingClientRect() {
			return { height };
		},
		getAttribute(name) {
			return name === 'data-cb-flow-preview-root' ? '1' : null;
		},
		addEventListener(type, handler) {
			assert.equal(type, 'click');
			assert.equal(typeof handler, 'function');
		},
	};
	const windowObject = {
		parent: parentWindow,
		addEventListener(type, handler) {
			if (type === 'message') {
				messageHandler = handler;
			}
		},
	};
	const documentObject = {
		querySelectorAll(selector) {
			if (selector === 'meta[name="cb-core-flow-preview-protocol"]') {
				return [{ content: '1' }];
			}
			if (selector === '[data-cb-flow-preview-root]') {
				return [root];
			}
			return [];
		},
		documentElement: {
			getAttribute(name) {
				return name === 'data-cb-core-flow-preview-generation' ? '1' : null;
			},
		},
	};
	class ResizeObserverStub {
		observe() {}
	}

	runInNewContext(bridge, {
		window: windowObject,
		document: documentObject,
		ResizeObserver: ResizeObserverStub,
		requestAnimationFrame: () => 1,
		Number,
		Math,
	});

	assert.equal(typeof messageHandler, 'function');
	assert.equal(sent.length, 0, 'Initial zero-height self-report must remain fail closed.');

	const valid = { type: 'cb-core-flow-preview-measure', version: 1, generation: 1 };
	messageHandler({ source: {}, data: valid });
	messageHandler({ source: parentWindow, data: { ...valid, type: 'wrong' } });
	messageHandler({ source: parentWindow, data: { ...valid, version: 2 } });
	messageHandler({ source: parentWindow, data: { ...valid, generation: 2 } });
	messageHandler({ source: parentWindow, data: { ...valid, extra: true } });
	assert.equal(sent.length, 0);

	height = 1200;
	messageHandler({ source: parentWindow, data: valid });
	assert.equal(sent.length, 1);
	assert.equal(sent[0].targetOrigin, '*');
	assert.equal(sent[0].data.type, 'cb-core-flow-preview-size');
	assert.equal(sent[0].data.version, 1);
	assert.equal(sent[0].data.generation, 1);
	assert.equal(sent[0].data.height, 1200);
});

test('destroy removes listener pending load handshake and invalidates the preview lifecycle', () => {
	const env = harness();
	const host = createFlowPreviewHost(env.iframe);
	host.render(canonicalHtml());
	assert.equal(env.listenerCount(), 1);
	assert.equal(env.iframe.eventListenerCount('load'), 1);
	host.destroy();
	assert.equal(env.listenerCount(), 0);
	assert.equal(env.iframe.eventListenerCount('load'), 0);
	assert.equal(env.iframe.srcdoc, '');
	assert.equal(env.iframe.style.height, '0px');
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'destroyed');
	assert.equal(env.parent.children.length, 1);
	env.dispatchLoad();
	assert.equal(env.postedMessages().length, 0);
	env.dispatch(env.iframe.contentWindow, sizeMessage(1, 1400));
	assert.equal(env.iframe.style.height, '0px');
	assert.throws(() => host.render(canonicalHtml()), /destroyed/);
});

test('parent host never reads iframe DOM and never grants same-origin', () => {
	const source = readFileSync(new URL('../../assets/js/design/document/flow/preview-host.js', import.meta.url), 'utf8');
	assert.doesNotMatch(source, /contentDocument/);
	assert.doesNotMatch(source, /contentWindow\.document/);
	assert.doesNotMatch(source, /scrollHeight/);
	assert.doesNotMatch(source, /allow-same-origin/);
});


test('selected preview regions keep empty elements visibly selectable and restore prior inline styles', () => {
	const bridge = sizingBridge();
	let messageHandler = null;

	class HTMLElementStub {
		constructor(region) {
			this.region = region;
			this.attributes = new Map();
			this.style = {
				outline: '',
				outlineOffset: '',
				minHeight: '0px',
			};
		}

		getAttribute(name) {
			if (name === 'data-cb-flow-preview-region') return this.region;
			return this.attributes.get(name) ?? null;
		}

		setAttribute(name, value) {
			this.attributes.set(name, String(value));
		}

		removeAttribute(name) {
			this.attributes.delete(name);
		}
	}

	const target = new HTMLElementStub('empty-paragraph');
	const parentWindow = { postMessage() {} };
	const root = {
		scrollHeight: 100,
		offsetHeight: 100,
		getBoundingClientRect: () => ({ height: 100 }),
		getAttribute: (name) => name === 'data-cb-flow-preview-root' ? '1' : null,
		querySelectorAll: (selector) => selector === '[data-cb-flow-preview-region]' ? [target] : [],
		addEventListener(type, handler) {
			assert.equal(type, 'click');
			assert.equal(typeof handler, 'function');
		},
	};
	const windowObject = {
		parent: parentWindow,
		addEventListener(type, handler) {
			if (type === 'message') messageHandler = handler;
		},
	};
	const documentObject = {
		querySelectorAll(selector) {
			if (selector === 'meta[name="cb-core-flow-preview-protocol"]') return [{ content: '1' }];
			if (selector === '[data-cb-flow-preview-root]') return [root];
			return [];
		},
		documentElement: {
			getAttribute: (name) => name === 'data-cb-core-flow-preview-generation' ? '1' : null,
		},
	};
	class ResizeObserverStub {
		observe() {}
	}

	runInNewContext(bridge, {
		window: windowObject,
		document: documentObject,
		HTMLElement: HTMLElementStub,
		ResizeObserver: ResizeObserverStub,
		requestAnimationFrame: () => 1,
		Number,
		Math,
	});

	const selection = {
		type: 'cb-core-flow-preview-selection',
		version: 1,
		generation: 1,
		region: 'empty-paragraph',
	};
	messageHandler({ source: parentWindow, data: selection });
	assert.equal(target.style.outline, '2px solid #00a8e8');
	assert.equal(target.style.outlineOffset, '3px');
	assert.equal(target.style.minHeight, '1em');
	assert.equal(target.getAttribute('data-cb-flow-preview-selected'), '1');

	messageHandler({ source: parentWindow, data: { ...selection, region: null } });
	assert.equal(target.style.outline, '');
	assert.equal(target.style.outlineOffset, '');
	assert.equal(target.style.minHeight, '0px');
	assert.equal(target.getAttribute('data-cb-flow-preview-selected'), null);
});


test('opaque preview activations are source, generation, shape and lifecycle bound', () => {
	const env = harness();
	const activated = [];
	assert.throws(() => createFlowPreviewHost(env.iframe, { onActivate: 1 }), /callback/);
	const host = createFlowPreviewHost(env.iframe, { onActivate: (region) => activated.push(region) });
	const activation = (generation, region) => ({
		type: 'cb-core-flow-preview-activate', version: 1, generation, region,
	});
	host.render(canonicalHtml());
	env.dispatch(env.iframe.contentWindow, activation(1, 'header'));
	assert.deepEqual(activated, [], 'Do not accept interaction before the preview is visible');
	env.dispatch(env.iframe.contentWindow, sizeMessage(1, 850));
	assert.equal(env.iframe.getAttribute('data-cb-core-flow-preview-state'), 'ready');

	env.dispatch({}, activation(1, 'header'));
	env.dispatch(env.iframe.contentWindow, activation(0, 'header'));
	env.dispatch(env.iframe.contentWindow, activation(2, 'header'));
	env.dispatch(env.iframe.contentWindow, activation(1, '../untrusted'));
	env.dispatch(env.iframe.contentWindow, activation(1, null));
	env.dispatch(env.iframe.contentWindow, { ...activation(1, 'header'), unexpected: true });
	env.dispatch(env.iframe.contentWindow, { ...activation(1, 'header'), version: 2 });
	assert.deepEqual(activated, []);

	env.dispatch(env.iframe.contentWindow, activation(1, 'kpis'));
	assert.deepEqual(activated, ['kpis']);
	host.render(canonicalHtml());
	env.dispatch(env.iframe.contentWindow, activation(1, 'header'));
	assert.deepEqual(activated, ['kpis']);
	env.dispatch(env.iframe.contentWindow, sizeMessage(2, 860));
	env.dispatch(env.iframe.contentWindow, activation(2, 'footer'));
	assert.deepEqual(activated, ['kpis', 'footer']);
	host.destroy();
	env.dispatch(env.iframe.contentWindow, activation(2, 'header'));
	assert.deepEqual(activated, ['kpis', 'footer']);
});

test('CSP preview bridge emits only clicked semantic regions inside its own root', () => {
	const bridge = sizingBridge();
	const listeners = new Map();
	const sent = [];
	const parentWindow = { postMessage(data, targetOrigin) { sent.push({ data, targetOrigin }); } };
	const root = {
		scrollHeight: 100,
		offsetHeight: 100,
		getBoundingClientRect: () => ({ height: 100 }),
		getAttribute: (name) => name === 'data-cb-flow-preview-root' ? '1' : null,
		querySelectorAll: () => [],
		addEventListener(type, fn) { listeners.set(type, fn); },
		contains(element) { return element.inside === true; },
	};
	const documentObject = {
		querySelectorAll(selector) {
			if (selector === 'meta[name="cb-core-flow-preview-protocol"]') return [{ content: '1' }];
			if (selector === '[data-cb-flow-preview-root]') return [root];
			return [];
		},
		documentElement: {
			getAttribute(name) { return name === 'data-cb-core-flow-preview-generation' ? '3' : null; },
		},
	};
	const windowObject = { parent: parentWindow, addEventListener() {} };
	class ResizeObserverStub { observe() {} }
	runInNewContext(bridge, {
		window: windowObject,
		document: documentObject,
		ResizeObserver: ResizeObserverStub,
		requestAnimationFrame: () => 1,
		Number,
		Math,
	});
	assert.equal(typeof listeners.get('click'), 'function');
	const click = listeners.get('click');
	const marker = { inside: true, getAttribute: () => 'current_state' };
	let prevented = 0;
	const send = (item) => click({
		target: { closest: () => item },
		preventDefault() { prevented++; },
	});
	send(marker);
	assert.equal(JSON.stringify(sent.at(-1)), JSON.stringify({
		data: { type: 'cb-core-flow-preview-activate', version: 1, generation: 3, region: 'current_state' },
		targetOrigin: '*',
	}));
	assert.equal(prevented, 1);
	const previous = sent.length;
	send({ inside: false, getAttribute: () => 'header' });
	send({ inside: true, getAttribute: () => '../evil' });
	send(null);
	assert.equal(sent.length, previous);
	assert.equal(prevented, 1);
});


test('Reports consumer preserves failure state when canonical Flow host refuses untrusted preview HTML', () => {
	const reportsSource = readFileSync(
		new URL('../../assets/js/features/reports-preferences.js', import.meta.url), 'utf8'
	);
	const start = reportsSource.indexOf('if ( host.render( response.data.html ) !== true )');
	const success = reportsSource.indexOf("setPreviewState( '', 'success' );", start);
	assert.ok(start >= 0, 'Reports must check the Base host acceptance result');
	assert.ok(success > start, 'Reports success state must follow verified host acceptance');
	const guard = reportsSource.slice(start, success);
	assert.match(guard, /setPreviewState\(\s*i18n\.previewFailed[^;]+,\s*'error'\s*\);/);
	assert.match(guard, /\breturn;/);
});


test('isolated Flow geometry messages are generation-bound, bounded, and never authorize mutations', () => {
	const env = harness();
	const delivered = [];
	const host = createFlowPreviewHost(env.iframe, {
		onRegions: regions => delivered.push(regions),
	});
	assert.equal(host.render(canonicalHtml()), true);
	const message = (generation, regions) => ({
		type: 'cb-core-flow-preview-regions', version: 1, generation, regions,
	});
	const valid = [{id: 'r1', top: 120, height: 40}];
	env.dispatch({}, message(1, valid));
	env.dispatch(env.iframe.contentWindow, message(0, valid));
	env.dispatch(env.iframe.contentWindow, message(2, valid));
	env.dispatch(env.iframe.contentWindow, message(1, [{id: 'r1', top: NaN, height: 40}]));
	env.dispatch(env.iframe.contentWindow, message(1, [{id: 'r1', top: 120, height: -1}]));
	env.dispatch(env.iframe.contentWindow, message(1, [valid[0], valid[0]]));
	env.dispatch(env.iframe.contentWindow, {...message(1, valid), unexpected: true});
	assert.deepEqual(delivered.map(regions=>regions.length), [0]);
	env.dispatch(env.iframe.contentWindow, message(1, valid));
	assert.deepEqual(delivered.map(regions=>regions.length), [0,1]);
	assert.equal(Object.isFrozen(delivered[1]), true);
	assert.equal(Object.isFrozen(delivered[1][0]), true);
	assert.deepEqual(delivered[1][0], valid[0]);
	host.render(canonicalHtml());
	env.dispatch(env.iframe.contentWindow, message(1, valid));
	assert.deepEqual(delivered.map(regions=>regions.length), [0,1,0]);
	env.dispatch(env.iframe.contentWindow, message(2, valid));
	assert.deepEqual(delivered.map(regions=>regions.length), [0,1,0,1]);
	host.destroy();
	assert.deepEqual(delivered.at(-1), []);
	env.dispatch(env.iframe.contentWindow, message(2, valid));
	assert.deepEqual(delivered.at(-1), []);
});


test('isolated Flow bridge publishes only bounded semantic geometry and preserves size messages', () => {
	const bridge = sizingBridge();
	const sent = [];
	class RegionElement {
		constructor(id, box) { this.id = id; this.box = box; }
		getAttribute(name) { return name === 'data-cb-flow-preview-region' ? this.id : null; }
		getBoundingClientRect() { return this.box; }
	}
	const region = new RegionElement('r1', {
		top: 64, height: 48, bottom: 112, left: 24, width: 340, right: 364,
	});
	const nested = new RegionElement('r1.0', {
		top: 72, height: 24, bottom: 96, left: 40, width: 308, right: 348,
	});
	const invalid = new RegionElement('r1.1', {
		top: 96, height: 24, bottom: 120, left: 40, width: Number.NaN, right: 348,
	});
	const root = {
		scrollHeight: 480, offsetHeight: 480,
		getBoundingClientRect: () => ({height: 480}),
		getAttribute: name => name === 'data-cb-flow-preview-root' ? '1' : null,
		querySelectorAll: selector => selector === '[data-cb-flow-preview-region]'
			? [region, nested, invalid] : [],
		addEventListener() {},
	};
	const documentObject = {
		querySelectorAll(selector) {
			if (selector === 'meta[name="cb-core-flow-preview-protocol"]') return [{content: '1'}];
			if (selector === '[data-cb-flow-preview-root]') return [root];
			return [];
		},
		documentElement: {
			getAttribute(name) { return name === 'data-cb-core-flow-preview-generation' ? '7' : null; },
		},
	};
	const windowObject = {
		parent: {postMessage(data) { sent.push(data); }},
		addEventListener() {},
	};
	class ResizeObserverStub {observe() {}}
	runInNewContext(bridge, {
		window: windowObject, document: documentObject,
		ResizeObserver: ResizeObserverStub, HTMLElement: RegionElement,
		requestAnimationFrame: () => 1, Number, Math,
	});
	assert.deepEqual(JSON.parse(JSON.stringify(sent)), [
		{type: 'cb-core-flow-preview-regions', version: 1, generation: 7,
			regions: [
				{id: 'r1', top: 64, height: 48, left: 24, width: 340},
				{id: 'r1.0', top: 72, height: 24, left: 40, width: 308},
			]},
		{type: 'cb-core-flow-preview-size', version: 1, generation: 7, height: 480},
	]);
});
