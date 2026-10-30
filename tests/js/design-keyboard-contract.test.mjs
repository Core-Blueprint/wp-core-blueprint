import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
const mail = readFileSync(new URL('../../assets/js/design/mail/platform-adapter.js', import.meta.url),'utf8');
const reports = readFileSync(new URL('../../assets/js/design/reports/platform-adapter.js', import.meta.url),'utf8');
// Evaluate only the original adapter handler bodies, as the existing DF-D4A
// source regression does, with bounded DOM event doubles.
const take = (source,start,end) => {
  const a=source.indexOf(start),b=source.indexOf(end,a+start.length);
  assert.ok(a>=0&&b>a,'expected existing handler');
  return source.slice(a,b+end.length);
};
const mailSelection=take(mail,"  e.addEventListener('keydown',ev=>{\n","\n  });");
const reportsSelection=take(reports,
  "\t\t\t\thit.addEventListener('keydown', event => {",
  "\n\t\t\t\t});");
const f2Code="   e.addEventListener('keydown',ev=>{if(ev.key==='F2')openEdit(ev);});";
assert.ok(mail.includes(f2Code),'Mail F2 editing remains');
class Target {
  constructor(){this.listeners=[];}
  addEventListener(type,fn){if(type==='keydown')this.listeners.push(fn);}
  dispatch(ev){for(const fn of this.listeners)fn(ev);}
}
const key=(target,value,extra={})=>({
  target,key:value,defaultPrevented:false,
  preventDefault(){this.defaultPrevented=true;},
  stopPropagation(){this.stopped=true;},...extra,
});
const makeHost=()=>{
  let dragging=false;
  return {setDragging(value){dragging=value;},get dragging(){return dragging;},
    root:{closest:sel=>sel==='[data-cb-design-dnd-active="true"]'&&dragging?{}:null}};
};
const mailFixture=()=>{
  const e=new Target(),host=makeHost(),selected=[],edited=[];
  const editor={select(refs,primary){selected.push({refs,primary});}};
  new Function('e','root','editor','container','destroyed','path',mailSelection)(
    e,host.root,editor,{isConnected:true},false,[1,0]);
  new Function('e','openEdit',f2Code)(e,ev=>edited.push(ev.key));
  return {target:e,host,selected,edited};
};
const reportsFixture=()=>{
  const hit=new Target(),host=makeHost(),selected=[];
  new Function('hit','root','onActivate','region',reportsSelection)(
    hit,host.root,id=>selected.push(id),{id:'r1'});
  return {target:hit,host,selected};
};
test('DF-D4 Mail keyboard selection: Enter/Space, nested target, F2 and editing controls',()=>{
  const f=mailFixture();
  for(const name of ['Enter',' ']){
    const ev=key(f.target,name);f.target.dispatch(ev);
    assert.equal(ev.defaultPrevented,true);
    assert.deepEqual(f.selected.at(-1).primary,{kind:'node',path:[1,0]});
  }
  assert.equal(f.selected.length,2);
  f.target.dispatch(key({},'Enter')); // Bubbled from nested canvas node
  f.target.dispatch(key({},' '));     // Bubbled from editing control
  assert.equal(f.selected.length,2);
  f.target.dispatch(key(f.target,'F2'));
  assert.deepEqual(f.edited,['F2']);
  assert.equal(f.selected.length,2);
});
test('DF-D4 Reports keyboard selection: Enter/Space, not nested/modifier keys',()=>{
  const f=reportsFixture();
  for(const name of ['Enter',' ']) {
    const ev=key(f.target,name);f.target.dispatch(ev);
    assert.equal(ev.defaultPrevented,true);
  }
  assert.deepEqual(f.selected,['r1','r1']);
  f.target.dispatch(key({},'Enter'));
  f.target.dispatch(key(f.target,'Enter',{ctrlKey:true}));
  assert.equal(f.selected.length,2);
});
test('DF-D4 keyboard DND Enter/Space: adapter-first and DND-first avoid selection',()=>{
  for(const fixture of [mailFixture,reportsFixture])
    for(const order of ['adapter-first','dnd-first'])
      for(const name of ['Enter',' ']){
        const f=fixture(),ev=key(f.target,name);
        f.host.setDragging(true);
        let drops=0;
        const base=()=>{drops++;ev.preventDefault();f.host.setDragging(false);};
        if(order==='adapter-first'){f.target.dispatch(ev);base();}
        else {base();f.target.dispatch(ev);}
        assert.equal(drops,1);
        assert.equal(f.selected.length,0,order+' '+name+' no selection');
        assert.equal(ev.defaultPrevented,true);
      }
});
