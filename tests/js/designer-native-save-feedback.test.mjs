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

test('native fullscreen Toast is above the Designer screen without a legacy fullscreen class', async () => {
	const css = await readFile(new URL('../../assets/css/components/toasts.css', import.meta.url), 'utf8');
	const screenCss = await readFile(new URL('../../assets/css/design/platform-fullscreen.css', import.meta.url), 'utf8');
	const screenZ = Number(screenCss.slice(screenCss.indexOf('.cb-core-platform-screen {')).split('z-index:')[1]?.split(';')[0]?.trim());
	const nativeRule = css.slice(css.indexOf('body:has(.cb-core-platform-screen[data-cb-platform-mounted="true"])'));
	const nativeZ = Number(nativeRule.split('z-index:')[1]?.split(';')[0]?.trim());
	assert.equal(screenZ, 100150);
	assert.ok(nativeZ > screenZ, 'Base Toast should appear above native Mail and Reports screens');
});

test('native Save transport does not turn server unknown into success or refusal', async () => {
	const reason = 'SAVE_LEDGER_READ_UNVERIFIED';
	const request = instantiateRequest(async () => ({
		ok:true, status:200, json:async()=>({status:'unknown',reason}),
	}));
	assert.deepEqual(await request('save','POST',{operationId:'dummy'}),{status:'unknown',reason});
	const editor = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
	const coordinator = await readFile(new URL('../../assets/js/design/platform/save-coordinator.js', import.meta.url), 'utf8');
	assert.ok(coordinator.includes('safeUnknownReason(response)'));
	assert.match(editor,/SAVE_LEDGER_READ_UNVERIFIED/);
	assert.match(transport,/SAVE_LEDGER_READ_UNVERIFIED/);
});

test('rolled-back Mail refusals reveal only a bounded phase without optimistic success', async () => {
	const read = instantiateRequest(async () => ({
		ok: true, status: 200, json: async () => ({
			status: 'refused', reason: 'SAVE_DOCUMENT_VALIDATION_REFUSED',
		}),
	}));
	const response = await read('save','POST',{operationId:'test'});
	assert.deepEqual(response, {
		status: 'refused', reason: 'SAVE_DOCUMENT_VALIDATION_REFUSED',
	});
	const seen = [];
	const toast = {success: () => seen.push('success'), warning: () => seen.push('warning'),
		error: () => seen.push('error')};
	const warnings = [];
	const display = new Function('baseToast','config','console',
		extract('const notifyDesignerSave = ', '\nconst config=') + '\nreturn notifyDesignerSave;'
	)(toast, {saveNotices:{}}, {warn: (...args) => warnings.push(args)});
	display({phase:'save', outcome:response.status, reason:response.reason});
	assert.deepEqual(seen,['error']);
	assert.deepEqual(warnings, [[
		'[Core Blueprint Designer] Save refused at:', 'SAVE_DOCUMENT_VALIDATION_REFUSED',
	]]);
	assert.ok(!JSON.stringify(warnings).includes('operationId'));
	const authority = await readFile(
		new URL('../../assets/js/design/platform/save-coordinator.js', import.meta.url), 'utf8');
	assert.match(authority,/safeRefusedReason\(response\)/);
	assert.match(editor,/SAVE_DOCUMENT_VALIDATION_REFUSED/);
	assert.match(transport,/SAVE_DOCUMENT_VALIDATION_REFUSED/);
});

test('Mail storage CREATE and UPDATE failures remain bounded refusals, never success', async () => {
	const codes = [
		'SAVE_STORAGE_CREATE_INVALID',
		'SAVE_STORAGE_CREATE_SCHEMA_UNAVAILABLE',
		'SAVE_STORAGE_CREATE_QUERY_FAILED',
		'SAVE_STORAGE_CREATE_READBACK_UNVERIFIED',
		'SAVE_STORAGE_UPDATE_QUERY_FAILED',
		'SAVE_STORAGE_UPDATE_READBACK_UNVERIFIED',
	];
	const seen = [];
	const warnings = [];
	const presenter = new Function('baseToast','config','console',
		extract('const notifyDesignerSave = ', '\nconst config=') + '\nreturn notifyDesignerSave;'
	)({
		success: () => seen.push('success'),
		error: () => seen.push('error'),
		warning: () => seen.push('warning'),
	}, {saveNotices:{}}, {warn: (...args) => warnings.push(args)});
	for (const reason of codes) {
		const req = instantiateRequest(async () => ({
			ok: true, status: 200, json: async () => ({status: 'refused', reason}),
		}));
		const response = await req('save','POST',{operationId:'dummy'});
		presenter({phase:'save', outcome:response.status, reason:response.reason});
	}
	assert.deepEqual(seen, codes.map(() => 'error'));
	assert.deepEqual(warnings.map(args => args[1]), codes);
	assert.ok(!JSON.stringify(warnings).includes('operationId'));
	const coordinator = await readFile(
		new URL('../../assets/js/design/platform/save-coordinator.js', import.meta.url), 'utf8');
	assert.match(coordinator, /SAVE_STORAGE_/);
	assert.match(editor, /SAVE_STORAGE_/);
	assert.match(transport, /SAVE_STORAGE_/);
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
