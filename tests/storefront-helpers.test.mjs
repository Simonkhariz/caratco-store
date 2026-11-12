// Small storefront helpers: cart lines, WhatsApp links, the store's address.
// Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { withAddedLines } from '../lib/cart-lines.ts';
import { storeAddressLine } from '../lib/store-address.ts';
// lib/whatsapp.ts and lib/variants.ts read the shop's settings through the "@/" alias.
import './helpers/alias-hooks.mjs';

const {
  buildWhatsAppInvoiceUrl,
  buildWhatsAppMessageUrl,
  buildWhatsAppPrePaymentHelpUrl,
  buildWhatsAppReceiptUrl,
  formatSupportPhone,
} = await import('../lib/whatsapp.ts');

const line = (id, quantity, maxStock = 50) => ({ id, quantity, maxStock, name: `Item ${id}` });

test('lines added while the cart loads survive the load', () => {
  const merged = withAddedLines([line('1', 2)], [{ line: line('9', 1), quantity: 1 }], 20);
  assert.deepEqual(merged.map((i) => [i.id, i.quantity]), [['1', 2], ['9', 1]]);
});

test('an added line counts on top of what the loaded cart holds, within the limits', () => {
  assert.equal(withAddedLines([line('1', 2)], [{ line: line('1', 1), quantity: 1 }], 20)[0].quantity, 3);
  assert.equal(withAddedLines([line('1', 2)], [{ line: line('1', 3, 3), quantity: 3 }], 20)[0].quantity, 3, 'stock');
  assert.equal(withAddedLines([line('1', 19)], [{ line: line('1', 5), quantity: 5 }], 20)[0].quantity, 20, 'per-line limit');
  const twice = withAddedLines([], [{ line: line('1', 1), quantity: 1 }, { line: line('1', 2), quantity: 1 }], 20);
  assert.deepEqual(twice.map((i) => [i.id, i.quantity]), [['1', 2]]);
});

test('without a store number there are no WhatsApp links', () => {
  const order = { order_number: 'ORD-1', grand_total: 100 };
  for (const phone of [null, undefined, '', '   ']) {
    assert.equal(buildWhatsAppInvoiceUrl(order, phone), '');
    assert.equal(buildWhatsAppReceiptUrl(order, phone), '');
    assert.equal(buildWhatsAppMessageUrl('Hello', phone), '');
    assert.equal(buildWhatsAppPrePaymentHelpUrl('ORD-1', 100, phone), '');
    assert.equal(buildWhatsAppPrePaymentHelpUrl(undefined, undefined, phone), '');
    assert.equal(formatSupportPhone(phone), '');
  }
});

test('with a store number the links use it', () => {
  assert.equal(buildWhatsAppMessageUrl('Hello you', '0803 123 4567'), 'https://wa.me/2348031234567?text=Hello%20you');
  assert.ok(buildWhatsAppInvoiceUrl({ order_number: 'ORD-1', grand_total: 1 }, '+2348031234567').startsWith('https://wa.me/2348031234567?text='));
  assert.equal(formatSupportPhone('08031234567'), '+234 803 123 4567');
});

test('the WhatsApp payment message names the order and amount and asks for the account', () => {
  const url = buildWhatsAppInvoiceUrl({ order_number: 'ORD-20261109-7Q2K', grand_total: 58750 }, '08031234567');
  const text = decodeURIComponent(url.split('?text=')[1]);
  assert.match(text, /\*Order number:\* ORD-20261109-7Q2K/);
  assert.match(text, /\*Amount to pay:\* ₦58,750/);
  assert.match(text, /account number to pay into/);
  // Without a usable amount the line is left out rather than showing ₦0.
  const noAmount = decodeURIComponent(buildWhatsAppInvoiceUrl({ order_number: 'ORD-1', grand_total: 0 }, '08031234567').split('?text=')[1]);
  assert.doesNotMatch(noAmount, /Amount to pay/);
});

test('the store address does not repeat a city or state it already names', () => {
  assert.equal(
    storeAddressLine('12 Okpara Avenue, Independence Layout, Enugu, Nigeria', 'Enugu', 'Enugu'),
    '12 Okpara Avenue, Independence Layout, Enugu, Nigeria'
  );
  assert.equal(storeAddressLine('12 Okpara Avenue Enugu', 'enugu', 'Enugu State'), '12 Okpara Avenue Enugu, Enugu State');
  assert.equal(storeAddressLine('5 Allen Avenue, Ikeja, Lagos State', 'Ikeja', 'Lagos'), '5 Allen Avenue, Ikeja, Lagos State');
});

test('the store address adds what is missing, and a street named after a city is not the city', () => {
  assert.equal(storeAddressLine('5 Allen Avenue', 'Ikeja', 'Lagos'), '5 Allen Avenue, Ikeja, Lagos');
  assert.equal(storeAddressLine('12 Lagos Street', 'Ikeja', 'Lagos'), '12 Lagos Street, Ikeja, Lagos');
  assert.equal(storeAddressLine('', 'Ikeja', 'Ikeja'), 'Ikeja');
  assert.equal(storeAddressLine(null, null, ' '), '');
});

test('only sizes in stock in the chosen colour are offered', async () => {
  const { sizeStatesFor, visibleSizes, sizeAfterColorChange } = await import('../lib/variants.ts');
  const red = { name: 'Red', hex: '#f00' };
  const blue = { name: 'Blue', hex: '#00f' };
  const variants = [
    { id: 1, color: red, size: 'S', stock: 0 },
    { id: 2, color: red, size: 'M', stock: 4 },
    { id: 3, color: blue, size: 'S', stock: 2 },
    { id: 4, color: blue, size: 'L', stock: 0 },
  ];
  const sizes = ['S', 'M', 'L'];
  assert.deepEqual(visibleSizes(sizes, sizeStatesFor(sizes, variants, red)), ['M']);
  assert.deepEqual(visibleSizes(sizes, sizeStatesFor(sizes, variants, blue)), ['S']);
  // switching colour drops a size that is sold out there
  // ...but a size that is sold out there is never swapped for another behind the shopper's back
  assert.equal(sizeAfterColorChange(variants, red, 'S'), null);
  // nothing chosen yet and the colour comes in one size: that size
  assert.equal(sizeAfterColorChange(variants, red, null), 'M');
  // a colour with nothing in stock: no size
  assert.equal(sizeAfterColorChange(variants, { name: 'Green', hex: '#0f0' }, 'S'), null);
  assert.equal(sizeAfterColorChange(variants, blue, 'S'), 'S');
  // everything sold out: nothing is offered
  const none = variants.map((v) => ({ ...v, stock: 0 }));
  assert.deepEqual(visibleSizes(sizes, sizeStatesFor(sizes, none, red)), []);
});
