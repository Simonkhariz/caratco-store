/**
 * Formatting and status wording for the admin screens.
 */

import { formatPrice } from '@/lib/currency';
import type { StatusTone } from '@/lib/order-status';

export { formatPrice };

/** What the sales figures per product and category mean (see the API's report formulas). */
export const ITEM_SALES_NOTE = 'Item prices before discounts and delivery. Cancelled and refunded orders are left out.';

/** Numbers from MySQL arrive as strings; treat anything unparsable as 0. */
export function num(value: unknown): number {
  const n = typeof value === 'number' ? value : parseFloat(String(value ?? ''));
  return Number.isFinite(n) ? n : 0;
}

function toDate(value: string | null | undefined): Date | null {
  if (!value) return null;
  const d = new Date(value.includes('T') ? value : value.replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : d;
}

export function formatDate(value: string | null | undefined): string {
  const d = toDate(value);
  return d ? d.toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}

export function formatDateTime(value: string | null | undefined): string {
  const d = toDate(value);
  return d
    ? d.toLocaleString('en-NG', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })
    : '—';
}

export function formatRelative(value: string | null | undefined): string {
  const d = toDate(value);
  if (!d) return '—';
  const minutes = Math.round((Date.now() - d.getTime()) / 60000);
  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${minutes} min ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} h ago`;
  const days = Math.round(hours / 24);
  return days < 30 ? `${days} d ago` : formatDate(value);
}

export function humanize(value: string | null | undefined): string {
  if (!value) return '—';
  const s = value.replace(/[._]/g, ' ').trim();
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export interface AdminStatus {
  label: string;
  tone: StatusTone;
}

export const ORDER_STATUS: Record<string, AdminStatus> = {
  pending_payment: { label: 'Awaiting payment', tone: 'amber' },
  paid: { label: 'Paid – to pack', tone: 'blue' },
  processing: { label: 'Processing', tone: 'blue' },
  shipped: { label: 'Shipped', tone: 'blue' },
  delivered: { label: 'Delivered', tone: 'green' },
  completed: { label: 'Completed', tone: 'green' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
  returned: { label: 'Returned', tone: 'amber' },
  refund_pending: { label: 'Refund pending', tone: 'amber' },
  refunded: { label: 'Refunded', tone: 'neutral' },
};

export const PAYMENT_STATUS: Record<string, AdminStatus> = {
  pending: { label: 'Pending', tone: 'amber' },
  successful: { label: 'Paid', tone: 'green' },
  failed: { label: 'Failed', tone: 'red' },
  partially_refunded: { label: 'Partly refunded', tone: 'amber' },
  refunded: { label: 'Refunded', tone: 'neutral' },
  abandoned: { label: 'Abandoned', tone: 'neutral' },
};

/** Action verbs for moving an order to a status (used on buttons). */
export const ORDER_TRANSITION_LABELS: Record<string, string> = {
  processing: 'Mark processing',
  shipped: 'Ship order',
  delivered: 'Mark delivered',
  completed: 'Mark completed',
  cancelled: 'Cancel order',
  returned: 'Mark returned',
  refund_pending: 'Start refund',
};

export function orderStatus(status: string | null | undefined): AdminStatus {
  return (status && ORDER_STATUS[status]) || { label: humanize(status), tone: 'neutral' };
}

export function paymentStatus(status: string | null | undefined): AdminStatus {
  return (status && PAYMENT_STATUS[status]) || { label: humanize(status), tone: 'neutral' };
}

export const GATEWAY_LABELS: Record<string, string> = {
  kora: 'Kora',
  monnify: 'Monnify',
  whatsapp: 'WhatsApp / transfer',
  manual: 'Manual',
};

export function gatewayLabel(gateway: string | null | undefined): string {
  return (gateway && GATEWAY_LABELS[gateway]) || humanize(gateway);
}
