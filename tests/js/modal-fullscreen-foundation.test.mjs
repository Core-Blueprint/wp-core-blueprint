import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const modalSource = read('assets/js/core/modal.js');
const browserSource = read('assets/js/core/template-browser.js');
const coreCss = read('assets/css/components/modals.css');
const nativeCss = read('assets/css/components/modals-native.css');
const iconRegistry = read('src/UI/Icon.php');

function modalFixture(presentation) {
	const doc = {activeElement:null};
	class Element {
		constructor(tag) {
			this.tagName=tag.toUpperCase();
			this.children=[];
			this.parentNode=null;
			this.className='';
			this.dataset={};
			this.attributes={};
			this.listeners=new Map();
			this.open=false;
			this.disabled=false;
			this.isConnected=true;
			const classes=new Set();
			this.classList={
				add:(key)=>classes.add(key),
				contains:(key)=>classes.has(key),
				toggle:(key,force)=>{
					const next=force===undefined?!classes.has(key):Boolean(force);
					if(next) classes.add(key);else classes.delete(key);
					return next;
				},
			};
		}
		appendChild(child) {child.parentNode=this;this.children.push(child);return child;}
		append(...items) {items.forEach(item=>this.appendChild(item));}
		replaceChildren(...items) {this.children=[];this.append(...items);}
		setAttribute(key,value) {this.attributes[key]=String(value);}
		removeAttribute(key) {delete this.attributes[key];}
		getAttribute(key) {return this.attributes[key]??null;}
		addEventListener(type,handler) {this.listeners.set(type,handler);}
		fire(type,event) {
			return this.listeners.get(type)?.(event??{target:this,preventDefault(){}});
		}
		showModal(){this.open=true;}
		close(){this.open=false;}
		focus(){doc.activeElement=this;}
		remove(){
			if(this.parentNode) this.parentNode.children=this.parentNode.children.filter(item=>item!==this);
			this.isConnected=false;
		}
	}
	doc.body=new Element('body');
	doc.getElementById=()=>({textContent:JSON.stringify({
		presentation,
		i18n:{close:'Close',cancel:'Cancel',confirm:'Confirm'},
	})});
	doc.createElement=tag=>new Element(tag);
	const opener=new Element('button');
	opener.focus();

	const script=modalSource
		.replace("import { create as createIcon } from './icon.js';",
			"const createIcon = name => { const node=document.createElement('svg'); node.dataset.icon=name; return node; };")
		.replace('export { show };','')
		.replace('export default modal;','');
	const window={};
	runInNewContext(script,{
		document:doc,window,HTMLElement:Element,
		requestAnimationFrame:callback=>callback(),
		console:{error:()=>{}},
	},{timeout:1000});
	return {doc,opener,api:window.cbCore.modal};
}

test('FIELD-04: fullscreen uses the same shared modal on Core and WP-native screens', async()=>{
	for(const presentation of ['core','wp-native']){
		const {doc,opener,api}=modalFixture(presentation);
		const outcome=api.show({title:'Browser',body:'Content',size:'fullscreen'});
		const dialog=doc.body.children[0];
		assert.match(dialog.className, /cb-core-modal--fullscreen/);
		assert.doesNotMatch(dialog.className, /cb-core-modal--expandable/);
		assert.equal(dialog._cbHeaderCloseBtn.type,'button');
		assert.equal(dialog._cbHeaderCloseBtn.getAttribute('aria-label'),'Close');
		assert.equal(dialog._cbHeaderCloseBtn.title,'Close');
		assert.equal(dialog._cbHeaderCloseBtn.children[0].dataset.icon,'x');
		assert.equal(doc.activeElement,dialog._cbHeaderCloseBtn);
		assert.equal(dialog._cbCancelBtn.textContent,'Cancel');
		dialog._cbHeaderCloseBtn.fire('click');
		assert.equal(await outcome,false,'header Close must cancel, never confirm');
		assert.equal(dialog.open,false);
		assert.equal(doc.activeElement,opener,'original toolbar control regains focus');
		assert.equal(doc.body.children.length,0,'dialog removed after settlement');
	}
});

