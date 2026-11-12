'use client';

import React from 'react';
import Image from '@/components/ui/StoreImage';
import Link from 'next/link';
import { useCart, MAX_QUANTITY_PER_LINE } from '@/context/CartContext';
import { HugeiconsIcon } from '@hugeicons/react';
import { Delete02Icon, MinusSignIcon, PlusSignIcon } from '@hugeicons/core-free-icons';
import { formatPrice } from '@/lib/currency';
import { SHOP, hasOption1, hasOption2, nameWithOptions, optionsText } from '@/lib/shop';

export const CartItemList: React.FC = () => {
  const { items, updateQuantity, removeFromCart, cartNotices, dismissCartNotices } = useCart();

  const notices = cartNotices.length > 0 && (
    <div role="status" className="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="font-semibold">Your cart was updated</p>
          <ul className="mt-1 list-disc space-y-0.5 pl-5">
            {cartNotices.map((notice) => (
              <li key={notice}>{notice}</li>
            ))}
          </ul>
        </div>
        <button type="button" onClick={dismissCartNotices} className="shrink-0 text-sm font-semibold underline hover:no-underline">
          Dismiss
        </button>
      </div>
    </div>
  );

  if (items.length === 0) {
    return (
      <div>
        {notices}
        <div className="border border-neutral-200 rounded-3xl p-12 text-center bg-white">
          <h2 className="font-bold text-xl text-black mb-2">Your cart is empty</h2>
          <p className="text-sm text-neutral-600 mb-6 max-w-sm mx-auto">{SHOP.wording.emptyCart}</p>
          <Link
            href="/category"
            className="inline-block bg-accent text-accent-ink px-8 py-3.5 rounded-full text-sm font-semibold hover:bg-accent-hover transition-colors shadow-md"
          >
            Start shopping
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div>
      {notices}
      <ul aria-label="Items in your cart" className="border border-neutral-200 rounded-3xl p-4 sm:p-6 bg-white divide-y divide-neutral-200">
        {items.map((item) => {
          const maxQty = Math.min(MAX_QUANTITY_PER_LINE, item.maxStock ?? MAX_QUANTITY_PER_LINE);
          const atMax = item.quantity >= maxQty;
          return (
            <li key={item.id} className="py-4 sm:py-6 first:pt-0 last:pb-0 flex gap-4 sm:gap-6 items-start">
              {/* The image repeats the product link below, so it is hidden from keyboard and screen readers. */}
              <Link
                href={`/products/${item.slug}`}
                tabIndex={-1}
                aria-hidden="true"
                className="relative w-24 h-24 sm:w-32 sm:h-32 bg-card rounded-2xl overflow-hidden shrink-0"
              >
                <Image src={item.image} alt="" fill sizes="128px" className="object-cover hover:scale-105 transition-transform" />
              </Link>

              <div className="flex-1 min-w-0 flex flex-col justify-between min-h-24 sm:min-h-32 gap-2">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <Link
                      href={`/products/${item.slug}`}
                      className="font-bold text-base sm:text-lg text-black hover:text-neutral-700 transition-colors line-clamp-2"
                    >
                      {item.name}
                    </Link>
                    {/* Only the options this product uses: a line for the "none" value would read as a choice. */}
                    {hasOption1(item.size) && (
                      <p className="text-sm text-neutral-600 mt-0.5">
                        <span className="text-black font-medium">{SHOP.option1.label}:</span> {item.size}
                      </p>
                    )}
                    {hasOption2(item.color?.name) && (
                      <p className={`text-sm text-neutral-600 ${hasOption1(item.size) ? '' : 'mt-0.5'}`}>
                        <span className="text-black font-medium">{SHOP.option2.label}:</span> {item.color.name}
                      </p>
                    )}
                  </div>

                  <button
                    type="button"
                    onClick={() => removeFromCart(item.id)}
                    className="-m-1.5 shrink-0 rounded-full p-2.5 text-red-600 transition-colors hover:bg-red-50 hover:text-red-800"
                    aria-label={`Remove ${nameWithOptions(item.name, optionsText(item.size, item.color?.name, ', '))} from cart`}
                  >
                    <HugeiconsIcon icon={Delete02Icon} size={20} aria-hidden="true" />
                  </button>
                </div>

                <div className="flex items-center justify-between gap-3 mt-auto">
                  <div className="min-w-0">
                    <span className="font-bold text-lg sm:text-xl text-black">{formatPrice(item.price)}</span>
                    {item.quantity > 1 && (
                      <span className="block text-xs text-neutral-600">{formatPrice(item.price * item.quantity)} for {item.quantity}</span>
                    )}
                  </div>

                  <div className="flex flex-col items-end gap-1">
                    <div
                      role="group"
                      aria-label={`Quantity of ${item.name}`}
                      className="flex items-center bg-surface rounded-full p-1 gap-1 sm:gap-2"
                    >
                      <button
                        type="button"
                        onClick={() => updateQuantity(item.id, item.quantity - 1)}
                        className="text-black hover:bg-black/5 rounded-full p-2.5"
                        aria-label={item.quantity <= 1 ? `Remove ${item.name} from cart` : `Decrease quantity of ${item.name}`}
                      >
                        <HugeiconsIcon icon={MinusSignIcon} size={14} aria-hidden="true" />
                      </button>
                      <span className="font-bold text-sm text-black min-w-[1rem] text-center" aria-live="polite" aria-atomic="true">
                        <span className="sr-only">Quantity </span>
                        {item.quantity}
                      </span>
                      <button
                        type="button"
                        onClick={() => updateQuantity(item.id, item.quantity + 1)}
                        disabled={atMax}
                        className="text-black hover:bg-black/5 rounded-full p-2.5 disabled:opacity-30"
                        aria-label={`Increase quantity of ${item.name}`}
                      >
                        <HugeiconsIcon icon={PlusSignIcon} size={14} aria-hidden="true" />
                      </button>
                    </div>
                    {typeof item.maxStock === 'number' && item.maxStock <= 5 ? (
                      <span className="text-xs text-amber-800">Only {item.maxStock} left</span>
                    ) : atMax ? (
                      <span className="text-xs text-neutral-600">
                        {maxQty < MAX_QUANTITY_PER_LINE ? `Only ${maxQty} in stock` : `Maximum ${maxQty} per order`}
                      </span>
                    ) : null}
                  </div>
                </div>
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
};
