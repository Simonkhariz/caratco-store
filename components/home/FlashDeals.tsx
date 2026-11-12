'use client';

import React, { useEffect, useEffectEvent, useSyncExternalStore } from 'react';
import Link from 'next/link';
import { Product } from '@/types';
import { ProductCard } from '@/components/ui/ProductCard';
import { parseShopTime } from '@/lib/shop-time';
import { HugeiconsIcon } from '@hugeicons/react';
import { FireIcon, Clock01Icon, ArrowRight01Icon } from '@hugeicons/core-free-icons';

interface FlashDealsProps {
  products?: Product[];
  /** Real end time of the soonest-ending deal shown (from the API). Null/absent = no countdown. */
  endsAt?: string | null;
  /** Called once when the countdown reaches zero (the deals are over). */
  onExpired?: () => void;
}

// A shared one-second clock, read with useSyncExternalStore: null on the server and while
// hydrating (so no time-dependent markup is rendered there), then the current time.
let clockNow = 0;
function subscribeClock(onTick: () => void) {
  clockNow = Date.now();
  const timer = setInterval(() => {
    clockNow = Date.now();
    onTick();
  }, 1000);
  return () => clearInterval(timer);
}
const subscribeNever = () => () => {};
const readClock = () => clockNow || (clockNow = Date.now());

function useClock(running: boolean): number | null {
  return useSyncExternalStore(running ? subscribeClock : subscribeNever, running ? readClock : () => null, () => null);
}

function parseEnd(value: string | null | undefined): number | null {
  if (!value) return null;
  const ts = parseShopTime(value).getTime();
  return Number.isFinite(ts) ? ts : null;
}

function splitRemaining(ms: number) {
  const total = Math.max(0, Math.floor(ms / 1000));
  return {
    days: Math.floor(total / 86400),
    hours: Math.floor((total % 86400) / 3600),
    minutes: Math.floor((total % 3600) / 60),
    seconds: total % 60,
  };
}

export const FlashDeals: React.FC<FlashDealsProps> = ({ products, endsAt, onExpired }) => {
  const endTs = parseEnd(endsAt);
  // null until hydrated, so server and first client render match (no time-dependent markup).
  const now = useClock(endTs !== null);

  const expired = endTs !== null && now !== null && now >= endTs;

  // Fires once when the countdown runs out, with the latest onExpired.
  const notifyExpired = useEffectEvent(() => onExpired?.());
  useEffect(() => {
    if (expired) notifyExpired();
  }, [expired]);

  const flashProducts = products && products.length > 0 ? products.slice(0, 4) : [];

  if (flashProducts.length === 0 || expired) {
    return null;
  }

  const remaining = endTs !== null && now !== null ? splitRemaining(endTs - now) : null;
  const endLabel =
    endTs !== null
      ? new Date(endTs).toLocaleString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
      : null;

  const units = remaining
    ? [
        ...(remaining.days > 0 ? [{ label: 'days', value: remaining.days }] : []),
        { label: 'hours', value: remaining.hours },
        { label: 'minutes', value: remaining.minutes },
        { label: 'seconds', value: remaining.seconds },
      ]
    : [];

  return (
    <section className="py-12 sm:py-16 bg-neutral-50/70 border-y border-neutral-100" aria-labelledby="flash-deals-heading">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8 sm:mb-12">
          <div className="space-y-1">
            <div className="inline-flex items-center gap-2 bg-red-100 text-red-800 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">
              <HugeiconsIcon icon={FireIcon} size={14} className="text-red-700" aria-hidden="true" />
              {endTs !== null ? 'Limited time deals' : 'Deals'}
            </div>
            <h2 id="flash-deals-heading" className="font-integral text-2xl sm:text-4xl lg:text-5xl text-black tracking-tight">
              FLASH SALE OFFERS
            </h2>
          </div>

          {/* Real countdown: only when the store set an end time */}
          {endTs !== null && endLabel && (
            <div className="flex items-center gap-2 sm:gap-3 bg-white border border-neutral-200 shadow-sm rounded-2xl p-3 px-4 self-start md:self-auto">
              <HugeiconsIcon icon={Clock01Icon} size={20} className="text-neutral-600" aria-hidden="true" />
              <span className="text-xs font-semibold text-neutral-600 uppercase tracking-wider mr-1">Ends in</span>
              <span className="sr-only">Deals end {endLabel}</span>
              <div className="flex items-center gap-1.5 font-mono font-bold text-sm sm:text-base" aria-hidden="true" aria-live="off">
                {remaining ? (
                  units.map((unit, idx) => (
                    <React.Fragment key={unit.label}>
                      {idx > 0 && <span className="text-black font-bold">:</span>}
                      <span className="bg-accent text-accent-ink px-2.5 py-1 rounded-lg" title={unit.label}>
                        {unit.label === 'days' ? `${unit.value}d` : String(unit.value).padStart(2, '0')}
                      </span>
                    </React.Fragment>
                  ))
                ) : (
                  <span className="text-xs text-neutral-600">{endLabel}</span>
                )}
              </div>
            </div>
          )}
        </div>

        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6">
          {flashProducts.map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </div>

        <div className="mt-10 text-center">
          <Link
            href="/category?flash=true"
            className="inline-flex items-center gap-2 bg-accent text-accent-ink hover:bg-accent-hover font-semibold px-10 py-3.5 rounded-full transition-colors text-sm shadow-md"
          >
            <span>View all deals</span>
            <HugeiconsIcon icon={ArrowRight01Icon} size={16} aria-hidden="true" />
          </Link>
        </div>
      </div>
    </section>
  );
};
