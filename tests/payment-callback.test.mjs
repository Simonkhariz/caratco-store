// Tests for gateway redirect query parsing, callback parameter recovery, and payment queries.
// Run with: npm run test:web
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import './helpers/alias-hooks.mjs';

function fakeStorage() {
  const data = new Map();
  return {
    get length() {
      return data.size;
    },
    key: (i) => Array.from(data.keys())[i] ?? null,
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => data.set(k, String(v)),
    removeItem: (k) => data.delete(k),
    keys: () => Array.from(data.keys()),
  };
}

const set = (name, value) => Object.defineProperty(globalThis, name, { value, configurable: true, writable: true });
beforeEach(() => {
  set('localStorage', fakeStorage());
  set('sessionStorage', fakeStorage());
});

const { getCallbackParams } = await import('../lib/payment-queries.ts');
const { savePendingOrder } = await import('../lib/pending-order.ts');
const api = await import('../lib/api-client.ts');

const ok = (data) => new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });

test('getCallbackParams extracts order and reference when both present', () => {
  const params = new URLSearchParams('order=ORD-20261115-001&reference=PAY-ORD-20261115-001-ABCD1234');
  const res = getCallbackParams(params, '?order=ORD-20261115-001&reference=PAY-ORD-20261115-001-ABCD1234');
  assert.equal(res.orderNumber, 'ORD-20261115-001');
  assert.equal(res.reference, 'PAY-ORD-20261115-001-ABCD1234');
});

test('getCallbackParams handles gateway double-question-mark string concatenation', () => {
  const malformed = '?order=ORD-20261115-001?paymentReference=MNFY-ORD-20261115-001-A1B2C3D4';
  const initialParams = new URLSearchParams(malformed);
  const res = getCallbackParams(initialParams, malformed);
  assert.equal(res.orderNumber, 'ORD-20261115-001');
  assert.equal(res.reference, 'MNFY-ORD-20261115-001-A1B2C3D4');
});

test('getCallbackParams extracts orderNumber from PAY- formatted reference', () => {
  const params = new URLSearchParams('reference=PAY-ORD-20261115-999-F0E1D2C3');
  const res = getCallbackParams(params, '?reference=PAY-ORD-20261115-999-F0E1D2C3');
  assert.equal(res.orderNumber, 'ORD-20261115-999');
  assert.equal(res.reference, 'PAY-ORD-20261115-999-F0E1D2C3');
});

test('getCallbackParams extracts orderNumber from MNFY- formatted reference', () => {
  const params = new URLSearchParams('paymentReference=MNFY-ORD-20261115-555-ABCDEF01');
  const res = getCallbackParams(params, '?paymentReference=MNFY-ORD-20261115-555-ABCDEF01');
  assert.equal(res.orderNumber, 'ORD-20261115-555');
  assert.equal(res.reference, 'MNFY-ORD-20261115-555-ABCDEF01');
});

test('getCallbackParams falls back to stored pending order if reference has custom prefix', () => {
  savePendingOrder({ orderNumber: 'ORD-PENDING-777', cartSignature: '1:1', expiresAt: null, createdAt: '2026-11-15 01:00:00' });
  const params = new URLSearchParams('transactionReference=MNFY|20261115123456|998877');
  const res = getCallbackParams(params, '?transactionReference=MNFY|20261115123456|998877');
  assert.equal(res.orderNumber, 'ORD-PENDING-777');
  assert.equal(res.reference, 'MNFY|20261115123456|998877');
});

test('getCallbackParams falls back to reference if no order in storage', () => {
  const params = new URLSearchParams('paymentReference=MNFY|20261115123456|998877');
  const res = getCallbackParams(params, '?paymentReference=MNFY|20261115123456|998877');
  assert.equal(res.orderNumber, 'MNFY|20261115123456|998877');
  assert.equal(res.reference, 'MNFY|20261115123456|998877');
});

test('apiGetOrder includes X-Payment-Reference and ?reference= query parameter', async () => {
  let requestedUrl = '';
  let requestedHeaders = {};
  globalThis.fetch = async (url, init) => {
    requestedUrl = String(url);
    requestedHeaders = init?.headers || {};
    return ok({ order_number: 'ORD-1', grand_total: 5000, items: [] });
  };

  await api.apiGetOrder('ORD-1', { reference: 'PAY-ORD-1-ABC' });
  assert.ok(requestedUrl.includes('/checkout/orders/ORD-1?reference=PAY-ORD-1-ABC'));
  assert.equal(requestedHeaders['X-Payment-Reference'], 'PAY-ORD-1-ABC');
});

test('apiVerifyPayment sends X-Payment-Reference and ?reference= query parameter', async () => {
  let requestedUrl = '';
  let requestedHeaders = {};
  globalThis.fetch = async (url, init) => {
    requestedUrl = String(url);
    requestedHeaders = init?.headers || {};
    return ok({ order_number: 'ORD-1', status: 'successful' });
  };

  await api.apiVerifyPayment('ORD-1', undefined, 'MNFY-ORD-1-DEF');
  assert.ok(requestedUrl.includes('/checkout/orders/ORD-1/verify-payment?reference=MNFY-ORD-1-DEF'));
  assert.equal(requestedHeaders['X-Payment-Reference'], 'MNFY-ORD-1-DEF');
});
