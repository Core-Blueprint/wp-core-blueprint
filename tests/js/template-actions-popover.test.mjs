import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
    new URL('../../assets/js/core/template-actions.js', import.meta.url), 'utf8'
);
const css = readFileSync(
    new URL('../../assets/css/components/template-library.css', import.meta.url), 'utf8'
);
const iconRegistry = readFileSync(
    new URL('../../src/UI/Icon.php', import.meta.url), 'utf8'
);

function environment({ native = true, viewport = {width:600,height:420} } = {}) {
    const docEvents = new Map(), winEvents = new Map();
    let document;
    class Element {
        constructor(tag) {
            this.tagName = tag.toUpperCase();
            this.children = [];
            this.parentNode = null;
            this.attributes = {};
            this.events = new Map();
            this.className = '';
            this.hidden = false;
            this.disabled = false;
            this.isConnected = true;
            this.open = false;
            this.style = {};
            this.scrollHeight = 168;
            this.textContent = '';
            this.value = '';
            this.box = null;
            if (native) {
                this.showPopover = () => { this.open = true; };
                this.hidePopover = () => { this.open = false; };
            }
        }
        append(...nodes) {
            for (const node of nodes) {
                if (node.parentNode) {
                    node.parentNode.children.splice(node.parentNode.children.indexOf(node), 1);
                }
                node.parentNode = this;
                this.children.push(node);
            }
        }
        setAttribute(key,value) { this.attributes[key] = String(value); }
        removeAttribute(key) { delete this.attributes[key]; }
        addEventListener(key,callback) { this.events.set(key, callback); }
        removeEventListener(key,callback) { if (this.events.get(key) === callback) this.events.delete(key); }
        contains(target) { return this === target || this.children.some(child => child.contains(target)); }
        closest(selector) {
            let node = this.parentNode;
            while (node) {
                if (selector === 'dialog' && node.tagName === 'DIALOG') return node;
                if (selector === '[dir="rtl"]' && node.attributes.dir === 'rtl') return node;
                if (selector === '.cb-template-browser__results-viewport'
                    && node.className.includes('cb-template-browser__results-viewport')) return node;
                node = node.parentNode;
            }
            return null;
        }
        matches(selector) { return selector === ':popover-open' && this.open; }
        getBoundingClientRect() {
            return this.box || {left:0,top:0,right:220,bottom:168,width:220,height:168};
        }
        querySelectorAll(selector) {
            if (selector !== '[role="menuitem"]') return [];
            return this.children.filter(child => child.attributes.role === 'menuitem');
        }
        focus() { document.activeElement = this; }
        click() { return this.events.get('click')?.({target:this}); }
        remove() { this.isConnected = false; }
    }
    document = {
        body: new Element('body'), activeElement:null,
        createElement:tag=>new Element(tag),
        addEventListener:(name,callback)=>docEvents.set(name,callback),
        removeEventListener:(name,callback)=>{
            if (docEvents.get(name) === callback) docEvents.delete(name);
        },
    };
    const window = {
        location:{origin:'https://example.test',href:'https://example.test/wp-admin/'},
        innerWidth:viewport.width,innerHeight:viewport.height,
        addEventListener:(name,callback)=>winEvents.set(name,callback),
        removeEventListener:(name,callback)=>{
            if (winEvents.get(name) === callback) winEvents.delete(name);
        },
    };
    const sandbox = {document,window,URL,Blob,TextEncoder,setTimeout,
        fetch:async()=>({ok:false,json:async()=>({status:'refused'})})};
    const executable = source
        .replace("import { show as showModal } from '@cb-core/modal';",
            'const showModal = async () => false;')
        .replace("import { create as createIcon } from '@cb-core/icon';",
            "const createIcon = () => document.createElement('svg');")
        .replace('export function createActionMenu','function createActionMenu') +
        '\nglobalThis.makeMenu = createActionMenu;';
    runInNewContext(executable,sandbox,{timeout:1000});
    const item = {id:'saved.one',version:'1',source:'mail',title:'Saved Mail',
        actions:['rename','duplicate','export-json','delete']};
    const options = {
        restRoot:'/wp-json/core-blueprint/v1/template-library/', nonce:'nonce',
        contextToken:'signed', globalMode:false,label:(_key,fallback)=>fallback,
        isCurrent:()=>true,onOpening:()=>{},onNotice:()=>{},onChanged:async()=>{},
    };
    const dialog = new Element('dialog');
    dialog.box = {left:8,top:8,right:592,bottom:412,width:584,height:404};
    document.body.append(dialog);
    const scroller = new Element('div');
    scroller.className = 'cb-template-browser__results-viewport';
    scroller.box = {left:240,top:80,right:592,bottom:390,width:352,height:310};
    dialog.append(scroller);
    const card = new Element('article');
    scroller.append(card);
    const menu = sandbox.makeMenu(item,options);
    card.append(menu.node);
    const trigger = menu.node.children[0];
    const panel = menu.node.children[1];
    trigger.box = {left:540,top:342,right:576,bottom:378,width:36,height:36};
    return {menu,trigger,panel,card,dialog,scroller,document,window,docEvents,winEvents};
}

