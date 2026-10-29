import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';

const editor = readFileSync(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
const reports = readFileSync(new URL('../../assets/js/design/reports/platform-adapter.js', import.meta.url), 'utf8');
const presentation = readFileSync(new URL('../../assets/js/design/shell/interaction-presentation.js', import.meta.url), 'utf8');
const dndSource = readFileSync(new URL('../../assets/js/design/shell/drag-drop.js', import.meta.url), 'utf8');
const {createDesignerDragDropController} = await import('data:text/javascript;charset=utf-8,' + encodeURIComponent(dndSource));
const {createDesignerInteractionPresentation} = await import(
  'data:text/javascript;charset=utf-8,' + encodeURIComponent(presentation));

const statusStart = editor.indexOf('\t\t\t\tonStatus: ({status}) => {');
const statusEnd = editor.indexOf('\n\t\t\t\t},', statusStart);
assert.ok(statusStart >= 0 && statusEnd > statusStart, 'canonical Base onStatus present');
const onStatusSource = editor.slice(
  statusStart + '\t\t\t\tonStatus: '.length,
  statusEnd + '\n\t\t\t\t}'.length
);
const makeOnStatus = (stagedRoot, dropSurface, activeRegistry, interactions) =>
  new Function('stagedRoot', 'dropSurface', 'activeRegistry', 'interactions',
    'return (' + onStatusSource + ');'
  )(stagedRoot, dropSurface, activeRegistry, interactions);

test('DF-D4A Reports native dragstart never exposes overlapping independent proxy zones; Mail unchanged', () => {
  const proxy = {style: {pointerEvents:'none', visibility:'hidden'}};
  const iframe = {style:{pointerEvents:'none'}};
  const stagedRoot = {dataset:{}};
  let refreshes=0;
  const surface = {querySelectorAll(selector) {
    if (selector === '[data-cb-design-drop-zone-id]') return [proxy];
    if (selector === 'iframe[data-cb-core-flow-preview-host]') return [iframe];
    return [];
  }};
  const registry = {profileId:'base/reports'};
  const onStatus = makeOnStatus(stagedRoot, surface, registry, {refresh(){refreshes++;}});
  onStatus({status:'started'});
  assert.equal(stagedRoot.dataset.cbDesignDndActive,'true');
  assert.equal(proxy.style.pointerEvents,'none');
  assert.equal(proxy.style.visibility,'hidden');
  assert.equal(iframe.style.pointerEvents,'none');
  onStatus({status:'committed'});
  assert.equal(stagedRoot.dataset.cbDesignDndActive,'false');
  assert.equal(proxy.style.pointerEvents,'none');
  registry.profileId='base/mail';
  onStatus({status:'started'});
  assert.equal(proxy.style.pointerEvents,'auto','Mail retains existing native target activation');
  assert.equal(proxy.style.visibility,'visible');
  onStatus({status:'cancelled'});
  assert.equal(proxy.style.pointerEvents,'none');
  assert.equal(proxy.style.visibility,'hidden');
  assert.equal(refreshes,4);
});

test('DF-D4A Reports geometry refresh never reactivates an independent proxy during a drag', () => {
  const begin = reports.indexOf('\tconst syncInteractionSelection = () => {');
  const end = reports.indexOf('\n\treturn Object.freeze({',begin);
  assert.ok(begin >= 0 && end > begin);
  const source = reports.slice(begin,end);
  const doc = {defaultView:{CustomEvent:class{constructor(type){this.type=type;}}}};
  class Element {
    constructor() {this.dataset={};this.style={};this.attrs={};this.children=[];this.parent=null;this.ownerDocument=doc;}
    append(el){el.parent=this;this.children.push(el);}
    remove(){if(this.parent)this.parent.children=this.parent.children.filter(el=>el!==this);this.parent=null;}
    setAttribute(k,v){this.attrs[k]=String(v);}
    getAttribute(k){return this.attrs[k]??null;}
    querySelectorAll(sel){
      if(sel==='[data-cb-design-drop-zone-id]')return this.children.filter(el=>el.dataset.cbDesignDropZoneId);
      if(sel==='[data-cb-reports-direct-region]')return this.children.filter(el=>el.dataset.cbReportsDirectRegion);
      return [];
    }
    getBoundingClientRect(){return {left:0,top:0,width:800,height:500};}
    dispatchEvent(){}
  }
  const layer=new Element();
  layer.offsetWidth=800;layer.offsetHeight=500;
  const frame={offsetWidth:800,offsetHeight:500,clientWidth:800,clientLeft:0,clientTop:0,
    getBoundingClientRect:()=>({left:0,top:0,width:800,height:500})};
  const project={root:{type:'root',children:[
    {type:'reports.header',children:[]},
    {type:'reports.status',children:[]},
    {type:'reports.kpis',children:[]},
    {type:'reports.footer',children:[]},
  ]}};
  const callbacks=new Function('dropLayer','frame','lastProject','lastSelection','lastRegions',
    'destroyed','activeSignal','container','el','nodeAt','previewPathForRegion',
    'doc','editor',source+'\nreturn {onRegions};'
  )(layer,frame,project,{primary:null},[],false,null,
    {closest:()=>({dataset:{cbDesignDndActive:'true'}})},()=>new Element(),
    (root,path)=>path.reduce((node,i)=>node?.children?.[i],root),
    id=>[Number(id.slice(1))],doc,{select(){}});
  const regions=[0,1,2,3].map(i=>({id:'r'+i,left:10,top:i*85,width:600,height:70}));
  callbacks.onRegions(regions);
  callbacks.onRegions(regions.map(r=>({...r,top:r.top+2})));
  for(const zone of layer.querySelectorAll('[data-cb-design-drop-zone-id]')){
    assert.equal(zone.style.pointerEvents,'none');
    assert.equal(zone.style.visibility,'hidden');
  }
  const hits=layer.querySelectorAll('[data-cb-reports-direct-region]');
  assert.equal(hits.length,4);
  for (let i=0;i<hits.length;i++) {
    assert.equal(hits[i].dataset.cbDesignNodePath,JSON.stringify([i]),
      'trusted native interaction region retains semantic drop-target path');
  }
  assert.equal(hits[0].dataset.cbDesignNodeDragSource,undefined,'header cannot move');
  assert.equal(hits[3].dataset.cbDesignNodeDragSource,undefined,'footer cannot move');
});

test('DF-D4A direct Reports node target handles valid move and palette insert exactly once; invalid drop is inert', () => {
  const doc = {createElement(){return new Element();},addEventListener(){},removeEventListener(){}};
  class Element {
    constructor(){this.ownerDocument=doc;this.parent=null;this.children=[];this.attrs={};this.listeners={};this.isConnected=true;}
    append(child){child.parent=this;this.children.push(child);}
    remove(){if(this.parent)this.parent.children=this.parent.children.filter(el=>el!==this);
      this.parent=null;this.isConnected=false;}
    contains(other){return this===other||this.children.some(child=>child.contains(other));}
    addEventListener(k,fn){this.listeners[k]=fn;}
    removeEventListener(k,fn){if(this.listeners[k]===fn)delete this.listeners[k];}
    getAttribute(k){return this.attrs[k]??null;}
    setAttribute(k,v){this.attrs[k]=String(v);}
    removeAttribute(k){delete this.attrs[k];}
    getBoundingClientRect(){return {top:40,height:100};}
  }
  const root=new Element(),source=new Element(),target=new Element(),palette=new Element();
  root.append(source);root.append(target);root.append(palette);
  source.dataset={cbDesignNodePath:'[1]',cbDesignNodeDragSource:'[1]',cbDesignNodeType:'reports.status'};
  target.dataset={cbDesignNodePath:'[2]',cbDesignNodeType:'reports.kpis'};
  const committed=[];
  const dnd=createDesignerDragDropController({
    root,isReady:()=>true,getContextKey:()=> 'reports:valid:1',
    resolveIntent:({source,zoneId,edge})=>({commandId:source.kind==='node'?'reports.move':'reports.insert',
      input:{path:source.path,zoneId,edge}}),
    canDrop:({intent})=>intent.input.zoneId==='node/2' && intent.input.edge==='inside',
    commit:({source,intent})=>committed.push({source,intent}),
  });
  dnd.registerSource(source,{id:'node/1',type:'reports.status',kind:'node',path:[1],directCanvas:true});
  dnd.registerSource(palette,{id:'reports.heading',type:'reports.heading'});
  dnd.registerZone(target,{id:'node/2'});
  const transfer={setData(){}};
  const event=(y)=>({target:{closest:()=>null},clientY:y,dataTransfer:transfer,
    preventDefault(){this.prevented=true;},stopPropagation(){}});
  source.listeners.dragstart(event(80));
  assert.equal(dnd.active,true);
  const denied=event(45);
  target.listeners.dragover(denied);
  assert.equal(denied.prevented,undefined,'invalid before placement must not be authorized');
  target.listeners.drop(event(45));
  assert.equal(committed.length,0);
  assert.equal(dnd.active,true,'an invalid destination cannot commit');
  const allowed=event(80);
  target.listeners.dragover(allowed);
  assert.equal(allowed.prevented,true);
  assert.equal(target.attrs['data-cb-design-drop-active'],'true');
  assert.equal(target.attrs['data-cb-design-drop-edge'],'inside');
  target.listeners.drop(event(80));
  target.listeners.drop(event(80));
  assert.equal(committed.length,1,'native move dispatched exactly once');
  assert.equal(committed[0].intent.commandId,'reports.move');
  assert.deepEqual(committed[0].source.path,[1]);
  palette.listeners.dragstart(event(80));
  target.listeners.drop(event(80));
  assert.equal(committed.length,2,'palette insert uses same semantic target');
  assert.equal(committed[1].intent.commandId,'reports.insert');
  dnd.destroy();
});

test('DF-D4A shared Base overlay resolves direct node drop and selection to one semantic indicator', () => {
  const doc={activeElement:null};
  class Element {
    constructor(){this.dataset={};this.style={};this.children=[];this.attrs={};this.ownerDocument=doc;
      this.isConnected=true;this.offsetWidth=800;this.offsetHeight=500;}
    append(el){this.children.push(el);el.parent=this;}
    remove(){if(this.parent)this.parent.children=this.parent.children.filter(el=>el!==this);}
    setAttribute(k,v){this.attrs[k]=String(v);}
    getAttribute(k){return this.attrs[k]??null;}
    removeAttribute(k){delete this.attrs[k];}
    addEventListener(){}
    removeEventListener(){}
    contains(el){return this===el||this.children.some(child=>child.contains(el));}
    getBoundingClientRect(){return {left:0,top:0,width:800,height:500};}
    replaceChildren(...els){this.children=els;for(const el of els)el.parent=this;}
    querySelector(sel){
      if(sel==='[data-cb-design-interaction-layer]')return this.children.find(el=>el.dataset.cbDesignInteractionLayer);
      return null;
    }
    querySelectorAll(sel){
      if(sel==='[data-cb-design-node-path]')return this.children.filter(el=>el.dataset.cbDesignNodePath);
      if(sel==='[data-cb-design-drop-active="true"]')return this.children.filter(el=>el.attrs['data-cb-design-drop-active']==='true');
      return [];
    }
  }
  doc.createElement=()=>new Element();
  doc.defaultView={requestAnimationFrame:()=>1,cancelAnimationFrame(){}};
  const root=new Element(),surface=new Element(),hit=new Element();
  root.append(surface);surface.append(hit);
  hit.dataset.cbDesignNodePath='[1]';
  hit.dataset.cbDesignDropEdge='after';
  hit.setAttribute('data-cb-design-drop-active','true');
  const ui=createDesignerInteractionPresentation({
    root,surface,getSelection:()=>({kind:'node',path:[1]}),isDragging:()=>true,
  });
  const overlay=surface.querySelector('[data-cb-design-interaction-layer]');
  assert.ok(overlay);
  assert.equal(overlay.children.length,1,'one semantic node yields one outline');
  assert.equal(overlay.children[0].dataset.state,'drop');
  assert.equal(overlay.children[0].dataset.edge,'after');
  ui.destroy();
});
