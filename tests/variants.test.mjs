// Product options on the product page and quick add (lib/variants.ts): which pickers a product
// gets, the stock line and the phone bar's label. The words come from backend/config/shop.json,
// so every check holds for any kind of shop. Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import './helpers/alias-hooks.mjs';

const { SHOP, option1Word, option2Word, withArticle } = await import('../lib/shop.ts');
const { findVariant, firstChoice, selectionLabelFor, sizeAfterColorChange, soldOutColorName, stockMessageFor, usedOptions } = await import(
  '../lib/variants.ts'
);
const { whatsAppItems } = await import('../lib/whatsapp.ts');

const NONE1 = SHOP.option1.none;
const NONE2 = SHOP.option2.none;
const navy = { name: 'Navy', hex: '#001f3f' };
const red = { name: 'Red', hex: '#ff0000' };
const noColour = { name: NONE2, hex: '#000000' };
const variant = (id, size, color, stock) => ({ id, sku: `SKU-${id}`, size, color, price: 1000, stock });

test('a product uses only the options that hold a real value', () => {
  assert.deepEqual(usedOptions({ sizes: ['M', 'L'], colors: [navy, red] }), { option1: true, option2: true });
  assert.deepEqual(usedOptions({ sizes: [NONE1], colors: [navy] }), { option1: false, option2: true });
  assert.deepEqual(usedOptions({ sizes: ['50ml'], colors: [noColour] }), { option1: true, option2: false });
  assert.deepEqual(usedOptions({ sizes: [NONE1], colors: [noColour] }), { option1: false, option2: false });
  assert.deepEqual(usedOptions({ sizes: [], colors: [] }), { option1: false, option2: false });
});

test('the stock line names only the options the product uses', () => {
  assert.equal(stockMessageFor(variant(1, 'M', navy, 0), 4), `Sold out in this ${option2Word} and ${option1Word}.`);
  assert.equal(stockMessageFor(variant(1, 'M', noColour, 0), 2), `Sold out in this ${option1Word}.`);
  assert.equal(stockMessageFor(variant(1, NONE1, navy, 0), 2), `Sold out in this ${option2Word}.`);
  assert.equal(stockMessageFor(variant(1, NONE1, noColour, 0), 1), 'Sold out.');

  assert.equal(stockMessageFor(variant(1, 'M', navy, 3), 4), `Only 3 left in this ${option1Word}.`);
  assert.equal(stockMessageFor(variant(1, NONE1, navy, 3), 2), `Only 3 left in this ${option2Word}.`);
  assert.equal(stockMessageFor(variant(1, NONE1, noColour, 3), 1), 'Only 3 left.');
  assert.equal(stockMessageFor(variant(1, 'M', navy, 6), 4), null, 'plenty in stock: nothing to say');

  assert.equal(stockMessageFor(null, 4), `Choose ${withArticle(option1Word)} to continue.`);
  assert.equal(stockMessageFor(null, 0), 'This product is not available to order right now.');
  for (const message of [stockMessageFor(variant(1, NONE1, noColour, 0), 1), stockMessageFor(variant(1, NONE1, noColour, 2), 1)]) {
    assert.ok(!message.includes(NONE1) && !message.includes(NONE2));
  }
});

test('a chosen option 2 with nothing in stock says "sold out", not "choose"', () => {
  // Navy has an M in stock; Red is made in S and M and both are gone.
  const variants = [variant(1, 'S', navy, 0), variant(2, 'M', navy, 2), variant(3, 'S', red, 0), variant(4, 'M', red, 0)];
  assert.equal(soldOutColorName(variants, red), 'Red');
  assert.equal(soldOutColorName(variants, navy), null, 'something is left in it');
  assert.equal(soldOutColorName(variants, null), null);
  assert.equal(soldOutColorName(variants, { name: 'Green', hex: '#0f0' }), null, 'not made in it: not "sold out"');

  // The shopper had M in Navy and picks Red: no size is chosen, because none can be.
  const size = sizeAfterColorChange(variants, red, 'M');
  assert.equal(size, null);
  const chosen = findVariant(variants, red, size);
  assert.equal(chosen, null);
  assert.equal(stockMessageFor(chosen, variants.length, soldOutColorName(variants, red)), `Sold out in this ${option2Word}.`);
  // Back to Navy with S (sold out there, M is not): there is a choice to make, so it is asked for.
  assert.equal(stockMessageFor(findVariant(variants, navy, null), variants.length, soldOutColorName(variants, navy)), `Choose ${withArticle(option1Word)} to continue.`);

  // A product that does not use option 2 never has its "none" value named.
  const plain = [variant(1, 'S', noColour, 0), variant(2, 'M', noColour, 0)];
  assert.equal(stockMessageFor(null, plain.length, soldOutColorName(plain, noColour)), 'Sold out.');
  // Every other message is as it was, whatever the third argument.
  assert.equal(stockMessageFor(variant(3, 'S', red, 0), 4, 'Red'), `Sold out in this ${option2Word} and ${option1Word}.`);
  assert.equal(stockMessageFor(variant(2, 'M', navy, 2), 4, null), `Only 2 left in this ${option1Word}.`);
  assert.equal(stockMessageFor(null, 0, 'Red'), 'This product is not available to order right now.');
});

