import {insertNodeCommand,removeNodeCommand,reorderNodeCommand,moveNodeCommand,setPropertyCommand} from '../core/commands.js';
import {nodeAt} from '../core/tree.js';
import {sanitizeMailMarkup,createMailRichTextControls} from './rich-text-controls.js';
const PROVIDER='core.blueprint', ROOT='core.blueprint.mail';
const FONT=['Arial, Helvetica, sans-serif','Helvetica, Arial, sans-serif','Georgia, Times New Roman, serif','Tahoma, Verdana, sans-serif','Verdana, Geneva, sans-serif'];
const col={type:'color',maxLength:32},align={type:'select',options:['left','center','right']};
const str=(maxLength)=>({type:'text',maxLength}),int=(min,max)=>({type:'number',min,max}),sel=(options)=>({type:'select',options});
const both=['mail.section','mail.column'];
const def=(parents,defaults,controls,label)=>Object.freeze({parents,defaults:Object.freeze(defaults),controls:Object.freeze(controls),label});
const TYPES=Object.freeze({
 [ROOT]:def([],{background:'#f3f4f6',contentBackground:'#ffffff',textColor:'#1f2937',accentColor:'#2563eb',fontFamily:FONT[0],width:600,padding:32},{background:col,contentBackground:col,textColor:col,accentColor:col,fontFamily:sel(FONT),width:int(320,800),padding:int(0,64)},'Mail settings'),
 'mail.section':def([ROOT],{background:'#ffffff',padding:32,spacing:0},{background:col,padding:int(0,64),spacing:int(0,64)},'Section'),
 'mail.heading':def(both,{text:'Your heading',fontSize:28,align:'left',color:'#1f2937',spacing:16},{text:str(10000),fontSize:int(16,48),align,color:col,spacing:int(0,48)},'Heading'),
 'mail.text':def(both,{text:'Write your message here.',fontSize:16,align:'left',color:'#1f2937',spacing:16},{text:str(100000),fontSize:int(12,28),align,color:col,spacing:int(0,48)},'Text'),
 'mail.rich-text':def(both,{markup:'<p>Write your message here.</p>',fontSize:16,align:'left',color:'#1f2937',spacing:16},{markup:{type:'rich-text',maxLength:100000},fontSize:int(12,28),align,color:col,spacing:int(0,48)},'Rich text'),
 'mail.button':def(both,{label:'Continue',url:'',align:'left',background:'#2563eb',color:'#ffffff',radius:6,spacing:20},{label:str(160),url:{type:'url',maxLength:2048},align,background:col,color:col,radius:int(0,32),spacing:int(0,48)},'Button'),
 'mail.image':def(both,{url:'',alt:'',width:560,align:'center',spacing:20},{url:{type:'url',maxLength:2048},alt:str(255),width:int(1,800),align,spacing:int(0,48)},'Image'),
 'mail.divider':def(both,{color:'#e5e7eb',spacing:20},{color:col,spacing:int(0,48)},'Divider'),
 'mail.spacer':def(both,{height:24},{height:int(0,120)},'Spacer'),
 'mail.columns':def(['mail.section'],{ratio:'equal',gap:16,spacing:20},{ratio:sel(['equal','left-wide','right-wide','left-third','right-third']),gap:int(0,40),spacing:int(0,48)},'Two columns'),
 'mail.column':def(['mail.columns'],{background:'#ffffff',padding:0},{background:col,padding:int(0,40)},'Column'),
});
const has=(o,k)=>!!o&&Object.prototype.hasOwnProperty.call(o,k);
const PALETTE=Object.keys(TYPES).filter(t=>t!==ROOT&&t!=='mail.column');
const COMPONENTS=Object.freeze(PALETTE.map(type=>Object.freeze({id:'base/'+type.replace('.','-'),type,label:TYPES[type].label})));
const valueOK=(v,c)=>{
 if(c.type==='number')return Number.isSafeInteger(v)&&v>=c.min&&v<=c.max;
 if(typeof v!=='string'||[...v].length>(c.maxLength??128))return false;
 if(c.type==='select')return c.options.includes(v);
 if(c.type==='color')return /^#[a-fA-F0-9]{6}$/.test(v);
 if(c.type==='url')return v===''||/^https?:\/\/\S+$/i.test(v)||/^\{\{[a-z][a-z0-9._-]*\}\}$/.test(v);
 return true;
};
const propsOK=(type,p)=>!!TYPES[type]&&!!p&&typeof p==='object'&&!Array.isArray(p)
 &&Object.keys(p).length===Object.keys(TYPES[type].controls).length
 &&Object.entries(TYPES[type].controls).every(([k,c])=>has(p,k)&&valueOK(p[k],c));
