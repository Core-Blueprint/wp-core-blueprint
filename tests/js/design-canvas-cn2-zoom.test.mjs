import assert from 'node:assert/strict';
import {test} from 'node:test';
import {readFileSync} from 'node:fs';
// Load standalone ESM directly: Base intentionally has no package.json type:module.

const source = readFileSync(new URL('../../assets/js/design/shell/canvas-navigation.js',import.meta.url),'utf8');
const {createDesignerCanvasNavigation} = await import('data:text/javascript;charset=utf-8,'+encodeURIComponent(source));
const assets = readFileSync(new URL('../../src/Design/Editor/Assets.php',import.meta.url),'utf8');

const fake = ({rectWidth=1000, rectHeight=700, scrollLeft=0, scrollTop=0} = {}) => {
  const listeners = new Map();
  let context = 'workflow:1', ready = true;
  const doc = {defaultView:null, addEventListener(){}, removeEventListener(){}};
  const root = {
    isConnected:true, contains:child=>child===viewport,
    getBoundingClientRect:()=>({left:0,top:0,width:1200,height:900}),
    addEventListener(){}, removeEventListener(){},
  };
  const scene = {style:{transform:'',transformOrigin:''},addEventListener(){},getBoundingClientRect:()=>({left:0,top:0,width:1000,height:700})};
  const viewport = {
    ownerDocument:doc, style:{}, scrollLeft,scrollTop,
    clientWidth:1000,clientHeight:700,
    contains:child=>child===scene,
    getBoundingClientRect:()=>({left:100,top:50,width:rectWidth,height:rectHeight}),
    addEventListener(name,fn){listeners.set(name,fn)},
    removeEventListener(name){listeners.delete(name)},
    dispatchEvent(){},classList:{add(){},remove(){}},
  };
  let events=[];
  const api=createDesignerCanvasNavigation({
    root,viewport,scene,getContentBounds:()=>({x:-100,y:-100,width:400,height:300}),
    getContextKey:()=> context,isReady:()=>ready,
    onViewChange:v=>events.push(v),
  });
  return {api,viewport,scene,events,
    setContext:value=>{context=value;},setReady:value=>{ready=value;}};
};
test('CN-2 advertises version 2 while retaining the v1 graph neutral controller',()=>{
  assert.match(assets,/CANVAS_NAVIGATION_API = 2/);
  assert.match(source,/const MIN_ZOOM = 0\.1/);
  assert.match(source,/const MAX_ZOOM = 4/);
});
test('CN-2 preserves cursor canvas point, supports center default and publishes events',()=>{
  const {api,events}=fake();
  const anchor={clientX:415,clientY:312};
  const before=api.clientToCanvas(anchor);
  assert.equal(api.zoomAt({scale:2,...anchor}),true);
  const after=api.clientToCanvas(anchor);
  assert.ok(Math.abs(before.x-after.x)<1e-8);
  assert.ok(Math.abs(before.y-after.y)<1e-8);
  assert.equal(api.getView().scale,2);
  assert.equal(events.at(-1).scale,2);
  assert.equal(api.zoomBy({factor:0.5}),true);
  assert.equal(api.getView().scale,1);
  api.destroy();
});
test('CN-2 zoom clamps, refuses invalid values and never changes document bounds',()=>{
  const {api}=fake();
  assert.equal(api.zoomAt({scale:100}),true);
  assert.equal(api.getView().scale,4);
  assert.equal(api.zoomAt({scale:100}),false);
  assert.equal(api.zoomBy({factor:0.001}),true);
  assert.equal(api.getView().scale,0.1);
  assert.throws(()=>api.zoomAt({scale:NaN}),/ZOOM_INVALID/);
  assert.throws(()=>api.zoomAt({scale:-1}),/ZOOM_INVALID/);
  assert.throws(()=>api.zoomBy({factor:0}),/ZOOM_INVALID/);
  api.destroy();
});

test('CN-2 relative zoom reads the new context before multiplying its scale',()=>{
  const {api,setContext,events}=fake();
  assert.equal(api.zoomAt({scale:3}),true);
  setContext('workflow:2');
  // A fresh context starts at fit scale=1, so factor 2 must yield 2, not 4.
  assert.equal(api.zoomBy({factor:2}),true);
  assert.equal(api.getView().scale,2);
  assert.equal(events.at(-1).scale,2);
  api.destroy();
});
test('CN-2 clamps overflow and underflow from finite positive factors',()=>{
  const {api}=fake();
  assert.equal(api.zoomAt({scale:4}),true);
  assert.equal(api.zoomBy({factor:Number.MAX_VALUE}),false);
  assert.equal(api.getView().scale,4);
  assert.equal(api.zoomAt({scale:0.1}),true);
  assert.equal(api.zoomBy({factor:Number.MIN_VALUE}),false);
  assert.equal(api.getView().scale,0.1);
  api.destroy();
});
test('CN-2 anchors cursor under scaled viewport and scroll offsets',()=>{
  const {api}=fake({rectWidth:1500,rectHeight:1050,scrollLeft:38,scrollTop:19});
  const anchor={clientX:482,clientY:217};
  const before=api.clientToCanvas(anchor);
  assert.equal(api.zoomAt({scale:2,...anchor}),true);
  const after=api.clientToCanvas(anchor);
  assert.ok(Math.abs(after.x-before.x)<1e-8);
  assert.ok(Math.abs(after.y-before.y)<1e-8);
  assert.equal(api.zoomBy({factor:0.75,...anchor}),true);
  const final=api.clientToCanvas(anchor);
  assert.ok(Math.abs(final.x-before.x)<1e-8);
  assert.ok(Math.abs(final.y-before.y)<1e-8);
  api.destroy();
});
test('CN-2 preserves lifecycle, unavailable response and invalid input contract',()=>{
  const {api,setReady}=fake();
  assert.throws(()=>api.zoomAt({scale:1,clientX:Infinity}),/ZOOM_INVALID/);
  assert.throws(()=>api.zoomBy({factor:NaN}),/ZOOM_INVALID/);
  setReady(false);
  assert.equal(api.zoomAt({scale:2}),false);
  assert.equal(api.zoomBy({factor:2}),false);
  setReady(true);
  api.destroy();
  assert.throws(()=>api.zoomBy({factor:2}),/NAVIGATION_DESTROYED/);
});
