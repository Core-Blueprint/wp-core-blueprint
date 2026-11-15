import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {resolve, dirname} from 'node:path';
import {runInNewContext} from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const source = readFileSync(resolve(root, 'assets/js/core/template-json-import.js'), 'utf8');
const browser = readFileSync(resolve(root, 'assets/js/core/template-browser.js'), 'utf8');
const registry = readFileSync(resolve(root, 'src/TemplateLibrary/TemplateSourceRegistry.php'), 'utf8');
const php = readFileSync(resolve(root, 'src/TemplateLibrary/BrowserController.php'), 'utf8');
const assets = readFileSync(resolve(root, 'src/TemplateLibrary/Assets.php'), 'utf8');
const mail = readFileSync(resolve(root, 'src/Mail/Admin/Page.php'), 'utf8');
const mailLibrary = readFileSync(resolve(root, 'assets/js/design/mail/saved-library.js'), 'utf8');

function harness({responses = [], confirm = true, sourceRows = null} = {}) {
	const writes = [];
	const notes = [];
	let reloads = 0;
	class Node {
		constructor(tag) {
			this.tagName = tag;
			this.children = [];
			this.events = new Map();
			this.value = '';
			this.hidden = false;
			this.disabled = false;
			this.files = [];
			this.clicks = 0;
			this.attrs = new Map();
		}
		append(...children) { this.children.push(...children); }
		replaceChildren(...children) {
			this.children = children;
			if (this.tagName === 'select') this.value = children[0]?.value || '';
		}
		setAttribute(k,v) { this.attrs.set(k,v); }
		getAttribute(k) { return this.attrs.get(k); }
		addEventListener(k,cb) { this.events.set(k,cb); }
		removeEventListener(k) { this.events.delete(k); }
		click() { this.clicks++; this.events.get('click')?.(); }
		async emit(k) { await this.events.get(k)?.(); }
	}
	const document = {createElement:tag=>new Node(tag)};
	const calls = [];
	const context = {
		document, Array, Number, Object, JSON, Uint8Array, Blob, String,
		crypto:{getRandomValues:arr=>{ arr.fill(7); return arr; }},
		showModal:async()=>confirm,
		fetch:async(url,opts)=>{
			const body = JSON.parse(opts.body);
			calls.push({url,opts,body});
			const item = responses.length ? responses.shift() : null;
			const result = typeof item === 'function' ? item(body) : item;
			return {ok:result?.status==='ready'||result?.status==='created',
				json:async()=>result||{status:'invalid'}};
		},
	};
	const transformed = source
		.replace(/^import .+;$/gm,'')
		.replace(/\bexport\s+(?=(?:const|function)\b)/gm,'')
		+ '\nglobalThis.__api={createJsonImportControl,jsonImportSources};';
	runInNewContext(transformed,context,{filename:'template-json-import.js'});
	const sourceInfo = sourceRows ?? [{
		id:'core-blueprint-mail-saved',
		jsonImport:{version:1,destinationPrefix:'mail.saved.'},
	}];
	const control = context.__api.createJsonImportControl({
		label:(key,fallback)=>fallback,restRoot:'https://example.test/wp-json/core-blueprint/v1/template-library/',
		nonce:'test-nonce',contextToken:'',isCurrent:()=>true,
		onNotice:message=>notes.push(message),
		onChanged:async()=>{reloads++;},
	});
	control.updateSources(sourceInfo);
	control.setVisible(true);
	const [select,button,input] = control.node.children;
	return {control,select,button,input,calls,notes,context,api:context.__api,
		reloads:()=>reloads};
}

test('MC-02C source descriptors are opt-in, versioned and bounded',()=>{
	const h=harness();
	const sources=h.api.jsonImportSources([
		{id:'core-blueprint-mail-saved',jsonImport:{version:1,destinationPrefix:'mail.saved.'}},
		{id:'core-blueprint-mail',jsonImport:null},
		{id:'foreign',jsonImport:{version:2,destinationPrefix:'bad.'}},
		{id:'malformed',jsonImport:{version:1,destinationPrefix:'../../'}},
	]);
	assert.deepEqual(Array.from(sources.map(x=>x.id)),['core-blueprint-mail-saved']);
	assert.equal(Object.isFrozen(sources),true);
	assert.equal(h.button.hidden,false);
	assert.equal(h.select.hidden,true);
	h.control.destroy();
	assert.equal(h.control.node.children.length,0);
});

