import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import { MAIL_DESIGN_TYPE, validateProject } from '../../assets/js/design/mail/index.js';

const get = async (name) => readFile(new URL('../../' + name, import.meta.url), 'utf8');
const example = () => ({
	schema_version: 0,
	design_type: MAIL_DESIGN_TYPE,
	root: {
		type: 'mail.root', provider: 'core', properties: { layout: { width: 600 } },
		children: [{
			type: 'mail.section', provider: 'core', properties: { padding: 28 },
			children: [
				{ type: 'mail.rich-text', provider: 'core', properties: { markup: '<p>Hello <strong>there</strong></p>' }, children: [] },
				{
					type: 'mail.columns', provider: 'core', properties: { ratio: 'equal', gap: 12, spacing: 20 },
					children: [0, 1].map(() => ({
						type: 'mail.column', provider: 'core', properties: {},
						children: [{ type: 'mail.text', provider: 'core', properties: { text: 'Inside column' }, children: [] }],
					})),
				},
			],
		}],
	},
});

test('mail JS profile accepts M1 rich-text and M2 columns as declarative projects', () => {
	assert.equal(validateProject(example()).design_type, MAIL_DESIGN_TYPE);
});

test('palette inserts structured two-column nodes, never freeform HTML or CSS', async () => {
	const source = await get('assets/js/features/mail-designer.js');
	assert.match(source, /node\.type === 'mail\.columns'/);
	assert.match(source, /node\.children = \[0, 1\]\.map/);
	assert.match(source, /type: 'mail\.column'/);
	assert.match(source, /insertionContainer\(definition\)/);
	assert.match(source, /node\?\.type === 'mail\.column'/);
	assert.match(source, /session\.execute\(commands\.insertNode/);
	assert.doesNotMatch(source, /innerHTML\s*=/);
});

test('Mail registry declares new nodes and explicit safe formatting fields', async () => {
	const registry = await get('src/Mail/Designer/ComponentRegistry.php');
	assert.match(registry, /'node_type'\s*=>\s*'mail\.rich-text'/);
	assert.match(registry, /'node_type'\s*=>\s*'mail\.columns'/);
	assert.match(registry, /'ratio'\s*=>\s*'equal'/);
	assert.match(registry, /'gap'\s*=>\s*16/);
	assert.match(registry, /'key'\s*=>\s*'markup'/);
});

test('Renderer sanitizes rich-text HTML and uses table-based responsive columns', async () => {
	const renderer = await get('src/Design/Profile/Mail/HtmlRenderer.php');
	const richText = await get('src/Design/Profile/Mail/RichText.php');
	const validator = await get('src/Design/Profile/Mail/Validator.php');
	assert.match(renderer, /'mail\.rich-text'\s*=>\s*\$this->render_rich_text/);
	assert.match(renderer, /'mail\.columns'\s*=>\s*\$this->render_columns/);
	assert.match(renderer, /class="cb-mail-columns"/);
	assert.match(renderer, /class="cb-mail-column"/);
	assert.match(renderer, /\.cb-mail-column\{display:block!important/);
	assert.match(richText, /wp_kses\(/);
	assert.match(richText, /esc_html\(/);
	assert.doesNotMatch(richText, /'script'\s*=>/);
	assert.doesNotMatch(richText, /'style'\s*=>/);
	assert.match(validator, /mail\.columns_count/);
	assert.match(validator, /mail\.column_parent/);
	assert.match(validator, /mail\.rich_text_markup/);
});
