import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const source = async path => readFile(new URL('../../' + path, import.meta.url), 'utf8');

test('cross-lane: Mail admin exposes one Browser-first launcher without competing template dropdown', async () => {
	const page = await source('src/Mail/Admin/Page.php');
	assert.match(page, /name="cb_mail_browser" value="1"/);
	assert.match(page, /Open with Core Blueprint/);
	assert.doesNotMatch(page, /<select id="cb-mail-platform-template" name="template"/);
	assert.match(page, /data-cb-mail-selection/);

	const adapter = await source('assets/js/design/platform/wordpress-save-adapter.js');
	const controls = await source('assets/js/design/mail/saved-controls.js');
	const browser = await source('assets/js/core/template-browser.js');
	assert.match(adapter, /cbMailSelection/);
	assert.match(adapter, /initialSelection:true/);
	assert.match(adapter, /searchParams\.delete\('cb_mail_browser'\)/);
	assert.match(controls, /beginMailDesignSelection/);
	assert.match(browser, /designsOnly/);
});

test('cross-lane: shared canvas actions and Layers menu remain registered in Native Host', async () => {
	const editor = await source('assets/js/design/editor.js');
	const layers = await source('assets/js/design/shell/layers.js');
	assert.match(editor, /const openCanvasActions = \(event, keyboard = false\)/);
	assert.match(editor, /dropSurface\.addEventListener\('contextmenu'/);
	assert.match(editor, /projectDesignerLayerContextActions/);
	assert.match(editor, /tree\.openActions\(\{/);
	assert.match(editor, /host\.refresh\('selection'\)/);
	assert.match(layers, /openActions\(options\)/);
	assert.match(layers, /isCurrent/);
});

test('cross-lane: document navigation and true PDF page count survive Mail/Context reconciliation', async () => {
	const editor = await source('assets/js/design/editor.js');
	const api = await source('src/Design/Profile/Document/Flow/Api/FlowRenderApi.php');
	const renderer = await source('src/PDF/Renderer.php');
	const frame = await source('assets/js/design/document/flow/preview-host.js');
	assert.match(editor, /configureDesignerPageNavigation\(stagedRoot, \{signal\}\)/);
	assert.match(editor, /data-cb-design-shell-page-navigation/);
	assert.match(api, /pdf_with_page_count/);
	assert.match(renderer, /getCanvas\(\)->get_page_count\(\)/);
	assert.match(frame, /frameBoxHeight/);
});
