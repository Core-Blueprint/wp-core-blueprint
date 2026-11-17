import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const launch = readFileSync(new URL('../../assets/js/features/designer-launch.js', import.meta.url), 'utf8');
const start = launch.indexOf('\tconst composeHeader = ');
const end = launch.indexOf('\n\tconst syncFullscreenIcon = ', start);
assert.ok(start >= 0 && end > start, 'The real Base composeHeader function must be available');
const realComposeHeader = launch.slice(start, end);

// Small deterministic DOM fixture: relocation must retain the original button
// and its identity, not merely recreate a visually similar Pages control.
class Element {
  constructor(tag = 'div', className = '') {
    this.tagName = tag.toUpperCase();
    this.className = className;
    this.children = [];
    this.parentElement = null;
    this.dataset = {};
    this.attributes = new Map();
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.classList = {
      add: (...names) => { this.className = [...new Set([...this.className.split(/\s+/), ...names])].filter(Boolean).join(' '); },
      remove: (...names) => { this.className = this.className.split(/\s+/).filter(name => name && !names.includes(name)).join(' '); },
    };
  }
  append(...nodes) {
    for (const node of nodes) {
      if (node.parentElement) {
        const siblings = node.parentElement.children;
        siblings.splice(siblings.indexOf(node), 1);
      }
      node.parentElement = this;
      this.children.push(node);
    }
  }
  replaceChildren(...nodes) {
    for (const child of this.children) child.parentElement = null;
    this.children = [];
    this.append(...nodes);
  }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  removeAttribute(name) { this.attributes.delete(name); }
  matches(selector) {
    if (selector.startsWith('.')) return this.className.split(/\s+/).includes(selector.slice(1));
    if (selector.startsWith('[') && selector.endsWith(']')) return this.attributes.has(selector.slice(1, -1));
    return false;
  }
  querySelector(selector) {
    for (const child of this.children) {
      if (child.matches(selector)) return child;
      const nested = child.querySelector(selector);
      if (nested) return nested;
    }
    return null;
  }
  closest(selector) {
    for (let node = this; node; node = node.parentElement) {
      if (node.matches(selector)) return node;
    }
    return null;
  }
}

const tagged = (name, tag = 'button') => {
  const node = new Element(tag);
  node.setAttribute(name, '');
  return node;
};

const fixture = withPages => {
  const root = new Element();
  root.dataset.cbDesignTitle = 'Contracts Designer';
  const shell = new Element();
  const toolbar = new Element('div', 'cb-core-design-shell__toolbar cb-core-design-shell__toolbar--designer');
  shell.append(toolbar);

  const context = tagged('data-cb-design-shell-context', 'div');
  const history = new Element('div', 'cb-core-design-shell__toolbar-group');
  const undo = tagged('data-cb-design-shell-undo');
  const redo = tagged('data-cb-design-shell-redo');
  history.append(undo, redo);

  const viewportGroup = new Element('div', 'cb-core-design-shell__toolbar-group');
  viewportGroup.append(tagged('data-cb-design-shell-viewport'));

  const pageGroup = new Element('div', 'cb-core-design-shell__toolbar-group cb-core-design-shell__page-navigation');
  pageGroup.setAttribute('data-cb-design-shell-page-navigation-group', '');
  const pageButton = tagged('data-cb-design-shell-page-navigation');
  pageButton.disabled = true;
  pageGroup.append(pageButton);

  const operations = new Element('div', 'cb-core-design-shell__toolbar-group');
  const save = tagged('data-cb-design-shell-primary-action');
  const fullscreen = tagged('data-cb-design-shell-fullscreen');
  operations.append(save, fullscreen);
  toolbar.append(context, history, viewportGroup);
  if (withPages) toolbar.append(pageGroup);
  toolbar.append(operations);

  const sandbox = {
    document: {createElement: tag => new Element(tag)},
    config: {},
    bindSaveState: () => {},
    configureSidebar: () => {},
    composePanelToggles: () => null,
    appendBrandedModeTitle: () => {},
    enhanceHeaderContext: () => {},
  };
  sandbox.composeHeader = undefined;
  runInNewContext(realComposeHeader + '\ncomposeHeader(root, shell, shellApi);', {
    ...sandbox, root, shell,
    shellApi: {
      icons: {decorate: () => {}},
      configureViewports: () => {},
      configureHistory: () => {},
    },
  }, {timeout: 1000});

  return {shell, toolbar, history, save, fullscreen, viewportGroup, pageGroup, pageButton};
};

test('DP-NAV-02: classic Contracts header relocation retains the original Page Navigation trigger', () => {
  const {shell, toolbar, history, save, fullscreen, viewportGroup, pageGroup, pageButton} = fixture(true);
  const center = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--center');
  const end = toolbar.querySelector('.cb-core-design-shell__toolbar-zone--end');
  assert.ok(center, 'Base must construct its canonical center zone');
  assert.deepEqual(center.children, [viewportGroup, pageGroup], 'Pages follows existing viewport controls');
  assert.strictEqual(pageGroup.parentElement, center, 'Move existing group rather than copying it');
  assert.strictEqual(shell.querySelector('[data-cb-design-shell-page-navigation]'), pageButton, 'Preserve the trigger identity for Base Page Navigation');
  assert.strictEqual(pageButton.disabled, true, 'Do not activate navigation without a trusted PDF projection');
  assert.ok(end.querySelector('[data-cb-design-shell-primary-action]') === save, 'Save survives header composition');
  assert.ok(end.querySelector('[data-cb-design-shell-fullscreen]') === fullscreen, 'Close/fullscreen survives header composition');
  assert.ok(end.querySelector('[data-cb-design-shell-undo]') === history.children[0], 'History survives header composition');
});

test('DP-NAV-02: classic Mail header remains valid without an opted-in Pages control', () => {
  const {shell, toolbar, save, fullscreen} = fixture(false);
  assert.strictEqual(shell.querySelector('[data-cb-design-shell-page-navigation]'), null);
  assert.ok(toolbar.querySelector('[data-cb-design-shell-primary-action]') === save);
  assert.ok(toolbar.querySelector('[data-cb-design-shell-fullscreen]') === fullscreen);
});
