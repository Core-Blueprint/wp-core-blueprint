import assert from 'node:assert/strict';
import {cp, mkdtemp, readFile, readdir, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
import {before, after, test} from 'node:test';

let editor, shellModule, selectionModule;
let temporary;
async function convert(dir) {
	for (const entry of await readdir(dir, {withFileTypes:true})) {
		const file = join(dir, entry.name);
		if (entry.isDirectory()) {await convert(file); continue;}
		if (!entry.isFile() || !file.endsWith('.js')) continue;
		let source = (await readFile(file,'utf8'))
			.replaceAll(/(['"])(\.\.?\/[^'"]+)\.js\1/g, '$1$2.mjs$1');
		if (entry.name === 'editor.js') {
			source = source.replace("'@cb-core/design-motion'", "'./core/motion.mjs'");
		}
		await writeFile(file.replace(/\.js$/, '.mjs'), source);
	}
}
class FakeElement {
	constructor(name = 'root') {
		this.name = name;
		this.dataset = {};
		this.parentNode = null;
		this.childNodes = [];
		this.isConnected = false;
		this.attributes = new Map();
		this.handlers = new Map();
		this.ownerDocument = globalThis.document;
		this.classList = {add(){},remove(){},toggle(){},contains(){return false;}};
	}
	matches(selector) {return selector === '[data-cb-design-shell]';}
	querySelector(selector) {
		if (selector === '[data-cb-design-shell-undo]') return this.undo;
		if (selector === '[data-cb-design-shell-redo]') return this.redo;
		return null;
	}
	querySelectorAll(){return [];}
	addEventListener(type, callback) {
		if (!this.handlers.has(type)) this.handlers.set(type,[]);
		this.handlers.get(type).push(callback);
	}
	dispatchEvent(){return true;}
	setAttribute(k,v){this.attributes.set(k,v);}
	getAttribute(k){return this.attributes.get(k)??null;}
	hasAttribute(k){return this.attributes.has(k);}
	removeAttribute(k){this.attributes.delete(k);}
	focus(){globalThis.document.activeElement=this;}
	replaceChildren(...children) {
		for(const child of this.childNodes) {child.parentNode=null;child.isConnected=false;}
		this.childNodes=[...children];
		for(const child of children) {child.parentNode=this;child.isConnected=true;}
	}
	fire(type) {for(const callback of this.handlers.get(type)??[]) callback({});}
}
class FakeButton extends FakeElement {constructor(name) {super(name);this.disabled=false;}}
class FakeEvent {constructor(type,{detail}={}) {this.type=type;this.detail=detail;}}
before(async()=>{
	temporary=await mkdtemp(join(tmpdir(),'cb-df-cx02d-'));
	const dest=join(temporary,'design');
	await cp(new URL('../../assets/js/design/',import.meta.url),dest,{recursive:true});
	await convert(dest);
	globalThis.document={
		activeElement:null,documentElement:{classList:{add(){},remove(){}}},
		addEventListener(){},removeEventListener(){},querySelector(){return null;},
	};
	globalThis.Element=FakeElement;
	globalThis.HTMLButtonElement=FakeButton;
	globalThis.CustomEvent=FakeEvent;
	globalThis.window={cbCore:{designerExitGuard:{canTransition:()=>true}},matchMedia:()=>({matches:false})};
	const module=(file)=>import(pathToFileURL(join(dest,file)).href);
	[editor,shellModule,selectionModule]=await Promise.all([
		module('editor.mjs'),module('shell/index.mjs'),module('shell/selection-controller.mjs'),
	]);
});
after(async()=>{
	delete globalThis.document;delete globalThis.Element;
	delete globalThis.HTMLButtonElement;delete globalThis.CustomEvent;delete globalThis.window;
	if(temporary)await rm(temporary,{recursive:true,force:true});
});

const names=['canvas','layers','inspector','controls','preview'];
const project=(name, count=0)=>({
	schema_version:0,design_type:'fixture.cx02d',
	root:{type:'fixture',props:{name,count},children:[]},
});
const spec=(name)=>({id:name,kind:'domain',sessionOptions:{project:project(name)}});
const increment=()=>Object.freeze({
	label:'increment',
	apply(current) {
		const next=structuredClone(current);
		next.root.props.count++;
		return {project:next};
	},
});
const deferred=()=>{
	let resolve;
	const promise=new Promise(r=>{resolve=r;});
	return {promise,resolve};
};
const denied=(call)=>assert.throws(call,error=>error?.code==='DESIGNER_CONTEXT_NOT_WRITABLE');
const fixture=({decision='discard', onLoad=null, onSave=null, onVerify=null,
	onAuthorize=null, onRevalidate=null, onCleanup=null}={})=>{
	const binding=editor.createManagedContextBinding({initial:spec('source')});
	const root=new FakeElement();
	root.isConnected=true;
	root.undo=new FakeButton('undo');
	root.redo=new FakeButton('redo');
	const shell=shellModule.createDesignerShell(root,{session:binding.current.session});
	const layers=[];
	const selection=selectionModule.createDesignerSelectionController({
		session:binding.current.session,typed:'domain',shell,
		renderLayers:({project})=>layers.push(project.root.props.name),
	});
	const slots=Object.fromEntries(names.map(name=>{
		const host=new FakeElement(name);
		host.isConnected=true;
		host.replaceChildren(new FakeElement('source-'+name));
		return [name,host];
	}));
	const events={saves:[],loads:[],revalidations:0,verified:0,late:[]};
	const nodes=(name)=>Object.fromEntries(names.map(k=>[k,[new FakeElement(name+'-'+k)]]));
	const coordinator=editor.createManagedContextCoordinator({
		binding,shell,selection,slots,
		authorize:async (args)=>onAuthorize?onAuthorize(args):true,
		revalidate:(args)=>{events.revalidations++;return onRevalidate?onRevalidate(args):true;},
		load:async (args)=>{
			events.loads.push(args);
			return onLoad?onLoad(args):{spec:spec(args.targetId),nodes:nodes(args.targetId)};
		},
		decide:async ()=>decision,
		saveStatus:()=> 'IDLE',
		save:async (args)=>{events.saves.push(args);return onSave?onSave(args):{status:'committed',verified:true,sourceId:args.sourceId,
			generation:args.generation,revision:args.revision,baselineProject:args.submittedProject};},
		verify:(args)=>{events.verified++;return onVerify?onVerify(args):true;},
		cleanup:onCleanup,
	});
	return {binding,root,shell,selection,coordinator,slots,layers,events,nodes};
};
const mounted=(f,target)=>names.every(name=>f.slots[name].childNodes[0]?.name===target+'-'+name);

test('CX02D: synchronous authority commit, five mounts, Shell, History, Selection and Inspector are unified',async()=>{
	const f=fixture();
	const old=f.binding.current;
	const oldSession=old.session;
	oldSession.execute(increment());
	assert.equal(oldSession.historyStatus().canUndo,true);
	const result=await f.coordinator.transition({targetId:'target'});
	assert.equal(result.status,'committed');
	assert.equal(f.binding.current.id,'target');
	assert.equal(old.status,'RETIRED');
	assert.ok(mounted(f,'target'));
	assert.equal(f.layers.at(-1),'target');
	assert.equal(f.root.undo.disabled,true);
	denied(()=>oldSession.execute(increment()));
	assert.throws(()=>oldSession.project(),error=>error?.code==='DESIGNER_CONTEXT_INVALID');
	assert.throws(()=>editor.createManagedContextCoordinator({binding:f.binding}),/AUTHORITY_REFUSED/);
	const current=f.binding.current.session;
	current.execute(increment());
	assert.equal(f.root.undo.disabled,false);
	f.root.undo.fire('click');
	assert.equal(current.project().root.props.count,0);
	current.select([{kind:'node',path:[]}],{kind:'node',path:[]},{expectedEpoch:0});
	assert.equal(current.selection().primary.kind,'node');
	assert.equal(f.layers.at(-1),'target');
	assert.equal(f.selection.refresh(),undefined);
	assert.equal(f.coordinator.status().state,'READY');
	f.selection.destroy();f.shell.destroy();
});

test('CX02D: target cannot mutate during synchronous view verification',async()=>{
	let fenced=false;
	const f=fixture({onVerify:({session})=>{
		denied(()=>session.execute(increment()));
		fenced=true;
		return true;
	}});
	const result=await f.coordinator.transition({targetId:'target'});
	assert.equal(result.status,'committed');
	assert.equal(fenced,true);
	f.binding.current.session.execute(increment());
	assert.equal(f.binding.current.session.project().root.props.count,1);
});

test('CX02D: dirty Discard leaves source unchanged until verified retirement',async()=>{
	let originalCount;
	const f=fixture({onVerify:()=>{
		originalCount=f.binding.current.session.project().root.props.count;
		assert.equal(f.binding.current.status,'LOCKED');
		return true;
	}});
	f.binding.current.session.execute(increment());
	assert.equal((await f.coordinator.transition({targetId:'target'})).status,'committed');
	assert.equal(originalCount,1,'Discard cannot erase dirty source during verification');
});

test('CX02D: dirty Save performs Base-owned checkpoint/rebase before switching',async()=>{
	let savedBaseline, snapshotAtVerify;
	const f=fixture({decision:'save',onSave:async args=>{
		savedBaseline=args.submittedProject;
		return {status:'committed',verified:true,sourceId:args.sourceId,
			generation:args.generation,revision:args.revision,baselineProject:savedBaseline};
	},onVerify:()=>{
		snapshotAtVerify=f.binding.current.session.baselineSnapshot();
		return true;
	}});
	f.binding.current.session.execute(increment());
	const result=await f.coordinator.transition({targetId:'target'});
	assert.equal(result.status,'committed');
	assert.equal(f.events.saves.length,1);
	assert.deepEqual(snapshotAtVerify,savedBaseline);
});

test('CX02D: Save receipt from another context generation cannot rebase History',async()=>{
	const f=fixture({decision:'save',onSave:async args=>({
		status:'committed',verified:true,sourceId:args.sourceId,
		generation:args.generation+1,revision:args.revision,
		baselineProject:args.submittedProject,
	})});
	f.binding.current.session.execute(increment());
	const result=await f.coordinator.transition({targetId:'target'});
	assert.equal(result.code,'DESIGNER_SAVE_NOT_VERIFIED');
	assert.equal(f.binding.current.status,'ACTIVE');
	assert.equal(f.binding.current.session.project().root.props.count,1);
});

test('CX02D: Cancel and rejected Save leave original dirty context editable',async()=>{
	const cancel=fixture({decision:'cancel'});
	cancel.binding.current.session.execute(increment());
	assert.equal((await cancel.coordinator.transition({targetId:'target'})).status,'cancelled');
	assert.equal(cancel.binding.current.status,'ACTIVE');
	assert.equal(cancel.binding.current.session.project().root.props.count,1);
	cancel.binding.current.session.execute(increment());
	const fail=fixture({decision:'save',onSave:async()=>({status:'unknown'})});
	fail.binding.current.session.execute(increment());
	const result=await fail.coordinator.transition({targetId:'target'});
	assert.equal(result.status,'refused');
	assert.equal(result.code,'DESIGNER_SAVE_NOT_VERIFIED');
	assert.equal(fail.binding.current.status,'ACTIVE');
	assert.equal(fail.binding.current.session.project().root.props.count,1);
});

test('CX02D: failed target load and rejected authorization never disturb original dirty context',async()=>{
	const f=fixture({onLoad:async()=>{throw new Error('TARGET_LOAD_FAILED');}});
	f.binding.current.session.execute(increment());
	const result=await f.coordinator.transition({targetId:'broken'});
	assert.equal(result.status,'refused');
	assert.equal(f.binding.current.session.project().root.props.count,1);
	assert.equal(f.binding.current.status,'ACTIVE');
	assert.ok(mounted(f,'source'));
	const g=fixture({onAuthorize:async()=>false});
	assert.equal((await g.coordinator.transition({targetId:'target'})).code,'DESIGNER_TRANSITION_UNAUTHORIZED');
});

test('CX02D: partial mount commit failure restores source and all five slots',async()=>{
	const f=fixture();
	f.binding.current.session.execute(increment());
	const bad=f.slots.inspector;
	const replace=bad.replaceChildren.bind(bad);
	let thrown=false;
	bad.replaceChildren=(...args)=>{
		if(!thrown){thrown=true;throw new Error('INJECTED_MOUNT_COMMIT_FAILURE');}
		replace(...args);
	};
	const r=await f.coordinator.transition({targetId:'target'});
	assert.equal(r.status,'refused');
	assert.equal(f.binding.current.status,'ACTIVE');
	assert.equal(f.binding.current.session.project().root.props.count,1);
	assert.ok(mounted(f,'source'));
	assert.equal(f.layers.at(-1),'source');
	f.binding.current.session.execute(increment());
});

test('CX02D: failed verification rolls back Shell, History, Selection and Inspector',async()=>{
	const f=fixture({onVerify:()=>false});
	const source=f.binding.current;
	const originalSession=source.session;
	originalSession.execute(increment());
	const r=await f.coordinator.transition({targetId:'target'});
	assert.equal(r.code,'DESIGNER_CONTEXT_VERIFICATION_FAILED');
	assert.equal(f.binding.current,source);
	assert.equal(f.binding.current.status,'ACTIVE');
	assert.ok(mounted(f,'source'));
	assert.equal(f.layers.at(-1),'source');
	assert.equal(f.root.undo.disabled,false);
	f.root.undo.fire('click');
	assert.equal(source.session.project().root.props.count,0);
});

test('CX02D: broken rollback locks original context and reports recovery required',async()=>{
	const f=fixture({onVerify:()=>false});
	const bad=f.slots.controls;
	const replace=bad.replaceChildren.bind(bad);
	bad.replaceChildren=(...nodes)=>{
		if(nodes[0]?.name==='source-controls') throw new Error('ROLLBACK_FAILED');
		replace(...nodes);
	};
	const result=await f.coordinator.transition({targetId:'target'});
	assert.equal(result.status,'recovery_required');
	assert.equal(f.coordinator.status().state,'RECOVERY_REQUIRED');
	assert.equal(f.binding.current.status,'LOCKED');
	denied(()=>f.binding.current.session.execute(increment()));
	assert.equal((await f.coordinator.transition({targetId:'other'})).status,'recovery_required');
});

test('CX02D: cleanup failure after irreversible retirement locks target and signals recovery',async()=>{
	const f=fixture({onCleanup:()=>{throw new Error('INJECTED_CLEANUP_FAILURE');}});
	const source=f.binding.current;
	const r=await f.coordinator.transition({targetId:'target'});
	assert.equal(r.status,'recovery_required');
	assert.equal(source.status,'RETIRED');
	assert.equal(f.binding.current.id,'target');
	assert.equal(f.binding.current.status,'LOCKED');
	denied(()=>f.binding.current.session.execute(increment()));
	assert.equal(f.coordinator.status().state,'RECOVERY_REQUIRED');
});

test('CX02D: stale document load after cancel cannot overwrite a later context',async()=>{
	const gate=deferred();
	const f=fixture({onLoad:args=>args.targetId==='stale'?gate.promise:{
		spec:spec(args.targetId),nodes:f.nodes(args.targetId),
	}});
	const first=f.coordinator.transition({targetId:'stale'});
	// Permit authorize/load to enter the asynchronous waiting phase.
	await Promise.resolve();await Promise.resolve();
	assert.equal(f.coordinator.cancelPending(),true);
	const second=await f.coordinator.transition({targetId:'fresh'});
	assert.equal(second.status,'committed');
	gate.resolve({spec:spec('stale'),nodes:f.nodes('stale')});
	assert.equal((await first).status,'cancelled');
	assert.equal(f.binding.current.id,'fresh');
	assert.ok(mounted(f,'fresh'));
	assert.equal(f.events.loads[0].isCurrent(),false);
});

test('CX02D: stale Save cannot rebase a later context after cancellation',async()=>{
	const gate=deferred();
	const f=fixture({decision:'save',onSave:()=>gate.promise});
	f.binding.current.session.execute(increment());
	const first=f.coordinator.transition({targetId:'stale'});
	for(let i=0;i<12&&!f.events.saves.length;i++)await Promise.resolve();
	assert.equal(f.events.saves.length,1);
	assert.equal(f.coordinator.cancelPending(),true);
	gate.resolve({status:'committed',verified:true,sourceId:f.events.saves[0].sourceId,
		generation:f.events.saves[0].generation,revision:f.events.saves[0].revision,
		baselineProject:f.events.saves[0].submittedProject});
	assert.equal((await first).status,'cancelled');
	assert.equal(f.binding.current.status,'ACTIVE');
	assert.equal(f.binding.current.session.project().root.props.count,1);
	assert.equal(f.events.saves[0].isCurrent(),false);
});

test('CX02D: overlapping and rapid successive switches enforce a single active generation',async()=>{
	const gate=deferred();
	const f=fixture({onLoad:args=>args.targetId==='waiting'?gate.promise:{
		spec:spec(args.targetId),nodes:f.nodes(args.targetId),
	}});
	const first=f.coordinator.transition({targetId:'waiting'});
	assert.equal((await f.coordinator.transition({targetId:'blocked'})).code,'DESIGNER_TRANSITION_BUSY');
	assert.equal(f.coordinator.cancelPending(),true);
	gate.resolve({spec:spec('waiting'),nodes:f.nodes('waiting')});
	assert.equal((await first).status,'cancelled');
	const a=await f.coordinator.transition({targetId:'second'});
	assert.equal(a.status,'committed');
	const b=await f.coordinator.transition({targetId:'third'});
	assert.equal(b.status,'committed');
	assert.ok(b.generation>a.generation);
	assert.ok(mounted(f,'third'));
	assert.equal(f.layers.at(-1),'third');
});
