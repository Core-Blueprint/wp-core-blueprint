import {
	insertNodeCommand, removeNodeCommand, reorderNodeCommand, setPropertyCommand,
} from '../core/commands.js';
import { nodeAt, normalizePath } from '../core/tree.js';

const PROVIDER = 'core.blueprint';
const TYPE = 'core.blueprint.mail';
const SCHEMA = 'base/mail/1';
const ROOT = TYPE;
const ALLOWED = Object.freeze({
	[TYPE]: Object.freeze({background: 32}),
	'mail.section': Object.freeze({title: 160}),
	'mail.text': Object.freeze({text: 100000}),
	'mail.button': Object.freeze({label: 160, href: 2048}),
	'mail.image': Object.freeze({src: 2048, alt: 255}),
});
const COMPONENTS = Object.freeze([
	Object.freeze({id: 'base/mail-section', type:'mail.section', label:'Section'}),
	Object.freeze({id: 'base/mail-text', type:'mail.text', label:'Text'}),
	Object.freeze({id: 'base/mail-button', type:'mail.button', label:'Button'}),
	Object.freeze({id: 'base/mail-image', type:'mail.image', label:'Image'}),
]);
const defaults = Object.freeze({
	'mail.section': Object.freeze({title:'New section'}),
	'mail.text': Object.freeze({text:'New text'}),
	'mail.button': Object.freeze({label:'Open link',href:''}),
	'mail.image': Object.freeze({src:'',alt:''}),
});
const pathOK = (path) => Array.isArray(path) && path.length <= 64
	&& path.every((i) => Number.isSafeInteger(i) && i >= 0);
const checkProps = (node) => node && typeof node === 'object'
	&& Object.hasOwn(ALLOWED, node.type) && node.provider === PROVIDER
	&& node.properties && typeof node.properties === 'object' && !Array.isArray(node.properties)
	&& Object.keys(node.properties).length === Object.keys(ALLOWED[node.type]).length
	&& Object.entries(ALLOWED[node.type]).every(([key,max]) =>
		typeof node.properties[key] === 'string' && [...node.properties[key]].length <= max);
const canParent = (parent, type) => type === 'mail.section'
	? [ROOT,'mail.section'].includes(parent?.type)
	: parent?.type === 'mail.section';
const validNode = (node, parent = null, depth = 0) => {
	if (!checkProps(node) || depth > 64 || !Array.isArray(node.children)
		|| (parent !== null && !canParent(parent, node.type))
		|| (node.type !== ROOT && node.type !== 'mail.section' && node.children.length)) return false;
	return node.children.every((child) => validNode(child, node, depth + 1));
};
export function validateProject(project) {
	if (!project || project.schema_version !== 1 || project.design_type !== TYPE
		|| !project.root || project.root.type !== ROOT || !validNode(project.root)
		|| Object.keys(project).length !== 3) throw new TypeError('SCHEMA_DOMAIN_INVALID');
	return true;
}
const acceptedInput = (definition, input) => {
	if (!input || typeof input !== 'object' || Array.isArray(input)) return false;
	if (definition.id === 'mail.insert') {
		return pathOK(input.parentPath) && Number.isSafeInteger(input.index)
			&& input.index >= 0 && typeof input.type === 'string'
			&& Object.hasOwn(defaults,input.type)
			&& Object.keys(input).length === 3;
	}
	if (definition.id === 'mail.remove') return pathOK(input.path)
		&& input.path.length > 0 && Object.keys(input).length === 1;
	if (definition.id === 'mail.reorder') return pathOK(input.parentPath)
		&& Number.isSafeInteger(input.fromIndex) && Number.isSafeInteger(input.toIndex)
		&& input.fromIndex >= 0 && input.toIndex >= 0 && Object.keys(input).length === 3;
	if (definition.id === 'mail.set') {
		return pathOK(input.path) && typeof input.key === 'string'
			&& typeof input.value === 'string'
			&& Object.keys(input).length === 3;
	}
	return false;
};
export const validateCommandInput = acceptedInput;
export const authorizeCommand = (definition, project, input, context) =>
	context?.resourceType === 'base.mail-template' && context.actorId > 0
	&& context.siteId > 0 && context.contextEpoch >= 0
	&& definition === resolveCommand(definition?.id)
	&& project?.design_type === TYPE && acceptedInput(definition,input);
