import { attachTrustedDesignerHost } from '@cb-core/design-editor';
import * as mailProfile from '@cb-core/mail-platform';
import * as reportsProfile from '@cb-core/reports-platform';

const MODULES=new Map([
	['@cb-core/mail-platform',mailProfile],
	['@cb-core/reports-platform',reportsProfile],
]);
const issue=(code)=>Object.assign(new Error(code),{code});
const config=typeof window!=='undefined'?window.cbCorePlatformV1:null;
const validConfig=()=>{
	if(!config||typeof config!=='object'||typeof config.restRoot!=='string'
		||typeof config.nonce!=='string'||!config.nonce||!config.restRoot.startsWith(location.origin+'/'))
		throw issue('HOST_CONTEXT_REFUSED');
};
async function request(action,method,payload=null,signal=null) {
	validConfig();
	const base=config.restRoot.replace(/\/$/,'')+'/core-blueprint/v1/designer-platform/'+action;
	const u=new URL(base,location.href);
	if(u.origin!==location.origin)throw issue('HOST_CONTEXT_REFUSED');
	if(method==='GET'){
		for(const [key,value] of Object.entries(payload||{}))u.searchParams.set(key,String(value));
	}
	const options={method,credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce,'Accept':'application/json'}};
	if(method==='POST'){
		options.headers['Content-Type']='application/json';
		options.body=JSON.stringify(payload);
	}
	if(signal)options.signal=signal;
	const response=await fetch(u.href,options);
	const body=await response.json();
	if(!response.ok)throw issue(String(body?.code||'HOST_CONTEXT_REFUSED'));
	return body;
}

/**
 * Context-bound transport, never a second Save Authority. Receipts must be
 * returned by the authorized WordPress operation/recovery endpoint; there
 * are no client-generated "committed" outcomes or retries after UNKNOWN.
 */
export function createWordPressSaveAdapter({identity,profile,evidence,loadReceipt,signal}) {
	if(signal?.aborted||loadReceipt?.status!=='loaded'
		||loadReceipt?.bootstrap?.evidence?.verificationStatus!=='verified'
		||loadReceipt.bootstrap.evidence?.buildId!==evidence?.buildId
		||loadReceipt.bootstrap.evidence?.moduleGraphFingerprint!==evidence?.moduleGraphFingerprint
		||loadReceipt.profileId!==profile.id
		||loadReceipt.resourceKey!==identity.resourceId)throw issue('SAVE_NOT_READY');
	let destroyed=false;
	const originallySubmitted=new Map();
	const authenticatedReceipts=new WeakSet();
	const active=()=>!destroyed&&!signal.aborted;
	const serialize=checkpoint=>{
		if(!active()||!checkpoint||checkpoint.context?.contextEpoch!==identity.contextEpoch)
			throw issue('SAVE_CONTEXT_CHANGED');
		return Object.freeze({
			contractVersion:1,profileId:profile.id,projectSchema:profile.projectSchema,
			registryFingerprint:loadReceipt.registryFingerprint,
			operationId:checkpoint.operationId,
			expectedStoreRevision:checkpoint.expectedStoreRevision,
			resourceType:identity.resourceType,resourceKey:identity.resourceId,
			blogId:String(identity.siteId),actorId:String(identity.actorId),
			project:checkpoint.project,fields:checkpoint.fields,
		});
	};
	const authenticate=(result)=>{
		if(result?.status==='committed'&&result.receipt&&typeof result.receipt==='object')
			authenticatedReceipts.add(result.receipt);
		return result;
	};
	return Object.freeze({
		serialize,
		canSave:()=>active()&&loadReceipt?.storeRevision!==undefined,
		async submit(payload,{expectedStoreRevision,idempotencyKey}={}){
			if(!active()||payload.expectedStoreRevision!==expectedStoreRevision
				||payload.operationId!==idempotencyKey
				||originallySubmitted.has(idempotencyKey))throw issue('SAVE_INVALID');
			originallySubmitted.set(idempotencyKey,payload);
			// Never interpret a thrown fetch or missing reply as no-write.
			return authenticate(await request('save','POST',payload));
		},
		async lookupOperation(evidence){
			if(!active())throw issue('SAVE_CONTEXT_CHANGED');
			const original=originallySubmitted.get(evidence.operationId);
			if(!original||original.expectedStoreRevision!==evidence.expectedStoreRevision)
				throw issue('SAVE_UNKNOWN');
			// Original request values enable server-side receipt correlation.
			// RECOVER does not retry Save and never creates a new operation ID.
			return authenticate(await request('recover','POST',original));
		},
		verifyReceipt(receipt,evidence){
			return active()&&!!receipt&&authenticatedReceipts.has(receipt)
				&&receipt.operationId===evidence.operationId
				&&receipt.registryFingerprint===evidence.registryFingerprint
				&&receipt.profileId===profile.id&&receipt.status==='committed';
		},
		destroy(){destroyed=true;originallySubmitted.clear();},
	});
}

