import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';

const read = relative => readFileSync(new URL('../../' + relative, import.meta.url), 'utf8');
const nativeCSS = read('assets/css/design/platform-fullscreen.css');
const mailCSS = read('assets/css/pages/mail-designer.css');
const renderer = read('assets/js/design/mail/platform-adapter.js');

test('FIELD-06C R3: canonical Mail document width remains renderer-owned', () => {
	assert.match(renderer, /frame\.style\.maxWidth\s*=\s*p\.width\s*\+\s*'px'/);
	assert.match(renderer, /frame\.style\.margin\s*=\s*'0 auto'/);
	assert.match(mailCSS, /\.cb-mail-platform__frame\s*\{\s*width: 100%;/);
	assert.match(nativeCSS, /\.cb-core-design-shell--native \.cb-mail-platform\s*\{\s*padding: 0;/);
});

test('FIELD-06C R3: tablet/mobile preview caps cannot override document max-width', () => {
	for (const [device, cap] of [['mobile', 430], ['tablet', 782]]) {
		const selector = '.cb-core-design-shell--native[data-cb-design-shell-viewport="' +
			device + '"] .cb-mail-platform__frame';
		const index = nativeCSS.indexOf(selector + ' {');
		assert.ok(index !== -1, device + ' preview rule must be present');
		const rule = nativeCSS.slice(index, nativeCSS.indexOf('}', index) + 1);
		assert.match(rule, new RegExp('width:\\s*min\\(' + cap + 'px,\\s*100%\\) !important;'));
		assert.doesNotMatch(rule, /max-width\s*:/,
			'Only Mail renderer may set the document width ceiling');
	}
});

test('FIELD-06C R3: Reports keeps independent document-view sizing', () => {
	assert.match(nativeCSS,
		/\[data-cb-design-shell-document-view="fixed"\] \.cb-reports-platform__preview-frame\s*\{\s*width: 860px;\s*max-width: none;/);
});
