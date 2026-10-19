import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext, Script } from 'node:vm';
import test from 'node:test';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const js = read('assets/js/core/select-picker.js');
const core = read('assets/css/components/pickers.css');
const native = read('assets/css/components/pickers-native.css');
const assets = read('src/UI/Assets.php');

test('SP-H01: header extends public Select Picker without a parallel component engine', () => {
  assert.doesNotThrow(() => new Script(js));
  assert.match(js, /options\.variant \?\? select\.dataset\.cbCoreSelectPickerVariant \?\? 'field'/);
  assert.match(js, /if \(variant !== 'field' && variant !== 'header'\) return null/);
  assert.match(js, /if \(headerMode\) root\.classList\.add\('cb-core-select-picker--header'\)/);
  assert.match(js, /if \(headerMode && typeof document\.createElement\('div'\)\.showPopover !== 'function'\) return null/);
  assert.match(js, /select\.dispatchEvent\(new Event\('change', \{ bubbles: true \}\)\)/);
  assert.match(js, /select\.addEventListener\('change', sync\)/);
  assert.match(js, /window\.cbCore\.selectPicker = Object\.freeze\(\{ init, enhance \}\)/);
  assert.match(assets, /public static function enqueue_select_picker\(/);
  assert.match(assets, /public const SELECT_PICKER_HEADER_API = 1;/);
  assert.match(assets, /self::asset_revision\( 'assets\/js\/core\/select-picker.js' \)/);
  assert.match(assets, /self::asset_revision\( 'assets\/css\/components\/pickers.css' \)/);
  assert.match(assets, /'@cb-core\/select-picker'/);
});

test('SP-H02: header popover escapes rail clipping with bounded lifecycle and keyboard recovery', () => {
  assert.match(js, /panel\.setAttribute\('popover', 'manual'\)/);
  assert.match(js, /panel\.showPopover\(\)/);
  assert.match(js, /panel\.hidePopover\(\)/);
  assert.match(js, /window\.addEventListener\('scroll', onHeaderViewportChange, \{ capture: true, passive: true \}\)/);
  assert.match(js, /window\.removeEventListener\('scroll', onHeaderViewportChange, true\)/);
  assert.match(js, /event\.key === 'Escape'/);
  assert.match(js, /close\(\{ focus: true \}\)/);
  assert.match(js, /select\.getAttribute\('aria-label'\)/);
  assert.match(js, /toggle\.setAttribute\('aria-label', externalLabel \+ ': ' \+ title\.textContent\)/);
  assert.match(js, /toggle\.setAttribute\('aria-expanded', 'true'\)/);
  assert.match(js, /focusOption\(index >= 0 \? index : 0\)/);
  assert.match(js, /select\.disabled/);
});

test('SP-H03: popup positioning clamps to viewport and flips above the trigger', () => {
  const start = js.indexOf('\tconst locateHeaderPanel = () => {');
  const end = js.indexOf('\n\tconst onHeaderViewportChange', start);
  assert.ok(start >= 0 && end > start);
  const panel = {
    hidden: false,
    style: {},
    getBoundingClientRect: () => ({ width: 248, height: 140 }),
  };
  const trigger = { getBoundingClientRect: () => ({ left: 520, top: 30, bottom: 65 }) };
  const document = { documentElement: { clientWidth: 680, clientHeight: 420 } };
  const locate = runInNewContext(
    '(() => { ' + js.slice(start, end) + ' return locateHeaderPanel; })()',
    { panel, toggle: trigger, document, headerMode: true }
  );
  locate();
  assert.equal(panel.style.left, '420px', 'horizontal position clamps to right viewport gutter');
  assert.equal(panel.style.top, '73px', 'menu opens below trigger when space permits');
  trigger.getBoundingClientRect = () => ({ left: 20, top: 340, bottom: 380 });
  locate();
  assert.equal(panel.style.left, '20px');
  assert.equal(panel.style.top, '192px', 'menu flips above the trigger if lower space is too small');
  panel.hidden = true;
  locate();
  assert.equal(panel.style.top, '192px', 'closed popup never moves');
});

test('SP-H04: header has dedicated tokenized chrome while existing field and native adapters remain untouched', () => {
  assert.match(core, /\.cb-core-picker\.cb-core-select-picker--header \{/);
  assert.match(core, /\.cb-core-select-picker--header \.cb-core-select-picker__panel \{/);
  assert.match(core, /position: fixed;/);
  assert.match(core, /min-block-size: var\(--cb-button-height-compact\)/);
  assert.match(core, /background: transparent;/);
  assert.match(core, /box-shadow: var\(--cb-shadow-lg\)/);
  assert.match(core, /var\(--cb-surface-2\)/);
  assert.match(core, /var\(--cb-accent\)/);
  assert.match(core, /\.cb-core-select-picker__option\.is-selected::after/);
  assert.match(native, /\.cb-core-select-picker \{ width:100%; \}/);
  assert.match(native, /\.cb-core-picker\.cb-core-select-picker--header/);
  assert.match(native, /\.cb-core-select-picker--header \.cb-core-select-picker__panel/);
  assert.match(core, /\.cb-core-wrap \.cb-core-picker__toggle,/);
});
