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
	const painted = [];
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
			this.dataset.cbCoreSelectPickerReady = '1';
		}
		dispatchEvent(event) {
			if (event.type === 'cb:select-picker-sync') painted.push({value:this.value, disabled:this.disabled});
			return true;
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
	class Event { constructor(type) { this.type = type; } }

	runInNewContext(guardSource, {window, document, Element});
	window.cbCore.designerExitGuard.register(shell, {
		isDirty: () => (kind === 'dirty-save' || kind === 'cancel') && !saved,
		isUncertain: () => false,
		save: async () => { saved = true; return true; },
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
		window, document, Element, HTMLSelectElement, MutationObserver, CustomEvent, Event,
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
		stored: control.dataset.cbDesignShellContextValue, disabled: control.disabled, saved,
		painted, visible: painted.at(-1)?.value};
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
		assert.equal(actual.visible, success ? 'next' : 'original', kind + ' visual rollback');
		assert.ok(actual.painted.some((frame) => frame.disabled === true), kind + ' busy paint');
		assert.equal(actual.painted.at(-1)?.disabled, false, kind + ' ready paint');
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


test('B1 Header Picker readiness handles both module and classic-first orders exactly once', () => {
  const start = launchSource.indexOf('\tconst enhanceHeaderContext =');
  const end = launchSource.indexOf('\n\tconst composeHeader =', start);
  assert.ok(start > 0 && end > start, 'shared header enhancement helpers are present');
  const helpers = launchSource.slice(start, end);
  const expression = '(() => { ' + helpers +
    "\n document.addEventListener('cb:select-picker:ready', enhanceReadyDesignerContexts);" +
    "\n enhanceReadyDesignerContexts();" +
    "\n return { enhanceReadyDesignerContexts, enhanceHeaderContext }; })()";
  for (const moduleFirst of [true, false]) {
    class HTMLSelectElement {
      constructor() { this.multiple = false; this.dataset = {}; }
    }
    const select = new HTMLSelectElement();
    const context = { querySelectorAll: () => [select] };
    const toolbar = {
      dataset: { cbDesignShellHeader: 'true' },
      querySelector: () => context,
    };
    const root = { querySelector: () => toolbar };
    const listeners = new Map();
    const document = {
      querySelectorAll: () => [root],
      addEventListener(type, fn) { listeners.set(type, fn); },
    };
    let count = 0;
    const picker = { enhance(target, options) {
      assert.equal(target, select);
      assert.equal(options.variant, 'header');
      count++;
      select.dataset.cbCoreSelectPickerReady = '1';
    } };
    const window = { cbCore: moduleFirst ? {selectPicker:picker} : {} };
    const api = runInNewContext(expression, {document, window, HTMLSelectElement});
    if (!moduleFirst) {
      window.cbCore.selectPicker = picker;
      listeners.get('cb:select-picker:ready')();
    }
    api.enhanceReadyDesignerContexts();
    assert.equal(count, 1, moduleFirst ? 'module-first' : 'classic-first');
  }
});

test('B1 Native Mail imports one Picker module and restores blocked selection paint', () => {
  const native = read('assets/js/design/platform/wordpress-save-adapter.js');
  const php = read('src/Design/Editor/Assets.php');
  assert.match(php, /enqueue_select_picker\( \\CoreBlueprint\\Core\\UI\\Assets::SELECT_PICKER_PRESENTATION_CORE \)/);
  assert.match(native, /import '@cb-core\/select-picker';/);
  assert.match(php, /'@cb-core\/reports-platform', '@cb-core\/select-picker'/);
  assert.ok(native.indexOf('await host.mount(') < native.indexOf('contextSlot.append(contextControl);'));
  assert.ok(native.indexOf('contextSlot.append(contextControl);') < native.indexOf("picker.enhance(selected,{variant:'header'});"));

  const start = native.indexOf("selected.addEventListener('change',()=>{");
  const end = native.indexOf("\n\t\t\t});", start);
  assert.ok(start > 0 && end > start, 'native change handler is present');
  let onChange = null, paints = 0, navigation = 0;
  const selected = {
    value: 'next', dataset: {cbCoreSelectPickerReady:'1'},
    addEventListener(type, cb) { if (type === 'change') onChange = cb; },
    dispatchEvent(event) { if (event.type === 'cb:select-picker-sync') paints++; return true; },
  };
  const resourceKey = 'original';
  const transitionSafe = () => false;
  const location = {href:'https://example.test/?template=original', assign(){navigation++;}};
  runInNewContext(native.slice(start, end + 7), {
    selected, resourceKey, transitionSafe, location,
    Event: class {constructor(type){this.type=type;}},
  });
  assert.equal(typeof onChange, 'function');
  onChange();
  assert.equal(selected.value, 'original');
  assert.equal(paints, 1);
  assert.equal(navigation, 0);
});
