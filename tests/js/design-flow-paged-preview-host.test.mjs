import test from 'node:test';
import assert from 'node:assert/strict';
import {createFlowPagedPreviewHost} from '../../assets/js/design/document/flow/paged-preview-host.js';

class FakeNode {
	constructor(tag, doc) {
		this.tagName = tag.toUpperCase();
		this.ownerDocument = doc;
		this.children = [];
		this.attributes = {};
		this.dataset = {};
		this.events = new Map();
		this.style = {};
		this.isConnected = true;
		const classes = new Set();
		this.classList = {
			add: x => classes.add(x), remove: x => classes.delete(x),
			toggle: (x, flag) => flag ? classes.add(x) : classes.delete(x),
			contains: x => classes.has(x),
		};
	}
	setAttribute(k, v) { this.attributes[k] = String(v); }
	addEventListener(k, f) { this.events.set(k, f); }
	append(...nodes) { this.children.push(...nodes); }
	replaceChildren(...nodes) { this.children = [...nodes]; }
	dispatchEvent(event) { this.lastEvent = event.type; }
	getContext() { return {}; }
	scrollIntoView() {}
}
const makeRoot = fetch => {
	const doc = {
		baseURI: 'https://site.test/wp-admin/admin.php',
		defaultView: {
			fetch, devicePixelRatio: 1,
			CustomEvent: class { constructor(type) { this.type = type; } },
		},
		createElement(tag) { return new FakeNode(tag, doc); },
	};
	return new FakeNode('div', doc);
};
const body = JSON.stringify({project: {root: {}}, fields: {}});
const request = {
	endpoint: 'https://site.test/wp-json/core-blueprint/v1/reports-designer/preview-paged',
	nonce: 'example-nonce', documentJSON: body,
	workerSrc: 'https://site.test/wp-content/plugins/core-blueprint/assets/vendor/pdfjs/pdf.worker.min.mjs',
};
const payload = () => {
	const bytes = Buffer.concat([Buffer.from('%PDF-1.7\n'), Buffer.alloc(64)]);
	return {
		status: 'ready', source: 'sample', pdf_base64: bytes.toString('base64'),
		projection: {
			page_count: 2, page_width_pt: 595.28, page_height_pt: 841.89,
			regions: {r0: [{page: 1, x: 51, y: 44, width: 485, height: 120}],
				r1: [{page: 2, x: 51, y: 44, width: 485, height: 120}]},
		},
	};
};
const sourceFactory = async options => {
	assert.ok(options.pdfBytes instanceof Uint8Array);
	assert.match(options.workerSrc, /pdf\.worker\.min\.mjs$/);
	return {
		numPages: 2,
		async getPage(page) {
			return {
				getViewport({scale}) { return {width: 595.28 * scale, height: 841.89 * scale}; },
				render() { return {promise: Promise.resolve(), cancel() {}}; },
			};
		},
		destroy() {},
	};
};
const tick = () => new Promise(resolve => setImmediate(resolve));

test('DP-NAV-02C authenticated paged preview emits no-store POST and live native page targets', async () => {
	let requests = 0;
	let notices = [];
	const root = makeRoot(async (url, options) => {
		requests++;
		assert.match(url, /\/reports-designer\/preview-paged$/);
		assert.equal(options.method, 'POST');
		assert.equal(options.headers['X-WP-Nonce'], 'example-nonce');
		assert.equal(options.credentials, 'same-origin');
		assert.equal(options.cache, 'no-store');
		assert.equal(options.body, body);
		return {ok: true, headers: {get() { return null; }}, json: async () => payload()};
	});
	let navConfig;
	const host = createFlowPagedPreviewHost({
		root, pdfJsLoader: async () => ({version: '6.5.438'}),
		pageSourceFactory: sourceFactory,
		navigation: {update: x => navConfig = x, setActivePage() {}},
		resolveRegion: id => ({path: [id === 'r0' ? 0 : 1], type: 'reports.text', draggable: true}),
		onActivate: intent => notices.push(intent),
		onSource: text => notices.push(text),
	});
	assert.equal(await host.render(request), true);
	await tick();
	assert.equal(requests, 1);
	assert.equal(root.children.length, 2);
	assert.equal(root.children[0].children[0].children[0].tagName, 'CANVAS');
	assert.equal(root.children[0].children[1].children[0].dataset.cbDesignNodePath, '[0]');
	assert.deepEqual(navConfig.pages.map(x => x.id), ['pdf-page-1', 'pdf-page-2']);
	assert.equal(notices[0], 'sample');
	root.children[0].children[1].children[0].events.get('click')();
	assert.deepEqual(notices[1], {regionId: 'r0', page: 1});
	assert.equal(host.setSelection('r0'), true);
	assert.equal(root.children[0].children[1].children[0].attributes['aria-pressed'], 'true');
	host.destroy();
	assert.equal(root.children.length, 0);
});

test('DP-NAV-02C disallows cross-origin preview transport before network or PDF parsing', async () => {
	let fetched = false;
	const root = makeRoot(async () => { fetched = true; });
	const host = createFlowPagedPreviewHost({root});
	await assert.rejects(
		host.render({...request, endpoint: 'https://evil.test/preview-paged'}),
		/FLOW_PAGED_PREVIEW_ENDPOINT_UNTRUSTED/
	);
	assert.equal(fetched, false);
	host.destroy();
});

test('DP-NAV-02C cancellation defeats stale debounced PDF requests', async () => {
	let fetched = 0;
	const root = makeRoot(async () => { fetched++; });
	const host = createFlowPagedPreviewHost({root});
	const pending = host.render(request);
	host.destroy();
	assert.equal(await pending, false);
	assert.equal(fetched, 0);
});

test('DP-NAV-02C invalid PDF+projection envelope fails closed without DOM or Save', async () => {
	let errors = 0;
	const root = makeRoot(async () => ({
		ok: true, headers: {get() { return null; }},
		json: async () => ({...payload(), status: 'committed'}),
	}));
	const host = createFlowPagedPreviewHost({root, onError: () => errors++});
	await assert.rejects(host.render(request), /FLOW_PAGED_PREVIEW_ENVELOPE_INVALID/);
	assert.equal(errors, 1);
	assert.equal(root.children.length, 0);
	host.destroy();
});
