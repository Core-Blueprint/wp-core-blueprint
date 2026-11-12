import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const viewports = read('assets/js/design/shell/viewports.js');
const popover = read('assets/js/design/shell/toolbar-popover.js');
const icons = read('assets/js/design/shell/icons.js');
const editor = read('assets/js/design/editor.js');
const css = read('assets/css/design/designer-toolbar.css');
const shellCss = read('assets/css/design/editor-shell.css');

test('FIELD-06C/D: one real viewport control set and one canonical event authority', () => {
	assert.match(viewports, /const controls = controlsFor\(root\)/);
	assert.match(viewports, /controls\.sort\(/);
	assert.match(viewports, /configureDesignerToolbarPopover\(root,/);
	assert.match(viewports, /kind: 'viewport', icon: 'monitor-smartphone'/);
	assert.match(popover, /options\.append\(\.\.\.controls\)/);
	assert.match(popover, /group\.append\(trigger, options\)/);
	assert.match(viewports, /controls\.forEach\(control => \{\s*control\.addEventListener\('click'/);
	assert.doesNotMatch(viewports, /cloneNode/);
	assert.match(viewports, /root\.dispatchEvent\(new CustomEvent\(VIEWPORT_EVENT/);
	assert.match(viewports, /target\.getAttribute\('aria-label'\)/);
	assert.doesNotMatch(viewports, /base\/mail|base\/reports/,
		'Canonical viewport coordinator must not branch by product');
});

test('FIELD-06C/D: desktop inline and tablet/mobile popover share the exact 1280px breakpoint', () => {
	assert.match(popover, /view\.matchMedia\?\.\('\(max-width: 1280px\)'\)/);
	assert.match(popover, /const next = media\.matches/);
	assert.match(popover, /media\?\.addEventListener\?\.\('change', sync, \{signal: local\.signal\}\)/);
	assert.doesNotMatch(popover, /available <|inlineWidth|hysteresis|toolbarWidth/);
	assert.match(popover, /new view\.ResizeObserver\(sync\)/);
	assert.match(popover, /view\.addEventListener\?\.\('resize', sync,/);
	assert.match(popover, /signal\?\.addEventListener\('abort', destroy/);
	assert.match(popover, /observer\?\.disconnect\(\)/);
	assert.match(editor, /configureDesignerViewports\(stagedRoot, \{active: 'desktop', signal\}\)/);
});

test('FIELD-06C/D: accessible popover behavior and original pressed-state remain separate', () => {
	assert.match(popover, /trigger\.setAttribute\('aria-controls', id\)/);
	assert.match(popover, /trigger\.setAttribute\('aria-expanded', 'false'\)/);
	assert.match(popover, /options\.setAttribute\('role', 'group'\)/);
	assert.match(popover, /options\.hidden = compact && !open/);
	assert.match(popover, /const position = \(\) =>/);
	assert.match(popover, /options\.style\.setProperty\('--cb-toolbar-popover-shift'/);
	assert.match(popover, /setOpen\(false, \{restoreFocus: true\}\)/);
	assert.match(popover, /event\.key === 'Escape'/);
	assert.match(popover, /event\.stopPropagation\(\)/);
	assert.match(popover, /doc\.addEventListener\('pointerdown'/);
	assert.match(popover, /doc\.addEventListener\('focusin'/);
	assert.match(popover, /'ArrowLeft', 'ArrowRight', 'Home', 'End'/);
	assert.match(popover, /if \(!candidate\.disabled\)/);
	assert.match(viewports, /control\.setAttribute\('aria-pressed', selected \? 'true' : 'false'\)/);
	assert.match(viewports, /root\.dataset\.cbDesignShellViewport = value/);
	assert.match(popover, /controllers\.delete\(group\)/);
	assert.match(popover, /group\.append\(\.\.\.controls\)/);
});

test('FIELD-06C/D: viewport and History use the same Base toolbar popover visuals', () => {
	assert.match(icons, /'monitor-smartphone': Object\.freeze\(\[/);
	assert.match(css, /\.cb-core-design-shell__toolbar-group--adaptive > \.cb-core-design-shell__adaptive-options/);
	assert.match(css, /\.is-adaptive-compact > \.cb-core-design-shell__adaptive-options/);
	assert.match(css, /\.is-adaptive-compact > \.cb-core-design-shell__adaptive-trigger/);
	assert.match(css, /\.cb-core-design-shell__adaptive-options\[hidden\]\s*\{\s*display: none !important;/);
	assert.match(shellCss, /viewport-trigger\[aria-expanded="true"\]/);
	assert.match(shellCss, /history-trigger\[aria-expanded="true"\]/);
	assert.match(css, /\.has-adaptive-viewport-switcher,/);
	assert.match(css, /\.has-adaptive-history-switcher\s*\{/);
});
