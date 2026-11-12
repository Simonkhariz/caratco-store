/**
 * WhatsApp helpers for order invoices (before payment), receipts (after payment) and support.
 * The store's number comes from the backend (Settings or STORE_WHATSAPP_PHONE, via
 * /checkout/payment-methods). There is no built-in number: without one every link here is ''
 * and the page offers another way to reach the store.
 */

import { SHOP, mainOption } from '@/lib/shop';
import type { ApiOrder, ApiOrderItem } from '@/lib/api-client';

/**
 * International digits for wa.me from a number typed in Nigerian local format:
 * "0803 123 4567", "803 123 4567", "+234 0803..." and "+234 803..." all become "2348031234567".
 * Numbers from other countries are kept as typed. Returns '' when there are no digits.
 */
export function normalizePhoneDigits(phone?: string | null): string {
  let digits = (phone || '').replace(/\D/g, '');
  if (digits.startsWith('00')) digits = digits.slice(2);
  if (digits.length === 11 && digits.startsWith('0')) return `234${digits.slice(1)}`;
  if (digits.length === 10 && /^[789]/.test(digits)) return `234${digits}`;
  if (digits.length === 14 && digits.startsWith('2340')) return `234${digits.slice(4)}`;
  return digits;
}

/** A wa.me link with a prefilled message, or '' when no number is configured. */
function whatsAppUrl(supportPhone: string | null | undefined, text: string): string {
  const digits = normalizePhoneDigits(supportPhone);
  return digits ? `https://wa.me/${digits}?text=${encodeURIComponent(text)}` : '';
}

export interface WhatsAppOrderItem {
  name: string;
  quantity: number;
  price?: number;
  /** The option that names the line ("M", "50ml"); left out for a product with no options. */
  size?: string;
  line_total?: number;
  product_name?: string;
  unit_price?: number;
}

export interface WhatsAppOrderDetails {
  order_number: string;
  grand_total: number;
  subtotal?: number;
  shipping_fee?: number;
  discount_amount?: number;
  items?: WhatsAppOrderItem[];
  guest_email?: string;
  payment_status?: string;
  guest_access_token?: string;
  shipping_address?: {
    full_name?: string;
    phone?: string;
    address_line1?: string;
    city?: string;
    state?: string;
  };
}

/** Order lines as the WhatsApp messages list them. An option the product does not use is never named. */
export function whatsAppItems(items: ApiOrderItem[] | null | undefined): WhatsAppOrderItem[] {
  return (items ?? []).map((it) => ({
    name: it.item_name,
    quantity: Number(it.quantity),
    line_total: Number(it.line_total),
    size: mainOption(it.variant_options?.size, it.variant_options?.color) || undefined,
  }));
}

/** Invoice or receipt details for an order from the order lookup. */
export function whatsAppOrderFrom(order: ApiOrder): WhatsAppOrderDetails {
  return {
    order_number: order.order_number,
    grand_total: Number(order.grand_total),
    subtotal: Number(order.subtotal),
    shipping_fee: Number(order.shipping_fee),
    discount_amount: Number(order.discount_amount),
    items: whatsAppItems(order.items),
    guest_email: order.guest_email ?? undefined,
    shipping_address: order.shipping_address_snapshot ?? undefined,
  };
}

/**
 * The exact amount, kobo included when there are any, so the message says what checkout shows
 * (the same rule as formatPrice in lib/currency; kept here so this file stands alone in tests).
 */
function formatCurrency(amount: number): string {
  const kobo = Math.round(amount * 100);
  const text = (kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: kobo % 100 === 0 ? 0 : 2, maximumFractionDigits: 2 });
  return `₦${text}`;
}

/**
 * Proforma Invoice sent BEFORE payment is completed.
 * Includes complete itemized product list, pricing, subtotal, delivery, and total.
 */
export function buildWhatsAppInvoiceUrl(order: WhatsAppOrderDetails, supportPhone?: string | null): string {
  // A short, polite request the store can act on at once: which order, how much, and what the
  // customer needs back (the account to pay into). The customer can still edit it before sending.
  const amount = Number(order.grand_total);
  return whatsAppUrl(
    supportPhone,
    [
      `Hello! I'd like to pay for my order.`,
      ``,
      `*Order number:* ${order.order_number}`,
      ...(Number.isFinite(amount) && amount > 0 ? [`*Amount to pay:* ${formatCurrency(amount)}`] : []),
      ``,
      `Please send me the account number to pay into. Thank you.`,
    ].join('\n')
  );
}

/**
 * Receipt message after payment: also only the order number (the store has the details).
 */
export function buildWhatsAppReceiptUrl(order: WhatsAppOrderDetails, supportPhone?: string | null): string {
  return whatsAppUrl(supportPhone, order.order_number);
}

/**
 * Builds pre-payment trust link for customers who wish to inquire before placing an order.
 */
export function buildWhatsAppPrePaymentHelpUrl(orderNumber?: string, totalAmount?: number, supportPhone?: string | null): string {
  if (orderNumber && totalAmount) {
    return whatsAppUrl(
      supportPhone,
      [
        `*${SHOP.name} — CUSTOMER INQUIRY*`,
        `━━━━━━━━━━━━━━━━━━━━`,
        `Hello Support! I have an inquiry regarding my order:`,
        ``,
        `• *Order Ref:* ${orderNumber}`,
        `• *Amount:* ${formatCurrency(totalAmount)}`,
        ``,
        `Please assist me before I complete payment. Thank you!`,
      ].join('\n')
    );
  }

  return whatsAppUrl(supportPhone, `Hello ${SHOP.name} Support! I am shopping on your store and have an inquiry.`);
}

/** Plain support chat link with a prefilled message. */
export function buildWhatsAppMessageUrl(message: string, supportPhone?: string | null): string {
  return whatsAppUrl(supportPhone, message);
}

/** Human-readable form of the configured support number ('' when none is set). */
export function formatSupportPhone(supportPhone?: string | null): string {
  const digits = normalizePhoneDigits(supportPhone);
  if (!digits) return '';
  if (digits.startsWith('234') && digits.length === 13) {
    return `+234 ${digits.slice(3, 6)} ${digits.slice(6, 9)} ${digits.slice(9)}`;
  }
  return `+${digits}`;
}
