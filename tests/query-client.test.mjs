// TanStack Query defaults (lib/query-client.ts). Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clearQueryCache, getQueryClient, shouldRetryQuery } from '../lib/query-client.ts';

const apiError = (statusCode) => Object.assign(new Error('x'), { statusCode });

test('network failures, timeouts and server errors are retried once', () => {
  assert.equal(shouldRetryQuery(0, apiError(0)), true);
  assert.equal(shouldRetryQuery(0, apiError(500)), true);
  assert.equal(shouldRetryQuery(0, apiError(503)), true);
  assert.equal(shouldRetryQuery(0, new TypeError('Failed to fetch')), true);
  assert.equal(shouldRetryQuery(1, apiError(503)), false, 'only one retry');
});

test('refused requests are never retried', () => {
  for (const status of [400, 401, 403, 404, 409, 422, 429]) {
    assert.equal(shouldRetryQuery(0, apiError(status)), false, `HTTP ${status}`);
  }
});

test('on the server every render gets its own client (no data shared between visitors)', () => {
  // node:test has no window, so this runs as the server does.
  const a = getQueryClient();
  const b = getQueryClient();
  assert.notEqual(a, b);
  a.setQueryData(['admin', 'secret'], { visitor: 'A' });
  assert.equal(b.getQueryData(['admin', 'secret']), undefined);
  // Stored data starts a 5-minute clean-up timer; clear it so the test process can exit.
  a.clear();
  b.clear();
});

test('the defaults never retry mutations and do not refetch on focus', () => {
  const options = getQueryClient().getDefaultOptions();
  assert.equal(options.mutations?.retry, false);
  assert.equal(options.queries?.refetchOnWindowFocus, false);
  assert.equal(options.queries?.retry, shouldRetryQuery);
});

test('clearing the cache with no browser client is safe', () => {
  assert.doesNotThrow(() => clearQueryCache());
});
