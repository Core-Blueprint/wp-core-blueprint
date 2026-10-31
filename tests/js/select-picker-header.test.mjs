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


// Small synthetic DOM exercising the real controller, Popover API, focus, frames and events.
const pickerFixture = ({ variant = 'header', count = 3, selected = 'v2', disabled = [], popover = true, search } = {}) => {
  const frames = new Map();
  let nextFrame = 0;
  const doc = {
    readyState: 'loading', activeElement: null, listeners: new Map(),
    addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); },
    removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); },
    getElementById() { return null; }, querySelector() { return null; }, querySelectorAll() { return []; },
    createElement(tag) { return new El(tag); },
  };
  class El {
    constructor(tag = 'div') {
      this.tagName = tag.toUpperCase(); this.children = []; this.parentNode = null;
      this.parentElement = null; this.dataset = {}; this.attributes = {}; this.style = {};
      this.className = ''; this._text = ''; this.hidden = false; this.disabled = false;
      this.isConnected = true; this.isOpen = false; this.listeners = new Map();
    }
    get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }
    set textContent(value) { this._text = String(value); this.children = []; }
    get classList() {
      const el = this;
      return {
        contains(name) { return el.className.split(/\s+/).includes(name); },
        add(name) { if (!this.contains(name)) el.className = (el.className + ' ' + name).trim(); },
        remove(name) { el.className = el.className.split(/\s+/).filter((v) => v !== name).join(' '); },
        toggle(name, enabled) { if (enabled === undefined ? !this.contains(name) : enabled) this.add(name); else this.remove(name); },
      };
    }
    appendChild(child) {
      if (child.parentNode) child.parentNode.children = child.parentNode.children.filter((c) => c !== child);
      child.parentNode = this; child.parentElement = this; this.children.push(child); return child;
    }
    append(...children) { children.forEach((c) => this.appendChild(c)); }
    before(node) {
      const parent = this.parentNode;
      if (!parent) return;
      parent.children.splice(parent.children.indexOf(this), 0, node);
      node.parentNode = parent; node.parentElement = parent;
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name] ?? null; }
    addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); }
    removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
    dispatchEvent(event) { event.target ??= this; for (const fn of this.listeners.get(event.type) ?? []) fn(event); return true; }
    contains(target) { return this === target || this.children.some((child) => child.contains(target)); }
    closest() { return null; }
    matches(selector) { return selector === ':popover-open' && this.isOpen; }
    showPopover() { this.isOpen = true; }
    hidePopover() { this.isOpen = false; }
    focus() { doc.activeElement = this; }
    getBoundingClientRect() { return { left: 120, top: 24, bottom: 56, width: 240, height: 160 }; }
    querySelectorAll(selector) {
      const matches = [];
      const visit = (node) => {
        for (const child of node.children) {
          const option = child.classList.contains('cb-core-select-picker__option');
          if (selector === '[data-value]' && Object.hasOwn(child.dataset, 'value')) matches.push(child);
          if (selector === '.cb-core-select-picker__option:not(:disabled)' && option && !child.disabled) matches.push(child);
          if (selector === '.cb-core-select-picker__search' && child.classList.contains('cb-core-select-picker__search')) matches.push(child);
          visit(child);
        }
      };
      visit(this);
      return matches;
    }
  }
  class NativeOption extends El {
    constructor(value) { super('option'); this.value = value; this.textContent = value; this.disabled = disabled.includes(value); }
  }
  class NativeGroup extends El { constructor() { super('optgroup'); this.label = ''; } }
  class NativeSelect extends El {
    constructor() { super('select'); this.value = ''; this.multiple = false; }
    get options() { return this.children.flatMap((child) => child instanceof NativeGroup ? child.children : [child]); }
    get selectedOptions() { return this.options.filter((option) => option.value === this.value).slice(0, 1); }
  }
  if (!popover) { El.prototype.showPopover = undefined; El.prototype.hidePopover = undefined; }
  const win = {
    innerWidth: 640, innerHeight: 480, cbCore: {}, listeners: new Map(),
    addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); },
    removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); },
    requestAnimationFrame(fn) { const id = ++nextFrame; frames.set(id, fn); return id; },
    cancelAnimationFrame(id) { frames.delete(id); },
    setTimeout(fn) { fn(); },
  };
  new Script(js).runInNewContext({
    document: doc, window: win, HTMLElement: El, HTMLSelectElement: NativeSelect,
    HTMLOptGroupElement: NativeGroup, HTMLOptionElement: NativeOption,
    MutationObserver: class { observe() {} }, CSS: { escape: (s) => s },
    Event: class { constructor(type, options) { this.type = type; this.bubbles = options?.bubbles || false; } },
  });
  const parent = new El();
  const select = new NativeSelect();
  select.id = 'stage';
  select.setAttribute('aria-label', 'Workflow stage');
  for (let i = 1; i <= count; i++) select.appendChild(new NativeOption('v' + i));
  select.value = selected;
  parent.appendChild(select);
  const api = win.cbCore.selectPicker.enhance(select, { variant, ...(search === undefined ? {} : { search }) });
  const flush = () => {
    for (const [id, fn] of [...frames]) if (frames.delete(id)) fn();
  };
  const buttons = () => api?.panel.querySelectorAll('.cb-core-select-picker__option:not(:disabled)') || [];
  const listening = (target, type) => target.listeners.get(type)?.size ?? 0;
  return { doc, win, frames, api, parent, select, flush, buttons, listening };
};

