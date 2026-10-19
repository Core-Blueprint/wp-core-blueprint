import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const source = read('assets/js/features/designer-exit-guard.js');

const fixture = ({ dirty = false, receipt = true, changeDuringSave = false, action = 'save', accept = true, busy = false, withAdapter = true } = {}) => {
	const events = new Map();
	const toasts = [];
	const modals = [];
	let exits = 0;
	let writes = 0;
	let dirtyNow = dirty;
	class Element {
		constructor(tag = '') {
			this.tag = tag;
			this.children = [];
			this.checked = false;
			this.hidden = false;
			this.dataset = {};
		}
		append(...nodes) { this.children.push(...nodes); }
		setAttribute(key, value) { this[key] = value; }
		hasAttribute(key) { return key === 'aria-busy' && busy; }
	}
	const walk = (node) => [node, ...(node.children || []).flatMap(walk)];
	const document = {
		createElement: (tag) => new Element(tag),
		createTextNode: (text) => ({ text }),
		querySelector: () => null,
	};
	const window = {
		addEventListener: (name, callback) => events.set(name, callback),
		cbCore: {
			toast: {
				warning: (message) => toasts.push(['warning', message]),
				error: (message) => toasts.push(['error', message]),
			},
			modal: {
				show: async (options) => {
					modals.push(options);
					if (!accept) return false;
					if (!options.onConfirm) return true;
					for (const node of walk(options.body)) {
						if (node.tag === 'input') node.checked = node.value === action;
					}
					return Boolean(await options.onConfirm());
				},
			},
		},
	};
	runInNewContext(source, { window, document, Element, Error, TypeError });
	const shell = new Element('shell');
	if (withAdapter) window.cbCore.designerExitGuard.register(shell, {
		isDirty: () => dirtyNow,
		save: async () => {
			writes++;
			if (!receipt) throw new Error('Persisted receipt unavailable');
			if (!changeDuringSave) dirtyNow = false;
			return true;
		},
	});
	const exit = () => { exits++; return true; };
	return {
		shell, events, modals, toasts,
		request: () => window.cbCore.designerExitGuard.request(shell, exit),
		setDirty: (value) => { dirtyNow = value; },
		get exits() { return exits; },
		get writes() { return writes; },
	};
};

test('EXIT-A01: Base intercepts the canonical fullscreen lifecycle before exit', () => {
	const shell = read('assets/js/design/shell/index.js');
	const launch = read('assets/js/features/designer-launch.js');
	const assets = read('src/Design/Editor/Assets.php');
	assert.match(shell, /cb:design-shell:beforeexit/);
	assert.match(shell, /cancelable: true/);
	assert.match(shell, /if \(!immediate && !root\.dispatchEvent\(/);
	assert.match(launch, /bindExitGuard\(shell, fullscreen\)/);
	assert.match(launch, /event\.preventDefault\(\)/);
	assert.match(launch, /api\(\)\?\.request\?\.\(shell/);
	assert.match(assets, /DESIGNER_EXIT_GUARD_API = 1/);
	assert.match(assets, /enqueue_modals/);
	assert.match(assets, /DESIGNER_EXIT_GUARD_SCRIPT/);
});

test('EXIT-A02: clean exit asks for confirmation; Cancel never exits', async () => {
	const allowed = fixture();
	assert.equal(await allowed.request(), true);
	assert.equal(allowed.exits, 1);
	assert.equal(allowed.modals.length, 1);
	assert.equal(allowed.modals[0].title, 'Close Designer?');
	const cancelled = fixture({ accept: false });
	assert.equal(await cancelled.request(), false);
	assert.equal(cancelled.exits, 0);
});

test('EXIT-A03: dirty Save & Close requires the verified receipt and no newer edits', async () => {
	const clean = fixture({ dirty: true });
	assert.equal(await clean.request(), true);
	assert.equal(clean.writes, 1);
	assert.equal(clean.exits, 1);
	assert.equal(clean.modals.length, 1);
	const unverified = fixture({ dirty: true, receipt: false });
	assert.equal(await unverified.request(), false);
	assert.equal(unverified.exits, 0);
	assert.equal(unverified.writes, 1);
	const newer = fixture({ dirty: true, changeDuringSave: true });
	assert.equal(await newer.request(), false);
	assert.equal(newer.exits, 0);
});

test('EXIT-A04: explicit Discard is separate from Save and never retries writes', async () => {
	const discard = fixture({ dirty: true, action: 'discard' });
	assert.equal(await discard.request(), true);
	assert.equal(discard.writes, 0);
	assert.equal(discard.exits, 1);
});

test('EXIT-A05: active saves and absent modal fail closed', async () => {
	const saving = fixture({ dirty: true, busy: true });
	assert.equal(await saving.request(), false);
	assert.equal(saving.modals.length, 0);
	assert.equal(saving.exits, 0);
	assert.equal(saving.toasts[0][0], 'warning');
});

test('EXIT-A06: native unload warning only for registered dirty edits', () => {
	const current = fixture();
	let blocked = false;
	const beforeUnload = () => {
		const event = { preventDefault: () => { blocked = true; } };
		current.events.get('beforeunload')(event);
		return blocked;
	};
	assert.equal(beforeUnload(), false);
	current.setDirty(true);
	assert.equal(beforeUnload(), true);
});

test('EXIT-A07: consumer without a registered dirty adapter cannot be assumed clean', async () => {
	const unknown = fixture({ withAdapter: false });
	assert.equal(await unknown.request(), true);
	assert.match(unknown.modals[0].body, /may not be saved/i);
	assert.equal(unknown.modals.length, 1);
});
