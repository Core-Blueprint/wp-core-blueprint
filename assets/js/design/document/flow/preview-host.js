const PROTOCOL_VERSION = 1;
const MIN_HEIGHT = 1;
const MAX_HEIGHT = 100000;
const SELECTION_ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/;
const FIRST_SIZE_TIMEOUT_MS = 5000;
const MEASURE_MESSAGE_TYPE = 'cb-core-flow-preview-measure';
const SELECTION_MESSAGE_TYPE = 'cb-core-flow-preview-selection';
const PROTOCOL_META = '<meta name="cb-core-flow-preview-protocol" content="1">';
const DOCUMENT_PROTOCOL_MARKER = 'data-cb-core-flow-preview-protocol="1"';
const GENERATION_MARKER = 'data-cb-core-flow-preview-generation="0"';
const ROOT_MARKER = 'data-cb-flow-preview-root="1"';
const BRIDGE_MARKER = 'data-cb-core-flow-preview-sizing="1"';

const countMatches = (value, pattern) => (value.match(pattern) || []).length;

const isCanonicalFlowPreviewHtml = (html) => {
	if (typeof html !== 'string' || html.length === 0 || !html.startsWith('<!doctype html><html ')) {
		return false;
	}

	if (countMatches(html, /<meta\s+[^>]*name=["']cb-core-flow-preview-protocol["'][^>]*>/gi) !== 1 || !html.includes(PROTOCOL_META)) {
		return false;
	}
	if (countMatches(html, /data-cb-core-flow-preview-protocol\s*=/gi) !== 1 || !html.includes(DOCUMENT_PROTOCOL_MARKER)) {
		return false;
	}
	if (countMatches(html, /data-cb-core-flow-preview-generation\s*=/gi) !== 1 || !html.includes(GENERATION_MARKER)) {
		return false;
	}
	if (countMatches(html, /data-cb-flow-preview-root\s*=/gi) !== 1 || !html.includes(ROOT_MARKER)) {
		return false;
	}
	if (countMatches(html, /data-cb-core-flow-preview-sizing\s*=/gi) !== 1 || !html.includes(BRIDGE_MARKER)) {
		return false;
	}
	if (countMatches(html, /<meta\s+[^>]*http-equiv=["']Content-Security-Policy["'][^>]*>/gi) !== 1) {
		return false;
	}
	if (!html.includes('script-src &#39;sha256-')) {
		return false;
	}

	return html.endsWith('</body></html>');
};

const isSizingMessage = (data) => {
	if (data === null || typeof data !== 'object' || Array.isArray(data)) {
		return false;
	}

	const keys = Object.keys(data).sort();
	if (keys.length !== 4 || keys.join('|') !== 'generation|height|type|version') {
		return false;
	}

	return data.type === 'cb-core-flow-preview-size'
		&& data.version === PROTOCOL_VERSION
		&& Number.isSafeInteger(data.generation)
		&& data.generation > 0
		&& Number.isSafeInteger(data.height)
		&& data.height >= MIN_HEIGHT
		&& data.height <= MAX_HEIGHT;
};

const normalizeSelectionId = (value) => {
	if (value === null) return null;
	if (typeof value !== 'string' || !SELECTION_ID_PATTERN.test(value)) {
		throw new TypeError('Flow preview selection requires a bounded semantic region id or null.');
	}
	return value;
};

const nextGeneration = (current) => current >= Number.MAX_SAFE_INTEGER ? 1 : current + 1;

export const createFlowPreviewHost = (iframe) => {
	if (!iframe || String(iframe.tagName || '').toUpperCase() !== 'IFRAME') {
		throw new TypeError('Flow preview host requires an iframe element.');
	}

	const documentRef = iframe.ownerDocument;
	const windowRef = documentRef?.defaultView;
	const parentNode = iframe.parentNode;
	if (!documentRef || !windowRef || !parentNode || typeof windowRef.addEventListener !== 'function') {
		throw new TypeError('Flow preview host requires an attached iframe with a parent window.');
	}

	const state = documentRef.createElement('div');
	state.className = 'cb-core-design-shell__empty-state cb-core-design-shell__flow-preview-state';
	state.setAttribute('data-cb-core-flow-preview-state', 'loading');
	state.setAttribute('role', 'status');
	state.setAttribute('aria-live', 'polite');
	state.textContent = 'Loading preview…';
	parentNode.insertBefore(state, iframe);

	iframe.setAttribute('sandbox', 'allow-scripts');
	iframe.removeAttribute('scrolling');
	iframe.removeAttribute('height');
	iframe.setAttribute('data-cb-core-flow-preview-host', '1');
	iframe.style.height = '0px';
	iframe.style.minHeight = '0px';
	iframe.style.visibility = 'hidden';
	iframe.setAttribute('aria-hidden', 'true');

	let generation = 0;
	let activeGeneration = 0;
	let lastAppliedHeight = null;
	let selectedRegionId = null;
	let awaitingFirstSize = false;
	let firstSizeTimer = null;
	let pendingLoadHandler = null;
	let lifecycle = 'idle';
	let destroyed = false;

	const clearFirstSizeTimer = () => {
		if (firstSizeTimer !== null) {
			windowRef.clearTimeout(firstSizeTimer);
			firstSizeTimer = null;
		}
	};

	const clearPendingLoadHandler = () => {
		if (pendingLoadHandler !== null) {
			iframe.removeEventListener('load', pendingLoadHandler);
			pendingLoadHandler = null;
		}
	};

	const setState = (kind) => {
		lifecycle = kind;
		iframe.setAttribute('data-cb-core-flow-preview-state', kind);
		state.setAttribute('data-cb-core-flow-preview-state', kind);

		if (kind === 'ready') {
			state.hidden = true;
			iframe.style.visibility = 'visible';
			iframe.removeAttribute('aria-hidden');
			return;
		}

		iframe.style.height = '0px';
		iframe.style.minHeight = '0px';
		iframe.style.visibility = 'hidden';
		iframe.setAttribute('aria-hidden', 'true');
		state.hidden = false;
		state.textContent = kind === 'failure' ? 'Preview unavailable.' : 'Loading preview…';
	};

	const failCurrentRender = () => {
		awaitingFirstSize = false;
		lastAppliedHeight = null;
		clearFirstSizeTimer();
		clearPendingLoadHandler();
		setState('failure');
	};

	const postSelection = (messageGeneration = activeGeneration) => {
		if (destroyed || messageGeneration < 1) return;
		const childWindow = iframe.contentWindow;
		if (!childWindow || typeof childWindow.postMessage !== 'function') return;
		childWindow.postMessage({
			type: SELECTION_MESSAGE_TYPE,
			version: PROTOCOL_VERSION,
			generation: messageGeneration,
			region: selectedRegionId,
		}, '*');
	};

	const onMessage = (event) => {
		if (
			destroyed
			|| (lifecycle !== 'loading' && lifecycle !== 'ready')
			|| event.source !== iframe.contentWindow
			|| !isSizingMessage(event.data)
		) {
			return;
		}

		const { generation: messageGeneration, height } = event.data;
		if (messageGeneration !== activeGeneration) {
			return;
		}
		if (lastAppliedHeight === height) {
			return;
		}

		iframe.style.height = `${height}px`;
		lastAppliedHeight = height;

		if (awaitingFirstSize) {
			awaitingFirstSize = false;
			clearFirstSizeTimer();
			setState('ready');
		}
	};

	windowRef.addEventListener('message', onMessage);

	const render = (html) => {
		if (destroyed) {
			throw new Error('Flow preview host has been destroyed.');
		}

		clearPendingLoadHandler();
		generation = nextGeneration(generation);
		activeGeneration = generation;
		lastAppliedHeight = null;
		awaitingFirstSize = false;
		clearFirstSizeTimer();
		setState('loading');

		if (!isCanonicalFlowPreviewHtml(html)) {
			failCurrentRender();
			return false;
		}

		const hydratedHtml = html.replace(
			GENERATION_MARKER,
			`data-cb-core-flow-preview-generation="${activeGeneration}"`
		);

		awaitingFirstSize = true;
		const renderGeneration = activeGeneration;
		firstSizeTimer = windowRef.setTimeout(() => {
			if (!destroyed && awaitingFirstSize && activeGeneration === renderGeneration) {
				failCurrentRender();
			}
		}, FIRST_SIZE_TIMEOUT_MS);

		const onLoad = () => {
			if (pendingLoadHandler === onLoad) {
				pendingLoadHandler = null;
			}
			if (
				destroyed
				|| activeGeneration !== renderGeneration
				|| (lifecycle !== 'loading' && lifecycle !== 'ready')
			) {
				return;
			}
			const childWindow = iframe.contentWindow;
			if (!childWindow || typeof childWindow.postMessage !== 'function') {
				return;
			}
			childWindow.postMessage({
				type: MEASURE_MESSAGE_TYPE,
				version: PROTOCOL_VERSION,
				generation: renderGeneration,
			}, '*');
			postSelection(renderGeneration);
		};

		pendingLoadHandler = onLoad;
		iframe.addEventListener('load', onLoad, { once: true });
		iframe.srcdoc = hydratedHtml;
		return true;
	};

	const setSelection = (regionId) => {
		if (destroyed) {
			throw new Error('Flow preview host has been destroyed.');
		}
		selectedRegionId = normalizeSelectionId(regionId);
		if (lifecycle === 'loading' || lifecycle === 'ready') {
			postSelection();
		}
	};

	const destroy = () => {
		if (destroyed) {
			return;
		}

		destroyed = true;
		lifecycle = 'destroyed';
		generation = nextGeneration(generation);
		activeGeneration = generation;
		selectedRegionId = null;
		awaitingFirstSize = false;
		clearFirstSizeTimer();
		clearPendingLoadHandler();
		windowRef.removeEventListener('message', onMessage);
		iframe.srcdoc = '';
		iframe.style.height = '0px';
		iframe.style.minHeight = '0px';
		iframe.style.visibility = 'hidden';
		iframe.setAttribute('aria-hidden', 'true');
		iframe.setAttribute('data-cb-core-flow-preview-state', 'destroyed');
		if (state.parentNode) {
			state.parentNode.removeChild(state);
		}
	};

	return { render, setSelection, destroy };
};
