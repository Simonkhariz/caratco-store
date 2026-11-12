import { ApiPromotion } from '@/lib/api-client';
import { formatPrice } from '@/lib/currency';

/**
 * The advertised offer in a few words: "₦5,000 off orders of ₦30,000+", "10% off orders of
 * ₦20,000+ (up to ₦10,000)". The minimum spend comes before the cap, so on a narrow screen it is
 * the cap that gets cut short.
 */
export function offerText(p: ApiPromotion): string {
  const fixed = p.discount_type === 'fixed';
  const value = Number(p.discount_value ?? p.discount_percent);
  const off = fixed ? `${formatPrice(value)} off` : `${Number(p.discount_percent || value)}% off`;
  const min = Number(p.min_order_amount ?? 0);
  const cap = Number(p.max_discount_amount ?? 0);
  return [off, min > 0 ? `orders of ${formatPrice(min)}+` : 'your order', !fixed && cap > 0 ? `(up to ${formatPrice(cap)})` : '']
    .filter(Boolean)
    .join(' ');
}

/**
 * The advertised code beside the hero's button: "Use code" and the code as a black tag with white
 * letters, the offer underneath. It sits in the button's row and is never taller than the button,
 * so it adds no height: on phones the hero fills the screen and its picture takes the height that
 * is left, so a code above the heading shrank the picture, one on the picture covered it, and a
 * strip under the menu pushed the first screen past the bottom of the screen.
 */
export function PromoTag({ promo }: { promo: ApiPromotion }) {
  return (
    <div data-promo-code className="min-w-0 leading-tight" title={promo.label || undefined}>
      <p className="flex items-center gap-1.5 whitespace-nowrap text-[11px] text-neutral-500 sm:text-xs">
        <span>Use code</span>
        <span className="rounded-md bg-accent px-2 py-0.5 font-bold tracking-widest text-white">{promo.code}</span>
      </p>
      {/* One line, so the tag is never taller than the button beside it (a second line made the
          row taller and the picture shorter on small phones). */}
      <p className="mt-1 truncate text-[11px] text-neutral-700 sm:text-sm">{offerText(promo)}</p>
    </div>
  );
}
