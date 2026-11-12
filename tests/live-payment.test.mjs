// Live payment status reader (lib/live-payment.ts). Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SseParser, isNewerState, openPaymentStream } from '../lib/live-payment.ts';

const state = (status, order_status = 'pending_payment') => ({ order_number: 'ORD-1', status, order_status });

test('parser handles chunks split anywhere, comments, retry and ids', () => {
  const text = 'retry: 3000\n\nid: 7-pending\nevent: payment\ndata: {"a":1}\n\n: keep-alive\n\nevent: end\r\ndata: {"reason":"final"}\r\n\r\n';
  for (const size of [1, 2, 5, 13, text.length]) {
    const parser = new SseParser();
    const messages = [];
    for (let i = 0; i < text.length; i += size) messages.push(...parser.push(text.slice(i, i + size)));
    assert.deepEqual(messages, [
      { event: 'payment', data: '{"a":1}', id: '7-pending' },
      { event: 'end', data: '{"reason":"final"}', id: '7-pending' },
    ], `chunk size ${size}`);
  }
});

test('states only move forward; paid is final', () => {
  assert.equal(isNewerState(null, state('pending')), true);
  assert.equal(isNewerState(state('pending'), state('successful', 'paid')), true);
  assert.equal(isNewerState(state('successful', 'paid'), state('pending')), false, 'never back from paid');
  assert.equal(isNewerState(state('pending'), state('pending')), false, 'repeat ignored');
  assert.equal(isNewerState(state('pending'), state('not_started')), false, 'older ignored');
  assert.equal(isNewerState(state('failed'), state('pending')), true, 'a new attempt after a failure');
  assert.equal(isNewerState(state('pending'), state('pending', 'cancelled')), true, 'the order closed');
});

const streamResponse = (chunks, { status = 200, type = 'text/event-stream', hang = false } = {}) => {
  const encoder = new TextEncoder();
  const body = new ReadableStream({
    async start(controller) {
      for (const c of chunks) controller.enqueue(encoder.encode(c));
      if (!hang) controller.close();
    },
  });
  return new Response(body, { status, headers: { 'content-type': type } });
};

const run = (response, extra = {}) => {
  const seen = [];
  const calls = [];
  const fetchImpl = async (url, init) => {
    calls.push({ url, init });
    if (init.signal.aborted) throw new DOMException('aborted', 'AbortError');
    const res = typeof response === 'function' ? response(init) : response;
    return res;
  };
  const controller = new AbortController();
  const done = openPaymentStream({
    baseUrl: '/api/v1',
    orderNumber: 'ORD-1',
    token: 'secret-key',
    reference: 'PAY-REF',
    signal: controller.signal,
    onState: (s) => seen.push(s),
    fetchImpl,
    firstMessageMs: 100,
    ...extra,
  });
  return { done, seen, calls, controller };
};

test('reports each state and ends as final; the key travels as a header, never in the address', async () => {
  const { done, seen, calls } = run(streamResponse([
    'retry: 3000\n\nevent: payment\ndata: {"order_number":"ORD-1","status":"pending","order_status":"pending_payment"}\n\n',
    'event: payment\ndata: {"order_number":"ORD-1","status":"successful","order_status":"paid"}\n\nevent: end\ndata: {"reason":"final"}\n\n',
  ]));
  assert.equal(await done, 'final');
  assert.deepEqual(seen.map((s) => s.status), ['pending', 'successful']);
  assert.equal(calls[0].url, '/api/v1/checkout/orders/ORD-1/events');
  assert.equal(calls[0].init.headers['X-Guest-Access-Token'], 'secret-key');
  assert.equal(calls[0].init.headers['X-Payment-Reference'], 'PAY-REF');
  assert.ok(!calls[0].url.includes('secret-key'));
});

test('time limit, refused, switched off and dropped streams', async () => {
  assert.equal(await run(streamResponse(['event: payment\ndata: {"order_number":"ORD-1","status":"pending","order_status":"x"}\n\nevent: end\ndata: {"reason":"time_limit"}\n\n'])).done, 'time_limit');
  assert.equal(await run(new Response('{"success":false}', { status: 404 })).done, 'unavailable', 'switched off');
  assert.equal(await run(new Response('{"success":false}', { status: 503 })).done, 'unavailable', 'server full');
  assert.equal(await run(streamResponse(['{}'], { type: 'application/json' })).done, 'unavailable', 'not a stream');
  assert.equal(await run(streamResponse(['event: payment\ndata: {"order_number":"ORD-1","status":"pending","order_status":"x"}\n\n'])).done, 'dropped');
});

test('a stream that sends nothing (a buffering proxy) is given up after the first-message limit', async () => {
  const response = (init) => {
    const body = new ReadableStream({
      start(controller) {
        init.signal.addEventListener('abort', () => controller.error(new DOMException('aborted', 'AbortError')));
      },
    });
    return new Response(body, { status: 200, headers: { 'content-type': 'text/event-stream' } });
  };
  const started = Date.now();
  assert.equal(await run(response).done, 'unavailable');
  assert.ok(Date.now() - started < 1000);
});

test('closing the page aborts the stream', async () => {
  const response = (init) => {
    const body = new ReadableStream({
      start(controller) {
        controller.enqueue(new TextEncoder().encode('event: payment\ndata: {"order_number":"ORD-1","status":"pending","order_status":"x"}\n\n'));
        init.signal.addEventListener('abort', () => controller.error(new DOMException('aborted', 'AbortError')));
      },
    });
    return new Response(body, { status: 200, headers: { 'content-type': 'text/event-stream' } });
  };
  const r = run(response);
  setTimeout(() => r.controller.abort(), 30);
  assert.equal(await r.done, 'aborted');
});
