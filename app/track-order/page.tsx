'use client';

import React, { useState, useEffect, useEffectEvent, Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import {
  Search,
  Package,
  Truck,
  Clock,
  MapPin,
  AlertCircle,
  ArrowLeft,
  CreditCard,
  ArrowRight,
  Copy,
  ExternalLink,
  Check,
  X,
} from 'lucide-react';
import { formatPrice } from '@/lib/currency';
import { useAuth } from '@/context/AuthContext';
import { ApiOrder, apiGetOrder, apiInitializePayment, apiMarkPaymentSent, errorMessage } from '@/lib/api-client';
import { buildWhatsAppInvoiceUrl, buildWhatsAppMessageUrl, formatSupportPhone, whatsAppOrderFrom } from '@/lib/whatsapp';
import { markInvoiceSent, readGuestToken, saveGuestToken, saveLookupEmail } from '@/lib/pending-order';
import { useStoreSupport } from '@/lib/use-store-support';
import { useInvoiceSent, useLatestOrderNumber } from '@/lib/use-order-data';
import { getOrderStatusDisplay, getShipmentDisplay, orderLookupErrorMessage, STATUS_TONE_CLASSES } from '@/lib/order-status';
import { optionsText } from '@/lib/shop';
import { WhatsAppIcon } from '@/components/ui/WhatsAppIcon';
import { Dialog } from '@/components/ui/Dialog';
import { PaymentMethodsNotice } from '@/components/cart/PaymentMethodsNotice';

/** Safari-safe parsing of MySQL "YYYY-MM-DD HH:MM:SS" timestamps. */
function toDate(value: string): Date {
  return new Date(String(value).replace(' ', 'T'));
}

const NOT_FOUND_MESSAGE = 'Order not found. Please verify your order number and contact details.';

/**
 * Fetch an order for the lookup form. Uses the access key given, else the one saved on this
 * device; a key that worked is saved for next time.
 */
async function requestOrder(cleanNum: string, verificationOpts?: { token?: string; email?: string }): Promise<ApiOrder> {
  const token = verificationOpts?.token || readGuestToken(cleanNum);
  const res = await apiGetOrder(cleanNum, { token, email: verificationOpts?.email });
  if (token && verificationOpts?.token) saveGuestToken(cleanNum, token, false);
  return res;
}

function TrackOrderContent() {
  const searchParams = useSearchParams();
  const queryOrder = searchParams.get('order') || searchParams.get('order_number') || '';
  const queryToken = searchParams.get('token') || '';

  const [orderNumber, setOrderNumber] = useState<string>(queryOrder);
  const [verificationInput, setVerificationInput] = useState<string>('');
  // The last order placed on this device, offered when the address names no order.
  const latestOrderNumber = useLatestOrderNumber();
  const recentOrderNumber = queryOrder ? null : latestOrderNumber;

  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [isInitializingPayment, setIsInitializingPayment] = useState<string | null>(null);
  // null until the shopper opens or closes it; "Pay now" links (?pay=1) open it on arrival.
  const [paymentModalChoice, setShowPaymentModal] = useState<boolean | null>(null);
  const { supportPhone, payNowGateway, whatsappEnabled, loaded: paymentMethodsLoaded, retry: retryPaymentMethods } = useStoreSupport();
  const wantsToPay = searchParams.get('pay') === '1';
  const [paymentError, setPaymentError] = useState<string | null>(null);
  const [order, setOrder] = useState<ApiOrder | null>(null);
  // The checkout email the order was found with: lets this device start a payment without the
  // access key (the order emails link here, so a guest elsewhere only has number and email).
  const [lookupEmail, setLookupEmail] = useState<string>('');
  const [lookupError, setLookupError] = useState<string | null>(null);
  const [copiedTracking, setCopiedTracking] = useState<boolean>(false);

  // Signing out leaves nothing of the order on screen for the next person.
  const { user, refreshUser } = useAuth();
  const viewerId = user?.id ?? null;
  const [lastViewerId, setLastViewerId] = useState(viewerId);
  // Signing in (again) can open an order from the address that couldn't be opened before.
  const [lookupAttempt, setLookupAttempt] = useState(0);
  if (viewerId !== lastViewerId) {
    setLastViewerId(viewerId);
    if (viewerId === null) {
      setOrder(null);
      setOrderNumber('');
      setLookupEmail('');
      setVerificationInput('');
      setLookupError(null);
    } else if (queryOrder && !order) {
      setLookupAttempt((n) => n + 1);
      setIsLoading(true);
      setLookupError(null);
    }
  }

  const handleCopyTracking = (code: string) => {
    if (typeof navigator !== 'undefined' && navigator.clipboard) {
      navigator.clipboard.writeText(code);
      setCopiedTracking(true);
      setTimeout(() => setCopiedTracking(false), 2000);
    }
  };

  // Look up an order typed in (or the recent one).
  const performLookup = (ordNum: string, verificationOpts?: { token?: string; email?: string }) => {
    const cleanNum = ordNum.trim().toUpperCase();
    if (!cleanNum) {
      setLookupError('Please enter your order reference (e.g. ORD-...) or carrier tracking code (e.g. GIG-..., TRK-...).');
      return;
    }
    setIsLoading(true);
    setLookupError(null);
    requestOrder(cleanNum, verificationOpts)
      .then(
        (res) => {
          setOrder(res);
          setOrderNumber(cleanNum);
          const usedEmail = verificationOpts?.email?.trim().toLowerCase() || '';
          setLookupEmail(usedEmail);
          // Lets "View receipt" open the full receipt in this tab without the access key.
          if (usedEmail) saveLookupEmail(res.order_number, usedEmail);
        },
        (err) => {
          setOrder(null);
          setLookupError(orderLookupErrorMessage(err, NOT_FOUND_MESSAGE));
          // A signed-in customer's own order "not found" can mean the session ended: this asks,
          // and prompts them to sign in again if so.
          if (user) void refreshUser();
        }
      )
      .finally(() => setIsLoading(false));
  };

  // An order in the address (?order=...): show it as loading straight away, then look it up.
  const autoLookupKey = queryOrder ? `${queryOrder}|${queryToken}` : '';
  const [autoLookupFor, setAutoLookupFor] = useState('');
  if (autoLookupKey !== autoLookupFor) {
    setAutoLookupFor(autoLookupKey);
    if (autoLookupKey) {
      setIsLoading(true);
      setLookupError(null);
    }
  }
  // Same as a typed lookup: "not found" for a signed-in customer may mean the session ended.
  const checkSessionAfterFailure = useEffectEvent(() => {
    if (user) void refreshUser();
  });
  useEffect(() => {
    const cleanNum = queryOrder.trim().toUpperCase();
    if (!cleanNum) return;
    let current = true;
    requestOrder(cleanNum, { token: queryToken || undefined })
      .then(
        (res) => {
          if (!current) return;
          setOrder(res);
          setOrderNumber(cleanNum);
        },
        (err) => {
          if (!current) return;
          setOrder(null);
          setLookupError(orderLookupErrorMessage(err, NOT_FOUND_MESSAGE));
          checkSessionAfterFailure();
        }
      )
      .finally(() => current && setIsLoading(false));
    return () => {
      current = false;
    };
  }, [queryOrder, queryToken, lookupAttempt]);

  // Follow the order while the page is open, like the payment page: payment confirmed, packed,
  // shipped and delivered appear without a refresh. Every 5 seconds while the tab is visible,
  // and at once when the shopper comes back to it; it stops once the order has finished.
  const followedNumber = order?.order_number ?? '';
  const followedStatus = order?.status || order?.order_status || '';
  const followToken = queryToken || undefined;
  const followEmail = lookupEmail || undefined;
  useEffect(() => {
    if (!followedNumber || ['delivered', 'completed', 'cancelled', 'refunded', 'returned'].includes(followedStatus)) return;
    let active = true;
    let busy = false;
    const refresh = () => {
      if (busy || document.visibilityState !== 'visible') return;
      busy = true;
      requestOrder(followedNumber, { token: followToken, email: followEmail })
        .then(
          (res) => {
            if (active && res.order_number === followedNumber) setOrder(res);
          },
          () => {
            // A failed check leaves the order on screen; the next one tries again.
          }
        )
        .finally(() => {
          busy = false;
        });
    };
    const onReturn = () => {
      if (document.visibilityState === 'visible') refresh();
    };
    const timer = window.setInterval(refresh, 5_000);
    document.addEventListener('visibilitychange', onReturn);
    window.addEventListener('focus', onReturn);
    return () => {
      active = false;
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', onReturn);
      window.removeEventListener('focus', onReturn);
    };
  }, [followedNumber, followedStatus, followToken, followEmail]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const cleanVerification = verificationInput.trim();

    let email: string | undefined;
    let token: string | undefined;

    if (cleanVerification) {
      if (cleanVerification.includes('@')) {
        email = cleanVerification;
      } else if (cleanVerification.length === 64 && /^[0-9a-fA-F]+$/.test(cleanVerification)) {
        token = cleanVerification;
      } else {
        setLookupError('Enter the email address you used at checkout, or the access key from your order confirmation.');
        return;
      }
    }

    performLookup(orderNumber, { token, email });
  };

  const getGatewayDisplayName = (provider?: string) => {
    if (!provider) return null;
    const p = provider.toLowerCase();
    if (p === 'kora') return 'Kora Hosted Checkout';
    if (p === 'monnify') return 'Monnify Gateway';
    if (p === 'whatsapp' || p === 'manual_whatsapp') return 'WhatsApp Bank Transfer';
    return provider.charAt(0).toUpperCase() + provider.slice(1);
  };


  const handleInitiatePayment = async (gatewayOverride: string) => {
    if (!order) return;
    const gateway = gatewayOverride;

    setIsInitializingPayment(gateway);
    setPaymentError(null);

    // Open the WhatsApp tab inside the click so pop-up blockers allow it; it is pointed at the
    // invoice once the server has reserved the order for a manual transfer.
    const whatsappTab = gateway === 'whatsapp' ? window.open('about:blank', '_blank') : null;

    try {
      const guestToken = readGuestToken(order.order_number);
      const redirectUrl = `${window.location.origin}/checkout/callback?order=${encodeURIComponent(order.order_number)}`;

      const res = await apiInitializePayment(order.order_number, guestToken, redirectUrl, gateway, guestToken ? undefined : lookupEmail || undefined);
      if (gateway === 'whatsapp') {
        // Remembered on this device: the shopper can now report the transfer ("I've sent the
        // transfer"), here or in checkout.
        markInvoiceSent(order.order_number);
        if (whatsAppInvoiceUrl && whatsappTab) {
          whatsappTab.location.href = whatsAppInvoiceUrl;
        } else if (whatsAppInvoiceUrl) {
          window.location.assign(whatsAppInvoiceUrl);
        }
        setShowPaymentModal(false);
      } else if (res.checkout_url) {
        window.location.assign(res.checkout_url);
      } else {
        throw new Error(`${gateway === 'kora' ? 'Kora' : 'Monnify'} did not return a valid payment URL.`);
      }
    } catch (err) {
      whatsappTab?.close();
      setPaymentError(errorMessage(err, 'Payment initialization failed. Please retry.'));
      // The error is shown in the payment window, which the WhatsApp shortcut does not open.
      setShowPaymentModal(true);
    } finally {
      setIsInitializingPayment(null);
    }
  };

  const rawStatus = order?.status || order?.order_status || 'pending_payment';
  const statusDisplay = order ? getOrderStatusDisplay(rawStatus, order.payment_status, order.expires_at, order.customer_marked_paid_at) : null;
  const canPay = Boolean(statusDisplay?.canPay);
  // The shopper reported their WhatsApp transfer and the store has not confirmed it yet.
  const awaitingConfirmation = rawStatus === 'pending_payment' && Boolean(order?.customer_marked_paid_at);
  // WhatsApp transfer: once the chat was opened, the shopper reports the transfer here; the store is
  // told (admins emailed) and the payment page waits for its confirmation, then shows the receipt.
  const router = useRouter();
  const invoiceSent = useInvoiceSent(order?.order_number);
  const [isReporting, setIsReporting] = useState(false);
  const [reportError, setReportError] = useState<string | null>(null);
  const reportTransfer = async () => {
    if (!order) return;
    setIsReporting(true);
    setReportError(null);
    try {
      const guestToken = readGuestToken(order.order_number);
      await apiMarkPaymentSent(order.order_number, guestToken, guestToken ? undefined : lookupEmail || undefined);
      router.push(`/checkout/callback?order=${encodeURIComponent(order.order_number)}`);
    } catch (err) {
      setReportError(errorMessage(err, 'We could not reach the store just now. Please try again in a moment.'));
      setIsReporting(false);
    }
  };
  const isPaid = ['successful', 'partially_refunded', 'refunded'].includes(order?.payment_status ?? '');
  const isOffTrack = ['cancelled', 'returned', 'refund_pending', 'refunded'].includes(rawStatus);

  // "Pay now" from My orders opens the payment options straight away.
  const showPaymentModal = paymentModalChoice ?? (wantsToPay && canPay);

  // The step happening now (or next). Every step before it is done; placing the order is always
  // done, so a new order is on "Payment". A delivered order has every step done.
  const getStageIndex = (statusStr: string, paymentStatus: string) => {
    if (statusStr === 'delivered' || statusStr === 'completed') return 4;
    if (statusStr === 'shipped') return 3;
    if (statusStr === 'processing' || statusStr === 'paid' || paymentStatus === 'successful') return 2;
    return 1;
  };

  const currentStage = order ? getStageIndex(rawStatus, order.payment_status || '') : 1;
  const isFinished = rawStatus === 'delivered' || rawStatus === 'completed';

  const steps = [
    { title: 'Order placed', tooltip: 'Your order was received and the items reserved.' },
    { title: 'Payment', tooltip: 'The store confirms your payment.' },
    { title: 'Packing', tooltip: 'The store packs your order.' },
    { title: 'On the way', tooltip: 'Your parcel is with the courier.' },
    { title: 'Delivered', tooltip: 'Your parcel was delivered.' },
  ];
  // What the step happening now is waiting for, in the customer's words.
  const currentNote =
    currentStage === 1
      ? awaitingConfirmation ? 'Awaiting confirmation' : statusDisplay?.label === 'Payment window closed' ? 'Not paid in time' : 'Awaiting payment'
      : currentStage === 2
        ? rawStatus === 'processing' ? 'Being packed' : 'Up next'
        : currentStage === 3
          ? 'With the courier'
          : '';
  const stepNote = (idx: number) =>
    idx === currentStage && !isFinished ? currentNote : idx < currentStage || isFinished ? (idx === 1 ? 'Paid' : 'Done') : 'Not yet';

  const whatsAppInvoiceUrl = order ? buildWhatsAppInvoiceUrl(whatsAppOrderFrom(order), supportPhone) : '';
  const helpWhatsAppUrl = buildWhatsAppMessageUrl('Hello! I have a question about my order.', supportPhone);
  const orderWhatsAppUrl = order ? buildWhatsAppMessageUrl(`Hello! I'm checking on my order #${order.order_number}.`, supportPhone) : '';
  const shipmentDisplay = getShipmentDisplay(order?.shipment?.status);

  return (
    <div className="min-h-screen bg-[#FBFBFB] py-8 sm:py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-4xl mx-auto space-y-6 sm:space-y-8">
        {/* Navigation Bar */}
        <div className="flex items-center justify-between">
          <Link
            href="/"
            className="inline-flex items-center gap-2 text-sm font-semibold text-neutral-600 hover:text-black transition-colors"
          >
            <ArrowLeft className="w-4 h-4" aria-hidden="true" />
            <span>Back to the store</span>
          </Link>

          {helpWhatsAppUrl ? (
            <a
              href={helpWhatsAppUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1.5 text-xs font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200 px-3 py-1.5 rounded-full hover:bg-emerald-100 transition-colors"
            >
              <WhatsAppIcon className="w-3.5 h-3.5 text-emerald-700" aria-hidden="true" />
              <span>Help: {formatSupportPhone(supportPhone)}</span>
              <span className="sr-only">on WhatsApp (opens in a new tab)</span>
            </a>
          ) : (
            <Link href="/contact" className="text-xs font-semibold text-neutral-700 underline hover:text-black">
              Need help? Contact us
            </Link>
          )}
        </div>

        {/* Header */}
        <div className="text-center space-y-2">
          <h1 className="text-2xl sm:text-3xl font-black text-black tracking-tight">
            Track Your Package
          </h1>
          <p className="text-sm text-neutral-700 max-w-md mx-auto">
            See your order&apos;s payment, packing and delivery status.
          </p>
        </div>

        {/* Recent Order Quick-Track Chip */}
        {!order && recentOrderNumber && (
          <div className="bg-white rounded-2xl p-4 border border-neutral-200 shadow-sm flex items-center justify-between gap-3 text-sm">
            <div className="flex items-center gap-2">
              <Clock className="w-4 h-4 text-emerald-700 shrink-0" aria-hidden="true" />
              <span>
                Recent order: <strong className="font-mono text-black">{recentOrderNumber}</strong>
              </span>
            </div>
            <button
              type="button"
              onClick={() => {
                setOrderNumber(recentOrderNumber);
                performLookup(recentOrderNumber);
              }}
              className="bg-accent hover:bg-accent-hover text-accent-ink font-semibold px-4 py-1.5 rounded-full transition-colors shrink-0 flex items-center gap-1"
            >
              <span>Track now</span>
              <ArrowRight className="w-3 h-3" aria-hidden="true" />
            </button>
          </div>
        )}

        {/* Lookup Card */}
        <div className="bg-white rounded-3xl p-6 sm:p-7 border border-neutral-200 shadow-sm">
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label htmlFor="track-order-number" className="block text-xs font-bold uppercase tracking-wider text-neutral-600 mb-1.5">
                  Order number or tracking number <span aria-hidden="true">*</span>
                </label>
                <div className="relative">
                  <input
                    id="track-order-number"
                    type="text"
                    required
                    aria-invalid={lookupError ? true : undefined}
                    aria-describedby={lookupError ? 'track-lookup-error' : undefined}
                    value={orderNumber}
                    onChange={(e) => setOrderNumber(e.target.value)}
                    placeholder="e.g. ORD-20261016-XXXXXX"
                    className="w-full text-sm font-mono pl-4 pr-10 py-3 bg-neutral-50 border border-neutral-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-accent uppercase placeholder:normal-case placeholder:font-sans"
                  />
                  <Package className="w-4 h-4 text-neutral-500 absolute right-4 top-3.5" aria-hidden="true" />
                </div>
              </div>

              <div>
                <label htmlFor="track-verification" className="mb-1.5 block text-xs font-bold uppercase tracking-wider text-neutral-600">
                  Checkout Email
                </label>
                <input
                  id="track-verification"
                  aria-describedby="track-verification-hint"
                  type="text"
                  autoComplete="email"
                  value={verificationInput}
                  onChange={(e) => setVerificationInput(e.target.value)}
                  placeholder="Email or access key"
                  className="w-full text-sm px-4 py-3 bg-neutral-50 border border-neutral-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-accent"
                />
                <p id="track-verification-hint" className="mt-1.5 text-xs text-neutral-600">Not needed on the device you ordered from.</p>
              </div>
            </div>

            {lookupError && (
              <div id="track-lookup-error" role="alert" className="p-3 bg-red-50 border border-red-200 rounded-2xl flex items-center gap-2 text-sm text-red-800">
                <AlertCircle className="w-4 h-4 shrink-0 text-red-600" aria-hidden="true" />
                <span>{lookupError}</span>
              </div>
            )}

            <button
              type="submit"
              disabled={isLoading}
              className="w-full bg-accent text-accent-ink hover:bg-accent-hover font-bold py-3.5 rounded-full text-sm transition-all shadow-md flex items-center justify-center gap-2 disabled:opacity-50"
            >
              {isLoading ? (
                <>
                  <span className="w-4 h-4 border-2 border-[color-mix(in_srgb,var(--accent-ink)_30%,transparent)] border-t-accent-ink rounded-full animate-spin" aria-hidden="true" />
                  <span>Checking status…</span>
                </>
              ) : (
                <>
                  <Search className="w-4 h-4" aria-hidden="true" />
                  <span>Track order</span>
                </>
              )}
            </button>
          </form>
        </div>

        {/* Order Details Display */}
        {order && (
          <div className="space-y-6 animate-in fade-in" aria-live="polite">
            {/* Top Status Banner */}
            <div className="bg-white rounded-3xl p-5 sm:p-7 border border-neutral-100 shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <h2 className="text-xl font-extrabold text-black font-mono">
                    #{order.order_number}
                  </h2>

                  {statusDisplay && (
                    <span className={`inline-flex items-center text-xs font-semibold px-2.5 py-0.5 rounded-full ${STATUS_TONE_CLASSES[statusDisplay.tone]}`}>
                      {statusDisplay.label}
                    </span>
                  )}
                </div>

                <p className="text-sm text-neutral-700">
                  Placed {toDate(order.created_at).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' })}
                  {statusDisplay?.description ? ` · ${statusDisplay.description}` : ''}
                </p>
              </div>

              {/* Action Buttons */}
              <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                {canPay ? (
                  <>
                    {payNowGateway && (
                    <button
                      type="button"
                      onClick={() => void handleInitiatePayment(payNowGateway)}
                      disabled={Boolean(isInitializingPayment)}
                      className="flex-1 sm:flex-initial bg-accent hover:bg-accent-hover text-accent-ink font-bold px-5 py-2.5 rounded-full text-xs transition-all shadow flex items-center justify-center gap-1.5 disabled:opacity-60"
                    >
                      <CreditCard className="w-3.5 h-3.5" aria-hidden="true" />
                      <span>{isInitializingPayment === payNowGateway ? 'Connecting…' : `Pay now (${formatPrice(order.grand_total)})`}</span>
                    </button>
                    )}

                    {/* The same path as "Continue to WhatsApp": the server opens the WhatsApp
                        attempt (the order is held for the manual-transfer window and shows in
                        the admin's "Awaiting WhatsApp payment") before the invoice opens. */}
                    {whatsappEnabled && invoiceSent && (
                      <button
                        type="button"
                        onClick={() => void reportTransfer()}
                        disabled={isReporting || Boolean(isInitializingPayment)}
                        className="flex-1 sm:flex-initial bg-[#1DA851] hover:bg-[#178a43] text-white font-bold px-4 py-2.5 rounded-full text-xs transition-all shadow disabled:opacity-60"
                      >
                        {isReporting ? 'Telling the store…' : "I've sent the transfer"}
                      </button>
                    )}
                    {whatsappEnabled && (
                      <button
                        type="button"
                        onClick={() => handleInitiatePayment('whatsapp')}
                        disabled={Boolean(isInitializingPayment)}
                        className="p-2.5 bg-neutral-100 hover:bg-neutral-200 text-[#128C7E] rounded-full transition-colors relative group shrink-0 disabled:opacity-60"
                        aria-label="Send the invoice on WhatsApp (opens in a new tab)"
                      >
                        <WhatsAppIcon className="w-4 h-4" aria-hidden="true" />
                        <div aria-hidden="true" className="pointer-events-none absolute bottom-full right-0 mb-2 hidden group-hover:block group-focus-visible:block z-20 whitespace-nowrap bg-neutral-900 text-white text-xs rounded-lg px-2 py-1 shadow-md">
                          WhatsApp Invoice
                        </div>
                      </button>
                    )}
                    {reportError && (
                      <p role="alert" className="basis-full text-xs text-red-700">
                        {reportError}
                      </p>
                    )}
                  </>
                ) : (
                  <>
                  {(isPaid || awaitingConfirmation) && (
                    <Link
                      href={`/checkout/callback?order=${encodeURIComponent(order.order_number)}`}
                      className="inline-flex items-center gap-1.5 bg-accent hover:bg-accent-hover text-accent-ink font-semibold px-4 py-2 rounded-full text-xs transition-colors"
                    >
                      <span>{isPaid ? 'View receipt' : 'See payment status'}</span>
                    </Link>
                  )}
                  {orderWhatsAppUrl && (
                    <a
                      href={orderWhatsAppUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 bg-neutral-100 hover:bg-neutral-200 text-neutral-800 font-semibold px-4 py-2 rounded-full text-xs transition-colors"
                    >
                      <WhatsAppIcon className="w-3.5 h-3.5 text-[#128C7E]" aria-hidden="true" />
                      <span>WhatsApp help</span>
                      <span className="sr-only">(opens in a new tab)</span>
                    </a>
                  )}
                  </>
                )}
              </div>
            </div>

            {isOffTrack && statusDisplay && (
              <div role="status" className={`rounded-3xl p-5 sm:p-6 ${STATUS_TONE_CLASSES[statusDisplay.tone]}`}>
                <p className="font-bold">{statusDisplay.label}</p>
                <p className="text-sm mt-1">{statusDisplay.description}</p>
              </div>
            )}

            {/* Stepper Progress Timeline with Connected Bar */}
            {!isOffTrack && (
            <div className="bg-white rounded-3xl p-5 sm:p-7 border border-neutral-100 shadow-sm space-y-6">
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-sm font-bold text-black">
                    Delivery progress
                  </h3>
                  <p className="text-xs text-neutral-600">
                    {isFinished
                      ? 'Delivered • all steps complete'
                      : `Step ${currentStage + 1} of ${steps.length} • ${steps[currentStage]?.title}: ${currentNote.toLowerCase()}`}
                  </p>
                </div>

              </div>

              <div className="relative pt-2 pb-1">
                {/* Wide screens: one line through the centres of the five circles (10% to 90% of the
                    width), filled up to the step happening now. */}
                <div aria-hidden="true" className="hidden sm:block absolute top-6 left-[10%] right-[10%] h-1 rounded-full bg-neutral-200 -translate-y-1/2 z-0" />
                <div
                  aria-hidden="true"
                  className="hidden sm:block absolute top-6 left-[10%] h-1 rounded-full bg-accent -translate-y-1/2 z-0 transition-all duration-500"
                  style={{ width: `${(currentStage / (steps.length - 1)) * 80}%` }}
                />

                <ol className="grid grid-cols-1 sm:grid-cols-5 gap-3 sm:gap-2 relative z-10" aria-label="Order progress">
                  {steps.map((step, idx) => {
                    const isDone = idx < currentStage || isFinished;
                    const isCurrent = idx === currentStage && !isFinished;
                    return (
                      <li
                        key={step.title}
                        aria-current={isCurrent ? 'step' : undefined}
                        className="group relative flex sm:flex-col items-center gap-3 sm:gap-2"
                      >
                        {/* Phones: a short line down to the next step, black once this step is done. */}
                        {idx < steps.length - 1 && (
                          <div aria-hidden="true" className={`sm:hidden absolute left-[15px] top-8 h-3 w-0.5 ${isDone ? 'bg-accent' : 'bg-neutral-200'}`} />
                        )}

                        <div aria-hidden="true" className="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-2 hidden sm:group-hover:block z-30 w-44 bg-neutral-900 text-white text-xs rounded-xl px-2.5 py-1.5 shadow-lg text-center">
                          {step.tooltip}
                          <div className="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-neutral-900" />
                        </div>

                        {/* Done: the shop's accent with a tick in its ink (black and white in the template). Now: white with an accent ring. Later: grey number. */}
                        <div
                          aria-hidden="true"
                          className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all shrink-0 ${
                            isDone
                              ? 'bg-accent text-accent-ink'
                              : isCurrent
                                ? 'bg-white text-black border-2 border-accent ring-4 ring-black/10'
                                : 'bg-neutral-100 text-neutral-500'
                          }`}
                        >
                          {isDone ? <Check className="w-4 h-4" strokeWidth={3} aria-hidden="true" /> : <span>{idx + 1}</span>}
                        </div>

                        <div className="text-left sm:text-center min-w-0">
                          <p className={`text-xs font-bold leading-tight ${isDone || isCurrent ? 'text-black' : 'text-neutral-500'}`}>
                            {step.title}
                          </p>
                          <p className={`text-xs leading-tight mt-0.5 ${isCurrent ? 'font-semibold text-amber-700' : 'text-neutral-500'}`}>
                            {stepNote(idx)}
                          </p>
                          <p className="sr-only">{step.tooltip}</p>
                        </div>
                      </li>
                    );
                  })}
                </ol>
              </div>
            </div>

            )}

            {/* Courier & Shipment Details Card (if shipment exists) */}
            {order.shipment && (
              <div className="bg-white rounded-3xl p-6 sm:p-7 border border-blue-100 bg-gradient-to-br from-white via-white to-blue-50/20 shadow-sm space-y-4">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-neutral-100">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-2xl bg-blue-50 text-blue-700 flex items-center justify-center shrink-0" aria-hidden="true">
                      <Truck className="w-5 h-5" aria-hidden="true" />
                    </div>
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="text-xs font-bold uppercase tracking-wider text-blue-700">{shipmentDisplay.heading}</span>
                        <span className="inline-flex items-center text-xs font-semibold px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                          {shipmentDisplay.badge}
                        </span>
                      </div>
                      <h3 className="text-base font-extrabold text-neutral-900 mt-0.5">
                        {order.shipment.carrier || 'Logistics Courier'}
                      </h3>
                    </div>
                  </div>

                  {order.shipment.tracking_url && (
                    <a
                      href={order.shipment.tracking_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 px-4 py-2 rounded-full transition-colors shrink-0"
                    >
                      <span>Track on the courier&apos;s site</span>
                      <ExternalLink className="w-3.5 h-3.5" aria-hidden="true" />
                      <span className="sr-only">(opens in a new tab)</span>
                    </a>
                  )}
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-1">
                  <div className="bg-neutral-50 rounded-2xl p-3.5 border border-neutral-200">
                    <span className="text-xs font-semibold uppercase tracking-wider text-neutral-600 block mb-1">
                      Tracking code
                    </span>
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-mono font-bold text-sm text-neutral-900 select-all truncate">
                        {order.shipment.tracking_number}
                      </span>
                      <button
                        type="button"
                        onClick={() => handleCopyTracking(order.shipment?.tracking_number ?? '')}
                        className="p-1.5 hover:bg-neutral-200 rounded-lg text-neutral-600 hover:text-black transition-colors shrink-0"
                        title="Copy tracking number"
                        aria-label={copiedTracking ? 'Tracking number copied' : 'Copy tracking number'}
                      >
                        {copiedTracking ? (
                          <Check className="w-4 h-4 text-emerald-700" aria-hidden="true" />
                        ) : (
                          <Copy className="w-4 h-4" aria-hidden="true" />
                        )}
                      </button>
                      <span className="sr-only" role="status">{copiedTracking ? 'Tracking number copied' : ''}</span>
                    </div>
                  </div>

                  <div className="bg-neutral-50 rounded-2xl p-3.5 border border-neutral-200">
                    <span className="text-xs font-semibold uppercase tracking-wider text-neutral-600 block mb-1">
                      Dispatched on
                    </span>
                    <span className="text-xs font-bold text-neutral-800">
                      {order.shipment.shipped_at
                        ? toDate(order.shipment.shipped_at).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' })
                        : 'Scheduled for dispatch'}
                    </span>
                  </div>

                  <div className="bg-neutral-50 rounded-2xl p-3.5 border border-neutral-200">
                    <span className="text-xs font-semibold uppercase tracking-wider text-neutral-600 block mb-1">
                      Delivery status
                    </span>
                    <span className="text-xs font-bold text-neutral-800">
                      {order.shipment.delivered_at
                        ? `Delivered on ${toDate(order.shipment.delivered_at).toLocaleDateString('en-NG', { dateStyle: 'medium' })}`
                        : shipmentDisplay.delivery}
                    </span>
                  </div>
                </div>
              </div>
            )}

            {/* Breakdown & Destination Columns */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {/* Order Items (2 cols) */}
              <div className="md:col-span-2 bg-white rounded-3xl p-6 sm:p-7 border border-neutral-200 shadow-sm space-y-4">
                <div className="flex items-center justify-between pb-3 border-b border-neutral-100">
                  <h3 className="font-bold text-sm text-black flex items-center gap-2">
                    <Package className="w-4 h-4 text-neutral-500" aria-hidden="true" />
                    <span>Items ({order.items?.length || 0})</span>
                  </h3>
                  <span className="text-xs text-neutral-600 font-mono">
                    {order.currency || 'NGN'}
                  </span>
                </div>

                <div className="divide-y divide-neutral-100">
                  {order.items.map((item) => {
                    // Which one of the product was ordered ("M · Navy"): two lines can differ only in
                    // this. Nothing for a product with no options.
                    const options = optionsText(item.variant_options?.size, item.variant_options?.color, ' · ');
                    return (
                      <div key={item.id} className="py-3 flex items-center justify-between gap-3 text-sm">
                        <div>
                          <p className="font-bold text-neutral-900">{item.item_name}</p>
                          {options && <p className="text-neutral-600 text-xs">{options}</p>}
                          <p className="text-neutral-600 font-mono text-xs">
                            Qty: {item.quantity} &bull; SKU: {item.sku}
                          </p>
                          {item.variant_options?.warranty && (
                            <p className="text-neutral-600 text-xs mt-0.5">Warranty: {item.variant_options.warranty}</p>
                          )}
                        </div>
                        <span className="font-bold text-neutral-900 font-mono">
                          {formatPrice(item.line_total)}
                        </span>
                      </div>
                    );
                  })}
                </div>

                <div className="pt-3 border-t border-neutral-100 space-y-1.5 text-sm text-neutral-600">
                  <div className="flex justify-between">
                    <span>Subtotal:</span>
                    <span className="font-semibold text-black">{formatPrice(order.subtotal)}</span>
                  </div>
                  {order.discount_amount > 0 && (
                    <div className="flex justify-between text-emerald-800">
                      <span>Discount:</span>
                      <span className="font-semibold">-{formatPrice(order.discount_amount)}</span>
                    </div>
                  )}
                  <div className="flex justify-between">
                    <span>Delivery{order.shipping_zone_snapshot?.name ? ` (${order.shipping_zone_snapshot.name})` : ''}:</span>
                    <span className="font-semibold text-black">{formatPrice(order.shipping_fee)}</span>
                  </div>
                  <div className="flex justify-between text-base font-extrabold text-black pt-2 border-t border-neutral-200">
                    <span>Total:</span>
                    <span className="font-mono">{formatPrice(order.grand_total)}</span>
                  </div>
                </div>
              </div>

              {/* Destination & Payment Verification (1 col) */}
              <div className="space-y-6">
                {/* Delivery Destination */}
                <div className="bg-white rounded-3xl p-6 border border-neutral-200 shadow-sm space-y-3">
                  <h4 className="text-xs font-bold uppercase tracking-wider text-neutral-600 flex items-center gap-1.5">
                    <MapPin className="w-3.5 h-3.5" aria-hidden="true" />
                    <span>Destination</span>
                  </h4>
                  <div className="text-sm text-neutral-700 space-y-1">
                    <p className="font-bold text-neutral-900">
                      {order.shipping_address_snapshot?.full_name}
                    </p>
                    <p>{order.shipping_address_snapshot?.address_line1}</p>
                    <p>
                      {order.shipping_address_snapshot?.city}, {order.shipping_address_snapshot?.state}
                    </p>
                    <p className="text-neutral-600 pt-1">Phone: {order.shipping_address_snapshot?.phone}</p>
                  </div>
                </div>

                {/* Payment Status Card */}
                <div className="bg-white rounded-3xl p-6 border border-neutral-200 shadow-sm space-y-3">
                  <div className="flex items-center justify-between">
                    <h4 className="text-xs font-bold uppercase tracking-wider text-neutral-600">
                      Payment
                    </h4>
                    <span
                      className={`inline-flex items-center gap-1 font-bold text-xs px-2 py-0.5 rounded-full capitalize ${
                        isPaid ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
                      }`}
                    >
                      {isPaid ? (
                        <svg aria-hidden="true" className="w-3 h-3 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                          <path d="M20 6L9 17l-5-5" />
                        </svg>
                      ) : (
                        <Clock className="w-3 h-3 text-amber-700" aria-hidden="true" />
                      )}
                      <span>{isPaid ? 'Paid' : order.payment_status === 'cancelled' ? 'Cancelled' : awaitingConfirmation ? 'Awaiting confirmation' : 'Not paid'}</span>
                    </span>
                  </div>

                  <div className="text-sm space-y-1.5 pt-1 text-neutral-600">
                    <div className="flex justify-between">
                      <span>Gateway:</span>
                      <span className="font-medium text-black">
                        {order.payment?.provider ? getGatewayDisplayName(order.payment.provider) : 'Not started'}
                      </span>
                    </div>
                    {order.payment?.provider_reference && (
                      <div className="flex justify-between">
                        <span>Ref:</span>
                        <span className="font-mono text-xs text-neutral-700 select-all break-all text-right">
                          {order.payment.provider_reference}
                        </span>
                      </div>
                    )}
                    {order.payment?.verified_at && (
                      <div className="flex justify-between text-xs">
                        <span>Verified:</span>
                        <span className="text-neutral-600">
                          {toDate(order.payment.verified_at).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' })}
                        </span>
                      </div>
                    )}
                  </div>
                </div>

              </div>
            </div>
          </div>
        )}

        {/* Payment Options Modal */}
        {order && canPay && (
          <Dialog
            open={showPaymentModal}
            onClose={() => setShowPaymentModal(false)}
            title="Pay for your order"
            busy={Boolean(isInitializingPayment)}
            backdropClassName="bg-black/60 backdrop-blur-sm"
            panelClassName="w-full max-w-md"
            describedBy="track-pay-summary"
          >
            <div className="relative flex max-h-[92vh] supports-[height:100dvh]:max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl animate-rise sm:rounded-3xl">
              {/* A fixed row: only the payment options below it scroll. */}
              <div className="flex shrink-0 items-center justify-between gap-3 border-b border-neutral-100 py-3 pl-6 pr-4 sm:pl-7 sm:pr-5">
                <div>
                  <p className="text-base font-bold text-black" aria-hidden="true">Pay for your order</p>
                  <p id="track-pay-summary" className="text-xs text-neutral-600 font-mono">
                    Order #{order.order_number} &bull; Total: {formatPrice(order.grand_total)}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setShowPaymentModal(false)}
                  disabled={Boolean(isInitializingPayment)}
                  aria-label="Close payment options"
                  className="shrink-0 p-2 hover:bg-neutral-100 rounded-full text-neutral-600 hover:text-black transition-colors disabled:opacity-50"
                >
                  <X className="w-5 h-5" aria-hidden="true" />
                </button>
              </div>

              <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-6 sm:p-7">

              {paymentError && (
                <div role="alert" className="p-3 bg-red-50 border border-red-200 rounded-2xl flex items-center gap-2 text-sm text-red-800">
                  <AlertCircle className="w-4 h-4 text-red-600 shrink-0" aria-hidden="true" />
                  <span>{paymentError}</span>
                </div>
              )}

              {/* One "Pay now" for the store's card/transfer gateway, and WhatsApp as the other way. */}
              {!payNowGateway && !whatsappEnabled && (
                <PaymentMethodsNotice loaded={paymentMethodsLoaded} supportPhone={supportPhone} onRetry={retryPaymentMethods} />
              )}
              <div className="space-y-2.5 pt-1">
                {payNowGateway && (
                  <>
                    <button
                      type="button"
                      onClick={() => void handleInitiatePayment(payNowGateway)}
                      disabled={Boolean(isInitializingPayment)}
                      className="w-full py-3.5 rounded-full text-accent-ink font-bold text-sm transition-all shadow-md flex items-center justify-center gap-2 bg-accent hover:bg-accent-hover disabled:opacity-60"
                    >
                      {isInitializingPayment === payNowGateway ? (
                        <>
                          <span className="w-4 h-4 border-2 border-[color-mix(in_srgb,var(--accent-ink)_30%,transparent)] border-t-accent-ink rounded-full animate-spin" aria-hidden="true" />
                          <span>Opening secure payment…</span>
                        </>
                      ) : (
                        <>
                          <span>Pay now ({formatPrice(order.grand_total)})</span>
                          <ArrowRight className="w-4 h-4" aria-hidden="true" />
                        </>
                      )}
                    </button>
                    <p className="text-[11px] text-neutral-600 text-center">Card or bank transfer, secured by {payNowGateway === 'kora' ? 'Kora' : 'Monnify'}.</p>
                  </>
                )}
                {whatsappEnabled && (
                  <button
                    type="button"
                    onClick={() => void handleInitiatePayment('whatsapp')}
                    disabled={Boolean(isInitializingPayment)}
                    className={`w-full py-3.5 rounded-full font-bold text-sm transition-all flex items-center justify-center gap-2 disabled:opacity-60 ${
                      payNowGateway ? 'border border-[#1DA851] text-[#178a43] bg-white hover:bg-emerald-50' : 'text-white shadow-md bg-[#1DA851] hover:bg-[#178a43]'
                    }`}
                  >
                    <WhatsAppIcon className="w-4 h-4" aria-hidden="true" />
                    <span>{isInitializingPayment === 'whatsapp' ? 'Opening WhatsApp…' : 'Pay via WhatsApp (bank transfer)'}</span>
                  </button>
                )}
              </div>
              </div>
            </div>
          </Dialog>
        )}
      </div>
    </div>
  );
}

export default function TrackOrderPage() {
  return (
    <Suspense
      fallback={
        <div className="min-h-screen flex items-center justify-center" role="status">
          <div className="w-10 h-10 border-4 border-black/10 border-t-black rounded-full animate-spin" aria-hidden="true" />
          <span className="sr-only">Loading…</span>
        </div>
      }
    >
      <TrackOrderContent />
    </Suspense>
  );
}
