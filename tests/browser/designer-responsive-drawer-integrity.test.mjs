import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, rm, writeFile, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { test } from 'node:test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const editor = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
const nativeDrawer = editor.match(/^const configurePlatformSidebars = \(root, signal\) => \{[\s\S]*?^\};/m)?.[0];
assert.ok(nativeDrawer, 'Missing canonical native Designer drawer controller');
assert.match(editor, /const backdrop = button\('', 'data-cb-design-shell-drawer-backdrop'\)/);
assert.match(editor, /backdrop\.setAttribute\('aria-label', 'Close sidebar'\)/);

const chromium = process.env.CHROMIUM_BIN || ['chromium', 'chromium-browser', 'google-chrome'].find(name =>
    spawnSync(name, ['--version'], {encoding: 'utf8', timeout: 3000}).status === 0
);

function inspectBrowser() {
    window.addEventListener('load', async () => {
        const root = document.getElementById('inner-shell');
        const left = root.querySelector('.cb-core-design-shell__palette');
        const right = root.querySelector('.cb-core-design-shell__sidebar');
        const backdrop = root.querySelector('[data-cb-design-shell-drawer-backdrop]');
        const leftLauncher = root.querySelector('[data-cb-design-shell-drawer-toggle="left"]');
        const rightLauncher = root.querySelector('[data-cb-design-shell-drawer-toggle="right"]');
        const visible = el => getComputedStyle(el).visibility === 'visible';
        const pause = () => new Promise(resolve => setTimeout(resolve, 220));
        const snap = () => ({
            leftVisible: visible(left), rightVisible: visible(right),
            leftCollapsed: root.classList.contains('is-left-panel-collapsed'),
            rightCollapsed: root.classList.contains('is-right-panel-collapsed'),
            backdropHidden: backdrop.hidden, backdropText: backdrop.textContent.trim(),
            backdropName: backdrop.getAttribute('aria-label'),
            leftInert: left.hasAttribute('inert'), rightInert: right.hasAttribute('inert'),
            leftAriaHidden: left.getAttribute('aria-hidden'),
            rightAriaHidden: right.getAttribute('aria-hidden'),
            launcherExpanded: rightLauncher.getAttribute('aria-expanded'),
        });
        try {
            configurePlatformSidebars(root, new AbortController().signal);
            await pause();
            const report = {
                width: innerWidth, compact: matchMedia('(max-width: 1280px)').matches,
                initial: snap(), rightLauncherDisplay: getComputedStyle(rightLauncher).display,
            };
            if (report.compact) {
                rightLauncher.click(); await pause(); report.rightOpen = snap();
                right.dispatchEvent(new KeyboardEvent('keydown', {
                    key: 'Escape', bubbles: true, cancelable: true,
                }));
                await pause(); report.escapeClosed = snap();
                report.focusReturnedOnEscape = document.activeElement === rightLauncher;
                leftLauncher.click(); await pause(); report.leftOpen = snap();
                backdrop.click(); await pause(); report.backdropClosed = snap();
                report.focusReturnedOnBackdrop = document.activeElement === leftLauncher;
            }
            document.documentElement.setAttribute('data-drawer-report',
                encodeURIComponent(JSON.stringify(report)));
        } catch (error) {
            document.documentElement.setAttribute('data-drawer-error', String(error?.stack || error));
        }
    });
}

