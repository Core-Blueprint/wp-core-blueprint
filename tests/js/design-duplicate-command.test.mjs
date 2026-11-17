import assert from 'node:assert/strict';
import {test} from 'node:test';
import {duplicateNodeCommand} from '../../assets/js/design/core/commands.js';
import {ProjectState} from '../../assets/js/design/core/project-state.js';
import {EditorState} from '../../assets/js/design/core/editor-state.js';
import {CommandHistory} from '../../assets/js/design/core/history.js';
import * as mail from '../../assets/js/design/mail/platform-adapter.js';
import * as reports from '../../assets/js/design/reports/platform-adapter.js';
import {projectDesignerLayerInteractions} from '../../assets/js/design/shell/layers.js';
import {DESIGNER_ICON_NAMES} from '../../assets/js/design/shell/icons.js';

const n = (type,children=[],properties={})=>({type,provider:'fixture',properties,children});
const fixture=()=>({schema_version:0,design_type:'fixture',root:n('root',[
  n('section',[n('heading',[],{text:'Original'})],{title:'Section'}),
  n('paragraph',[],{text:'Trailing'}),
])});

test('DP-DUP-01: subtree copy is deeply independent and directly below source',()=>{
 const input=fixture(),effect=duplicateNodeCommand([0]).apply(input);
 assert.equal(input.root.children.length,2);
 assert.equal(effect.project.root.children.length,3);
 assert.deepEqual(effect.project.root.children[1],input.root.children[0]);
 assert.notStrictEqual(effect.project.root.children[1],input.root.children[0]);
 assert.notStrictEqual(effect.project.root.children[1].children[0],input.root.children[0].children[0]);
 assert.deepEqual(effect.selectPath,[1]);
 assert.deepEqual(effect.remapSelection([1]),[2]);
 assert.throws(()=>duplicateNodeCommand([]),/non-root/);
 assert.throws(()=>duplicateNodeCommand([5]).apply(input),/unknown/);
});

test('DP-DUP-02: one History entry; one Undo and Redo restore the exact subtree and selection',()=>{
 const state=new ProjectState(fixture()),editor=new EditorState(),history=new CommandHistory(state,editor);
 history.execute(duplicateNodeCommand([0]));
 assert.equal(history.size,1);
 assert.equal(state.current().root.children.length,3);
 assert.deepEqual(editor.selection.primary(),[1]);
 assert.equal(history.undo(),true);
 assert.equal(state.current().root.children.length,2);
 assert.equal(history.redo(),true);
 assert.equal(state.current().root.children.length,3);
 assert.deepEqual(editor.selection.primary(),[1]);
});

test('DP-DUP-03: trusted identity mapper is synchronous; original retains its identity',()=>{
 const input=fixture();
 const copy=duplicateNodeCommand([0],item=>{item.properties.title='Copy';return item;}).apply(input);
 assert.equal(input.root.children[0].properties.title,'Section');
 assert.equal(copy.project.root.children[1].properties.title,'Copy');
 assert.throws(()=>duplicateNodeCommand([0],()=>Promise.resolve({})).apply(input),/synchronous/);
});

const reportNode=(type,properties,children=[])=>({type,provider:'core.blueprint',properties,children});
const report=()=>({schema_version:1,design_type:'core.blueprint.reports',root:reportNode('core.blueprint.reports',{title:'Report'},[
 reportNode('reports.header',{showSiteUrl:'on',showMetadata:'on'}),
 reportNode('reports.section',{title:'Original',enabled:'on'},[reportNode('reports.heading',{text:'Hello'})]),
 reportNode('reports.footer',{showPageNumber:'on'}),
])});
test('DP-DUP-04: Reports admits nested sections but denies fixed header/footer',()=>{
 const p=report(),cmd=reports.resolveCommand('reports.duplicate');
 assert.equal(reports.validateProject(p),true);
 assert.equal(cmd.canApply(p,{path:[1]}),true);
 assert.equal(cmd.apply(p,{path:[1]}).project.root.children[2].children[0].properties.text,'Hello');
 assert.equal(cmd.canApply(p,{path:[0]}),false);
 assert.equal(cmd.canApply(p,{path:[2]}),false);
 assert.equal(reports.layerPolicyFor(p.root.children[0],p.root,0).duplicate,false);
 assert.equal(reports.layerPolicyFor(p.root.children[1],p.root,1).duplicate,true);
});

