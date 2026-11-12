import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import {test} from 'node:test';

const source = readFileSync(new URL('../../assets/js/features/designer-context.js', import.meta.url), 'utf8');
const nextMicrotasks = async () => {
	for (let i = 0; i < 8; i++) await Promise.resolve();
};

function fixture({direct = false, initial = 'one', mounted = true} = {}) {
	const listeners = new Map();
	let observerCallback = null;
	const calls = [];
	const errors = [];
	const state = {outcome:'blocked', mounted};
	class Element {
		constructor() {
			this.children = [];
			this.dataset = {};
			this.attributes = {};
			this.hidden = false;
			this.classList = {add() {}, remove() {}};
		}
		append(...children) { this.children.push(...children); }
		setAttribute(key, value) { this.attributes[key] = String(value); }
		removeAttribute(key) { delete this.attributes[key]; }
		querySelector(selector) {
			if (selector === ':scope > [data-cb-design-shell-context-transition]') {
				return this.children.find(node => Object.hasOwn(node.dataset, 'cbDesignShellContextTransition')) ?? null;
			}
			if (selector === '[data-cb-design-shell-context-transition-message]') {
				return this.children.flatMap(node => node.children).find(
					node => Object.hasOwn(node.dataset, 'cbDesignShellContextTransitionMessage')
				) ?? null;
			}
			return null;
		}
	}
	const shell = new Element();
	const root = {querySelector(selector) {
		return selector === '[data-cb-design-shell]' ? shell : null;
	}};
	const context = {closest(selector) {
		return selector === '[data-cb-design-launch-root]' ? root : null;
	}};
	class HTMLSelectElement extends Element {
		constructor() {
			super();
			this.value = initial;
			this.disabled = false;
			this.paints = 0;
			this.changeEvents = 0;
		}
		closest(selector) {
			if (selector === '[data-cb-design-shell-context]') return direct ? this : context;
			if (selector === '[data-cb-design-launch-root]') return root;
			return null;
		}
		dispatchEvent(event) {
			if (event.type === 'cb:select-picker-sync') this.paints++;
			if (event.type === 'change') this.changeEvents++;
			return true;
		}
	}
	const select = new HTMLSelectElement();
	select.dataset.cbCoreSelectPickerReady = '1';
	const document = {
		documentElement: {}, readyState: 'complete',
		createElement: () => new Element(),
		querySelectorAll(selector) {
			if (!state.mounted) return [];
			const wanted = direct
				? 'select[data-cb-design-shell-context]'
				: '[data-cb-design-shell-context] select';
			return selector.split(',').map(item => item.trim()).includes(wanted) ? [select] : [];
		},
		addEventListener(type, callback) { listeners.set(type, callback); },
	};
	const window = {
		cbCoreDesignerLaunch: {},
		cbCore: {
			designerExitGuard: {
				contractVersion: 4,
				transition(actingShell, input) {
					assert.equal(actingShell, shell);
					calls.push(input.targetId);
					return Promise.resolve({status: state.outcome});
				},
			},
			toast: {error(message) {errors.push(message);}},
		},
		requestAnimationFrame(callback) {callback();},
		setTimeout(callback) {callback();},
	};
	runInNewContext(source, {
		document, window, HTMLSelectElement,
		MutationObserver: class {
			constructor(callback) { observerCallback = callback; }
			observe() {}
		},
		Event: class {constructor(type) {this.type = type;}},
	}, {timeout: 1000});
	const fire = () => {
		const event = {
			target: select, prevented: 0, stopped: 0, stoppedImmediate: 0,
			preventDefault() {this.prevented++;},
			stopPropagation() {this.stopped++;},
			stopImmediatePropagation() {this.stoppedImmediate++;},
		};
		listeners.get('change')(event);
		return event;
	};
	return {select, calls, errors, state, window, fire, observe: () => observerCallback()};
}

test('BASE-CTX-01: nested and direct-marked selects start with the last authorized value', () => {
	for (const direct of [false, true]) {
		const f = fixture({direct});
		assert.equal(f.select.dataset.cbDesignShellContextValue, 'one',
			(direct ? 'direct' : 'nested') + ' select must have its baseline on initial scan');
		f.select.value = 'unapproved';
		f.observe();
		assert.equal(f.select.dataset.cbDesignShellContextValue, 'one',
			'a subsequent DOM scan must not commit an unapproved select value');
	}
});

test('BASE-CTX-01: Cancel/failure restores baseline; applied transitions advance it once', async () => {
	for (const direct of [false, true]) {
		const f = fixture({direct});
		f.select.value = 'two';
		f.state.outcome = 'cancelled';
		let event = f.fire();
		assert.equal(f.select.value, 'one', 'target never paints as current while unauthorized');
		await nextMicrotasks();
		assert.deepEqual(f.calls, ['two']);
		assert.equal(f.select.dataset.cbDesignShellContextValue, 'one');
		assert.equal(f.select.value, 'one');
		assert.equal(event.prevented, 1);
		assert.equal(event.stoppedImmediate, 1);

		f.select.value = 'two';
		f.state.outcome = 'applied';
		f.fire();
		await nextMicrotasks();
		assert.deepEqual(f.calls, ['two', 'two']);
		assert.equal(f.select.dataset.cbDesignShellContextValue, 'two');
		assert.equal(f.select.value, 'two');

		f.select.value = 'one';
		f.state.outcome = 'failed';
		f.fire();
		await nextMicrotasks();
		assert.deepEqual(f.calls, ['two', 'two', 'one']);
		assert.equal(f.select.value, 'two');
		assert.equal(f.select.dataset.cbDesignShellContextValue, 'two');
		assert.ok(f.select.paints >= 6, 'Picker repaints without dispatching another change');
		assert.equal(f.select.changeEvents, 0, 'rollback never emits a second change');
		assert.equal(f.select.disabled, false);
		assert.equal(f.errors.length, 1, 'only the failed load notifies, Cancel stays silent');
	}
});

test('BASE-CTX-01: dynamically mounted direct select is initialized by the existing observer', () => {
	const f = fixture({direct:true, initial:'draft-b', mounted:false});
	assert.equal(f.select.dataset.cbDesignShellContextValue, undefined);
	f.state.mounted = true;
	f.observe();
	assert.equal(f.select.dataset.cbDesignShellContextValue, 'draft-b');
	f.select.value = 'draft-c';
	const event = f.fire();
	assert.equal(event.stoppedImmediate, 1);
	assert.equal(f.select.value, 'draft-b');
	assert.deepEqual(f.calls, ['draft-c']);
});

test('BASE-CTX-01: unavailable v4 authority blocks without authorizing a target', () => {
	const f = fixture({direct:true, initial:'safe'});
	f.window.cbCore.designerExitGuard.contractVersion = 3;
	f.select.value = 'unsafe';
	f.fire();
	assert.equal(f.select.value, 'safe');
	assert.equal(f.select.dataset.cbDesignShellContextValue, 'safe');
	assert.deepEqual(f.calls, [], 'no unsupported transition request');
	assert.equal(f.errors.length, 1);
});

test('BASE-CTX-01: no second context coordinator or legacy event protocol', () => {
	assert.match(source, /select\[data-cb-design-shell-context\]/);
	assert.match(source, /guard\.transition\(shell, \{targetId: value\}\)/);
	assert.doesNotMatch(source, /respondWith|contextrequest|requestTransition/);
	assert.equal((source.match(/document\.addEventListener\('change'/g) ?? []).length, 1);
});
