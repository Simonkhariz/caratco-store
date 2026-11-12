'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '@/context/AuthContext';
import { ApiCustomerOrder, apiGetCustomerOrders, errorMessage } from '@/lib/api-client';
import { formatPrice } from '@/lib/currency';
import { getOrderStatusDisplay, STATUS_TONE_CLASSES } from '@/lib/order-status';
import { optionsText } from '@/lib/shop';
import { Package, ArrowRight, AlertCircle, Truck, ArrowLeft, Search, Mail } from 'lucide-react';

export default function CustomerOrdersPage() {
  const { user, isLoading: authLoading, openAuthModal } = useAuth();
  // One request per signed-in customer and per "Try again"; only its answer is shown.
  const [retries, setRetries] = useState(0);
  const requestKey = user ? `${user.id}#${retries}` : null;
  const [answer, setAnswer] = useState<{ key: string; orders: ApiCustomerOrder[]; error: string | null } | null>(null);
  const isLoading = requestKey !== null && answer?.key !== requestKey;
  const orders = requestKey ? (answer?.orders ?? []) : [];
  const error = isLoading ? null : (answer?.error ?? null);
  const loadOrders = () => setRetries((n) => n + 1);

  useEffect(() => {
    if (!requestKey) return;
    let current = true;
    apiGetCustomerOrders().then(
      (data) => current && setAnswer({ key: requestKey, orders: data || [], error: null }),
      (err) => current && setAnswer({ key: requestKey, orders: [], error: errorMessage(err, 'Failed to load your orders.') })
    );
    return () => {
      current = false;
    };
  }, [requestKey]);

  return (
    <div className="min-h-screen bg-[#FBFBFB] py-8 sm:py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-4xl mx-auto space-y-6 sm:space-y-8">
        <div className="flex items-center justify-between">
          <Link href="/" className="inline-flex items-center gap-2 text-sm font-semibold text-neutral-700 hover:text-black">
            <ArrowLeft className="w-4 h-4" aria-hidden="true" />
            <span>Back to the store</span>
          </Link>
          <Link href="/track-order" className="inline-flex items-center gap-1.5 text-sm font-semibold text-black hover:underline">
            <Search className="w-3.5 h-3.5" aria-hidden="true" />
            <span>Track an order</span>
          </Link>
        </div>

        <div className="bg-white rounded-3xl p-6 sm:p-8 border border-neutral-100 shadow-sm">
          <h1 className="text-2xl sm:text-3xl font-black text-black">My orders</h1>
          <p className="text-sm text-neutral-700 mt-1">
            {user ? `Signed in as ${user.email}` : 'Sign in to see every order placed with your email.'}
          </p>
        </div>

        {isLoading || authLoading ? (
          <div className="bg-white rounded-3xl p-12 text-center border border-neutral-100 shadow-sm space-y-3" role="status">
            <div className="w-10 h-10 border-4 border-black/10 border-t-black rounded-full animate-spin mx-auto" aria-hidden="true" />
            <p className="text-sm text-neutral-700">Loading your orders…</p>
          </div>
        ) : !user ? (
          <div className="bg-white rounded-3xl p-8 sm:p-12 text-center border border-neutral-100 shadow-sm space-y-4">
            <div className="w-16 h-16 bg-neutral-100 text-neutral-700 rounded-full flex items-center justify-center mx-auto">
              <Package className="w-8 h-8" aria-hidden="true" />
            </div>
            <div>
              <h2 className="text-lg sm:text-xl font-bold text-black">You&apos;re not signed in</h2>
              <p className="text-sm text-neutral-700 max-w-md mx-auto mt-1">
                Ordered as a guest? We&apos;ll email you a one-time sign-in link, and every order placed with that email will
                show up here.
              </p>
            </div>
            <div className="pt-2 flex flex-wrap items-center justify-center gap-3">
              <button
                type="button"
                onClick={() => openAuthModal('magic')}
                className="inline-flex items-center gap-2 bg-accent text-white hover:bg-accent-hover font-semibold px-6 py-3 rounded-full text-sm"
              >
                <Mail className="w-4 h-4" aria-hidden="true" />
                Email me a sign-in link
              </button>
              <Link href="/track-order" className="bg-neutral-100 hover:bg-neutral-200 font-semibold px-6 py-3 rounded-full text-sm">
                Track one order
              </Link>
            </div>
          </div>
        ) : error ? (
          <div className="bg-white rounded-3xl p-8 text-center border border-red-100 shadow-sm space-y-3" role="alert">
            <AlertCircle className="w-8 h-8 text-red-600 mx-auto" aria-hidden="true" />
            <p className="text-sm font-semibold text-red-800">{error}</p>
            <button type="button" onClick={loadOrders} className="px-4 py-2 bg-neutral-100 hover:bg-neutral-200 text-sm font-semibold rounded-full">
              Try again
            </button>
          </div>
        ) : orders.length === 0 ? (
          <div className="bg-white rounded-3xl p-12 text-center border border-neutral-100 shadow-sm space-y-4">
            <div className="w-14 h-14 bg-neutral-100 text-neutral-600 rounded-full flex items-center justify-center mx-auto" aria-hidden="true">
              <Package className="w-6 h-6" aria-hidden="true" />
            </div>
            <div>
              <h2 className="text-base font-bold text-black">No orders yet</h2>
              <p className="text-sm text-neutral-700 mt-1">Orders you place with {user.email} will appear here.</p>
            </div>
            <Link href="/category" className="inline-block bg-accent text-white hover:bg-accent-hover font-semibold px-6 py-2.5 rounded-full text-sm">
              Start shopping
            </Link>
          </div>
        ) : (
          <ul className="space-y-4">
            {orders.map((ord) => {
              const status = getOrderStatusDisplay(ord.order_status, ord.payment_status, ord.expires_at, ord.customer_marked_paid_at);
              const trackHref = `/track-order?order=${encodeURIComponent(ord.order_number)}`;
              return (
                <li key={ord.id || ord.order_number} className="bg-white rounded-3xl p-5 sm:p-6 border border-neutral-100 shadow-sm space-y-4">
                  <div className="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 pb-3">
                    <div>
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono font-bold text-sm text-black">{ord.order_number}</span>
                        <span className={`text-xs font-semibold px-2.5 py-0.5 rounded-full ${STATUS_TONE_CLASSES[status.tone]}`}>
                          {status.label}
                        </span>
                        {ord.shipment?.tracking_number && (
                          <span className="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-800">
                            <Truck className="w-3 h-3" aria-hidden="true" />
                            {ord.shipment.carrier}: {ord.shipment.tracking_number}
                          </span>
                        )}
                      </div>
                      <p className="text-xs text-neutral-600 mt-0.5">
                        Placed {new Date(String(ord.created_at).replace(' ', 'T')).toLocaleDateString('en-NG', { dateStyle: 'medium' })}
                        {status.description ? ` · ${status.description}` : ''}
                      </p>
                    </div>
                    {/* Its own row on phones, label on the left and amount on the right. */}
                    <div className="flex w-full items-baseline justify-between gap-2 sm:block sm:w-auto sm:text-right">
                      <p className="text-xs text-neutral-600">Total</p>
                      <p className="font-extrabold text-base text-black">{formatPrice(ord.grand_total)}</p>
                    </div>
                  </div>

                  {ord.items.length > 0 && (
                    <ul className="divide-y divide-neutral-50 text-sm">
                      {ord.items.map((it) => {
                        // Only the options the product uses ("M · Navy"); nothing for one with none.
                        const options = optionsText(it.variant_options?.size, it.variant_options?.color, ' · ');
                        return (
                          <li key={it.id} className="py-2 flex items-center justify-between gap-2">
                            <div className="min-w-0 pr-2">
                              <p className="font-medium text-black truncate">{it.item_name}</p>
                              <p className="text-xs text-neutral-600">
                                Qty {it.quantity}
                                {options ? ` · ${options}` : ''}
                              </p>
                              {it.variant_options?.warranty && (
                                <p className="text-xs text-neutral-600">Warranty: {it.variant_options.warranty}</p>
                              )}
                            </div>
                            <span className="text-neutral-800 shrink-0">{formatPrice(it.line_total)}</span>
                          </li>
                        );
                      })}
                    </ul>
                  )}

                  <div className="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-neutral-100">
                    <span className="text-xs text-neutral-700">
                      Deliver to{' '}
                      <strong className="text-black font-medium">
                        {ord.shipping_address_snapshot?.city}, {ord.shipping_address_snapshot?.state}
                      </strong>
                    </span>
                    <div className="flex items-center gap-3">
                      {status.canPay && (
                        <Link href={`${trackHref}&pay=1`} className="bg-accent text-white hover:bg-accent-hover font-semibold px-4 py-2 rounded-full text-xs">
                          Pay now
                        </Link>
                      )}
                      <Link href={trackHref} className="inline-flex items-center gap-1.5 text-sm font-semibold text-black hover:underline">
                        <span>Details & tracking</span>
                        <ArrowRight className="w-3.5 h-3.5" aria-hidden="true" />
                      </Link>
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}