const parentOK=(p,t)=>!!TYPES[t]&&TYPES[t].parents.includes(p?.type);
const validNode=(n,parent=null,depth=0,counter={count:0})=>!!n&&depth<=64&&++counter.count<=5000&&has(TYPES,n.type)&&n.provider==='core.blueprint'
 &&propsOK(n.type,n.properties)&&Array.isArray(n.children)
 &&(parent===null||parentOK(parent,n.type))
 &&(n.type!=='mail.columns'||(n.children.length===2&&n.children.every(c=>c?.type==='mail.column')))
 &&([ROOT,'mail.section','mail.columns','mail.column'].includes(n.type)||n.children.length===0)
 &&n.children.every(c=>validNode(c,n,depth+1,counter));
export function validateProject(p){
 if(!p||Object.keys(p).length!==3||p.schema_version!==1||p.design_type!==ROOT
  ||p.root?.type!==ROOT||!validNode(p.root))throw new TypeError('SCHEMA_DOMAIN_INVALID');
 return true;
}
const pathOK=p=>Array.isArray(p)&&p.length<=64&&p.every(x=>Number.isSafeInteger(x)&&x>=0);
const node=t=>{
 const d=TYPES[t];if(!d||t===ROOT)return null;
 return {type:t,provider:PROVIDER,properties:{...d.defaults},
  children:t==='mail.columns'?[node('mail.column'),node('mail.column')]:[]};
};
const inputOK=(d,i)=>{
 if(!i||typeof i!=='object'||Array.isArray(i))return false;
 switch(d?.id){
 case 'mail.insert':return pathOK(i.parentPath)&&Number.isSafeInteger(i.index)&&i.index>=0&&PALETTE.includes(i.type)&&Object.keys(i).length===3;
 case 'mail.remove':return pathOK(i.path)&&i.path.length>0&&Object.keys(i).length===1;
 case 'mail.reorder':return pathOK(i.parentPath)&&Number.isSafeInteger(i.fromIndex)&&Number.isSafeInteger(i.toIndex)&&i.fromIndex>=0&&i.toIndex>=0&&Object.keys(i).length===3;
 case 'mail.move':return pathOK(i.fromPath)&&i.fromPath.length>0&&pathOK(i.parentPath)
  &&Number.isSafeInteger(i.index)&&i.index>=0&&Object.keys(i).length===3;
 case 'mail.set':return pathOK(i.path)&&typeof i.key==='string'&&Object.keys(i).length===3;
 default:return false;
 }};
export const validateCommandInput=inputOK;
export const authorizeCommand=(d,p,i,c)=>c?.resourceType==='base.mail-template'
 &&Number.isSafeInteger(c.actorId)&&c.actorId>0&&Number.isSafeInteger(c.siteId)&&c.siteId>0
 &&Number.isSafeInteger(c.contextEpoch)&&c.contextEpoch>=0
 &&d===resolveCommand(d?.id)&&p?.design_type===ROOT&&inputOK(d,i);
const cmd=(id,canApply,apply)=>Object.freeze({contractVersion:1,id,canApply,apply});
const CMDS=Object.freeze({
 'mail.insert':cmd('mail.insert',(p,i)=>{
  const at=nodeAt(p.root,i.parentPath);return parentOK(at,i.type)&&i.index<=at.children.length;
 },(p,i)=>insertNodeCommand(i.parentPath,i.index,node(i.type)).apply(p)),
 'mail.remove':cmd('mail.remove',(p,i)=>{
  const at=nodeAt(p.root,i.path);return !!at&&at.type!=='mail.column';
 },(p,i)=>removeNodeCommand(i.path).apply(p)),
 'mail.reorder':cmd('mail.reorder',(p,i)=>{
  const at=nodeAt(p.root,i.parentPath);return !!at&&at.type!=='mail.columns'
   &&i.fromIndex<at.children.length&&i.toIndex<at.children.length;
 },(p,i)=>reorderNodeCommand(i.parentPath,i.fromIndex,i.toIndex).apply(p)),
 'mail.move':cmd('mail.move',(p,i)=>{
  const moving=nodeAt(p.root,i.fromPath);
  if(!moving||moving.type==='mail.column')return false;
  try{validateProject(moveNodeCommand(i.fromPath,i.parentPath,i.index).apply(p).project);return true;}
  catch{return false;}
 },(p,i)=>moveNodeCommand(i.fromPath,i.parentPath,i.index).apply(p)),
 'mail.set':cmd('mail.set',(p,i)=>{
  const at=nodeAt(p.root,i.path);return !!at&&has(TYPES[at.type]?.controls,i.key)
   &&valueOK(i.value,TYPES[at.type].controls[i.key]);
 },(p,i)=>setPropertyCommand(i.path,[i.key],i.value).apply(p)),
});
/**
 * Domain permissions only: the shared Base Layers primitive renders actions
 * and dispatches the registered commands. Mail has no persisted visibility
 * property, so this profile does not advertise Hide/Show.
 */
