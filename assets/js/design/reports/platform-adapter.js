import {
	insertNodeCommand,removeNodeCommand,reorderNodeCommand,setPropertyCommand,
} from '../core/commands.js';
import {nodeAt} from '../core/tree.js';

const PROVIDER='core.blueprint';
const TYPE='core.blueprint.reports';
const MAX=Object.freeze({
	[TYPE]:Object.freeze({title:200}),
	'reports.section':Object.freeze({title:160}),
	'reports.heading':Object.freeze({text:500}),
	'reports.paragraph':Object.freeze({text:20000}),
	'reports.metric':Object.freeze({label:160,value:200}),
});
const DEFAULT=Object.freeze({
	'reports.section':Object.freeze({title:'New section'}),
	'reports.heading':Object.freeze({text:'Heading'}),
	'reports.paragraph':Object.freeze({text:'New paragraph'}),
	'reports.metric':Object.freeze({label:'Metric',value:'—'}),
});
const COMPONENTS=Object.freeze([
	Object.freeze({id:'base/reports-section',type:'reports.section',label:'Section'}),
	Object.freeze({id:'base/reports-heading',type:'reports.heading',label:'Heading'}),
	Object.freeze({id:'base/reports-paragraph',type:'reports.paragraph',label:'Paragraph'}),
	Object.freeze({id:'base/reports-metric',type:'reports.metric',label:'Metric'}),
]);
const validPath=p=>Array.isArray(p)&&p.length<=64&&p.every(i=>Number.isSafeInteger(i)&&i>=0);
const validParent=(parent,type)=>type==='reports.section'
	?[TYPE,'reports.section'].includes(parent?.type):parent?.type==='reports.section';
const validNode=(node,parent=null,depth=0)=>{
	if(!node||typeof node!=='object'||depth>64||node.provider!==PROVIDER
		||!Object.hasOwn(MAX,node.type)||!node.properties||Array.isArray(node.properties)
		||!Array.isArray(node.children)||Object.keys(node.properties).length!==Object.keys(MAX[node.type]).length
		||!Object.entries(MAX[node.type]).every(([key,max])=>
			typeof node.properties[key]==='string'&&[...node.properties[key]].length<=max)
		||(parent&&!validParent(parent,node.type))
		||(![TYPE,'reports.section'].includes(node.type)&&node.children.length))return false;
	return node.children.every(n=>validNode(n,node,depth+1));
};
export function validateProject(project){
	if(!project||project.schema_version!==1||project.design_type!==TYPE
		||Object.keys(project).length!==3||project.root?.type!==TYPE
		||!validNode(project.root))throw new TypeError('SCHEMA_DOMAIN_INVALID');
	return true;
}
const validate=(d,i)=>{
	if(!i||typeof i!=='object'||Array.isArray(i))return false;
	if(d.id==='reports.insert')return validPath(i.parentPath)&&Number.isSafeInteger(i.index)
		&&i.index>=0&&typeof i.type==='string'&&Object.hasOwn(DEFAULT,i.type)
		&&Object.keys(i).length===3;
	if(d.id==='reports.remove')return validPath(i.path)&&i.path.length>0&&Object.keys(i).length===1;
	if(d.id==='reports.reorder')return validPath(i.parentPath)
		&&Number.isSafeInteger(i.fromIndex)&&i.fromIndex>=0
		&&Number.isSafeInteger(i.toIndex)&&i.toIndex>=0&&Object.keys(i).length===3;
	if(d.id==='reports.set')return validPath(i.path)&&typeof i.key==='string'
		&&typeof i.value==='string'&&Object.keys(i).length===3;
	return false;
};
export const validateCommandInput=validate;
export const authorizeCommand=(d,p,i,c)=>c?.resourceType==='base.maintenance-report-design'
	&&c.resourceId==='maintenance'&&c.siteId>0&&c.actorId>0
	&&d===resolveCommand(d?.id)&&p?.design_type===TYPE&&validate(d,i);