test('DP-DUP-05: Mail copies a valid two-column set; individual fixed columns refused',()=>{
 const m=(type,properties,children=[])=>({type,provider:'core.blueprint',properties,children});
 const p={schema_version:1,design_type:'core.blueprint.mail',root:m('core.blueprint.mail',{
 background:'#f3f4f6',contentBackground:'#ffffff',textColor:'#1f2937',accentColor:'#2563eb',
 fontFamily:'Arial, Helvetica, sans-serif',width:600,padding:32},[
 m('mail.section',{background:'#ffffff',padding:32,spacing:0},[
 m('mail.columns',{ratio:'equal',gap:16,spacing:20},[
 m('mail.column',{background:'#ffffff',padding:0}),m('mail.column',{background:'#ffffff',padding:0})])
 ])])};
 assert.equal(mail.validateProject(p),true);
 const cmd=mail.resolveCommand('mail.duplicate');
 assert.equal(cmd.canApply(p,{path:[0,0]}),true);
 assert.equal(cmd.apply(p,{path:[0,0]}).project.root.children[0].children[1].children.length,2);
 assert.equal(cmd.canApply(p,{path:[0,0,0]}),false);
});

test('DP-DUP-06: shared Layers policy forbids unique elements and disables unadmitted actions',()=>{
 const p=fixture(),logs=[],item=p.root.children[0];
 const opts={node:item,parent:p.root,path:[0],policy:{remove:true,duplicate:true},
 commandIds:{remove:'fx.remove',set:'fx.set',reorder:'fx.reorder',duplicate:'fx.duplicate'},
 execute:(id,input)=>logs.push([id,input]),isReady:()=>true};
 const yes=projectDesignerLayerInteractions({...opts,canDuplicate:()=>true});
 yes.actions.duplicate.onActivate();
 assert.deepEqual(logs,[['fx.duplicate',{path:[0]}]]);
 const no=projectDesignerLayerInteractions({...opts,canDuplicate:()=>false});
 assert.equal(no.actions.duplicate.disabled,true);
 no.actions.duplicate.onActivate();
 assert.equal(logs.length,1);
 const unique=projectDesignerLayerInteractions({...opts,policy:{remove:false,duplicate:false}});
 assert.equal(Object.hasOwn(unique.actions,'duplicate'),false);
});

test('DP-DUP-07: Duplicate uses the canonical Base Lucide copy icon',()=>{
 assert.equal(DESIGNER_ICON_NAMES.includes('copy'),true);
});

// DP-DUP-08: menu eligibility remains bounded for full Mail templates.
test('DP-DUP-08: Mail disables Duplicate at max node count without mutating the document',()=>{
 const m=(type,properties,children=[])=>({type,provider:'core.blueprint',properties,children});
 const heading=()=>m('mail.heading',{text:'Heading',fontSize:28,align:'left',color:'#1f2937',spacing:16});
 const section=()=>m('mail.section',{background:'#ffffff',padding:32,spacing:0},[]);
 const root=m('core.blueprint.mail',{background:'#f3f4f6',contentBackground:'#ffffff',textColor:'#1f2937',
 accentColor:'#2563eb',fontFamily:'Arial, Helvetica, sans-serif',width:600,padding:32},[section()]);
 const p={schema_version:1,design_type:'core.blueprint.mail',root};
 root.children[0].children=Array.from({length:4997},heading);
 assert.equal(mail.validateProject(p),true);
 assert.equal(mail.layerPolicyFor(root.children[0].children[0],root.children[0],0,p).duplicate,true);
 root.children[0].children.push(heading());
 assert.equal(mail.validateProject(p),true);
 assert.equal(mail.layerPolicyFor(root.children[0].children[0],root.children[0],0,p).duplicate,true);
 assert.equal(mail.layerPolicyFor(root.children[0].children[0],root.children[0],0,p).duplicateAvailable,false);
 assert.equal(mail.resolveCommand('mail.duplicate').canApply(p,{path:[0,0]}),false);
});