test('the phone bar shows the chosen options, or what is still to choose', () => {
  const both = { sizes: ['M', 'L'], colors: [navy, red] };
  assert.equal(selectionLabelFor(variant(1, 'M', navy, 5), both), 'M • Navy');
  assert.equal(selectionLabelFor(null, both), `Choose ${option2Word} & ${option1Word}`);
  assert.equal(selectionLabelFor(variant(1, '50ml', noColour, 5), { sizes: ['50ml'], colors: [noColour] }), '50ml');
  assert.equal(selectionLabelFor(null, { sizes: ['50ml', '100ml'], colors: [noColour] }), `Choose ${option1Word}`);
  assert.equal(selectionLabelFor(null, { sizes: [NONE1], colors: [navy, red] }), `Choose ${option2Word}`);
  // A product with no options has nothing to show and nothing to choose.
  const plain = { sizes: [NONE1], colors: [noColour] };
  assert.equal(selectionLabelFor(variant(1, NONE1, noColour, 5), plain), '');
  assert.equal(selectionLabelFor(null, plain), '');
});

test('a product with no options has one variant, already chosen', () => {
  const variants = [variant(7, NONE1, noColour, 4)];
  const first = firstChoice({ variants });
  assert.equal(first.id, 7);
  assert.equal(findVariant(variants, first.color, first.size).id, 7);
  assert.deepEqual(usedOptions({ sizes: [NONE1], colors: [noColour] }), { option1: false, option2: false });
});

test('changing option 2 keeps the one value of an option 1 the product does not use', () => {
  const variants = [variant(1, NONE1, navy, 3), variant(2, NONE1, red, 0)];
  // In stock or sold out, there is nothing to pick again: the variant is found and its own
  // stock line is shown ("sold out"), never "choose a size".
  assert.equal(sizeAfterColorChange(variants, navy, NONE1), NONE1);
  assert.equal(sizeAfterColorChange(variants, red, NONE1), NONE1);
  assert.equal(sizeAfterColorChange(variants, red, null), NONE1);
  assert.equal(stockMessageFor(findVariant(variants, red, sizeAfterColorChange(variants, red, NONE1)), 2), `Sold out in this ${option2Word}.`);
  // A product that does use option 1 behaves as before.
  const sized = [variant(1, 'S', navy, 0), variant(2, 'M', navy, 2), variant(3, 'S', red, 1)];
  assert.equal(sizeAfterColorChange(sized, navy, 'S'), null);
  assert.equal(sizeAfterColorChange(sized, navy, null), 'M');
  assert.equal(sizeAfterColorChange(sized, red, 'S'), 'S');
});

test('an order line for WhatsApp names both options, and never an unused one', () => {
  const line = (variant_options) => ({ item_name: 'Item', quantity: '2', line_total: '3000', variant_options });
  const [both, sameSize, onlyOne, onlyTwo, neither, missing] = whatsAppItems([
    line({ size: 'M', color: 'Navy' }),
    line({ size: 'M', color: 'Red' }),
    line({ size: '50ml', color: NONE2 }),
    line({ size: NONE1, color: 'Vanilla' }),
    line({ size: NONE1, color: NONE2 }),
    line(null),
  ]);
  assert.equal(both.size, 'M · Navy');
  // Two lines of one product that differ only in option 2 can be told apart.
  assert.equal(sameSize.size, 'M · Red');
  assert.notEqual(both.size, sameSize.size);
  assert.equal(onlyOne.size, '50ml', 'no stray separator');
  assert.equal(onlyTwo.size, 'Vanilla');
  assert.equal(neither.size, undefined);
  assert.equal(missing.size, undefined);
  assert.deepEqual([both.quantity, both.line_total], [2, 3000]);
  assert.deepEqual(whatsAppItems(null), []);
});
