import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtemp, readFile, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {test} from 'node:test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const modules = await Promise.all([
    'icons.js', 'toolbar-popover.js', 'history.js',
].map(file => readFile(new URL('../../assets/js/design/shell/' + file, import.meta.url), 'utf8')));
const runtime = modules.map(src => src.replace(/^import .*$/gm, '').replace(/^export /gm, '')).join('\n');
const browser = process.env.CHROMIUM_BIN || ['chromium', 'chromium-browser', 'google-chrome'].find(name =>
    spawnSync(name, ['--version'], {encoding: 'utf8', timeout: 3000}).status === 0
);

function runFixture() {
    window.addEventListener('load', async () => {
        const root = document.querySelector('#native');
        const brand = document.querySelector('#brand-wordmark');
        const group = document.querySelector('#history-group');
        const undo = document.querySelector('[data-cb-design-shell-undo]');
        const redo = document.querySelector('[data-cb-design-shell-redo]');
        const original = [undo, redo];
        const actions = [];
        undo.addEventListener('click', () => actions.push('undo'));
        redo.addEventListener('click', () => actions.push('redo'));
        const scope = new AbortController();
        const controller = configureDesignerHistory(root, {signal: scope.signal});
        const shown = element => getComputedStyle(element).display !== 'none' &&
            getComputedStyle(element).visibility !== 'hidden';
        const pause = () => new Promise(resolve => setTimeout(resolve, 100));
        const record = () => {
            const trigger = group.querySelector('[data-cb-design-shell-history-trigger]');
            const options = group.querySelector('.cb-core-design-shell__history-options');
            const live = [...options.querySelectorAll('[data-cb-design-shell-undo], [data-cb-design-shell-redo]')];
            const bounds = options.getBoundingClientRect();
            return {
                compact: group.classList.contains('is-adaptive-compact'),
                brandVisible: shown(brand),
                triggerVisible: shown(trigger),
                optionsVisible: shown(options) && !options.hidden,
                expanded: trigger.getAttribute('aria-expanded'),
                triggerIcon: trigger.querySelector('[data-cb-design-shell-lucide-icon]')?.dataset.cbDesignShellLucideIcon,
                disabled: live.map(b => b.disabled),
                originalButtons: live.length === original.length && live.every(b => original.includes(b)),
                buttonOrder: live.map(b => b.hasAttribute('data-cb-design-shell-undo') ? 'undo' : 'redo'),
                direction: getComputedStyle(options).flexDirection,
                position: getComputedStyle(options).position,
                border: getComputedStyle(trigger).borderTopColor,
                bounds: {left: bounds.left, right: bounds.right},
            };
        };
        try {
            const report = {width: innerWidth, initial: record()};
            if (report.initial.compact) {
                controller.trigger.click(); await pause(); report.open = record();
                // A disabled original command cannot fire or dismiss the menu.
                undo.click(); report.disabledClick = record();
                redo.disabled = false;
                redo.focus(); redo.click(); await pause();
                report.chosen = record();
                report.focusReturned = document.activeElement === controller.trigger;
                controller.trigger.click();
                redo.focus();
                redo.dispatchEvent(new KeyboardEvent('keydown',
                    {key: 'Escape', bubbles: true, cancelable: true}));
                await pause(); report.escape = record();
                report.escapeFocus = document.activeElement === controller.trigger;
                controller.trigger.click();
                document.getElementById('outside').dispatchEvent(new Event('pointerdown', {bubbles: true}));
                report.outside = record();
            } else {
                redo.disabled = false;
                redo.click(); report.desktopClick = record();
            }
            report.actions = actions;
            scope.abort();
            report.aborted = controller.trigger.parentNode === null &&
                original.every(b => group.contains(b));
            document.documentElement.setAttribute('data-history-report',
                encodeURIComponent(JSON.stringify(report)));
        } catch (error) {
            document.documentElement.setAttribute('data-history-error', String(error?.stack || error));
        }
    });
}

