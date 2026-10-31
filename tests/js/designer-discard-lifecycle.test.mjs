import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../assets/js/features/designer-exit-guard.js', import.meta.url), 'utf8');

const scenario = ({choice = 'discard', accept = true, outcome = true} = {}) => {
  let input = 'Test eee', baseline = 'Test', exits = 0, restores = 0;
  class Element {
    constructor() { this.dataset = {}; this.children = []; this.isConnected = true; this.checked = false; }
    append(...children) { this.children.push(...children); }
    hasAttribute() { return false; }
    setAttribute() {}
  }
  const walk = node => [node, ...(node.children || []).flatMap(walk)];
  const window = {
    addEventListener() {},
    cbCore: { toast: {error() {}, warning() {}}, modal: {
      show: async options => {
        if (!accept) return false;
        if (!options.onConfirm) return true;
        for (const element of walk(options.body)) {
          if (element.value === 'save' || element.value === 'discard') {
            element.checked = element.value === choice;
          }
        }
        return options.onConfirm();
      },
    }},
  };
  const document = {
    createElement: () => new Element(),
    createTextNode: text => ({text}),
    querySelector: () => null,
  };
  runInNewContext(source, {window, document, Element});
  const shell = new Element();
  window.cbCore.designerExitGuard.register(shell, {
    isDirty: () => input !== baseline,
    isUncertain: () => false,
    save: async () => { baseline = input; return true; },
    discard: () => {
      restores++;
      if (outcome !== true) return outcome;
      input = baseline;
      return true;
    },
  });
  return {
    request: () => window.cbCore.designerExitGuard.request(shell, () => { exits++; return true; }),
    get input() { return input; },
    get exits() { return exits; },
    get restores() { return restores; },
    setInput: value => { input = value; },
  };
};

test('DF-EXIT-01: Discard restores unsaved title before close and reopens without a reload', async () => {
  const editor = scenario();
  assert.equal(editor.input, 'Test eee');
  assert.equal(await editor.request(), true);
  assert.equal(editor.exits, 1);
  assert.equal(editor.restores, 1);
  // Manual launch keeps this exact DOM and consumer session mounted.
  assert.equal(editor.input, 'Test');
  editor.setInput('Test more');
  assert.equal(await editor.request(), true);
  assert.equal(editor.input, 'Test');
});

test('DF-EXIT-01: Cancel and failed restoration preserve editor state and prevent navigation', async () => {
  const cancelled = scenario({accept:false});
  assert.equal(await cancelled.request(), false);
  assert.equal(cancelled.input, 'Test eee');
  assert.equal(cancelled.restores, 0);
  const failed = scenario({outcome:false});
  assert.equal(await failed.request(), false);
  assert.equal(failed.input, 'Test eee');
  assert.equal(failed.exits, 0);
  const promise = scenario({outcome:Promise.resolve(true)});
  assert.equal(await promise.request(), false);
  assert.equal(promise.exits, 0);
});
