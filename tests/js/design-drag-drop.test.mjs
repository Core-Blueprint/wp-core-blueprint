import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const source = await readFile(
	new URL('../../assets/js/design/shell/drag-drop.js', import.meta.url), 'utf8'
);
const { createDesignerDragDropController } = await import(
	'data:text/javascript;charset=utf-8,' + encodeURIComponent(source)
);

class FakeElement {
	constructor(doc, name = 'div') {
		this.ownerDocument = doc;
		this.name = name;
		this.isConnected = true;
		this.parent = null;
		this.children = [];
		this.attributes = new Map();
		this.listeners = new Map();
		this.style = {};
		this.textContent = '';
	}
	append(child) {
		child.parent = this;
		this.children.push(child);
	}
	remove() {
		if (this.parent) this.parent.children = this.parent.children.filter(item => item !== this);
		this.parent = null;
		this.isConnected = false;
	}
	contains(target) {
		return this === target || this.children.some(child => child.contains(target));
	}
	getAttribute(name) { return this.attributes.get(name) ?? null; }
	setAttribute(name, value) { this.attributes.set(name, String(value)); }
	removeAttribute(name) { this.attributes.delete(name); }
	addEventListener(name, callback) {
		if (!this.listeners.has(name)) this.listeners.set(name, new Set());
		this.listeners.get(name).add(callback);
	}
	removeEventListener(name, callback) { this.listeners.get(name)?.delete(callback); }
	getBoundingClientRect() { return {top: 100, height: 120}; }
	focus() { this.ownerDocument.activeElement = this; this.fire('focus'); }
	fire(name, extra = {}) {
		let prevented = false;
		const event = {
			key: undefined, altKey: false, repeat: false, clientY: 160,
			relatedTarget: null,
			preventDefault() { prevented = true; },
			stopPropagation() {},
			...extra,
		};
		for (const listener of this.listeners.get(name) ?? []) listener(event);
		return prevented;
	}
}
class FakeDocument extends FakeElement {
	constructor() {
		super(null, 'document');
		this.ownerDocument = this;
	}
	createElement(name) { return new FakeElement(this, name); }
}

const fixture = (policy = () => true, commitHandler = null) => {
	const doc = new FakeDocument();
	const root = doc.createElement('main');
	doc.append(root);
	const source = doc.createElement('button');
	const zone = doc.createElement('section');
	root.append(source);
	root.append(zone);
	let epoch = 'site:1:report:1';
	let ready = true;
	const commits = [];
	const statuses = [];
	const controller = createDesignerDragDropController({
		root,
		isReady: () => ready,
		getContextKey: () => epoch,
		resolveIntent: ({source: dragged, zoneId, edge}) =>
			({commandId: 'fixture.insert', input: {type: dragged.type, zoneId, edge}}),
		canDrop: policy,
		commit: ({source: dragged, intent}) => {
			commits.push({dragged, intent});
			return commitHandler?.({source: dragged, intent});
		},
		onStatus: status => statuses.push(status.status),
	});
	controller.registerSource(source, {id: 'fixture/heading', type: 'fixture.heading'});
	controller.registerZone(zone, {id: 'report/section-1'});
	return {
		doc, root, source, zone, commits, statuses, controller,
		setEpoch: value => { epoch = value; },
		setReady: value => { ready = value; },
	};
};
const transfer = () => ({
	effectAllowed: '', dropEffect: '',
	setData(type, value) { this[type] = value; },
});

test('a palette drag yields one authorized domain intent, never DOM/project mutation', () => {
	const f = fixture();
	const dataTransfer = transfer();
	assert.equal(f.source.fire('dragstart', {dataTransfer}), false);
	assert.equal(dataTransfer['text/plain'], 'cb-designer-element');
	assert.equal(f.controller.active, true);
	assert.equal(f.zone.fire('dragover', {dataTransfer, clientY: 105}), true);
	assert.equal(f.zone.getAttribute('data-cb-design-drop-edge'), 'before');
	assert.equal(f.zone.fire('drop', {dataTransfer, clientY: 105}), true);
	assert.equal(f.commits.length, 1);
	assert.equal(f.commits[0].intent.commandId, 'fixture.insert');
	assert.deepEqual(f.commits[0].intent.input, {
		type: 'fixture.heading', zoneId: 'report/section-1', edge: 'before',
	});
	assert.equal(f.zone.getAttribute('data-cb-design-drop-active'), null);
	assert.equal(f.zone.fire('drop', {dataTransfer}), false, 'replayed drop is ignored');
	assert.equal(f.commits.length, 1);
	f.controller.destroy();
});

