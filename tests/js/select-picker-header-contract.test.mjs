import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const root = new URL('../../', import.meta.url);
const js = readFileSync(new URL('assets/js/core/select-picker.js', root), 'utf8');
const css = readFileSync(new URL('assets/css/components/pickers.css', root), 'utf8');
const ui = readFileSync(new URL('src/UI/Assets.php', root), 'utf8');

test('Header API v1 is declared only alongside the implemented component', () => {
	assert.match(ui, /public const SELECT_PICKER_HEADER_API = 1;/);
	assert.match(ui, /function enqueue_select_picker\( \?string \$presentation = null \)/);
	assert.match(js, /select\.dataset\.cbCoreSelectPickerVariant/);
	assert.match(js, /cb-core-select-picker--header/);
	assert.match(css, /\.cb-core-select-picker--header \.cb-core-picker__toggle/);
});

test('Unsupported top-layer browsers keep the accessible native select', () => {
	const fallback = js.indexOf("if (header && (typeof HTMLElement.prototype.showPopover");
	const move = js.indexOf("select.before(root)");
	assert.ok(fallback >= 0 && move > fallback, 'fallback must run before the native select is moved');
	assert.match(js, /typeof HTMLElement\.prototype\.hidePopover !== 'function'\)\) return null/);
	assert.match(js, /select\.hidden = true/);
	assert.match(js, /select\.dispatchEvent\(new Event\('change', \{ bubbles: true \}\)\)/);
});

test('Header menu uses the browser top-layer with bounded viewport geometry and close rules', () => {
	assert.match(js, /panel\.setAttribute\('popover', 'manual'\)/);
	assert.match(js, /panel\.showPopover\(\)/);
	assert.match(js, /panel\.hidePopover\(\)/);
	assert.match(js, /Math\.min\(Math\.max\(280, anchor\.width\)/);
	assert.match(js, /window\.addEventListener\('scroll', onHeaderViewportChange/);
	assert.match(js, /window\.removeEventListener\('scroll', onHeaderViewportChange, true\)/);
	assert.match(js, /window\.addEventListener\('resize'/);
	assert.match(css, /\.cb-core-select-picker__panel\[popover\]/);
	assert.match(css, /max-height: calc\(100vh - 16px\)/);
});

test('A missing browser Popover API executes native fallback without changing the select', () => {
	class FakeSelect {}
	class FakeElement {}
	const handlers = new Map();
	const context = {
		document: {
			getElementById: () => null,
			readyState: 'loading',
			addEventListener: (type, handler) => handlers.set(type, handler),
		},
		window: {},
		HTMLSelectElement: FakeSelect,
		HTMLElement: FakeElement,
	};
	runInNewContext(js, context, { timeout: 1000 });
	const select = new FakeSelect();
	select.multiple = false;
	select.dataset = { cbCoreSelectPickerVariant: 'header' };
	select.options = [];
	select.parentNode = {};
	const outcome = context.window.cbCore.selectPicker.enhance(select);
	assert.equal(outcome, null);
	assert.equal(select.hidden, undefined);
	assert.equal(select.dataset.cbCoreSelectPickerReady, undefined);
	assert.equal(handlers.has('DOMContentLoaded'), true);
});
