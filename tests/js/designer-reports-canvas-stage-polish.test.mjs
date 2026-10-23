import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';

const source = path => readFile(new URL(path, import.meta.url), 'utf8');

test('Reports Flow document stage preserves square paper, fixed width and responsive spacing', async () => {
	const css = await source('../../assets/css/pages/reports-designer.css');
	const shell = await source('../../assets/css/design/platform-fullscreen.css');
	assert.match(css, /--cb-reports-paper-width: 860px/);
	assert.match(css, /max-width: var\(--cb-reports-paper-width\)/);
	assert.match(css, /\.cb-reports-platform__preview-frame\s*\{[^}]*border-radius: 0;[^}]*background: var\(--cb-report-paper\);[^}]*box-shadow:/s);
	assert.match(css, /@media \(max-width: 640px\)[\s\S]*padding: 12px 8px 28px/);
	assert.match(shell, /data-cb-design-shell-document-view="fixed"[^\{]*\.cb-reports-platform__preview-frame\s*\{[^}]*width: 860px;[^}]*max-width: none;/s);
	assert.match(shell, /\.cb-core-design-shell--native \.cb-core-design-shell__canvas-workarea\s*\{[^}]*overflow: auto;/s);
});

test('Reports preview status identifies source without modifying document fields or renderer', async () => {
	const js = await source('../../assets/js/design/reports/platform-adapter.js');
	const css = await source('../../assets/css/pages/reports-designer.css');
	assert.match(js, /container\.dataset\.cbReportsPreviewSource = 'loading'/);
	assert.match(js, /container\.dataset\.cbReportsPreviewSource = 'error'/);
	assert.match(js, /container\.dataset\.cbReportsPreviewSource = source/);
	assert.match(js, /source === 'sample'[\s\S]*?'Example report metrics;/);
	assert.match(js, /Most recent archived report\. Historical values, not live status/);
	assert.match(css, /data-cb-reports-preview-source="sample"/);
	assert.match(css, /data-cb-reports-preview-source="archived"/);
	assert.match(css, /is-preview-error \.cb-reports-platform__preview-note::before/);
	assert.match(js, /preview = createFlowPreviewHost\(frame, \{onActivate, onRegions\}\)/);
	assert.match(js, /body: documentKey/);
	assert.doesNotMatch(js, /pageCount|page_count|estimatePages/);
});
