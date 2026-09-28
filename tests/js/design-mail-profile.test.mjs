import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
	MAIL_DESIGN_TYPE,
	MAIL_FONT_FAMILIES,
	MAIL_ROOT_TYPE,
	MAIL_WIDTH_DEFAULT,
	normalizeMailLayout,
	validateProject,
} from '../../assets/js/design/mail/index.js';

const project = () => ({
	schema_version: 0,
	design_type: MAIL_DESIGN_TYPE,
	root: {
		type: MAIL_ROOT_TYPE,
		provider: 'core',
		properties: {
			layout: {
				width: 600,
				background: '#f3f4f6',
				contentBackground: '#ffffff',
				fontFamily: 'Arial, Helvetica, sans-serif',
				textColor: '#1f2937',
				accentColor: '#2563eb',
			},
		},
		children: [{
			type: 'mail.section',
			provider: 'core',
			properties: { padding: 32 },
			children: [{
				type: 'mail.text',
				provider: 'core',
				properties: { text: 'Hello' },
				children: [],
			}],
		}],
	},
});

test('mail editor profile accepts canonical declarative mail projects', () => {
	assert.equal(validateProject(project()).design_type, MAIL_DESIGN_TYPE);
});

test('mail editor profile rejects a document project', () => {
	const candidate = project();
	candidate.design_type = 'fixture.flow.document';
	assert.throws(() => validateProject(candidate), /mail-template/);
});

test('mail layout normalizes bounded email width independently of document geometry', () => {
	assert.equal(normalizeMailLayout({}).width, MAIL_WIDTH_DEFAULT);
	assert.equal(normalizeMailLayout({ width: 1200 }).width, 800);
	assert.equal(normalizeMailLayout({ width: 200 }).width, 320);
});

test('mail layout normalizes font family to the renderer-safe contract', () => {
	assert.deepEqual(MAIL_FONT_FAMILIES, [
		'Arial, Helvetica, sans-serif',
		'Helvetica, Arial, sans-serif',
		'Georgia, Times New Roman, serif',
		'Tahoma, Verdana, sans-serif',
		'Verdana, Geneva, sans-serif',
	]);
	assert.equal(normalizeMailLayout({ fontFamily: MAIL_FONT_FAMILIES[2] }).fontFamily, MAIL_FONT_FAMILIES[2]);
	assert.equal(normalizeMailLayout({ fontFamily: 'Comic Sans MS' }).fontFamily, MAIL_FONT_FAMILIES[0]);
});

