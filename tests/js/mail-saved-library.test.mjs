import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {resolve, dirname} from 'node:path';
import {runInNewContext} from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const source = readFileSync(resolve(root, 'assets/js/design/mail/saved-library.js'), 'utf8');
const platform = readFileSync(resolve(root, 'assets/js/design/platform/wordpress-save-adapter.js'), 'utf8');
const bridge = readFileSync(resolve(root, 'assets/js/design/mail/saved-controls.js'), 'utf8');
const mailAssets = readFileSync(resolve(root, 'src/Mail/Admin/DesignerAssets.php'), 'utf8');
const backend = readFileSync(resolve(root, 'src/Mail/Designer/SavedTemplateController.php'), 'utf8');
const phpSource = readFileSync(resolve(root, 'src/Mail/Designer/SavedTemplateSource.php'), 'utf8');
const bootstrap = readFileSync(resolve(root, 'src/Mail/Bootstrap.php'), 'utf8');

// Execute the real UI contract without WordPress modules or a second Host.
function harness({configOrigin = 'https://example.test', saved = {}} = {}) {
	const events = new Map();
	const buttons = [];
	const browser = {calls: 0, selected: null};
	const notices = [];
	const http = [];
	class Node {
		constructor(tag) {
			this.tagName = tag;
			this.children = [];
			this.value = '';
			this.dataset = {};
			this.isConnected = true;
		}
		append(...children) { this.children.push(...children); }
		replaceChildren(...children) { this.children = [...children]; }
		setAttribute() {}
		addEventListener(type, callback) { this.listeners = this.listeners || {}; this.listeners[type] = callback; }
		remove() { this.isConnected = false; }
	}
	const config = {nonce:'test-nonce', restRoot: configOrigin + '/wp-json/core-blueprint/v1/mail-templates/'};
	const sandbox = {
		window:{cbCoreMailSaved:config},
		document:{readyState:'loading',addEventListener(){},createElement(tag){
			const el = new Node(tag);
			if(tag === 'button') buttons.push(el);
			return el;
		}},
		location:{origin:'https://example.test',href:'https://example.test/wp-admin/'},
		URL, JSON, Object, Number,
		fetch:async (url, options) => {
			http.push({url, options});
			return {ok:true,json:async()=>saved.response || {status:'created',id:'mail.saved.12345678901234567890123456789012'}};
		},
		openTemplateBrowser:async (options) => {
			browser.calls++;
			browser.selected=options;
			return true;
		},
		loadForApply:async () => saved.reply,
		showModal:async (options) => {
			if (saved.modalHook) return saved.modalHook(options);
			return false;
		},
		toast:{success(x){notices.push(['success',x]);},error(x){notices.push(['error',x]);}},
	};
	const transformed = source
		.replace(/^import\s+\{[^}]+\}\s+from\s+['"][^'"]+['"];\s*$/gm,'')
		.replace(/^export function bindMailTemplates/m, 'function bindMailTemplates')
		+ '\nglobalThis.__test = {api, authorizedUrl, bindMailTemplates, canCapture, targetOf};';
	runInNewContext(transformed, sandbox, {filename:'saved-library.js'});
	const browserTrigger = sandbox.document.createElement('button');
	return {api:sandbox.__test, sandbox, events, browser, notices, http, buttons, browserTrigger};
}

test('T3-02 uses a separate Mail-owned source, not operational Mail resource IDs', () => {
	assert.match(phpSource, /SOURCE_ID = 'core-blueprint-mail-saved'/);
	assert.match(phpSource, /TemplateRegistry::get\( \$resource_key \)/);
	assert.match(phpSource, /SavedTemplateStore::find\( \$template_id, \$actor_id \)/);
	assert.match(bootstrap, /SavedTemplateSchema::class, 'register_schema'/);
	assert.match(bootstrap, /new SavedTemplateSource\(\)/);
	assert.match(backend, /DesignerScope::verify\( \$token, \$data\['resourceType'\], \$data\['resourceKey'\] \)/);
});

