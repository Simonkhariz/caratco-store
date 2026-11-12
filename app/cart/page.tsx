'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { CartItemList } from '@/components/cart/CartItemList';
import { OrderSummary } from '@/components/cart/OrderSummary';
import { CheckoutModal } from '@/components/cart/CheckoutModal';
import { useCart } from '@/context/CartContext';
import { HugeiconsIcon } from '@hugeicons/react';
import { ArrowRight01Icon } from '@hugeicons/core-free-icons';

export default function CartPage() {
  const [isCheckoutOpen, setIsCheckoutOpen] = useState(false);
  const [isCheckingOut, setIsCheckingOut] = useState(false);
  const { items, isLoaded, revalidateCart, isRevalidating } = useCart();

  // Nothing to check out: the check below may have emptied the cart, or another tab did.
  if (isCheckoutOpen && items.length === 0) setIsCheckoutOpen(false);

  // Confirm current prices and stock whenever the cart page is opened.
  useEffect(() => {
    revalidateCart();
  }, [revalidateCart]);

  const handleCheckout = async () => {
    setIsCheckingOut(true);
    const changed = await revalidateCart();
    setIsCheckingOut(false);
    // A price or quantity was just changed: the shopper sees the notice and the new total first,
    // then presses checkout again (opening it now would hide the notice behind the window).
    if (changed) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }
    setIsCheckoutOpen(true);
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
      <nav aria-label="Breadcrumb" className="flex items-center gap-2 text-sm text-neutral-600 mb-6">
        <Link href="/" className="hover:text-black transition-colors">
          Home
        </Link>
        <HugeiconsIcon icon={ArrowRight01Icon} size={14} className="text-neutral-500" aria-hidden="true" />
        <span className="text-black font-medium" aria-current="page">Cart</span>
      </nav>

      <h1 className="font-integral text-2xl sm:text-4xl text-black uppercase mb-6 sm:mb-8 font-black">
        YOUR CART
      </h1>

      {isLoaded ? (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 sm:gap-8 items-start">
          <div className={items.length === 0 ? 'lg:col-span-12' : 'lg:col-span-7'}>
            <CartItemList />
            {isRevalidating && items.length > 0 && (
              <p className="mt-3 text-xs text-neutral-600" role="status">
                Checking current prices and stock…
              </p>
            )}
          </div>

          {items.length > 0 && (
            <div className="lg:col-span-5">
              <OrderSummary onCheckout={handleCheckout} isChecking={isCheckingOut || isRevalidating} />
            </div>
          )}
        </div>
      ) : (
        <div className="border border-neutral-200 rounded-3xl p-12 text-center bg-white space-y-3" role="status">
          <div className="w-10 h-10 border-4 border-black/10 border-t-black rounded-full animate-spin mx-auto" aria-hidden="true" />
          <p className="text-sm text-neutral-700">Loading your cart…</p>
        </div>
      )}

      <CheckoutModal isOpen={isCheckoutOpen && items.length > 0} onClose={() => setIsCheckoutOpen(false)} />
    </div>
  );
}
