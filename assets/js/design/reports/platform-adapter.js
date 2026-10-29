import {createFlowPreviewHost} from '../document/flow/preview-host.js';
import {
	insertNodeCommand, removeNodeCommand, reorderNodeCommand, moveNodeCommand, setPropertyCommand,
} from '../core/commands.js';
import {nodeAt} from '../core/tree.js';

const PROVIDER = 'core.blueprint';
const TYPE = 'core.blueprint.reports';
const SWITCH = ['on', 'off'];
const ROOT = [TYPE];
const TEXT_CHILD = [TYPE, 'reports.section'];
const defs = Object.freeze({
	[TYPE]: {parents: [], values: {title: 200}},
	'reports.activity': {parents: ROOT, values: {enabled: SWITCH, showIntro: SWITCH}},
	'reports.current-state': {parents: ROOT, values: {enabled: SWITCH, showNotes: SWITCH}},
	'reports.footer': {parents: ROOT, values: {showPageNumber: SWITCH}},
	'reports.header': {parents: ROOT, values: {showSiteUrl: SWITCH, showMetadata: SWITCH}},
	'reports.heading': {parents: TEXT_CHILD, values: {text: 500}},
	'reports.kpis': {parents: ROOT, values: {enabled: SWITCH, showDetails: SWITCH}},
	'reports.metric': {parents: TEXT_CHILD, values: {label: 160, value: 200}},
	'reports.notes': {parents: ROOT, values: {enabled: SWITCH, showHeading: SWITCH}},
	'reports.paragraph': {parents: TEXT_CHILD, values: {text: 20000}},
	'reports.section': {parents: ROOT, values: {title: 160, enabled: SWITCH}},
	'reports.status': {parents: ROOT, values: {enabled: SWITCH, showDetails: SWITCH}},
	'reports.summary': {parents: ROOT, values: {enabled: SWITCH, showSecurity: SWITCH, showBackups: SWITCH}},
});
const defaults = Object.freeze({
	'reports.activity': {enabled:'on', showIntro:'on'},
	'reports.current-state': {enabled:'on', showNotes:'on'},
 	'reports.heading': {text:'Heading'},
	'reports.kpis': {enabled:'on', showDetails:'on'},
	'reports.metric': {label:'Metric', value:'Value'},
	'reports.notes': {enabled:'on', showHeading:'on'},
	'reports.paragraph': {text:'Paragraph'},
	'reports.section': {title:'New section', enabled:'on'},
	'reports.status': {enabled:'on', showDetails:'on'},
	'reports.summary': {enabled:'on', showSecurity:'on', showBackups:'on'},
});
const singletons = new Set([
	'reports.header','reports.status','reports.kpis','reports.current-state',
	'reports.activity','reports.summary','reports.notes','reports.footer',
]);
const labels = Object.freeze({
	'reports.activity':'Activity', 'reports.current-state':'Current State',
	'reports.footer':'Footer', 'reports.header':'Header', 'reports.heading':'Heading',
	'reports.kpis':'KPIs', 'reports.metric':'Metric', 'reports.notes':'Notes',
	'reports.paragraph':'Paragraph', 'reports.section':'Section', 'reports.status':'Status',
	'reports.summary':'Summary',
});
const components = Object.freeze(Object.keys(defaults).sort().map(type=>Object.freeze({
	id:'base/'+type.replace('.', '-'),type,label:labels[type],singleton:singletons.has(type),
})));
const validPath = path=>Array.isArray(path)&&path.length<=64
	&&path.every(i=>Number.isSafeInteger(i)&&i>=0);
const validValue = (value,spec)=>typeof value==='string'
	&&(typeof spec==='number'?[...value].length<=spec:spec.includes(value));
const exact = (obj,keys)=>obj&&typeof obj==='object'&&!Array.isArray(obj)
	&&Object.keys(obj).length===keys.length&&keys.every(k=>Object.hasOwn(obj,k));
