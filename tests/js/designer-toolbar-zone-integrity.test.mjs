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

test('FIELD-06E R2: every responsive rail stays on one row, and resource actions cannot shrink into History', () => {
	assert.match(shared, /grid-column: auto;\s*grid-row: 1;\s*flex-wrap: nowrap;/);
	assert.match(shared, /\.cb-core-design-shell__toolbar--designer \.cb-core-design-shell__toolbar-group--resource\s*\{\s*flex: 0 0 auto;/);
	assert.doesNotMatch(shared, /grid-row:\s*2;/);
	assert.doesNotMatch(shared, /flex-wrap:\s*wrap;/);
	assert.doesNotMatch(native, /grid-template-columns: minmax\(0, 1fr\) max-content minmax\(0, 1fr\);/);
});

test('FIELD-06E R2: exceptionally narrow screens scroll the one row without shrinking tap targets', () => {
	assert.match(shared, /@media \(max-width: 700px\)[\s\S]*?grid-template-columns: max-content max-content max-content;/);
	assert.match(shared, /overflow-x: auto;/);
	assert.match(shared, /scrollbar-width: thin;/);
	assert.match(native, /@media \(max-width: 700px\)[\s\S]*?grid-template-columns: max-content max-content max-content;/);
	assert.match(shared, /position: fixed;\s*inset-block-start: var\(--cb-toolbar-popover-fixed-top/);
	assert.match(shared, /inset-inline-start: var\(--cb-toolbar-popover-fixed-left/);
	assert.doesNotMatch(shared, /max-width: 700px\)[\s\S]*?--cb-design-toolbar-control-size:/);
});

test('FIELD-06E R2: shared Viewport/History popover positioning remains accessible inside narrow scrollers', () => {
	const popover = read('assets/js/design/shell/toolbar-popover.js');
	assert.match(popover, /const narrowMedia = view\.matchMedia\?\.\('\(max-width: 700px\)'\)/);
	assert.match(popover, /options\.style\.setProperty\('--cb-toolbar-popover-fixed-left', left \+ 'px'\)/);
	assert.match(popover, /options\.style\.setProperty\('--cb-toolbar-popover-fixed-top', top \+ 'px'\)/);
	assert.match(popover, /toolbar\.addEventListener\('scroll', position, \{signal: local\.signal, passive: true\}\)/);
	assert.match(popover, /trigger\.setAttribute\('aria-expanded', open \? 'true' : 'false'\)/);
});
