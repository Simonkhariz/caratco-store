/**
 * Currency formatting utilities for Shop.co Storefront
 * Default currency is Nigerian Naira (NGN - ₦) as established in the system blueprint.
 */

export const STORE_CURRENCY = 'NGN';
export const STORE_CURRENCY_SYMBOL = '₦';

/**
 * Format a numeric amount into Nigerian Naira (₦).
 * Kobo are shown only when the amount has them, so a total is never rounded away from what is
 * actually owed (a percentage coupon can leave kobo).
 * e.g., 14500 -> "₦14,500", 11110.5 -> "₦11,110.50"
 */
export function formatPrice(amount: number | null | undefined): string {
  if (amount === null || amount === undefined || isNaN(amount)) {
    return '₦0';
  }
  const kobo = Math.round(amount * 100);
  const formatted = (kobo / 100).toLocaleString('en-NG', {
    minimumFractionDigits: kobo % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  });
  return `${STORE_CURRENCY_SYMBOL}${formatted}`;
}

/**
 * Format a numeric amount with optional decimal precision.
 */
export function formatCurrency(amount: number | null | undefined, includeDecimals = false): string {
  if (amount === null || amount === undefined || isNaN(amount)) {
    return '₦0.00';
  }
  if (!includeDecimals) {
    return formatPrice(amount);
  }
  const formatted = amount.toLocaleString('en-NG', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return `${STORE_CURRENCY_SYMBOL}${formatted}`;
}
