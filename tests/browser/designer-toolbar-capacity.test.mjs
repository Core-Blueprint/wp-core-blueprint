import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { test } from 'node:test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const browser = process.env.CHROMIUM_BIN || ['chromium', 'chromium-browser', 'google-chrome'].find((name) =>
	spawnSync(name, ['--version'], { encoding: 'utf8', timeout: 3000 }).status === 0
);

function setupBrowser() {
	window.cbCoreDesignerLaunch = { toolbarLabels: { view: 'View', actions: 'Actions', tools: 'Tools', action: 'Action' } };
	window.cbCore = { designEditor: { shell: { icons: { decorate(button, icon, options = {}) {
		button.dataset.fixtureIcon = icon;
		if (options.label) button.setAttribute('aria-label', options.label);
	} } } } };
	window.fixtureClicks = 0;
	document.querySelectorAll('[data-cb-design-shell-toolbar-extension] button').forEach((button) => {
		button.addEventListener('click', () => { window.fixtureClicks += 1; });
	});
}

function inspectBrowser() {
	window.addEventListener('load', () => setTimeout(() => {
		const toolbar = document.querySelector('[data-cb-design-shell-header]');
		const save = toolbar.querySelector('[data-cb-design-shell-primary-action]');
		const close = toolbar.querySelector('[data-cb-design-shell-close]');
		const shown = (el) => Boolean(el && el.getClientRects().length && getComputedStyle(el).display !== 'none');
		const zones = [...toolbar.querySelectorAll('.cb-core-design-shell__toolbar-zone')];
		const tools = toolbar.querySelector('[data-cb-design-shell-compact-menu-trigger="actions"]');
		const result = {
			booted: toolbar.dataset.cbDesignShellCompactToolbar === 'true',
			saveVisible: shown(save), closeVisible: shown(close),
			saveCloseOrdered: close.getBoundingClientRect().right <= save.getBoundingClientRect().left + 1,
			overflow: toolbar.scrollWidth > toolbar.clientWidth + 1 ||
				zones.some((zone) => zone.scrollWidth > zone.clientWidth + 1),
			toolsAvailable: shown(tools),
			longTooltipVisible: [...toolbar.querySelectorAll('button')].some((el) =>
				el.textContent.includes('DO NOT SHOW THIS TOOLTIP')),
		};
		const inline = toolbar.querySelector('.cb-core-design-shell__toolbar-extension-action[data-cb-design-shell-action-id="fixture.action-0"]');
		result.inlineAvailable = shown(inline);
		if (shown(inline)) inline.click();
		else if (shown(tools)) {
			tools.click();
			const first = toolbar.querySelector('[data-cb-design-shell-compact-menu-popover="actions"] [data-cb-design-shell-action-id="fixture.action-0"]');
			if (shown(first)) first.click();
			tools.click();
			document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
		}
		setTimeout(() => {
			result.actionTriggered = window.fixtureClicks;
			result.menuDismissed = !tools || tools.getAttribute('aria-expanded') === 'false';
			document.documentElement.setAttribute('data-capacity-report', encodeURIComponent(JSON.stringify(result)));
		}, 120);
	}, 250));
}

