import assert from 'node:assert/strict';
import {test} from 'node:test';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../../assets/js/design/shell/interaction-presentation.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../../assets/css/design/platform-fullscreen.css', import.meta.url), 'utf8');
const sharedCss = readFileSync(new URL('../../assets/css/design/designer-composition.css', import.meta.url), 'utf8');
const editor = readFileSync(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
const reports = readFileSync(new URL('../../assets/js/design/reports/platform-adapter.js', import.meta.url), 'utf8');
const assets = readFileSync(new URL('../../src/Design/Editor/Assets.php', import.meta.url), 'utf8');
const {createDesignerInteractionPresentation} = await import(
  'data:text/javascript;charset=utf-8,' + encodeURIComponent(source)
);

function fixture(profile='mail') {
  const callbacks = new Map();
  const win = {
    requestAnimationFrame(callback) { callbacks.set('raf', callback); return 1; },
    cancelAnimationFrame() { callbacks.delete('raf'); },
    addEventListener() {}, removeEventListener() {},
  };
  const rect = (left,top,width,height) => ({left,top,width,height,right:left+width,bottom:top+height});
  const doc = {
    defaultView:win, activeElement:null,
    createElement(tag) { return element(tag); },
  };
  function element(tag,box=rect(0,0,1000,700)) {
    const attributes = new Map();
    const listeners = new Map();
    const node = {
      tagName:tag.toUpperCase(), ownerDocument:doc, parent:null,
      children:[], dataset:{}, style:{}, isConnected:true,
      offsetWidth:box.width,offsetHeight:box.height,
      getBoundingClientRect:()=>box,
      setAttribute(k,v) {attributes.set(k,String(v));},
      getAttribute(k) {return attributes.get(k) ?? null;},
      removeAttribute(k) {attributes.delete(k);},
      append(child) {child.parent=this;this.children.push(child);},
      replaceChildren(...newChildren) {this.children=newChildren;for(const child of newChildren)child.parent=this;},
      remove() {if(this.parent)this.parent.children=this.parent.children.filter(n=>n!==this);},
      contains(child) {return this===child||this.children.some(n=>n.contains(child));},
      querySelector(selector) {return this.querySelectorAll(selector)[0] || null;},
      querySelectorAll(selector) {
        const walk=n=>n.children.flatMap(c=>[c,...walk(c)]);
        return walk(this).filter(n=>selector==='[data-cb-design-node-path]'
          ? typeof n.dataset.cbDesignNodePath==='string'
          : selector==='[data-cb-design-drop-active="true"]'
            ? n.dataset.cbDesignDropActive==='true'
            : selector==='[data-cb-design-interaction-layer]'
              ? n.dataset.cbDesignInteractionLayer==='1' : false);
      },
      addEventListener(type,callback) {listeners.set(type,callback);},
      removeEventListener(type) {listeners.delete(type);},
      emit(type,properties={}) {listeners.get(type)?.(properties);},
    };
    return node;
  }
  const root=element('div');
  const surface=element('main');root.append(surface);
  const parent=element('div',rect(20,40,400,240));parent.dataset.cbDesignNodePath='[0]';surface.append(parent);
  const child=element('div',rect(50,70,150,60));
  child.dataset.cbDesignNodePath='[0,0]';
  if(profile==='mail') parent.append(child); else surface.append(child);
  let selected={kind:'node',path:[0,0]}, dragging=false;
  const presentation=createDesignerInteractionPresentation({
    root,surface,getSelection:()=>selected,isDragging:()=>dragging,
  });
  return {
    surface,parent,child,root,win,doc,
    presentation, markers:()=>surface.children.find(n=>n.dataset.cbDesignInteractionLayer==='1').children,
    setSelection:value=>{selected=value;},setDragging:value=>{dragging=value;},
    flush:()=>{const cb=callbacks.get('raf');callbacks.delete('raf');cb?.();},
    element,
    rect,
  };
}
test('DF-D3 uses one selected outline and canonical geometry for native and proxy nodes',()=>{
  for(const profile of ['mail','reports']) {
    const f=fixture(profile);
    assert.equal(f.markers().length,1);
    const indicator=f.markers()[0];
    assert.equal(indicator.dataset.state,'selected');
    assert.equal(indicator.style.left,'50px');
    assert.equal(indicator.style.top,'70px');
    assert.equal(indicator.style.width,'150px');
    assert.equal(indicator.style.height,'60px');
    f.presentation.destroy();
    assert.equal(f.surface.querySelector('[data-cb-design-interaction-layer]'),null);
  }
});
test('DF-D3 prioritizes selected over hover and deepest nested geometry',()=>{
  const f=fixture('reports');
  f.surface.emit('pointermove',{clientX:75,clientY:80});
  f.flush();
  assert.equal(f.markers().length,1); // same semantic node: one outline
  f.setSelection({kind:'node',path:[0]});
  f.presentation.refresh();
  assert.equal(f.markers().length,2);
  f.setSelection({kind:'node',path:[0,0]});
  f.presentation.refresh();
  assert.equal(f.markers().length,1);
  assert.equal(f.markers().filter(m=>m.dataset.state==='selected').length,1);
  f.presentation.destroy();
});
test('DF-D3 inline editing overrides selected without a duplicate outline',()=>{
  const f=fixture('mail');
  const field=f.element('input',f.rect(60,75,120,30));
  field.closest=()=>field;
  f.child.append(field);
  f.doc.activeElement=field;
  f.presentation.refresh();
  assert.equal(f.markers().length,1);
  assert.equal(f.markers()[0].dataset.state,'editing');
  f.presentation.destroy();
});
test('DF-D3 valid drop supersedes selected rectangle and invalid drop is not painted',()=>{
  const f=fixture('reports');
  const zone=f.element('div',f.rect(50,70,150,60));
  zone.dataset.cbDesignDropZoneId='node/0.0';
  f.surface.append(zone);
  f.setDragging(true);
  f.presentation.refresh();
  assert.equal(f.markers().length,1);
  zone.dataset.cbDesignDropActive='true';
  zone.dataset.cbDesignDropEdge='inside';
  f.presentation.refresh();
  assert.equal(f.markers().length,1);
  assert.equal(f.markers()[0].dataset.state,'drop');
  f.presentation.destroy();
});
test('DF-D3 disposed session refuses stale visual state without mutation',()=>{
  const f=fixture('reports');
  f.setSelection({kind:'node',path:[0,0]});
  f.root.isConnected=false;
  f.presentation.refresh();
  f.root.isConnected=true;
  f.presentation.refresh();
  assert.equal(f.markers().length,1);
  f.presentation.destroy();
});
test('DF-D3 Maps iframe regions across independent viewport and layer transforms',()=>{
  const start=reports.indexOf('\t\tconst mapped = region => ({');
  const end=reports.indexOf('\n\t\t});',start);
  assert.ok(start>0 && end>start);
  const snippet=reports.slice(start,end+6);
  const frameRect={left:220,top:190,width:1200,height:900};
  const layerRect={left:100,top:50,width:1800,height:1000};
  const frame={clientLeft:2,clientTop:1};
  const frameScaleX=2,frameScaleY=2,layerScaleX=2,layerScaleY=2;
  const mapped=new Function('frameRect','layerRect','frame','frameScaleX',
    'frameScaleY','layerScaleX','layerScaleY',
    snippet+'\nreturn mapped;'
  )(frameRect,layerRect,frame,frameScaleX,frameScaleY,layerScaleX,layerScaleY);
  assert.deepEqual(mapped({left:20,top:30,width:100,height:50}),{
    left:82,top:101,width:100,height:50,
  });
});
test('DF-D3 is Base-owned opt-in and preserves sandbox and command authority',()=>{
  assert.match(assets,/DESIGNER_INTERACTION_API = 1/);
  assert.match(editor,/shell\/interaction-presentation\.js/);
  assert.match(editor,/interactions: Object\.freeze\(\{ createPresentation:/);
  assert.match(editor,/createDesignerDragDropController\(/);
  assert.match(reports,/frame\.style\.pointerEvents = 'none'/);
  assert.match(reports,/preview\.setSelection\(null\)/);
  assert.match(reports,/frame\.getBoundingClientRect\(\)/);
  assert.match(reports,/dropLayer\.getBoundingClientRect\(\)/);
  assert.doesNotMatch(css,/\.cb-reports-platform__interaction-region\[draggable="true"\]:hover\s*\{\s*outline:/);
  assert.match(sharedCss,/\.cb-core-design-interaction-indicator/);
});

// Shared visual presentation must be available to non-native consumers without
// changing the native Platform document, selection, or drag controllers.

test('DF-D3 shared interaction CSS is opted-in, pointer-inert and state-complete',()=>{
  assert.ok(assets.includes("CB_CORE_URL . 'assets/css/design/designer-composition.css'"));
  const root='[data-cb-design-interaction-overlay="true"] ';
  const rule = selector => {
    const start=sharedCss.indexOf(selector+' {');
    assert.ok(start!==-1,'Missing shared rule: '+selector);
    return sharedCss.slice(start,sharedCss.indexOf('}',start)+1);
  };
  for(const element of ['.cb-core-design-interaction-layer','.cb-core-design-interaction-indicator']) {
    assert.match(rule(root+element),/pointer-events:\s*none;/);
    assert.match(rule(root+element),/position:\s*absolute;/);
  }
  for(const state of ['hover','focus','editing','dragging','drop']) {
    assert.ok(sharedCss.includes('.cb-core-design-interaction-indicator[data-state="'+state+'"]'));
  }
  for(const edge of ['before','after']) {
    assert.ok(sharedCss.includes('.cb-core-design-interaction-indicator[data-state="drop"][data-edge="'+edge+'"]'));
  }
  assert.match(rule(root+'.cb-core-design-interaction-indicator'),/var\(--cb-accent/);
  assert.match(rule(root+'.cb-core-design-shell__canvas-workarea'),/position:\s*relative;/);
  assert.match(rule(root+'[data-cb-design-drop-active="true"]'),/outline:\s*none !important;/);
  assert.doesNotMatch(css,/\.cb-core-design-shell--native \.cb-core-design-interaction-(?:layer|indicator)/);
  assert.match(css,/\.cb-core-platform-screen \.cb-core-design-shell--native \.cb-core-design-shell__canvas-workarea/);
  assert.match(css,/\.cb-mail-platform__node/);
  assert.match(css,/\.cb-reports-platform__interaction-region/);
});
