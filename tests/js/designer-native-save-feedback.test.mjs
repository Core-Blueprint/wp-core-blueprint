import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const transport = await readFile(new URL('../../assets/js/design/platform/wordpress-save-adapter.js', import.meta.url), 'utf8');
const editor = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
const assets = await readFile(new URL('../../src/Design/Editor/Assets.php', import.meta.url), 'utf8');
const launch = await readFile(new URL('../../assets/js/features/designer-launch.js', import.meta.url), 'utf8');

const extract = (start, end) => {
	const a = transport.indexOf(start), b = transport.indexOf(end, a);
	assert.ok(a >= 0 && b > a, 'Expected trusted transport function: ' + start);
	return transport.slice(a, b);
};

const instantiateRequest = (respond) => {
	const config = {restRoot: 'https://example.test/wp-json/', nonce: 'test-nonce'};
	const location = {origin: 'https://example.test', href: 'https://example.test/wp-admin/'};
	const validConfig = () => true;
	const issue = code => Object.assign(new Error(code), {code});
	return new Function('config','location','validConfig','issue','fetch','URL',
		extract('async function request(', '\n/**\n * Context-bound transport') + '\nreturn request;'
	)(config,location,validConfig,issue,respond,URL);
};

test('proven REST validation refusal is not misreported as ambiguous Save', async () => {
	for (const status of [400, 403, 409]) {
		const request = instantiateRequest(async () => ({
			ok:false, status, json:async()=>({status:'refused'}),
		}));
		assert.deepEqual(await request('save','POST',{operationId:'dummy'}),{status:'refused'});
	}
	const conflict = instantiateRequest(async () => ({
		ok:false,status:409,json:async()=>({status:'conflict'}),
	}));
	assert.deepEqual(await conflict('save','POST',{operationId:'dummy'}),{status:'conflict'});
});

test('ambiguous transport and recovery failures remain unverified', async () => {
	for (const response of [
		{ok:false,status:503,json:async()=>({status:'refused'})},
		{ok:false,status:409,json:async()=>({status:'unknown'})},
		{ok:false,status:403,json:async()=>({code:'rest_forbidden'})},
	]) {
		const request = instantiateRequest(async()=>response);
		await assert.rejects(request('save','POST',{operationId:'dummy'}),
			/SAVE_TRANSPORT_UNVERIFIED/);
	}
	const recovery = instantiateRequest(async()=>({
		ok:false,status:409,json:async()=>({status:'refused'}),
	}));
	await assert.rejects(recovery('recover','POST',{operationId:'dummy'}),
		/SAVE_TRANSPORT_UNVERIFIED/);
	const disconnected = instantiateRequest(async()=>{throw new Error('network lost')});
	await assert.rejects(disconnected('save','POST',{operationId:'dummy'}),/network lost/);
});

test('native Save outcomes use only the canonical Toast Foundation', () => {
	const called = [];
	const log = [];
	const baseToast = {
		success:(...args)=>called.push(['success',...args]),
		error:(...args)=>called.push(['error',...args]),
		warning:(...args)=>called.push(['warning',...args]),
	};
	const config = {saveNotices:{saved:'Saved locale',unknown:'Unknown locale'}};
	const console = {warn:(...args)=>log.push(args)};
	const fn = new Function('baseToast','config','console',
		extract('const notifyDesignerSave = ', '\nconst config=') + '\nreturn notifyDesignerSave;'
	)(baseToast,config,console);
	fn({phase:'save',outcome:'committed'});
	fn({phase:'save',outcome:'refused'});
	fn({phase:'save',outcome:'unknown',reason:'SAVE_RECEIPT_INVALID'});
	fn({phase:'save',outcome:'persisted-unsynchronized'});
	assert.deepEqual(called.map(x=>x[0]),['success','error','warning','error']);
	assert.equal(called[0][1],'Saved locale');
	assert.equal(called[2][1],'Unknown locale');
	assert.deepEqual(called[2][2],{duration:12000});
	assert.deepEqual(called[3][2],{persistent:true});
	assert.equal(log.length,1);
	assert.equal(log[0][1],'SAVE_RECEIPT_INVALID');
	assert.ok(!JSON.stringify(log).includes('operationId'));
});

test('native Host requires toast module and preserves legacy no-double-toast behaviour', () => {
	assert.match(transport,/import \{ toast as baseToast \} from '@cb-core\/toast'/);
	assert.match(transport,/notifySave:notifyDesignerSave/);
	assert.match(assets,/Assets::enqueue_toasts\( \\CoreBlueprint\\Core\\UI\\Assets::TOAST_PRESENTATION_CORE \)/);
	assert.match(assets,/'@cb-core\/toast'/);
	assert.match(assets,/'saveNotices'/);
	assert.match(editor,/notifySave\?\.\(/);
	assert.match(editor,/nativeHandled: notifySave !== null/);
	assert.match(launch,/event\.detail\?\.nativeHandled === true/);
	assert.match(editor,/outcome === 'committed' \? 'saved'/);
});
