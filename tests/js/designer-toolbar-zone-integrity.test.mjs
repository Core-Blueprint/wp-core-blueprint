import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const shared = read('assets/css/design/designer-toolbar.css');
const native = read('assets/css/design/platform-fullscreen.css');
const shell = read('assets/css/design/editor-shell.css');
const editor = read('assets/js/design/editor.js');

test('FIELD-06E: Start/Center/End remain Base grid zones with Flexbox inside', () => {
	assert.match(shell, /\.cb-core-design-shell__toolbar--designer\s*\{\s*position: relative;\s*display: grid;/);
	assert.match(shell, /\.cb-core-design-shell__toolbar-zone\s*\{\s*display: flex;/);
	assert.match(editor, /toolbar\.append\(startZone, centerZone, endZone\)/);
	assert.match(editor, /endZone\.append\(resourceGroup, operationGroup\)/);
	assert.match(editor, /endZone\.prepend\(rightLauncher\)/);
});

test('FIELD-06E: tablet End owns intrinsic width instead of competing fractional grid tracks', () => {
	assert.match(shared,
		/@media \(max-width: 1280px\)[\s\S]*?grid-template-columns: minmax\(0, 1fr\) max-content max-content;/);
	assert.match(native,
		/@media \(max-width: 1280px\)[\s\S]*?grid-template-columns: minmax\(0, 1fr\) max-content max-content;/);
	assert.doesNotMatch(native,
		/@media \(max-width: 1280px\)[\s\S]*?grid-template-columns: minmax\(0, 1fr\) auto minmax\(0, 1fr\);/,
		'Equal-width end tracks cause the Browser control to overlap History');
});

test('FIELD-06E: narrow toolbars give End its own row, retaining all commands', () => {
	assert.match(shared,
		/@media \(max-width: 700px\)[\s\S]*?grid-template-columns: minmax\(0, 1fr\) max-content minmax\(0, 1fr\);/);
	assert.match(shared, /grid-column: 1 \/ -1;\s*grid-row: 2;/);
	assert.match(shared, /justify-content: flex-end;\s*flex-wrap: wrap;/);
	assert.match(shared, /cb-core-design-shell__toolbar-group--resource\s*\{\s*flex-shrink: 0;/);
	assert.match(native,
		/@media \(max-width: 700px\)[\s\S]*?grid-template-columns: minmax\(0, 1fr\) max-content minmax\(0, 1fr\);/);
	assert.doesNotMatch(shared,
		/@media \(max-width: 700px\)[\s\S]*?\.cb-core-design-shell__toolbar-zone--end[^}]*display:\s*none/,
		'The End zone must never be hidden to solve a collision');
});
