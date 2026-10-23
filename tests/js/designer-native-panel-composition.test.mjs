import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';

const load = relative => readFile(new URL(relative, import.meta.url), 'utf8');

test('native Designer panel geometry is independent, inset and consistently rounded', async () => {
	const css = await load('../../assets/css/design/platform-fullscreen.css');
	const base = await load('../../assets/css/design/editor-shell.css');
	assert.match(css, /\.cb-core-platform-screen \.cb-core-design-shell--native\s*\{[^}]*--cb-design-native-surface-gap: var\(--cb-space-3, 12px\);[^}]*padding: var\(--cb-design-native-surface-gap\);[^}]*box-sizing: border-box;/s);
	assert.match(css, /\.cb-core-platform-screen \.cb-core-design-shell--native > \.cb-core-design-shell__toolbar\s*\{[^}]*margin: 0 0 var\(--cb-design-native-surface-gap\);[^}]*border: 1px solid var\(--cb-border\);[^}]*border-radius: var\(--cb-radius-md, 8px\);/s);
	assert.match(css, /> \.cb-core-design-shell__workspace--collapsible\s*\{[^}]*gap: var\(--cb-design-native-surface-gap\);/s);
	assert.match(css, /\.cb-core-design-shell--native \.cb-core-design-shell__palette,[^{]*\.cb-core-design-shell__sidebar\s*\{[^}]*border: 1px solid var\(--cb-border\);[^}]*border-radius: var\(--cb-radius-md, 8px\);/s);
	assert.match(css, /\.cb-core-platform-screen \.cb-core-design-shell--native \.cb-core-design-shell__canvas\s*\{[^}]*border: 1px solid var\(--cb-border\);[^}]*border-radius: var\(--cb-radius-md, 8px\);/s);
	assert.match(base, /\.cb-core-design-shell__toolbar\s*\{[^}]*margin-bottom: var\(--cb-space-3\);[^}]*border: 1px solid var\(--cb-border\);[^}]*border-radius: var\(--cb-radius-md\);/s);
});

test('narrow mode retains isolated canvas and drawer roles with compact external spacing', async () => {
	const css = await load('../../assets/css/design/platform-fullscreen.css');
	const shared = await load('../../assets/css/design/designer-mode.css');
	assert.match(css, /@media \(max-width: 1280px\)\s*\{[\s\S]*grid-template-columns: minmax\(0, 1fr\);/);
	assert.match(css, /@media \(max-width: 600px\)\s*\{[\s\S]*--cb-design-native-surface-gap: var\(--cb-space-2, 8px\);/);
	assert.match(shared, /\.cb-core-design-shell__workspace--collapsible > \.cb-core-design-shell__palette[^{]*\{[^}]*position: absolute !important;/s);
	assert.match(shared, /\.cb-core-design-shell\.is-left-panel-collapsed[\s\S]*transform: translateX/);
	assert.match(css, /\.cb-core-design-shell--native \.cb-core-design-shell__canvas-workarea\s*\{[^}]*overflow: auto;/s);
	assert.match(css, /\.cb-core-design-shell--native \.cb-core-design-shell__panel\s*\{[^}]*min-height: 0;[^}]*overflow-y: auto;/s);
});

test('B-V2 does not alter domain model, Save authority or renderer protocol', async () => {
	const css = await load('../../assets/css/design/platform-fullscreen.css');
	assert.doesNotMatch(css, /cbCorePlatformV1|save-coordinator|MutationObserver|createPlatformHost/);
	assert.match(css, /\.cb-core-design-shell--native \.cb-mail-platform,/);
	assert.match(css, /\.cb-core-design-shell--native \.cb-reports-platform/);
});
