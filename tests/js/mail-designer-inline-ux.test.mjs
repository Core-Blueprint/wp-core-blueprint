import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import { inlineMailIsSafe, inlineMailProperty } from '../../assets/js/design/mail/inline-text.js';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');
const node = (type, properties = {}, provider = 'core') => ({ type, provider, properties, children: [] });

test('M1 inline edits only canonical text content, never Inspector-owned configuration', () => {
	assert.equal(inlineMailProperty(node('mail.heading', { text: 'Title' })), 'text');
	assert.equal(inlineMailProperty(node('mail.text', { text: 'Message' })), 'text');
	assert.equal(inlineMailProperty(node('mail.rich-text', { markup: '<p>Message</p>' })), 'markup');
	for (const type of ['mail.button', 'mail.image', 'mail.section', 'mail.columns', 'mail.column']) {
		assert.equal(inlineMailProperty(node(type, { label: 'CTA' })), null, type);
	}
	assert.equal(inlineMailProperty(node('mail.text', { text: 'Secret' }, 'third-party')), null);
});

test('Preview substitution cannot overwrite dynamic binding tokens', () => {
	assert.equal(inlineMailIsSafe(node('mail.text', { text: 'Hello {{contact.first_name}}' })), false);
	assert.equal(inlineMailIsSafe(node('mail.rich-text', { markup: '<p>{{ newsletter.unsubscribe_url }}</p>' })), false);
	assert.equal(inlineMailIsSafe(node('mail.heading', { text: 'News today' })), true);
	assert.equal(inlineMailIsSafe(node('mail.rich-text', { markup: '<p>Ready</p>' })), true);
	assert.equal(inlineMailIsSafe(node('mail.text', { text: 'x'.repeat(100001) })), false);
	assert.equal(inlineMailIsSafe(node('mail.text', {})), false);
});

