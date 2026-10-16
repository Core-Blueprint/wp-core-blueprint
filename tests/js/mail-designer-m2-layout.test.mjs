import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { MAIL_DESIGN_TYPE, validateProject } from '../../assets/js/design/mail/index.js';

const source = (name) => readFile(new URL('../../' + name, import.meta.url), 'utf8');
const node = (type, properties = {}, children = []) => ({
	type, provider: 'core', properties, children,
});
const documentWithColumns = (properties = {}, columns = [
	node('mail.column', { background: '#ffffff', padding: 12 }, [
		node('mail.heading', { text: 'Editorial' }),
	]),
	node('mail.column', { background: '#f8fafc', padding: 8 }, [
		node('mail.text', { text: 'Details' }),
	]),
]) => ({
	schema_version: 0,
	design_type: MAIL_DESIGN_TYPE,
	root: node('mail.root', { layout: { width: 600 } }, [
		node('mail.section', { padding: 24 }, [
			node('mail.columns', { ratio: 'left-wide', gap: 16, ...properties }, columns),
		]),
	]),
});

test('Mail JS project schema accepts isolated M2 styled column objects', () => {
	const design = validateProject(documentWithColumns());
	assert.equal(design.design_type, MAIL_DESIGN_TYPE);
	assert.equal(design.root.children[0].children[0].children[0].properties.padding, 12);
	assert.equal(design.root.children[0].children[0].children[1].properties.background, '#f8fafc');
});

test('M2 Inspector exposes column background/padding without adding them to canvas toolbar', async () => {
	const consumer = await source('assets/js/features/mail-designer.js');
	const toolbar = await source('assets/js/design/mail/inline-toolbar.js');
	assert.match(consumer, /const columnDefinition = Object\.freeze\(\{/);
	assert.match(consumer, /node_type: 'mail\.column'/);
	assert.match(consumer, /label: 'Column background', type: 'color'/);
	assert.match(consumer, /label: 'Inner padding', type: 'number', min: 0, max: 32/);
	assert.match(consumer, /properties: structuredClone\(columnDefinition\.defaults\)/);
	assert.match(consumer, /return columnDefinition/);
	assert.doesNotMatch(toolbar, /commands\.setProperty|fontSize|accentColor/);
});

test('M2 width presets exist in renderer and canonical PHP component registry', async () => {
	const renderer = await source('src/Design/Profile/Mail/HtmlRenderer.php');
	const registry = await source('src/Mail/Designer/ComponentRegistry.php');
	assert.match(renderer, /'left-wide'\s*=>\s*\[\s*60,\s*40\s*\]/);
	assert.match(renderer, /'right-wide'\s*=>\s*\[\s*40,\s*60\s*\]/);
	assert.match(registry, /'left-wide'\s*=>\s*__\(\s*'60 \/ 40'/);
	assert.match(registry, /'right-wide'\s*=>\s*__\(\s*'40 \/ 60'/);
});

test('M2 renderer uses escaped email-table background and inner-cell padding', async () => {
	const renderer = await source('src/Design/Profile/Mail/HtmlRenderer.php');
	assert.match(renderer, /\$column_padding = \$this->int_range\(/);
	assert.match(renderer, /sanitize_hex_color\(/);
	assert.match(renderer, /\$background_attribute = '' !== \$column_bg/);
	assert.match(renderer, /bgcolor="/);
	assert.match(renderer, /<tr><td style="padding:/);
	assert.match(renderer, /role="presentation"/);
	assert.match(renderer, /'mail\.columns' => \$this->render_columns/);
});

test('M2 structural validator prevents table-layout nesting that breaks mail clients', async () => {
	const validator = await source('src/Design/Profile/Mail/Validator.php');
	assert.match(validator, /mail\.column_structural_child/);
	assert.match(validator, /mail\.section_parent/);
	assert.match(validator, /mail\.columns_parent/);
	assert.match(validator, /mail\.column_parent/);
});

test('Legacy columns are optional style additions, no migration required', async () => {
	const renderer = await source('src/Design/Profile/Mail/HtmlRenderer.php');
	assert.match(renderer, /\$column_properties\['padding'\] \?\? 0/);
	assert.match(renderer, /\$column_padding > 0/);
	assert.match(renderer, /'' !== \$column_bg \? ' bgcolor="/);
	assert.match(renderer, /default => \[ 50, 50 \]/);
});

test('Client-side Mail validator rejects orphan and nested structural containers', () => {
	const make = () => documentWithColumns();
	const malformed = [
		(project) => { project.root.children[0].children[0].children.pop(); },
		(project) => { project.root.children[0].children[0].children[0].type = 'mail.section'; },
		(project) => { project.root.children[0].children[0].children[0].children.push(node('mail.section')); },
		(project) => { project.root.children[0].children[0].children[0].children.push(node('mail.columns')); },
		(project) => { project.root.children.push(node('mail.column')); },
		(project) => { project.root.children[0].children[0].type = 'mail.column'; },
	];
	for (const mutate of malformed) {
		const project = make();
		mutate(project);
		assert.throws(() => validateProject(project), TypeError);
	}
});

test('M2 structural guards still allow ordinary provider-owned leaves in a column', () => {
	const project = documentWithColumns();
	project.root.children[0].children[0].children[0].children.push({
		type: 'example.product',
		provider: 'example',
		properties: { id: 'sku-1' },
		children: [],
	});
	assert.equal(validateProject(project).design_type, MAIL_DESIGN_TYPE);
});
