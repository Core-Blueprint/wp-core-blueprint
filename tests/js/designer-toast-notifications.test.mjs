import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const readSource = async (relative) => readFile(new URL('../../' + relative, import.meta.url), 'utf8');

const instantiateSaveBinding = async (window, config = {}) => {
	const source = await readSource('assets/js/features/designer-launch.js');
	const start = source.indexOf('const bindSaveState = (shell, save) => {');
	const end = source.indexOf('\n\tconst panelToggleLabel', start);
	assert.ok(start >= 0 && end > start, 'real shared Designer Save binding exists');
	return new Function('window', 'config', 'SAVE_EVENT',
		`${source.slice(start, end)}\nreturn bindSaveState;`
	)(window, config, 'cb:design-shell:savechange');
};

const fakeShell = () => {
	const events = new Map();
	const attributes = new Map();
	return {
		shell: {
			dataset: {},
			addEventListener(type, listener) { events.set(type, listener); },
		},
		save: {
			disabled: false,
			setAttribute(key, value) { attributes.set(key, value); },
			removeAttribute(key) { attributes.delete(key); },
		},
		emit(state, message = '') {
			assert.ok(events.has('cb:design-shell:savechange'));
			events.get('cb:design-shell:savechange')({ detail: { state, message } });
		},
		attributes,
	};
};

test('shared Designer keeps saving and idle states silent and only toasts confirmed outcomes', async () => {
	const notifications = [];
	const window = { cbCore: { toast: {
		success: (message) => notifications.push(['success', message]),
		error: (message) => notifications.push(['error', message]),
	} } };
	const bind = await instantiateSaveBinding(window, { toastLabels: {
		saved: 'Saved (locale)', error: 'Failed (locale)',
	} });
	const scenario = fakeShell();
	bind(scenario.shell, scenario.save);
	scenario.emit('saving');
	assert.equal(scenario.save.disabled, true);
	assert.equal(scenario.attributes.get('aria-busy'), 'true');
	assert.deepEqual(notifications, [], 'pending saves must not produce toast spam');
	scenario.emit('saved', 'Committed by server');
	assert.equal(scenario.save.disabled, false);
	assert.equal(scenario.attributes.has('aria-busy'), false);
	assert.deepEqual(notifications, [['success', 'Committed by server']]);
	scenario.emit('error', 'Revision conflict');
	assert.deepEqual(notifications.at(-1), ['error', 'Revision conflict']);
	scenario.emit('idle');
	assert.equal(notifications.length, 2);
	assert.equal(scenario.shell.dataset.cbDesignShellSaveState, 'idle');
	scenario.emit('saved');
	scenario.emit('error');
	assert.deepEqual(notifications.slice(-2), [['success', 'Saved (locale)'], ['error', 'Failed (locale)']]);
});

test('shared Designer notifies even on shells without a primary save control', async () => {
	const notifications = [];
	const window = { cbCore: { toast: {
		success: m => notifications.push(['success', m]),
		error: m => notifications.push(['error', m]),
	} } };
	const bind = await instantiateSaveBinding(window);
	const scenario = fakeShell();
	bind(scenario.shell, null);
	scenario.emit('error', 'Save unavailable');
	assert.deepEqual(notifications, [['error', 'Save unavailable']]);
});

test('Designer shell displays no transient toolbar messages and preserves local preview status', async () => {
	const launch = await readSource('assets/js/features/designer-launch.js');
	const mail = await readSource('templates/mail-designer.php');
	const reports = await readSource('templates/preferences-reports.php');
	const mailRuntime = await readSource('assets/js/features/mail-designer.js');
	const reportsRuntime = await readSource('assets/js/features/reports-preferences.js');
	const contextRuntime = await readSource('assets/js/features/designer-context.js');
	const toastStyles = await readSource('assets/css/components/toasts.css');
	const assets = await readSource('src/Design/Editor/Assets.php');
	assert.equal(launch.includes('end.append(status)'), false);
	assert.equal(launch.includes('status.textContent = message'), false);
	assert.equal(mail.includes('data-cb-design-shell-status'), false);
	assert.equal(reports.includes('data-cb-design-shell-status'), false);
	assert.ok(mail.includes('screen-reader-text" data-cb-mail-preview-status aria-live="polite"'));
	assert.ok(mailRuntime.includes('window.cbCore?.toast?.error?.(message)'));
	assert.ok(contextRuntime.includes("window.cbCore?.toast?.error?.(error?.message || label('error', 'Could not load selection.'))"));
	assert.equal(contextRuntime.includes("setTransition(shell, 'error'"), false);
	assert.ok(reportsRuntime.includes("if ( kind === 'error' && message ) window.cbCore?.toast?.error?.( message )"));
	assert.ok(assets.includes('Assets::enqueue_toasts('));
	assert.ok(assets.includes('Assets::TOAST_PRESENTATION_CORE'));
	assert.ok(toastStyles.includes('body:has(.cb-core-design-shell.is-fullscreen) .cb-core-toast-container--core'));
	assert.ok(toastStyles.includes('z-index: 100110;'));
});
