import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';

const read = relative => readFileSync(new URL('../../' + relative, import.meta.url), 'utf8');
const history = read('assets/js/design/shell/history.js');
const popover = read('assets/js/design/shell/toolbar-popover.js');
const viewports = read('assets/js/design/shell/viewports.js');
const editor = read('assets/js/design/editor.js');
const classic = read('assets/js/features/designer-launch.js');
const shell = read('assets/js/design/shell/index.js');
const icons = read('assets/js/design/shell/icons.js');
const toolbarCSS = read('assets/css/design/designer-toolbar.css');
const activeCSS = read('assets/css/design/editor-shell.css');

test('FIELD-06D: shared brand wordmark is hidden on tablet/mobile for every consumer', () => {
	assert.match(toolbarCSS,
		/@media \(max-width: 1280px\)\s*\{\s*\.cb-core-design-shell__toolbar--designer \.cb-core-design-shell__brand-wordmark\s*\{\s*display: none !important;/);
	assert.match(editor, /'base\/mail': 'Mail Designer'/);
	assert.match(editor, /'base\/reports': 'Reports Designer'/);
	assert.match(editor, /'aria-label': designerTitle \+ ', Core Blueprint'/);
	assert.match(classic, /brand\.setAttribute\('aria-label', \`\$\{title\}, Core Blueprint\`\)/);
	assert.match(classic, /cb-core-design-shell__brand-wordmark/);
	assert.doesNotMatch(toolbarCSS, /@media \(min-width: 1281px\)[\s\S]*brand-wordmark\s*\{\s*display: none/,
		'Desktop must not have a fixed breakpoint hiding its designer name');
});

test('FIELD-06D: viewport and History call the same responsive Base popover primitive', () => {
	assert.match(viewports, /configureDesignerToolbarPopover\(root,/);
	assert.match(history, /configureDesignerToolbarPopover\(root,/);
	assert.match(history, /controls: \[undo, redo\]/);
	assert.match(history, /kind: 'history'/);
	assert.match(history, /icon: 'history'/);
	assert.match(icons, /'history': Object\.freeze\(\[/);
	assert.match(popover, /options\.append\(\.\.\.controls\)/);
	assert.match(popover, /group\.append\(trigger, options\)/);
	assert.doesNotMatch(history, /\.cloneNode|\.undo\(|\.redo\(|new CommandHistory/);
	assert.match(editor, /configureDesignerHistory\(stagedRoot, \{signal\}\)/);
	assert.match(editor, /configureHistory: configureDesignerHistory/);
	assert.match(classic, /if \(historyGroup\) shellApi\.configureHistory\(shell\)/);
});

test('FIELD-06D: Undo/Redo availability and events remain exclusively Shell-owned', () => {
	assert.match(shell, /const undo = element\(root, '\[data-cb-design-shell-undo\]'\)/);
	assert.match(shell, /const redo = element\(root, '\[data-cb-design-shell-redo\]'\)/);
	assert.match(shell, /if \(undo instanceof HTMLButtonElement\) undo\.disabled = !status\.canUndo/);
	assert.match(shell, /if \(redo instanceof HTMLButtonElement\) redo\.disabled = !status\.canRedo/);
	assert.match(popover, /if \(open\) setOpen\(false, \{restoreFocus: true\}\)/);
	assert.match(popover, /if \(!candidate\.disabled\)/);
	assert.match(popover, /event\.key === 'Escape'/);
	assert.match(popover, /doc\.addEventListener\('pointerdown'/);
	assert.match(popover, /doc\.addEventListener\('focusin'/);
	assert.match(popover, /signal\?\.addEventListener\('abort', destroy/);
});

test('FIELD-06D: desktop inline and compact history use shared sizing/focus/active styles', () => {
	assert.match(toolbarCSS, /\.cb-core-design-shell__toolbar-group--adaptive:not\(\.is-adaptive-compact\) > \.cb-core-design-shell__adaptive-trigger/);
	assert.match(toolbarCSS, /\.cb-core-design-shell__toolbar-group--adaptive\.is-adaptive-compact > \.cb-core-design-shell__adaptive-options/);
	assert.match(toolbarCSS, /\.cb-core-design-shell__adaptive-options\[hidden\]\s*\{\s*display: none !important;/);
	assert.match(toolbarCSS, /\.cb-core-design-shell__toolbar-group--history\.has-adaptive-history-switcher/);
	assert.match(activeCSS, /history-trigger\[aria-expanded="true"\]/);
	assert.match(popover, /view\.matchMedia\?\.\('\(max-width: 1280px\)'\)/);
	assert.match(popover, /options\.style\.setProperty\('--cb-toolbar-popover-shift'/);
});
