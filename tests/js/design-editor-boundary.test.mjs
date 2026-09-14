import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import test from 'node:test';
import './design-editor-public.test.mjs';
import './design-mail-profile.test.mjs';
import './design-motion.test.mjs';

const sourceDirectory = new URL('../../assets/js/design/core/', import.meta.url);
const assetsBoundary = new URL('../../src/Design/Editor/Assets.php', import.meta.url);
const publicEditorBoundary = new URL('../../assets/js/design/editor.js', import.meta.url);
const designerToolbarStyle = new URL('../../assets/css/design/designer-toolbar.css', import.meta.url);

test('shared editor core remains profile-neutral and free of document/PDF geometry dependencies', async () => {
	const entries = await readdir(sourceDirectory, { withFileTypes: true });
	const files = entries.filter((entry) => entry.isFile() && entry.name.endsWith('.js'));
	assert.ok(files.length >= 10);

	for (const file of files) {
		const source = await readFile(new URL(file.name, sourceDirectory), 'utf8');
		const imports = [...source.matchAll(/(?:import|export)\s+[\s\S]*?\sfrom\s+['"]([^'"]+)['"]/g)]
			.map((match) => match[1].toLowerCase());
		for (const specifier of imports) {
			assert.doesNotMatch(
				specifier,
				/(?:profile|document|pdf|fixed|flow|geometry|pagination|paper)/,
				`${file.name} must not import profile/document rendering concerns`
			);
		}
		assert.doesNotMatch(
			source,
			/\b(?:pageWidth|pageHeight|paperSize|pagination|pdfRenderer|fixedProfile|flowProfile)\b/i,
			`${file.name} must not own document-profile geometry`
		);
	}
});

test('public Designer assets and Motion dependency use independent content revisions', async () => {
	const [assets, editor] = await Promise.all([
		readFile(assetsBoundary, 'utf8'),
		readFile(publicEditorBoundary, 'utf8'),
	]);
	assert.match(assets, /hash_file\(\s*'sha256'\s*,\s*\$path\s*\)/);
	assert.match(assets, /asset_version\(\s*'assets\/js\/design\/editor\.js'\s*\)/);
	assert.match(assets, /asset_version\(\s*'assets\/js\/design\/core\/motion\.js'\s*\)/);
	assert.match(assets, /asset_version\(\s*'assets\/js\/features\/designer-launch\.js'\s*\)/);
	assert.match(assets, /asset_version\(\s*'assets\/css\/design\/editor-shell\.css'\s*\)/);
	assert.match(assets, /wp_register_script_module\([\s\S]*self::MOTION_MODULE_ID[\s\S]*motion\.js/);
	assert.match(assets, /wp_enqueue_script_module\([\s\S]*self::MODULE_ID[\s\S]*\[ self::MOTION_MODULE_ID \]/);
	assert.match(editor, /from '@cb-core\/design-motion'/);
	assert.doesNotMatch(editor, /DESIGNER_MOTION_DEFAULTS,[\s\S]*from '\.\/core\/index\.js'/);
	assert.match(assets, /CB_CORE_VERSION\s*\.\s*'-'\s*\.\s*substr\(\s*\$hash/);
});

test('Designer toolbar keeps icon controls square while textual primary actions size to content', async () => {
	const css = await readFile(designerToolbarStyle, 'utf8');

	assert.match(
		css,
		/\.cb-core-design-shell__toolbar--designer :is\([\s\S]*?\.cb-core-design-shell__icon-button,[\s\S]*?\.cb-core-design-shell__drawer-launcher,[\s\S]*?\.cb-core-design-shell__compact-menu-trigger[\s\S]*?\)\s*\{[\s\S]*?inline-size:\s*var\(--cb-design-toolbar-control-size\)\s*!important;[\s\S]*?max-inline-size:\s*var\(--cb-design-toolbar-control-size\)\s*!important;[\s\S]*?padding:\s*0\s*!important;/
	);

	assert.match(
		css,
		/\.cb-core-design-shell__toolbar--designer \[data-cb-design-shell-primary-action\]\s*\{[\s\S]*?inline-size:\s*auto\s*!important;[\s\S]*?min-inline-size:\s*var\(--cb-design-toolbar-control-size\)\s*!important;[\s\S]*?max-inline-size:\s*none\s*!important;[\s\S]*?padding-inline:\s*var\(--cb-space-3\)\s*!important;[\s\S]*?white-space:\s*nowrap;/
	);

	assert.doesNotMatch(
		css,
		/\[data-cb-design-shell-primary-action\][\s\S]{0,300}max-inline-size:\s*var\(--cb-design-toolbar-control-size\)/
	);
});