test('external DataTransfer payload is never a source of authority', () => {
	const f = fixture();
	const dataTransfer = transfer();
	dataTransfer.setData('text/plain', 'cb-designer-element');
	assert.equal(f.zone.fire('drop', {dataTransfer}), false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
});

test('a domain policy rejection never prevents an invalid drop or dispatches a command', () => {
	const f = fixture(() => false);
	f.source.fire('dragstart', {dataTransfer: transfer()});
	assert.equal(f.zone.fire('dragover', {dataTransfer: transfer()}), false);
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
});

test('DF-D1 removes stale valid drop feedback when an edge becomes forbidden', () => {
	const f = fixture(({intent}) => intent.input.edge === 'before');
	f.source.fire('dragstart', {dataTransfer: transfer()});
	assert.equal(f.zone.fire('dragover', {dataTransfer: transfer(), clientY: 105}), true);
	assert.equal(f.zone.getAttribute('data-cb-design-drop-edge'), 'before');
	assert.equal(f.zone.fire('dragover', {dataTransfer: transfer(), clientY: 215}), false);
	assert.equal(f.zone.getAttribute('data-cb-design-drop-active'), null);
	assert.equal(f.zone.getAttribute('data-cb-design-drop-edge'), null);
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer(), clientY: 215}), false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
});

test('context changes and inactive sessions cancel drag before an outdated command', () => {
	const f = fixture();
	f.source.fire('dragstart', {dataTransfer: transfer()});
	f.setEpoch('site:1:report:2');
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(f.commits.length, 0);
	assert.equal(f.controller.active, false);
	f.source.fire('dragstart', {dataTransfer: transfer()});
	f.setReady(false);
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
});

test('keyboard operation preserves click and uses a registered dropzone', () => {
	const f = fixture();
	assert.equal(f.source.fire('keydown', {key: 'Enter'}), false, 'Enter click unaffected');
	assert.equal(f.controller.active, false);
	assert.equal(f.source.fire('keydown', {key: 'ArrowDown', altKey: true}), true);
	assert.equal(f.controller.active, true);
	assert.equal(f.doc.activeElement, f.zone);
	f.zone.fire('keydown', {key: 'ArrowUp'});
	assert.equal(f.zone.getAttribute('data-cb-design-drop-edge'), 'before');
	assert.equal(f.zone.fire('keydown', {key: 'Enter'}), true);
	assert.equal(f.commits[0].intent.input.edge, 'before');
	f.controller.destroy();
});

