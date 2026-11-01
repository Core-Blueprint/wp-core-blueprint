import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import {runInNewContext} from 'node:vm';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const guardSource = read('assets/js/features/designer-exit-guard.js');
const launchSource = read('assets/js/features/designer-launch.js');
const contextSource = read('assets/js/features/designer-context.js');

test('CX02E: Context Picker calls only the public managed v4 transaction', () => {
	const guard = read('assets/js/features/designer-exit-guard.js');
	const launch = read('assets/js/features/designer-launch.js');
	const picker = read('assets/js/features/designer-context.js');
	const php = read('src/Design/Editor/Assets.php');
	assert.match(guard,/contractVersion:4/);
	assert.match(php,/public const DESIGNER_EXIT_GUARD_API = 4;/);
	assert.match(picker,/guard\.transition\(shell, \{targetId: value\}\)/);
	assert.match(picker,/activeRoots\.add\(root\)/);
	assert.match(picker,/control\.value = previousValue/);
	assert.match(picker,/outcome\?\.status === 'applied'/);
	assert.doesNotMatch(guard+picker+launch,
		/requestTransition|isTransitionDispatchAuthorized|contextrequest|respondWith/);
	assert.match(launch,/bindExitGuard\(shell, fullscreen\)/);
});

test('B1 native Mail/Reports still use their Host-specific transport', () => {
	const assets = read('src/Design/Editor/Assets.php');
	const native = read('assets/js/design/platform/wordpress-save-adapter.js');
	assert.match(assets, /public static function enqueue_platform\(\): void/);
	assert.match(assets, /@cb-core\/mail-platform/);
	assert.match(assets, /@cb-core\/reports-platform/);
	assert.match(native, /attachTrustedDesignerHost/);
	assert.match(native, /transitionSafe/);
});


test('B1 Header Picker readiness handles both module and classic-first orders exactly once', () => {
  const start = launchSource.indexOf('\tconst enhanceHeaderContext =');
  const end = launchSource.indexOf('\n\tconst composeHeader =', start);
  assert.ok(start > 0 && end > start, 'shared header enhancement helpers are present');
  const helpers = launchSource.slice(start, end);
  const expression = '(() => { ' + helpers +
    "\n document.addEventListener('cb:select-picker:ready', enhanceReadyDesignerContexts);" +
    "\n enhanceReadyDesignerContexts();" +
    "\n return { enhanceReadyDesignerContexts, enhanceHeaderContext }; })()";
  for (const moduleFirst of [true, false]) {
    class HTMLSelectElement {
      constructor() { this.multiple = false; this.dataset = {}; }
    }
    const select = new HTMLSelectElement();
    const context = { querySelectorAll: () => [select] };
    const toolbar = {
      dataset: { cbDesignShellHeader: 'true' },
      querySelector: () => context,
    };
    const root = { querySelector: () => toolbar };
    const listeners = new Map();
    const document = {
      querySelectorAll: () => [root],
      addEventListener(type, fn) { listeners.set(type, fn); },
    };
    let count = 0;
    const picker = { enhance(target, options) {
      assert.equal(target, select);
      assert.equal(options.variant, 'header');
      count++;
      select.dataset.cbCoreSelectPickerReady = '1';
    } };
    const window = { cbCore: moduleFirst ? {selectPicker:picker} : {} };
    const api = runInNewContext(expression, {document, window, HTMLSelectElement});
    if (!moduleFirst) {
      window.cbCore.selectPicker = picker;
      listeners.get('cb:select-picker:ready')();
    }
    api.enhanceReadyDesignerContexts();
    assert.equal(count, 1, moduleFirst ? 'module-first' : 'classic-first');
  }
});

test('B1 Native Mail imports one Picker module and restores blocked selection paint', () => {
  const native = read('assets/js/design/platform/wordpress-save-adapter.js');
  const php = read('src/Design/Editor/Assets.php');
  assert.match(php, /enqueue_select_picker\( \\CoreBlueprint\\Core\\UI\\Assets::SELECT_PICKER_PRESENTATION_CORE \)/);
  assert.match(native, /import '@cb-core\/select-picker';/);
  assert.match(php, /'@cb-core\/reports-platform', '@cb-core\/select-picker'/);
  assert.ok(native.indexOf('await host.mount(') < native.indexOf('contextSlot.append(contextControl);'));
  assert.ok(native.indexOf('contextSlot.append(contextControl);') < native.indexOf("picker.enhance(selected,{variant:'header'});"));

  const start = native.indexOf("selected.addEventListener('change',()=>{");
  const end = native.indexOf("\n\t\t\t});", start);
  assert.ok(start > 0 && end > start, 'native change handler is present');
  let onChange = null, paints = 0, navigation = 0, reentrantChanges = 0;
  const selected = {
    value: 'next', dataset: {cbCoreSelectPickerReady:'1'},
    addEventListener(type, cb) { if (type === 'change') onChange = cb; },
    dispatchEvent(event) {
      if (event.type === 'cb:select-picker-sync') paints++;
      if (event.type === 'change') reentrantChanges++;
      return true;
    },
  };
  const resourceKey = 'original';
  const transitionSafe = () => false;
  const location = {href:'https://example.test/?template=original', assign(){navigation++;}};
  runInNewContext(native.slice(start, end + 7), {
    selected, resourceKey, transitionSafe, location,
    Event: class {constructor(type){this.type=type;}},
  });
  assert.equal(typeof onChange, 'function');
  onChange();
  assert.equal(selected.value, 'original');
  assert.equal(paints, 1);
  assert.equal(reentrantChanges, 0, 'visual rollback cannot dispatch a second change');
  assert.equal(navigation, 0);
});

test('Native adapter retains canonical fullscreen Exit Guard v4 without a second unload authority', () => {
  const native = read('assets/js/design/platform/wordpress-save-adapter.js');
  const php = read('src/Design/Editor/Assets.php');
  assert.match(php, /public const DESIGNER_EXIT_GUARD_API = 4;/);
  assert.match(native, /discard:\(\)=>host\.discard\(\)/);
  assert.match(native, /exitGuard\.request\(root,/);
  assert.match(native, /window\.addEventListener\('pagehide'/);
  assert.doesNotMatch(native, /window\.addEventListener\('beforeunload'/);
});
