import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';

const reports = readFileSync(new URL('../../assets/js/design/reports/platform-adapter.js', import.meta.url), 'utf8');
const dragSource = readFileSync(new URL('../../assets/js/design/shell/drag-drop.js', import.meta.url), 'utf8');
const {createDesignerDragDropController} = await import('data:text/javascript;charset=utf-8,' +
  encodeURIComponent(dragSource));
const start = reports.indexOf('\tconst syncInteractionSelection = () => {');
const end = reports.indexOf('\n\treturn Object.freeze({', start);
assert.ok(start > 0 && end > start, 'Reports render adapter region block present');
const callbackSource = reports.slice(start, end);

const fixture = () => {
  const doc={
    defaultView:{CustomEvent:class{constructor(type){this.type=type}}},
    createElement:name=>new Element(name),
    addEventListener(){},removeEventListener(){},
  };
  class Element {
    constructor(name='div') {
      this.className=name; this.dataset={}; this.attrs={}; this.style={};
      this.children=[]; this.listeners={}; this.parent=null; this.removed=false;
      this.ownerDocument=doc;this.isConnected=true;
    }
    append(child) { child.parent=this;this.children.push(child); }
    replaceChildren(...children) { this.children.forEach(n=>n.parent=null); this.children=children;
      this.children.forEach(n=>n.parent=this); }
    remove() { if(this.parent)this.parent.children=this.parent.children.filter(n=>n!==this);
      this.parent=null;this.removed=true;this.isConnected=false; }
    setAttribute(name,value) {this.attrs[name]=String(value);}
    getAttribute(name) {return this.attrs[name]??null;}
    removeAttribute(name) {delete this.attrs[name];}
    addEventListener(name,fn) {this.listeners[name]=fn;}
    removeEventListener(name,fn) {if(this.listeners[name]===fn)delete this.listeners[name];}
    contains(target) {return this===target||this.children.some(n=>n.contains(target));}
    getBoundingClientRect() {
      const left=parseFloat(this.style.left)||0,top=parseFloat(this.style.top)||0;
      const width=parseFloat(this.style.width)||800,height=parseFloat(this.style.height)||350;
      return {left,top,width,height,right:left+width,bottom:top+height};
    }
    dispatchEvent() {}
    closest() {return null;}
    querySelectorAll(selector) {
      if(selector==='[data-cb-reports-direct-region]')
        return this.children.filter(n=>typeof n.dataset.cbReportsDirectRegion==='string');
      if(selector==='[data-cb-design-drop-zone-id]')
        return this.children.filter(n=>typeof n.dataset.cbDesignDropZoneId==='string');
      return [];
    }
  }
  const root=new Element('main');
  const layer=new Element('layer');root.append(layer);
  layer.offsetWidth=800;layer.offsetHeight=350;
  layer.getBoundingClientRect=()=>({left:0,top:0,width:800,height:350});
  const frame={offsetWidth:800,offsetHeight:350,clientWidth:800,clientLeft:0,clientTop:0,
    getBoundingClientRect:()=>({left:10,top:20,width:800,height:350})};
  const project={root:{type:'root',children:[
    {type:'reports.header',children:[]},
    {type:'reports.section',children:[{type:'reports.heading',children:[]}]},
    {type:'reports.footer',children:[]},
  ]}};
  const nodeAt=(root,path)=>path.reduce((n,i)=>n?.children?.[i]??null,root);
  const previewPathForRegion=id=>/^r[0-9]+(?:\.[0-9]+)?$/.test(id)
    ?id.slice(1).split('.').map(Number):null;
  const container=new Element('reports');container.classList={contains:()=>false};
  const create=(tag,name)=>new Element(name);
  const editor={select(){}};
  const callbacks=new Function('dropLayer','frame','lastProject','lastSelection','lastRegions',
    'destroyed','activeSignal','container','el','nodeAt',
    'previewPathForRegion','doc','editor',
    callbackSource+'\nreturn {onRegions,syncInteractionSelection};'
  )(layer,frame,project,{primary:{kind:'node',path:[1,0]}},[],false,null,container,
    create,nodeAt,previewPathForRegion,doc,editor);
  const regions=[
    {id:'r0',top:1,height:30,left:1,width:700},
    {id:'r1',top:40,height:200,left:1,width:700},
    {id:'r1.0',top:55,height:30,left:20,width:400},
    {id:'r2',top:260,height:30,left:1,width:700},
  ];
  const hit=id=>layer.querySelectorAll('[data-cb-reports-direct-region]')
    .find(n=>n.dataset.cbReportsDirectRegion===id);
  const zone=id=>layer.querySelectorAll('[data-cb-design-drop-zone-id]')
    .find(n=>n.dataset.cbDesignDropZoneId===id);
  return {callbacks,layer,root,doc,regions,hit,zone};
};

