import { queryOptions } from '@tanstack/react-query';
import { apiGetOrder, apiVerifyPayment, type PaymentVerificationResponse } from '@/lib/api-client';

/**
 * TanStack Query options for the payment return page (app/checkout/callback). Read-only:
 * these only ask the server what it knows. Starting a payment, "Mark as paid" and every other
 * change stay plain calls that run once and are never retried.
 *
 * Keys hold the order, the guest access key saved on this device and the gateway reference,
 * so a different key or reference is a different question (and never reuses another answer).
 * They live in this tab's memory only and are cleared on sign-out.
 */
export const paymentKeys = {
  status: (orderNumber: string, token: string | null, reference: string | null) => ['payment-status', orderNumber, token, reference] as const,
  order: (orderNumber: string, token: string | null, stage: 'open' | 'paid') => ['payment-order', orderNumber, token, stage] as const,
};

/** Poll delays (ms) while a payment is still settling: about two minutes in total. */
export const POLL_DELAYS = [3000, 3000, 5000, 5000, 8000, 10000, 15000, 15000, 20000, 20000, 20000];

export type PaymentView = 'paid' | 'pending' | 'failed' | 'cancelled' | 'late';

/** What the page shows for an answer from verify-payment. */
export function paymentViewFor(res: PaymentVerificationResponse): PaymentView {
  const orderStatus = String(res.order_status || '');
  if (res.status === 'successful') return orderStatus === 'cancelled' ? 'late' : 'paid';
  if (orderStatus === 'cancelled') return 'cancelled';
  if (res.status === 'failed') return 'failed';
  return 'pending';
}

/**
 * The payment's status as the server sees it (the server asks the gateway only while it is
 * still pending, and records a result once). Safe to repeat: one retry after a network or
 * server error, as for every query.
 */
export const paymentStatusQuery = (orderNumber: string, token: string | null, reference: string | null, email: string | null = null) =>
  queryOptions({
    queryKey: paymentKeys.status(orderNumber, token, reference),
    queryFn: ({ signal }) => apiVerifyPayment(orderNumber, token ?? undefined, reference ?? undefined, signal, email ?? undefined),
  });

/**
 * The order for the receipt. Asked once while the payment is open and once more when it is
 * paid. Without the access key or sign-in the server refuses it: the status still shows.
 */
export const paymentOrderQuery = (orderNumber: string, token: string | null, stage: 'open' | 'paid', email: string | null = null) =>
  queryOptions({
    queryKey: paymentKeys.order(orderNumber, token, stage),
    queryFn: ({ signal }) => apiGetOrder(orderNumber, token ? { token } : email ? { email } : undefined, signal),
    staleTime: 5 * 60_000,
    retry: false,
  });
