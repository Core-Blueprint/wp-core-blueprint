import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';

const source = relative => readFile(new URL(relative, import.meta.url), 'utf8');

test('native Mail and Reports use the same canonical Base logo and Miriam Libre wordmark as Automations', async () => {
	const editor = await source('../../assets/js/design/editor.js');
	const classic = await source('../../assets/js/features/designer-launch.js');
	const php = await source('../../src/Design/Editor/Assets.php');
	const font = await source('../../assets/css/components/brand-lockup.css');
	const fullscreen = await source('../../assets/css/design/platform-fullscreen.css');
	assert.match(editor, /'base\/mail': 'Mail Designer'/);
	assert.match(editor, /'base\/reports': 'Reports Designer'/);
	assert.match(editor, /cb-core-design-shell__brand cb-core-brand-lockup/);
	assert.match(editor, /cb-core-brand-lockup__mark cb-core-design-shell__brand-mark/);
	assert.match(editor, /cb-core-brand-lockup__wordmark cb-core-design-shell__brand-wordmark/);
	assert.match(editor, /cb-core-brand-lockup__dot/);
	assert.match(editor, /designerTitle \+ ', Core Blueprint'/);
	assert.match(classic, /appendBrandedModeTitle\(wordmark, title\)/);
	assert.match(php, /CoreBlueprintLockup::enqueue_assets\(\)/);
	assert.match(php, /CoreBlueprintMark::data_uri\(\)/);
	assert.match(font, /font-family: "Miriam Libre", sans-serif/);
	assert.doesNotMatch(fullscreen, /font-family:[^\n]*Space Grotesk/);
	assert.doesNotMatch(editor, /cb-core-design-shell__native-profile/);
});

test('native toolbar only condenses its identity when space is insufficient, preserving a small-screen fallback', async () => {
	const editor = await source('../../assets/js/design/editor.js');
	const fullscreen = await source('../../assets/css/design/platform-fullscreen.css');
	assert.match(editor, /const configurePlatformBrand = \(root, signal\) =>/);
	assert.match(editor, /start\.scrollWidth > available \+ 1/);
	assert.match(editor, /new view\.ResizeObserver\(sync\)/);
	assert.match(editor, /new view\.MutationObserver\(sync\)/);
	assert.match(editor, /signal\.addEventListener\('abort'/);
	assert.match(editor, /configurePlatformBrand\(stagedRoot, signal\)/);
	assert.match(fullscreen, /is-identity-condensed \.cb-core-design-shell__brand-wordmark/);
	const tablet = fullscreen.split('@media (max-width: 1280px)')[1].split('@media (max-width: 600px)')[0];
	assert.doesNotMatch(tablet, /brand-wordmark/);
	assert.match(fullscreen, /@media \(max-width: 600px\)[\s\S]*?brand-wordmark/);
});
