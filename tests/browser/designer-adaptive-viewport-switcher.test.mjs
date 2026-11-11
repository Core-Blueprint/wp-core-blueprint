import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtemp, readFile, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {test} from 'node:test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const iconsSource = await readFile(new URL('../../assets/js/design/shell/icons.js', import.meta.url), 'utf8');
const viewportSource = await readFile(new URL('../../assets/js/design/shell/viewports.js', import.meta.url), 'utf8');
const runtime = iconsSource.replace(/^export /gm, '') + '\n' +
    viewportSource.replace(/^import .*$/gm, '').replace(/^export /gm, '');
const browser = process.env.CHROMIUM_BIN || ['chromium', 'chromium-browser', 'google-chrome'].find(name =>
    spawnSync(name, ['--version'], {encoding: 'utf8', timeout: 3000}).status === 0
);

function inspect() {
    window.addEventListener('load', async () => {
        const root = document.getElementById('native');
        const toolbar = root.querySelector('.cb-core-design-shell__toolbar--designer');
        const group = root.querySelector('[data-cb-design-shell-viewport-group]');
        const buttons = [...group.querySelectorAll('[data-cb-design-shell-viewport]')];
        const original = [...buttons];
        const eventLog = [];
        const signal = new AbortController();
        root.addEventListener('cb:design-shell:viewportchange', e => eventLog.push(e.detail.viewport));
        const controller = configureDesignerViewports(root, {
            active: 'desktop', signal: signal.signal,
        });
        const pause = () => new Promise(resolve => setTimeout(resolve, 250));
        const shown = element => {
            const css = getComputedStyle(element);
            return css.display !== 'none' && css.visibility !== 'hidden';
        };
        const record = () => {
            const trigger = group.querySelector('[data-cb-design-shell-viewport-trigger]');
            const options = group.querySelector('.cb-core-design-shell__viewport-options');
            // Snapshot the live DOM after the component has sorted and moved
            // its original button nodes. The fixture's initial array is
            // deliberately unsorted and cannot prove rendered order.
            const ordered = [...options.querySelectorAll('[data-cb-design-shell-viewport]')];
            return {
                compact: group.classList.contains('is-viewport-compact'),
                triggerVisible: shown(trigger),
                optionsVisible: shown(options) && !options.hidden,
                expanded: trigger.getAttribute('aria-expanded'),
                active: controller.activeViewport,
                sameElements: ordered.length === original.length
                    && new Set(ordered).size === original.length
                    && ordered.every(b => original.includes(b) && options.contains(b)),
                buttonOrder: ordered.map(b => b.dataset.cbDesignShellViewport),
                pressed: ordered.map(b => b.getAttribute('aria-pressed')),
                optionsColumns: getComputedStyle(options).flexDirection,
                optionsBounds: options.getBoundingClientRect().toJSON(),
            };
        };
        try {
            await pause();
            const trigger = group.querySelector('[data-cb-design-shell-viewport-trigger]');
            const options = group.querySelector('.cb-core-design-shell__viewport-options');
            const report = {width: innerWidth, initial: record(),
                triggerIcon: trigger.querySelector('[data-cb-design-shell-lucide-icon]')?.dataset.cbDesignShellLucideIcon};
            if (report.initial.compact) {
                trigger.click(); await pause(); report.open = record();
                const mobile = buttons.find(b => b.dataset.cbDesignShellViewport === 'mobile');
                mobile.focus(); mobile.click(); await pause(); report.selected = record();
                report.selectionFocus = document.activeElement === trigger;
                trigger.click(); await pause();
                report.reopened = record();
                const tablet = buttons.find(b => b.dataset.cbDesignShellViewport === 'tablet');
                tablet.focus();
                tablet.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
                await pause(); report.escape = record();
                report.escapeFocus = document.activeElement === trigger;
                trigger.click(); await pause();
                document.querySelector('#outside').dispatchEvent(new Event('pointerdown', {bubbles:true}));
                await pause(); report.outside = record();
            } else {
                const mobile = buttons.find(b => b.dataset.cbDesignShellViewport === 'mobile');
                mobile.click(); report.inlineSelected = record();
            }
            report.changeEvents = eventLog;
            report.afterReconfigureSameController = configureDesignerViewports(root, {active:'desktop'}) === controller;
            report.reconfigured = record();
            signal.abort();
            report.destroyedOnAbort = controller.activate('mobile') === false;
            document.documentElement.setAttribute('data-viewport-report',
                encodeURIComponent(JSON.stringify(report)));
        } catch (e) {
            document.documentElement.setAttribute('data-viewport-error', String(e?.stack || e));
        }
    });
}

