import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';
import {canInsert, componentsFor, resolveCommand, validateProject} from '../../assets/js/design/reports/platform-adapter.js';

const node = (type, properties, children = []) => ({type, provider: 'core.blueprint', properties, children});
const project = () => ({
	schema_version: 1,
	design_type: 'core.blueprint.reports',
	root: node('core.blueprint.reports', {title: 'Maintenance Report'}, [
		node('reports.header', {showSiteUrl: 'on', showMetadata: 'on'}),
		node('reports.status', {enabled: 'on', showDetails: 'on'}),
		node('reports.kpis', {enabled: 'on', showDetails: 'on'}),
		node('reports.current-state', {enabled: 'on', showNotes: 'on'}),
		node('reports.activity', {enabled: 'on', showIntro: 'on'}),
		node('reports.summary', {enabled: 'on', showSecurity: 'on', showBackups: 'on'}),
		node('reports.notes', {enabled: 'on', showHeading: 'on'}),
		node('reports.footer', {showPageNumber: 'on'}),
	]),
});

test('Reports palette exposes six existing singleton blocks and four repeatable elements', () => {
	const components = componentsFor();
	assert.equal(components.length, 10);
	const byType = new Map(components.map(item => [item.type, item]));
	for (const type of ['reports.status','reports.kpis','reports.current-state','reports.activity','reports.summary','reports.notes']) {
		assert.equal(byType.get(type)?.singleton, true, type);
	}
	for (const type of ['reports.section','reports.heading','reports.paragraph','reports.metric']) {
		assert.equal(byType.get(type)?.singleton, false, type);
	}
	assert.equal(byType.has('reports.header'), false);
	assert.equal(byType.has('reports.footer'), false);
});

test('Reports duplicate insert is refused by existing domain command authority', () => {
	const p = project();
	assert.equal(validateProject(p), true);
	assert.equal(canInsert(p, 'reports.status', []), false);
	assert.equal(resolveCommand('reports.insert').canApply(p, {
		parentPath: [], index: p.root.children.length, type: 'reports.status',
	}), false);
});

test('New Section stays before Footer and nested Heading stays in Section', () => {
	const insert = resolveCommand('reports.insert');
	const p = project();
	assert.equal(canInsert(p, 'reports.heading', []), true);
	const withSection = insert.apply(p, {
		parentPath: [], index: p.root.children.length, type: 'reports.section',
	}).project;
	assert.equal(withSection.root.children.at(-2).type, 'reports.section');
	assert.equal(withSection.root.children.at(-1).type, 'reports.footer');
	const sectionPath = [withSection.root.children.length - 2];
	assert.equal(canInsert(withSection, 'reports.heading', sectionPath), true);
	const withHeading = insert.apply(withSection, {
		parentPath: sectionPath, index: 0, type: 'reports.heading',
	}).project;
	assert.equal(withHeading.root.children.at(-2).children[0].type, 'reports.heading');
	assert.equal(withHeading.root.children.at(-1).type, 'reports.footer');
});

test('DF-C: Reports refuses nested Section instead of redirecting to document root', () => {
	const insert = resolveCommand('reports.insert');
	const original = project();
	const withSection = insert.apply(original, {
		parentPath: [], index: original.root.children.length, type: 'reports.section',
	}).project;
	const sectionPath = [withSection.root.children.length - 2];
	const before = JSON.stringify(withSection);
	const attempted = {parentPath: sectionPath, index: 0, type: 'reports.section'};
	assert.equal(canInsert(withSection, 'reports.section', sectionPath), false);
	assert.equal(insert.canApply(withSection, attempted), false);
	assert.throws(() => insert.apply(withSection, attempted), /COMMAND_REFUSED/);
	assert.equal(JSON.stringify(withSection), before, 'no root-level or nested mutation');
});

test('Native Reports and Mail share the public renderer with existing commands and one DND controller', async () => {
	const source = await readFile(new URL('../../assets/js/design/editor.js', import.meta.url), 'utf8');
	assert.match(source, /component\.singleton !== true/);
	assert.match(source, /existingReportComponentPath\(project, component\)/);
	assert.match(source, /action: existingPath !== null \? 'edit' : 'add'/);
	assert.match(source, /palette\.update\(\{elements: descriptors, dynamicData: nativeTokens\}\)/);
	assert.match(source, /editor\.select\(\[ref\], ref\);\s*showSelectedInspector\(\);\s*return;/);
	assert.match(source, /controller\.activatePanel\('inspector'\)/);
	assert.match(source, /refreshElements\(project, selection\)/);
	assert.match(source, /activeRegistry\.profileId === 'base\/reports'/);
	assert.match(source, /elementsPanel\.replaceChildren\(palette\.element\)/);
	assert.match(source, /releasePaletteSources\(\);\s*palette\.update/);
	assert.match(source, /registeredPaletteSources\.set\(button, dnd\.registerSource/);
	assert.equal((source.match(/createDesignerDragDropController\(\{/g) || []).length, 1);
	assert.doesNotMatch(source, /componentGrid\.append\(button\)/);
	assert.doesNotMatch(source, /dynamicGrid\.append\(dataButton\)/);
});