test('MC-02C imports one JSON file as one new personal Mail copy after preflight',async()=>{
	const h=harness({responses:[
		b=>({status:'ready',source:b.source,destinationId:b.destinationId,
			preview:{valid:true,record_count:1,counts:{create:1},fingerprint:'f'.repeat(64)}}),
		b=>({status:'created',source:b.source,id:b.destinationId,revision:1}),
	]});
	assert.equal(h.button.hidden,false);
	h.button.click();
	assert.equal(h.input.clicks,1);
	h.input.files=[{name:'saved-template.json',size:100,text:async()=>'{ "format":"core-blueprint-data-exchange" }'}];
	await h.input.emit('change');
	assert.deepEqual(h.calls.map(x=>x.body.phase),['preview','apply']);
	assert.equal(h.calls[0].body.source,'core-blueprint-mail-saved');
	assert.equal(h.calls[1].body.destinationId,h.calls[0].body.destinationId);
	assert.match(h.calls[1].body.destinationId,/^mail\.saved\.(?:07){16}$/);
	assert.equal(h.calls[1].body.fingerprint,'f'.repeat(64));
	assert.equal(h.calls[1].body.file,h.calls[0].body.file);
	assert.equal(h.calls[1].opts.headers['X-WP-Nonce'],'test-nonce');
	assert.equal(h.reloads(),1);
	assert.equal(h.button.disabled,false);
	assert.equal(h.notes.length,1);
});

test('MC-02C refuses unregistered sources and invalid preview before any write',async()=>{
	const h=harness({responses:[{status:'invalid'}]});
	h.control.updateSources([{id:'core-blueprint-mail',jsonImport:null}]);
	assert.equal(h.button.hidden,true);
	h.control.updateSources([{
		id:'core-blueprint-mail-saved',jsonImport:{version:1,destinationPrefix:'mail.saved.'},
	}]);
	h.input.files=[{name:'bad.json',size:10,text:async()=>'{not-json}'}];
	await h.input.emit('change');
	assert.deepEqual(h.calls.map(x=>x.body.phase),['preview']);
	assert.equal(h.button.disabled,false,'rejected preview cannot strand the import button');
	assert.equal(h.notes.length,1);
});

test('MC-02C unknown post-commit outcome locks out automatic retry',async()=>{
	const h=harness({responses:[
		b=>({status:'ready',source:b.source,destinationId:b.destinationId,
			preview:{valid:true,record_count:1,counts:{create:1},fingerprint:'a'.repeat(64)}}),
		{status:'unknown'},
	]});
	h.input.files=[{name:'live.json',size:5,text:async()=>'{abc}'}];
	await h.input.emit('change');
	assert.deepEqual(h.calls.map(x=>x.body.phase),['preview','apply']);
	assert.equal(h.button.disabled,true);
	h.button.click();
	assert.equal(h.calls.length,2,'unknown must not retry or issue another import');
	assert.match(h.notes.at(-1),/unknown/i);
	assert.equal(h.reloads(),0);
	// Closing/reopening the Browser in the same page cannot bypass an
	// uncertain post-commit result or mint another destination.
	const reopened=h.context.__api.createJsonImportControl({
		label:(key,fallback)=>fallback,
		restRoot:'https://example.test/wp-json/core-blueprint/v1/template-library/',
		nonce:'test-nonce',contextToken:'',isCurrent:()=>true,
		onNotice(){},onChanged:async()=>{},
	});
	reopened.updateSources([{
		id:'core-blueprint-mail-saved',
		jsonImport:{version:1,destinationPrefix:'mail.saved.'},
	}]);
	reopened.setVisible(true);
	assert.equal(reopened.node.children[1].disabled,true);
	reopened.node.children[1].click();
	assert.equal(reopened.node.children[2].clicks,0);
	reopened.destroy();
});

test('MC-02C shares existing Browser, Mail Data Exchange and save controls',()=>{
	assert.match(browser,/createJsonImportControl/);
	assert.match(browser,/jsonImportControl\?\.updateSources/);
	assert.match(browser,/jsonImportControl\?\.setVisible/);
	assert.match(browser,/jsonImportControl\?\.destroy/);
	assert.match(registry,/TemplateJsonImportSourceInterface/);
	assert.match(registry,/function import_json\(/);
	assert.match(php,/template-library\/import-json/);
	assert.match(php,/TemplateSourceRegistry::import_json/);
	assert.match(assets,/'@cb-core\/template-json-import'/);
	assert.doesNotMatch(mail,/data-cb-mail-saved-manager/);
	assert.doesNotMatch(mailLibrary,/mountManagement|mountMailImport|exportMailTemplate/);
	assert.match(mailLibrary,/captureApplyIntent/);
	assert.match(mailLibrary,/launchSaveAs/);
	assert.match(mailLibrary,/beginMailDesignSelection/);
});