test('T3-02 refuses a cross-origin Mail library request before network', async () => {
	const h = harness({configOrigin:'https://attacker.test'});
	await assert.rejects(h.api.api('create', {title:'No',visibility:'site'}), /MAIL_SAVED_AUTHORITY_UNAVAILABLE/);
	assert.equal(h.http.length, 0);
});

test('T3-02 sends nonce, exact owner route and scope with same-origin credentials', async () => {
	const h = harness();
	const response = await h.api.api('create', {title:'Reusable'}, {scope:'signed-token'});
	assert.equal(response.status, 'created');
	assert.equal(h.http.length, 1);
	assert.match(h.http[0].url, /\/core-blueprint\/v1\/mail-templates\/create$/);
	assert.equal(h.http[0].options.credentials,'same-origin');
	assert.equal(h.http[0].options.method,'POST');
	assert.equal(h.http[0].options.headers['X-WP-Nonce'],'test-nonce');
	assert.equal(h.http[0].options.headers['X-CB-Designer-Scope'],'signed-token');
});

test('T3-02 treats contradictory HTTP failures and positive payloads as unverified', async () => {
	const h = harness();
	h.sandbox.fetch=async () => ({ok:false,json:async()=>({status:'created',id:'mail.saved.fake'})});
	await assert.rejects(h.api.api('create',{title:'Unsafe'}), /MAIL_SAVED_UNVERIFIED/);
});

test('T3-02 blocks Apply and Save as when canonical Save is UNKNOWN', () => {
	const h = harness();
	const editor = {
		saveStatus:()=>({state:'UNKNOWN',blocked:true}),
		snapshot:()=>({}),persistedFields:()=>({}),
	};
	const host = {state:'READY',profileId:'base/mail',context:{contextEpoch:0},editor};
	assert.equal(h.api.canCapture(host),false);
	const slot = h.sandbox.document.createElement('span');
	h.api.bindMailTemplates({trigger:h.browserTrigger,host,root:{isConnected:true},contextSlot:slot,scopeToken:'signed-token'});
	assert.equal(h.browserTrigger.disabled,false);
	h.browserTrigger.listeners.click();
	assert.equal(h.browser.calls,0);
	assert.equal(h.notices[0][0],'error');
});

test('T3-02 native Use passes the authorized response only to Host facade', async () => {
	const reply={status:'ready',templateId:'wordpress.new-user'};
	const h=harness({saved:{reply}});
	let captured=0, applied=0;
	const editor={
		saveStatus:()=>({state:'READY_DIRTY',blocked:false}),
		captureApplyIntent:()=>{captured++;return Object.freeze({witness:true});},
		applyTemplate:(actual,intent)=>{assert.equal(actual,reply);assert.equal(intent.witness,true);applied++;return true;},
	};
	const host={
		state:'READY',profileId:'base/mail',context:{
			resourceType:'base.mail-template',resourceId:'wordpress.new-user',contextEpoch:0,
		},editor,
	};
	const slot=h.sandbox.document.createElement('span');
	h.api.bindMailTemplates({trigger:h.browserTrigger,host,root:{isConnected:true},contextSlot:slot,scopeToken:'signed-token'});
	h.browserTrigger.listeners.click();
	// The browser selector retains the real asynchronous Choose callback.
	await Promise.resolve();
	assert.equal(h.browser.calls,1);
	const accepted=await h.browser.selected.onChoose({source:'core-blueprint-mail',id:'wordpress.new-user'});
	assert.equal(accepted,true);
	assert.equal(captured,1);
	assert.equal(applied,1);
	assert.equal(h.notices.at(-1)[0],'success');
});

