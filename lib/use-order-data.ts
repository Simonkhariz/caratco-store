'use client';

/**
 * Order values remembered on this device (see lib/pending-order.ts), read as React state:
 * the component re-renders when they change in this tab or another one. On the server and
 * during hydration they read as "not set", so the first render matches the server HTML.
 */
import { useSyncExternalStore } from 'react';
import { readGuestToken, readLookupEmail, subscribeToOrderData, wasInvoiceSent } from '@/lib/pending-order';

/** The guest access key saved for this order, if any. */
export function useGuestToken(orderNumber: string | null | undefined): string | undefined {
  return useSyncExternalStore(
    subscribeToOrderData,
    () => (orderNumber ? readGuestToken(orderNumber) : undefined),
    () => undefined
  );
}

/** The checkout email this tab looked the order up with ("Track order"), if any. */
export function useLookupEmail(orderNumber: string | null | undefined): string | undefined {
  return useSyncExternalStore(
    subscribeToOrderData,
    () => (orderNumber ? readLookupEmail(orderNumber) : undefined),
    () => undefined
  );
}

/** The order most recently placed on this device, if any. */
export function useLatestOrderNumber(): string | null {
  return useSyncExternalStore(
    subscribeToOrderData,
    () => {
      try {
        return localStorage.getItem('shopco_latest_order_number');
      } catch {
        return null;
      }
    },
    () => null
  );
}

/** Whether the customer already sent the WhatsApp invoice for this order. */
export function useInvoiceSent(orderNumber: string | null | undefined): boolean {
  return useSyncExternalStore(
    subscribeToOrderData,
    () => (orderNumber ? wasInvoiceSent(orderNumber) : false),
    () => false
  );
}
