/**
 * Coupon rules for the cart preview. The server (backend/src/Services/CouponRules.php) decides
 * the real discount at checkout; this mirrors it so the cart total shown while shopping matches.
 * No "@/" imports: the web unit tests load this file directly.
 */

export type DiscountType = 'percent' | 'fixed';

export interface CouponRule {
  discountType: DiscountType;
  /** For 'percent' codes. */
  discountPercent: number;
  /** For 'fixed' codes: the amount taken off. */
  discountValue: number;
  /** The items subtotal must be at least this (null: no minimum). */
  minOrderAmount: number | null;
  /** A percentage discount never takes off more than this (null: no cap). */
  maxDiscountAmount: number | null;
}

/** The fields the promo check and the homepage send, as the storefront names them. */
export function couponRuleFrom(res: {
  discount_type?: string | null;
  discount_percent?: number | null;
  discount_value?: number | null;
  min_order_amount?: number | null;
  max_discount_amount?: number | null;
}): CouponRule {
  const num = (v: unknown): number | null => (v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) || Number(v) <= 0 ? null : Number(v));
  const type: DiscountType = res.discount_type === 'fixed' ? 'fixed' : 'percent';
  return {
    discountType: type,
    discountPercent: type === 'percent' ? Number(res.discount_percent) || 0 : 0,
    discountValue: type === 'fixed' ? num(res.discount_value) ?? 0 : 0,
    minOrderAmount: num(res.min_order_amount),
    maxDiscountAmount: type === 'percent' ? num(res.max_discount_amount) : null,
  };
}

const round2 = (v: number) => Math.round(v * 100) / 100;

/** The amount off for this items subtotal: 0 below the minimum, never more than the subtotal. */
export function couponDiscount(rule: CouponRule, subtotal: number): number {
  if (!(subtotal > 0)) return 0;
  if (rule.minOrderAmount !== null && subtotal + 0.001 < rule.minOrderAmount) return 0;
  let discount = rule.discountType === 'fixed' ? rule.discountValue : round2((subtotal * rule.discountPercent) / 100);
  if (rule.maxDiscountAmount !== null) discount = Math.min(discount, rule.maxDiscountAmount);
  return round2(Math.min(discount, subtotal));
}

/** How much more the shopper must add before the code works (0 when it already does). */
export function couponShortfall(rule: CouponRule, subtotal: number): number {
  if (rule.minOrderAmount === null) return 0;
  return Math.max(0, round2(rule.minOrderAmount - subtotal));
}