function validNode(node,parent=null,depth=0,state={count:0,types:new Set()}) {
	const def=defs[node?.type];
	if(!def||depth>16||++state.count>256||!exact(node,['type','provider','properties','children'])
		||node.provider!==PROVIDER||!Array.isArray(node.children)
		||!exact(node.properties,Object.keys(def.values))
		||!Object.entries(def.values).every(([key,spec])=>validValue(node.properties[key],spec))
		||(parent!==null&&!def.parents.includes(parent))
		||((parent!==TYPE&&parent!==null)&&node.children.length&&node.type!=='reports.section')
		||(![TYPE,'reports.section'].includes(node.type)&&node.children.length))return false;
	if(singletons.has(node.type)){
		if(parent!==TYPE||state.types.has(node.type))return false;
		state.types.add(node.type);
	}
	if(node.type==='reports.section'&&parent!==TYPE)return false;
	return node.children.every(child=>validNode(child,node.type,depth+1,state));
}
export function validateProject(project) {
	if(!exact(project,['schema_version','design_type','root'])
		||project.schema_version!==1||project.design_type!==TYPE
		||project.root?.type!==TYPE||!validNode(project.root))throw new TypeError('SCHEMA_DOMAIN_INVALID');
	const children=project.root.children;
	if(children.length<2||children[0].type!=='reports.header'
		||children.at(-1).type!=='reports.footer')throw new TypeError('SCHEMA_DOMAIN_INVALID');
	return true;
}
const validateInput=(d,i)=>{
	if(!exact(i,d.id==='reports.insert'?['parentPath','index','type']
		:d.id==='reports.remove'?['path']
		:d.id==='reports.reorder'?['parentPath','fromIndex','toIndex']
		:d.id==='reports.move'?['fromPath','parentPath','index']
		:d.id==='reports.set'?['path','key','value']:[]))return false;
	if(d.id==='reports.insert')return validPath(i.parentPath)&&Number.isSafeInteger(i.index)
		&&i.index>=0&&Object.hasOwn(defaults,i.type);
	if(d.id==='reports.remove')return validPath(i.path)&&i.path.length>0;
	if(d.id==='reports.reorder')return validPath(i.parentPath)
		&&Number.isSafeInteger(i.fromIndex)&&i.fromIndex>=0
		&&Number.isSafeInteger(i.toIndex)&&i.toIndex>=0;
	if(d.id==='reports.move')return validPath(i.fromPath)&&i.fromPath.length>0&&validPath(i.parentPath)
  &&Number.isSafeInteger(i.index)&&i.index>=0;
	if(d.id==='reports.set')return validPath(i.path)&&typeof i.key==='string'&&typeof i.value==='string';
	return false;
};
export const validateCommandInput=validateInput;
export const authorizeCommand=(d,p,i,c)=>c?.resourceType==='base.maintenance-report-design'
	&&c.resourceId==='maintenance'&&c.siteId>0&&c.actorId>0
	&&d===resolveCommand(d?.id)&&p?.design_type===TYPE&&validateInput(d,i);
const command=(id,apply)=>Object.freeze({
	contractVersion:1,id,
	canApply(p,i){
		try {
			validateProject(p);
			if(!validateInput({id},i))return false;
			const result=apply(p,i);
			validateProject(result.project);
			return true;
		}catch(_){return false;}
	},
	apply(p,i){
		const result=apply(p,i);
		validateProject(result.project);
		return result;
	},
});
const table=Object.freeze({
	'reports.insert':command('reports.insert',(p,i)=>{
		const parentPath=i.parentPath;
		const parent=nodeAt(p.root,parentPath);
		// DF-C: an inadmissible selected parent is a refusal, not a
		// silent re-interpretation as a top-level insertion.
		if(!parent||!defs[i.type].parents.includes(parent.type)
			||!Array.isArray(parent.children)||i.index>parent.children.length)
			throw new TypeError('COMMAND_REFUSED');
		// Preserve the anchored Footer, including root append requests.
		// An explicit before/after position is otherwise respected.
		const index=parent.type===TYPE
			?Math.min(i.index,parent.children.length-1):i.index;
		return insertNodeCommand(parentPath,index,{
			type:i.type,provider:PROVIDER,properties:{...defaults[i.type]},children:[],
		}).apply(p);
	}),
	'reports.remove':command('reports.remove',(p,i)=>removeNodeCommand(i.path).apply(p)),
	'reports.reorder':command('reports.reorder',(p,i)=>{
		const parent=nodeAt(p.root,i.parentPath);
		if(!parent||i.fromIndex>=parent.children.length||i.toIndex>=parent.children.length)
			throw new TypeError('COMMAND_REFUSED');
		return reorderNodeCommand(i.parentPath,i.fromIndex,i.toIndex).apply(p);
	}),
	'reports.move':command('reports.move',(p,i)=>{
  const moving=nodeAt(p.root,i.fromPath);
  if(!moving||['reports.header','reports.footer'].includes(moving.type))
   throw new TypeError('COMMAND_REFUSED');
  return moveNodeCommand(i.fromPath,i.parentPath,i.index).apply(p);
 }),
	'reports.set':command('reports.set',(p,i)=>{
		const node=nodeAt(p.root,i.path);
		if(!node||!Object.hasOwn(defs[node.type].values,i.key)
			||!validValue(i.value,defs[node.type].values[i.key]))throw new TypeError('COMMAND_REFUSED');
		return setPropertyCommand(i.path,[i.key],i.value).apply(p);
	}),
});
/**
 * Reports-specific editing limits. Header/Footer are fixed document anchors;
 * ordinary root sections reorder between them, nested elements within their
 * parent. Only nodes with an actual persisted 'enabled' field may be hidden.
 */
