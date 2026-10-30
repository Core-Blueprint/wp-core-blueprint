import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {performance} from 'node:perf_hooks';
import {runInNewContext} from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../assets/js/design/document/flow/preview-host.js', import.meta.url), 'utf8');
const {createFlowPreviewHost} = await import('data:text/javascript;charset=utf-8,' + encodeURIComponent(source));
const canonical = '<!doctype html><html data-cb-core-flow-preview-protocol="1" data-cb-core-flow-preview-generation="0">'
  + '<head><meta name="cb-core-flow-preview-protocol" content="1">'
  + '<meta http-equiv="Content-Security-Policy" content="script-src &#39;sha256-fixture&#39;;"></head>'
  + '<body><div data-cb-flow-preview-root="1"></div>'
  + '<script data-cb-core-flow-preview-sizing="1"></script></body></html>';

const region = (id, extended = true) => extended
  ? {id, top:10, height:20, left:10, width:80}
  : {id, top:10, height:20};

const fixture = () => {
  const events = new Map();
  const windowRef = {
    addEventListener(type, fn) {events.set(type,fn);},
    removeEventListener(type,fn){if(events.get(type)===fn)events.delete(type);},
    setTimeout(){return 1;},clearTimeout(){},
  };
  const documentRef = {
    defaultView:windowRef,
    createElement(){return {className:'',style:{},setAttribute(){},parentNode:null,hidden:false};},
  };
  const parentNode = {
    insertBefore(state){state.parentNode=this;},
    removeChild(state){state.parentNode=null;},
  };
  const sent=[];
  const child={postMessage(data){sent.push(data);}};
  const attributes=new Map();
  const loads=new Set();
  const iframe={
    tagName:'IFRAME',ownerDocument:documentRef,parentNode,contentWindow:child,
    style:{},srcdoc:'',
    setAttribute(k,v){attributes.set(k,String(v));},
    getAttribute(k){return attributes.get(k)??null;},
    removeAttribute(k){attributes.delete(k);},
    addEventListener(type,fn){if(type==='load')loads.add(fn);},
    removeEventListener(type,fn){if(type==='load')loads.delete(fn);},
  };
  const received=[],activated=[];
  const host=createFlowPreviewHost(iframe,{
    onRegions(items){received.push(items);},
    onActivate(id){activated.push(id);},
  });
  const message=(data,sourceWindow=child)=>events.get('message')?.({source:sourceWindow,data});
  return {host,message,iframe,received,activated,events,sent};
};
const regions = count => Array.from({length:count},(_,i)=>region('r'+i));
const packet=(list,generation=1)=>({type:'cb-core-flow-preview-regions',version:1,generation,regions:list});
const ready=(f,generation=1)=>{
  f.message({type:'cb-core-flow-preview-size',version:1,generation,height:450});
};

test('Flow Preview v1 accepts shallow and eight-level nested geometry, 100/101/500 without truncation',()=>{
  for (const count of [1,100,101,500]) {
    const f=fixture();
    assert.equal(f.host.render(canonical),true);
    const list=regions(count);
    if(count===1) list[0]=region('r0',false);
    if(count===500) list[499]=region('r0.1.2.3.4.5.6.7');
    const start=performance.now();
    f.message(packet(list));
    const elapsed=performance.now()-start;
    assert.equal(f.received.at(-1).length,count);
    assert.equal(f.received.at(-1).at(-1).id,list.at(-1).id);
    assert.ok(elapsed < 5000,'representative 500-region geometry must stay bounded');
    ready(f);
    assert.equal(f.iframe.getAttribute('data-cb-core-flow-preview-state'),'ready');
    f.host.destroy();
    assert.equal(f.events.has('message'),false);
    assert.equal(f.received.at(-1).length,0,'teardown clears trusted geometry');
  }
});

test('Flow Preview v1 accepts legacy 1/2/3 and maximum 8 numeric semantic levels',()=>{
  const f=fixture();
  assert.equal(f.host.render(canonical),true);
  const list=['r0','r0.1','r0.1.2','r0.1.2.3.4.5.6.7'].map(id=>region(id));
  f.message(packet(list));
  assert.deepEqual(f.received.at(-1).map(item=>item.id),list.map(item=>item.id));
  ready(f);
  assert.equal(f.iframe.getAttribute('data-cb-core-flow-preview-state'),'ready');
  f.host.destroy();
});

test('Flow Preview v1 rejects 501, bad depth, duplicated IDs and malformed geometry atomically',()=>{
  const failures=[
    regions(501),
    [region('r0.1.2.3.4.5.6.7.8')],
    [region('r00')],
    [region('r0..1')],
    [region('r0.01')],
    [region('r9007199254740992')],
    [region('r0'),region('r0')],
    [region('r1'),{...region('r2'),width:0}],
    [region('r1'),{...region('r2'),top:99999,height:2}],
    null, // bridge's explicit malformed-set signal
  ];
  for (const rejected of failures) {
    const f=fixture();
    f.host.render(canonical);
    f.message(packet([region('r0')]));
    assert.equal(f.received.at(-1).length,1);
    f.message(packet(rejected));
    assert.equal(f.received.at(-1).length,0,'previous geometry is cleared, never retained');
    assert.equal(f.iframe.getAttribute('data-cb-core-flow-preview-state'),'failure');
    f.message(packet([region('r9')]));
    assert.equal(f.received.at(-1).length,0,'failed generation cannot recover silently');
    f.host.destroy();
  }
});