const command=(id,canApply,apply)=>Object.freeze({contractVersion:1,id,canApply,apply});
const table=Object.freeze({
	'reports.insert':command('reports.insert',(p,i)=>{
		const parent=nodeAt(p.root,i.parentPath);
		return validParent(parent,i.type)&&i.index<=parent.children.length;
	},(p,i)=>insertNodeCommand(i.parentPath,i.index,{
		type:i.type,provider:PROVIDER,properties:{...DEFAULT[i.type]},children:[],
	}).apply(p)),
	'reports.remove':command('reports.remove',(p,i)=>!!nodeAt(p.root,i.path),
		(p,i)=>removeNodeCommand(i.path).apply(p)),
	'reports.reorder':command('reports.reorder',(p,i)=>{
		const parent=nodeAt(p.root,i.parentPath);
		return !!parent&&i.fromIndex<parent.children.length&&i.toIndex<parent.children.length;
	},(p,i)=>reorderNodeCommand(i.parentPath,i.fromIndex,i.toIndex).apply(p)),
	'reports.set':command('reports.set',(p,i)=>{
		const node=nodeAt(p.root,i.path);
		return !!node&&Object.hasOwn(MAX[node.type],i.key)
			&&[...i.value].length<=MAX[node.type][i.key];
	},(p,i)=>setPropertyCommand(i.path,[i.key],i.value).apply(p)),
});
export const resolveCommand=(id)=>Object.hasOwn(table,id)?table[id]:null;
export const componentsFor=()=>COMPONENTS;
export const propertiesFor=(node)=>Object.hasOwn(MAX,node?.type)
	?Object.keys(MAX[node.type]).map(key=>Object.freeze({
		key,label:key.charAt(0).toUpperCase()+key.slice(1),value:node.properties[key],
		multiline:key==='text',maxLength:MAX[node.type][key],
	})):[];

export function createRenderAdapter({root,document:doc,profileId}){
	if(!root||!doc||profileId!=='base/reports')throw new TypeError('HOST_MODULE_MISSING');
	let editor=null,container=null,destroyed=false;
	const el=(tag,cls,text='')=>{const node=doc.createElement(tag);node.className=cls;node.textContent=text;return node;};
	const renderNode=(node,path)=>{
		const section=el('div','cb-reports-platform__node');
		section.tabIndex=0;
		section.dataset.cbDesignNodePath=JSON.stringify(path);
		section.dataset.cbDesignNodeType=node.type;
		if(node.type==='reports.section')section.append(el('h2','cb-reports-platform__section-title',node.properties.title));
		if(node.type==='reports.heading')section.append(el('h3','cb-reports-platform__heading',node.properties.text));
		if(node.type==='reports.paragraph')section.append(el('p','cb-reports-platform__paragraph',node.properties.text));
		if(node.type==='reports.metric'){
			const metric=el('div','cb-reports-platform__metric');
			metric.append(el('dt','cb-reports-platform__metric-label',node.properties.label));
			metric.append(el('dd','cb-reports-platform__metric-value',node.properties.value));
			section.append(metric);
		}
		node.children.forEach((n,i)=>section.append(renderNode(n,[...path,i])));
		section.addEventListener('click',ev=>{
			ev.stopPropagation();
			if(!destroyed&&editor&&container?.isConnected)
				editor.select([{kind:'node',path}],{kind:'node',path});
		});
		return section;
	};
	const show=(project)=>{
		validateProject(project);
		const documentView=el('article','cb-reports-platform__document');
		const fields=editor.persistedFields();
		const branding=el('header','cb-reports-platform__branding');
		branding.append(el('span','cb-reports-platform__brand',String(fields.brandName||'')));
		if(typeof fields.accentColor==='string'&&/^#[0-9a-fA-F]{6}$/.test(fields.accentColor))
			documentView.style.setProperty('--cb-report-accent',fields.accentColor);
		documentView.append(branding);
		documentView.append(el('h1','cb-reports-platform__title',project.root.properties.title));
		documentView.append(renderNode(project.root,[]));
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
