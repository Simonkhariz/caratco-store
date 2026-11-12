// Lagos server times, read the same in every viewer's timezone (lib/shop-time.ts).
// Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';

// A shopper abroad: Dubai is three hours ahead of the shop.
process.env.TZ = 'Asia/Dubai';

const { parseShopTime, formatShopDeadline } = await import('../lib/shop-time.ts');

test('a time without an offset is Lagos time, wherever the viewer is', () => {
  assert.equal(parseShopTime('2026-11-06 04:10:00').toISOString(), '2026-11-06T03:10:00.000Z');
  assert.equal(parseShopTime('2026-11-06T04:10:00').toISOString(), '2026-11-06T03:10:00.000Z');
  assert.equal(parseShopTime('2026-11-06 04:10').toISOString(), '2026-11-06T03:10:00.000Z');
  assert.equal(parseShopTime('2026-11-06').toISOString(), '2026-11-05T23:00:00.000Z');
});

test('a time with its own offset keeps it', () => {
  assert.equal(parseShopTime('2026-11-06T04:10:00Z').toISOString(), '2026-11-06T04:10:00.000Z');
  assert.equal(parseShopTime('2026-11-06T04:10:00+03:00').toISOString(), '2026-11-06T01:10:00.000Z');
});

test('empty or broken values are invalid dates, not "now"', () => {
  for (const value of ['', null, undefined, 'soon']) assert.ok(Number.isNaN(parseShopTime(value).getTime()), String(value));
});

test('a deadline today shows only the time, on another day the date too', () => {
  const now = new Date('2026-11-06T03:10:00.000Z'); // 04:10 in Lagos
  const today = formatShopDeadline('2026-11-06 04:40:00', now);
  assert.match(today, /0?4:40/);
  assert.doesNotMatch(today, /Nov/);
  const tomorrow = formatShopDeadline('2026-11-07 04:10:00', now);
  assert.match(tomorrow, /7 Nov/);
  assert.match(tomorrow, /0?4:10/);
  assert.equal(formatShopDeadline(null, now), null);
});

test('"today" is the shop\'s day, not the viewer\'s', () => {
  // 23:30 in Lagos is already the next day in Dubai; a deadline at 23:50 Lagos is still today.
  const now = new Date('2026-11-06T22:30:00.000Z');
  assert.doesNotMatch(formatShopDeadline('2026-11-06 23:50:00', now), /Nov/);
});
