import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext, Script } from 'node:vm';
import test from 'node:test';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const js = read('assets/js/core/select-picker.js');
const css = read('assets/css/components/pickers.css');
const native = read('assets/css/components/pickers-native.css');
const assets = read('src/UI/Assets.php');

test('SP-H01: one canonical Picker engine, public Header API and native fallback', () => {
  assert.doesNotThrow(() => new Script(js));
  assert.match(js, /const enhance = \(select, options = \{\}\) => \{/);
  assert.match(js, /window\.cbCore\.selectPicker = Object\.freeze\(\{ init, enhance \}\)/);
  assert.match(assets, /public const SELECT_PICKER_HEADER_API = 1;/);
  const fallback = js.indexOf("if (header && (typeof HTMLElement.prototype.showPopover");
  assert.ok(fallback >= 0 && js.indexOf('select.before(root)') > fallback);
  assert.match(js, /select\.dispatchEvent\(new Event\('change', \{ bubbles: true \}\)\)/);
});

test('SP-H02: temporary header viewport and focus listeners are removed after close', () => {
  assert.match(js, /window\.addEventListener\('scroll', onHeaderViewportChange/);
  assert.match(js, /window\.removeEventListener\('scroll', onHeaderViewportChange, true\)/);
  assert.match(js, /window\.removeEventListener\('resize', onHeaderViewportChange\)/);
  assert.match(js, /document\.removeEventListener\('focusin', onHeaderFocusOutside\)/);
  assert.match(js, /panel\.addEventListener\('toggle'/);
  assert.match(js, /close\(\{ focus: true \}\)/);
  assert.match(js, /event\.key === 'Escape'/);
});

test('SP-H03: single popup keeps width bounded and follows viewport flips', () => {
  const start = js.indexOf('\tconst locateHeaderPanel = () => {');
  const end = js.indexOf('\n\tconst onHeaderViewportChange', start);
  assert.ok(start >= 0 && end > start, 'Header locator must remain a pure extractable function');
  const anchor = { left: 520, top: 30, bottom: 65, width: 248 };
  const panel = { hidden: false, style: {}, getBoundingClientRect: () => ({ width: 280, height: 140 }) };
  const toggle = { getBoundingClientRect: () => anchor };
  const window = { innerWidth: 680, innerHeight: 420 };
  const locate = runInNewContext(
    '(() => { ' + js.slice(start, end) + ' return locateHeaderPanel; })()',
    { header: true, panel, toggle, window }
  );
  locate();
  assert.equal(panel.style.left, '392px', 'clamped away from right viewport edge');
  assert.equal(panel.style.top, '71px', 'below toggle when room exists');
  Object.assign(anchor, { left: 20, top: 340, bottom: 380 });
  locate();
  assert.equal(panel.style.top, '194px', 'flips above when popup would overflow bottom');
  panel.hidden = true;
  locate();
  assert.equal(panel.style.top, '194px', 'closed popup stays stationary');
});

test('SP-H04: header stays labeled and uses one tokenized styling and native adapter', () => {
  assert.match(js, /select\.getAttribute\('aria-label'\)/);
  assert.match(js, /toggle\.setAttribute\('aria-label', externalLabel \+ ': ' \+ title\.textContent\)/);
  assert.match(js, /toggle\.setAttribute\('aria-expanded', 'true'\)/);
  assert.match(css, /\.cb-core-select-picker--header/);
  assert.match(css, /\.cb-core-select-picker__panel\[popover\]/);
  assert.match(native, /\.cb-core-select-picker/);
  assert.match(assets, /'@cb-core\/select-picker'/);
});
