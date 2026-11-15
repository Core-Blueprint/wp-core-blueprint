import test from 'node:test';
import assert from 'node:assert/strict';
import {
	createFlowPagedSurface, validateFlowPageProjection,
} from '../../assets/js/design/document/flow/paged-surface.js';
import {
	createBundledPdfJsPageSource, BUNDLED_PDFJS_VERSION,
} from '../../assets/js/design/document/flow/pdfjs-page-source.js';

class FakeNode {
	constructor(tag, doc) {
		this.tagName = tag.toUpperCase();
		this.ownerDocument = doc;
		this.children = [];
		this.dataset = {};
		this.attributes = {};
		this.events = new Map();
		this.style = {};
		this.disabled = false;
		const classes = new Set();
		this.classList = {
			add: (...names) => names.forEach(name => classes.add(name)),
			remove: (...names) => names.forEach(name => classes.delete(name)),
			toggle: (name, force) => {
				if (force) classes.add(name);
				else classes.delete(name);
			},
			contains: name => classes.has(name),
		};
	}
	append(...nodes) { this.children.push(...nodes); }
	replaceChildren(...nodes) { this.children = [...nodes]; }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	addEventListener(name, listener) { this.events.set(name, listener); }
	scrollIntoView(options) { this.scrollOptions = options; }
	getContext() { return {}; }
}

const mount = () => {
	const doc = {
		defaultView: {devicePixelRatio: 1},
		createElement(tag) { return new FakeNode(tag, doc); },
	};
	return new FakeNode('main', doc);
};
const geometry = (count = 2) => ({
	page_count: count, page_width_pt: 595.28, page_height_pt: 841.89,
	regions: {
		r0: [
			{page: 1, x: 51.02, y: 42.52, width: 493.23, height: 742.63},
			...(count > 1 ? [{page: 2, x: 51.02, y: 42.52, width: 493.23, height: 80}] : []),
		],
		...(count > 1 ? {r1: [{page: 2, x: 51, y: 150, width: 300, height: 30}]} : {}),
	},
});
const pdfPageSource = (count = 2) => {
	const calls = [];
	let destroyCount = 0;
	return {
		numPages: count, calls,
		get destroyCount() { return destroyCount; },
		async getPage(page) {
			calls.push(page);
			return {
				getViewport({scale}) {
					return {width: 595.28 * scale, height: 841.89 * scale};
				},
				render({canvasContext, viewport}) {
					assert.ok(canvasContext);
					assert.ok(viewport.width > 0);
					return {promise: Promise.resolve(), cancel() {}};
				},
			};
		},
		destroy() { destroyCount++; },
	};
};
const tick = () => new Promise(resolve => setImmediate(resolve));
const pdfBytes = () => Uint8Array.from(Buffer.concat([
	Buffer.from('%PDF-1.7\n'),
	Buffer.alloc(32),
]));

test('DP-NAV-02C page projection validates real PDF-point fragments without guessing HTML heights', () => {
	const normalized = validateFlowPageProjection(geometry());
	assert.equal(normalized.page_count, 2);
	assert.equal(normalized.regions.r0[1].page, 2);
	assert.equal(Object.isFrozen(normalized.regions.r0[0]), true);
	assert.throws(() => validateFlowPageProjection({...geometry(), page_count: 501}),
		/FLOW_PAGED_PROJECTION_INVALID/);
	assert.throws(() => validateFlowPageProjection({
		...geometry(), regions: {r0: [{page: 2, x: 595, y: 0, width: 2, height: 1}]},
	}), /FLOW_PAGED_PROJECTION_RECTANGLE_INVALID/);
	assert.throws(() => validateFlowPageProjection({...geometry(), regions: {'../x': geometry().regions.r0}}),
		/FLOW_PAGED_PROJECTION_REGION_ID_INVALID/);
});

test('DP-NAV-02C mounts actual separate paper surfaces, semantic overlays and Base page targets', async () => {
	const root = mount();
	const source = pdfPageSource();
	const intents = [];
	const navigation = {
		update(descriptors) { this.descriptors = descriptors; },
		setActivePage(id) { this.selected = id; },
	};
	const surface = createFlowPagedSurface({
		root, pageSource: source, projection: geometry(), navigation,
		labels: {r0: 'Report paragraph'},
		onActivate: intent => intents.push(intent),
	});
	await tick();
	await tick();
	assert.equal(root.children.length, 2);
	assert.equal(root.children[0].dataset.cbFlowPagedPage, undefined);
	assert.equal(root.children[0].attributes['data-cb-flow-paged-page'], '1');
	assert.equal(root.children[1].attributes['data-cb-flow-paged-page'], '2');
	assert.equal(root.children[0].children[0].children[0].tagName, 'CANVAS');
	assert.equal(root.children[1].children[0].children.length, 0);
	assert.deepEqual(source.calls, [1]);
	assert.deepEqual(navigation.descriptors.pages.map(x => x.id), ['pdf-page-1', 'pdf-page-2']);
	const first = root.children[0].children[1].children[0];
	assert.equal(first.attributes['aria-label'], 'Select Report paragraph on page 1');
	first.events.get('click')();
	assert.deepEqual(intents, [{regionId: 'r0', page: 1}]);
	assert.equal(surface.setSelection('r0'), true);
	assert.equal(first.attributes['aria-pressed'], 'true');
	assert.equal(surface.goToPage(2), true);
	await tick();
	await tick();
	assert.deepEqual(source.calls, [1, 2]);
	assert.equal(root.children[1].children[0].children[0].tagName, 'CANVAS');
	assert.equal(navigation.selected, 'pdf-page-2');
	assert.equal(root.children[1].scrollOptions.block, 'start');
	assert.equal(surface.goToPage(3), false);
	assert.throws(() => surface.setSelection('../injection'), /FLOW_PAGED_SELECTION_INVALID/);
	surface.destroy();
	assert.equal(root.children.length, 0);
	assert.equal(source.destroyCount, 1);
	surface.destroy();
	assert.equal(source.destroyCount, 1);
	assert.equal(surface.goToPage(1), false);
});