export const layerPolicyFor = (node, parent, index) => {
	const hasParent = !!parent;
	const fixedColumn = node?.type === 'mail.column';
	const reorderParent = hasParent && parent.type !== 'mail.columns';
	const maxIndex = hasParent ? parent.children.length - 1 : -1;
	return Object.freeze({
		remove: hasParent && !fixedColumn,
		reorder: reorderParent && !fixedColumn && Number.isSafeInteger(index)
			? Object.freeze({minIndex: 0, maxIndex}) : null,
		visibility: null,
	});
};

export const resolveCommand=id=>has(CMDS,id)?CMDS[id]:null;
export const componentsFor=()=>COMPONENTS;
export const propertiesFor=n=>!propsOK(n?.type,n?.properties)?[]:
 Object.entries(TYPES[n.type].controls).map(([key,c])=>Object.freeze({
  key,label:key.replace(/([A-Z])/g,' $1').replace(/^./,x=>x.toUpperCase()),
  type:c.type,value:n.properties[key],options:c.options||null,min:c.min,max:c.max,
  maxLength:c.maxLength,multiline:['text','markup'].includes(key),
 }));
const url=(v)=>{try{if(!v||v.includes('{{'))return null;const u=new URL(v);
 return ['http:','https:'].includes(u.protocol)?u.href:null;}catch{return null;}};