test('public editor delegates validation to profile APIs and exposes shared Designer UI contracts without profile conditionals', async () => {
	const source = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
	assert.match(source, /['"]mail['"]\s*:\s*Object\.freeze/);
	assert.match(source, /profile\.api\.validateProject/);
	assert.match(source, /createDesignerShell/);
	assert.match(source, /configureSidebar:\s*configureDesignerSidebar/);
	assert.match(source, /configureViewports:\s*configureDesignerViewports/);
	assert.match(source, /sidebarRoles:\s*DESIGNER_SIDEBAR_ROLES/);
	assert.match(source, /viewportOrder:\s*DESIGNER_VIEWPORT_ORDER/);
	assert.match(source, /icons:\s*Object\.freeze/);
	assert.match(source, /cb:design-editor:ready/);
	assert.doesNotMatch(source, /profile\.id\s*===\s*['"]document-(?:fixed|flow)['"]/);
});

test('shared Designer Shell supports independent named tab groups', async () => {
	const source = await readFile(new URL('../../assets/js/design/shell/index.js', import.meta.url), 'utf8');
	assert.match(source, /cbDesignShellGroup/);
	assert.match(source, /activePanelFor\(groupId\)/);
	assert.match(source, /defaultPanels/);
	assert.match(source, /group:\s*state\.id/);
});

test('shared Designer Shell owns reusable viewport controls independently of Mail', async () => {
	const source = await readFile(new URL('../../assets/js/design/shell/viewports.js', import.meta.url), 'utf8');
	assert.match(source, /DESIGNER_VIEWPORT_ORDER\s*=\s*Object\.freeze\(\['mobile', 'tablet', 'desktop'\]\)/);
	assert.match(source, /\[data-cb-design-shell-viewport\]/);
	assert.match(source, /mobile:\s*'smartphone'/);
	assert.match(source, /tablet:\s*'tablet'/);
	assert.match(source, /desktop:\s*'monitor'/);
	assert.match(source, /root\.dataset\.cbDesignShellViewport\s*=\s*value/);
	assert.match(source, /cb:design-shell:viewportchange/);
	assert.match(source, /onChange/);
	assert.doesNotMatch(source, /data-cb-mail-/);
	assert.doesNotMatch(source, /mail/i);
});

test('mail designer uses the canonical live preview as its editable canvas', async () => {
	const source = await readFile(new URL('../../assets/js/features/mail-designer.js', import.meta.url), 'utf8');
	assert.match(source, /\[data-cb-mail-editor-node\]/);
	assert.match(source, /createDesignerSelectionController/);
	assert.match(source, /selectionController\?\.select\(path\)/);
	assert.doesNotMatch(source, /session\.editorState\.selection\./);
	assert.match(source, /data-cb-mail-structure/);
	assert.match(source, /bindPreviewInteractions/);
	assert.doesNotMatch(source, /\[data-cb-mail-canvas\]/);
});

test('mail designer consumes Mail profile styling and exposes section inspection without adding a section palette component', async () => {
	const source = await readFile(new URL('../../assets/js/features/mail-designer.js', import.meta.url), 'utf8');
	const componentRegistry = await readFile(new URL('../../src/Mail/Designer/ComponentRegistry.php', import.meta.url), 'utf8');
	assert.match(source, /profiles\.mail/);
	assert.match(source, /MAIL_FONT_FAMILIES/);
	assert.match(source, /normalizeMailLayout/);
	assert.match(source, /node_type:\s*['"]mail\.section['"]/);
	assert.match(source, /defaults:\s*Object\.freeze\(\{\s*background:\s*['"]#ffffff['"],\s*padding:\s*28\s*\}\)/);
	assert.match(source, /key:\s*['"]background['"]/);
	assert.match(source, /key:\s*['"]padding['"]/);
	assert.match(source, /node\.properties\?\.\[field\.key\]\s*\?\?\s*definition\.defaults\?\.\[field\.key\]/);
	assert.doesNotMatch(componentRegistry, /'node_type'\s*=>\s*'mail\.section'/);
});

test('mail designer consumes the shared Designer Shell instead of owning shell controls', async () => {
	const source = await readFile(new URL('../../assets/js/features/mail-designer.js', import.meta.url), 'utf8');
	assert.match(source, /createDesignerShell/);
	assert.match(source, /shell\s*=\s*createDesignerShell/);
	assert.match(source, /createDesignerSelectionController/);
	assert.match(source, /selectionController\s*=\s*createDesignerSelectionController/);
	assert.match(source, /shell\?\.syncHistory\(\)/);
	assert.doesNotMatch(source, /shell\?\.activatePanel\(['"]inspector['"]\)/);
	assert.doesNotMatch(source, /data-cb-mail-side-tab/);
	assert.doesNotMatch(source, /updateHistoryButtons/);
});

test('shared Designer Mode composes generic chrome and delegates viewport state to the Shell API', async () => {
	const source = await readFile(new URL('../../assets/js/features/designer-launch.js', import.meta.url), 'utf8');
	assert.doesNotThrow(() => new Function(source));
	assert.match(source, /fullscreen\.click\(\)/);
	assert.match(source, /cb:design-shell:fullscreenchange/);
	assert.match(source, /\[data-cb-design-shell-viewport\]/);
	assert.match(source, /shellApi\.configureViewports\(shell\)/);
	assert.match(source, /cb-core-design-shell__toolbar--designer/);
	assert.match(source, /shellApi\.icons\.decorate\(undo, 'undo-2'/);
	assert.match(source, /shellApi\.icons\.decorate\(redo, 'redo-2'/);
	assert.match(source, /shellApi\.configureSidebar\(shell/);
	assert.match(source, /discoverSidebarRoles/);
	assert.doesNotMatch(source, /data-cb-mail-/);
	assert.doesNotMatch(source, /layers:\s*'structure'/);
	assert.doesNotMatch(source, /settings:\s*'email'/);
	assert.doesNotMatch(source, /cbDesignShellViewport\s*=/);
	assert.doesNotMatch(source, /const ICONS/);
	assert.doesNotMatch(source, /createElementNS/);
	assert.doesNotMatch(source, /createDesignerShell/);
	assert.doesNotMatch(source, /requestFullscreen/);
});

test('mail designer template exposes shared Designer capabilities and declarative sidebar roles', async () => {
	const source = await readFile(new URL('../../templates/mail-designer.php', import.meta.url), 'utf8');
	assert.match(source, /data-cb-mail-template-select/);
	assert.match(source, /data-cb-design-launch-root/);
	assert.match(source, /data-cb-design-launch-context/);
	assert.match(source, /data-cb-design-shell/);
	assert.match(source, /data-cb-design-shell-viewport="desktop"/);
	assert.match(source, /data-cb-design-shell-viewport="tablet"/);
	assert.match(source, /data-cb-design-shell-viewport="mobile"/);
	assert.match(source, /data-cb-design-shell-primary-action/);
	assert.match(source, /data-cb-design-shell-sidebar-role="inspector"/);
	assert.match(source, /data-cb-design-shell-sidebar-role="layers"/);
	assert.match(source, /data-cb-design-shell-sidebar-role="settings"/);
	assert.match(source, /cb-core-design-shell__workspace/);
	assert.match(source, /data-cb-design-shell-group="palette"\s+data-cb-design-shell-tab="elements"/);
	assert.match(source, /data-cb-design-shell-group="palette"\s+data-cb-design-shell-tab="dynamic-data"/);
	assert.match(source, /data-cb-design-shell-group="palette"\s+data-cb-design-shell-panel="elements"/);
	assert.match(source, /data-cb-design-shell-group="palette"\s+data-cb-design-shell-panel="dynamic-data"/);
	assert.match(source, /data-cb-design-shell-tab="email"/);
	assert.match(source, /data-cb-design-shell-tab="structure"/);
	assert.match(source, /data-cb-design-shell-tab="inspector"/);
	assert.match(source, /cb-core-design-shell__tab/);
	assert.match(source, /data-cb-mail-preview-frame/);
	assert.match(source, /sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"/);
	assert.doesNotMatch(source, /cb-core-mail-designer__templates/);
	assert.doesNotMatch(source, /cb-core-mail-designer__preview-section/);
	assert.doesNotMatch(source, /data-cb-mail-canvas/);
});
