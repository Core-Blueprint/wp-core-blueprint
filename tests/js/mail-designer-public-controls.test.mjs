import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import * as profile from '../../assets/js/design/mail/index.js';

test('Mail profile exposes Base-owned visual rich-text controls to public Designer consumers', () => {
	assert.equal(typeof profile.createMailRichTextControls, 'function');
	assert.equal(typeof profile.createMailInlineTextController, 'function');
	assert.equal(typeof profile.validateProject, 'function');
});

test('public opt-in Mail CSS and consumer boundaries are explicit', async () => {
	const assets = await readFile(new URL('../../src/Design/Editor/Assets.php', import.meta.url), 'utf8');
	const mail = await readFile(new URL('../../src/Mail/Admin/DesignerAssets.php', import.meta.url), 'utf8');
	const css = await readFile(new URL('../../assets/css/design/mail-rich-text-controls.css', import.meta.url), 'utf8');
	assert.match(assets, /function enqueue_mail_rich_text_controls/);
	assert.match(assets, /mail-rich-text-controls\.css/);
	assert.match(mail, /DesignEditorAssets::enqueue_mail_rich_text_controls/);
	assert.match(css, /\.cb-mail-rich-text-controls__editor/);
	assert.doesNotMatch(css, /cb-core-mail-designer__preview-frame/);
});

test('Base Mail consumer uses only public profile factories without importing Design source paths', async () => {
	const mailFeature = await readFile(new URL('../../assets/js/features/mail-designer.js', import.meta.url), 'utf8');
	assert.match(mailFeature, /from '@cb-core\\/design-editor'/);
	assert.match(mailFeature, /mailProfile\\.createMailRichTextControls\\(\\{/);
	assert.match(mailFeature, /mailProfile\\.createMailInlineTextController\\(\\{/);
	assert.doesNotMatch(mailFeature, /from ['"]\\.\\.\\/design\\//);
});
