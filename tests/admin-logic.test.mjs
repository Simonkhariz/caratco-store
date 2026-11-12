// Rules behind the admin screens (lib/admin-logic.ts, lib/currency.ts). Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  canRecordLatePayment,
  createModalStack,
  isNumericSlug,
  pastEndPage,
  queryString,
  refundStartAmount,
  searchBoxOnUrlChange,
  slugify,
  splitUploads,
  uploadFailureMessage,
  wholeNumber,
  zoneOverlaps,
} from '../lib/admin-logic.ts';
import { formatPrice } from '../lib/currency.ts';

test('a refund starts at the extra for an overpaid order that stands, otherwise at all that can go back', () => {
  const base = { order_status: 'paid', order_total: 20500, paid_amount: 22500, refunded_amount: 0, pending_refund_amount: 0, refundable_amount: 22500 };
  assert.equal(refundStartAmount(base), '2000');
  assert.equal(refundStartAmount({ ...base, order_status: 'cancelled' }), '22500');
  assert.equal(refundStartAmount({ ...base, paid_amount: 20500, refundable_amount: 20500 }), '20500');
  assert.equal(refundStartAmount({ ...base, refunded_amount: 2000, refundable_amount: 20500 }), '20500');
  assert.equal(refundStartAmount({ ...base, paid_amount: 41000, order_total: 20500, refundable_amount: 20500 }), '20500');
  assert.equal(refundStartAmount({ ...base, refundable_amount: 0 }), '');
});

test('analytics "All time" sends range=all; other filters still drop "all"', () => {
  assert.equal(queryString({ range: 'all' }), '?range=all');
  assert.equal(queryString({ status: 'all', filter: 'all', search: '', page: 2 }), '?page=2');
  assert.equal(queryString({ range: '7days', start_date: undefined }), '?range=7days');
  assert.equal(queryString(), '');
});

test('money shows kobo only when the amount has them', () => {
  assert.equal(formatPrice(14500), '₦14,500');
  assert.equal(formatPrice(14500.0), '₦14,500');
  assert.equal(formatPrice(0), '₦0');
  assert.equal(formatPrice(null), '₦0');
  assert.equal(formatPrice(11110.5), '₦11,110.50');
  assert.equal(formatPrice(0.5), '₦0.50');
  assert.equal(formatPrice(9999.99), '₦9,999.99');
  // Sums of kobo carry floating point noise; it never shows.
  assert.equal(formatPrice(0.1 + 0.2), '₦0.30');
  assert.equal(formatPrice(11110.000000001), '₦11,110');
});

test('whole numbers: decimals and exponents are refused, never cut down', () => {
  assert.equal(wholeNumber('12'), 12);
  assert.equal(wholeNumber(' -3 '), -3);
  assert.equal(wholeNumber('+5'), 5);
  assert.equal(wholeNumber('0'), 0);
  assert.equal(wholeNumber('12.5'), null);
  assert.equal(wholeNumber('2.5'), null);
  assert.equal(wholeNumber('1e3'), null);
  assert.equal(wholeNumber(''), null);
  assert.equal(wholeNumber('abc'), null);
  assert.equal(wholeNumber('99999999999999999999'), null);
});

test('a list past its last page moves to the last page', () => {
  const list = (page, total, total_pages, items = []) => ({ items, pagination: { page, per_page: 20, total, total_pages } });
  // The last order on page 2 was shipped: 20 left, all on page 1.
  assert.equal(pastEndPage(list(2, 20, 1)), 1);
  // An old link.
  assert.equal(pastEndPage(list(9999, 45, 3)), 3);
  // total_pages missing: worked out from the total.
  assert.equal(pastEndPage({ items: [], pagination: { page: 9, per_page: 20, total: 45 } }), 3);
});

test('a list that is really empty, or fine, stays where it is', () => {
  const list = (page, total, total_pages, items = []) => ({ items, pagination: { page, per_page: 20, total, total_pages } });
  assert.equal(pastEndPage(list(1, 0, 0)), null);
  assert.equal(pastEndPage(list(3, 0, 0)), null);
  assert.equal(pastEndPage(list(2, 45, 3, [{ id: 1 }])), null);
  // Empty on a page that should have rows, or on page 1: nothing to move to, so no loop.
  assert.equal(pastEndPage(list(2, 45, 3)), null);
  assert.equal(pastEndPage(list(1, 5, 1)), null);
  // Not a list at all.
  assert.equal(pastEndPage(null), null);
  assert.equal(pastEndPage([]), null);
  assert.equal(pastEndPage({ zones: [] }), null);
});

