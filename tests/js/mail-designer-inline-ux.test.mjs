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
	assert.match(consumer, /inlineEditor\?\.finish\(true\)/);

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
