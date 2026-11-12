'use client';

import React, { useState, useEffect, useEffectEvent, useCallback, useRef } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCart } from '@/context/CartContext';
import { useAuth } from '@/context/AuthContext';
import { HugeiconsIcon } from '@hugeicons/react';
import {
  Cancel01Icon,
  CheckmarkBadge01Icon,
  DeliveryTruck01Icon,
  Ticket02Icon,
  AlertCircleIcon,
  Copy01Icon,
  Tick02Icon,
  ArrowRight01Icon,
} from '@hugeicons/core-free-icons';
import { formatPrice } from '@/lib/currency';
import {
  apiGetCheckoutQuote,
  apiCreateCheckoutOrder,
  apiInitializePayment,
  apiGetOrder,
  apiVerifyPayment,
  apiMarkPaymentSent,
  apiReleaseOrder,
  ApiError,
  CheckoutQuoteResponse,
  errorMessage,
} from '@/lib/api-client';
import { buildWhatsAppInvoiceUrl, whatsAppItems } from '@/lib/whatsapp';
import { useStoreSupport } from '@/lib/use-store-support';
import {
  cartSignature,
  clearCheckoutDraft,
  clearPendingOrder,
  readCheckoutDraft,
  readGuestToken,
  readPendingOrder,
  saveCheckoutDraft,
  saveGuestToken,
  markInvoiceSent,
  savePendingOrder,
} from '@/lib/pending-order';
import { formatShopDeadline } from '@/lib/shop-time';
import {
  CHECKOUT_FIELD_ORDER,
  ageConfirmErrorFrom,
  ageConfirmLabel,
  ageConfirmMissingMessage,
  checkoutFieldErrorsFrom,
  cleanPhone,
  firstValidationMessage,
  validateCheckoutDetails,
  type CheckoutField,
  type CheckoutFieldErrors,
} from '@/lib/checkout-validation';
import { useInvoiceSent } from '@/lib/use-order-data';
import { NIGERIAN_STATES } from '@/lib/nigeria';
import { SHOP, mainOption, option1Word, option2Word } from '@/lib/shop';
import { Dialog } from '@/components/ui/Dialog';
import { PaymentMethodsNotice } from '@/components/cart/PaymentMethodsNotice';

interface CheckoutModalProps {
  isOpen: boolean;
  onClose: () => void;
}

type PaymentMethodId = 'monnify' | 'kora' | 'whatsapp';

/** What the payment step needs, whether the order was just placed or is being resumed. */
interface PayableOrder {
  order_number: string;
  guest_email: string;
  guest_access_token?: string;
  subtotal: number;
  discount_amount: number;
  shipping_fee: number;
  grand_total: number;
  expires_at?: string | null;
  shipping_address: { full_name?: string; phone?: string; address_line1?: string; city?: string; state?: string };
  items: Array<{ name: string; quantity: number; size?: string; line_total?: number; price?: number }>;
}

function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID().replace(/-/g, '');
  }
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

const fieldClass =
  'w-full bg-surface rounded-xl px-3.5 py-2.5 text-sm text-black placeholder:text-neutral-500 focus:outline-none focus:ring-2 focus:ring-accent';
const labelClass = 'text-xs font-semibold text-neutral-700 block mb-1';

interface CheckoutForm {
  fullName: string;
  email: string;
  phone: string;
  address: string;
  city: string;
  state: string;
  notes: string;
  selectedZoneId: number | undefined;
  /** Optional pin shared from the phone ("Use my current location"), for the rider's map. */
  location?: { latitude: number; longitude: number; accuracy_m: number | null };
}

const EMPTY_FORM: CheckoutForm = { fullName: '', email: '', phone: '', address: '', city: '', state: '', notes: '', selectedZoneId: undefined };

const FIELD_INPUT_IDS: Record<CheckoutField, string> = {
  fullName: 'co-name',
  email: 'co-email',
  phone: 'co-phone',
  address: 'co-address',
  city: 'co-city',
  state: 'co-state',
};

/** The details this tab kept over a refresh (lib/pending-order.ts), or an empty form. */
function formFromDraft(): CheckoutForm {
  const draft = readCheckoutDraft();
  if (!draft) return EMPTY_FORM;
  const text = (value: unknown) => (typeof value === 'string' ? value : '');
  const state = text(draft.state);
  const pin = draft.location as CheckoutForm['location'] | undefined;
  return {
    ...EMPTY_FORM,
    fullName: text(draft.fullName),
    email: text(draft.email),
    phone: text(draft.phone),
    address: text(draft.address),
    city: text(draft.city),
    state: NIGERIAN_STATES.includes(state) ? state : '',
    notes: text(draft.notes),
    location: typeof pin?.latitude === 'number' && typeof pin?.longitude === 'number' ? pin : undefined,
  };
}

/** A field's own message, under it. */
function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null;
  return <p id={id} className="mt-1 text-xs text-red-700">{message}</p>;
}

/**
 * Checkout window. The delivery details typed in it are kept when the shopper closes and
 * reopens it, and in this tab over a refresh; everything else (step, resumed order, quote,
 * errors) starts fresh on each open.
 */
