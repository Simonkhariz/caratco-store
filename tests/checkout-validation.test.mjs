// Delivery details at checkout (lib/checkout-validation.ts). Run with: npm run test:web
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  ageConfirmErrorFrom,
  ageConfirmLabel,
  ageConfirmMissingMessage,
  checkoutFieldErrorsFrom,
  cleanPhone,
  firstValidationMessage,
  validateCheckoutDetails,
} from '../lib/checkout-validation.ts';

const good = { fullName: 'Ada Obi', phone: '08031234567', address: '12 Okpara Avenue', city: 'Enugu' };

test('real details pass', () => {
  assert.deepEqual(validateCheckoutDetails(good), {});
  assert.deepEqual(validateCheckoutDetails({ ...good, fullName: 'Ọ̀ṣun Adé', phone: '+234 803 123 4567' }), {});
  assert.deepEqual(validateCheckoutDetails({ ...good, phone: '0803-123-4567' }), {});
});

test('the junk that used to be accepted is refused, each on its own field', () => {
  const errors = validateCheckoutDetails({ fullName: '.', phone: '1', address: '.', city: '.' });
  assert.deepEqual(Object.keys(errors).sort(), ['address', 'city', 'fullName', 'phone']);
});

test('an address or city of only punctuation is refused', () => {
  const errors = validateCheckoutDetails({ ...good, address: '...', city: '-- -' });
  assert.deepEqual(Object.keys(errors).sort(), ['address', 'city']);
  assert.deepEqual(validateCheckoutDetails({ ...good, address: '1 A', city: 'Aba' }), {});
});

test('a name needs two letters, not two characters', () => {
  assert.ok(validateCheckoutDetails({ ...good, fullName: 'A.' }).fullName);
  assert.ok(validateCheckoutDetails({ ...good, fullName: '12' }).fullName);
  assert.equal(validateCheckoutDetails({ ...good, fullName: 'Al' }).fullName, undefined);
});

test('a Nigerian phone number is 0 and 10 digits, or 234 and 10 digits', () => {
  for (const phone of ['0803123456', '080312345678', '8031234567', '+1 555 123 4567', '0803123456a', '+23480312345', '+44 20 7946 0958']) {
    assert.ok(validateCheckoutDetails({ ...good, phone }).phone, phone);
  }
  for (const phone of ['2348031234567', '234 803 123 4567', '+234 0803 123 4567', '+2348031234567']) {
    assert.equal(validateCheckoutDetails({ ...good, phone }).phone, undefined, phone);
  }
  assert.equal(cleanPhone(' (0803) 123-4567 '), '08031234567');
});

test('address and city need three characters after trimming', () => {
  assert.ok(validateCheckoutDetails({ ...good, address: ' ab ' }).address);
  assert.ok(validateCheckoutDetails({ ...good, city: ' ab ' }).city);
  assert.equal(validateCheckoutDetails({ ...good, city: 'Aba' }).city, undefined);
});

test('the first message of a 422 answer, whatever its shape', () => {
  assert.equal(firstValidationMessage({ full_name: 'Full name is required.', email: 'Bad email.' }), 'Full name is required.');
  assert.equal(firstValidationMessage({ phone: ['Too short.', 'Not a number.'] }), 'Too short.');
  assert.equal(firstValidationMessage({ shipping_address: { city: 'City is required.' } }), 'City is required.');
  assert.equal(firstValidationMessage({}), null);
  assert.equal(firstValidationMessage(undefined), null);
});

test('a 422 answer lands on the checkout fields', () => {
  assert.deepEqual(
    checkoutFieldErrorsFrom({ 'shipping_address.full_name': 'Name?', phone: ['Phone?'], address_line1: 'Address?', unknown: 'x' }),
    { fullName: 'Name?', phone: 'Phone?', address: 'Address?' }
  );
  assert.deepEqual(checkoutFieldErrorsFrom({ shipping_address: { city: 'City?' } }), { city: 'City?' });
  assert.deepEqual(checkoutFieldErrorsFrom(undefined), {});
});

test('the age tick box uses the age the server gave', () => {
  assert.equal(ageConfirmLabel(18), 'I confirm that I am 18 or older.');
  assert.equal(ageConfirmLabel(21), 'I confirm that I am 21 or older.');
  assert.equal(ageConfirmMissingMessage(18), 'Please confirm that you are 18 or older to order these items.');
});

test("the server's refusal for a missing age confirmation is found, and nothing else is taken for it", () => {
  const message = 'Please confirm that you are 18 or older to order these items.';
  assert.equal(ageConfirmErrorFrom({ age_confirmed: message }), message);
  assert.equal(ageConfirmErrorFrom({ age_confirmed: [message] }), message);
  assert.equal(ageConfirmErrorFrom({ phone: 'Phone?' }), null);
  assert.equal(ageConfirmErrorFrom(undefined), null);
  assert.equal(ageConfirmErrorFrom('age_confirmed'), null);
  // It is not one of the delivery fields.
  assert.deepEqual(checkoutFieldErrorsFrom({ age_confirmed: message }), {});
});