test('Flow Preview host ignores stale generations and stale frame windows',()=>{
  const f=fixture();
  f.host.render(canonical);
  f.message(packet([region('r0')]));
  f.host.render(canonical); // generation 2 clears generation 1
  f.message(packet(regions(501),1));
  assert.notEqual(f.iframe.getAttribute('data-cb-core-flow-preview-state'),'failure');
  f.message(packet([region('r1')],2),{postMessage(){}});
  assert.equal(f.received.at(-1).length,0);
  f.message(packet([region('r1')],2));
  assert.equal(f.received.at(-1).length,1);
  f.message({type:'cb-core-flow-preview-activate',version:1,generation:1,region:'r0'});
  assert.deepEqual(f.activated,[]);
  ready(f,2);
  f.message({type:'cb-core-flow-preview-activate',version:1,generation:2,region:'r1'});
  assert.deepEqual(f.activated,['r1']);
  f.host.destroy();
});

const rendererPath=fileURLToPath(new URL('../../src/Design/Profile/Document/Flow/HtmlRenderer.php',import.meta.url));
const php='define("ABSPATH","/");require $argv[1];$r=new ReflectionClass("CoreBlueprint\\\\Core\\\\Design\\\\Profile\\\\Document\\\\Flow\\\\HtmlRenderer");echo $r->getReflectionConstant("PREVIEW_SIZING_BRIDGE")->getValue();';
const bridge=execFileSync('php',['-r',php,rendererPath],{encoding:'utf8'});

const bridgeFixture=(ids)=>{
  const sent=[];
  class HTMLElement {}
  const targets=ids.map(entry=>{
    const el=new HTMLElement();
    el.getAttribute=()=>entry.id;
    el.getBoundingClientRect=()=>entry.rect??{top:20,left:10,width:120,height:25};
    return el;
  });
  const root={
    getAttribute:()=> '1',
    querySelectorAll:selector=>selector==='[data-cb-flow-preview-region]'?targets:[],
    getBoundingClientRect:()=>({height:400}),
    scrollHeight:400,offsetHeight:400,
    addEventListener(){},
    contains(){return true;},
  };
  const document={
    documentElement:{getAttribute:()=> '1'},
    querySelectorAll(selector){
      if(selector==='meta[name="cb-core-flow-preview-protocol"]') return [{content:'1'}];
      if(selector==='[data-cb-flow-preview-root]')return [root];
      return [];
    },
  };
  const window={
    parent:{postMessage(data){sent.push(data);}},
    addEventListener(){},
  };
  const ResizeObserver=class {observe(){}};
  const started=performance.now();
  runInNewContext(bridge,{document,window,HTMLElement,ResizeObserver,requestAnimationFrame(){return 1;}},{timeout:5000});
  assert.ok(performance.now()-started<5000,'iframe measurement is bounded');
  return sent;
};

test('Actual CSP-hashed PHP iframe bridge sends all 500 regions, not a 100-region prefix',()=>{
  for(const count of [1,100,101,500]){
    const sent=bridgeFixture(Array.from({length:count},(_,i)=>({id:'r'+i})));
    const geometry=sent.find(message=>message.type==='cb-core-flow-preview-regions');
    assert.equal(geometry.regions.length,count);
    assert.equal(geometry.regions.at(-1).id,'r'+(count-1));
    assert.ok(sent.some(message=>message.type==='cb-core-flow-preview-size'));
  }
  const nested=bridgeFixture([{id:'r0.1.2.3.4.5.6.7'}]);
  assert.equal(nested[0].regions[0].id,'r0.1.2.3.4.5.6.7');
});

test('Actual iframe bridge fails closed on 501/invalid/unsafe geometry without posting a prefix',()=>{
  for(const ids of [
    Array.from({length:501},(_,i)=>({id:'r'+i})),
    [{id:'r0'},{id:'r0.1.2.3.4.5.6.7.8'}],
    [{id:'r00'}],
    [{id:'r0.00'}],
    [{id:'r9007199254740992'}],
    [{id:'r0',rect:{top:99999,left:0,width:100,height:2}}],
  ]){
    const sent=bridgeFixture(ids);
    assert.equal(sent.length,1,'invalid geometry cannot send a valid size or partial map');
    assert.equal(sent[0].type,'cb-core-flow-preview-regions');
    assert.equal(sent[0].regions,null,'explicit failure envelope');
  }
});

test('Iframe bridge may merge repeated DOM targets for one semantic ID without duplicating regions',()=>{
  const sent=bridgeFixture([
    {id:'r0',rect:{top:10,left:10,width:20,height:15}},
    {id:'r0',rect:{top:35,left:35,width:25,height:15}},
  ]);
  assert.equal(sent[0].regions.length,1);
  assert.deepEqual(JSON.parse(JSON.stringify(sent[0].regions[0])),{
    id:'r0',top:10,height:40,left:10,width:50,
  });
});