function fixture(width, actionCount) {
	const css = ['editor-shell.css', 'designer-mode.css', 'designer-toolbar.css']
		.map((name) => '<link rel="stylesheet" href="' +
			pathToFileURL(join(root, 'assets/css/design', name)).href + '">').join('');
	const actions = Array.from({ length: actionCount }, (_, i) =>
		'<button type="button" data-cb-design-shell-action-id="fixture.action-' + i + '"' +
		' data-cb-design-shell-action-label="Action ' + i + '"' +
		' data-cb-design-shell-action-priority="' + (i % 3 === 0 ? 'contextual' : 'utility') + '"' +
		' title="DO NOT SHOW THIS TOOLTIP: a long detailed explanation">Action ' + i + '</button>'
	).join('');
	const toolbarScript = pathToFileURL(join(root, 'assets/js/features/designer-toolbar.js')).href;
	return '<!doctype html><html><head><meta charset="utf-8"><style>' +
		':root{--cb-space-1:4px;--cb-space-2:8px;--cb-space-3:12px;--cb-space-4:16px;' +
		'--cb-control-height:36px;--cb-border:#999;--cb-radius-md:4px;--cb-surface-1:white;' +
		'--cb-surface-2:#eee;--cb-text:#222;--cb-text-strong:#111;--cb-fs-sm:12px;--cb-accent:#06f;}' +
		'html,body{margin:0;padding:0}.fixture{width:' + width + 'px}' +
		'.button{font:14px Arial;white-space:nowrap;min-height:36px;padding:3px 8px}</style>' + css +
		'</head><body><div class="fixture" data-cb-design-launch-root><div data-cb-design-shell>' +
		'<div class="cb-core-design-shell__toolbar cb-core-design-shell__toolbar--designer" data-cb-design-shell-header="true">' +
		'<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--start">' +
		'<span class="cb-core-design-shell__brand">Core Blueprint Designer</span>' +
		'<label class="cb-core-design-shell__toolbar-context"><select data-cb-design-shell-context>' +
		'<option>Long active assessment name</option></select></label></div>' +
		'<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--center">' +
		'<div class="cb-core-design-shell__toolbar-group cb-core-design-shell__toolbar-group--viewport">' +
		'<button class="button" data-cb-design-shell-viewport="desktop">Desktop</button></div></div>' +
		'<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--end">' +
		'<div class="cb-core-design-shell__toolbar-group cb-core-design-shell__toolbar-group--history">' +
		'<button class="button" data-cb-design-shell-undo>Undo</button>' +
		'<button class="button" data-cb-design-shell-redo>Redo</button></div>' +
		'<button class="button" data-cb-design-shell-close>Close</button>' +
		'<button class="button" data-cb-design-shell-primary-action>Save</button></div></div>' +
		'<div data-cb-design-shell-toolbar-extension="actions">' + actions + '</div></div></div>' +
		'<script>(' + setupBrowser.toString() + ')()</script>' +
		'<script src="' + toolbarScript + '"></script>' +
		'<script>(' + inspectBrowser.toString() + ')()</script></body></html>';
}

for (const scenario of [
	{ width: 1600, count: 0 },
	{ width: 1280, count: 1 },
	{ width: 800, count: 12 },
	{ width: 600, count: 12 },
	{ width: 360, count: 12 },
]) {
	test('Chromium toolbar: ' + scenario.width + 'px, ' + scenario.count + ' actions', async () => {
		assert.ok(browser, 'Real Chromium is mandatory; set CHROMIUM_BIN if not on PATH.');
		const temp = await mkdtemp(join(tmpdir(), 'cb-toolbar-browser-'));
		try {
			const page = join(temp, 'toolbar.html');
			await writeFile(page, fixture(scenario.width, scenario.count), 'utf8');
			const run = spawnSync(browser, [
				'--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
				'--window-size=1800,1000', '--virtual-time-budget=2500', '--dump-dom',
				pathToFileURL(page).href,
			], { encoding: 'utf8', timeout: 30000, maxBuffer: 4 * 1024 * 1024 });
			assert.equal(run.status, 0, run.stderr || 'Chromium failed');
			const match = run.stdout.match(/data-capacity-report="([^"]+)"/);
			assert.ok(match, 'Browser did not emit the toolbar report');
			const actual = JSON.parse(decodeURIComponent(match[1]));
			assert.equal(actual.booted, true, 'Toolbar initialization');
			assert.equal(actual.saveVisible, true, 'Save must remain visible');
			assert.equal(actual.closeVisible, true, 'Close must remain visible');
			assert.equal(actual.saveCloseOrdered, true, 'Save and Close must not overlap');
			assert.equal(actual.overflow, false, 'Toolbar or one of its zones overflows');
			assert.equal(actual.longTooltipVisible, false, 'Tooltip must not become button text');
			assert.equal(actual.menuDismissed, true, 'Tools must close after command/Escape');
			if (scenario.count) {
				assert.equal(actual.toolsAvailable || actual.inlineAvailable, true, 'Action must be reachable inline or in Tools');
				assert.equal(actual.actionTriggered, 1, 'Original command must execute once');
			}
		} finally {
			await rm(temp, { recursive: true, force: true });
		}
	});
}