/** Load an authorized fresh canonical v1 document. Never decode legacy v0. */
export async function loadWordPressContext(profileId,resourceKey,contextEpoch){
	if(!['base/mail','base/reports'].includes(profileId)
		||typeof resourceKey!=='string'||!resourceKey
		||!Number.isSafeInteger(contextEpoch)||contextEpoch<0)throw issue('HOST_CONTEXT_REFUSED');
	const loaded=await request('context','GET',{profileId,resourceKey,contextEpoch});
	if(loaded?.status!=='loaded'||loaded.profileId!==profileId
		||loaded.resourceKey!==resourceKey||loaded.contextEpoch!==contextEpoch
		||typeof loaded.storeRevision!=='string'
		||!loaded.bootstrap||!loaded.project||!loaded.fields)throw issue('HOST_CONTEXT_REFUSED');
	return loaded;
}

const showRefusal=(container,code)=>{
	const note=document.createElement('p');
	note.className='notice notice-error';
	note.setAttribute('role','alert');
	const category=String(code).toUpperCase();
	note.textContent=category==='SCHEMA_UNSUPPORTED_GENERATION'
		?'This document uses an unsupported development schema. No changes were made.'
		:category==='SAVE_AUTHORITY_UNAVAILABLE'
		?'The Designer document storage is unavailable. No changes were made.'
		:category==='DESIGNER_ASSETS_UNAVAILABLE'
		?'The Designer assets could not be loaded. No changes were made.'
		:category==='DESIGNER_RUNTIME_UNAVAILABLE'
		?'The Designer is temporarily unavailable. No changes were made.'
		:'The Designer could not verify your permissions or document. Nothing was changed.';
	container.replaceChildren(note);
};

async function activate() {
	const mail=document.querySelector('[data-cb-mail-designer]');
	const reports=!mail?document.querySelector('[data-cb-reports-designer]'):null;
	const origin=mail||reports;
	if(!origin)return;
	const profileId=mail?'base/mail':'base/reports';
	const selected=mail?mail.querySelector('[data-cb-mail-template-select]'):null;
	const resourceKey=mail?(selected?.value||''):'maintenance';
	const previous=origin.querySelector('[data-cb-design-shell]');
	if(previous)previous.hidden=true;
	const panel=document.createElement('div');
	panel.className='cb-core-designer-platform-v1';
	panel.setAttribute('data-cb-designer-platform-v1','');
	// One live root, one Host; all old PHP forms are presentation-only and
	// must never submit or mount their own History/Save engine.
	if(mail){
		const form=origin.querySelector('[data-cb-mail-designer-form]');
		if(form){form.hidden=true;form.setAttribute('inert','');}
		origin.append(panel);
	}else{
		origin.hidden=true;
		origin.setAttribute('inert','');
		origin.parentNode.insertBefore(panel,origin.nextSibling);
	}
	const root=document.createElement('section');
	root.className='cb-core-design-shell cb-core-form-scope';
	root.setAttribute('data-cb-design-shell','');
	const canvas=document.createElement('div');
	canvas.setAttribute('data-cb-platform-mount','');
	root.append(canvas);
	panel.append(root);
	if(!resourceKey){showRefusal(panel,'HOST_CONTEXT_REFUSED');return;}
	try {
		const loaded=await loadWordPressContext(profileId,resourceKey,0);
		const host=attachTrustedDesignerHost({
			root,canvasRoot:canvas,bootstrap:loaded.bootstrap,runtimeModules:MODULES,
			loadReceipt:async ({identity,profile,evidence,signal})=>{
				if(signal?.aborted||identity.resourceId!==loaded.resourceKey
					||profile.id!==loaded.profileId
					||evidence?.buildId!==loaded.bootstrap.evidence?.buildId)
					throw issue('SAVE_NOT_READY');
				return loaded;
			},
			createSaveAdapter:createWordPressSaveAdapter,
		});
		await host.mount({bootstrap:loaded.bootstrap,project:loaded.project,persistedFields:loaded.fields});
		const transitionSafe=()=>{
			if(host.state!=='READY')return false;
			const status=host.editor?.saveStatus?.();
			return status?.dirty===false&&status?.blocked===false
				&&status?.state==='READY_CLEAN';
		};
		window.addEventListener('beforeunload',(event)=>{
			if(!transitionSafe()){
				event.preventDefault();
				event.returnValue='';
			}
		});
		if(selected){
			selected.addEventListener('change',()=>{
				if(!transitionSafe()){
					selected.value=resourceKey;
					return;
				}
				const url=new URL(location.href);
				url.searchParams.set('template',selected.value);
				location.assign(url.href);
			});
		}
	} catch(error) {
		showRefusal(panel,String(error?.code||error?.message||'HOST_CONTEXT_REFUSED'));
	}
}

if(typeof document!=='undefined'){
	if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{void activate();},{once:true});
	else void activate();
}
