import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
const source = await readFile(new URL('../../assets/js/design/shell/state-history.js', import.meta.url), 'utf8');
const { createDesignerStateHistory } = await import('data:text/javascript;charset=utf-8,' + encodeURIComponent(source));

test('public state history snapshots isolate consumer data and bound undo', () => {
  const source = {steps: []};
  const observed = [];
  const history = createDesignerStateHistory({initialState: source, onApply: state => observed.push(state), limit: 2});
  source.steps.push('external');
  assert.deepEqual(history.snapshot(), {steps: []});
  assert.equal(history.record({steps: ['a']}), true);
  assert.equal(history.record({steps: ['a']}), false);
  history.record({steps: ['a','b']});
  history.record({steps: ['a','b','c']});
  assert.deepEqual(history.status(), {canUndo: true, canRedo: false, size: 2});
  assert.equal(history.undo(), true);
  assert.deepEqual(observed.at(-1), {steps: ['a','b']});
  assert.equal(history.undo(), true);
  assert.deepEqual(observed.at(-1), {steps: ['a']});
  assert.equal(history.undo(), false);
  assert.equal(history.redo(), true);
  history.record({steps: ['branch']});
  assert.equal(history.redo(), false);
});

test('history resets per context and fails closed on render error or disposal', () => {
  let reject = false;
  const h = createDesignerStateHistory({initialState:{id:1},onApply:() => {if(reject) throw Error('blocked');}});
  h.record({id:2});
  reject=true;
  assert.throws(()=>h.undo(),/blocked/);
  assert.deepEqual(h.snapshot(),{id:2});
  reject=false;
  h.reset({id:3},{current:{id:4}});
  h.undo();
  assert.deepEqual(h.snapshot(),{id:3});
  assert.equal(h.undo(),false);
  h.dispose();
  assert.throws(()=>h.record({id:5}),/disposed/);
});
