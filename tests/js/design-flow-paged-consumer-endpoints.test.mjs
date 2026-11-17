import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import assert from 'node:assert/strict';

const file = readFileSync(new URL('../../assets/js/design/document/flow/paged-preview-host.js', import.meta.url), 'utf8');
const from = file.indexOf('const endpointUrl = (endpoint, baseUrl, namespace) => {');
const to = file.indexOf('export const createFlowPagedPreviewHost', from);
assert.ok(from >= 0 && to > from, 'real paged host exposes narrow endpoint boundary');
const endpointUrl = new Function('URL', file.slice(from, to) + '\nreturn endpointUrl;')(URL);
const base = 'https://example.test/wp-admin/admin.php';
const external = 'core-blueprint-contracts/v1';
const native = 'core-blueprint/v1';

test('native Reports preview retains its original versioned endpoint', () => {
	assert.equal(endpointUrl('https://example.test/wp-json/core-blueprint/v1/reports-designer/preview-paged', base, native),
		'https://example.test/wp-json/core-blueprint/v1/reports-designer/preview-paged');
});

test('explicit external Document Flow consumer namespace uses same REST transport', () => {
	assert.equal(endpointUrl('https://example.test/wp-json/core-blueprint-contracts/v1/template-preview/preview-paged', base, external),
		'https://example.test/wp-json/core-blueprint-contracts/v1/template-preview/preview-paged');
	assert.equal(endpointUrl('https://example.test/?rest_route=%2Fcore-blueprint-contracts%2Fv1%2Ftemplate-preview%2Fpreview-paged', base, external).includes('rest_route='), true);
});

test('cross-namespace and non-preview POST targets remain forbidden', () => {
	for (const url of [
		'https://example.test/wp-json/core-blueprint/v1/reports-designer/preview-paged',
		'https://example.test/wp-admin/admin-ajax.php',
		'https://example.test/wp-json/core-blueprint-contracts/v1/template-preview/save',
		'https://example.test/wp-json/core-blueprint-contracts/v1/template-preview/preview-paged?nonce=one',
		'https://other.test/wp-json/core-blueprint-contracts/v1/template-preview/preview-paged',
	]) {
		assert.throws(() => endpointUrl(url, base, external), {name: 'TypeError'});
	}
});

test('host source advertises exact consumer opt-in without weakening payload bounds', () => {
	assert.match(file, /endpointNamespace = 'core-blueprint\/v1'/);
	assert.match(file, /endpointUrl\(endpoint, baseUrl, endpointNamespace\)/);
	assert.match(file, /MAX_PDF_BYTES = 8 \* 1024 \* 1024/);
	assert.match(file, /validateFlowPageProjection\(envelope.projection\)/);
});
