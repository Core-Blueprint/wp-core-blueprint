import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const read = path => readFileSync(resolve(root, path), 'utf8');
const editor = read('assets/js/design/editor.js');
const platform = read('assets/js/design/platform/wordpress-save-adapter.js');
const toolbar = read('assets/js/features/designer-toolbar.js');
const browser = read('assets/js/core/template-browser.js');
const mail = read('assets/js/design/mail/saved-library.js');
const mailAssets = read('src/Mail/Admin/DesignerAssets.php');
const css = read('assets/css/components/template-library.css');

test('canonical toolbar zones preserve all controls with Close at the right edge', () => {
	assert.match(editor, /startZone\.append\(brand\)/);
	assert.match(editor, /centerZone\.append\(viewport\)/);
	assert.match(editor, /centerZone\.append\(documentGroup\)/);
	assert.match(editor, /endZone\.append\(resourceGroup, operationGroup\)/);
	assert.match(editor, /operationGroup\.append\(history, save, closeSlot\)/);
	assert.match(editor, /resourceGroup\.append\(browserButton\)/);
	assert.match(editor, /decorateDesignerControl\(browserButton, 'folder-open'/);
	assert.match(toolbar, /const actionMount = toolbar\.querySelector\('\[data-cb-design-shell-actions-group\]'\) \|\| end/);
	assert.match(platform, /const unregisterExit=exitGuard\.register\(root/);
	assert.match(platform, /const openDesign = async item =>/);
	assert.match(platform, /return await guard\.request\(root,/);
	assert.match(platform, /selected\.hidden=true/);
	assert.doesNotMatch(platform, /picker\.enhance\(selected/);
});

test('classic extension Designer shells use the same end-zone grammar without changing context authority', () => {
	const launch = read('assets/js/features/designer-launch.js');
	const shared = read('assets/css/design/designer-toolbar.css');
	assert.match(launch, /start\.append\(brand\)/);
	assert.match(launch, /if \(viewportGroup\) center\.append\(viewportGroup\)/);
	assert.match(launch, /if \(contextSwitcher\) resourceGroup\.append\(contextSwitcher\)/);
	assert.match(launch, /if \(save\) operationGroup\.append\(save\)/);
	assert.match(launch, /operationGroup\.append\(close\)/);
	assert.match(launch, /operationGroup\.append\(fullscreen\)/);
	assert.match(launch, /end\.append\(resourceGroup, operationGroup\)/);
	assert.doesNotMatch(launch, /start\.append\(contextSwitcher\)/);
	assert.match(toolbar, /const context = toolbar\.querySelector\('\[data-cb-design-shell-context\]'\)/);
	assert.match(shared, /\.cb-core-design-shell__toolbar-group--resource/);
	assert.match(shared, /\.cb-core-design-shell__toolbar-group--operations/);
});

test('Mail Browser uses the canonical workspace and rejects competing Save engines', () => {
	assert.match(mail, /onSaveAs: launchSaveAs/);
	assert.match(mail, /onOpenDesign,/);
	assert.match(mail, /onChoose: async \(item\)/);
	assert.match(mail, /host\.editor\.captureApplyIntent\(\)/);
	assert.match(mail, /host\.editor\.applyTemplate\(reply, intent\)/);
	assert.doesNotMatch(mail, /createSaveCoordinator|new CommandHistory|TemplateRepository::save/);
	assert.doesNotMatch(mail, /cb-core-mail-saved-menu/);
	assert.match(browser, /size: 'fullscreen'/);
	assert.doesNotMatch(browser, /expandable: true/);
	assert.match(browser, /const accepted = await showModal/);
	assert.match(browser, /if \( accepted && pendingDesign && typeof onOpenDesign === 'function' \)/);
	assert.match(css, /\.cb-template-browser--designer-workspace/);
	assert.match(css, /\.cb-template-browser__collections/);
});

test('landing saved manager enqueues all static imports and never leaves an unowned loading fallback', () => {
	assert.match(mailAssets, /UiAssets::enqueue_toasts\( UiAssets::TOAST_PRESENTATION_CORE \)/);
	assert.match(mailAssets, /TemplateLibraryAssets::enqueue\(\)/);
	assert.match(mailAssets, /'@cb-core\/mail-saved-library'/);
	assert.match(mail, /status\.textContent = label\('loading', 'Loading saved templates…'\)/);
	assert.doesNotMatch(read('src/Mail/Admin/Page.php'), /<div data-cb-mail-saved-list role="status"><\?php esc_html_e\( 'Loading saved templates…'/);
});

test('Designer Browser renders three panes with active resource cards and separate template collections', async () => {
	class Node {
		constructor(tag) {
			this.tagName=tag.toUpperCase();this.children=[];this.dataset={};this.events=new Map();
			this.value='';this.hidden=false;this.disabled=false;this.attributes={};
			this.className='';this.textContent='';this.parentElement=null;
			this.classList={add:(x)=>{this.className += ' '+x;}};
		}
		append(...nodes){for(const node of nodes){node.parentElement=this;this.children.push(node);}}
		prepend(...nodes){for(const node of nodes){node.parentElement=this;}this.children.unshift(...nodes);}
		replaceChildren(...nodes){this.children=[];this.append(...nodes);}
		setAttribute(key,value){this.attributes[key]=String(value);}
		addEventListener(key,cb){this.events.set(key,cb);}
	}
	const calls=[];
	const document={
		createElement:tag=>new Node(tag),
		getElementById:()=>({textContent:JSON.stringify({restRoot:'/wp-json/core-blueprint/v1/template-library/',nonce:'test'})}),
		querySelector:()=>null,
	};
	const window={location:{href:'https://example.test/wp-admin/',origin:'https://example.test'}};
	const sandbox={document,window,HTMLElement:Node,URL,URLSearchParams,AbortController,setTimeout,clearTimeout,
		fetch:async url=>{
			calls.push(url);
			return {ok:true,json:async()=>url.includes('/sources?')?{sources:[]}:{items:[],next_cursor:null}};
		},
	};
	const executable=browser
		.replace("import { show as showModal } from '@cb-core/modal';","const showModal = async () => false;")
		.replace('export { createBrowser, open, loadForApply };','')
		.replace('export default api;','');
	runInNewContext(executable,sandbox,{timeout:1000});
	const target=new Node('div');
	const api=window.cbCore.templateBrowser.createBrowser(target,{
		mode:'designer',contextToken:'server-issued-token',
		activeDesignId:'wordpress.email-changed',
		designs:[
			{id:'wordpress.email-changed',title:'Email changed',provider:'WordPress'},
			{id:'provider.notice',title:'Provider notice',provider:'Extension'},
		],
	});
	const shell=target.children[0];
	assert.equal(shell.children.length,3,'category, collection and grid panes');
	assert.match(shell.className,/designer-workspace/);
	const category=shell.children[0],collections=shell.children[1],content=shell.children[2];
	assert.equal(category.children.length,2,'Designs and Templates navigation');
	assert.equal(collections.children.length,4,'heading, All designs and two provider collections');
	const cards=content.children[2].children;
	assert.equal(cards.length,2);
	assert.match(cards[0].className,/is-current/);
	cards[1].children[0].checked=true;
	cards[1].children[0].events.get('change')();
	assert.equal(api.selected().kind,'design');
	assert.equal(api.selected().id,'provider.notice');
	category.children[1].events.get('click')();
	await Promise.resolve();
	assert.ok(calls.some(url=>url.includes('/templates?')),'templates remain server-authorized');
	api.destroy();
});

test('Browser modal closes before a design navigation; Apply callback is never used', async () => {
	class Node {
		constructor(tag) {
			this.tagName=tag.toUpperCase();this.children=[];this.dataset={};this.events=new Map();
			this.value='';this.hidden=false;this.disabled=false;this.attributes={};
			this.textContent='';this.parentElement=null;this.className='';
			this.classList={add:key=>{this.className+=' '+key;}};
		}
		append(...nodes){for(const node of nodes){node.parentElement=this;this.children.push(node);}}
		prepend(...nodes){for(const node of nodes)node.parentElement=this;this.children.unshift(...nodes);}
		replaceChildren(...nodes){this.children=[];this.append(...nodes);}
		setAttribute(key,value){this.attributes[key]=String(value);}
		addEventListener(key,handler){this.events.set(key,handler);}
	}
	const order=[];
	const window={location:{origin:'https://example.test',href:'https://example.test/wp-admin/'}};
	const document={
		createElement:tag=>new Node(tag),
		getElementById:()=>({textContent:JSON.stringify({
			restRoot:'/wp-json/core-blueprint/v1/template-library/',nonce:'signed-nonce',
		})}),
		querySelector:()=>null,
	};
	const sandbox={window,document,HTMLElement:Node,URL,URLSearchParams,AbortController,
		setTimeout,clearTimeout,fetch:async()=>({ok:true,json:async()=>({sources:[]})})};
	sandbox.modalHook=async ({body,onConfirm})=>{
		order.push('modal-open');
		const card=body.children[1].children[0].children[2].children[2].children[1];
		card.children[0].checked=true;
		card.children[0].events.get('change')();
		const accepted=await onConfirm();
		order.push('modal-closed');
		return accepted;
	};
	const executable=browser
		.replace("import { show as showModal } from '@cb-core/modal';",
			'const showModal = globalThis.modalHook;')
		.replace('export { createBrowser, open, loadForApply };','')
		.replace('export default api;','');
	runInNewContext(executable,sandbox,{timeout:1000});
	const result=await window.cbCore.templateBrowser.open({
		contextToken:'signed-context',
		designs:[
			{id:'wordpress.a',title:'Current',provider:'WordPress'},
			{id:'wordpress.b',title:'Other',provider:'WordPress'},
		],
		activeDesignId:'wordpress.a',
		onChoose:async()=>{throw new Error('DESIGN_NAVIGATION_MUST_NOT_APPLY_TEMPLATE');},
		onOpenDesign:async design=>{order.push('navigation:'+design.id);return true;},
	});
	assert.equal(result,true);
	assert.deepEqual(order,['modal-open','modal-closed','navigation:wordpress.b']);
});