export const CheckoutModal: React.FC<CheckoutModalProps> = ({ isOpen, onClose }) => {
  const { user, addresses } = useAuth();
  // Read on the browser only; nothing here is drawn until checkout is opened, so the first
  // render still matches the server's.
  const [formData, setFormData] = useState<CheckoutForm>(formFromDraft);
  // Whose details the form holds: another account signing in must not see or send them.
  const [formOwner, setFormOwner] = useState<string | null>(() => {
    const owner = readCheckoutDraft()?.ownerId;
    return typeof owner === 'string' ? owner : null;
  });

  // Kept for this tab only, without the delivery option (chosen again from the live quote).
  useEffect(() => {
    const details = { ...formData, selectedZoneId: undefined };
    if (Object.values(details).some(Boolean)) saveCheckoutDraft({ ...details, ownerId: formOwner });
    else clearCheckoutDraft();
  }, [formData, formOwner]);

  // Prefill from the signed-in customer and their default address, once per account and
  // address, never overwriting what was typed. Adjusted while rendering (React's pattern for
  // state that follows a changing input), so the fields are filled on the first paint.
  const defaultAddr = addresses.find((a) => a.is_default) || addresses[0];
  const prefillKey = user ? `${user.id}:${defaultAddr?.id ?? ''}` : '';
  const [prefilledFor, setPrefilledFor] = useState('');
  if (prefillKey !== prefilledFor) {
    setPrefilledFor(prefillKey);
    if (user) {
      const userId = String(user.id);
      const otherAccount = formOwner !== null && formOwner !== userId;
      setFormOwner(userId);
      setFormData((current) => {
        const prev = otherAccount ? EMPTY_FORM : current;
        return {
          ...prev,
          fullName: prev.fullName || defaultAddr?.recipient_name || user.full_name || '',
          email: prev.email || user.email || '',
          phone: prev.phone || defaultAddr?.phone || user.phone || '',
          address: prev.address || defaultAddr?.street_address || '',
          city: prev.city || defaultAddr?.city || '',
          state: prev.state || (defaultAddr?.state && NIGERIAN_STATES.includes(defaultAddr.state) ? defaultAddr.state : ''),
        };
      });
    } else if (prefilledFor) {
      // Signed out: that cleared the kept details, so the next person gets an empty form. A
      // session that merely expired keeps what was typed.
      setFormData(formFromDraft());
    }
  }

  if (!isOpen) return null;
  return <CheckoutSession onClose={onClose} formData={formData} setFormData={setFormData} />;
};