test('search box: its own URL update never removes what was typed since', () => {
  // Wrote "ab", kept typing "abc", then the URL catches up with "ab".
  assert.deepEqual(searchBoxOnUrlChange('ab', 'abc', ['ab']), { text: 'abc', pending: [] });
  // Two writes in flight: each arrival is recognised.
  let state = searchBoxOnUrlChange('ab', 'abcd', ['ab', 'abc']);
  assert.deepEqual(state, { text: 'abcd', pending: ['abc'] });
  state = searchBoxOnUrlChange('abc', state.text, state.pending);
  assert.deepEqual(state, { text: 'abcd', pending: [] });
  // The router dropped the older write and went straight to the newer one.
  assert.deepEqual(searchBoxOnUrlChange('abc', 'abcd', ['ab', 'abc']), { text: 'abcd', pending: [] });
  // A space typed between words stays.
  assert.deepEqual(searchBoxOnUrlChange('red', 'red ', ['red']), { text: 'red ', pending: [] });
});

test('search box: outside URL changes (Back, Forward, links) still fill the box', () => {
  assert.deepEqual(searchBoxOnUrlChange('shoes', 'abc', []), { text: 'shoes', pending: [] });
  assert.deepEqual(searchBoxOnUrlChange('', 'abc', ['abc']), { text: '', pending: [] });
  assert.deepEqual(searchBoxOnUrlChange('red', 'red ', []), { text: 'red ', pending: [] });
});

test('modal stack: only the top dialog answers keys', () => {
  const stack = createModalStack(() => () => {});
  const editor = stack.enter();
  assert.equal(stack.isTop(editor), true);
  const confirm = stack.enter();
  assert.equal(stack.isTop(editor), false);
  assert.equal(stack.isTop(confirm), true);
  stack.leave(confirm);
  assert.equal(stack.isTop(editor), true);
});

test('modal stack: the page unlocks once, after the last dialog, in any closing order', () => {
  for (const closeEditorFirst of [true, false]) {
    let locks = 0;
    let unlocks = 0;
    const stack = createModalStack(() => {
      locks += 1;
      return () => {
        unlocks += 1;
      };
    });
    const editor = stack.enter();
    const confirm = stack.enter();
    assert.equal(locks, 1);
    stack.leave(closeEditorFirst ? editor : confirm);
    assert.equal(unlocks, 0);
    stack.leave(closeEditorFirst ? confirm : editor);
    assert.equal(unlocks, 1);
    // Leaving twice (a re-run effect) changes nothing.
    stack.leave(editor);
    assert.equal(unlocks, 1);
  }
});

test('uploads: one bad file keeps the good ones and is named', async () => {
  const files = [{ name: 'good.jpg' }, { name: 'bad.gif' }, { name: 'also-good.png' }];
  const settled = await Promise.allSettled([
    Promise.resolve({ image_url: '/uploads/1.webp' }),
    Promise.reject(new Error('Not an image.')),
    Promise.resolve({ image_url: '/uploads/2.webp' }),
  ]);
  const { uploaded, failed } = splitUploads(files, settled);
  assert.deepEqual(uploaded.map((u) => u.image_url), ['/uploads/1.webp', '/uploads/2.webp']);
  assert.deepEqual(failed.map((f) => f.name), ['bad.gif']);
  assert.equal(uploadFailureMessage(failed), '"bad.gif" could not be uploaded. Not an image.');
  assert.equal(
    uploadFailureMessage([...failed, { name: 'worse.bmp', reason: 'x' }]),
    'Not uploaded: bad.gif, worse.bmp. Not an image.'
  );
});

test('slugs: a name that is only a number gets a word in front', () => {
  assert.equal(slugify('501', 'product'), 'product-501');
  assert.equal(slugify('2E3', 'category'), 'category-2e3');
  assert.equal(slugify(' 501 ', 'category'), 'category-501');
  assert.equal(slugify('Levi 501', 'product'), 'levi-501');
  assert.equal(slugify('5-pack', 'product'), '5-pack');
  assert.equal(slugify('!!!', 'product'), '');
  assert.equal(isNumericSlug('501'), true);
  assert.equal(isNumericSlug('-5'), true);
  assert.equal(isNumericSlug('5e-3'), true);
  assert.equal(isNumericSlug('1-2'), false);
  assert.equal(isNumericSlug('e5'), false);
});

test('late payment: only a claimed transfer on a cancelled order with no money recorded', () => {
  const order = (order_status, payment_status, customer_marked_paid_at = '2026-11-01 10:00:00') => ({ order_status, payment_status, customer_marked_paid_at });
  for (const status of ['pending', 'failed', 'cancelled']) assert.equal(canRecordLatePayment(order('cancelled', status)), true);
  assert.equal(canRecordLatePayment(order('cancelled', 'pending', null)), false);
  assert.equal(canRecordLatePayment(order('cancelled', 'successful')), false);
  assert.equal(canRecordLatePayment(order('pending_payment', 'pending')), false);
});

test('zones: overlaps count active zones only', () => {
  const zone = (name, states_covered, is_active = true) => ({ name, states_covered, is_active });
  assert.deepEqual(zoneOverlaps([zone('A', ['Lagos', 'Ogun']), zone('B', ['Lagos', 'Oyo']), zone('C', ['Ogun'], false)]), [{ state: 'Lagos', zones: ['A', 'B'] }]);
  assert.deepEqual(zoneOverlaps([zone('A', ['Lagos', 'Lagos']), zone('B', ['Oyo'])]), []);
});
