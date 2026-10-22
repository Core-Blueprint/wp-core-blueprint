import {
	insertNodeCommand, removeNodeCommand, reorderNodeCommand, setPropertyCommand,
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
	'reports.footer': {showPageNumber:'on'},
	'reports.header': {showSiteUrl:'on', showMetadata:'on'},
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
	id:'base/'+type.replace('.', '-'),type,label:labels[type],
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
		:d.id==='reports.set'?['path','key','value']:[]))return false;
	if(d.id==='reports.insert')return validPath(i.parentPath)&&Number.isSafeInteger(i.index)
		&&i.index>=0&&Object.hasOwn(defaults,i.type);
	if(d.id==='reports.remove')return validPath(i.path)&&i.path.length>0;
	if(d.id==='reports.reorder')return validPath(i.parentPath)
		&&Number.isSafeInteger(i.fromIndex)&&i.fromIndex>=0
		&&Number.isSafeInteger(i.toIndex)&&i.toIndex>=0;
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
		const parent=nodeAt(p.root,i.parentPath);
		if(!parent||!defs[i.type].parents.includes(parent.type)
			||i.index>parent.children.length)throw new TypeError('COMMAND_REFUSED');
		// The shared Elements palette appends; reserve the anchored last Footer.
		const index=parent.type===TYPE?Math.min(i.index,parent.children.length-1):i.index;
		return insertNodeCommand(i.parentPath,index,{
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
	'reports.set':command('reports.set',(p,i)=>{
		const node=nodeAt(p.root,i.path);
		if(!node||!Object.hasOwn(defs[node.type].values,i.key)
			||!validValue(i.value,defs[node.type].values[i.key]))throw new TypeError('COMMAND_REFUSED');
		return setPropertyCommand(i.path,[i.key],i.value).apply(p);
	}),
});
export const resolveCommand=id=>Object.hasOwn(table,id)?table[id]:null;
export const componentsFor=()=>components;
export const propertiesFor=node=>Object.hasOwn(defs,node?.type)
	?Object.entries(defs[node.type].values).map(([key,spec])=>Object.freeze({
		key,label:key.replace(/([A-Z])/g,' $1').replace(/^./,c=>c.toUpperCase()),
		value:node.properties[key],multiline:key==='text',
		maxLength:typeof spec==='number'?spec:Math.max(...spec.map(v=>v.length)),
		options:Array.isArray(spec)?[...spec]:undefined,
	})):[];

export function createRenderAdapter({root,document:doc,profileId}) {
	if(!root||!doc||profileId!=='base/reports')throw new TypeError('HOST_MODULE_MISSING');
	let editor=null,container=null,destroyed=false;
	const el=(tag,cls,text='')=>{
		const node=doc.createElement(tag);node.className=cls;node.textContent=text;return node;
	};
	const renderNode=(node,path)=>{
		const section=el('section','cb-reports-platform__node cb-reports-platform__'+node.type.replace('reports.',''));
		section.tabIndex=0;
		section.dataset.cbDesignNodePath=JSON.stringify(path);
		section.dataset.cbDesignNodeType=node.type;
		if(node.properties.enabled==='off')section.classList.add('is-hidden-report');
		if(node.type==='reports.section')section.append(el('h2','cb-reports-platform__section-title',node.properties.title));
		else if(node.type==='reports.heading')section.append(el('h3','cb-reports-platform__heading',node.properties.text));
		else if(node.type==='reports.paragraph')section.append(el('p','cb-reports-platform__paragraph',node.properties.text));
		else if(node.type==='reports.metric'){
			const metric=el('dl','cb-reports-platform__metric');
			metric.append(el('dt','cb-reports-platform__metric-label',node.properties.label));
			metric.append(el('dd','cb-reports-platform__metric-value',node.properties.value));
			section.append(metric);
		}else if(node.type==='reports.header'){
			section.append(el('strong','cb-reports-platform__eyebrow','HEADER'));
		}else if(node.type==='reports.footer'){
			section.append(el('strong','cb-reports-platform__eyebrow','FOOTER'));
		}else{
			section.append(el('strong','cb-reports-platform__eyebrow',labels[node.type]));
			section.append(el('span','cb-reports-platform__dynamic',
				'Dynamic report data: '+labels[node.type]));
		}
		node.children.forEach((child,i)=>section.append(renderNode(child,[...path,i])));
		section.addEventListener('click',event=>{
			event.stopPropagation();
			if(!destroyed&&editor&&container?.isConnected)
				editor.select([{kind:'node',path}],{kind:'node',path});
		});
		return section;
	};
	const show=project=>{
		validateProject(project);
		const fields=editor.persistedFields();
		const documentView=el('article','cb-reports-platform__document');
		if(/^#[0-9a-fA-F]{6}$/.test(fields.accentColor||''))
			documentView.style.setProperty('--cb-report-accent',fields.accentColor);
		documentView.dataset.cbReportsDensity=fields.density||'comfortable';
		const brand=el('div','cb-reports-platform__branding',
			fields.providerName||'Core Blueprint');
		documentView.append(brand);
		documentView.append(el('h1','cb-reports-platform__title',project.root.properties.title));
		project.root.children.forEach((node,i)=>documentView.append(renderNode(node,[i])));
		container.replaceChildren(documentView);
	};
	return Object.freeze({
		contractVersion:1,
		mount({editor:session,signal}){
			if(destroyed||signal?.aborted||typeof session?.project!=='function')throw new TypeError('RENDER_STALE');
			editor=session;container=el('div','cb-reports-platform');root.append(container);
		},
		update({project,selection,signal}){
			if(destroyed||signal?.aborted||!container)throw new TypeError('RENDER_STALE');
			show(project);this.syncSelection({selection});
		},
		syncSelection({selection}){
			const key=selection?.primary?.kind==='node'?JSON.stringify(selection.primary.path):null;
			for(const node of container?.querySelectorAll('[data-cb-design-node-path]')||[]){
				const selected=node.dataset.cbDesignNodePath===key;
				node.classList.toggle('is-selected',selected);
				node.setAttribute('aria-selected',selected?'true':'false');
			}
		},
		captureFocus(){
			const target=doc.activeElement;
			return root.contains(target)?target.dataset.cbDesignNodePath||null:null;
		},
		restoreFocus(token){
			if(typeof token!=='string'||!container)return;
			for(const node of container.querySelectorAll('[data-cb-design-node-path]')){
				if(node.dataset.cbDesignNodePath===token){node.focus({preventScroll:true});break;}
			}
		},
		destroy(){destroyed=true;editor=null;container?.remove();container=null;},
	});
}