test('T3-02 Save as captures project+fields only and never triggers live document Save', async () => {
	const project = {schema_version:1, root:{type:'mail.root',children:[]}};
	const fields = {subject:'Hello',preheader:'Preview'};
	const saved = {
		modalHook:async ({body,onConfirm}) => {
			body.children[0].children[0].value = 'Reusable copy';
			body.children[1].children[0].value = 'personal';
			return onConfirm();
		},
	};
	const h = harness({saved});
	let liveSaved = 0;
	const editor = {
		saveStatus:()=>({state:'READY_DIRTY',blocked:false}),
		snapshot:()=>project, persistedFields:()=>fields,
		save:()=>{liveSaved++;throw new Error('LIVE_SAVE_MUST_NOT_RUN');},
	};
	const host = {
		state:'READY',profileId:'base/mail',
		context:{resourceType:'base.mail-template',resourceId:'wordpress.new-user',contextEpoch:0},
		editor,
	};
	h.api.bindMailTemplates({trigger:h.browserTrigger,
		host,root:{isConnected:true},
		contextSlot:h.sandbox.document.createElement('span'),
		scopeToken:'signed-token',
	});
	h.browserTrigger.listeners.click();
	await Promise.resolve();
	await h.browser.selected.onSaveAs();
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(h.http.length,1);
	const sent=JSON.parse(h.http[0].options.body);
	assert.equal(sent.title,'Reusable copy');
	assert.equal(sent.visibility,'personal');
	assert.equal(sent.profileId,'base/mail');
	assert.equal(sent.resourceKey,'wordpress.new-user');
	assert.deepEqual(sent.document,{project,fields});
	assert.equal(liveSaved,0);
	assert.equal(h.notices.at(-1)[0],'success');
});

test('T3-02 ambiguous Save as response blocks retry instead of creating another asset', async () => {
	const saved = {
		response:{status:'unknown'},
		modalHook:async ({body,onConfirm}) => {
			body.children[0].children[0].value = 'Uncertain copy';
			body.children[1].children[0].value = 'site';
			return onConfirm();
		},
	};
	const h=harness({saved});
	const editor={
		saveStatus:()=>({state:'READY_CLEAN',blocked:false}),
		snapshot:()=>({root:{}}),persistedFields:()=>({subject:'Hello'}),
	};
	const host={
		state:'READY',profileId:'base/mail',
		context:{resourceType:'base.mail-template',resourceId:'wordpress.new-user',contextEpoch:0},
		editor,
	};
	h.api.bindMailTemplates({trigger:h.browserTrigger,
		host,root:{isConnected:true},
		contextSlot:h.sandbox.document.createElement('span'),
		scopeToken:'signed-token',
	});
	h.browserTrigger.listeners.click();
	await Promise.resolve();
	await h.browser.selected.onSaveAs();
	await new Promise(resolve=>setImmediate(resolve));
	assert.equal(h.http.length,1);
	await h.browser.selected.onSaveAs();
	assert.equal(h.http.length,1);
	assert.equal(h.notices.at(-1)[0],'error');
});

test('Browser is the single Mail toolbar action and receives authorized active design choices', async () => {
	const h=harness();
	const editor={saveStatus:()=>({state:'READY_CLEAN',blocked:false})};
	const host={state:'READY',profileId:'base/mail',
		context:{resourceType:'base.mail-template',resourceId:'wordpress.a',contextEpoch:0},editor};
	let opened=null;
	h.api.bindMailTemplates({trigger:h.browserTrigger,host,root:{isConnected:true},
		contextSlot:h.sandbox.document.createElement('span'),scopeToken:'signed-token',
		designs:[{id:'wordpress.a',title:'Current Mail',provider:'WordPress'}],
		activeDesignId:'wordpress.a',onOpenDesign:async design=>{opened=design;return true;}});
	assert.equal(h.buttons.length,1,'Mail adds no second action control or selector');
	h.browserTrigger.listeners.click();
	await Promise.resolve();
	assert.equal(h.browser.calls,1);
	assert.equal(h.browser.selected.designs[0].id,'wordpress.a');
	assert.equal(h.browser.selected.activeDesignId,'wordpress.a');
	assert.equal(await h.browser.selected.onOpenDesign({id:'wordpress.a'}),true);
	assert.deepEqual(opened,{id:'wordpress.a'});
	assert.equal(h.browserTrigger.disabled,false);
});

