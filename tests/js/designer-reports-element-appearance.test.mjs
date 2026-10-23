import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';
import {layerSummaryFor, propertiesFor, resolveCommand} from '../../assets/js/design/reports/platform-adapter.js';

const node = (type, properties) => ({type, provider: 'core.blueprint', properties, children: []});

test('Reports Layers identify Section, Heading, Paragraph and Metric without persisting labels', () => {
	assert.equal(layerSummaryFor(node('reports.section', {title: 'Executive summary'})), 'Executive summary');
	assert.equal(layerSummaryFor(node('reports.heading', {text: 'Project status'})), 'Project status');
	assert.equal(layerSummaryFor(node('reports.paragraph', {text: 'Multiple\n  lines\t of text'})), 'Multiple lines of text');
	assert.equal(layerSummaryFor(node('reports.metric', {label: 'Updates completed', value: '42'})), 'Updates completed');
	assert.equal(layerSummaryFor(node('reports.status', {enabled: 'on'})), '');
	const long = '🌱'.repeat(50);
	assert.equal(layerSummaryFor(node('reports.heading', {text: long})), '🌱'.repeat(44) + '…');
	assert.equal(layerSummaryFor(node('reports.heading', {text: '   '})), '');
	assert.equal(layerSummaryFor(null), '');
});

test('Layers summary remains presentation-only and all typed editing commands stay authoritative', () => {
	const heading = node('reports.heading', {text: 'First'});
	const original = JSON.stringify(heading);
	assert.equal(layerSummaryFor(heading), 'First');
	assert.equal(JSON.stringify(heading), original);
	assert.equal(propertiesFor(heading)[0].value, 'First');
	assert.ok(resolveCommand('reports.set'));
	assert.equal(Object.hasOwn(heading.properties, 'layerSummary'), false);
});

test('Shared Layers use Reports-derived context and plain DOM labels while preserving Mail mode', async () => {
	const editor = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
	const layers = await readFile(new URL('../../assets/js/design/shell/layers.js', import.meta.url), 'utf8');
	assert.match(editor, /activeRegistry\.profileId === 'base\/reports'[\s\S]*?profileModule\.layerSummaryFor\?\.\(node\)/);
	assert.match(editor, /label: displayLabel/);
	assert.match(editor, /typeLabel \+ ' · ' \+ summary/);
	assert.match(editor, /editor\.execute\(prefix \+ '\.set'/);
	assert.match(layers, /labelNode\.textContent = normalizedLabel/);
});

test('Hover outlines and empty field placeholders are preview-only and do not override Flow PDF styles', async () => {
	const html = await readFile(new URL('../../src/Design/Profile/Document/Flow/HtmlRenderer.php', import.meta.url), 'utf8');
	assert.match(html, /private const PREVIEW_EDITING_CSS/);
	assert.match(html, /self::PREVIEW_EDITING_CSS/);
	assert.match(html, /data-cb-flow-preview-region\]:hover:not\(\[data-cb-flow-preview-selected\]\)/);
	assert.match(html, /\.cb-flow-text\[data-cb-flow-preview-region\]:empty/);
	assert.match(html, /\.cb-flow-heading\[data-cb-flow-preview-region\]/);
	const render = html.slice(html.indexOf('public function render('), html.indexOf('public function render_preview('));
	assert.doesNotMatch(render, /PREVIEW_EDITING_CSS/);
});