test('DF-D4A repeated geometry updates keep the same Reports browser drag source and registered zone',()=>{
  const f=fixture();f.callbacks.onRegions(f.regions);
  const hit=f.hit('r1.0'),zone=f.zone('node/1.0');
  assert.ok(hit&&zone);
  hit.setAttribute('draggable','true'); // Base registerSource side effect
  hit.listeners.dragstart=()=>{}; // native source listener retains its node
  zone.listeners.dragover=()=>{}; // Base registerZone side effect
  f.callbacks.onRegions(f.regions.map(r=>r.id==='r1.0'?{...r,top:70}:r));
  assert.equal(f.hit('r1.0'),hit,'same identity survives normal preview geometry update');
  assert.equal(f.zone('node/1.0'),zone,'same zone retains its registered listeners');
  assert.equal(hit.attrs.draggable,'true');
  assert.equal(typeof hit.listeners.dragstart,'function');
  assert.equal(hit.style.top,'90px','proxy geometry still refreshes');
  assert.equal(f.layer.children.length,8,'no duplicate proxies');
});

test('DF-D4A real region removal retires old proxy instead of keeping stale target',()=>{
  const f=fixture();f.callbacks.onRegions(f.regions);
  const hit=f.hit('r1.0'),zone=f.zone('node/1.0');
  f.callbacks.onRegions(f.regions.filter(r=>r.id!=='r1.0'));
  assert.equal(hit.removed,true);
  assert.equal(zone.removed,true);
  assert.equal(f.hit('r1.0'),undefined);
  assert.equal(f.zone('node/1.0'),undefined);
});

test('DF-D4A integrated Base gesture survives Reports geometry refresh and commits exactly once',()=>{
  const f=fixture();f.callbacks.onRegions(f.regions);
  const source=f.hit('r1.0'),destination=f.zone('node/2');
  const committed=[];
  const dnd=createDesignerDragDropController({
    root:f.root,isReady:()=>true,getContextKey:()=> 'host:1:reports:epoch0',
    resolveIntent:({source,zoneId,edge})=>({commandId:'reports.move',input:{
      fromPath:source.path,zoneId,edge,
    }}),
    canDrop:({intent})=>intent.commandId==='reports.move',
    commit:({intent})=>committed.push(intent),
  });
  dnd.registerSource(source,{id:'node/1.0',type:'reports.heading',kind:'node',path:[1,0],directCanvas:true});
  dnd.registerZone(destination,{id:'node/2'});
  const transfer={effectAllowed:'',setData(){}};
  source.listeners.dragstart({target:{closest:()=>null},
    stopPropagation(){},preventDefault(){},dataTransfer:transfer});
  assert.equal(dnd.active,true,'drag started from trusted Reports parent proxy');
  f.callbacks.onRegions(f.regions.map(r=>r.id==='r1.0'?{...r,top:75}:r));
  assert.equal(f.hit('r1.0'),source);
  assert.equal(f.zone('node/2'),destination);
  assert.equal(dnd.active,true,'geometry refresh must not invalidate active gesture');
  let prevented=0;
  destination.listeners.dragover({clientY:285,preventDefault(){prevented++;},
    stopPropagation(){},dataTransfer:transfer});
  destination.listeners.drop({clientY:285,preventDefault(){prevented++;},
    stopPropagation(){},dataTransfer:transfer});
  assert.equal(committed.length,1);
  assert.deepEqual(committed[0].input.fromPath,[1,0]);
  destination.listeners.drop({clientY:285,preventDefault(){},stopPropagation(){}});
  assert.equal(committed.length,1,'duplicate drop cannot execute a second command');
  dnd.destroy();
});

test('DF-D4A actual removal of a Reports drag-source fails closed',()=>{
  const f=fixture();f.callbacks.onRegions(f.regions);
  const source=f.hit('r1.0'),destination=f.zone('node/2'),commits=[];
  const dnd=createDesignerDragDropController({
    root:f.root,isReady:()=>true,getContextKey:()=> 'host:1:reports:epoch0',
    resolveIntent:()=>({commandId:'reports.move',input:{}}),
    canDrop:()=>true,commit:()=>commits.push(1),
  });
  dnd.registerSource(source,{id:'node/1.0',type:'reports.heading',kind:'node',path:[1,0],directCanvas:true});
  dnd.registerZone(destination,{id:'node/2'});
  source.listeners.dragstart({target:{closest:()=>null},stopPropagation(){},
    dataTransfer:{setData(){}},preventDefault(){}});
  assert.equal(dnd.active,true);
  f.callbacks.onRegions(f.regions.filter(r=>r.id!=='r1.0'));
  destination.listeners.drop({clientY:285,stopPropagation(){},preventDefault(){}});
  assert.equal(dnd.active,false,'stale source must invalidate gesture');
  assert.equal(commits.length,0,'stale source must never commit');
  dnd.destroy();
});