export function createRenderAdapter({root,document:doc,profileId}){
 if(!root||!doc||profileId!=='base/mail')throw new TypeError('HOST_MODULE_MISSING');
 let editor=null,container=null,destroyed=false,editing=null;
 const el=(tag,cl='',t='')=>{const e=doc.createElement(tag);e.className=cl;e.textContent=t;return e;};
 const draw=(n,path)=>{
  const p=n.properties,e=el('div','cb-mail-platform__node');
  e.dataset.cbDesignNodePath=JSON.stringify(path);e.dataset.cbDesignNodeType=n.type;e.tabIndex=0;
  e.style.position='relative';
  if(path.length&&n.type!=='mail.column'){
   const handle=el('button','cb-mail-platform__drag-handle','⋮⋮');
   handle.type='button';handle.hidden=true;
   handle.dataset.cbDesignNodeDragHandle=JSON.stringify(path);
   handle.dataset.cbDesignNodeType=n.type;
   handle.setAttribute('aria-label','Move '+n.type.replace('mail.','')+' element');
   handle.addEventListener('click',ev=>ev.stopPropagation());
   handle.addEventListener('dblclick',ev=>ev.stopPropagation());
   e.prepend(handle);
  }
  e.style.boxSizing='border-box';
  if(n.type==='mail.section'){e.classList.add('cb-mail-platform__section');e.style.background=p.background;e.style.padding=p.padding+'px';e.style.marginBottom=p.spacing+'px';}
  else if(n.type==='mail.columns'){e.classList.add('cb-mail-platform__columns');e.style.display='grid';
   e.style.gridTemplateColumns=({'equal':'1fr 1fr','left-wide':'3fr 2fr','right-wide':'2fr 3fr','left-third':'1fr 2fr','right-third':'2fr 1fr'})[p.ratio];
   e.style.gap=p.gap+'px';e.style.marginBottom=p.spacing+'px';}
  else if(n.type==='mail.column'){e.classList.add('cb-mail-platform__column');e.style.background=p.background;e.style.padding=p.padding+'px';}
  else if(['mail.heading','mail.text','mail.rich-text'].includes(n.type)){
   const child=el(n.type==='mail.heading'?'h2':'div','cb-mail-platform__content');
   child.style.fontSize=p.fontSize+'px';child.style.color=p.color;child.style.textAlign=p.align;
   child.style.marginBottom=p.spacing+'px';child.style.whiteSpace='pre-wrap';
   if(n.type==='mail.rich-text')child.replaceChildren(sanitizeMailMarkup(p.markup,doc));
   else child.textContent=p.text;e.append(child);
  }else if(n.type==='mail.button'){const a=el('span','cb-mail-platform__button',p.label);
   a.style.display='inline-block';a.style.padding='12px 20px';a.style.background=p.background;
   a.style.color=p.color;a.style.borderRadius=p.radius+'px';
   if(url(p.url))a.title=p.url;e.style.textAlign=p.align;e.style.marginBottom=p.spacing+'px';e.append(a);
  }else if(n.type==='mail.image'){const u=url(p.url);
   if(u){const img=el('img');img.src=u;img.alt=p.alt;img.loading='lazy';img.style.maxWidth='100%';img.style.width=p.width+'px';e.append(img);}
   else e.append(el('span','cb-mail-platform__image-placeholder',p.alt||'Image'));
   e.style.textAlign=p.align;e.style.marginBottom=p.spacing+'px';
  }else if(n.type==='mail.divider'){const hr=el('hr');hr.style.border=0;hr.style.borderTop='1px solid '+p.color;
   hr.style.margin=p.spacing+'px 0';e.append(hr);
  }else if(n.type==='mail.spacer')e.style.height=p.height+'px';

  if(['mail.heading','mail.text','mail.rich-text'].includes(n.type)){
   const openEdit=ev=>{
    ev.preventDefault();ev.stopPropagation();
    if(destroyed||!editor||editing)return;
    const key=n.type==='mail.rich-text'?'markup':'text',display=e.querySelector('.cb-mail-platform__content');
    if(!display)return;
    editing={path:[...path],key,display,host:e};
    display.hidden=true;
    if(key==='markup'){
     const controls=createMailRichTextControls({documentRef:doc,value:p.markup,
      onCommit:value=>{
       if(!editor||destroyed)return false;
       try{if(value!==p.markup)editor.execute('mail.set',{path:[...path],key,value});return true;}
       catch{return false;}
      }});
     editing.controls=controls;
     const apply=el('button','button','Apply formatting');apply.type='button';
     apply.addEventListener('click',()=>{if(controls.commit()){editing=null;display.hidden=false;controls.element.remove();apply.remove();}});
     e.append(controls.element,apply);controls.editor.focus();
    }else{
     const field=el('textarea','cb-mail-platform__inline-text');
     field.value=p.text;field.rows=n.type==='mail.heading'?2:5;
     field.setAttribute('aria-label','Edit Mail text');
     const commit=()=>{
      const value=field.value.replace(/\r\n?/g,'\n'),rule=TYPES[n.type].controls.text;
      if(!valueOK(value,rule))return;
      try{
       if(value!==p.text)editor.execute('mail.set',{path:[...path],key,value});
       editing=null;display.hidden=false;field.remove();
      }catch{field.setAttribute('aria-invalid','true');}
     };
     field.addEventListener('keydown',event=>{
      if(event.key==='Escape'){event.preventDefault();editing=null;display.hidden=false;field.remove();}
      if(event.key==='Enter'&&(event.ctrlKey||event.metaKey)){event.preventDefault();commit();}
     });
     field.addEventListener('blur',commit);e.append(field);field.focus();
    }
   };
   e.addEventListener('dblclick',openEdit);
   e.addEventListener('keydown',ev=>{if(ev.key==='F2')openEdit(ev);});
  }
  n.children.forEach((c,i)=>e.append(draw(c,[...path,i])));
  e.addEventListener('click',ev=>{ev.stopPropagation();
   if(!destroyed&&editor&&container?.isConnected)editor.select([{kind:'node',path}],{kind:'node',path});});
  return e;
 };
 const sync=({selection})=>{
  const key=selection?.primary?.kind==='node'?JSON.stringify(selection.primary.path):null;
  for(const n of container?.querySelectorAll('[data-cb-design-node-path]')||[]){
   const active=n.dataset.cbDesignNodePath===key;n.classList.toggle('is-selected',active);
   n.setAttribute('aria-selected',active?'true':'false');
   const handle=n.querySelector(':scope > [data-cb-design-node-drag-handle]');
   if(handle)handle.hidden=!active;
  }
 };
 return Object.freeze({
  contractVersion:1,
  mount({editor:session,signal}){if(destroyed||signal?.aborted||typeof session?.project!=='function')throw new TypeError('RENDER_STALE');
   editor=session;container=el('div','cb-mail-platform');root.append(container);},
  update({project,selection,signal}){if(destroyed||signal?.aborted||!container)throw new TypeError('RENDER_STALE');
   editing=null;
   validateProject(project);const p=project.root.properties;
   const frame=el('article','cb-mail-platform__frame');
   frame.style.maxWidth=p.width+'px';frame.style.margin='0 auto';frame.style.background=p.contentBackground;
   frame.style.color=p.textColor;frame.style.fontFamily=p.fontFamily;
   const sub=editor?.persistedFields()?.subject;frame.append(el('header','cb-mail-platform__subject',typeof sub==='string'?sub:''));
   frame.append(draw(project.root,[]));container.style.background=p.background;container.replaceChildren(frame);sync({selection});},
  syncSelection:sync,
  captureFocus(){const a=doc.activeElement;return root.contains(a)?a?.dataset?.cbDesignNodePath||null:null;},
  restoreFocus(token){if(typeof token!=='string'||!container)return;
   for(const n of container.querySelectorAll('[data-cb-design-node-path]'))if(n.dataset.cbDesignNodePath===token){n.focus({preventScroll:true});break;}},
  destroy(){destroyed=true;editing=null;editor=null;container?.remove();container=null;},
 });
}
