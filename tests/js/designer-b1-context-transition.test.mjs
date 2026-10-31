import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import {runInNewContext} from 'node:vm';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const guardSource = read('assets/js/features/designer-exit-guard.js');
const launchSource = read('assets/js/features/designer-launch.js');
const contextSource = read('assets/js/features/designer-context.js');

const launchGate = launchSource.slice(
	launchSource.indexOf("document.addEventListener('cb:design-shell:contextrequest'"),
	launchSource.indexOf('\n\tconst panelToggleLabel'),
);

const scenario = async (kind) => {
	const handlers = new Map(), toasts = [];
	let saved = false, modalCount = 0, contextChanges = 0, loads = 0;
	class Element {
		constructor() {
			this.dataset = {};
			this.attributes = new Map();
			this.children = [];
			this.classList = {add() {}, remove() {}};
			this.isConnected = true;
			this.hidden = false;
		}
		setAttribute(key, value) { this.attributes.set(key, String(value)); }
		getAttribute(key) { return this.attributes.has(key) ? this.attributes.get(key) : null; }
		hasAttribute(key) { return this.attributes.has(key); }
		removeAttribute(key) { this.attributes.delete(key); }
		append(...nodes) { this.children.push(...nodes); }
		querySelector() { return null; }
	}
	class HTMLSelectElement extends Element {
		constructor() {
			super();
			this.value = 'next';
			this.dataset.cbDesignShellContextValue = 'original';
			this.disabled = false;
		}
		closest(selector) {
			return selector === '[data-cb-design-shell-context]' ? context
				: selector === '[data-cb-design-launch-root]' ? root : null;
		}
	}
	const shell = new Element();
	const context = new Element();
	const root = new Element();
	const control = new HTMLSelectElement();
	context.closest = selector => selector === '[data-cb-design-launch-root]' ? root : null;
	root.querySelector = selector => selector === '[data-cb-design-shell]' ? shell : null;
	const on = (name, listener) => {
		if (!handlers.has(name)) handlers.set(name, []);
		handlers.get(name).push(listener);
	};
	const document = {
		readyState: 'complete',
		documentElement: new Element(),
		createElement(tag) { const node = new Element(); node.tag = tag; return node; },
		createTextNode: text => ({text}),
		querySelectorAll: () => [],
		querySelector: () => null,
		addEventListener: on,
	};
	const window = {
		addEventListener: on,
		requestAnimationFrame: callback => callback(),
		setTimeout: callback => { Promise.resolve().then(callback); return 1; },
		cbCore: {
			toast: {error: message => toasts.push(message)},
			modal: {
				show: async options => {
					modalCount++;
					if (kind === 'cancel') return false;
					const walk = node => [node, ...(node.children || []).flatMap(walk)];
					for (const node of walk(options.body)) {
						if (node.tag === 'input') node.checked = node.value === 'save';
					}
					return options.onConfirm ? Boolean(await options.onConfirm()) : true;
				},
			},
		},
	};
	class CustomEvent {
		constructor(type, options) { this.type = type; this.detail = options.detail; }
		stopImmediatePropagation() { this.stopped = true; }
	}
	class MutationObserver { observe() {} }

	runInNewContext(guardSource, {window, document, Element});
	window.cbCore.designerExitGuard.register(shell, {
		isDirty: () => (kind === 'dirty-save' || kind === 'cancel') && !saved,
		isUncertain: () => false,
		save: async () => { saved = true; return true; },
		discard: () => true,
	});
	assert.ok(launchGate.includes('isTransitionDispatchAuthorized'));
	runInNewContext(launchGate, {window, document});
	root.dispatchEvent = event => {
		if (event.type === 'cb:design-shell:contextrequest') {
			event.target = root;
			handlers.get(event.type)?.forEach(listener => listener(event));
			assert.equal(event.stopped, undefined, 'unapproved event must be blocked');
			loads++;
			const response = kind === 'reject'
				? Promise.reject(new Error('backend secret must not be toasted'))
				: Promise.resolve(kind === 'false' ? false : {id: 'next'});
			event.detail.respondWith(response);
		} else if (event.type === 'cb:design-shell:contextchanged') {
			contextChanges++;
		}
		return true;
	};
	runInNewContext(contextSource, {
		window, document, Element, HTMLSelectElement, MutationObserver, CustomEvent,
	});

	const event = {
		target: control, preventDefault() {}, stopPropagation() {}, stopImmediatePropagation() {},
	};
	handlers.get('change').forEach(listener => listener(event));
	if (kind === 'duplicate') {
		control.value = 'next';
		handlers.get('change').forEach(listener => listener(event));
	}
	for (let turn = 0; turn < 35; turn++) await Promise.resolve();
	return {contextChanges, loads, modalCount, toasts, value: control.value,
		stored: control.dataset.cbDesignShellContextValue, disabled: control.disabled, saved};
};

test('B1 context capture commits only applied targets and never replays a context request', async () => {
	for (const kind of ['ok', 'reject', 'false', 'cancel', 'dirty-save', 'duplicate']) {
		const actual = await scenario(kind);
		const success = !['reject', 'false', 'cancel'].includes(kind);
		assert.equal(actual.contextChanges, success ? 1 : 0, kind);
		assert.equal(actual.loads, kind === 'cancel' ? 0 : 1, kind);
		assert.equal(actual.stored, success ? 'next' : 'original', kind);
		assert.equal(actual.value, success ? 'next' : 'original', kind);
		assert.equal(actual.disabled, false, kind);
		assert.equal(actual.modalCount, ['cancel', 'dirty-save'].includes(kind) ? 1 : 0, kind);
		assert.equal(actual.toasts.length, ['reject', 'false'].includes(kind) ? 1 : 0, kind);
		if (kind === 'reject') {
			assert.doesNotMatch(actual.toasts.join(' '), /backend secret/);
		}
		if (kind === 'dirty-save') assert.equal(actual.saved, true);
	}
});

test('B1 native Mail/Reports still use their Host-specific transport', () => {
	const assets = read('src/Design/Editor/Assets.php');
	const native = read('assets/js/design/platform/wordpress-save-adapter.js');
	assert.match(assets, /public static function enqueue_platform\(\): void/);
	assert.match(assets, /@cb-core\/mail-platform/);
	assert.match(assets, /@cb-core\/reports-platform/);
	assert.match(native, /attachTrustedDesignerHost/);
	assert.match(native, /transitionSafe/);
});