test('FIELD-04: while async confirmation is pending, header Close and Escape fail closed', async()=>{
	const {doc,api}=modalFixture('core');
	let finish;
	const outcome=api.show({
		title:'Browser',size:'fullscreen',
		onConfirm:()=>new Promise(resolve=>{finish=resolve;}),
	});
	const dialog=doc.body.children[0];
	const confirm=dialog._cbConfirmBtn.fire('click');
	assert.equal(dialog._cbHeaderCloseBtn.disabled,true);
	dialog._cbHeaderCloseBtn.fire('click');
	dialog._cbCancelBtn.fire('click');
	let cancelled=false;
	dialog.fire('cancel',{preventDefault(){cancelled=true;}});
	assert.equal(cancelled,true);
	assert.equal(dialog.open,true,'pending confirmation must not be interrupted');
	finish(false);
	await confirm;
	assert.equal(dialog._cbHeaderCloseBtn.disabled,false,'close restored on refused confirmation');
	dialog._cbHeaderCloseBtn.fire('click');
	assert.equal(await outcome,false);
});

test('FIELD-04: other dialogs retain ordinary and optional workspace presentation', async()=>{
	const {doc,api}=modalFixture('wp-native');
	const normal=api.show({title:'Normal'});
	const first=doc.body.children[0];
	assert.equal(first._cbHeaderCloseBtn,null);
	assert.doesNotMatch(first.className,/cb-core-modal--fullscreen/);
	first._cbCancelBtn.fire('click');
	assert.equal(await normal,false);
	const workspace=api.show({title:'Workspace',size:'workspace',expandable:true});
	const dialog=doc.body.children[0];
	assert.match(dialog.className,/cb-core-modal--workspace/);
	assert.match(dialog.className,/cb-core-modal--expandable/);
	assert.equal(dialog._cbHeaderCloseBtn,null);
	const expand=dialog._cbForm.children.find(item=>item.className==='cb-core-modal__expand-toggle');
	assert.ok(expand);
	expand.fire('click');
	assert.equal(dialog.classList.contains('is-expanded'),true);
	dialog._cbCancelBtn.fire('click');
	assert.equal(await workspace,false);
});

test('FIELD-04B: fullscreen shares the existing inset expanded-workspace geometry',()=>{
  for(const [css,prefix,space] of [
    [coreCss,'dialog.cb-core-modal--core','var(--cb-space-4)'],
    [nativeCss,'body.wp-admin dialog.cb-core-modal--wp-native','var(--cb-space-4, 16px)'],
  ]){
    const selector = prefix+'.cb-core-modal--workspace.is-expanded,\n'+prefix+'.cb-core-modal--fullscreen {';
    assert.ok(css.includes(selector),'same maximized presentation is reused');
    const start = css.indexOf(selector);
    const geometry = css.slice(start,css.indexOf('}',start)+1);
    assert.ok(geometry.includes('width: calc(100vw - '+space+' - '+space+');'));
    assert.ok(geometry.includes('height: calc(100dvh - '+space+' - '+space+');'));
    assert.match(geometry,/max-width: none;/);
    assert.match(geometry,/max-height: none;/);
    assert.doesNotMatch(geometry,/border:\s*0|border-radius:\s*0|margin:\s*0|inset:\s*0/);
    assert.ok(css.includes(prefix+'.cb-core-modal--fullscreen .cb-core-modal__form {'));
    assert.match(css, /cb-core-modal__header-close:focus-visible/);
    assert.match(css, /cb-core-modal__header-close:disabled/);
  }
  assert.match(iconRegistry, /'x'\s*=>\s*\[/);
  assert.match(browserSource, /size: 'fullscreen'/);
  assert.doesNotMatch(browserSource,/expandable: true/);
  assert.doesNotMatch(browserSource,/size: 'workspace'/);
});