test('One click remains selection, double click enables text, Escape cancels, blur commits through session', async () => {
	const consumer = await read('assets/js/features/mail-designer.js');
	const inline = await read('assets/js/design/mail/inline-text.js');
	const renderer = await read('src/Design/Profile/Mail/HtmlRenderer.php');

	assert.match(consumer, /inlineEditor\?\.attach\(doc\)/);
	assert.match(consumer, /if \(inlineEditor\?\.isEditing\(\)\)/);
	assert.match(consumer, /commands\.setProperty\(path, \[key\], value\)/);
	assert.match(consumer, /previewPending = true/);
	assert.match(consumer, /inlineEditor\.finish\(true\)/);

	assert.match(inline, /documentRef\.addEventListener\('dblclick'/);
	assert.match(inline, /target\.setAttribute\('contenteditable'/);
	assert.match(inline, /event\.key === 'Escape'/);
	assert.match(inline, /finish\(false\)/);
	assert.match(inline, /target\.addEventListener\('blur'/);
	assert.match(inline, /finish\(true\)/);
	assert.match(inline, /executeProperty\(active\.path, active\.property, next\)/);
	assert.match(inline, /!HAS_DYNAMIC_BINDING\.test/);
	assert.match(inline, /serializeMailMarkup/);
	assert.match(inline, /event\.clipboardData\?\.getData\('text\/plain'\)/);
	assert.match(renderer, /data-cb-mail-inline-editing/);
	assert.match(inline, /const EDITABLE_PROPERTIES/);
	assert.doesNotMatch(inline, /document\.execCommand/);
});

test('M1-B inline toolbar formats content, retains links safely and never owns styling', async () => {
	const inline = await read('assets/js/design/mail/inline-text.js');
	const toolbar = await read('assets/js/design/mail/inline-toolbar.js');
	assert.match(inline, /createMailInlineToolbar\(/);
	assert.match(inline, /active\.toolbar\?\.destroy\(\)/);
	assert.match(inline, /editing\.toolbar\?\.contains\(event\.relatedTarget\)/);
	assert.match(toolbar, /role', 'toolbar'/);
	assert.match(toolbar, /Bold selected text/);
	assert.match(toolbar, /Italicize selected text/);
	assert.match(toolbar, /Underline selected text/);
	assert.match(toolbar, /Link selected text/);
	assert.match(toolbar, /safeMailLink\(linkInput\.value\)/);
	assert.match(toolbar, /pointerdown', \(event\) => event\.preventDefault\(\)/);
	assert.match(toolbar, /createElement\('strong'\)|wrapSelection\('strong'\)/);
	assert.doesNotMatch(toolbar, /commands\.setProperty/);
	assert.doesNotMatch(toolbar, /setProperty|fontSize|accentColor|properties\.background|properties\.spacing/);
});

test('M1-B AJAX saves retain dirty baseline, protect switching and do not dispose on cancelled unload', async () => {
	const consumer = await read('assets/js/features/mail-designer.js');
	const save = await read('assets/js/features/mail-designer-save.js');
	assert.match(consumer, /const currentFingerprint = \(\) => JSON\.stringify\(/);
	assert.match(consumer, /const hasUnsavedChanges = \(\)/);
	assert.match(consumer, /persistedFingerprint !== null/);
	assert.match(consumer, /event\.returnValue = ''/);
	assert.match(consumer, /cb:design-shell:savechange/);
	assert.match(consumer, /pendingSaveFingerprint = currentFingerprint\(\)/);
	assert.match(consumer, /persistedFingerprint = pendingSaveFingerprint/);
	assert.match(consumer, /if \(hasUnsavedChanges\(\)\)/);
	assert.match(consumer, /Discard them and switch templates/);
	assert.match(consumer, /originalFingerprint = currentFingerprint\(\)/);
	assert.match(consumer, /currentFingerprint\(\) !== originalFingerprint/);
	assert.match(consumer, /window\.addEventListener\('pagehide'/);
	assert.doesNotMatch(consumer, /window\.addEventListener\('beforeunload', \(\) =>/);
	assert.match(save, /if \(event\.defaultPrevented\) return/);
	assert.match(save, /window\.addEventListener\('pagehide'/);
	assert.doesNotMatch(save, /window\.addEventListener\('beforeunload'/);
});

test('M1-C authoring gate rejects stale preview HTTP replies and unrelated keyboard F2', async () => {
	const consumer = await read('assets/js/features/mail-designer.js');
	const inline = await read('assets/js/design/mail/inline-text.js');
	assert.match(consumer, /let previewRevision = 0/);
	assert.match(consumer, /const revision = \+\+previewRevision/);
	assert.match(consumer, /controller\.signal\.aborted \|\| revision !== previewRevision \|\| inlineEditor\?\.isEditing\(\)/);
	assert.match(consumer, /\+\+previewRevision;\s*previewController\?\.abort\(\)/);
	assert.match(consumer, /inlineEditor\?\.startSelected\(preview\.contentDocument\)/);
	assert.match(consumer, /!shellRoot\.contains\(active\)/);
	assert.match(consumer, /syncDirtyIndicator\(\);\s*if \(isEditing\)/);
	assert.match(inline, /const startSelected = \(documentRef\) =>/);
	assert.match(inline, /if \(!finish\(true\)\) return false/);
	assert.match(inline, /Text exceeds the allowed length\. Shorten it before finishing/);
	assert.match(inline, /const HAS_DYNAMIC_BINDING = \//);
	assert.match(inline, /startSelected,\s*finish,\s*isEditing:/);
});

test('M1-D safe inline commands keep global settings in Inspector and finish drafts on cross-block selection', async () => {
	const consumer = await read('assets/js/features/mail-designer.js');
	const inline = await read('assets/js/design/mail/inline-text.js');
	const toolbar = await read('assets/js/design/mail/inline-toolbar.js');
	assert.match(consumer, /inlineEditor\.activePath\(\)/);
	assert.match(consumer, /if \(!inlineEditor\.finish\(true\)\)/);
	assert.match(inline, /editing\.toolbar\?\.applyShortcut\(event\)/);
	assert.match(inline, /activePath: \(\) => editing \? \[\.\.\.editing\.path\] : null/);
	assert.match(toolbar, /Create bulleted list from selected text/);
	assert.match(toolbar, /Create numbered list from selected text/);
	assert.match(toolbar, /documentRef\.addEventListener\('selectionchange', rememberSelection\)/);
	assert.match(toolbar, /documentRef\.removeEventListener\('selectionchange', rememberSelection\)/);
	assert.match(toolbar, /if \(key === 'k'\) return openLinkEditor\(\)/);
	assert.match(toolbar, /paragraph\.replaceWith\(list\)/);
	assert.doesNotMatch(toolbar, /session\.execute|commands\.setProperty|fontSize|accentColor/);
});
