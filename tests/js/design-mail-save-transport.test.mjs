/**
 * Execute the production Mail AJAX transport with a minimal event/DOM boundary.
 * Offline Level 1: verifies order, receipts and non-destructive failure paths.
 * WordPress save/reopen, option CAS and installed browser parity remain Level 2.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
  new URL('../../assets/js/features/mail-designer-save.js', import.meta.url), 'utf8',
);
const tick = () => new Promise((resolve) => setImmediate(resolve));

const fixture = ({ fetchImpl, acceptAck = true, capture = true } = {}) => {
  const events = [];
  const inputs = new Map();
  const listeners = {};
  const checkpoint = Object.freeze({
    submittedProject: Object.freeze({ root: Object.freeze({ type: 'mail.root' }) }),
  });
  const savedCapture = Object.freeze({
    checkpoint, templateId: 'core.welcome', subject: 'Welcome',
    revision: '12', epoch: 3,
  });
  const form = {
    addEventListener(type, callback) { listeners[type] = callback; },
  };
  const shell = { dispatchEvent(event) { events.push([event.type, event.detail.state]); } };
  const root = {
    dataset: { ajaxUrl: '/wp-admin/admin-ajax.php' },
    querySelector(selector) {
      if (selector === '[data-cb-mail-designer-form]') return form;
      if (selector === '[data-cb-design-shell]') return shell;
      return null;
    },
    dispatchEvent(event) {
      events.push([event.type, event.detail]);
      if (event.type === 'cb:mail:savecapture' && capture) event.detail.accept(savedCapture);
      if (event.type === 'cb:mail:saveack' && acceptAck) event.detail.accept();
    },
  };
  const local = {
    document: { querySelector(selector) {
      return selector === '[data-cb-mail-designer]' ? root : null;
    } },
    window: { addEventListener(type, callback) { listeners['window:' + type] = callback; } },
    CustomEvent: class {
      constructor(type, options) { this.type = type; this.detail = options.detail; }
    },
    FormData: class {
      constructor(input) { assert.equal(input, form); this.fields = new Map(inputs); }
      set(key, value) { this.fields.set(key, value); }
      get(key) { return this.fields.get(key); }
    },
    AbortController,
    fetch: fetchImpl || (async () => ({
      ok: true,
      json: async () => ({
        success: true,
        data: { template_id: 'core.welcome', revision: '13',
          document: { subject: 'Welcome', project: checkpoint.submittedProject } },
      }),
    })),
  };
  runInNewContext(source, local, { timeout: 1000 });
  return {
    events, inputs, checkpoint,
    submit() {
      assert.equal(typeof listeners.submit, 'function', 'submit listener installed');
      const event = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
      listeners.submit(event);
      assert.equal(event.defaultPrevented, true);
    },
    pagehide() { listeners['window:pagehide'](); },
    states() { return events.filter(([type]) => type === 'cb:design-shell:savechange').map(([, state]) => state); },
    captured() { return events.filter(([type]) => type === 'cb:mail:savecapture').length; },
    acknowledged() { return events.filter(([type]) => type === 'cb:mail:saveack').length; },
    failed() { return events.filter(([type]) => type === 'cb:mail:savefailed').length; },
  };
};

test('Mail transport submits checkpoint values and reports saved only after accepted acknowledgement', async () => {
  let posted;
  const f = fixture({ fetchImpl: async (url, request) => {
    posted = { url, request };
    return { ok: true, json: async () => ({
      success: true,
      data: { template_id: 'core.welcome', revision: '13',
        document: { subject: 'Welcome', project: f.checkpoint.submittedProject } },
    }) };
  } });
  f.submit();
  await tick();
  assert.equal(posted.url, '/wp-admin/admin-ajax.php');
  assert.equal(posted.request.method, 'POST');
  assert.equal(posted.request.credentials, 'same-origin');
  assert.equal(posted.request.body.get('template_id'), 'core.welcome');
  assert.equal(posted.request.body.get('subject'), 'Welcome');
  assert.equal(posted.request.body.get('expected_revision'), '12');
  assert.equal(posted.request.body.get('project_json'), JSON.stringify(f.checkpoint.submittedProject));
  assert.deepEqual(f.states(), ['saving', 'saved']);
  assert.equal(f.acknowledged(), 1);
  assert.equal(f.failed(), 0);
});

test('Mail transport never reports saved if consumer rejects server acknowledgement', async () => {
  const f = fixture({ acceptAck: false });
  f.submit();
  await tick();
  assert.deepEqual(f.states(), ['saving', 'error']);
  assert.equal(f.acknowledged(), 1);
  assert.equal(f.failed(), 1);
});

test('Mail transport returns conflict as uncertainty, without a false Saved event', async () => {
  const f = fixture({ fetchImpl: async () => ({
    ok: false, json: async () => ({
      success: false, data: { message: 'Expected revision conflict' },
    }),
  }) });
  f.submit();
  await tick();
  assert.deepEqual(f.states(), ['saving', 'error']);
  assert.equal(f.acknowledged(), 0);
  assert.equal(f.failed(), 1);
});

test('Mail transport refuses missing session capture before sending any request', async () => {
  let requests = 0;
  const f = fixture({ capture: false, fetchImpl: async () => { requests += 1; throw Error('unexpected'); } });
  f.submit();
  await tick();
  assert.equal(requests, 0);
  assert.deepEqual(f.states(), ['error']);
  assert.equal(f.failed(), 1);
});

test('Mail transport permits only one in-flight request and reopens after settlement', async () => {
  let resolveFirst;
  let requests = 0;
  const f = fixture({ fetchImpl: async () => {
    requests += 1;
    if (requests === 1) await new Promise((resolve) => { resolveFirst = resolve; });
    return { ok: true, json: async () => ({
      success: true,
      data: { template_id: 'core.welcome', revision: '13',
        document: { subject: 'Welcome', project: f.checkpoint.submittedProject } },
    }) };
  } });
  f.submit();
  f.submit();
  await tick();
  assert.equal(requests, 1);
  assert.equal(f.captured(), 1);
  resolveFirst();
  await tick();
  assert.deepEqual(f.states(), ['saving', 'saved']);
  f.submit();
  await tick();
  assert.equal(requests, 2);
  assert.deepEqual(f.states(), ['saving', 'saved', 'saving', 'saved']);
});


test('G2-S T02: simulated committed write with lost HTTP response stays UNKNOWN and cannot retry', async () => {
  // Local mock truth is deliberately not treated as database-commit evidence.
  let simulatedCommit = false;
  let requests = 0;
  const f = fixture({ fetchImpl: async () => {
    requests += 1;
    simulatedCommit = true;
    throw new TypeError('Network response was lost after simulated commit');
  } });
  f.submit();
  await tick();
  assert.equal(simulatedCommit, true, 'the fixture simulates a commit; it is not a DB witness');
  assert.equal(requests, 1);
  assert.deepEqual(f.states(), ['saving', 'unknown']);
  assert.equal(f.acknowledged(), 0);
  assert.equal(f.failed(), 1);
  f.submit();
  await tick();
  assert.equal(requests, 1, 'UNKNOWN must not submit another uncorrelated Save');
  assert.deepEqual(f.states(), ['saving', 'unknown']);
});

test('G2-S T03: simulated no-commit with lost HTTP response has the same fail-closed UNKNOWN behavior', async () => {
  let simulatedCommit = false;
  let requests = 0;
  const f = fixture({ fetchImpl: async () => {
    requests += 1;
    throw new TypeError('Network response was lost before simulated commit');
  } });
  f.submit();
  await tick();
  assert.equal(simulatedCommit, false, 'the fixture simulates no commit; it is not a DB witness');
  assert.equal(requests, 1);
  assert.deepEqual(f.states(), ['saving', 'unknown']);
  assert.equal(f.acknowledged(), 0);
  assert.equal(f.failed(), 1);
  f.submit();
  await tick();
  assert.equal(requests, 1, 'UNKNOWN must remain sticky even if the mock did not write');
  assert.deepEqual(f.states(), ['saving', 'unknown']);
});
