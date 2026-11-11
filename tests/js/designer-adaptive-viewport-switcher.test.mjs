import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const viewports = read('assets/js/design/shell/viewports.js');
const icons = read('assets/js/design/shell/icons.js');
const editor = read('assets/js/design/editor.js');
const css = read('assets/css/design/designer-toolbar.css');
const modeCss = read('assets/css/design/designer-mode.css');
const shellCss = read('assets/css/design/editor-shell.css');

test('FIELD-06C: one canonical set of actual viewport buttons, independent of consumer', () => {
	assert.match(viewports, /const controls = controlsFor\(root\)/);
	assert.match(viewports, /options\.append\(\.\.\.controls\)/);
	assert.match(viewports, /group\.append\(trigger, options\)/);
	assert.match(viewports, /controls\.forEach\(\(control\) => \{\s*control\.addEventListener\('click'/);
	assert.doesNotMatch(viewports, /cloneNode|controls\.map\([^)]*createElement/);
	assert.match(viewports, /root\.dispatchEvent\(new CustomEvent\(VIEWPORT_EVENT/);
	assert.match(viewports, /target\.getAttribute\('aria-label'\)/);
	assert.doesNotMatch(viewports, /base\/mail|base\/reports/,
		'Viewport adapter must have no Mail/Reports branching');
});

test('FIELD-06C: capacity measurement and lifecycle stay owned by Base', () => {
	assert.match(viewports, /const available = toolbarWidth - padding - widthOf\(start\) - widthOf\(end\) - \(2 \* gap\)/);
	assert.match(viewports, /available < inlineWidth \+ \(compact \? 36 : 16\)/);
	assert.match(viewports, /view\.ResizeObserver\(syncAdaptive\)/);
	assert.match(viewports, /view\?\.addEventListener\?\.\('resize', syncAdaptive/);
	assert.match(viewports, /signal\?\.addEventListener\('abort', destroy/);
	assert.match(viewports, /resize\?\.disconnect\(\)/);
	assert.match(editor, /configureDesignerViewports\(stagedRoot, \{active: 'desktop', signal\}\)/);
	assert.doesNotMatch(viewports, /innerWidth\s*[<>=]|matchMedia\(.*max-width/,
		'Component adapts to available toolbar capacity, not viewport breakpoint');
});

test('FIELD-06C: popover is a keyboard-accessible group with one active selection authority', () => {
	assert.match(viewports, /trigger\.setAttribute\('aria-controls', id\)/);
	assert.match(viewports, /trigger\.setAttribute\('aria-expanded', 'false'\)/);
	assert.match(viewports, /options\.setAttribute\('role', 'group'\)/);
	assert.match(viewports, /options\.hidden = compact && !open/);
	assert.match(viewports, /const positionPopover = \(\) =>/);
	assert.match(viewports, /options\.style\.setProperty\('--cb-viewport-popover-shift'/);
	assert.match(viewports, /setOpen\(false, \{ restoreFocus: true \}\)/);
	assert.match(viewports, /event\.key === 'Escape'/);
	assert.match(viewports, /event\.stopPropagation\(\)/);
	assert.match(viewports, /doc\.addEventListener\('pointerdown'/);
	assert.match(viewports, /doc\.addEventListener\('focusin'/);
	assert.match(viewports, /'ArrowLeft', 'ArrowRight', 'Home', 'End'/);
	assert.match(viewports, /control\.setAttribute\('aria-pressed', selected \? 'true' : 'false'\)/);
	assert.match(viewports, /root\.dataset\.cbDesignShellViewport = value/);
});

test('FIELD-06C: existing three control states and popover share Foundation visuals', () => {
	assert.match(icons, /'monitor-smartphone': Object\.freeze\(\[/);
	assert.match(icons, /M18 8V6a2 2 0 0 0-2-2H4/);
	assert.match(css, /viewport-options\s*\{[^}]*display: inline-flex;/);
	assert.match(css, /is-viewport-compact > \.cb-core-design-shell__viewport-options\s*\{[^}]*display: flex;/);
	assert.match(css, /is-viewport-compact > \.cb-core-design-shell__viewport-trigger\s*\{[^}]*display: inline-flex !important;/);
	assert.match(css, /\.cb-core-design-shell__viewport-options\[hidden\]\s*\{\s*display: none !important;/);
	assert.match(shellCss, /viewport-trigger\[aria-expanded="true"\]\s*\{\s*border-color: var\(--cb-accent\) !important;/);
	assert.match(modeCss, /\.cb-core-design-shell__toolbar--designer\.is-compact \.cb-core-design-shell__toolbar-group--viewport,/);
	assert.match(css, /\.cb-core-design-shell__toolbar--designer\.is-compact \.cb-core-design-shell__toolbar-group--viewport\.has-adaptive-viewport-switcher\s*\{\s*display: inline-flex !important;/);
	assert.match(viewports, /group\.classList\.add\('has-adaptive-viewport-switcher'\)/);
	assert.match(viewports, /toolbar\.classList\.contains\('is-compact'\)/);
});