function fixture() {
    const styles = ['editor-shell.css', 'designer-mode.css', 'designer-toolbar.css', 'platform-fullscreen.css']
        .map(name => '<link rel="stylesheet" href="' +
            pathToFileURL(join(root, 'assets/css/design', name)).href + '">').join('');
    // The real trusted Host mounts a native inner shell inside a leased outer
    // shell. A descendant :not(.is-left-panel-collapsed) selector on the outer
    // shell previously overrode the inner shell's collapsed visibility.
    return '<!doctype html><html><head><meta charset="utf-8">' +
        '<style>:root{--cb-space-1:4px;--cb-space-2:8px;--cb-space-3:12px;' +
        '--cb-space-4:16px;--cb-space-5:20px;--cb-border:#333;' +
        '--cb-surface-1:#14171d;--cb-surface-2:#222;--cb-text-strong:#fff;' +
        '--cb-radius-md:6px;--cb-accent:#00bfff;}' +
        'html,body{margin:0}body{color:white;background:#0b0d12;}' +
        '.cb-core-platform-screen{position:relative;inset:auto;height:650px;width:100%;}' +
        '.cb-core-design-shell__workspace--collapsible{height:530px;}' +
        '.cb-core-design-shell__canvas{background:#eee;min-width:0;}</style>' + styles +
        '</head><body><div class="cb-core-platform-screen" data-cb-platform-mounted="true">' +
        '<div class="cb-core-design-shell cb-core-form-scope" id="outer-host">' +
        '<div data-cb-platform-mount>' +
        '<div class="cb-core-design-shell cb-core-design-shell--native" data-cb-platform-canvas id="inner-shell">' +
        '<div class="cb-core-design-shell__toolbar cb-core-design-shell__toolbar--designer">' +
        '<button type="button" class="cb-core-design-shell__drawer-launcher"' +
        ' data-cb-design-shell-drawer-toggle="left">Elements</button>' +
        '<button type="button" class="cb-core-design-shell__drawer-launcher"' +
        ' data-cb-design-shell-drawer-toggle="right">Inspector</button>' +
        '</div><div class="cb-core-design-shell__workspace cb-core-design-shell__workspace--collapsible">' +
        '<section class="cb-core-design-shell__palette cb-core-design-shell__palette--tabbed">' +
        '<div class="cb-core-design-shell__panel-header"><button type="button"' +
        ' data-cb-design-shell-panel-toggle="left">Collapse elements</button></div>Elements</section>' +
        '<main class="cb-core-design-shell__canvas">Preview</main>' +
        '<aside class="cb-core-design-shell__sidebar cb-core-design-shell__sidebar--composed">' +
        '<div class="cb-core-design-shell__panel-header"><button type="button"' +
        ' data-cb-design-shell-panel-toggle="right">Collapse properties</button></div>Properties</aside>' +
        '<button type="button" class="cb-core-design-shell__drawer-backdrop"' +
        ' data-cb-design-shell-drawer-backdrop aria-label="Close sidebar"></button>' +
        '</div></div></div></div></div>' +
        '<script>const decorateDesignerControl=(el,icon,opts)=>{if(opts.label)el.setAttribute("aria-label",opts.label);};' +
        nativeDrawer + '\n(' + inspectBrowser.toString() + ')();</script></body></html>';
}

for (const width of [1281, 1280, 1263, 768]) {
    test('FIELD-06 Chromium nested native Designer drawer at ' + width + 'px', async () => {
        assert.ok(chromium, 'Real Chromium is required. Set CHROMIUM_BIN if necessary.');
        const temp = await mkdtemp(join(tmpdir(), 'cb-drawer-field06-'));
        try {
            const page = join(temp, 'drawer.html');
            await writeFile(page, fixture(), 'utf8');
            const run = spawnSync(chromium, [
                '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
                '--allow-file-access-from-files', '--window-size=' + width + ',900',
                '--virtual-time-budget=4500', '--dump-dom', pathToFileURL(page).href,
            ], {encoding: 'utf8', timeout: 30000, maxBuffer: 4 * 1024 * 1024});
            assert.equal(run.status, 0, run.stderr || 'Chromium failed');
            const result = run.stdout.match(/data-drawer-report="([^"]+)"/);
            const error = run.stdout.match(/data-drawer-error="([^"]+)"/);
            assert.ok(result, 'Browser report missing: ' + (error?.[1] || run.stderr));
            const value = JSON.parse(decodeURIComponent(result[1]));
            assert.equal(value.compact, width <= 1280);
            assert.equal(value.initial.leftVisible, width > 1280);
            assert.equal(value.initial.rightVisible, width > 1280);
            assert.equal(value.initial.backdropHidden, true);
            assert.equal(value.initial.backdropText, '');
            assert.equal(value.initial.backdropName, 'Close sidebar');
            if (width <= 1280) {
                assert.equal(value.initial.leftInert, true);
                assert.equal(value.initial.rightInert, true);
                assert.equal(value.initial.leftAriaHidden, 'true');
                assert.equal(value.initial.rightAriaHidden, 'true');
                assert.equal(value.rightOpen.leftVisible, false,
                    'Outer uncollapsed Host must not expose the inner collapsed palette');
                assert.equal(value.rightOpen.rightVisible, true);
                assert.equal(value.rightOpen.backdropHidden, false);
                assert.equal(value.rightOpen.leftInert, true);
                assert.equal(value.rightOpen.rightInert, false);
                assert.equal(value.rightOpen.launcherExpanded, 'true');
                assert.equal(value.escapeClosed.leftVisible, false);
                assert.equal(value.escapeClosed.rightVisible, false);
                assert.equal(value.escapeClosed.backdropHidden, true);
                assert.equal(value.focusReturnedOnEscape, true);
                assert.equal(value.leftOpen.leftVisible, true);
                assert.equal(value.leftOpen.rightVisible, false);
                assert.equal(value.backdropClosed.backdropHidden, true);
                assert.equal(value.backdropClosed.leftVisible, false);
                assert.equal(value.backdropClosed.rightVisible, false);
                assert.equal(value.focusReturnedOnBackdrop, true);
            } else {
                assert.equal(value.rightLauncherDisplay, 'none');
                assert.equal(value.initial.leftInert, false);
                assert.equal(value.initial.rightInert, false);
            }
        } finally {
            await rm(temp, {recursive: true, force: true});
        }
    });
}
