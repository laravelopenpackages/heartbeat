// The browser client, tested with Node's built-in runner (no dependencies):  node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createHeartbeat } from '../../resources/js/heartbeat.js';

const tick = () => new Promise((resolve) => setImmediate(resolve));

/** A fake server: answers each beat from a queue, and records what was asked. */
function server(answers) {
  const asked = [];
  const fetch = async (url) => {
    asked.push(new URL(url, 'http://app.test'));
    const answer = answers.shift();
    if (answer instanceof Error) {
      return { ok: false, status: 500, json: async () => ({}) };
    }
    return { ok: true, status: 200, json: async () => answer };
  };
  return { fetch, asked };
}

test('the first beat only learns now; later beats send it back as since, with the topics listened to', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const { fetch, asked } = server([
    { now: 1000, changed: [], interval: 15 },
    { now: 2000, changed: ['counts'], interval: 15 },
  ]);
  const heard = [];
  const hb = createHeartbeat({ url: '/heartbeat', fetch });
  hb.on('counts', (topic) => heard.push(topic));
  hb.on('reminders', (topic) => heard.push(topic));

  hb.start();
  await tick();
  assert.equal(asked[0].searchParams.get('since'), null);
  assert.equal(asked[0].searchParams.get('topics'), 'counts,reminders');

  t.mock.timers.tick(15_000);
  await tick();
  assert.equal(asked[1].searchParams.get('since'), '1000');
  assert.deepEqual(heard, ['counts'], 'only the changed topic is called');
  hb.stop();
});

test('stopping listening drops the topic from the next beat', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const { fetch, asked } = server([{ now: 1, changed: [], interval: 15 }, { now: 2, changed: [], interval: 15 }]);
  const hb = createHeartbeat({ url: '/heartbeat', fetch });
  const off = hb.on('counts', () => {});
  hb.on('todos', () => {});

  hb.start();
  await tick();
  off();
  t.mock.timers.tick(15_000);
  await tick();
  assert.equal(asked[1].searchParams.get('topics'), 'todos');
  hb.stop();
});

test('the server sets the interval', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const { fetch, asked } = server([{ now: 1, changed: [], interval: 60 }, { now: 2, changed: [], interval: 60 }]);
  const hb = createHeartbeat({ url: '/heartbeat', fetch, interval: 5 });
  hb.on('counts', () => {});

  hb.start();
  await tick();
  t.mock.timers.tick(59_000);
  await tick();
  assert.equal(asked.length, 1, 'not before 60s');
  t.mock.timers.tick(1_000);
  await tick();
  assert.equal(asked.length, 2);
  hb.stop();
});

test('a failing server is retried with growing gaps, then the normal rhythm resumes', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const errors = [];
  const { fetch, asked } = server([
    { now: 1, changed: [], interval: 10 },
    new Error('down'),
    new Error('down'),
    { now: 4, changed: [], interval: 10 },
    { now: 5, changed: [], interval: 10 },
  ]);
  const hb = createHeartbeat({ url: '/heartbeat', fetch, onError: (e) => errors.push(e) });
  hb.on('counts', () => {});

  hb.start();
  await tick();                                   // ok (1)
  t.mock.timers.tick(10_000); await tick();       // fails (2) → wait 20s
  t.mock.timers.tick(19_000); await tick();
  assert.equal(asked.length, 2, 'backs off');
  t.mock.timers.tick(1_000); await tick();        // fails (3) → wait 40s
  t.mock.timers.tick(40_000); await tick();       // ok (4) → back to 10s
  t.mock.timers.tick(10_000); await tick();       // ok (5)
  assert.equal(asked.length, 5);
  assert.equal(errors.length, 2);
  hb.stop();
});

test('beatNow never sends a second beat while one is in flight', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  let release;
  let calls = 0;
  const fetch = () => {
    calls++;
    return new Promise((resolve) => { release = () => resolve({ ok: true, json: async () => ({ now: 1, changed: [], interval: 15 }) }); });
  };
  const hb = createHeartbeat({ url: '/heartbeat', fetch });
  hb.on('counts', () => {});

  hb.start();
  hb.beatNow();
  hb.beatNow();
  assert.equal(calls, 1);
  release();
  await tick();
  hb.stop();
});

test('a hidden tab does not beat, and beats at once when shown again', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const handlers = {};
  globalThis.document = {
    visibilityState: 'hidden',
    addEventListener: (name, fn) => { handlers[name] = fn; },
    removeEventListener: () => {},
  };
  try {
    const { fetch, asked } = server([{ now: 1, changed: [], interval: 15 }]);
    const hb = createHeartbeat({ url: '/heartbeat', fetch });
    hb.on('counts', () => {});

    hb.start();
    await tick();
    assert.equal(asked.length, 0, 'hidden: no beat');

    document.visibilityState = 'visible';
    handlers.visibilitychange();
    await tick();
    assert.equal(asked.length, 1, 'shown: beats at once');
    hb.stop();
  } finally {
    delete globalThis.document;
  }
});

test('a listener that throws does not stop the others', async (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const errors = [];
  const heard = [];
  const { fetch } = server([{ now: 1, changed: [], interval: 15 }, { now: 2, changed: ['counts'], interval: 15 }]);
  const hb = createHeartbeat({ url: '/heartbeat', fetch, onError: (e) => errors.push(e) });
  hb.on('counts', () => { throw new Error('boom'); });
  hb.on('counts', () => heard.push('second'));

  hb.start();
  await tick();
  t.mock.timers.tick(15_000);
  await tick();

  assert.deepEqual(heard, ['second']);
  assert.equal(errors.length, 1);
  hb.stop();
});
