import assert from 'node:assert/strict';
import {cp, mkdtemp, readFile, readdir, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
import {test} from 'node:test';

async function convert(dir) {
	for (const entry of await readdir(dir, {withFileTypes: true})) {
		const path = join(dir, entry.name);
		if (entry.isDirectory()) { await convert(path); continue; }
		if (!entry.isFile() || !path.endsWith('.js')) continue;
		let source = (await readFile(path, 'utf8'))
			.replaceAll(/(['"])(\.\.?\/[^'"]+)\.js\1/g, '$1$2.mjs$1');
		if (entry.name === 'editor.js') {
			source = source.replace("'@cb-core/design-motion'", "'./core/motion.mjs'");
		}
		await writeFile(path.replace(/\.js$/, '.mjs'), source);
	}
}

test('CX02D-P1: supported Designer ESM and global facade never expose Base-managed authority', async () => {
	const temporary = await mkdtemp(join(tmpdir(), 'cb-df-cx02d-abi-'));
	try {
		const dest = join(temporary, 'design');
		await cp(new URL('../../assets/js/design/', import.meta.url), dest, {recursive: true});
		await convert(dest);
		globalThis.window = {};
		const module = (path) => import(pathToFileURL(join(dest, path)).href);
		const publicEditor = await module('editor.mjs');
		const internal = await module('core/context-managed.mjs');
		for (const key of ['createManagedContextBinding','createManagedContextCoordinator',
			'registerManagedContextFactories','createManagedContextAuthority']) {
			assert.equal(Object.hasOwn(publicEditor, key), false, key+' must not be a public named ESM export');
			assert.equal(Object.hasOwn(globalThis.window.cbCore.designEditor,key),false,
				key+' must not be available on the Native Trusted Host facade');
		}
		assert.equal(typeof internal.createManagedContextBinding, 'function');
		assert.equal(typeof internal.createManagedContextCoordinator, 'function');
		assert.equal(typeof internal.registerManagedContextFactories, 'function');
		const binding=internal.createManagedContextBinding({
			initial:{id:'private-source',kind:'domain',sessionOptions:{
				project:{schema_version:0,design_type:'fixture.private',
					root:{type:'fixture',properties:{},provider:'fixture.private',children:[]}},
			}},
		});
		assert.equal(binding.current.id,'private-source');
		assert.equal(binding.current.status,'ACTIVE');
		assert.equal(typeof binding.current.session.dispose,'undefined');
		assert.equal(typeof binding.current.session.projectState,'undefined');
		assert.equal(typeof binding.current.session.history,'undefined');
		assert.equal(typeof publicEditor.createDomainSession,'function');
		assert.equal(typeof globalThis.window.cbCore.designEditor.createDomainSession,'function');
	} finally {
		delete globalThis.window;
		await rm(temporary,{recursive:true,force:true});
	}
});
