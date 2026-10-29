import assert from 'node:assert/strict';
import {test} from 'node:test';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../../assets/js/design/shell/interaction-presentation.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../../assets/css/design/platform-fullscreen.css', import.meta.url), 'utf8');
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
  assert.match(css,/\.cb-core-design-interaction-indicator/);
});
