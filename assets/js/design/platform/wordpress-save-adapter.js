import { attachTrustedDesignerHost, decorateDesignerControl } from '@cb-core/design-editor';
import { toast as baseToast } from '@cb-core/toast';
import * as mailProfile from '@cb-core/mail-platform';
import * as reportsProfile from '@cb-core/reports-platform';

const MODULES=new Map([
	['@cb-core/mail-platform',mailProfile],
	['@cb-core/reports-platform',reportsProfile],
]);
const issue=(code)=>Object.assign(new Error(code),{code});

// Native Designer uses the canonical Base Toast Foundation, never a second
// notification stack or an optimistic success inferred from HTTP 200.
const notifyDesignerSave = ({outcome, phase, reason = null}) => {
	if (phase !== 'save') return;
	const messages = config?.saveNotices || {};
	const label = (key, fallback) => typeof messages[key] === 'string'
		&& messages[key].trim() ? messages[key] : fallback;
	if (outcome === 'unknown') {
		// A bounded classification only; never log request bodies, document
		// data, tokens, operation IDs or raw backend messages.
		if ([
			'SAVE_TRANSPORT_UNVERIFIED', 'SAVE_SERVER_UNVERIFIED',
			'SAVE_RECEIPT_INVALID', 'SAVE_RECOVERY_UNVERIFIED',
			'SAVE_TX_STATE_UNVERIFIED', 'SAVE_TX_BEGIN_UNVERIFIED',
			'SAVE_TX_ROLLBACK_UNVERIFIED', 'SAVE_OPERATION_CLAIM_UNVERIFIED',
			'SAVE_TX_COMMIT_UNVERIFIED', 'SAVE_POSTCOMMIT_RECEIPT_UNVERIFIED',
			'SAVE_TX_EXCEPTION_UNVERIFIED', 'SAVE_RECOVERY_PREFLIGHT_UNVERIFIED',
			'SAVE_LEDGER_SCHEMA_UNVERIFIED', 'SAVE_LEDGER_READ_UNVERIFIED',
			'SAVE_LEDGER_STATE_UNVERIFIED', 'SAVE_LEDGER_RECEIPT_INVALID',
			'SAVE_DISPATCH_UNVERIFIED',
		].includes(reason)) {
			console.warn('[Core Blueprint Designer] Unverified Save phase:', reason);
		}
	}
	if (outcome === 'refused' && [
		'SAVE_PREFLIGHT_REFUSED', 'SAVE_DOCUMENT_LOAD_REFUSED',
		'SAVE_DOCUMENT_AUTHORIZATION_REFUSED', 'SAVE_DOCUMENT_CREATION_REFUSED',
		'SAVE_DOCUMENT_VALIDATION_REFUSED', 'SAVE_DOCUMENT_STORAGE_REFUSED',
		'SAVE_DOCUMENT_NORMALIZATION_REFUSED', 'SAVE_DOCUMENT_WRITE_REFUSED',
		'SAVE_DOCUMENT_VERIFY_REFUSED', 'SAVE_OPERATION_RECEIPT_REFUSED',
		'SAVE_REFUSED_UNCLASSIFIED',
	].includes(reason) || /^SAVE_STORAGE_(CREATE|UPDATE)_(INVALID|SCHEMA_UNAVAILABLE|QUERY_FAILED|READBACK_UNVERIFIED|READ_UNAVAILABLE|UNCLASSIFIED)$/.test(reason ?? '')) {
		console.warn('[Core Blueprint Designer] Save refused at:', reason);
	}
	switch (outcome) {
	case 'committed':
		baseToast.success(label('saved', 'Changes saved and verified.'));
		break;
	case 'conflict':
		baseToast.error(label('conflict',
			'This document changed elsewhere. Your changes were not confirmed as saved.'));
		break;
	case 'refused':
	case 'idempotency_reused':
		baseToast.error(label('refused',
			'Save was refused. Your changes have not been confirmed as saved.'));
		break;
	case 'persisted-unsynchronized':
		baseToast.error(label('unsynchronized',
			'Changes may be stored, but the editor could not reconcile them. Keep this Designer open.'),
			{persistent:true});
		break;
	default:
		// Do not leave an obsolete UNKNOWN toast after verified recovery;
		// canonical Save/Exit guards preserve the uncertain state meanwhile.
		baseToast.warning(label('unknown',
			'Save outcome is uncertain. Choose Verify save status to check the original operation. Keep this Designer open.'),
			{duration:12000});
		break;
	}
};
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
	if(!response.ok) {
		// The Base REST Save route conclusively refuses before entering the
		// transaction on these exact authenticated validation responses.
		// Do not translate that proven no-write into a misleading UNKNOWN.
		// Network errors, proxy errors, WordPress error envelopes and recovery
		// failures remain ambiguous and MUST NOT authorize a retry.
		if(action==='save' && [400,403,409].includes(response.status)
			&& body && typeof body==='object' && !Array.isArray(body)
			&& ['refused','conflict','idempotency_reused'].includes(body.status)) {
			return body;
		}
		throw issue('SAVE_TRANSPORT_UNVERIFIED');
	}
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
	// Fatal startup refusal remains visibly persistent, but temporary
	// feedback also uses the same Foundation as other Base Designers.
	baseToast.error(note.textContent);
};