test('T3-02 Mail-only static bootstrap binds both early and late to a READY native Host', () => {
	assert.doesNotMatch(platform, /\bimport\s*\(/);
	assert.doesNotMatch(bridge, /\bimport\s*\(/);
	assert.match(bridge, /import \{registerNativeMailTemplateActions\} from '@cb-core\/base-platform-v1'/);
	assert.match(bridge, /import \{bindMailTemplates\} from '@cb-core\/mail-saved-library'/);
	assert.match(bridge, /registerNativeMailTemplateActions\(bindMailTemplates\)/);
	assert.match(platform, /function installMailTemplateActions\(context\)/);
	assert.match(platform, /pendingMailTemplateContext=context/);
	assert.match(platform, /export function registerNativeMailTemplateActions\(binder\)/);
	assert.match(platform, /if\(pendingMailTemplateContext\)/);
	assert.match(platform, /if\(mail\) \{/);
	assert.match(platform, /installMailTemplateActions\(\{/);
	assert.match(mailAssets, /if \( \$fullscreen \) \{/);
	assert.match(mailAssets, /'@cb-core\/mail-saved-controls'/);
	assert.match(mailAssets, /\[ '@cb-core\/base-platform-v1', '@cb-core\/mail-saved-library' \]/);
	assert.doesNotMatch(source, /createSaveCoordinator|new CommandHistory|TemplateRepository::save/);
});

test('T3-02 READY Host binding succeeds before and after Mail bootstrap registration', () => {
	const begin = platform.indexOf('const issue=(code)=>Object.assign(new Error(code),{code});');
	const finish = platform.indexOf('// Native Designer uses the canonical Base Toast Foundation', begin);
	assert.ok(begin >= 0 && finish > begin, 'real private registration source is present');
	const actualImplementation = platform.slice(begin, finish).replace(/^export /gm, '');
	const exercise = (early) => {
		const calls = [];
		const events = [];
		const notifications = [];
		const sandbox = {
			baseToast: {warning: text => notifications.push(text)},
			window: {addEventListener: (...args) => events.push(args)},
		};
		runInNewContext(actualImplementation +
			'\nglobalThis.mailReadyBridge = {installMailTemplateActions,registerNativeMailTemplateActions};', sandbox);
		const api = sandbox.mailReadyBridge;
		const ctx = {host:{state:'READY'},root:{isConnected:true},contextSlot:{},scopeToken:'signed'};
		const bind = item => { calls.push(item); return () => {}; };
		if (early) {
			assert.equal(api.registerNativeMailTemplateActions(bind), true);
			api.installMailTemplateActions(ctx);
		} else {
			api.installMailTemplateActions(ctx);
			assert.equal(api.registerNativeMailTemplateActions(bind), true);
		}
		assert.equal(calls.length, 1, 'exactly one Mail binder call');
		assert.equal(calls[0], ctx, 'private Host context handed to Mail-only binder');
		assert.equal(events.length, 1, 'pagehide cleanup installed');
		assert.equal(events[0][0], 'pagehide');
		assert.equal(api.registerNativeMailTemplateActions(bind), false, 'duplicate binder refused');
		api.installMailTemplateActions({host:{state:'NOT_READY'},root:{isConnected:true}});
		assert.equal(calls.length, 1, 'not-READY Host never binds');
		assert.deepEqual(notifications, [], 'no unexpected fallback warnings');
	};
	exercise(true);
	exercise(false);
});

test('T3-02 release-safe Designer module graph excludes every dynamic import in the new bridge', () => {
	for(const code of [platform, bridge, source]){
		assert.doesNotMatch(code, /\bimport\s*\(/);
	}
});
