/**
 * The unpaid order created from the current cart, remembered on this device so that
 * reopening checkout resumes it (instead of reserving stock for a duplicate order) and the
 * payment return page knows when it is safe to empty the cart.
 */
import type { CartItem } from '@/types';

const KEY = 'shopco_pending_order';

/** Fired on this tab whenever one of these values changes (other tabs get the 'storage' event). */
const CHANGE_EVENT = 'shopco-order-data';

function notifyChange(): void {
  try {
    window.dispatchEvent(new Event(CHANGE_EVENT));
  } catch {
    // not in a browser
  }
}

/** For useSyncExternalStore: call back when an order value stored on this device changes. */
export function subscribeToOrderData(onChange: () => void): () => void {
  window.addEventListener('storage', onChange);
  window.addEventListener(CHANGE_EVENT, onChange);
  return () => {
    window.removeEventListener('storage', onChange);
    window.removeEventListener(CHANGE_EVENT, onChange);
  };
}

export interface PendingOrder {
  orderNumber: string;
  /** Cart contents the order was created from (variant:quantity list). */
  cartSignature: string;
  expiresAt: string | null;
  createdAt: string;
}

export function cartSignature(items: CartItem[]): string {
  return items
    .map((i) => `${i.variantId ?? i.id}:${i.quantity}`)
    .sort()
    .join('|');
}

/** A cart signature ("variant:quantity|...") as quantities per variant. */
export function signatureQuantities(signature: string): Map<string, number> {
  const quantities = new Map<string, number>();
  for (const part of signature.split('|')) {
    const at = part.lastIndexOf(':');
    if (at > 0) quantities.set(part.slice(0, at), (quantities.get(part.slice(0, at)) ?? 0) + (parseInt(part.slice(at + 1), 10) || 0));
  }
  return quantities;
}

export function savePendingOrder(order: PendingOrder): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(order));
    notifyChange();
  } catch {
    // Storage unavailable: checkout still works, it just won't resume.
  }
}

/**
 * How long this device keeps asking about an unpaid order. Its payment deadline is not used:
 * a WhatsApp transfer extends it on the server, and the store can confirm a transfer after it.
 * The server answers "paid" or "cancelled" well before this.
 */
const PENDING_ORDER_MAX_AGE_MS = 14 * 24 * 60 * 60 * 1000;

export function readPendingOrder(): PendingOrder | null {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as PendingOrder;
    const created = new Date(String(parsed.createdAt ?? '').replace(' ', 'T')).getTime();
    if (Number.isFinite(created) && Date.now() - created > PENDING_ORDER_MAX_AGE_MS) {
      localStorage.removeItem(KEY);
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

export function clearPendingOrder(orderNumber?: string): void {
  try {
    if (orderNumber) {
      const current = readPendingOrder();
      if (current && current.orderNumber !== orderNumber) return;
    }
    localStorage.removeItem(KEY);
    notifyChange();
  } catch {
    // ignore
  }
}

const SETTLED_KEY = 'shopco_settled_orders';

/**
 * Paid orders whose items this device already took out of the cart, so opening the receipt
 * again (or a later background check) never removes items added after the payment.
 */
export function isOrderSettled(orderNumber: string): boolean {
  try {
    const list = JSON.parse(localStorage.getItem(SETTLED_KEY) || '[]');
    return Array.isArray(list) && list.includes(orderNumber);
  } catch {
    return false;
  }
}

export function markOrderSettled(orderNumber: string): void {
  try {
    const raw = JSON.parse(localStorage.getItem(SETTLED_KEY) || '[]');
    const list = Array.isArray(raw) ? raw.filter((n) => n !== orderNumber) : [];
    localStorage.setItem(SETTLED_KEY, JSON.stringify([...list, orderNumber].slice(-30)));
  } catch {
    // ignore
  }
}

/** The customer sent the WhatsApp invoice for this order, so checkout offers "Mark as paid". */
export function markInvoiceSent(orderNumber: string): void {
  try {
    localStorage.setItem(`shopco_whatsapp_invoice_${orderNumber}`, String(Date.now()));
    notifyChange();
  } catch {
    // ignore
  }
}

export function wasInvoiceSent(orderNumber: string): boolean {
  try {
    return localStorage.getItem(`shopco_whatsapp_invoice_${orderNumber}`) !== null;
  } catch {
    return false;
  }
}

/**
 * Guest access tokens are kept per order on this device only (never in URLs). The order also
 * becomes this device's latest order unless `asLatest` is false (a key typed in to look one up).
 */
export function saveGuestToken(orderNumber: string, token: string, asLatest = true): void {
  try {
    localStorage.setItem(`shopco_guest_token_${orderNumber}`, token);
    if (asLatest) localStorage.setItem('shopco_latest_order_number', orderNumber);
    notifyChange();
  } catch {
    // ignore
  }
}

/**
 * The checkout email an order was looked up with on "Track order", kept for this browser tab only
 * (sessionStorage), so its receipt can be opened without the access key.
 */
export function saveLookupEmail(orderNumber: string, email: string): void {
  try {
    sessionStorage.setItem(`shopco_lookup_email_${orderNumber}`, email.trim().toLowerCase());
    notifyChange();
  } catch {
    // ignore
  }
}

export function readLookupEmail(orderNumber: string): string | undefined {
  try {
    return sessionStorage.getItem(`shopco_lookup_email_${orderNumber}`) || undefined;
  } catch {
    return undefined;
  }
}

export function readGuestToken(orderNumber: string): string | undefined {
  try {
    return localStorage.getItem(`shopco_guest_token_${orderNumber}`) || undefined;
  } catch {
    return undefined;
  }
}

const CHECKOUT_DRAFT_KEY = 'shopco_checkout_draft';

/** Delivery details typed at checkout, kept for this tab so a refresh doesn't lose them. Never payment data. */
export function saveCheckoutDraft(draft: Record<string, unknown>): void {
  try {
    sessionStorage.setItem(CHECKOUT_DRAFT_KEY, JSON.stringify(draft));
  } catch {
    // Storage unavailable: the details just aren't kept over a refresh.
  }
}

export function readCheckoutDraft(): Record<string, unknown> | null {
  try {
    const parsed = JSON.parse(sessionStorage.getItem(CHECKOUT_DRAFT_KEY) || 'null');
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

export function clearCheckoutDraft(): void {
  try {
    sessionStorage.removeItem(CHECKOUT_DRAFT_KEY);
  } catch {
    // ignore
  }
}

function removeKeysStartingWith(storage: Storage, prefixes: string[]): void {
  const keys: string[] = [];
  for (let i = 0; i < storage.length; i++) {
    const key = storage.key(i);
    if (key && prefixes.some((prefix) => key.startsWith(prefix))) keys.push(key);
  }
  keys.forEach((key) => storage.removeItem(key));
}

/**
 * On sign-out, nothing that opens an order stays on this device for the next person: access keys
 * (they show the order's name, address and phone), the recent order, the pending order, lookup
 * emails and the typed delivery details. Which keys belong to the account is not recorded, so
 * all of them go; an order can still be opened with its number and checkout email.
 */
export function forgetOrdersOnThisDevice(): void {
  try {
    removeKeysStartingWith(localStorage, ['shopco_guest_token_', 'shopco_whatsapp_invoice_']);
    localStorage.removeItem('shopco_latest_order_number');
    localStorage.removeItem(KEY);
  } catch {
    // ignore
  }
  try {
    removeKeysStartingWith(sessionStorage, ['shopco_lookup_email_']);
  } catch {
    // ignore
  }
  clearCheckoutDraft();
  notifyChange();
}
