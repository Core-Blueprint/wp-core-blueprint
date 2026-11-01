import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import {runInNewContext} from 'node:vm';

const source=(path)=>readFileSync(new URL('../../'+path,import.meta.url),'utf8');

const runGuard=()=>{
	const listeners=new Map();
	class Element {
		constructor(){this.dataset={};this.isConnected=true;this.inert=false;}
		hasAttribute(){return false;}
	}
	const window={
		addEventListener:(key,listener)=>listeners.set(key,listener),
		cbCore:{toast:{error(){},warning(){}}},
	};
	const document={querySelector(){return null;},currentScript:null};
	runInNewContext(source('assets/js/features/designer-exit-guard.js'),{
		window,document,Element,URL,
	});
	return {api:window.cbCore.designerExitGuard,Element,listeners};
};

test('CX02E public API v4 keeps fullscreen request and denies context without a managed registration',async()=>{
	const {api,Element}=runGuard();
	assert.equal(api.contractVersion,4);
	assert.equal(typeof api.request,'function');
	assert.equal(typeof api.register,'function');
	assert.equal(typeof api.transition,'function');
	assert.equal(Object.hasOwn(api,'requestTransition'),false);
	assert.equal(Object.hasOwn(api,'isTransitionDispatchAuthorized'),false);
	const root=new Element();
	const release=api.register(root,{isDirty:()=>false,discard:()=>true});
	assert.equal(await release.ready,true);
	assert.equal(release.current(),null);
	assert.equal((await api.transition(root,{targetId:'another'})).status,'blocked');
	assert.equal(release(),true);
});

test('CX02E refuses malformed managed declaration and never grants a fallback',async()=>{
	const {api,Element}=runGuard();
	const root=new Element();
	assert.throws(()=>api.register(root,{
		isDirty:()=>false,discard:()=>true,
		context:{initial:{id:'one',kind:'domain',sessionOptions:{}},prepare:async()=>({})},
	}),/mountSlot/);
	assert.equal((await api.transition(root,{targetId:'two'})).status,'blocked');
});

test('CX02E unavailable managed authority blocks fullscreen, unload and teardown',async()=>{
	const {api,Element,listeners}=runGuard();
	const root=new Element();
	const release=api.register(root,{
		isDirty:()=>false,discard:()=>true,
		context:{
			initial:{id:'first',kind:'domain',sessionOptions:{project:{}}},
			mountSlot:{canvas:{},layers:{},inspector:{},controls:{},preview:{}},
			prepare:async()=>({}),
		},
	});
	assert.equal(await release.ready,false,'missing Base ESM must fail closed');
	assert.equal(api.canTransition(root),false);
	assert.equal((await api.transition(root,{targetId:'next'})).status,'blocked');
	assert.equal(await api.request(root,()=>true),false);
	let prevented=false;
	listeners.get('beforeunload')({
		preventDefault(){prevented=true;},returnValue:undefined,
	});
	assert.equal(prevented,true,'an unverified managed context cannot unload silently');
	assert.equal(await release(),false,'uncertain managed authority cannot be revoked');
});

test('CX02E private ESM and PHP capabilities are aligned with the v4-only public boundary',()=>{
	const php=source('src/Design/Editor/Assets.php');
	const editor=source('assets/js/design/editor.js');
	const managed=source('assets/js/design/core/context-managed.js');
	const adapter=source('assets/js/design/core/context-v4.js');
	const picker=source('assets/js/features/designer-context.js');
	const launch=source('assets/js/features/designer-launch.js');
	assert.match(php,/public const DESIGNER_EXIT_GUARD_API = 4;/);
	assert.match(adapter,/createManagedContextCoordinator/);
	assert.match(adapter,/createManagedContextBinding/);
	assert.match(adapter,/context\.prepare/);
	assert.match(adapter,/unchanged\(before,slots\)/);
	assert.match(managed,/revokeManagedContextBinding/);
	assert.match(picker,/guard\.transition\(shell, \{targetId: value\}\)/);
	assert.doesNotMatch(picker+launch,/cb:design-shell:contextrequest|respondWith|requestTransition/);
	for(const name of ['createManagedContextBinding','createManagedContextCoordinator',
		'createManagedV4Context','revokeManagedContextBinding']) {
		assert.doesNotMatch(editor,new RegExp('export const '+name));
	}
});
