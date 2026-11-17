import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const factory = readFileSync(new URL('../../assets/js/design/shell/page-navigation-control.js', import.meta.url), 'utf8');
const editor = readFileSync(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
const code = factory.replace("import { decorateDesignerControl } from './icons.js';", '')
	.replace('export const createDesignerPageNavigationControl =', 'const createDesignerPageNavigationControl =');
const make = decorateDesignerControl => runInNewContext(code + '\ncreateDesignerPageNavigationControl(doc);', {
	doc: {createElement: tag => ({tag, attributes: new Map(), children: [],
		setAttribute(k, v) {this.attributes.set(k, String(v));},
		append(n) {this.children.push(n);}})},
	decorateDesignerControl,
}, {timeout: 1000});

test('native Reports and consumer shells share one Base control factory', () => {
	assert.match(editor, /centerZone\.append\(createDesignerPageNavigationControl\(doc\)\)/);
	assert.match(editor, /createPageNavigationControl: createDesignerPageNavigationControl/);
});

test('factory renders one disabled, accessible icon-only Pages control', () => {
	const calls = [];
	const group = make((button, icon, opts) => {calls.push({button, icon, opts}); return true;});
	assert.match(group.className, /cb-core-design-shell__page-navigation/);
	assert.equal(group.attributes.get('data-cb-design-shell-page-navigation-group'), '');
	assert.equal(group.attributes.get('role'), 'group');
	assert.equal(group.children.length, 1);
	assert.equal(group.children[0].attributes.get('data-cb-design-shell-page-navigation'), '');
	assert.equal(group.children[0].disabled, true);
	assert.equal(calls.length, 1);
	assert.equal(calls[0].icon, 'file-text');
	assert.equal(calls[0].opts.iconOnly, true);
	assert.equal(calls[0].opts.label, 'Page navigation unavailable');
});

test('factory fails closed when Base icon decoration is unavailable', () => {
	assert.throws(() => make(() => false), /DESIGNER_PAGE_NAVIGATION_ICON_UNAVAILABLE/);
});
