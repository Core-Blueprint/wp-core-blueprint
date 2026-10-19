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

test('shared Base layout normalizer recognizes Layers roles on tab IDs different from panel IDs', async () => {
	const layout = await readSource('assets/js/features/designer-layout.js');
	const php = await readSource('src/Design/Editor/Assets.php');
	assert.match(layout, /PALETTE_ROLE_ORDER = Object\.freeze\(\['navigation', 'elements', 'layers'\]\)/);
	assert.match(layout, /matchingTab\?\.dataset\.cbDesignShellPaletteRole/);
	assert.match(layout, /return explicitRole === role \|\| panelId === role \|\| tabRole === role;/);
	assert.match(layout, /role === 'layers' \? 'Layers' : 'Elements'/);
	assert.match(php, /'paletteLabels' => \[/);
	assert.match(php, /'layers'\s*=> __\( 'Layers', 'core-blueprint' \)/);
	assert.doesNotMatch(layout, /'dynamic-data'/);
});

test('composed Designer palette never widens its rail or creates horizontal scrolling', async () => {
	const shared = await readSource('assets/css/design/designer-composition.css');
	const shell = await readSource('assets/css/design/editor-shell.css');
	assert.match(shared, /\.cb-core-design-shell__panel-body\s*\{[^}]*grid-template-columns: minmax\(0, 1fr\);[^}]*max-width: 100%;/s);
	assert.match(shared, /\.cb-core-design-shell__palette--composed > \.cb-core-design-shell__panel,[^{]*\{[^}]*overflow-x: hidden;/s);
	assert.match(shared, /\.cb-core-design-shell__palette-grid\s*\{[^}]*grid-template-columns: repeat\(var\(--cb-design-palette-columns\), minmax\(0, 1fr\)\);[^}]*max-width: 100%;/s);
	assert.match(shared, /\.cb-core-design-shell__palette-item\s*\{[^}]*width: 100%;[^}]*max-width: 100%;[^}]*min-width: 0;/s);
	assert.match(shared, /\.cb-core-design-shell__palette-item-label\s*\{[^}]*overflow: hidden;[^}]*text-overflow: ellipsis;[^}]*white-space: nowrap;/s);
	assert.match(shared, /\.cb-core-design-shell\.is-fullscreen \.cb-core-design-shell__panel-body,[^{]*\{[^}]*overflow-x: hidden;[^}]*overflow-y: auto;/s);
	assert.match(shell, /\.cb-core-design-shell\.is-fullscreen \.cb-core-design-shell__palette--tabbed > \.cb-core-design-shell__panel:not\(\[hidden\]\)\s*\{[^}]*overflow-x: hidden;[^}]*overflow-y: auto;/s);
	assert.match(shell, /\[data-cb-design-launch-root\]\[data-cb-design-launch-mode="direct"\][^{]*\.cb-core-design-shell__panel:not\(\[hidden\]\)\s*\{[^}]*overflow-x: hidden;[^}]*overflow-y: auto;/s);
});

test('Mail Dynamic Data is single-column with one clipped full-width token per row and full hover text', async () => {
	const css = await readSource('assets/css/pages/mail-designer.css');
	const template = await readSource('templates/mail-designer.php');
	assert.match(css, /\.cb-core-mail-designer__bindings\s*\{[^}]*display: grid;[^}]*grid-template-columns: minmax\(0, 1fr\);[^}]*width: 100%;[^}]*max-width: 100%;/s);
	assert.match(css, /\.cb-core-mail-binding\s*\{[^}]*width: 100%;[^}]*max-width: 100%;[^}]*min-width: 0;[^}]*overflow: hidden;[^}]*text-overflow: ellipsis;[^}]*white-space: nowrap;/s);
	assert.doesNotMatch(css, /\.cb-core-mail-designer__bindings\s*\{[^}]*flex-wrap:/s);
	assert.match(css, /button\.cb-core-mail-binding\.cb-core-clipboard-control:not\(\.cb-core-button\)\s*\{[^}]*display: block;/s);
	const clipboardCss = await readSource('assets/css/components/clipboard.css');
	assert.match(clipboardCss, /button\.cb-core-clipboard-control:not\(\.cb-core-button\),[^{]*\{[^}]*display: inline-flex;/s);
	assert.match(template, /class="cb-core-design-shell__palette-item-label"/);
	assert.match(template, /\$token = '\{\{' \. \(string\) \$binding\['id'\] \. '\}\}';/);
	assert.match(template, /data-cb-mail-binding="<\?php echo esc_attr\( \$token \); \?>" title="<\?php echo esc_attr\( \$token \. ' — ' \. \(string\) \$binding\['label'\] \); \?>" aria-label="<\?php echo esc_attr\( \$token \); \?>"/);
});

test('Mail canvas is headerless and white without stretching the responsive email', async () => {
	const template = await readSource('templates/mail-designer.php');
	const css = await readSource('assets/css/pages/mail-designer.css');
	const renderer = await readSource('src/Design/Profile/Mail/HtmlRenderer.php');
	const runtime = await readSource('assets/js/features/mail-designer.js');

	assert.doesNotMatch(template, /class="cb-core-design-shell__canvas-header"/);
	assert.doesNotMatch(template, /Visual canvas|Click an element to inspect it\. Double-click text to edit it\./);
	assert.match(template, /data-cb-mail-preview-status aria-live="polite"/);
	assert.match(template, /data-cb-mail-preview-frame/);
	assert.match(template, /sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"/);

	assert.match(css, /\.cb-core-mail-designer__canvas-panel\.cb-core-design-shell__canvas\s*\{[^}]*background: #fff;/s);
	assert.match(css, /\.cb-core-mail-designer__canvas-panel \.cb-core-mail-designer__preview-frame\s*\{[^}]*padding: 0;[^}]*background: #fff;/s);
	assert.match(css, /\.cb-core-mail-designer__preview-frame iframe\s*\{[^}]*width: 100%;[^}]*max-width: 900px;[^}]*border: 0;[^}]*box-shadow: none;/s);
	assert.match(css, /\.cb-core-mail-designer__preview-frame\.is-tablet iframe\s*\{[^}]*max-width: 782px;/s);
	assert.match(css, /\.cb-core-mail-designer__preview-frame\.is-mobile iframe\s*\{[^}]*max-width: 430px;/s);
	assert.match(renderer, /\$background = \$this->color\( \$layout\['background'\]/);
	assert.match(renderer, /\$content_background = \$this->color\( \$layout\['contentBackground'\]/);

	const start = runtime.indexOf('const styleEditorCanvas = (doc) => {');
	const end = runtime.indexOf('\n\t};', start);
	assert.ok(start > 0 && end > start, 'Preview-only background helper must exist');
	const helper = new Function(runtime.slice(start, end + 4) + '\nreturn styleEditorCanvas;')();
	const changes = [];
	const node = (key) => ({
		style: {
			setProperty: (...args) => changes.push([key, ...args]),
		},
	});
	const html = node('html');
	const body = node('body');
	const outer = node('outer');
	body.querySelector = (selector) => {
		assert.equal(selector, ':scope > table[role="presentation"][width="100%"]');
		return outer;
	};
	helper({ documentElement: html, body });
	assert.deepEqual(changes, [
		['html', 'background-color', '#fff', 'important'],
		['body', 'background-color', '#fff', 'important'],
		['outer', 'background-color', '#fff', 'important'],
	]);
	assert.match(runtime, /styleEditorCanvas\(doc\);\s*inlineEditor\?\.attach\(doc\);/);
});
