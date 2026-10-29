import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';

const source = async path => readFile(new URL(path, import.meta.url), 'utf8');

test('DF-D2E Mail uses the node itself and preserves click/select and double-click/F2 edit', async () => {
 const mail = await source('../../assets/js/design/mail/platform-adapter.js');
 assert.match(mail, /e\.dataset\.cbDesignNodeDragSource=JSON\.stringify\(path\)/);
 assert.match(mail, /n\.type!=='mail\.column'/);
 assert.match(mail, /e\.addEventListener\('click',ev=>\{/);
 assert.match(mail, /e\.addEventListener\('dblclick',openEdit\)/);
 assert.match(mail, /ev\.key==='F2'/);
 assert.doesNotMatch(mail, /cb-mail-platform__drag-handle/);
});

test('DF-D2E Reports uses bounded parent proxy for click and drag without iframe authority', async () => {
 const reports = await source('../../assets/js/design/reports/platform-adapter.js');
 assert.match(reports, /previewPathForRegion\(region\.id, lastProject\)/);
 assert.match(reports, /cb-reports-platform__interaction-region/);
 assert.match(reports, /hit\.dataset\.cbDesignNodeDragSource = JSON\.stringify\(path\)/);
 assert.match(reports, /onActivate\(region\.id\)/);
 assert.match(reports, /!\['reports\.header', 'reports\.footer'\]\.includes\(node\.type\)/);
 assert.match(reports, /createFlowPreviewHost\(frame, \{onActivate, onRegions\}\)/);
 assert.doesNotMatch(reports, /cb-reports-platform__drag-handle/);
});

test('DF-D2E same registered Move and History routes serve direct canvas sources', async () => {
 const editor = await source('../../assets/js/design/editor.js');
 const controller = await source('../../assets/js/design/shell/drag-drop.js');
 const css = await source('../../assets/css/design/platform-fullscreen.css');
 assert.match(editor, /querySelectorAll\('\[data-cb-design-node-drag-source\]'\)/);
 assert.match(editor, /directCanvas: item\.directCanvas === true/);
 assert.match(editor, /editor\.execute\(intent\.commandId, intent\.input\)/);
 assert.match(editor, /const path = \[\.\.\.parentPath, index\]/);
 assert.equal((editor.match(/createDesignerDragDropController\(\{/g)||[]).length, 1);
 assert.match(controller, /event\.target\?\.isContentEditable === true/);
 assert.match(controller, /event\.stopPropagation\?\.\(\)/);
 assert.match(css, /data-cb-design-node-drag-source/);
 assert.doesNotMatch(css, /cb-mail-platform__drag-handle/);
});

test('DF-D2E actual Reports proxy click selects nested node without a drag button or iframe command', async () => {
 const reports = await source('../../assets/js/design/reports/platform-adapter.js');
 const start = reports.indexOf('\tconst syncInteractionSelection = () => {');
 const end = reports.indexOf('\n\treturn Object.freeze({', start);
 assert.ok(start > 0 && end > start);
 const logic = reports.slice(start, end);
 class FakeElement {
  constructor(name = '') {
   this.className = name; this.dataset = {}; this.attrs = {}; this.style = {};
   this.children = []; this.listeners = {}; this.isConnected = true;
  }
  append(child) { this.children.push(child); }
  replaceChildren(...children) { this.children = children; }
  setAttribute(name, value) { this.attrs[name] = value; }
  addEventListener(name, listener) { this.listeners[name] = listener; }
  dispatchEvent() {}
  closest() { return null; }
  querySelectorAll(selector) {
   if (selector === '[data-cb-reports-direct-region]')
    return this.children.filter(n => n.dataset.cbReportsDirectRegion !== undefined);
   if (selector === '[data-cb-design-drop-zone-id]')
    return this.children.filter(n => n.dataset.cbDesignDropZoneId !== undefined);
   return [];
  }
 }
 const project = {root: {type: 'root', children: [
  {type: 'reports.header', children: []},
  {type: 'reports.section', children: [{type: 'reports.heading', children: []}]},
  {type: 'reports.footer', children: []},
 ]}};
 const nodeAt = (root, path) => path.reduce((n, i) => n?.children?.[i] ?? null, root);
 const previewPathForRegion = id => /^r[0-9]+(?:\.[0-9]+)?$/.test(id)
  ? id.slice(1).split('.').map(Number) : null;
 const layer = new FakeElement('layer');
 const frame = {offsetTop: 20, offsetLeft: 10, clientWidth: 800};
 const container = new FakeElement('reports');
 container.classList = {contains: () => false};
 const selected = [];
 const doc = {defaultView: {CustomEvent: class {constructor(type) {this.type = type;}}}};
 const el = (_, name) => new FakeElement(name);
 const editor = {select: (refs, primary) => selected.push({refs, primary})};
 const callbacks = new Function(
  'dropLayer', 'frame', 'lastProject', 'lastSelection', 'lastRegions',
  'destroyed', 'activeSignal', 'container', 'el', 'nodeAt',
  'previewPathForRegion', 'doc', 'editor',
  logic + '\nreturn {onRegions,syncInteractionSelection};'
 )(layer, frame, project, {primary: {kind: 'node', path: [1, 0]}},
  [], false, null, container, el, nodeAt, previewPathForRegion, doc, editor);
 callbacks.onRegions([
  {id: 'r0', top: 1, height: 30, left: 1, width: 700},
  {id: 'r1', top: 40, height: 200, left: 1, width: 700},
  {id: 'r1.0', top: 55, height: 30, left: 20, width: 400},
  {id: 'r2', top: 245, height: 30, left: 1, width: 700},
 ]);
 const hit = layer.querySelectorAll('[data-cb-reports-direct-region]');
 assert.equal(hit.length, 4);
 assert.equal(hit[0].dataset.cbDesignNodeDragSource, undefined,
  'Header remains select-only');
 assert.equal(hit[3].dataset.cbDesignNodeDragSource, undefined,
  'Footer remains select-only');
 assert.equal(hit[2].dataset.cbDesignNodeDragSource, '[1,0]');
 assert.equal(hit[2].attrs['aria-pressed'], 'true');
 assert.equal(hit[2].style.left, '30px');
 assert.equal(hit[2].style.top, '75px');
 hit[2].listeners.click({stopPropagation() {}});
 assert.deepEqual(selected[0].primary, {kind: 'node', path: [1, 0]});
 assert.equal(layer.children.filter(n => n.className.includes('drag-handle')).length, 0);
});