const command = (id, canApply, apply) => Object.freeze({
	contractVersion: 1, id, canApply, apply,
});
const commandTable = Object.freeze({
	'mail.insert': command('mail.insert', (project,input) => {
		const parent = nodeAt(project.root,input.parentPath);
		return canParent(parent,input.type) && input.index <= parent.children.length;
	}, (project,input) => insertNodeCommand(input.parentPath,input.index,{
		type: input.type,provider:PROVIDER,properties:{...defaults[input.type]},children:[],
	}).apply(project)),
	'mail.remove': command('mail.remove', (project,input) =>
		!!nodeAt(project.root,input.path), (project,input) =>
		removeNodeCommand(input.path).apply(project)),
	'mail.reorder': command('mail.reorder', (project,input) => {
		const parent=nodeAt(project.root,input.parentPath);
		return !!parent && input.fromIndex < parent.children.length
			&& input.toIndex < parent.children.length;
	},(project,input)=>reorderNodeCommand(input.parentPath,input.fromIndex,input.toIndex).apply(project)),
	'mail.set': command('mail.set', (project,input) => {
		const node=nodeAt(project.root,input.path);
		return !!node && Object.hasOwn(ALLOWED[node.type],input.key)
			&& [...input.value].length<=ALLOWED[node.type][input.key];
	}, (project,input) => setPropertyCommand(input.path,[input.key],input.value).apply(project)),
});
export const resolveCommand = (id) => Object.hasOwn(commandTable,id) ? commandTable[id] : null;
export function componentsFor() { return COMPONENTS; }
export function propertiesFor(node) {
	if (!checkProps(node)) return [];
	return Object.keys(ALLOWED[node.type]).map((key)=>Object.freeze({
		key, label:key === 'href' ? 'Link URL' : key.charAt(0).toUpperCase()+key.slice(1),
		value:node.properties[key],multiline:key === 'text',maxLength:ALLOWED[node.type][key],
	}));
}
const safeHttpUrl = (value) => {
	try { const url=new URL(value,location.href);return /^https?:$/.test(url.protocol)?url.href:null; }
	catch { return null; }
};
/**
 * Domain-only DOM projection. The Host owns all render scheduling,
 * Selection, IME reconciliation and Save. No inner iframe/editor/history.
 */
export function createRenderAdapter({root,document:doc,profileId}) {
	if (!root || !doc || profileId !== 'base/mail') throw new TypeError('HOST_MODULE_MISSING');
	let editor=null, destroyed=false, selection=null;
	let container=null, pendingFocus=null;
	const make=(tag,cls,text='')=>{const el=doc.createElement(tag);if(cls)el.className=cls;el.textContent=text;return el;};
	const renderNode=(node,path)=>{
		const el=make('div','cb-mail-platform__node');
		el.dataset.cbDesignNodePath=JSON.stringify(path);
		el.dataset.cbDesignNodeType=node.type;
		el.tabIndex=0;
		if(node.type==='mail.section')el.classList.add('cb-mail-platform__section');
		const p=node.properties;
		if(node.type==='mail.section')el.append(make('h2','cb-mail-platform__section-label',p.title));
		if(node.type==='mail.text')el.append(make('p','cb-mail-platform__text',p.text));
		if(node.type==='mail.button'){
			const a=make('span','cb-mail-platform__button',p.label);
			if(safeHttpUrl(p.href))a.title=p.href;
			el.append(a);
		}
		if(node.type==='mail.image'){
			const url=safeHttpUrl(p.src);
			if(url){const img=doc.createElement('img');img.src=url;img.alt=p.alt;img.loading='lazy';el.append(img);}
			else el.append(make('span','cb-mail-platform__image-placeholder',p.alt||'Image'));
		}
		for(let i=0;i<node.children.length;i++)el.append(renderNode(node.children[i],[...path,i]));
		el.addEventListener('click',event=>{
			event.stopPropagation();
			if(!destroyed && editor && container?.isConnected) editor.select([{kind:'node',path}],{kind:'node',path});
		});
		return el;
	};
	const show=(project)=>{
		validateProject(project);
		const frag=doc.createDocumentFragment();
		const frame=make('article','cb-mail-platform__frame');
		frame.style.maxWidth='600px';
		frame.style.margin='0 auto';
		frame.style.backgroundColor=project.root.properties.background;
		const subject=editor?.persistedFields()?.subject;
		frame.append(make('header','cb-mail-platform__subject',typeof subject==='string'?subject:''));
		frame.append(renderNode(project.root,[]));
		frag.append(frame);
		container.replaceChildren(frag);
	};
	return Object.freeze({
		contractVersion:1,
		mount({editor:session,signal}){
			if(destroyed||signal?.aborted||typeof session?.project!=='function')throw new TypeError('RENDER_STALE');
			editor=session;container=make('div','cb-mail-platform');
			root.append(container);
		},
		update({project,selection:next,signal}){
			if(destroyed||signal?.aborted||!container)throw new TypeError('RENDER_STALE');
			show(project);selection=next;this.syncSelection({selection:next,reason:'command'});
		},
		syncSelection({selection:next}){
			selection=next;
			const key=next?.primary?.kind==='node'?JSON.stringify(next.primary.path):null;
			for(const node of container?.querySelectorAll('[data-cb-design-node-path]')||[]){
				const active=node.dataset.cbDesignNodePath===key;
				node.classList.toggle('is-selected',active);
				node.setAttribute('aria-selected',active?'true':'false');
			}
		},
		captureFocus(){const el=doc.activeElement;return root.contains(el)?el?.dataset?.cbDesignNodePath||null:null;},
		restoreFocus(token){
			if(typeof token!=='string'||!container)return;
			for(const node of container.querySelectorAll('[data-cb-design-node-path]')){
				if(node.dataset.cbDesignNodePath===token){node.focus({preventScroll:true});break;}
			}
		},
		destroy(){destroyed=true;editor=null;container?.remove();container=null;},
	});
}
