import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('../../', import.meta.url);

test('Flow browser modules stay profile-local and renderer-free', () => {
	const dir = new URL('assets/js/design/document/flow/', root);
	for (const file of readdirSync(dir).filter((name) => name.endsWith('.js'))) {
		const code = readFileSync(join(dir.pathname, file), 'utf8');
		assert.doesNotMatch(code, /design\/document\/fixed|PdfApi|Dompdf|Renderer/i, file);
	}
});

test('Shared editor core does not import Flow or Document modules', () => {
	const dir = new URL('assets/js/design/core/', root);
	for (const file of readdirSync(dir).filter((name) => name.endsWith('.js'))) {
		const code = readFileSync(join(dir.pathname, file), 'utf8');
		assert.doesNotMatch(code, /design\/document|\/flow\//i, file);
	}
});

test('DP-NAV-02C deferred PDF.js loader preserves Base static module admission', () => {
	const adapter = readFileSync(
		new URL('assets/js/design/document/flow/pdfjs-page-source.js', root), 'utf8',
	);
	const bridge = readFileSync(
		new URL('assets/js/document/pdfjs-loader.js', root), 'utf8',
	);
	assert.match(adapter, /from ['"]\.\.\/\.\.\/\.\.\/document\/pdfjs-loader\.js['"]/);
	assert.doesNotMatch(adapter, /\bimport\s*\(/,
		'Designer source must contain only static imports');
	assert.equal((bridge.match(/\bimport\s*\(/g) || []).length, 1);
	assert.match(bridge, /import\(['"]@cb-core\/pdfjs-display['"]\)/);
	assert.doesNotMatch(bridge, /https?:\/\/|fetch\s*\(/,
		'The PDF.js bridge may not request external scripts or URLs');
});