function fixture() {
    const styleFiles = ['editor-shell.css', 'designer-mode.css', 'designer-toolbar.css', 'platform-fullscreen.css']
        .map(name => '<link rel="stylesheet" href="' +
            pathToFileURL(join(root, 'assets/css/design', name)).href + '">').join('');
    return '<!doctype html><html><head><meta charset="utf-8">' +
        '<style>:root{--cb-control-height:40px;--cb-space-1:4px;--cb-space-2:8px;' +
        '--cb-space-3:12px;--cb-space-4:16px;--cb-border:#34404b;' +
        '--cb-surface-0:#0b0f16;--cb-surface-1:#181f28;--cb-surface-2:#26303e;' +
        '--cb-text:#eee;--cb-text-strong:#fff;--cb-accent:#00bfff;' +
        '--cb-radius-md:8px;}' +
        'html,body{margin:0;min-height:100%;background:#0b0f16;color:white;}' +
        'body{padding:8px;box-sizing:border-box;}' +
        '#native{width:100%;box-sizing:border-box;}' +
        '#outside{margin-top:180px;}</style>' + styleFiles + '</head><body>' +
        '<div class="cb-core-design-shell cb-core-design-shell--native" id="native">' +
        '<div class="cb-core-design-shell__toolbar cb-core-design-shell__toolbar--designer">' +
        '<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--start"' +
        ' style="width:290px;flex:0 0 290px">Brand and sidebar</div>' +
        '<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--center">' +
        '<div class="cb-core-design-shell__toolbar-group" data-cb-design-shell-viewport-group' +
        ' role="group" aria-label="Preview width">' +
        '<button class="button cb-core-button" type="button" data-cb-design-shell-viewport="tablet">Tablet</button>' +
        '<button class="button cb-core-button" type="button" data-cb-design-shell-viewport="desktop" aria-pressed="true">Desktop</button>' +
        '<button class="button cb-core-button" type="button" data-cb-design-shell-viewport="mobile">Mobile</button>' +
        '</div></div>' +
        '<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--end"' +
        ' style="width:320px;flex:0 0 320px">Browser / Undo / Redo / Save / Close</div>' +
        '</div></div><button type="button" id="outside">Outside</button>' +
        '<script>' + runtime + '\n(' + inspect.toString() + ')();</script></body></html>';
}

for (const width of [1600, 1024, 768, 600]) {
    test('FIELD-06C Chromium viewport group at ' + width + 'px', async () => {
        assert.ok(browser, 'A locally installed Chromium is required; set CHROMIUM_BIN if needed.');
        const temp = await mkdtemp(join(tmpdir(), 'cb-viewport-field06c-'));
        try {
            const file = join(temp, 'viewport.html');
            await writeFile(file, fixture(), 'utf8');
            const run = spawnSync(browser, [
                '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
                '--allow-file-access-from-files', '--window-size=' + width + ',900',
                '--virtual-time-budget=5200', '--dump-dom', pathToFileURL(file).href,
            ], {encoding:'utf8', timeout:30000, maxBuffer:4 * 1024 * 1024});
            assert.equal(run.status, 0, run.stderr || 'Chromium failed');
            const encoded = run.stdout.match(/data-viewport-report="([^"]+)"/);
            const error = run.stdout.match(/data-viewport-error="([^"]+)"/);
            assert.ok(encoded, 'No viewport report: ' + (error?.[1] || run.stderr));
            const v = JSON.parse(decodeURIComponent(encoded[1]));
            const expectCompact = width <= 768;
            assert.equal(v.initial.compact, expectCompact, JSON.stringify(v.initial));
            assert.equal(v.initial.triggerVisible, expectCompact);
            assert.equal(v.initial.optionsVisible, !expectCompact);
            assert.equal(v.initial.active, 'desktop');
            assert.deepEqual(v.initial.buttonOrder, ['mobile','tablet','desktop']);
            assert.deepEqual(v.initial.pressed, ['false','false','true']);
            assert.equal(v.initial.sameElements, true);
            assert.equal(v.triggerIcon, 'monitor-smartphone');
            if (expectCompact) {
                assert.equal(v.open.expanded, 'true');
                assert.equal(v.open.optionsVisible, true);
                assert.equal(v.open.optionsColumns, 'row');
                assert.ok(v.open.optionsBounds.left >= 7,
                    'The popover must remain within the viewport left edge');
                assert.ok(v.open.optionsBounds.right <= v.width - 7,
                    'The popover must remain within the viewport right edge');
                assert.equal(v.selected.active, 'mobile');
                assert.equal(v.selected.optionsVisible, false);
                assert.equal(v.selected.expanded, 'false');
                assert.equal(v.selectionFocus, true);
                assert.equal(v.reopened.optionsVisible, true);
                assert.equal(v.escape.optionsVisible, false);
                assert.equal(v.escapeFocus, true);
                assert.equal(v.outside.optionsVisible, false);
                assert.deepEqual(v.changeEvents, ['mobile']);
            } else {
                assert.equal(v.inlineSelected.active, 'mobile');
                assert.deepEqual(v.changeEvents, ['mobile']);
            }
            assert.equal(v.afterReconfigureSameController, true);
            assert.equal(v.reconfigured.active, 'desktop');
            assert.equal(v.destroyedOnAbort, true);
        } finally {
            await rm(temp, {recursive:true, force:true});
        }
    });
}