function fixture() {
    const styles = ['editor-shell.css', 'designer-mode.css', 'designer-toolbar.css', 'platform-fullscreen.css']
        .map(file => '<link rel="stylesheet" href="' +
            pathToFileURL(join(root, 'assets/css/design', file)).href + '">').join('');
    return '<!doctype html><html><head><meta charset="utf-8">' +
        '<style>:root{--cb-control-height:40px;--cb-space-1:4px;--cb-space-2:8px;' +
        '--cb-space-3:12px;--cb-space-4:16px;--cb-border:#34404b;' +
        '--cb-surface-0:#0b0f16;--cb-surface-1:#181f28;--cb-surface-2:#26303e;' +
        '--cb-text:#eee;--cb-text-strong:#fff;--cb-accent:#00bfff;--cb-radius-md:8px;}' +
        'html,body{margin:0;color:white;background:#0b0f16;}' +
        '#native{width:100%;}#outside{margin-top:120px}</style>' +
        styles + '</head><body>' +
        '<div class="cb-core-platform-screen"><div class="cb-core-design-shell cb-core-design-shell--native" id="native">' +
        '<div class="cb-core-design-shell__toolbar cb-core-design-shell__toolbar--designer">' +
        '<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--start">' +
        '<div class="cb-core-design-shell__brand cb-core-brand-lockup" aria-label="Mail Designer, Core Blueprint">' +
        '<span class="cb-core-brand-lockup__mark"></span>' +
        '<span id="brand-wordmark" class="cb-core-design-shell__brand-wordmark">mail.designer</span></div></div>' +
        '<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--center"></div>' +
        '<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--end">' +
        '<div class="cb-core-design-shell__toolbar-group cb-core-design-shell__toolbar-group--operations">' +
        '<div class="cb-core-design-shell__toolbar-group" id="history-group" role="group" aria-label="History">' +
        '<button class="button cb-core-button cb-core-design-shell__icon-button" ' +
        'type="button" data-cb-design-shell-undo disabled>Undo</button>' +
        '<button class="button cb-core-button cb-core-design-shell__icon-button" ' +
        'type="button" data-cb-design-shell-redo disabled>Redo</button>' +
        '</div></div></div></div></div></div>' +
        '<button id="outside" type="button">Outside</button>' +
        '<script>' + runtime + '\n(' + runFixture.toString() + ')();</script></body></html>';
}

for (const width of [1600, 1281, 1280, 1024, 768, 700, 600, 375, 320]) {
    test('FIELD-06D Chromium shared History and brand at ' + width + 'px', async () => {
        assert.ok(browser, 'Chromium required; set CHROMIUM_BIN if necessary');
        const directory = await mkdtemp(join(tmpdir(), 'cb-history-field06d-'));
        try {
            const file = join(directory, 'fixture.html');
            await writeFile(file, fixture(), 'utf8');
            const run = spawnSync(browser, [
                '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
                '--allow-file-access-from-files', '--window-size=' + width + ',900',
                '--virtual-time-budget=4000', '--dump-dom', pathToFileURL(file).href,
            ], {encoding: 'utf8', timeout: 30000, maxBuffer: 4 * 1024 * 1024});
            assert.equal(run.status, 0, run.stderr || 'Chromium failed');
            const report = run.stdout.match(/data-history-report="([^"]+)"/);
            const error = run.stdout.match(/data-history-error="([^"]+)"/);
            assert.ok(report, 'Missing History report: ' + (error?.[1] || run.stderr));
            const v = JSON.parse(decodeURIComponent(report[1]));
            const compact = width <= 1280;
            assert.equal(v.initial.compact, compact);
            assert.equal(v.initial.brandVisible, !compact);
            assert.equal(v.initial.triggerVisible, compact);
            assert.equal(v.initial.optionsVisible, !compact);
            assert.equal(v.initial.triggerIcon, 'history');
            assert.equal(v.initial.originalButtons, true);
            assert.deepEqual(v.initial.buttonOrder, ['undo', 'redo']);
            assert.deepEqual(v.initial.disabled, [true, true]);
            if (compact) {
                assert.equal(v.open.expanded, 'true');
                assert.equal(v.open.optionsVisible, true);
                assert.equal(v.open.direction, 'row');
                if (width <= 700) assert.equal(v.open.position, 'fixed');
                assert.equal(v.open.border, 'rgb(0, 191, 255)');
                assert.ok(v.open.bounds.left >= 7 && v.open.bounds.right <= v.width - 7,
                    'History popup must remain inside the actual Chromium CSS viewport');
                assert.equal(v.disabledClick.optionsVisible, true);
                assert.equal(v.chosen.optionsVisible, false);
                assert.equal(v.chosen.expanded, 'false');
                assert.equal(v.focusReturned, true);
                assert.equal(v.escape.optionsVisible, false);
                assert.equal(v.escapeFocus, true);
                assert.equal(v.outside.optionsVisible, false);
            }
            assert.deepEqual(v.actions, ['redo']);
            assert.equal(v.aborted, true);
        } finally {
            await rm(directory, {recursive: true, force: true});
        }
    });
}
