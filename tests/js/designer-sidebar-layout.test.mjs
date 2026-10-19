import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const readSource = (relative) => readFile(new URL('../../' + relative, import.meta.url), 'utf8');

test('Base Mail and Reports keep Layers left of canvas and Inspector right', async () => {
	for (const [file, panel, layers] of [
		['templates/mail-designer.php', 'structure', 'data-cb-mail-structure'],
		['templates/preferences-reports.php', 'layers', 'data-cb-report-layers'],
	]) {
		const html = await readSource(file);
		const canvas = html.indexOf('cb-core-design-shell__canvas--composed');
		const layer = html.indexOf(layers);
		const right = html.indexOf('cb-core-design-shell__sidebar--composed');
		assert.ok(layer > 0 && layer < canvas && canvas < right, file + ': Layers left, Inspector right');
		assert.match(html, new RegExp('data-cb-design-shell-tab="' + panel + '" data-cb-design-shell-palette-role="layers"'));
		assert.match(html, /data-cb-design-shell-sidebar-role="inspector"/);
		assert.doesNotMatch(html, /data-cb-design-shell-sidebar-role="layers"/);
	}
});

test('Dynamic Data is an optional section inside Mail Elements, never a third tab', async () => {
	const html = await readSource('templates/mail-designer.php');
	assert.doesNotMatch(html, /data-cb-design-shell-tab="dynamic-data"/);
	assert.match(html, /<\?php if \( ! empty\( \$bindings \) \) : \?>/);
	const elements = html.indexOf('data-cb-design-shell-panel="elements"');
	const section = html.indexOf('data-cb-designer-dynamic-data-heading');
	const layers = html.indexOf('data-cb-design-shell-panel="structure"');
	assert.ok(elements >= 0 && elements < section && section < layers);
	assert.match(html, /data-cb-mail-binding/);
	const mail = await readSource('assets/js/features/mail-designer.js');
	assert.match(mail, /createDesignerIcon\('braces'/);
});

test('Shared tabs are single line with ellipsis, accessible labels and stable glyphs', async () => {
	const css = await readSource('assets/css/design/editor-shell.css');
	const icons = await readSource('assets/js/design/shell/icons.js');
	const shell = await readSource('assets/js/design/shell/index.js');
	const palette = await readSource('assets/js/design/shell/navigation.js');
	assert.match(css, /\.cb-core-design-shell__tab-label\s*\{[^}]*overflow: hidden;[^}]*text-overflow: ellipsis;[^}]*white-space: nowrap;/s);
	assert.match(css, /\.cb-core-design-shell__tab\s*\.cb-core-design-shell__icon[^}]*flex: 0 0 auto;/s);
	assert.match(icons, /control\.setAttribute\?\.\('aria-label', accessibleLabel\)/);
	assert.match(icons, /control\.setAttribute\?\.\('title', accessibleLabel\)/);
	assert.match(shell, /DESIGNER_SIDEBAR_ROLES = Object\.freeze\(\['inspector', 'settings'\]\)/);
	assert.match(palette, /DESIGNER_PALETTE_ROLES = Object\.freeze\(\['navigation', 'elements', 'layers'\]\)/);
	assert.match(shell, /role === 'elements' \? 'shapes' : role === 'layers' \? 'layers'/);
});

test('Base exposes one curated semantic element icon adapter with safe default', async () => {
	const icons = await readSource('assets/js/design/shell/icons.js');
	const entry = await readSource('assets/js/design/editor.js');
	const mail = await readSource('assets/js/features/mail-designer.js');
	const reports = await readSource('assets/js/features/reports-preferences.js');
	const body = icons.slice(icons.indexOf('export const decorateDesignerElement ='));
	const resolve = new Function('ICONS', 'decorateDesignerControl',
		body.replace('export const', 'const') + '\nreturn decorateDesignerElement;')(
		{ shapes: [], 'heading-1': [], 'align-left': [], image: [], 'mouse-pointer-click': [], braces: [] },
		(_control, icon) => icon
	);
	assert.equal(resolve(null, 'heading'), 'heading-1');
	assert.equal(resolve(null, 'text'), 'align-left');
	assert.equal(resolve(null, 'image'), 'image');
	assert.equal(resolve(null, 'button'), 'mouse-pointer-click');
	assert.equal(resolve(null, 'unrecognized-type'), 'shapes');
	assert.equal(resolve(null, 'heading', { icon: 'unsupported' }), 'heading-1');
	assert.match(entry, /decorateElement: decorateDesignerElement/);
	assert.match(mail, /decorateDesignerElement\(button, button\.dataset\.cbMailAdd/);
	assert.match(reports, /decorateDesignerElement\( button, type/);
});
