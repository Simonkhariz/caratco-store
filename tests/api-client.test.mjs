// What the API client does when the session behind a request changes (lib/api-client.ts), and how
// a product from the API is mapped. Run with: npm run test:web
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import './helpers/alias-hooks.mjs';

const api = await import('../lib/api-client.ts');

const reply = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const ok = (data) => reply(200, { success: true, data });
const refused = (status, code) => reply(status, { success: false, error: { code, message: code } });
const me = (id, token) => ok({ user: { id, role: 'customer' }, csrf_token: token });

/** Answers requests from `routes` by "METHOD /path", in order; records what was asked. */
function fakeServer(routes) {
  const calls = [];
  globalThis.fetch = async (url, init = {}) => {
    const key = `${init.method || 'GET'} ${String(url).replace(api.API_BASE_URL, '')}`;
    calls.push({ key, csrf: init.headers?.['X-CSRF-Token'] });
    const answers = routes[key];
    assert.ok(answers?.length, `unexpected request: ${key}`);
    return answers.shift()();
  };
  return calls;
}

let events;
let stopListening;
beforeEach(async () => {
  stopListening?.();
  events = { expired: 0, signedInElsewhere: [] };
  stopListening = api.onCustomerSessionEvent({
    expired: () => {
      events.expired += 1;
    },
    signedInElsewhere: (user) => events.signedInElsewhere.push(user.id),
  });
  // Start each test as a guest.
  fakeServer({ 'POST /auth/logout': [() => ok(null)] });
  await api.apiLogout();
});

const signIn = async (id, token) => {
  fakeServer({ 'GET /auth/me': [() => me(id, token)] });
  await api.apiGetMe();
};

test('signed in on another tab: the request is sent again with the new token, once', async () => {
  // This tab is a guest, so it holds no token; the cookie now belongs to customer 5.
  const calls = fakeServer({
    'POST /checkout/orders': [() => refused(403, 'CSRF_MISMATCH'), () => ok({ order_number: 'ORD-1' })],
    'GET /auth/me': [() => me(5, 'new-token')],
  });
  const order = await api.apiCreateCheckoutOrder({ items: [], shipping_address: {} }, 'key');
  assert.equal(order.order_number, 'ORD-1');
  assert.deepEqual(calls.map((c) => c.key), ['POST /checkout/orders', 'GET /auth/me', 'POST /checkout/orders']);
  assert.equal(calls[2].csrf, 'new-token');
  assert.deepEqual(events.signedInElsewhere, [5], 'the auth state hears who is signed in');
});

test('a second refusal is not retried again', async () => {
  const calls = fakeServer({
    'POST /checkout/orders': [() => refused(403, 'CSRF_MISMATCH'), () => refused(403, 'CSRF_MISMATCH')],
    'GET /auth/me': [() => me(5, 'new-token')],
  });
  await assert.rejects(api.apiCreateCheckoutOrder({ items: [], shipping_address: {} }), { errorCode: 'CSRF_MISMATCH' });
  assert.equal(calls.length, 3);
});

test('a request for one customer is never sent again as another', async () => {
  await signIn(5, 'token-5');
  const calls = fakeServer({
    'PUT /customer/cart': [() => refused(403, 'CSRF_MISMATCH')],
    'GET /auth/me': [() => me(6, 'token-6')],
  });
  await assert.rejects(api.apiSaveCart([{ variant_id: 1, quantity: 1 }]), { errorCode: 'CSRF_MISMATCH' });
  assert.deepEqual(calls.map((c) => c.key), ['PUT /customer/cart', 'GET /auth/me']);
  assert.deepEqual(events.signedInElsewhere, [6]);
});

test('no session to refresh: the original refusal stands', async () => {
  fakeServer({
    'POST /checkout/orders': [() => refused(403, 'CSRF_MISMATCH')],
    'GET /auth/me': [() => refused(401, 'UNAUTHENTICATED')],
  });
  await assert.rejects(api.apiCreateCheckoutOrder({ items: [], shipping_address: {} }), { errorCode: 'CSRF_MISMATCH' });
  assert.equal(events.expired, 0, '/auth/me answering 401 is normal for a guest');
});

test('a customer request answered 401 ends the session', async () => {
  await signIn(5, 'token-5');
  fakeServer({ 'GET /customer/orders': [() => refused(401, 'UNAUTHENTICATED')] });
  await assert.rejects(api.apiGetCustomerOrders(), { statusCode: 401 });
  assert.equal(events.expired, 1);
  assert.equal(api.getMemoryCsrfToken(), null);
});

test('401 from sign-in itself says nothing about a session', async () => {
  fakeServer({ 'POST /auth/login': [() => refused(401, 'INVALID_CREDENTIALS')] });
  await assert.rejects(api.apiLogin({ email: 'a@b.c', password: 'x' }), { statusCode: 401 });
  assert.equal(events.expired, 0);
});