export const layerPolicyFor = (node, parent, index) => {
	const anchoredNode = node?.type === 'reports.header' || node?.type === 'reports.footer';
	const editable = !!parent && !anchoredNode;
	const anchoredParent = parent?.type === TYPE;
	const minIndex = anchoredParent ? 1 : 0;
	const maxIndex = parent ? parent.children.length - (anchoredParent ? 2 : 1) : -1;
	const visibility = editable && Object.hasOwn(node.properties || {}, 'enabled')
		? Object.freeze({key: 'enabled', shownValue: 'on', hiddenValue: 'off'}) : null;
	return Object.freeze({
		remove: editable,
		reorder: editable && Number.isSafeInteger(index) && index >= minIndex && index <= maxIndex
			? Object.freeze({minIndex, maxIndex}) : null,
		visibility,
	});
};

/**
 * Report Elements are insertable only into a valid native parent. Existing
 * singleton report-data blocks cannot be added twice, even from the palette.
 * This is presentation guidance; reports.insert remains authoritative.
 */
export const canInsert = (project, type, parentPath = []) => {
	try {
		validateProject(project);
		if (!Object.hasOwn(defaults, type) || !validPath(parentPath)) return false;
		const parent = nodeAt(project.root, parentPath);
		return !!parent && defs[type].parents.includes(parent.type)
			&& (!singletons.has(type) || !project.root.children.some(node => node.type === type));
	} catch (_) {
		return false;
	}
};

/**
 * Concise view-only context for repeatable Reports elements in shared Layers.
 * Never serialize this derivative label into the project or persist it.
 * Rendering in the shared Shell uses textContent, not parsed HTML.
 */
export const layerSummaryFor = (node) => {
	const fields = {
		'reports.section': 'title',
		'reports.heading': 'text',
		'reports.paragraph': 'text',
		'reports.metric': 'label',
	};
	const key = Object.hasOwn(fields, node?.type) ? fields[node.type] : null;
	const raw = key && node?.properties?.[key];
	if (typeof raw !== 'string') return '';
	const normalized = raw.replace(/\s+/gu, ' ').trim();
	const chars = [...normalized];
	return chars.length > 44 ? chars.slice(0, 44).join('') + '…' : normalized;
};

export const resolveCommand=id=>Object.hasOwn(table,id)?table[id]:null;
export const componentsFor=()=>components;
export const propertiesFor=node=>Object.hasOwn(defs,node?.type)
	?Object.entries(defs[node.type].values).map(([key,spec])=>Object.freeze({
		key,label:key.replace(/([A-Z])/g,' $1').replace(/^./,c=>c.toUpperCase()),
		value:node.properties[key],multiline:key==='text',
		maxLength:typeof spec==='number'?spec:Math.max(...spec.map(v=>v.length)),
		options:Array.isArray(spec)?[...spec]:undefined,
	})):[];