test('SP-H05: Header mouse/keyboard focus selected enabled or first available', () => {
  const mouse = pickerFixture();
  mouse.api.toggle.dispatchEvent({ type: 'click' });
  assert.equal(mouse.doc.activeElement, null, 'focus occurs on the next animation frame');
  mouse.flush();
  assert.equal(mouse.doc.activeElement.dataset.value, 'v2');
  const keyboard = pickerFixture({ selected: 'v2', disabled: ['v2'] });
  keyboard.api.toggle.dispatchEvent({ type: 'keydown', key: 'ArrowDown', preventDefault() {} });
  keyboard.flush();
  assert.equal(keyboard.doc.activeElement.dataset.value, 'v1');
  const empty = pickerFixture({ count: 2, disabled: ['v1', 'v2'] });
  empty.api.open(); empty.flush();
  assert.equal(empty.doc.activeElement, null);
});

test('SP-H06: closed or reopened popover never receives stale scheduled focus', () => {
  const f = pickerFixture();
  f.api.open();
  const stale = [...f.frames.values()][0];
  f.api.close({ focus: true });
  assert.equal(f.doc.activeElement, f.api.toggle);
  stale(); // Already-running browser callback must fail closed after close.
  assert.equal(f.doc.activeElement, f.api.toggle);
  f.api.open();
  stale(); // An old open cycle may not focus a newly reopened menu.
  assert.equal(f.doc.activeElement, f.api.toggle);
  f.flush();
  assert.equal(f.doc.activeElement.dataset.value, 'v2');
  f.api.panel.dispatchEvent({ type: 'keydown', key: 'Escape', preventDefault() {} });
  assert.equal(f.api.panel.hidden, true);
  assert.equal(f.doc.activeElement, f.api.toggle);
  assert.equal(f.listening(f.win, 'resize'), 0);
  assert.equal(f.listening(f.win, 'scroll'), 0);
  assert.equal(f.listening(f.doc, 'focusin'), 0);
});

test('SP-H07: Header has no search; Field search and variants preserve native contract', () => {
  assert.equal(pickerFixture({ count: 9, search: true }).api.panel.querySelectorAll('.cb-core-select-picker__search').length, 0);
  assert.equal(pickerFixture({ variant: 'field', count: 9 }).api.panel.querySelectorAll('.cb-core-select-picker__search').length, 1);
  assert.equal(pickerFixture({ variant: 'field', count: 9, search: 'false' }).api.panel.querySelectorAll('.cb-core-select-picker__search').length, 0);
  for (const variant of ['other', 'HEADER', '', 'header-custom']) {
    const f = pickerFixture({ variant });
    assert.equal(f.api, null);
    assert.equal(f.parent.children[0], f.select);
    assert.equal(f.select.hidden, false);
    assert.equal(f.select.dataset.cbCoreSelectPickerReady, undefined);
  }
  const unsupported = pickerFixture({ popover: false });
  assert.equal(unsupported.api, null);
  assert.equal(unsupported.parent.children[0], unsupported.select);
});

test('SP-H08: changes dispatch one bubbling native event and restore trigger focus', () => {
  const f = pickerFixture();
  let changed = 0;
  f.select.addEventListener('change', (event) => { assert.equal(event.bubbles, true); changed++; });
  f.api.open();
  f.buttons().find((button) => button.dataset.value === 'v3').dispatchEvent({ type: 'click' });
  assert.equal(f.select.value, 'v3');
  assert.equal(changed, 1);
  assert.equal(f.api.panel.hidden, true);
  assert.equal(f.doc.activeElement, f.api.toggle);
  f.api.open();
  f.buttons().find((button) => button.dataset.value === 'v3').dispatchEvent({ type: 'click' });
  assert.equal(changed, 1);
});

test('SP-H09: WP-native Header has scoped, tokenized, viewport-bounded states', () => {
  assert.match(native, /\.cb-core-picker\.cb-core-select-picker--header/);
  assert.match(native, /\.cb-core-select-picker--header \.cb-core-select-picker__panel\[popover\]/);
  assert.match(native, /\.cb-core-select-picker--header \.cb-core-select-picker__option:focus-visible/);
  assert.match(native, /\.cb-core-select-picker--header \.cb-core-select-picker__option\.is-selected::after/);
  assert.match(native, /max-width:\s*calc\(100vw - 16px\)/);
  assert.match(native, /var\(--cb-core-picker-accent\)/);
});