test('a 401 for a request sent before a sign-in does not end the new session', async () => {
  let answerOld;
  globalThis.fetch = async (url) => {
    if (String(url).endsWith('/customer/orders')) return new Promise((resolve) => (answerOld = resolve));
    return ok({ user: { id: 5, role: 'customer' }, csrf_token: 'token-5' });
  };
  const oldRequest = api.apiGetCustomerOrders();
  await api.apiLogin({ email: 'a@b.c', password: 'x' });
  answerOld(refused(401, 'UNAUTHENTICATED'));
  await assert.rejects(oldRequest, { statusCode: 401 });
  assert.equal(events.expired, 0);
  assert.equal(api.getMemoryCsrfToken(), 'token-5');
});

test('admin requests are left to the admin sign-in', async () => {
  const calls = fakeServer({ 'POST /admin/products': [() => refused(403, 'CSRF_MISMATCH')] });
  await assert.rejects(api.apiCreateAdminProduct({}), { errorCode: 'CSRF_MISMATCH' });
  assert.equal(calls.length, 1, 'not retried');
  fakeServer({ 'GET /admin/products': [() => refused(401, 'UNAUTHENTICATED')] });
  await assert.rejects(api.apiGetAdminProducts(), { statusCode: 401 });
  assert.equal(events.expired, 0);
});

test('order numbers are encoded in every order address', async () => {
  const calls = fakeServer({
    'GET /checkout/orders/A%20B%2FC/verify-payment': [() => ok({ status: 'pending' })],
    'POST /checkout/orders/A%20B%2FC/initialize-payment': [() => ok({ provider: 'kora' })],
  });
  await api.apiVerifyPayment('A B/C');
  await api.apiInitializePayment('A B/C');
  assert.equal(calls.length, 2);
});

const product = {
  id: 1,
  name: 'Tee',
  slug: 'tee',
  base_price: 100,
  rating_avg: 0,
  rating_count: 0,
  discount_percent: 0,
  category_slug: null,
  category_name: null,
  dress_style: null,
  primary_image: null,
  total_stock: 0,
  in_stock: false,
  reviews: [
    { id: 1, author: 'Ada', rating: 5, title: 'Lovely', comment: 'Nice', verified: true, created_at: '2026-11-06 04:10:00' },
    { id: 2, author: 'Obi', rating: 4, title: null, comment: 'Good', verified: false, created_at: '2026-11-06 04:10:00' },
  ],
};

test('a review keeps its title', () => {
  const [titled, untitled] = api.mapApiProductToStorefront(product).reviews;
  assert.equal(titled.title, 'Lovely');
  assert.equal(untitled.title, undefined);
});

test('a product without a category has no category to link to', () => {
  assert.equal(api.mapApiProductToStorefront(product).category, '');
});

test('specification rows are tidied, and rows without a name or a value are left out', () => {
  assert.deepEqual(
    api.specificationRowsFrom([
      { name: ' Battery ', value: ' 5000 mAh ' },
      { name: 'Weight', value: 180 },
      { name: '', value: 'no name' },
      { name: 'No value', value: '  ' },
      null,
      'text',
    ]),
    [
      { name: 'Battery', value: '5000 mAh' },
      { name: 'Weight', value: '180' },
    ]
  );
  for (const nothing of [null, undefined, '', {}, 'rows']) assert.deepEqual(api.specificationRowsFrom(nothing), []);
});

test('a product page answer brings its specifications, warranty and flags; a list answer has none', async () => {
  const { SHOP } = await import('../lib/shop.ts');
  const listed = api.mapApiProductToStorefront(product);
  assert.deepEqual(listed.specifications, []);
  assert.equal(listed.warranty, undefined);
  assert.equal(listed.isHeavy, false);
  assert.equal(listed.ageRestricted, false);

  const detailed = api.mapApiProductToStorefront({
    ...product,
    specifications: [{ name: 'Top notes', value: 'Bergamot' }],
    warranty: ' 12 months ',
    is_heavy: true,
    age_restricted: true,
  });
  assert.deepEqual(detailed.specifications, [{ name: 'Top notes', value: 'Bergamot' }]);
  // A warranty is shown only by a shop that has warranties switched on.
  assert.equal(detailed.warranty, SHOP.features.warranty ? '12 months' : undefined);
  assert.equal(detailed.isHeavy, true);
  assert.equal(detailed.ageRestricted, true);
  // The SKU and category lines stay as they were.
  assert.deepEqual(api.mapApiProductToStorefront({ ...product, sku: 'TEE-1', category_name: 'Tops' }).details, ['SKU: TEE-1', 'Category: Tops']);
  assert.equal(api.mapApiProductToStorefront({ ...product, warranty: null, specifications: null }).warranty, undefined);
});