/**
 * Reports-only semantic region paths. The isolated Flow iframe never receives
 * access to the domain session; the Host validates every path before selection.
 */
const previewRegionForPath = (selection, project) => {
	const path = selection?.primary?.kind === 'node' ? selection.primary.path : null;
	if (!validPath(path) || (path.length !== 1 && path.length !== 2)) return null;
	const parent = project?.root?.children?.[path[0]];
	if (!parent || parent.properties?.enabled === 'off'
		|| (path.length === 2 && parent.type !== 'reports.section')
		|| !nodeAt(project.root, path)) return null;
	return 'r' + path.join('.');
};
const previewPathForRegion = (region, project) => {
	if (typeof region !== 'string' || region.length > 64
		|| !/^r(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?$/.test(region)) return null;
	const path = region.slice(1).split('.').map(Number);
	if (!validPath(path)) return null;
	const parent = project?.root?.children?.[path[0]];
	if (!parent || parent.properties?.enabled === 'off'
		|| (path.length === 2 && parent.type !== 'reports.section')
		|| !nodeAt(project.root, path)) return null;
	return path;
};

export function createRenderAdapter({root,document:doc,profileId}) {
	if (!root || !doc || profileId !== 'base/reports') throw new TypeError('HOST_MODULE_MISSING');
	let editor = null, container = null, frame = null, preview = null, dropLayer = null, destroyed = false;
	let activeSignal = null, request = null, connectedObserver = null;
	let lastDocumentKey = '', lastProject = null, requestedSerial = 0, lastSelection = null, lastRegions = [];
	const el = (tag, cls, value = '') => {
		const node = doc.createElement(tag);
		node.className = cls;
		node.textContent = value;
		return node;
	};
	const resolveEndpoint = () => {
		const config = doc.defaultView?.cbCorePlatformV1;
		if (typeof config?.restRoot !== 'string' || !config.restRoot
			|| typeof config?.nonce !== 'string' || !config.nonce) {
			throw new TypeError('REPORTS_PREVIEW_UNAVAILABLE');
		}
		const base = new URL(config.restRoot, doc.baseURI);
		const endpoint = base.searchParams.has('rest_route')
			? (() => {
				base.searchParams.set('rest_route', '/core-blueprint/v1/reports-designer/preview');
				return base.href;
			})()
			: new URL('core-blueprint/v1/reports-designer/preview', base).href;
		return {endpoint, nonce: config.nonce};
	};
	const selectedRegion = (selection) => previewRegionForPath(selection, lastProject);
	const stopObserver = () => {
		connectedObserver?.disconnect();
		connectedObserver = null;
	};
	const schedulePreview = (html) => {
		stopObserver();
		const apply = () => {
			if (destroyed || activeSignal?.aborted || !container?.isConnected) return;
			stopObserver();
			if (!preview?.render(html)) throw new Error('REPORTS_PREVIEW_UNAVAILABLE');
		};
		if (container.isConnected) {
			apply();
		} else {
			const Observer = doc.defaultView?.MutationObserver;
			if (!Observer || !doc.documentElement) throw new Error('REPORTS_PREVIEW_UNAVAILABLE');
			connectedObserver = new Observer(apply);
			connectedObserver.observe(doc.documentElement, {childList: true, subtree: true});
		}
	};
	const clearRegions = () => {
		lastRegions = [];
		dropLayer?.replaceChildren();
		dropLayer?.dispatchEvent(new doc.defaultView.CustomEvent(
			'cb:designer-dropzones-changed', {bubbles: true}
		));
	};
	const showError = () => {
		clearRegions();
		const alreadyReported = container?.classList.contains('is-preview-error') === true;
		stopObserver();
		if (preview) preview.render('');
		if (container) {
			container.dataset.cbReportsPreviewSource = 'error';
			container.classList.remove('is-fetching');
			container.classList.add('is-preview-error');
			const status = container.querySelector('[data-cb-reports-preview-note]');
			if (status) {
				status.hidden = false;
				status.textContent = 'Report preview unavailable. No changes have been saved.';
				if (!alreadyReported) {
					doc.defaultView?.cbCore?.toast?.error?.(status.textContent);
				}
			}
		}
		lastDocumentKey = '';
	};
	const showCompiled = (html, source) => {
		if (destroyed || activeSignal?.aborted) return;
		const note = container.querySelector('[data-cb-reports-preview-note]');
		container.dataset.cbReportsPreviewSource = source;
		note.textContent = source === 'sample'
			? 'Example report metrics; site identity may reflect this installation.'
			: 'Most recent archived report. Historical values, not live status.';
		note.hidden = false;
		container.classList.remove('is-fetching', 'is-preview-error');
		schedulePreview(html);
	};

	// DF-D2E: Direct interaction lives exclusively in the trusted parent DOM.
	// The iframe provides bounded, read-only rectangle hints; it never receives
	// the Host, command or Selection authority. Every rectangle gets an invisible
	// hit surface so a normal click selects and a browser drag moves the node.
	const syncInteractionSelection = () => {
		const key = lastSelection?.primary?.kind === 'node'
			? JSON.stringify(lastSelection.primary.path) : null;
		for (const surface of dropLayer?.querySelectorAll('[data-cb-reports-direct-region]') || []) {
			surface.setAttribute('aria-pressed',
				surface.dataset.cbDesignNodeDragSource === key ? 'true' : 'false');
		}
	};
	// The isolated renderer sends only bounded, generation-verified geometry.
	// This adapter creates same-document proxy zones outside the iframe; the
	// Base drag controller continues owning validation and command dispatch.
	const onRegions = (regions) => {
		if (destroyed || activeSignal?.aborted || !dropLayer || !frame || !lastProject) return;
		lastRegions = [...regions];
		dropLayer.replaceChildren();
		const frameTop = frame.offsetTop;
		const frameLeft = frame.offsetLeft;
		if (!Number.isFinite(frameTop) || !Number.isFinite(frameLeft)) return;
		for (const region of regions) {
			const path = previewPathForRegion(region.id, lastProject);
			const node = path === null ? null : nodeAt(lastProject.root, path);
			if (!node) continue;
			const zone = el('div', 'cb-reports-platform__drop-region');
			zone.dataset.cbDesignDropZoneId = 'node/' + path.join('.');
			zone.setAttribute('aria-label', 'Place report element near ' + node.type.replace('reports.', ''));
			zone.style.position = 'absolute';
			zone.style.boxSizing = 'border-box';
			zone.style.left = (frameLeft + (Number.isFinite(region.left) ? region.left : 0)) + 'px';
			zone.style.top = (frameTop + region.top) + 'px';
			zone.style.width = (Number.isFinite(region.width) ? region.width : frame.clientWidth) + 'px';
			zone.style.height = Math.max(8, region.height) + 'px';
			zone.style.pointerEvents = 'none';
			zone.style.visibility = 'hidden';
			zone.style.zIndex = '2';
			dropLayer.append(zone);

			const hit = el('div', 'cb-reports-platform__interaction-region');
			hit.dataset.cbReportsDirectRegion = region.id;
			hit.dataset.cbDesignNodeType = node.type;
			if (!['reports.header', 'reports.footer'].includes(node.type)) {
				hit.dataset.cbDesignNodeDragSource = JSON.stringify(path);
			}
			hit.setAttribute('role', 'button');
			hit.setAttribute('aria-label', 'Select report ' + node.type.replace('reports.', ''));
			hit.tabIndex = 0;
			hit.style.position = 'absolute';
			hit.style.boxSizing = 'border-box';
			hit.style.left = zone.style.left;
			hit.style.top = zone.style.top;
			hit.style.width = zone.style.width;
			hit.style.height = zone.style.height;
			hit.style.pointerEvents = 'auto';
			hit.style.zIndex = '1';
			hit.addEventListener('click', event => {
				event.stopPropagation();
				onActivate(region.id);
			});
			hit.addEventListener('keydown', event => {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					event.stopPropagation();
					onActivate(region.id);
				}
			});
			dropLayer.append(hit);
		}
		if (container?.closest('[data-cb-design-dnd-active="true"]')) {
			for (const zone of dropLayer.querySelectorAll('[data-cb-design-drop-zone-id]')) {
				zone.style.pointerEvents = 'auto';
				zone.style.visibility = 'visible';
			}
		}
		syncInteractionSelection();
		dropLayer.dispatchEvent(new doc.defaultView.CustomEvent(
			'cb:designer-dropzones-changed', {bubbles: true}
		));
	};
	const onActivate = (region) => {
		if (destroyed || activeSignal?.aborted || !editor || !container?.isConnected
			|| container.classList.contains('is-fetching')) return;
		const path = previewPathForRegion(region, lastProject);
		if (path === null) return;
		editor.select([{kind: 'node', path}], {kind: 'node', path});
	};

	return Object.freeze({
		contractVersion: 1,
		mount({editor: session, signal}) {
			if (destroyed || signal?.aborted || typeof session?.project !== 'function'
				|| typeof session?.persistedFields !== 'function') throw new TypeError('RENDER_STALE');
			editor = session;
			activeSignal = signal;
			container = el('div', 'cb-reports-platform');
			container.dataset.cbReportsPreview = '1';
			container.dataset.cbReportsPreviewSource = 'loading';
			container.style.position = 'relative';
			const note = el('p', 'cb-reports-platform__preview-note', 'Loading report preview…');
			note.dataset.cbReportsPreviewNote = '1';
			frame = el('iframe', 'cb-reports-platform__preview-frame');
			frame.setAttribute('title', 'Maintenance report preview');
			dropLayer = el('div', 'cb-reports-platform__drop-layer');
			dropLayer.style.position = 'absolute';
			dropLayer.style.inset = '0';
			dropLayer.style.zIndex = '5';
			dropLayer.style.pointerEvents = 'none';
			container.append(note, frame, dropLayer);
			root.append(container);
			preview = createFlowPreviewHost(frame, {onActivate, onRegions});
		},
		async update({project, selection, signal}) {
			if (destroyed || signal?.aborted || activeSignal?.aborted || !container || !preview) {
				throw new TypeError('RENDER_STALE');
			}
			validateProject(project);
			lastProject = project;
			lastSelection = selection;
			const fields = editor.persistedFields();
			const documentKey = JSON.stringify({project, fields});
			preview.setSelection(selectedRegion(selection));
			if (documentKey === lastDocumentKey) return;
			// A prior iframe's geometry is never usable for a new project revision.
			clearRegions();

			request?.abort();
			request = new AbortController();
			const localRequest = request;
			const serial = ++requestedSerial;
			const abort = () => localRequest.abort();
			signal?.addEventListener('abort', abort, {once: true});
			container.dataset.cbReportsPreviewSource = 'loading';
			container.classList.add('is-fetching');
			container.classList.remove('is-preview-error');
			const note = container.querySelector('[data-cb-reports-preview-note]');
			note.hidden = false;
			note.textContent = 'Updating report preview…';
			try {
				const {endpoint, nonce} = resolveEndpoint();
				const response = await doc.defaultView.fetch(endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: documentKey,
					cache: 'no-store',
					signal: localRequest.signal,
				});
				if (!response.ok) throw new Error('REPORTS_PREVIEW_UNAVAILABLE');
				const data = await response.json();
				if (!data || data.status !== 'ready'
					|| !['sample', 'archived'].includes(data.source)
					|| typeof data.html !== 'string') throw new Error('REPORTS_PREVIEW_UNAVAILABLE');
				if (destroyed || activeSignal?.aborted || serial !== requestedSerial) return;
				showCompiled(data.html, data.source);
				lastDocumentKey = documentKey;
			} catch (error) {
				if (!destroyed && !activeSignal?.aborted && serial === requestedSerial) showError();
			} finally {
				signal?.removeEventListener('abort', abort);
				if (request === localRequest) request = null;
			}
		},
		syncSelection({selection}) {
			if (!destroyed) { lastSelection = selection; preview?.setSelection(selectedRegion(selection)); syncInteractionSelection(); }
		},
		captureFocus() { return null; },
		restoreFocus() {},
		destroy() {
			destroyed = true;
			++requestedSerial;
			request?.abort();
			request = null;
			stopObserver();
			preview?.destroy();
			preview = null;
			editor = null;
			container?.remove();
			container = null;
			dropLayer = null;
			frame = null;
			lastProject = null;
		},
	});
}
