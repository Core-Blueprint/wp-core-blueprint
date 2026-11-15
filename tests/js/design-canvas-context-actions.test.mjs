import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';

const editor = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
const layers = await readFile(new URL('../../assets/js/design/shell/layers.js', import.meta.url), 'utf8');

test('Mail and Reports use one Foundation menu and profile policy', () => {
	assert.match(editor, /const openCanvasActions = \(event, keyboard = false\) =>/);
	assert.match(editor, /projectDesignerLayerInteractions\(\{/);
	assert.match(editor, /projectDesignerLayerContextActions\(\{/);
	assert.match(editor, /tree\.openActions\(\{/);
	assert.match(editor, /profileModule\.layerPolicyFor\(node, parent/);
	assert.match(layers, /openActions\(options\) \{/);
});
test('canvas renderer paths and Host state are revalidated; iframe is not delegated', () => {
	assert.match(editor, /nodeAt\(project\.root, path\)/);
	assert.match(editor, /source\.dataset\.cbDesignNodeType/);
	assert.match(editor, /editor\.project\(\) === project/);
	assert.match(editor, /dropSurface\.contains\(anchor\)/);
	assert.match(editor, /event\.target\?\.closest\?\.\('input, textarea, select, \[contenteditable\]'\)/);
	assert.match(editor, /dropSurface\.addEventListener\('contextmenu'/);
	assert.match(editor, /event\.key === 'ContextMenu'/);
	assert.match(editor, /event\.key === 'F10' && event\.shiftKey/);
	assert.doesNotMatch(editor.slice(editor.indexOf('const openCanvasActions'), editor.indexOf('// The Host requires an EMPTY render-surface')), /contentWindow|postMessage|\.execute\(\s*event\./);
});

test('context menu publication follows canonical Host repaint, not the stale canvas node', () => {
	const start = editor.indexOf('const openCanvasActions');
	const end = editor.indexOf('// The Host requires an EMPTY render-surface', start);
	const code = editor.slice(start, end);
	assert.ok(start > 0 && end > start);
	assert.match(code, /host\.refresh\('selection'\)\.then\(\(\) => \{/);
	assert.match(code, /requestId !== canvasMenuRequest/);
	assert.ok(code.indexOf("host.refresh('selection')") < code.indexOf('tree.openActions({'));
	assert.match(code, /event\.preventDefault\(\)/);
	assert.match(code, /nodeAt\(project\.root, path\) === node/);
});
