import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {resolve, dirname} from 'node:path';
import {runInNewContext} from 'node:vm';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const moduleSource = readFileSync(resolve(root, 'assets/js/design/mail/saved-portability.js'), 'utf8');
const ui = readFileSync(resolve(root, 'assets/js/design/mail/saved-library.js'), 'utf8');
const backend = readFileSync(resolve(root, 'src/Mail/Designer/SavedTemplateController.php'), 'utf8');
const entity = readFileSync(resolve(root, 'src/Mail/Designer/SavedTemplatePortability.php'), 'utf8');
const registry = readFileSync(resolve(root, 'src/Interoperability/Registry.php'), 'utf8');

function harness(api) {
	const created = [];
	const clicked = [];
	const downloads = [];
	const notices = [];
	let uncertainty = 0;
	class Node {
		constructor(tag) {
			this.tag = tag;
			this.children = [];
			this.events = {};
			this.files = [];
			this.value = '';
			this.size = 0;
			this.name = '';
		}
		append(...nodes) { this.children.push(...nodes); }
		setAttribute(name, val) { this[name] = val; }
		addEventListener(name, cb) { this.events[name] = cb; }
		click() { clicked.push(this); }
		remove() {}
	}
	const document = {
		body:{append(node) {downloads.push(node);}},
		createElement(tag) {
			const result = new Node(tag);
			created.push(result);
			return result;
		},
	};
	const sandbox = {
		document, Blob, Array, Uint8Array, URL:{
			createObjectURL:()=> 'blob:mail-test', revokeObjectURL:()=> {},
		},
		setTimeout(fn){fn();},
		crypto:{getRandomValues(bytes){ bytes.fill(7); return bytes; }},
	};
	const code = moduleSource
		.replace('export async function exportMailTemplate', 'async function exportMailTemplate')
		.replace('export function mountMailImport', 'function mountMailImport')
		+ '\nglobalThis.__test = {exportMailTemplate, mountMailImport};';
	runInNewContext(code, sandbox);
	return {
		...sandbox.__test, sandbox, created, clicked, downloads, notices,
		label:(key, fallback)=>fallback,
		toast:{success(t){notices.push(['success',t]);},error(t){notices.push(['error',t]);}},
		modal:async()=>true,
		uncertain:()=>{uncertainty++;},
		get uncertainty(){return uncertainty;},
		api,
	};
}

test('T4 source uses real Data Exchange and the isolated Mail domain, never a second parser/store', () => {
	assert.match(backend, /Engine::export_json/);
	assert.match(backend, /Engine::preview_json/);
	assert.match(backend, /Engine::apply_json/);
	assert.match(backend, /Foundation::MODE_CREATE_ONLY/);
	assert.match(entity, /implements EntityInterface/);
	assert.match(entity, /SavedTemplateStore::create_imported_personal/);
	assert.match(registry, /BaseImplementationCatalog::definitions/);
	assert.match(ui, /mountMailImport/);
	assert.match(ui, /exportMailTemplate/);
	assert.doesNotMatch(moduleSource, /eval\(|innerHTML|fetch\(/);
});

test('T4 export downloads only a successful authenticated REST Data Exchange result', async () => {
	const h = harness(async (action, data) => {
		assert.equal(action, 'export');
		assert.equal(data.id, 'mail.saved.'+'a'.repeat(32));
		return {status:'ready',data:'{"format":"core-blueprint-data-exchange"}'};
	});
	assert.equal(await h.exportMailTemplate({
		api:h.api,item:{id:'mail.saved.'+'a'.repeat(32),revision:1},
		label:h.label,toast:h.toast,
	}),true);
	assert.equal(h.downloads.length,1);
	assert.equal(h.downloads[0].download,'core-blueprint-mail-template.json');
	assert.equal(h.clicked.length,1);
	assert.equal(h.notices[0][0],'success');
});

test('T4 import preflights, confirms, and applies the same file and destination once', async () => {
	const requests = [];
	const h = harness(async(action, payload)=>{
		requests.push({action,payload});
		if (action === 'preview') return {
			status:'ready',preview:{valid:true,record_count:1,counts:{create:1},fingerprint:'f'.repeat(64)},
		};
		if (action === 'import') return {status:'created',id:payload.destinationId};
		throw new Error('unexpected action');
	});
	let reloaded=0;
	const root = new (h.sandbox.document.createElement('div').constructor)('div');
	h.mountMailImport({
		root,api:h.api,label:h.label,toast:h.toast,
		showModal:h.modal,reload:async()=>{reloaded++;},onUncertain:h.uncertain,
	});
	const input = h.created.find(node=>node.tag==='input');
	assert.ok(input);
	input.files=[{name:'offline.json',size:190,text:async()=> '{"format":"core-blueprint-data-exchange"}'}];
	await input.events.change();
	assert.deepEqual(requests.map(r=>r.action),['preview','import']);
	assert.equal(requests[0].payload.destinationId,'mail.saved.'+'07'.repeat(16));
	assert.equal(requests[1].payload.destinationId,requests[0].payload.destinationId);
	assert.equal(requests[1].payload.file,requests[0].payload.file);
	assert.equal(requests[1].payload.fingerprint,'f'.repeat(64));
	assert.equal(reloaded,1);
	assert.equal(h.uncertainty,0);
});

test('T4 import refuses invalid previews without a write', async () => {
	const req=[];
	const h=harness(async(action,data)=>{req.push(action);return{status:'invalid'};});
	const root=h.sandbox.document.createElement('div');
	h.mountMailImport({root,api:h.api,label:h.label,toast:h.toast,showModal:h.modal,reload:async()=>{}});
	const input=h.created.find(node=>node.tag==='input');
	input.files=[{name:'bad.json',size:2,text:async()=> '{}'}];
	await input.events.change();
	assert.deepEqual(req,['preview']);
	assert.equal(h.notices.at(-1)[0],'error');
});

test('T4 ambiguous import refuses automatic retry and disables import controls', async () => {
	const req=[];
	const h=harness(async(action,data)=>{
		req.push(action);
		return action==='preview'
			? {status:'ready',preview:{valid:true,record_count:1,counts:{create:1},fingerprint:'a'.repeat(64)}}
			: {status:'unknown'};
	});
	const root=h.sandbox.document.createElement('div');
	let uncertain=0;
	h.mountMailImport({root,api:h.api,label:h.label,toast:h.toast,showModal:h.modal,reload:async()=>{},onUncertain:()=>{uncertain++;}});
	const input=h.created.find(node=>node.tag==='input');
	input.files=[{name:'file.json',size:3,text:async()=> '{}'}];
	await input.events.change();
	assert.deepEqual(req,['preview','import']);
	assert.equal(uncertain,1);
	const button=h.created.find(node=>node.tag==='button');
	assert.equal(button.disabled,true);
});