test('cancel, unregister and destroy clean up listeners and DOM attributes', () => {
	const f = fixture();
	f.source.fire('dragstart', {dataTransfer: transfer()});
	f.doc.fire('keydown', {key: 'Escape'});
	assert.equal(f.controller.active, false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
	assert.equal(f.source.getAttribute('draggable'), null);
	assert.equal(f.source.getAttribute('aria-keyshortcuts'), null);
	assert.equal(f.source.getAttribute('data-cb-design-dnd-source'), null);
	assert.equal(f.source.getAttribute('data-cb-design-dnd-dragging'), null);
	assert.equal(f.zone.getAttribute('tabindex'), null);
	assert.equal(f.root.children.some(child => child.name === 'div'), false);
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
});

test('detached sources and zones cannot dispatch stale canvas commands', () => {
	const detachedZone = fixture();
	detachedZone.source.fire('dragstart', {dataTransfer: transfer()});
	detachedZone.zone.remove();
	assert.equal(detachedZone.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(detachedZone.commits.length, 0);
	detachedZone.controller.destroy();

	const detachedSource = fixture();
	detachedSource.source.fire('dragstart', {dataTransfer: transfer()});
	detachedSource.source.remove();
	assert.equal(detachedSource.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(detachedSource.controller.active, false);
	assert.equal(detachedSource.commits.length, 0);
	detachedSource.controller.destroy();
});

test('async domain policy is never accepted as authorization', () => {
	const f = fixture(() => Promise.resolve(true));
	f.source.fire('dragstart', {dataTransfer: transfer()});
	assert.equal(f.zone.fire('drop', {dataTransfer: transfer()}), false);
	assert.equal(f.commits.length, 0);
	f.controller.destroy();
});

test('DF-D2E direct canvas distinguishes click, interactive editors and a real drag gesture', () => {
	const doc = new FakeDocument();
	const root = doc.createElement('main');
	doc.append(root);
	const direct = doc.createElement('div');
	const zone = doc.createElement('div');
	root.append(direct);
	root.append(zone);
	const commits = [];
	const controller = createDesignerDragDropController({
		root,
		isReady: () => true,
		getContextKey: () => 'site:direct-canvas:1',
		resolveIntent: ({source, zoneId}) => ({
			commandId: 'fixture.move',
			input: {fromPath: source.path, zoneId},
		}),
		canDrop: () => true,
		commit: effect => { commits.push(effect); },
	});
	controller.registerSource(direct, {
		id: 'node/0.1', type: 'fixture.heading', kind: 'node',
		path: [0, 1], directCanvas: true,
	});
	controller.registerZone(zone, {id: 'node/1'});
	assert.equal(direct.fire('click'), false);
	assert.equal(controller.active, false, 'ordinary click does not start a move');

	const editorTarget = {closest: selector => selector.includes('textarea')};
	assert.equal(direct.fire('dragstart', {target: editorTarget}), true,
		'editing controls refuse starting a node drag');
	assert.equal(controller.active, false);

	let bubbleCount = 0;
	const payload = transfer();
	direct.fire('dragstart', {
		target: {closest: () => null},
		stopPropagation() { bubbleCount++; },
		dataTransfer: payload,
	});
	assert.equal(bubbleCount, 1, 'nested node must not re-trigger parent sources');
	assert.equal(controller.active, true);
	assert.equal(payload.effectAllowed, 'move');
	assert.equal(zone.fire('dragover', {clientY: 105, dataTransfer: payload}), true);
	assert.equal(zone.fire('drop', {clientY: 105, dataTransfer: payload}), true);
	assert.equal(commits.length, 1);
	assert.deepEqual(commits[0].source.path, [0, 1]);
	controller.destroy();
});

test('DF-D2E canvas-only affordance refuses a non-node source', () => {
	const doc = new FakeDocument();
	const root = doc.createElement('main');
	doc.append(root);
	const element = doc.createElement('div');
	root.append(element);
	const controller = createDesignerDragDropController({
		root, isReady: () => true, getContextKey: () => 'site:direct:2',
		resolveIntent: () => null, canDrop: () => false, commit: () => {},
	});
	assert.throws(() => controller.registerSource(element, {
		id: 'fixture/palette', type: 'fixture.heading', directCanvas: true,
	}), /DESIGNER_DND_SOURCE_INVALID/);
	controller.destroy();
});

test('DF-D4B native drag uses one centered 12px Base-accent dot, not a large node image', () => {
  const f=fixture();
  let snapshot=null,frame=null;
  f.doc.defaultView={
    requestAnimationFrame:fn=>{frame=fn;return 1;},
    cancelAnimationFrame:()=>{frame=null;},
  };
  const nativeTransfer={
    ...transfer(),
    setDragImage(el,x,y){snapshot={el,x,y};},
  };
  f.source.fire('dragstart',{dataTransfer:nativeTransfer,clientX:220,clientY:150});
  assert.equal(f.controller.active,true);
  assert.ok(snapshot?.el,'native browser drag image is overridden');
  assert.equal(snapshot.x,6);
  assert.equal(snapshot.y,6);
  assert.equal(snapshot.el.className,'cb-core-design-drag-dot');
  assert.equal(snapshot.el.style.width,'12px');
  assert.equal(snapshot.el.style.height,'12px');
  assert.equal(snapshot.el.style.background,'var(--cb-accent, #00a8e8)');
  assert.equal(snapshot.el.style.left,'214px');
  assert.equal(snapshot.el.style.top,'144px');
  assert.equal(f.root.contains(f.source),true,'original authored source stays in DOM');
  assert.equal(f.root.contains(snapshot.el),true);
  frame();
  assert.equal(f.root.contains(snapshot.el),false,'snapshot marker is removed after native capture');
  assert.equal(f.controller.active,true,'removing presentation does not invalidate gesture');
  assert.equal(f.zone.fire('drop',{dataTransfer:nativeTransfer}),true);
  assert.equal(f.commits.length,1);
  f.controller.destroy();
});

test('DF-D4B cleans the native drag dot on Escape, stale context, and unsupported image API',()=>{
  const f=fixture();
  let canceled=0;
  f.doc.defaultView={
    requestAnimationFrame:()=>77,cancelAnimationFrame:()=>{canceled++;},
  };
  const native={...transfer(),setDragImage(el){this.image=el;}};
  f.source.fire('dragstart',{dataTransfer:native,clientX:20,clientY:20});
  assert.equal(f.root.contains(native.image),true);
  f.doc.fire('keydown',{key:'Escape'});
  assert.equal(f.root.contains(native.image),false);
  assert.equal(f.controller.active,false);
  assert.equal(canceled,1);
  f.source.fire('dragstart',{dataTransfer:native,clientX:20,clientY:20});
  const next=native.image;
  f.setEpoch('site:2:report:1');
  f.zone.fire('drop',{dataTransfer:native});
  assert.equal(f.controller.active,false);
  assert.equal(f.root.contains(next),false);
  const transferWithoutImage=transfer();
  f.source.fire('dragstart',{dataTransfer:transferWithoutImage});
  assert.equal(f.controller.active,true,'unsupported setDragImage must not block DND');
  f.controller.destroy();
});

test('DF-D4 contract: synchronous commit results and exact-once status', () => {
  for (const [label, result, expected] of [
    ['undefined', undefined, 'committed'], ['true', true, 'committed'],
    ['false', false, 'refused'], ['legacy null', null, 'committed'],
  ]) {
    const f = fixture(() => true, () => result);
    f.source.fire('dragstart', {dataTransfer: transfer()});
    f.zone.fire('drop', {dataTransfer: transfer()});
    assert.equal(f.statuses.at(-1), expected, label);
    assert.equal(f.commits.length, 1, label);
    assert.equal(f.controller.active, false);
    f.zone.fire('drop', {dataTransfer: transfer()});
    assert.equal(f.commits.length, 1, label + ' replay');
    f.controller.destroy();
  }
  const f = fixture(() => true, () => { throw new Error('refused'); });
  f.source.fire('dragstart', {dataTransfer: transfer()});
  f.zone.fire('drop', {dataTransfer: transfer()});
  assert.equal(f.statuses.at(-1), 'refused');
  assert.equal(f.commits.length, 1);
  f.zone.fire('drop', {dataTransfer: transfer()});
  assert.equal(f.commits.length, 1);
  f.controller.destroy();
});
test('DF-D4 contract: async Promise/thenable commit refuses and cannot replay', () => {
  for (const result of [Promise.resolve(true), {then() {}}]) {
    const f = fixture(() => true, () => result);
    f.source.fire('dragstart', {dataTransfer: transfer()});
    f.zone.fire('drop', {dataTransfer: transfer()});
    assert.equal(f.statuses.at(-1), 'refused');
    assert.equal(f.commits.length, 1);
    f.zone.fire('drop', {dataTransfer: transfer()});
    assert.equal(f.commits.length, 1);
    f.controller.destroy();
  }
});

test('C1 sibling-only pointer placement uses 50/50, refuses inside and commits once',()=>{
	const doc=new FakeDocument(), root=doc.createElement('main');doc.append(root);
	const source=doc.createElement('button'),zone=doc.createElement('div');
	root.append(source);root.append(zone);
	const committed=[], edges=[];
	const dnd=createDesignerDragDropController({
		root,isReady:()=>true,getContextKey:()=> 'c1:scope',
		resolveIntent:({edge})=>{edges.push(edge);return {edge};},
		canDrop:()=>true,commit:({intent})=>{committed.push(intent.edge);},
	});
	dnd.registerSource(source,{id:'node/0',type:'heading',kind:'node',path:[0]});
	dnd.registerZone(zone,{id:'node/1',placementPolicy:'sibling-only'});
	source.fire('dragstart',{dataTransfer:transfer()});
	assert.equal(zone.fire('dragover',{clientY:145,dataTransfer:transfer()}),true);
	assert.equal(zone.getAttribute('data-cb-design-drop-edge'),'before');
	assert.equal(zone.fire('drop',{clientY:145,dataTransfer:transfer()}),true);
	assert.equal(zone.fire('drop',{clientY:145,dataTransfer:transfer()}),false);
	source.fire('dragstart',{dataTransfer:transfer()});
	assert.equal(zone.fire('dragover',{clientY:175,dataTransfer:transfer()}),true);
	assert.equal(zone.getAttribute('data-cb-design-drop-edge'),'after');
	assert.equal(zone.fire('drop',{clientY:175,dataTransfer:transfer()}),true);
	assert.deepEqual(committed,['before','after']);
	assert.equal(edges.includes('inside'),false);
	dnd.destroy();
});
test('C1 one controller handles pointer moves from Layers to Canvas and Canvas to Layers',()=>{
	const doc=new FakeDocument(),root=doc.createElement('main');doc.append(root);
	const layerSource=doc.createElement('button'),canvasSource=doc.createElement('button');
	const layerZone=doc.createElement('div'),canvasZone=doc.createElement('div');
	for(const item of [layerSource,canvasSource,layerZone,canvasZone])root.append(item);
	const commits=[];
	const dnd=createDesignerDragDropController({
		root,isReady:()=>true,getContextKey:()=> 'c1:scope',
		resolveIntent:({source,zoneId,edge})=>({sourceId:source.id,zoneId,edge}),
		canDrop:()=>true,commit:({intent})=>{commits.push(intent);},
	});
	dnd.registerSource(layerSource,{id:'node/0',type:'heading',kind:'node',path:[0]});
	dnd.registerSource(canvasSource,{id:'node/1',type:'heading',kind:'node',path:[1],directCanvas:true});
	dnd.registerZone(layerZone,{id:'node/0',placementPolicy:'sibling-only'});
	dnd.registerZone(canvasZone,{id:'node/1'});
	layerSource.fire('dragstart',{dataTransfer:transfer()});
	assert.equal(canvasZone.fire('drop',{clientY:110,dataTransfer:transfer()}),true);
	canvasSource.fire('dragstart',{dataTransfer:transfer()});
	assert.equal(layerZone.fire('drop',{clientY:185,dataTransfer:transfer()}),true);
	assert.deepEqual(commits,[
		{sourceId:'node/0',zoneId:'node/1',edge:'before'},
		{sourceId:'node/1',zoneId:'node/0',edge:'after'},
	]);
	assert.equal(dnd.active,false);
	dnd.destroy();
});
test('C1 sibling-only keyboard chooses a legal edge immediately',()=>{
	const doc=new FakeDocument(),root=doc.createElement('main');doc.append(root);
	const source=doc.createElement('button'),zone=doc.createElement('div');
	root.append(source);root.append(zone);
	const edges=[];
	const dnd=createDesignerDragDropController({
		root,isReady:()=>true,getContextKey:()=> 'c1:scope',
		resolveIntent:({edge})=>({edge}),
		canDrop:()=>true,commit:({intent})=>{edges.push(intent.edge);},
	});
	dnd.registerSource(source,{id:'node/0',type:'heading',kind:'node',path:[0]});
	dnd.registerZone(zone,{id:'node/1',placementPolicy:'sibling-only'});
	assert.equal(source.fire('keydown',{key:'ArrowDown',altKey:true}),true);
	assert.equal(doc.activeElement,zone);
	assert.equal(zone.getAttribute('data-cb-design-drop-edge'),'before');
	assert.equal(zone.fire('keydown',{key:'ArrowDown'}),true);
	assert.equal(zone.getAttribute('data-cb-design-drop-edge'),'after');
	assert.equal(zone.fire('keydown',{key:'Enter'}),true);
	assert.deepEqual(edges,['after']);
	dnd.destroy();
});
test('C1 invalid zone feedback is transient; invalid placement policy refuses registration',()=>{
	const f=fixture(()=>false);
	const other=f.doc.createElement('div');f.root.append(other);
	assert.throws(()=>f.controller.registerZone(other,{id:'node/3',placementPolicy:'unknown'}),/ZONE_INVALID/);
	f.source.fire('dragstart',{dataTransfer:transfer()});
	assert.equal(f.zone.fire('dragover',{clientY:160,dataTransfer:transfer()}),false);
	assert.equal(f.zone.getAttribute('data-cb-design-drop-invalid'),'true');
	f.doc.fire('keydown',{key:'Escape'});
	assert.equal(f.zone.getAttribute('data-cb-design-drop-invalid'),null);
	assert.equal(f.commits.length,0);
	f.controller.destroy();
});

test('C1 public same-source capability, ESM exports and native Base binding are present',async()=>{
	const assets=await readFile(new URL('../../src/Design/Editor/Assets.php',import.meta.url),'utf8');
	const editor=await readFile(new URL('../../assets/js/design/editor.js',import.meta.url),'utf8');
	const layers=await readFile(new URL('../../assets/js/design/shell/layers.js',import.meta.url),'utf8');
	assert.match(assets,/public const DESIGNER_DND_UNIFIED_API = 1;/);
	assert.match(editor,/createDesignerDragDropController,\s*createDesignerInteractionPresentation/);
	assert.match(editor,/createDesignerLayerTree,/);
	assert.match(editor,/tree\.bindDragDrop\(\{/);
	assert.match(layers,/bindDragDrop,\s*syncDragDrop,/);
	assert.doesNotMatch(editor,/for \(const row of tree\.element\.querySelectorAll\('\[data-cb-design-layer-key\]/);
});