test('TL-M2 R2: icon-only upper-end trigger and radio upper-start are canonical', () => {
    assert.match(iconRegistry, /'ellipsis'\s*=>\s*\[/);
    assert.match(css, /\.cb-template-browser__choice\s*\{[^}]*inset-inline-start:\s*12px;/);
    assert.match(css, /\.cb-template-browser__actions\s*\{[^}]*position:\s*absolute;[^}]*inset-inline-end:\s*12px;/);
    assert.match(css, /\.cb-template-browser__action-panel\s*\{[^}]*position:\s*fixed;/);
    const e = environment();
    assert.equal(e.trigger.textContent, '');
    assert.equal(e.trigger.children[0].tagName,'SVG');
    assert.equal(e.trigger.attributes['aria-haspopup'],'menu');
    assert.match(e.trigger.attributes['aria-label'],/Actions: Saved Mail/);
    assert.equal(e.panel.attributes.role,'menu');
});

test('TL-M2 R2: native popover escapes clipped card, flips above and keeps geometry stable', () => {
    const e = environment({native:true});
    const count = e.card.children.length;
    e.trigger.click();
    assert.equal(e.panel.open,true);
    assert.equal(e.panel.parentNode,e.menu.node,'native top-layer popover retains ownership');
    assert.equal(e.card.children.length,count,'popover never adds grid rows');
    assert.equal(e.trigger.attributes['aria-expanded'],'true');
    assert.equal(e.panel.style.width,'220px');
    assert.ok(Number.parseFloat(e.panel.style.left) >= 8);
    assert.ok(Number.parseFloat(e.panel.style.top) < 342,'menu flips above the trigger');
    assert.equal(e.winEvents.has('scroll'),true);
    e.trigger.click();
    assert.equal(e.panel.open,false);
    assert.equal(e.panel.hidden,true);
    assert.equal(e.winEvents.has('scroll'),false);
    assert.equal(e.trigger.attributes['aria-expanded'],'false');
});

test('TL-M2 R2: non-native fallback portals to dialog, not the clipped card', () => {
    const e = environment({native:false});
    e.trigger.click();
    assert.equal(e.panel.parentNode,e.dialog);
    assert.equal(e.card.children.length,1,'fallback also keeps the card height stable');
    assert.equal(e.panel.hidden,false);
    assert.ok(Number.parseFloat(e.panel.style.left) >= 8);
    e.winEvents.get('scroll')();
    assert.equal(e.panel.parentNode,e.dialog);
    e.trigger.click();
    assert.equal(e.panel.parentNode,e.menu.node,'fallback restores DOM ownership on close');
    assert.equal(e.panel.hidden,true);
    assert.equal(e.docEvents.size,0,'all outside and keyboard listeners removed');
    e.menu.dispose();
});

test('TL-M2 R2: keyboard menu, viewport scroll, outside focus and disposal clean up', () => {
    const e = environment();
    e.trigger.events.get('keydown')({key:'ArrowDown',preventDefault(){}});
    assert.equal(e.document.activeElement,e.panel.children[0]);
    e.panel.events.get('keydown')({key:'End',preventDefault(){}});
    assert.equal(e.document.activeElement,e.panel.children[3]);
    e.docEvents.get('keydown')({key:'Escape',preventDefault(){},stopPropagation(){}});
    assert.equal(e.document.activeElement,e.trigger);
    assert.equal(e.panel.hidden,true);
    e.trigger.click();
    e.trigger.box = {left:540,top:-70,right:576,bottom:-34,width:36,height:36};
    e.winEvents.get('scroll')();
    assert.equal(e.panel.hidden,true,'stale scrolled-out anchor closes');
    e.trigger.box = {left:540,top:170,right:576,bottom:206,width:36,height:36};
    e.trigger.click();
    e.docEvents.get('focusin')({target:e.document.body});
    assert.equal(e.panel.hidden,true,'focus outside closes the popover');
    e.trigger.click();
    e.menu.dispose();
    assert.equal(e.docEvents.size,0);
    assert.equal(e.winEvents.size,0);
});
