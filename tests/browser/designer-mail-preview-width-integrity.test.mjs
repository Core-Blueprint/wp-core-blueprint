import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtemp, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {test} from 'node:test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const browser = process.env.CHROMIUM_BIN || ['chromium', 'chromium-browser', 'google-chrome'].find(name =>
    spawnSync(name, ['--version'], {encoding: 'utf8', timeout: 3000}).status === 0
);

function inspect(documentWidth) {
    const shell = document.getElementById('mail-shell');
    const frame = document.getElementById('mail-frame');
    // Mirror the canonical renderer's project.root.properties.width binding.
    frame.style.maxWidth = documentWidth + 'px';
    frame.style.margin = '0 auto';

    const widths = {};
    for (const mode of ['desktop', 'tablet', 'mobile']) {
        shell.dataset.cbDesignShellViewport = mode;
        widths[mode] = {
            rendered: frame.getBoundingClientRect().width,
            computedWidth: getComputedStyle(frame).width,
            computedMaxWidth: getComputedStyle(frame).maxWidth,
        };
    }
    document.documentElement.setAttribute('data-mail-width-report',
        encodeURIComponent(JSON.stringify(widths)));
}

function fixture(documentWidth, workareaWidth) {
    const css = [
        'assets/css/pages/mail-designer.css',
        'assets/css/design/platform-fullscreen.css',
    ].map(path => '<link rel="stylesheet" href="' +
        pathToFileURL(join(root, path)).href + '">').join('');
    return '<!doctype html><html><head><meta charset="utf-8">' +
        '<style>html,body{margin:0;background:#111;}' +
        '#mail-shell{width:' + workareaWidth + 'px;max-width:calc(100vw - 32px);}' +
        '</style>' + css + '</head><body>' +
        '<div class="cb-core-design-shell--native" id="mail-shell">' +
        '<div class="cb-mail-platform"><article class="cb-mail-platform__frame" id="mail-frame">' +
        'Email content</article></div></div>' +
        '<script>(' + inspect.toString() + ')(' + documentWidth + ');</script>' +
        '</body></html>';
}

for (const scenario of [
    {documentWidth: 320, workareaWidth: 1100, expected: [320, 320, 320]},
    {documentWidth: 600, workareaWidth: 1100, expected: [600, 600, 430]},
    {documentWidth: 800, workareaWidth: 1100, expected: [800, 782, 430]},
    {documentWidth: 800, workareaWidth: 460, expected: [460, 460, 430]},
]) {
    const {documentWidth, workareaWidth, expected} = scenario;
    test('FIELD-06C R3 Chromium Mail ' + documentWidth + 'px document in ' +
        workareaWidth + 'px canvas', async () => {
        assert.ok(browser, 'Chromium required; set CHROMIUM_BIN if necessary.');
        const temp = await mkdtemp(join(tmpdir(), 'cb-mail-width-field06c-'));
        try {
            const file = join(temp, 'mail-width.html');
            await writeFile(file, fixture(documentWidth, workareaWidth), 'utf8');
            const run = spawnSync(browser, [
                '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
                '--allow-file-access-from-files', '--window-size=1600,900',
                '--virtual-time-budget=1500', '--dump-dom', pathToFileURL(file).href,
            ], {encoding: 'utf8', timeout: 30000, maxBuffer: 4 * 1024 * 1024});
            assert.equal(run.status, 0, run.stderr || 'Chromium failed');
            const match = run.stdout.match(/data-mail-width-report="([^"]+)"/);
            assert.ok(match, 'No Mail preview width report: ' + run.stderr);
            const result = JSON.parse(decodeURIComponent(match[1]));
            const actual = ['desktop', 'tablet', 'mobile'].map(mode => result[mode].rendered);
            for (let i = 0; i < expected.length; i++) {
                assert.ok(Math.abs(actual[i] - expected[i]) <= 1,
                    'Actual ' + actual + ' differs from expected ' + expected);
            }
            assert.ok(actual[0] >= actual[1] && actual[1] >= actual[2],
                'Tablet must not expand past Desktop, and Mobile must not expand past Tablet');
            assert.equal(result.desktop.computedMaxWidth, documentWidth + 'px',
                'The renderer-owned document max-width must survive all viewport changes');
            assert.equal(result.tablet.computedMaxWidth, documentWidth + 'px');
            assert.equal(result.mobile.computedMaxWidth, documentWidth + 'px');
        } finally {
            await rm(temp, {recursive: true, force: true});
        }
    });
}