async function activate() {
	const mail=document.querySelector('[data-cb-mail-designer]');
	const reports=!mail?document.querySelector('[data-cb-reports-designer]'):null;
	const origin=mail||reports;
	// A regular admin page is never an implicit editor launch target.
	if(!origin||!origin.closest('[data-cb-platform-screen]'))return;
	const screen=origin.closest('[data-cb-platform-screen]');
	const profileId=mail?'base/mail':'base/reports';
	const selected=mail?screen.querySelector('[data-cb-mail-template-select]'):null;
	const resourceKey=mail?(selected?.value||mail.dataset.cbMailTemplate||''):'maintenance';
	// Preserve the PHP-rendered status element until Host READY or refusal.
	const loadingContext=origin.querySelector('[data-cb-platform-base-context]') ||
		(origin.matches('[data-cb-platform-base-context]') ? origin : null);
	const startupHeader=screen.querySelector('.cb-core-platform-screen__header');
	const failStartup=(code)=>{
		// The old PHP header is never presented during normal startup.
		// On an actual failure, reveal its Close link so users can safely exit.
		screen.dataset.cbPlatformFailed='true';
		screen.setAttribute('aria-busy','false');
		if(loadingContext)loadingContext.hidden=true;
		if(startupHeader)startupHeader.hidden=false;
		showRefusal(panel,code);
	};
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
		// Keep the server-rendered loading status visible while the Host
		// fetches and verifies its context; overlay it rather than blanking
		// Reports before the canonical Designer is ready.
		origin.parentNode.insertBefore(panel,origin.nextSibling);
	}
	const root=document.createElement('section');
	root.className='cb-core-design-shell cb-core-form-scope';
	root.setAttribute('data-cb-design-shell','');
	const canvas=document.createElement('div');
	canvas.setAttribute('data-cb-platform-mount','');
	root.append(canvas);
	panel.append(root);
	if(!resourceKey){failStartup('HOST_CONTEXT_REFUSED');return;}
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
			notifySave:notifyDesignerSave,
		});
		await host.mount({bootstrap:loaded.bootstrap,project:loaded.project,persistedFields:loaded.fields});
		// Host-READY is the only point where the native toolbar may replace the
		// server-rendered loading header. Move trusted admin controls, not a
		// second toolbar or document-derived markup, into the committed shell.
		const toolbar=canvas.querySelector('.cb-core-design-shell__toolbar--designer');
		const contextSlot=toolbar?.querySelector('[data-cb-platform-context-slot]');
		const closeSlot=toolbar?.querySelector('[data-cb-platform-close-slot]');
		const close=screen.querySelector('[data-cb-platform-header-close]');
		const contextControl=selected||screen.querySelector('[data-cb-platform-header-context]');
		if(!contextSlot||!closeSlot||!close||!contextControl)throw issue('HOST_CAPABILITY_UNAVAILABLE');
		contextSlot.append(contextControl);
		// Keep the same server-provided close URL and beforeunload guard;
		// replace only its visible text with the shared Base close glyph.
		decorateDesignerControl(close, 'x', {iconOnly: true, label: 'Close Designer'});
		closeSlot.append(close);
		if(loadingContext)loadingContext.hidden=true;
		if(reports){origin.hidden=true;origin.setAttribute('inert','');}
		screen.dataset.cbPlatformMounted='true';
		screen.setAttribute('aria-busy','false');
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
		failStartup(String(error?.code||error?.message||'HOST_CONTEXT_REFUSED'));
	}
}

if(typeof document!=='undefined'){
	if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{void activate();},{once:true});
	else void activate();
}