function CheckoutSession({
  onClose,
  formData,
  setFormData,
}: {
  onClose: () => void;
  formData: CheckoutForm;
  setFormData: React.Dispatch<React.SetStateAction<CheckoutForm>>;
}) {
  const { items, appliedPromo, settlePendingOrder, removePromoCode } = useCart();
  const { user } = useAuth();
  const {
    supportPhone,
    paymentWindows,
    payNowGateway,
    whatsappEnabled,
    loaded: paymentMethodsLoaded,
    retry: retryPaymentMethods,
  } = useStoreSupport();

  const [step, setStep] = useState<'details' | 'payment'>('details');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  // The shop refused the order because this customer already holds its limit of unpaid orders:
  // the message then comes with a link to those orders.
  const [atUnpaidLimit, setAtUnpaidLimit] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<CheckoutFieldErrors>({});
  // The age tick box (shown when the cart holds an item for adults only). Asked again at each
  // checkout: it is never kept with the delivery details.
  const [ageConfirmed, setAgeConfirmed] = useState(false);
  const [ageError, setAgeError] = useState<string | null>(null);
  // The server refused an order for the missing confirmation although the quote had not asked for it.
  const [ageAskedByServer, setAgeAskedByServer] = useState(false);
  const submitErrorRef = useRef<HTMLDivElement>(null);
  // Whether this cart's unpaid order still has to be looked at. Until it has been, a new order
  // could duplicate it, so "Continue" waits; 'failed' offers another try.
  // An unpaid order for a different cart is looked at too: its items are given back first, or
  // they would be held twice and could block this very checkout ("only 0 available").
  const [resumeState, setResumeState] = useState<'idle' | 'checking' | 'failed'>(() => (readPendingOrder() ? 'checking' : 'idle'));
  // An earlier order that could not be given up because a payment was started for it.
  const [heldOrder, setHeldOrder] = useState<string | null>(null);
  const [resumeAttempt, setResumeAttempt] = useState(0);
  const [isReleasing, setIsReleasing] = useState(false);
  const [order, setOrder] = useState<PayableOrder | null>(null);
  const [copiedToken, setCopiedToken] = useState(false);
  // Which button is starting a payment ('whatsapp' or the "Pay now" gateway), for its label.
  const [isInitializingPayment, setInitializingMethod] = useState<PaymentMethodId | false>(false);
  const setIsInitializingPayment = (v: false) => setInitializingMethod(v);
  const [paymentError, setPaymentError] = useState<string | null>(null);
  const [isCheckingPaid, setIsCheckingPaid] = useState(false);
  const [awaitingFlash, setAwaitingFlash] = useState(false);
  const router = useRouter();

  // WhatsApp: once the invoice is sent the button becomes "Mark as paid" (remembered on this device).
  const orderNumber = order?.order_number;
  const invoiceSent = useInvoiceSent(orderNumber);
  // The "awaiting confirmation" note belongs to the order it was shown for.
  const [awaitingNoteFor, setAwaitingNoteFor] = useState<{ orderNumber: string; text: string } | null>(null);
  const awaitingNote = awaitingNoteFor && awaitingNoteFor.orderNumber === orderNumber ? awaitingNoteFor.text : null;

  // One key per checkout attempt: a double click or a retry after a dropped response
  // returns the same order instead of creating (and reserving stock for) a second one.
  const idempotencyKeyRef = useRef<string | null>(null);
  // The order details the key was made for: a retry after a lost answer reuses the key (so the
  // server returns the order it may already have made), but changed details get a new key, or
  // the server would silently return the earlier order with the old address or items.
  const idempotencySignatureRef = useRef<string | null>(null);
  const dialogRef = useRef<HTMLDivElement>(null);

  const busy = isSubmitting || isInitializingPayment !== false || isReleasing;

  const handleClose = useCallback(() => {
    if (busy) return;
    onClose();
  }, [busy, onClose]);

  // On open: resume this cart's unpaid order if there is one (otherwise the details step).
  const resumePendingOrder = useEffectEvent(() => {
    const pending = readPendingOrder();
    if (!pending) {
      // It was there when the window opened and is gone now (settled in another tab).
      void Promise.resolve().then(() => setResumeState('idle'));
      return;
    }

    const token = readGuestToken(pending.orderNumber);
    if (pending.cartSignature !== cartSignature(items)) {
      // The cart changed since that order was placed, so this checkout makes a new one. Give the
      // old order's items back first (the server refuses if a payment was started for it).
      apiReleaseOrder(pending.orderNumber, token)
        .then(
          ({ released }) => {
            if (released) clearPendingOrder(pending.orderNumber);
            else setHeldOrder(pending.orderNumber);
          },
          (err) => {
            // The server says this device can't have that order: nothing here to give back.
            if (err instanceof ApiError && (err.statusCode === 403 || err.statusCode === 404)) clearPendingOrder(pending.orderNumber);
          }
        )
        .finally(() => setResumeState('idle'));
      return;
    }

    apiGetOrder(pending.orderNumber, token ? { token } : undefined)
      .then((o) => {
        if (o.order_status !== 'pending_payment') {
          // Paid meanwhile (e.g. a WhatsApp transfer the store confirmed): take its items out
          // of the cart instead of only forgetting the order.
          setStep('details');
          setOrder(null);
          void settlePendingOrder().then((outcome) => {
            if (outcome === 'paid') {
              router.push(`/checkout/callback?order=${encodeURIComponent(pending.orderNumber)}`);
              onClose();
            } else if (outcome === 'unknown') {
              // Couldn't ask: it may still be paid, so it isn't forgotten yet.
              setResumeState('failed');
            } else {
              clearPendingOrder(pending.orderNumber);
              setResumeState('idle');
            }
          });
          return;
        }
        setOrder({
          order_number: o.order_number,
          guest_email: o.guest_email ?? '',
          guest_access_token: token,
          subtotal: Number(o.subtotal),
          discount_amount: Number(o.discount_amount),
          shipping_fee: Number(o.shipping_fee),
          grand_total: Number(o.grand_total),
          expires_at: o.expires_at,
          shipping_address: o.shipping_address_snapshot || {},
          items: whatsAppItems(o.items),
        });
        setStep('payment');
        setResumeState('idle');
      })
      .catch((err) => {
        setStep('details');
        // Only the server saying this device can't have the order ends it. A network blip or a
        // busy server must not forget it, or the next submit would place a second order.
        if (err instanceof ApiError && (err.statusCode === 403 || err.statusCode === 404)) {
          clearPendingOrder(pending.orderNumber);
          setResumeState('idle');
        } else {
          setResumeState('failed');
        }
      });
  });
  useEffect(() => {
    resumePendingOrder();
  }, [resumeAttempt]);

  const retryResume = () => {
    const pending = readPendingOrder();
    if (!pending || pending.cartSignature !== cartSignature(items)) {
      setResumeState('idle');
      return;
    }
    setResumeState('checking');
    setResumeAttempt((n) => n + 1);
  };

  // On a phone the form scrolls: an order error at its top must not go unseen below the fold.
  useEffect(() => {
    if (!submitError) return;
    submitErrorRef.current?.scrollIntoView({ block: 'center' });
    submitErrorRef.current?.focus({ preventScroll: true });
  }, [submitError]);

  // Back from the gateway with the page restored as it was left: the buttons are free again.
  useEffect(() => {
    const onPageShow = (e: PageTransitionEvent) => {
      if (e.persisted) setInitializingMethod(false);
    };
    window.addEventListener('pageshow', onPageShow);
    return () => window.removeEventListener('pageshow', onPageShow);
  }, []);

  // The Dialog handles Escape, focus trapping and scroll locking. When the step changes,
  // move focus to the first field of the new step (skipping the close button).
  // Once per step and order (not each time the same order is refreshed, which would pull focus
  // away from the button just pressed). The new step also starts at its top.
  const focusedOrder = order?.order_number;
  useEffect(() => {
    const firstField = dialogRef.current?.querySelector<HTMLElement>('input, select, button:not([data-close])');
    firstField?.focus({ preventScroll: true });
    const scroller = dialogRef.current?.querySelector<HTMLElement>('[data-dialog-body]');
    if (scroller) scroller.scrollTop = 0;
  }, [step, focusedOrder]);

  const checkoutItems = useCallback(
    () =>
      items.map((item) => ({
        product_id: parseInt(item.productId, 10),
        variant_id: item.variantId,
        quantity: item.quantity,
      })),
    [items]
  );

  // Server quote for the chosen state: authoritative prices, discount and delivery fee.
  // One request per distinct question; only the answer to the current one counts. It waits until
  // an earlier unpaid order has been looked at: that order may still hold this cart's stock.
  const quoteRequest =
    step === 'details' && items.length > 0 && formData.state && resumeState !== 'checking'
      ? JSON.stringify({
          items: checkoutItems(),
          shipping_address: { state: formData.state, country: 'Nigeria' },
          shipping_zone_id: formData.selectedZoneId,
          coupon_code: appliedPromo?.code,
        })
      : null;
  const [quoteAnswer, setQuoteAnswer] = useState<{ request: string; quote: CheckoutQuoteResponse | null; error: string | null } | null>(null);
  const [quoteAttempt, setQuoteAttempt] = useState(0);

  useEffect(() => {
    if (!quoteRequest) return;
    let current = true;
    apiGetCheckoutQuote(JSON.parse(quoteRequest)).then(
      (quote) => current && setQuoteAnswer({ request: quoteRequest, quote, error: null }),
      (err) => current && setQuoteAnswer({ request: quoteRequest, quote: null, error: errorMessage(err, 'We could not calculate delivery for this address.') })
    );
    return () => {
      current = false;
    };
  }, [quoteRequest, quoteAttempt]);

  const retryQuote = () => {
    setQuoteAnswer(null);
    setQuoteAttempt((n) => n + 1);
  };

  const isQuoting = quoteRequest !== null && quoteAnswer?.request !== quoteRequest;
  // While a new quote loads, the last one stays on screen (the order button waits for it).
  const quote = formData.state && items.length > 0 ? (quoteAnswer?.quote ?? null) : null;
  const quoteError = !isQuoting && quoteRequest !== null ? (quoteAnswer?.error ?? null) : null;
  // The state was just changed and its quote is on the way: the one on screen is another state's,
  // so its zone, fee and total are not shown as this one's.
  const quoteForOtherState =
    isQuoting && !!quoteAnswer && (JSON.parse(quoteAnswer.request) as { shipping_address?: { state?: string } }).shipping_address?.state !== formData.state;

  // An item for adults only is in the cart: the buyer confirms their age before ordering.
  const ageCheckRequired = quote?.age_check_required === true || ageAskedByServer;
  const minimumAge = Number(quote?.minimum_age) > 0 ? Number(quote?.minimum_age) : SHOP.ageCheck.minAge;
  // Large items the delivery fee counted, and what they added to it (a shop with heavy delivery on).
  const heavyItems = SHOP.features.heavyDelivery ? Number(quote?.heavy_items) || 0 : 0;
  const heavyItemsFee = heavyItems * (Number(quote?.shipping_zone?.heavy_item_fee) || 0);

  const setField = (field: CheckoutField, value: string) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setFieldErrors((prev) => (prev[field] ? { ...prev, [field]: undefined } : prev));
  };
  /** The attributes that tie a field to its message. */
  const fieldErrorProps = (field: CheckoutField) =>
    fieldErrors[field] ? { 'aria-invalid': true, 'aria-describedby': `${FIELD_INPUT_IDS[field]}-error` } : {};

  const handleSubmitOrder = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitError(null);
    setAtUnpaidLimit(false);
    if (resumeState !== 'idle') return;

    if (items.length === 0) {
      setSubmitError('Your cart is empty.');
      return;
    }
    if (items.some((i) => typeof i.variantId !== 'number')) {
      setSubmitError(`Some items need their ${option1Word} and ${option2Word} chosen again. Please review your cart.`);
      return;
    }
    if (!quote?.shipping_zone) {
      setSubmitError('Choose a delivery state we deliver to before placing your order.');
      return;
    }
    const problems = validateCheckoutDetails(formData);
    setFieldErrors(problems);
    setAgeError(null);
    const firstProblem = CHECKOUT_FIELD_ORDER.find((field) => problems[field]);
    if (firstProblem) {
      document.getElementById(FIELD_INPUT_IDS[firstProblem])?.focus();
      return;
    }
    if (ageCheckRequired && !ageConfirmed) {
      setAgeError(ageConfirmMissingMessage(minimumAge));
      document.getElementById('co-age')?.focus();
      return;
    }

    setIsSubmitting(true);
    const orderPayload = {
      email: formData.email.trim(),
      items: checkoutItems(),
      shipping_address: {
        full_name: formData.fullName.trim(),
        phone: cleanPhone(formData.phone),
        address_line1: formData.address.trim(),
        city: formData.city.trim(),
        state: formData.state,
        country: 'Nigeria',
        ...(formData.location ? { location: formData.location } : {}),
      },
      shipping_zone_id: quote.shipping_zone.id,
      coupon_code: appliedPromo?.code,
      notes: formData.notes.trim() || undefined,
      // Only sent when it was asked for and ticked (checked just above).
      ...(ageCheckRequired ? { age_confirmed: true } : {}),
    };
    const payloadSignature = JSON.stringify(orderPayload);
    if (!idempotencyKeyRef.current || idempotencySignatureRef.current !== payloadSignature) {
      idempotencyKeyRef.current = newIdempotencyKey();
      idempotencySignatureRef.current = payloadSignature;
    }

    try {
      const placed = await apiCreateCheckoutOrder(orderPayload, idempotencyKeyRef.current);

      idempotencyKeyRef.current = null;
      idempotencySignatureRef.current = null;
      saveGuestToken(placed.order_number, placed.guest_access_token);
      // The cart stays as it is until payment is verified; remember which order it belongs to.
      savePendingOrder({
        orderNumber: placed.order_number,
        cartSignature: cartSignature(items),
        expiresAt: placed.expires_at ?? null,
        createdAt: placed.created_at,
      });

      setOrder({
        order_number: placed.order_number,
        guest_email: placed.guest_email,
        guest_access_token: placed.guest_access_token,
        subtotal: placed.subtotal,
        discount_amount: placed.discount_amount,
        shipping_fee: placed.shipping_fee,
        grand_total: placed.grand_total,
        expires_at: placed.expires_at,
        shipping_address: placed.shipping_address,
        items: (placed.items || []).map((it) => ({
          name: it.name,
          quantity: Number(it.quantity),
          // Never the "none" value of an option the product does not use.
          size: mainOption(it.variant_options?.size, it.variant_options?.color) || undefined,
          line_total: Number(it.line_total),
        })),
      });
      setStep('payment');
    } catch (err) {
      // A 422 can say which detail the server refused: shown on that field, and at the top.
      const details = err instanceof ApiError && err.statusCode === 422 ? err.details : undefined;
      setFieldErrors(checkoutFieldErrorsFrom(details));
      // The server asks for the age confirmation: its message goes under the tick box, which is
      // shown from now on even if the quote on screen had not asked for it.
      const ageProblem = ageConfirmErrorFrom(details);
      setAgeError(ageProblem);
      if (ageProblem) {
        setAgeAskedByServer(true);
        setAgeConfirmed(false);
      }
      setAtUnpaidLimit(err instanceof ApiError && err.errorCode === 'TOO_MANY_UNPAID_ORDERS');
      setSubmitError(firstValidationMessage(details) ?? errorMessage(err, 'We could not place your order. Please review your details and try again.'));
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleCopyGuestToken = async () => {
    if (!order?.guest_access_token) return;
    try {
      await navigator.clipboard.writeText(order.guest_access_token);
      setCopiedToken(true);
      setTimeout(() => setCopiedToken(false), 2500);
    } catch {
      // Clipboard blocked: the key is still visible to copy by hand.
    }
  };

  const whatsappInvoice = order
    ? buildWhatsAppInvoiceUrl(
        {
          order_number: order.order_number,
          grand_total: order.grand_total,
          subtotal: order.subtotal,
          shipping_fee: order.shipping_fee,
          discount_amount: order.discount_amount,
          items: order.items,
          guest_email: order.guest_email,
          shipping_address: order.shipping_address,
        },
        supportPhone
      )
    : '';

  const handlePayOrder = async (selectedMethod: PaymentMethodId) => {
    if (!order) return;
    setInitializingMethod(selectedMethod);
    setPaymentError(null);

    const redirectUrl = `${window.location.origin}/checkout/callback?order=${encodeURIComponent(order.order_number)}`;
    // Open the WhatsApp tab synchronously (inside the click) so pop-up blockers allow it.
    const whatsappTab = selectedMethod === 'whatsapp' ? window.open('about:blank', '_blank') : null;

    try {
      // Every method starts a server-side payment attempt; for WhatsApp this keeps the stock
      // reserved longer while the customer arranges the transfer.
      const res = await apiInitializePayment(order.order_number, order.guest_access_token, redirectUrl, selectedMethod);

      if (selectedMethod === 'whatsapp') {
        if (!whatsappInvoice) throw new Error('The store has no WhatsApp number at the moment. Please choose another way to pay, or contact the store.');
        if (whatsappTab) whatsappTab.location.href = whatsappInvoice;
        else window.location.href = whatsappInvoice;
        markInvoiceSent(order.order_number);
        if (res.expires_at) {
          setOrder((prev) => (prev ? { ...prev, expires_at: res.expires_at } : prev));
        }
        setIsInitializingPayment(false);
        return;
      }

      if (!res.checkout_url) {
        throw new Error('The payment provider did not return a checkout page. Please try again.');
      }
      window.location.href = res.checkout_url;
    } catch (err) {
      whatsappTab?.close();
      setPaymentError(errorMessage(err, 'We could not start the payment. Please try again.'));
      setIsInitializingPayment(false);
    }
  };

  // The store confirms WhatsApp transfers by hand. Once it has, "Mark as paid" opens the
  // receipt (which also empties the cart); until then it says the payment is awaited.
  const handleMarkPaid = async () => {
    if (!order) return;
    setIsCheckingPaid(true);
    setPaymentError(null);
    try {
      const res = await apiVerifyPayment(order.order_number, order.guest_access_token);
      if (res.status === 'successful' || res.order_status === 'cancelled') {
        // The receipt page shows the confirmed payment (or explains an expired order).
        router.push(`/checkout/callback?order=${encodeURIComponent(order.order_number)}`);
        onClose();
        return;
      }
      // Not confirmed yet: tell the store the transfer was sent (it emails the admins), then
      // wait on the payment page, which shows "Awaiting confirmation" and opens the receipt the
      // moment the store confirms (live, or by checking every few seconds).
      const sent = await apiMarkPaymentSent(order.order_number, order.guest_access_token).catch(() => null);
      if (!sent) {
        setAwaitingNoteFor({ orderNumber: order.order_number, text: 'We could not reach the store just now. Please tap again in a moment.' });
        setAwaitingFlash(true);
        setTimeout(() => setAwaitingFlash(false), 3000);
        return;
      }
      router.push(`/checkout/callback?order=${encodeURIComponent(order.order_number)}`);
      onClose();
    } catch (err) {
      setPaymentError(errorMessage(err, 'We could not check the payment right now. Please try again.'));
    } finally {
      setIsCheckingPaid(false);
    }
  };

  const expiresLabel = formatShopDeadline(order?.expires_at);

  // Going back gives the order's items up if no payment was started for it (the server decides).
  // Until it has said so the order is still this cart's, so it isn't forgotten: another order now
  // would be a duplicate, and could be paid for twice.
  const startOver = async () => {
    if (!order) {
      setStep('details');
      return;
    }
    setIsReleasing(true);
    setPaymentError(null);
    try {
      const { released } = await apiReleaseOrder(order.order_number, readGuestToken(order.order_number));
      if (!released) {
        setPaymentError(
          `A payment was already started for this order, so its delivery details can't be changed here. You can finish paying${expiresLabel ? ` by ${expiresLabel}` : ''}, or contact the store to change them.`
        );
        return;
      }
    } catch (err) {
      // A refused security token says nothing about the order, which still holds its stock.
      if (!(err instanceof ApiError && err.statusCode === 404)) {
        setPaymentError(errorMessage(err, 'We could not reach the store just now. Please try again in a moment.'));
        return;
      }
      // The order is gone, or isn't this device's: nothing to give back.
    } finally {
      setIsReleasing(false);
    }
    clearPendingOrder(order.order_number);
    setOrder(null);
    setStep('details');
  };

  const zones = quote?.eligible_zones ?? [];
  const itemCount = quote?.total_quantity ?? items.reduce((n, i) => n + i.quantity, 0);
  const methodAccent: Record<PaymentMethodId, string> = {
    monnify: 'bg-[#002244] hover:bg-[#001730]',
    kora: 'bg-[#055B5C] hover:bg-[#044748]',
    whatsapp: 'bg-[#1DA851] hover:bg-[#178a43]',
  };

  return (
    <Dialog
      open
      onClose={handleClose}
      title={step === 'details' ? 'Checkout: delivery details' : 'Checkout: complete your payment'}
      busy={busy}
      dismissOnBackdrop={!order}
      backdropClassName="bg-black/60 backdrop-blur-sm"
      panelClassName="w-full max-w-md sm:mx-0"
    >
      <div
        ref={dialogRef}
        className="relative flex max-h-[92vh] supports-[height:100dvh]:max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-3xl"
        aria-busy={busy || undefined}
      >
        {/* A fixed row: only the part below it scrolls (long forms and invoices on small phones). */}
        <div className="flex shrink-0 items-center justify-between gap-3 border-b border-neutral-100 py-2.5 pl-5 pr-3 sm:pl-6 sm:pr-4">
          <div className="flex min-w-0 items-center gap-2">
            <HugeiconsIcon icon={DeliveryTruck01Icon} size={20} className="shrink-0 text-black" aria-hidden="true" />
            <p className="truncate font-bold text-lg text-black" aria-hidden="true">
              {step === 'details' ? 'Delivery details' : 'Checkout'}
            </p>
          </div>
          <button
            type="button"
            data-close
            onClick={handleClose}
            disabled={busy}
            className="shrink-0 p-2 rounded-full text-neutral-600 hover:text-black hover:bg-neutral-100 disabled:opacity-50"
            aria-label="Close checkout"
          >
            <HugeiconsIcon icon={Cancel01Icon} size={18} aria-hidden="true" />
          </button>
        </div>

        <div data-dialog-body className="min-h-0 flex-1 overflow-y-auto p-5 sm:p-6">
        {step === 'details' ? (
          <div>

            {submitError && (
              <div ref={submitErrorRef} tabIndex={-1} role="alert" className="mb-3 p-3 bg-red-50 border border-red-200 rounded-xl flex items-start gap-2 text-sm text-red-800 outline-none">
                <HugeiconsIcon icon={AlertCircleIcon} size={16} className="shrink-0 mt-0.5 text-red-600" aria-hidden="true" />
                <span>
                  {submitError}
                  {atUnpaidLimit && (
                    <>
                      {' '}
                      <Link href={user ? '/orders' : '/track-order'} onClick={onClose} className="font-semibold underline">
                        {user ? 'See my orders' : 'Track an order'}
                      </Link>
                    </>
                  )}
                </span>
              </div>
            )}

            {heldOrder && (
              <div role="status" className="mb-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Order <span className="font-semibold">{heldOrder}</span> is still waiting for the payment you started, and its items stay
                reserved for it. Finish paying it from{' '}
                <Link href={`/track-order?order=${encodeURIComponent(heldOrder)}`} className="font-semibold underline underline-offset-2">
                  Track order
                </Link>{' '}
                before ordering the same items again.
              </div>
            )}

            {resumeState === 'failed' && (
              <div role="alert" className="mb-3 p-3 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900">
                We could not check the order you already started, so we have not placed another one.
                <button type="button" onClick={retryResume} className="block mt-2 font-semibold underline underline-offset-2">
                  Try again
                </button>
              </div>
            )}

            <form onSubmit={handleSubmitOrder} className="space-y-3" noValidate={false} aria-describedby="co-required-note">
              <p id="co-required-note" className="text-xs text-neutral-600">
                All fields are required unless marked optional.
              </p>
              <div>
                <label htmlFor="co-name" className={labelClass}>Full name</label>
                <input id="co-name" type="text" required autoComplete="name" value={formData.fullName}
                  {...fieldErrorProps('fullName')}
                  onChange={(e) => setField('fullName', e.target.value)} className={fieldClass} />
                <FieldError id="co-name-error" message={fieldErrors.fullName} />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <div>
                  <label htmlFor="co-phone" className={labelClass}>Phone number</label>
                  <input id="co-phone" type="tel" required autoComplete="tel" inputMode="tel" placeholder="080..." value={formData.phone}
                    {...fieldErrorProps('phone')}
                    onChange={(e) => setField('phone', e.target.value)} className={fieldClass} />
                  <FieldError id="co-phone-error" message={fieldErrors.phone} />
                </div>
                <div>
                  <label htmlFor="co-email" className={labelClass}>Email</label>
                  <input id="co-email" type="email" required autoComplete="email" value={formData.email}
                    {...fieldErrorProps('email')}
                    onChange={(e) => setField('email', e.target.value)} className={fieldClass} />
                  <FieldError id="co-email-error" message={fieldErrors.email} />
                </div>
              </div>

              <div>
                <label htmlFor="co-address" className={labelClass}>Street address</label>
                <input id="co-address" type="text" required autoComplete="street-address" placeholder="House number, street, area"
                  {...fieldErrorProps('address')}
                  value={formData.address} onChange={(e) => setField('address', e.target.value)} className={fieldClass} />
                <FieldError id="co-address-error" message={fieldErrors.address} />
                <DeliveryPin
                  value={formData.location}
                  onChange={(location) => setFormData((f) => ({ ...f, location }))}
                />
              </div>

              <div className="grid grid-cols-2 gap-2.5">
                <div>
                  <label htmlFor="co-state" className={labelClass}>State</label>
                  <select
                    id="co-state"
                    required
                    aria-invalid={fieldErrors.state ? true : undefined}
                    aria-describedby={fieldErrors.state ? 'co-state-error co-delivery-status' : 'co-delivery-status'}
                    value={formData.state}
                    onChange={(e) => {
                      setField('state', e.target.value);
                      setFormData((prev) => ({ ...prev, selectedZoneId: undefined }));
                    }}
                    className={`${fieldClass} cursor-pointer`}
                  >
                    <option value="" disabled>Select state</option>
                    {NIGERIAN_STATES.map((st) => (
                      <option key={st} value={st}>{st === 'FCT' ? 'Abuja (FCT)' : st}</option>
                    ))}
                  </select>
                  <FieldError id="co-state-error" message={fieldErrors.state} />
                </div>
                <div>
                  <label htmlFor="co-city" className={labelClass}>City / town</label>
                  <input id="co-city" type="text" required autoComplete="address-level2" value={formData.city}
                    {...fieldErrorProps('city')}
                    onChange={(e) => setField('city', e.target.value)} className={fieldClass} />
                  <FieldError id="co-city-error" message={fieldErrors.city} />
                </div>
              </div>

              <div>
                <label htmlFor="co-notes" className={labelClass}>Delivery note (optional)</label>
                <input id="co-notes" type="text" maxLength={300} placeholder="e.g. landmark, gate instructions" value={formData.notes}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })} className={fieldClass} />
              </div>

              {/* Delivery zone & fee from the server */}
              <div id="co-delivery-status" aria-live="polite">
                {!formData.state ? (
                  <p className="text-xs text-neutral-600">Choose your state to see the delivery fee.</p>
                ) : isQuoting && !quote ? (
                  <p className="text-xs text-neutral-600">Calculating delivery…</p>
                ) : quoteError ? (
                  <div role="alert" className="p-3 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900">
                    {quoteError}
                    {appliedPromo && /coupon|promo/i.test(quoteError) && (
                      <button
                        type="button"
                        onClick={removePromoCode}
                        className="block mt-2 font-semibold underline underline-offset-2"
                      >
                        Remove code {appliedPromo.code} and continue without it
                      </button>
                    )}
                    <button type="button" onClick={retryQuote} className="block mt-2 font-semibold underline underline-offset-2">
                      Try again
                    </button>
                  </div>
                ) : zones.length > 1 ? (
                  <fieldset className="space-y-1.5">
                    <legend className={labelClass}>Delivery option</legend>
                    {zones.map((z) => (
                      <label key={z.id} className="flex items-center justify-between gap-2 rounded-xl border border-neutral-200 px-3 py-2 text-sm cursor-pointer has-[:checked]:border-accent">
                        <span className="flex items-center gap-2">
                          <input
                            type="radio"
                            name="zone"
                            className="accent-accent"
                            checked={(formData.selectedZoneId ?? quote?.shipping_zone?.id) === z.id}
                            onChange={() => setFormData((prev) => ({ ...prev, selectedZoneId: z.id }))}
                          />
                          <span>{z.name} <span className="text-neutral-600">· {z.estimated_days}</span></span>
                        </span>
                        <span className="font-semibold">{formatPrice(z.fee)}</span>
                      </label>
                    ))}
                  </fieldset>
                ) : quoteForOtherState ? (
                  <p className="text-xs text-neutral-600" role="status">
                    Calculating delivery to {formData.state === 'FCT' ? 'Abuja (FCT)' : formData.state}…
                  </p>
                ) : quote?.shipping_zone ? (
                  <p className="text-xs text-neutral-700">
                    Delivery to {formData.state === 'FCT' ? 'Abuja (FCT)' : formData.state}: <strong>{quote.shipping_zone.name}</strong> · {quote.shipping_zone.estimated_days}
                  </p>
                ) : null}
              </div>

              {quote?.applied_coupon && (
                <div className="bg-emerald-50 border border-emerald-200 rounded-xl p-2.5 flex items-center justify-between text-sm text-emerald-900 font-medium">
                  <div className="flex items-center gap-1.5">
                    <HugeiconsIcon icon={Ticket02Icon} size={14} className="text-emerald-700" aria-hidden="true" />
                    <span>Coupon <strong>{quote.applied_coupon.code}</strong></span>
                  </div>
                  <span className="font-bold">-{formatPrice(quote.discount_amount)}</span>
                </div>
              )}

              {/* Age check: only when an item in the cart is for adults only (the quote says so). */}
              {ageCheckRequired && (
                <div>
                  <label htmlFor="co-age" className="flex items-start gap-2 text-sm text-neutral-800 cursor-pointer">
                    <input
                      id="co-age"
                      type="checkbox"
                      required
                      checked={ageConfirmed}
                      aria-invalid={ageError ? true : undefined}
                      aria-describedby={ageError ? 'co-age-error' : undefined}
                      onChange={(e) => {
                        setAgeConfirmed(e.target.checked);
                        setAgeError(null);
                      }}
                      className="mt-0.5 size-4 shrink-0 accent-accent"
                    />
                    <span>{ageConfirmLabel(minimumAge)}</span>
                  </label>
                  <FieldError id="co-age-error" message={ageError ?? undefined} />
                </div>
              )}

              <div className="pt-2.5 border-t border-neutral-100 space-y-1.5 text-sm text-neutral-700">
                <div className="flex justify-between">
                  <span>Subtotal ({itemCount} {itemCount === 1 ? 'item' : 'items'})</span>
                  <span className="font-semibold text-black">
                    {formatPrice(quote ? quote.subtotal : items.reduce((acc, i) => acc + i.price * i.quantity, 0))}
                  </span>
                </div>
                {Boolean(quote?.discount_amount) && (
                  <div className="flex justify-between text-red-700">
                    <span>Discount</span>
                    <span className="font-semibold">-{formatPrice(quote?.discount_amount)}</span>
                  </div>
                )}
                <div className="flex justify-between">
                  <span>Delivery</span>
                  <span className="font-semibold text-black">
                    {quote?.shipping_zone && !quoteForOtherState ? formatPrice(quote.shipping_fee) : '—'}
                  </span>
                </div>
                {/* Part of the delivery fee above, said so the buyer knows why it is higher. */}
                {heavyItemsFee > 0 && quote?.shipping_zone && !quoteForOtherState && (
                  <p className="text-xs text-neutral-600">
                    Delivery includes {formatPrice(heavyItemsFee)} for {heavyItems} large {heavyItems === 1 ? 'item' : 'items'}.
                  </p>
                )}
                <div className="flex justify-between items-center pt-2 border-t border-neutral-200">
                  <span className="font-bold text-black">Total</span>
                  <span className="font-black text-lg sm:text-xl text-black">
                    {quote?.shipping_zone && !quoteForOtherState ? formatPrice(quote.grand_total) : '—'}
                  </span>
                </div>

                <button
                  type="submit"
                  disabled={isSubmitting || isQuoting || !quote?.shipping_zone || resumeState !== 'idle'}
                  aria-describedby={!quote?.shipping_zone ? 'co-delivery-status' : undefined}
                  className="w-full bg-accent text-white hover:bg-accent-hover disabled:opacity-50 font-bold py-3.5 rounded-full text-sm transition-all shadow-md mt-2 flex items-center justify-center gap-2"
                >
                  {isSubmitting ? (
                    <span>Placing order…</span>
                  ) : (
                    <>
                      <span>Continue to payment</span>
                      <HugeiconsIcon icon={ArrowRight01Icon} size={15} aria-hidden="true" />
                    </>
                  )}
                </button>
                <p className="text-xs text-neutral-600 text-center">
                  Your items are reserved for {paymentWindows.gatewayMinutes} minutes while you pay.
                </p>
              </div>
            </form>
          </div>
        ) : order ? (
          <div className="space-y-4">
            <div className="text-center">
              <div className="w-12 h-12 bg-emerald-50 text-emerald-700 rounded-full flex items-center justify-center mx-auto border border-emerald-200" aria-hidden="true">
                <HugeiconsIcon icon={CheckmarkBadge01Icon} size={28} />
              </div>
              <p className="font-extrabold text-xl text-black mt-2" aria-hidden="true">Complete your payment</p>
              <p className="text-sm text-neutral-700 mt-0.5">
                Order <strong className="font-mono">{order.order_number}</strong> is reserved
                {expiresLabel ? <> until <strong>{expiresLabel}</strong></> : null}.
              </p>
            </div>

            <div className="bg-[#FAFAFA] rounded-2xl p-4 text-sm space-y-3">
              <ul className="divide-y divide-neutral-100 max-h-36 overflow-y-auto">
                {order.items.map((item, idx) => (
                  <li key={`${item.name}-${idx}`} className="py-2 flex items-center justify-between gap-2">
                    <div className="min-w-0">
                      <p className="font-semibold text-black truncate">{item.name}</p>
                      <p className="text-xs text-neutral-600">
                        Qty {item.quantity}{item.size ? ` · ${item.size}` : ''}
                      </p>
                    </div>
                    <span className="font-medium text-black shrink-0">
                      {formatPrice(item.line_total ?? (item.price ?? 0) * item.quantity)}
                    </span>
                  </li>
                ))}
              </ul>
              <div className="pt-2 border-t border-neutral-200 space-y-1 text-neutral-700">
                <div className="flex justify-between"><span>Subtotal</span><span className="text-black">{formatPrice(order.subtotal)}</span></div>
                {order.discount_amount > 0 && (
                  <div className="flex justify-between text-emerald-800"><span>Discount</span><span>-{formatPrice(order.discount_amount)}</span></div>
                )}
                <div className="flex justify-between"><span>Delivery</span><span className="text-black">{formatPrice(order.shipping_fee)}</span></div>
                <div className="flex justify-between pt-1 border-t border-neutral-200 font-bold text-black">
                  <span>Amount due</span><span>{formatPrice(order.grand_total)}</span>
                </div>
              </div>
            </div>

            {order.guest_access_token && !user && (
              <div className="rounded-2xl border border-neutral-200 p-3 text-xs text-neutral-700 space-y-1.5">
                <p>
                  <strong className="text-black">Your order access key.</strong> It is saved on this device. Keep a copy to track
                  this order elsewhere, or use your order number and email.
                </p>
                <div className="flex items-center gap-2">
                  <code className="flex-1 truncate rounded-lg bg-neutral-100 px-2 py-1 font-mono text-xs">{order.guest_access_token}</code>
                  <button
                    type="button"
                    onClick={handleCopyGuestToken}
                    className="shrink-0 inline-flex items-center gap-1 rounded-lg bg-neutral-900 px-2.5 py-1 text-white"
                    aria-label="Copy order access key"
                  >
                    <HugeiconsIcon icon={copiedToken ? Tick02Icon : Copy01Icon} size={12} aria-hidden="true" />
                    <span aria-live="polite">{copiedToken ? 'Copied' : 'Copy'}</span>
                  </button>
                </div>
              </div>
            )}

            {/* One "Pay now" for the store's card/transfer gateway, and WhatsApp as the other way. */}
            {!payNowGateway && !whatsappEnabled && (
              <PaymentMethodsNotice loaded={paymentMethodsLoaded} supportPhone={supportPhone} onRetry={retryPaymentMethods} />
            )}

            {paymentError && (
              <div role="alert" className="text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-xl p-3">
                {paymentError}
              </div>
            )}

            {payNowGateway && (
              <div className="space-y-1">
                <button
                  type="button"
                  onClick={() => void handlePayOrder(payNowGateway)}
                  disabled={busy || isCheckingPaid}
                  className="w-full bg-accent text-white hover:bg-accent-hover font-bold py-3.5 rounded-full text-sm transition-all shadow-md flex items-center justify-center gap-1.5 disabled:opacity-60"
                >
                  {isInitializingPayment === payNowGateway ? (
                    <span>Opening secure payment…</span>
                  ) : (
                    <>
                      <span>Pay now ({formatPrice(order.grand_total)})</span>
                      <HugeiconsIcon icon={ArrowRight01Icon} size={15} aria-hidden="true" />
                    </>
                  )}
                </button>
                <p className="text-[11px] text-neutral-600 text-center">Card or bank transfer, secured by {payNowGateway === 'kora' ? 'Kora' : 'Monnify'}.</p>
              </div>
            )}

            {whatsappEnabled && (invoiceSent ? (
              <div className="space-y-2">
                <button
                  type="button"
                  onClick={handleMarkPaid}
                  disabled={isCheckingPaid || busy}
                  className={`w-full text-white font-bold py-3.5 rounded-full text-sm transition-colors shadow-md disabled:opacity-60 ${
                    awaitingFlash ? 'bg-amber-600' : methodAccent.whatsapp
                  }`}
                >
                  {isCheckingPaid ? 'Checking…' : "I've sent the transfer"}
                </button>
                <p className="text-xs text-neutral-700 text-center" role="status" aria-live="polite">
                  {awaitingNote ?? 'Sent the transfer? Tap above: we tell the store and show your receipt as soon as they confirm it.'}
                </p>
                <button
                  type="button"
                  onClick={() => void handlePayOrder('whatsapp')}
                  disabled={busy || isCheckingPaid}
                  className="w-full text-xs text-neutral-700 underline hover:no-underline disabled:opacity-50"
                >
                  {isInitializingPayment === 'whatsapp' ? 'Opening WhatsApp…' : 'Send the order number on WhatsApp again'}
                </button>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => void handlePayOrder('whatsapp')}
                disabled={busy || isCheckingPaid}
                className={`w-full font-bold py-3.5 rounded-full text-sm transition-all flex items-center justify-center gap-1.5 disabled:opacity-60 ${
                  payNowGateway ? 'border border-[#1DA851] text-[#178a43] bg-white hover:bg-emerald-50' : `text-white shadow-md ${methodAccent.whatsapp}`
                }`}
              >
                {isInitializingPayment === 'whatsapp' ? 'Opening WhatsApp…' : 'Pay via WhatsApp (bank transfer)'}
              </button>
            ))}

            <div className="flex items-center justify-between gap-2 text-xs">
              <button type="button" onClick={() => void startOver()} disabled={busy} className="text-neutral-700 underline hover:no-underline disabled:opacity-50">
                {isReleasing ? 'Changing…' : 'Change delivery details'}
              </button>
              <button type="button" onClick={handleClose} disabled={busy} className="text-neutral-700 underline hover:no-underline disabled:opacity-50">
                Pay later
              </button>
            </div>
          </div>
        ) : (
          <div className="py-10 text-center text-sm text-neutral-600" role="status">Loading your order…</div>
        )}
        </div>
      </div>
    </Dialog>
  );
};

