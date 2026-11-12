'use client';

import React, { useState } from 'react';
import { useCart } from '@/context/CartContext';
import { HugeiconsIcon } from '@hugeicons/react';
import {
  Tag01Icon,
  ArrowRight01Icon,
  Tick02Icon,
  Cancel01Icon,
} from '@hugeicons/core-free-icons';
import { formatPrice } from '@/lib/currency';

interface OrderSummaryProps {
  onCheckout: () => void;
  /** Prices and stock are being checked: checkout waits for the answer. */
  isChecking?: boolean;
}

export const OrderSummary: React.FC<OrderSummaryProps> = ({ onCheckout, isChecking = false }) => {
  const {
    items,
    subtotal,
    discountAmount,
    total,
    appliedPromo,
    applyPromoCode,
    removePromoCode,
  } = useCart();

  const [promoInput, setPromoInput] = useState('');
  const [promoError, setPromoError] = useState('');
  const [isApplying, setIsApplying] = useState(false);

  const handleApplyPromo = async (e: React.FormEvent) => {
    e.preventDefault();
    setPromoError('');
    if (!promoInput.trim() || isApplying) return;

    setIsApplying(true);
    try {
      const result = await applyPromoCode(promoInput);
      if (!result.success) {
        setPromoError(result.message);
      } else {
        setPromoInput('');
      }
    } finally {
      setIsApplying(false);
    }
  };

  return (
    <div className="border border-neutral-200 rounded-3xl p-6 bg-white space-y-6">
      <h2 className="font-bold text-xl sm:text-2xl text-black">Order summary</h2>

      {/* Financial Calculations */}
      <div className="space-y-4 text-sm sm:text-base" aria-live="polite">
        <div className="flex items-center justify-between text-neutral-600">
          <span>Subtotal</span>
          <span className="font-bold text-black">{formatPrice(subtotal)}</span>
        </div>

        {appliedPromo && discountAmount > 0 && (
          <div className="flex items-center justify-between text-neutral-700">
            <span>Discount ({appliedPromo.code})</span>
            <span className="font-bold text-red-600">-{formatPrice(discountAmount)}</span>
          </div>
        )}

        <div className="flex items-center justify-between text-neutral-700">
          <span>Delivery</span>
          <span className="text-sm text-neutral-600">Calculated at checkout</span>
        </div>

        <hr className="border-neutral-200" />

        <div className="flex items-center justify-between text-base sm:text-lg">
          <span className="font-medium text-black">Total before delivery</span>
          <span className="font-bold text-xl sm:text-2xl text-black">{formatPrice(total)}</span>
        </div>
        <p className="text-xs text-neutral-600">
          Delivery depends on your state and is added at checkout. Prices and stock are confirmed when you place the order.
        </p>
      </div>

      {/* Promo Code Form */}
      <div>
        {appliedPromo ? (
          <div className="bg-neutral-50 border border-neutral-200 rounded-2xl p-3 flex items-center justify-between text-sm">
            <div className="flex items-center gap-2 text-green-700 font-semibold">
              <HugeiconsIcon icon={Tick02Icon} size={16} aria-hidden="true" />
              <span>
                Code <strong className="uppercase">{appliedPromo.code}</strong> applied{appliedPromo.label ? ` (${appliedPromo.label})` : ''}
              </span>
            </div>
            <button
              type="button"
              onClick={removePromoCode}
              className="p-1 text-neutral-600 hover:text-black"
              aria-label={`Remove promo code ${appliedPromo.code}`}
            >
              <HugeiconsIcon icon={Cancel01Icon} size={16} aria-hidden="true" />
            </button>
          </div>
        ) : (
          <form onSubmit={handleApplyPromo} className="space-y-2">
            <div className="flex items-center gap-3">
              <div className="relative flex-1">
                <div className="absolute left-4 top-1/2 -translate-y-1/2 text-neutral-500 pointer-events-none" aria-hidden="true">
                  <HugeiconsIcon icon={Tag01Icon} size={20} />
                </div>
                <label htmlFor="promo-code" className="sr-only">Promo code</label>
                <input
                  id="promo-code"
                  type="text"
                  autoComplete="off"
                  value={promoInput}
                  onChange={(e) => {
                    setPromoInput(e.target.value);
                    setPromoError('');
                  }}
                  placeholder="Add promo code"
                  aria-invalid={promoError ? true : undefined}
                  aria-describedby={promoError ? 'promo-code-error' : undefined}
                  className="w-full bg-surface text-black placeholder:text-neutral-500 text-sm rounded-full pl-12 pr-4 py-3 sm:py-3.5 focus:outline-none focus:ring-2 focus:ring-accent"
                />
              </div>

              <button
                type="submit"
                disabled={isApplying}
                className="bg-accent text-accent-ink hover:bg-accent-hover disabled:opacity-50 text-sm font-semibold px-6 sm:px-8 py-3 sm:py-3.5 rounded-full transition-colors shrink-0"
              >
                {isApplying ? 'Applying…' : 'Apply'}
              </button>
            </div>

            {promoError && (
              <p id="promo-code-error" role="alert" className="text-sm text-red-700 pl-4">{promoError}</p>
            )}
          </form>
        )}
      </div>

      {/* Checkout CTA */}
      <button
        type="button"
        disabled={items.length === 0 || isChecking}
        onClick={onCheckout}
        className="w-full bg-accent text-accent-ink hover:bg-accent-hover disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-accent font-semibold py-4 rounded-full transition-all shadow-lg flex items-center justify-center gap-2 text-sm sm:text-base group"
      >
        <span>{isChecking ? 'Checking your cart…' : 'Go to checkout'}</span>
        <HugeiconsIcon
          icon={ArrowRight01Icon}
          size={20}
          aria-hidden="true"
          className="group-hover:translate-x-1 transition-transform"
        />
      </button>
    </div>
  );
};