test('DP-NAV-02C refuses mismatched page count and excessive raster budget before mounting', () => {
	const root = mount();
	const source = pdfPageSource(1);
	assert.throws(() => createFlowPagedSurface({root, pageSource: source, projection: geometry(2)}),
		/FLOW_PAGED_PAGE_COUNT_MISMATCH/);
	assert.deepEqual(root.children, []);
	root.ownerDocument.defaultView.devicePixelRatio = 2;
	assert.throws(() => createFlowPagedSurface({
		root, pageSource: pdfPageSource(), projection: geometry(), zoom: 2,
	}), /FLOW_PAGED_SURFACE_CANVAS_BUDGET_EXCEEDED/);
	assert.deepEqual(root.children, []);
});

test('DP-NAV-02C cancels stale asynchronous page renders on destroy', async () => {
	const root = mount();
	let release;
	const pending = new Promise(resolve => { release = resolve; });
	const source = pdfPageSource(1);
	source.getPage = () => pending;
	const surface = createFlowPagedSurface({
		root, pageSource: source, projection: geometry(1),
	});
	surface.destroy();
	release({
		getViewport: ({scale}) => ({width: 595.28 * scale, height: 841.89 * scale}),
		render: () => { throw new Error('Stale page should not render'); },
	});
	await tick();
	assert.equal(root.children.length, 0);
	assert.equal(source.destroyCount, 1);
});

test('DP-NAV-02C PDF.js adapter admits only pinned local worker and PDF byte snapshots', async () => {
	const data = pdfBytes();
	let taskDestroyed = 0;
	const pdfjs = {
		version: BUNDLED_PDFJS_VERSION,
		GlobalWorkerOptions: {},
		getDocument(options) {
			assert.equal(options.isEvalSupported, false);
			assert.equal(options.enableXfa, false);
			assert.ok(options.data instanceof Uint8Array);
			assert.notEqual(options.data, data, 'PDF bytes are copied before transfer');
			return {
				promise: Promise.resolve({numPages: 2, getPage: async page => ({page})}),
				destroy() { taskDestroyed++; },
			};
		},
	};
	const source = await createBundledPdfJsPageSource({
		pdfjs, pdfBytes: data, baseUrl: 'https://example.test/wp-admin/',
		workerSrc: 'https://example.test/wp-content/plugins/core-blueprint/assets/vendor/pdfjs/pdf.worker.min.mjs',
	});
	assert.equal(source.numPages, 2);
	assert.deepEqual(await source.getPage(1), {page: 1});
	assert.equal(pdfjs.GlobalWorkerOptions.workerSrc.startsWith('https://example.test/'), true);
	assert.throws(() => source.getPage(3), /FLOW_PAGED_PDFJS_PAGE_INVALID/);
	source.destroy();
	assert.equal(taskDestroyed, 1);
	assert.throws(() => source.getPage(1), /FLOW_PAGED_PDFJS_PAGE_INVALID/);
});

test('DP-NAV-02C rejects untrusted PDF.js worker and invalid PDF without fetching', async () => {
	let calls = 0;
	const pdfjs = {
		version: BUNDLED_PDFJS_VERSION,
		GlobalWorkerOptions: {},
		getDocument() { calls++; throw new Error('Must not load'); },
	};
	await assert.rejects(createBundledPdfJsPageSource({
		pdfjs, pdfBytes: pdfBytes(),
		baseUrl: 'https://example.test/wp-admin/',
		workerSrc: 'https://cdn.example.org/pdf.worker.min.mjs',
	}), /FLOW_PAGED_PDFJS_WORKER_UNTRUSTED/);
	await assert.rejects(createBundledPdfJsPageSource({
		pdfjs, pdfBytes: new Uint8Array(40),
		baseUrl: 'https://example.test/wp-admin/',
		workerSrc: 'https://example.test/assets/pdf.worker.min.mjs',
	}), /FLOW_PAGED_PDF_BYTES_INVALID/);
	assert.equal(calls, 0);
});