/**
 * Optional "Use my current location" for the delivery address: the phone asks the customer for
 * permission; the pin is saved with the order so the rider can open it on a map. The typed
 * address stays what the order relies on.
 */
function DeliveryPin({
  value,
  onChange,
}: {
  value: CheckoutForm['location'];
  onChange: (v: CheckoutForm['location']) => void;
}) {
  const [state, setState] = useState<'idle' | 'locating' | 'error'>('idle');
  const [error, setError] = useState('');
  const locate = () => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      setState('error');
      setError('This browser cannot share a location. The address above is enough.');
      return;
    }
    setState('locating');
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        onChange({ latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy_m: Math.round(pos.coords.accuracy) });
        setState('idle');
      },
      (err) => {
        setState('error');
        setError(err.code === err.PERMISSION_DENIED ? 'Location permission was not given. The address above is enough.' : 'Your location could not be found. The address above is enough.');
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
    );
  };
  return (
    <div className="mt-1.5 text-xs">
      {value ? (
        <p className="text-emerald-800">
          Location pin added{value.accuracy_m ? ` (within about ${value.accuracy_m} m)` : ''}.{' '}
          <button type="button" onClick={() => onChange(undefined)} className="font-semibold underline">
            Remove
          </button>
        </p>
      ) : (
        <button type="button" onClick={locate} disabled={state === 'locating'} className="font-semibold text-neutral-800 underline disabled:opacity-60">
          {state === 'locating' ? 'Finding your location…' : 'Use my current location (helps the rider find you)'}
        </button>
      )}
      {state === 'error' && <p role="status" className="mt-1 text-neutral-600">{error}</p>}
    </div>
  );
}
