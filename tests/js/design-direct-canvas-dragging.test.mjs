import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';

const source = async path => readFile(new URL(path, import.meta.url), 'utf8');

test('DF-D2E Mail uses the node itself and preserves click/select and double-click/F2 edit', async () => {
 const mail = await source('../../assets/js/design/mail/platform-adapter.js');
 assert.match(mail, /e\.dataset\.cbDesignNodeDragSource=JSON\.stringify\(path\)/);
 assert.match(mail, /n\.type!=='mail\.column'/);
 assert.match(mail, /e\.addEventListener\('click',ev=>\{/);
 assert.match(mail, /e\.addEventListener\('dblclick',openEdit\)/);
 assert.match(mail, /ev\.key==='F2'/);
 assert.doesNotMatch(mail, /cb-mail-platform__drag-handle/);
});

test('DF-D2E Reports uses bounded parent proxy for click and drag without iframe authority', async () => {
 const reports = await source('../../assets/js/design/reports/platform-adapter.js');
 assert.match(reports, /previewPathForRegion\(region\.id, lastProject\)/);
 assert.match(reports, /cb-reports-platform__interaction-region/);
 assert.match(reports, /hit\.dataset\.cbDesignNodeDragSource = JSON\.stringify\(path\)/);
 assert.match(reports, /onActivate\(region\.id\)/);
 assert.match(reports, /!\['reports\.header', 'reports\.footer'\]\.includes\(node\.type\)/);
 assert.match(reports, /createFlowPreviewHost\(frame, \{onActivate, onRegions\}\)/);
 assert.doesNotMatch(reports, /cb-reports-platform__drag-handle/);
});

test('DF-D2E same registered Move and History routes serve direct canvas sources', async () => {
 const editor = await source('../../assets/js/design/editor.js');
 const controller = await source('../../assets/js/design/shell/drag-drop.js');
 const css = await source('../../assets/css/design/platform-fullscreen.css');
 assert.match(editor, /querySelectorAll\('\[data-cb-design-node-drag-source\]'\)/);
 assert.match(editor, /directCanvas: item\.directCanvas === true/);
 assert.match(editor, /editor\.execute\(intent\.commandId, intent\.input\)/);
 assert.match(editor, /const path = \[\.\.\.parentPath, index\]/);
 assert.equal((editor.match(/createDesignerDragDropController\(\{/g)||[]).length, 1);
 assert.match(controller, /event\.target\?\.isContentEditable === true/);
 assert.match(controller, /event\.stopPropagation\?\.\(\)/);
 assert.match(css, /data-cb-design-node-drag-source/);
 assert.doesNotMatch(css, /cb-mail-platform__drag-handle/);
});
