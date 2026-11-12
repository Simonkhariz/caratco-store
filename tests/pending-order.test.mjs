// What this device remembers about orders (lib/pending-order.ts). Run with: npm run test:web
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

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

const {
  cartSignature,
  clearCheckoutDraft,
  forgetOrdersOnThisDevice,
  readCheckoutDraft,
  readGuestToken,
  readPendingOrder,
  saveCheckoutDraft,
  saveGuestToken,
  saveLookupEmail,
  markInvoiceSent,
  savePendingOrder,
  signatureQuantities,
  wasInvoiceSent,
} = await import('../lib/pending-order.ts');

test('a cart signature gives back the quantities of each variant', () => {
  const signature = cartSignature([
    { id: '12', variantId: 12, quantity: 2 },
    { id: '7', variantId: 7, quantity: 1 },
  ]);
  const quantities = signatureQuantities(signature);
  assert.equal(quantities.get('12'), 2);
  assert.equal(quantities.get('7'), 1);
  assert.equal(quantities.get('99'), undefined);
  assert.equal(signatureQuantities('').size, 0);
});

test('signing out leaves nothing that opens an order', () => {
  saveGuestToken('ORD-1', 'key-1');
  saveGuestToken('ORD-2', 'key-2', false);
  saveLookupEmail('ORD-1', 'a@example.com');
  markInvoiceSent('ORD-1');
  savePendingOrder({ orderNumber: 'ORD-1', cartSignature: '12:1', expiresAt: null, createdAt: '2099-01-01 00:00:00' });
  saveCheckoutDraft({ fullName: 'Ada' });
  localStorage.setItem('shopco_cart_guest', '[]');

  forgetOrdersOnThisDevice();

  assert.equal(readGuestToken('ORD-1'), undefined);
  assert.equal(readGuestToken('ORD-2'), undefined);
  assert.equal(localStorage.getItem('shopco_latest_order_number'), null);
  assert.equal(readPendingOrder(), null);
  assert.equal(wasInvoiceSent('ORD-1'), false);
  assert.deepEqual(sessionStorage.keys(), []);
  assert.deepEqual(localStorage.keys(), ['shopco_cart_guest'], 'other shop data stays');
});

test('typed delivery details are kept for the tab until cleared', () => {
  assert.equal(readCheckoutDraft(), null);
  saveCheckoutDraft({ fullName: 'Ada', city: 'Enugu' });
  assert.deepEqual(readCheckoutDraft(), { fullName: 'Ada', city: 'Enugu' });
  assert.deepEqual(localStorage.keys(), [], 'never in storage shared between tabs');
  clearCheckoutDraft();
  assert.equal(readCheckoutDraft(), null);
});
