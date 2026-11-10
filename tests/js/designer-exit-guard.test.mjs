import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const source = read('assets/js/features/designer-exit-guard.js');

const fixture = (options = {}) => {
	const { dirty = false, receipt = true, changeDuringSave = false, action = 'save', accept = true, busy = false, deferredModal = false, withAdapter = true, unknown = false, throwDirty = false, indeterminateDirty = false, discardOutcome, discardStaysDirty = false } = options;
	// Distinguish an explicitly undefined return from an omitted fixture override.
	const restoredOutcome = Object.hasOwn(options, 'discardOutcome') ? discardOutcome : true;
	const events = new Map();
	const toasts = [];
	const modals = [];
	let exits = 0;
	let writes = 0;
	let discards = 0;
	let dirtyNow = dirty;
	let busyNow = busy;
	let confirmModal = null;
	class Element {
		constructor(tag = '') {
			this.tag = tag;
			this.children = [];
			this.checked = false;
			this.hidden = false;
			this.dataset = {};
			this.isConnected = true;
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
					if (deferredModal) await new Promise((resolve) => { confirmModal = resolve; });
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
	const unregister = withAdapter ? window.cbCore.designerExitGuard.register(shell, {
		isDirty: () => { if (throwDirty) throw new Error('Dirty authority unavailable'); if (indeterminateDirty) return null; return dirtyNow; },
		save: async () => {
			writes++;
			if (!receipt) throw new Error('Persisted receipt unavailable');
			if (!changeDuringSave) dirtyNow = false;
			return true;
		},
		discard: () => {
			discards++;
			if (restoredOutcome !== true) return restoredOutcome;
			if (!discardStaysDirty) dirtyNow = false;
			return true;
		},
	}) : null;
	if (unknown) { shell.dataset.cbDesignShellSaveOutcomeUnknown = 'true'; shell.dataset.cbDesignShellSaveState = 'unknown'; }
	const exit = () => { exits++; return true; };
	return {
		shell, events, modals, toasts,
		request: () => window.cbCore.designerExitGuard.request(shell, exit),
		switchDesign: () => window.cbCore.designerExitGuard.request(shell, exit, {intent:'switch'}),
		transition: (apply) => window.cbCore.designerExitGuard.transition(shell, {targetId: apply}),
		setDirty: (value) => { dirtyNow = value; },
		beginSaving: () => { busyNow = true; shell.dataset.cbDesignShellSaveState = 'saving'; },
		releaseModal: () => { if (!confirmModal) throw new Error('Modal is not pending'); confirmModal(); },
		canTransition: () => window.cbCore.designerExitGuard.canTransition(shell),
		replaceAdapter: () => window.cbCore.designerExitGuard.register(shell, {
			isDirty: () => dirtyNow,
			save: async () => { writes++; return true; },
			discard: () => { discards++; dirtyNow = false; return true; },
		}),
		unregister: () => unregister?.(),
		detach: () => { shell.isConnected = false; },
		get exits() { return exits; },
		get writes() { return writes; },
		get discards() { return discards; },
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
	assert.match(assets, /DESIGNER_EXIT_GUARD_API = 4/);
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

test('BROWSER-SWITCH-01: clean design navigation uses existing synchronous fences without Close prompt', async () => {
	const f = fixture({dirty:false});
	assert.equal(await f.switchDesign(), true);
	assert.equal(f.modals.length, 0, 'Browser already conveys the explicit design choice');
	assert.equal(f.exits, 1);
	assert.equal(f.writes, 0);
	assert.equal(f.discards, 0);
});

test('BROWSER-SWITCH-02: dirty navigation requires Save/Discard/Cancel with switch-specific labels', async () => {
	const saved = fixture({dirty:true, action:'save'});
	assert.equal(await saved.switchDesign(), true);
	assert.equal(saved.modals.length, 1);
	assert.equal(saved.modals[0].confirmLabel, 'Switch design');
	assert.equal(saved.modals[0].body.children[0].textContent,
		'Save or discard your changes before switching designs.');
	assert.equal(saved.writes, 1);
	const cancelled = fixture({dirty:true, accept:false});
	assert.equal(await cancelled.switchDesign(), false);
	assert.equal(cancelled.exits, 0);
	const unknown = fixture({dirty:true, unknown:true});
	assert.equal(await unknown.switchDesign(), false);
	assert.equal(unknown.modals.length, 0);
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

test('EXIT-A07: missing dirty authority refuses Designer exit without a modal', async () => {
	const missing = fixture({ withAdapter: false });
	assert.equal(await missing.request(), false);
	assert.equal(missing.exits, 0);
	assert.equal(missing.modals.length, 0);
});
test('EXIT-P0-01: UNKNOWN Save blocks Save, discard and exits even if edits cease', async () => {
	for (const action of ['save', 'discard']) {
		const f = fixture({ dirty: true, unknown: true, action });
		assert.equal(await f.request(), false);
		assert.equal(f.exits, 0);
		assert.equal(f.writes, 0);
		f.setDirty(false);
		assert.equal(await f.request(), false);
	}
});
test('EXIT-P0-02: throwing dirty authority refuses to exit', async () => {
	const f = fixture({ dirty: true, throwDirty: true });
	assert.equal(await f.request(), false);
	assert.equal(f.exits, 0);
});
test('EXIT-P0-03: UNKNOWN Save triggers native unload warning', () => {
	const f = fixture({ unknown: true });
	let prevented = false;
	const event = { preventDefault() { prevented = true; }, returnValue: undefined };
	f.events.get('beforeunload')(event);
	assert.equal(prevented, true);
	assert.equal(event.returnValue, '');
});
test('EXIT-P0-04: live consumer Save authorization and explicit UNKNOWN event handling', () => {
	const launch = read('assets/js/features/designer-launch.js');
	assert.match(launch, /const saveAuthorized = \(\) => save\?\.getAttribute\?\.\('data-cb-design-shell-save-authorized'\) !== 'false'/);
	assert.match(launch, /cbDesignShellSaveOutcomeUnknown/);
	assert.match(launch, /if \(state === 'unknown' \|\| event\.detail\?\.uncertain === true\)/);
	assert.match(launch, /save\.disabled = !saveAuthorized\(\) \|\| busy/);
});
test('EXIT-P0-05: context and fullscreen escape use synchronous Base refusal gates', () => {
	const launch = read('assets/js/features/designer-launch.js');
	const shell = read('assets/js/design/shell/index.js');
	assert.doesNotMatch(launch, /cb:design-shell:contextrequest/);
	assert.doesNotMatch(launch, /isTransitionDispatchAuthorized/);
	assert.match(shell, /cb:design-shell:beforeexit/);
	assert.match(shell, /cancelable: true/);
});

test('EXIT-P0-06: indeterminate dirty authority cannot become a clean exit', async () => {
  const f = fixture({ indeterminateDirty: true });
  assert.equal(await f.request(), false);
  assert.equal(f.exits, 0);
});

test('EXIT-P0-07: idle -> modal pending -> saving -> clean confirmation refuses exit', async () => {
	const f = fixture({ dirty: false, deferredModal: true });
	const exitPending = f.request();
	assert.equal(f.modals.length, 1);
	f.beginSaving();
	f.releaseModal();
	assert.equal(await exitPending, false);
	assert.equal(f.exits, 0);
	assert.equal(f.writes, 0);
});

test('EXIT-P0-08: idle -> modal pending -> saving -> explicit Discard refuses exit', async () => {
	const f = fixture({ dirty: true, action: 'discard', deferredModal: true });
	const exitPending = f.request();
	assert.equal(f.modals.length, 1);
	f.beginSaving();
	f.releaseModal();
	assert.equal(await exitPending, false);
	assert.equal(f.exits, 0);
	assert.equal(f.writes, 0);
});

test('EXIT-P0-09: detached and unregistered shell does not cause stale unload warning', () => {
	const f = fixture({ dirty: true });
	f.detach();
	f.unregister();
	let prevented = false;
	const event = { preventDefault() { prevented = true; }, returnValue: undefined };
	f.events.get('beforeunload')(event);
	assert.equal(prevented, false);
	assert.equal(event.returnValue, undefined);
});


test('G2-S T09: modal opened before a newer edit, active Save and adapter replacement must not close', async () => {
  const dirty = fixture({ dirty: false, deferredModal: true });
  const pendingDirty = dirty.request();
  assert.equal(dirty.modals.length, 1);
  dirty.setDirty(true);
  dirty.releaseModal();
  assert.equal(await pendingDirty, false);
  assert.equal(dirty.exits, 0);

  const replaced = fixture({ dirty: false, deferredModal: true });
  const pendingReplaced = replaced.request();
  replaced.replaceAdapter();
  replaced.releaseModal();
  assert.equal(await pendingReplaced, false, 'a newly registered adapter cannot validate an older modal');
  assert.equal(replaced.exits, 0);

  const combined = fixture({ dirty: false, deferredModal: true });
  const pendingCombined = combined.request();
  combined.setDirty(true);
  combined.beginSaving();
  combined.replaceAdapter();
  combined.releaseModal();
  assert.equal(await pendingCombined, false);
  assert.equal(combined.exits, 0);
  assert.equal(combined.writes, 0);
});


test('CX02E: v4 replaces B1 while retaining fullscreen Exit Guard', async () => {
	const f = fixture({dirty:false});
	assert.equal((await f.transition('different')).status,'blocked');
	const guard = read('assets/js/features/designer-exit-guard.js');
	const picker = read('assets/js/features/designer-context.js');
	const launch = read('assets/js/features/designer-launch.js');
	assert.match(guard,/contractVersion:4/);
	assert.match(picker,/guard\.transition\(shell, \{targetId: value\}\)/);
	assert.doesNotMatch(guard+picker+launch,
		/requestTransition|isTransitionDispatchAuthorized|cb:design-shell:contextrequest|cb:design-shell:contextchanged/);
	assert.match(guard,/const request = async/);
	assert.match(launch,/bindExitGuard\(shell, fullscreen\)/);
});

test('DF-EXIT-01: Discard is synchronous, verified and never saves', async () => {
	const ok = fixture({dirty:true, action:'discard'});
	assert.equal(await ok.request(), true);
	assert.equal(ok.discards, 1);
	assert.equal(ok.exits, 1);
	assert.equal(ok.writes, 0);
	for (const outcome of [false, undefined, Promise.resolve(true)]) {
		const failed = fixture({dirty:true, action:'discard', discardOutcome:outcome});
		assert.equal(await failed.request(), false);
		assert.equal(failed.discards, 1);
		assert.equal(failed.exits, 0);
	}
	const stale = fixture({dirty:true, action:'discard', discardStaysDirty:true});
	assert.equal(await stale.request(), false);
	assert.equal(stale.exits, 0);
});

test('DF-EXIT-01: Cancel never discards, UNKNOWN remains blocked, and clean close never discards', async () => {
	for (const input of [{dirty:true, accept:false}, {dirty:true, action:'discard', unknown:true}, {dirty:false}]) {
		const f = fixture(input);
		await f.request();
		assert.equal(f.discards, 0);
	}
});


test('DF-EXIT-01: pending modal suppresses shell interactions and restores them after Cancel', async () => {
	const f = fixture({dirty:true, action:'discard', accept:false, deferredModal:true});
	const pending = f.request();
	assert.equal(f.shell.inert, true);
	f.releaseModal();
	assert.equal(await pending, false);
	assert.equal(f.shell.inert, false);
	assert.equal(f.discards, 0);
	assert.equal(f.exits, 0);
});
