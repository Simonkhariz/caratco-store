// Customer wording for orders and shipments (lib/order-status.ts). Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import './helpers/alias-hooks.mjs';

process.env.TZ = 'Asia/Dubai';

const { getOrderStatusDisplay, getShipmentDisplay, orderLookupErrorMessage } = await import('../lib/order-status.ts');

/** A server time (Lagos clock, no offset) this many minutes from now. */
const lagosIn = (minutes) => new Date(Date.now() + (minutes + 60) * 60_000).toISOString().slice(0, 19).replace('T', ' ');

test('a fresh order is open for a viewer in another timezone', () => {
  const fresh = getOrderStatusDisplay('pending_payment', 'pending', lagosIn(30));
  assert.equal(fresh.label, 'Awaiting payment');
  assert.equal(fresh.canPay, true);
  const closed = getOrderStatusDisplay('pending_payment', 'pending', lagosIn(-5));
  assert.equal(closed.label, 'Payment window closed');
  assert.equal(closed.canPay, false);
});

test('a reported transfer reads as awaiting confirmation, even past the deadline', () => {
  for (const deadline of [lagosIn(30), lagosIn(-5)]) {
    const shown = getOrderStatusDisplay('pending_payment', 'pending', deadline, '2026-11-06 04:10:00');
    assert.equal(shown.label, 'Awaiting confirmation');
    assert.equal(shown.canPay, false);
  }
});

test('a cancelled order says what happened to the money', () => {
  assert.match(getOrderStatusDisplay('cancelled', 'partially_refunded').description, /part of your payment was refunded/);
  assert.match(getOrderStatusDisplay('cancelled', 'refunded').label, /refunded/);
  assert.match(getOrderStatusDisplay('cancelled', 'successful').label, /refund due/);
  assert.match(getOrderStatusDisplay('cancelled', 'pending').description, /No payment was taken/);
});

test('every shipment status has its own wording', () => {
  assert.equal(getShipmentDisplay('out_for_delivery').badge, 'Out for delivery');
  assert.notEqual(getShipmentDisplay('label_created').heading, 'Dispatched by courier');
  assert.notEqual(getShipmentDisplay('label_created').delivery, 'Package in transit');
  assert.notEqual(getShipmentDisplay('returned').delivery, 'Package in transit');
  assert.equal(getShipmentDisplay('in_transit').delivery, 'Package in transit');
});

test('an unknown shipment status has no underscores', () => {
  assert.equal(getShipmentDisplay('held_at_depot_now').badge, 'Held at depot now');
  assert.equal(getShipmentDisplay(null).badge, 'In transit');
});

test('a tracking code the route refuses reads as order not found', () => {
  const routeError = Object.assign(new Error('Endpoint not found: GET /api/v1/checkout/orders/A/B'), { errorCode: 'ROUTE_NOT_FOUND' });
  assert.equal(orderLookupErrorMessage(routeError, 'Order not found.'), 'Order not found.');
  assert.equal(orderLookupErrorMessage(new Error('Too many lookups.'), 'Order not found.'), 'Too many lookups.');
});
