'use client';

import React, { useEffect, useState } from 'react';
import { Product } from '@/types';
import { formatPrice } from '@/lib/currency';
import { useHydrated } from '@/lib/use-hydrated';
import { HugeiconsIcon } from '@hugeicons/react';
import { ShoppingBag01Icon, FireIcon } from '@hugeicons/core-free-icons';

interface StickyMobileBarProps {
  product: Product;
  /** Price of the selected variant. */
  price: number;
  /** e.g. "Large • Black", or a prompt to choose options. '' for a product with no options. */
  selectionLabel: string;
  canPurchase: boolean;
  onAddToCart: () => void;
  onBuyNow: () => void;
}

export const StickyMobileBar: React.FC<StickyMobileBarProps> = ({
  product,
  price,
  selectionLabel,
  canPurchase,
  onAddToCart,
  onBuyNow,
}) => {
  const ready = useHydrated();
  // While the page's own quantity / Add to Cart row is on screen this bar would only repeat it
  // (two identical buttons, one overlapping the other), so it waits below the screen edge.
  const [mainOnScreen, setMainOnScreen] = useState(false);
  useEffect(() => {
    const main = document.querySelector('[data-main-purchase]');
    if (!main || typeof IntersectionObserver === 'undefined') return;
    const watcher = new IntersectionObserver(([entry]) => setMainOnScreen(entry.isIntersecting), { threshold: 0.6 });
    watcher.observe(main);
    return () => watcher.disconnect();
  }, []);
  return (
    // z-30: under the header (z-40), whose phone menu opens above this bar. data-sticky-purchase-bar
    // makes room for it below the footer (globals.css).
    <div
      role="region"
      aria-label="Quick purchase"
      data-sticky-purchase-bar
      data-off-screen={mainOnScreen || undefined}
      inert={mainOnScreen}
      className={`md:hidden fixed bottom-0 left-0 right-0 z-30 bg-white/95 backdrop-blur-md border-t border-neutral-200 p-3 px-4 shadow-2xl flex items-center justify-between gap-3 transition-transform duration-200 ${
        mainOnScreen ? 'translate-y-full' : ''
      }`}
    >
      <div className="flex flex-col min-w-0">
        {selectionLabel && <span className="text-xs text-neutral-600 font-medium truncate max-w-[140px]">{selectionLabel}</span>}
        <div className="flex items-baseline gap-1.5">
          <span className="font-bold text-lg text-black">{formatPrice(price)}</span>
          {product.originalPrice && product.originalPrice > price && (
            <span className="text-xs text-neutral-600 line-through">
              <span className="sr-only">Was </span>
              {formatPrice(product.originalPrice)}
            </span>
          )}
        </div>
      </div>

      <div className="flex items-center gap-2 flex-1 max-w-[240px]">
        <button
          type="button"
          onClick={onAddToCart}
          disabled={!canPurchase || !ready}
          className="flex-1 bg-accent text-accent-ink active:bg-accent-hover text-xs font-bold py-3 rounded-full flex items-center justify-center gap-1.5 shadow-md disabled:bg-neutral-300 disabled:text-neutral-700 disabled:shadow-none"
        >
          <HugeiconsIcon icon={ShoppingBag01Icon} size={14} aria-hidden="true" />
          <span>{canPurchase ? 'Add to Cart' : 'Unavailable'}</span>
        </button>
        <button
          type="button"
          onClick={onBuyNow}
          disabled={!canPurchase || !ready}
          className="bg-surface active:bg-neutral-200 text-black text-xs font-bold p-3 rounded-full flex items-center justify-center shadow-sm disabled:opacity-50"
          title="Buy now"
          aria-label={canPurchase ? `Buy ${product.name} now` : 'Buy now (unavailable)'}
        >
          <HugeiconsIcon icon={FireIcon} size={14} className="text-red-500 fill-current" aria-hidden="true" />
        </button>
      </div>
    </div>
  );
};
