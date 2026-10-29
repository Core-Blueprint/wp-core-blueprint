import assert from 'node:assert/strict';
import {test} from 'node:test';
import {readFileSync} from 'node:fs';

const editor = readFileSync(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
const presentationSource = readFileSync(new URL('../../assets/js/design/shell/interaction-presentation.js', import.meta.url), 'utf8');
const hostSource = readFileSync(new URL('../../assets/js/design/platform/host.js', import.meta.url), 'utf8');
const {createDesignerInteractionPresentation} = await import(
  'data:text/javascript;charset=utf-8,' + encodeURIComponent(presentationSource)
);

const extract = (start, end) => {
  const a = editor.indexOf(start), b = editor.indexOf(end, a);
  assert.ok(a >= 0 && b > a, 'Expected one known Host lifecycle boundary');
  return editor.slice(a, b);
};

const activation = extract('\t\t\tconst activateInteractions = () => {',
  '\n\t\t\tconst releaseNodeSources = () => {');
const publication = extract('\tconst publishCommitted = () => {',
  '\n\treturn Object.freeze({\n\t\tget root()');

test('DF-D3 P0 keeps the RenderAdapter surface empty through Host staging', () => {
  assert.match(hostSource, /renderSurface\.isConnected \|\| renderSurface\.childNodes\.length/);
  const staging = extract('\t\tconst createShell = (',
    '\n\t\t\tconst activateInteractions = () => {');
  assert.doesNotMatch(staging, /createDesignerInteractionPresentation\(\{/);
  assert.match(editor, /activateInteractions,\s*refreshInteraction:/);
  assert.match(editor, /refreshInteraction: \(\) => interactions\?\.refresh\(\)/);
  assert.match(editor, /interactions\?\.destroy\(\)/);
  assert.match(editor, /interactions\?\.refresh\(\)/);
  assert.match(publication, /shell\.activateInteractions\(\)/);
  assert.ok(publication.indexOf('shell.activateInteractions()') >
    publication.indexOf("if (host.state !== 'READY')"));
});

test('DF-D3 P0 only mounts the overlay after READY, once per context', () => {
  let created = 0, refreshed = 0, disposed = 0;
  const host = {state:'MOUNTING'};
  const stagedRoot = {isConnected:false};
  const signal = {aborted:false};
  const dropSurface = {};
  const editorFacade = {selection:()=>({primary:null})};
  const dnd = {active:false};
  const instantiate = () => {
    created++;
    return {refresh(){refreshed++},destroy(){disposed++}};
  };
  const make = new Function('signal','host','stagedRoot','dropSurface',
    'editor','dnd','createDesignerInteractionPresentation',
    'let interactions = null;\n' + activation + '\nreturn {activateInteractions, get:()=>interactions};');
  const instance = make(signal,host,stagedRoot,dropSurface,editorFacade,dnd,instantiate);
  assert.throws(() => instance.activateInteractions(), /DESIGNER_INTERACTION_UNAVAILABLE/);
  assert.equal(created,0,'Offscreen stage must not allocate overlay DOM');
  host.state='READY'; stagedRoot.isConnected=true;
  assert.equal(instance.activateInteractions(),true);
  assert.equal(instance.activateInteractions(),true);
  assert.equal(created,1,'One overlay per active Host context');
  assert.equal(refreshed,1);
  assert.equal(disposed,0);
});

test('DF-D3 P0 post-commit overlay failure is visible without rejecting READY', () => {
  const errors=[], events=[], alerts=[];
  let subscribed = false, synced = false;
  const canvas = {
    dataset:{cbDesignShellInitialized:'true'},
    ownerDocument:{createElement:()=>({
      setAttribute(){},
      set className(value){this.css=value},
      set textContent(value){this.text=value},
    })},
    querySelector:()=>({after:node=>alerts.push(node)}),
    dispatchEvent:event=>events.push(event),
  };
  const shell={refreshInteraction(){},activateInteractions(){throw new Error('FAKE_OVERLAY_FAILURE')}};
  const host={state:'READY',editor:{subscribe(){subscribed=true;return ()=>{}}}};
  const stagedShells=new Map([[canvas,shell]]);
  const stagedSaveUI=new Map([[canvas,{signal:{aborted:false,addEventListener(){}},syncSaveUI(){synced=true}}]]);
  const canvasRoot={firstElementChild:canvas};
  const Console={error:(...args)=>errors.push(args)};
  const Event=class {constructor(type,props){this.type=type;this.detail=props.detail}};
  const publish=new Function('host','canvasRoot','stagedShells','stagedSaveUI','CustomEvent','console',
    publication+'\nreturn publishCommitted;'
  )(host,canvasRoot,stagedShells,stagedSaveUI,Event,Console);
  assert.doesNotThrow(()=>publish());
  assert.equal(host.state,'READY');
  assert.equal(subscribed,true);
  assert.equal(synced,true);
  assert.equal(canvas.dataset.cbDesignInteractionStatus,'unavailable');
  assert.equal(alerts.length,1);
  assert.match(alerts[0].text,/Reload the Designer/);
  assert.equal(events[0].type,'cb:design-shell:interaction-failure');
  assert.equal(events[0].detail.code,'DESIGNER_INTERACTION_UNAVAILABLE');
  assert.equal(errors.length,1);
});

test('DF-D3 P0 unwinds partial presentation DOM and listeners on construction failure', () => {
  const registered = new Map();
  let layerRemoved = false;
  const rect={left:0,top:0,width:100,height:80,right:100,bottom:80};
  const view={
    addEventListener(){},removeEventListener(){},
    MutationObserver:class {
      observe(){throw new Error('FAKE_OBSERVER_FAILED')}
      disconnect(){}
    },
  };
  const layer={
    dataset:{},style:{},offsetWidth:100,offsetHeight:80,
    setAttribute(){},remove(){layerRemoved=true},
    getBoundingClientRect:()=>rect,
    contains:()=>false,
  };
  const doc={defaultView:view,createElement:()=>layer};
  const root={
    isConnected:true, ownerDocument:doc,
    contains:()=>true,getAttribute:()=>null,setAttribute(){},removeAttribute(){},
    addEventListener(name,fn){registered.set('root:'+name,fn)},
    removeEventListener(name){registered.delete('root:'+name)},
  };
  const surface={
    ownerDocument:doc,
    querySelector:()=>null,append(){},
    addEventListener(name,fn){registered.set('surface:'+name,fn)},
    removeEventListener(name){registered.delete('surface:'+name)},
    querySelectorAll:()=>[],
  };
  assert.throws(()=>createDesignerInteractionPresentation({
    root,surface,getSelection:()=>null,isDragging:()=>false,
  }),/FAKE_OBSERVER_FAILED/);
  assert.equal(layerRemoved,true);
  assert.equal(registered.size,0,'No staging or post-commit event leaks');
});
