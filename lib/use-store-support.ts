'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { apiGetPaymentMethods, PaymentMethodItem, PaymentMethodsResponse } from '@/lib/api-client';
import { useStoreSeed } from '@/components/layout/StoreDataProvider';
import { DEFAULT_PAYMENT_WINDOWS, PaymentWindows, paymentWindowsFrom } from '@/lib/site-content';
import { normalizePhoneDigits } from '@/lib/whatsapp';

interface StoreSupport {
  supportPhone: string | null;
  paymentMethods: PaymentMethodItem[];
  /** How long an unpaid order holds its items (the backend's defaults until loaded). */
  paymentWindows: PaymentWindows;
  /** The payment return page may open the live status stream (SSE_ENABLED on the server). */
  livePaymentStatus: boolean;
  /** The gateway behind the single "Pay now" button, or null when none is on. */
  payNowGateway: 'monnify' | 'kora' | null;
  /** "Pay via WhatsApp" is offered. */
  whatsappEnabled: boolean;
  loaded: boolean;
}

let cache: Promise<StoreSupport> | null = null;

function fromResponse(res: PaymentMethodsResponse | null | undefined): StoreSupport {
  const methods = res?.methods || [];
  // Older answers without the fields: the first gateway in the list, and WhatsApp if listed.
  const listedGateway = methods.find((m) => m.id === 'monnify' || m.id === 'kora')?.id as 'monnify' | 'kora' | undefined;
  return {
    supportPhone: res?.support_phone || null,
    paymentMethods: methods,
    paymentWindows: paymentWindowsFrom(res),
    livePaymentStatus: res?.live_payment_status === true,
    payNowGateway: res?.pay_now_gateway !== undefined ? res.pay_now_gateway : (listedGateway ?? null),
    // Without a number there is nobody to send the transfer details to.
    whatsappEnabled: (res?.whatsapp_enabled ?? methods.some((m) => m.id === 'whatsapp')) && normalizePhoneDigits(res?.support_phone) !== '',
    loaded: true,
  };
}

function load(): Promise<StoreSupport> {
  if (!cache) {
    cache = apiGetPaymentMethods()
      .then(fromResponse)
      .catch(() => {
        cache = null; // retry on the next mount
        return { supportPhone: null, paymentMethods: [], paymentWindows: DEFAULT_PAYMENT_WINDOWS, livePaymentStatus: false, payNowGateway: null, whatsappEnabled: false, loaded: true };
      });
  }
  return cache;
}

/** Forget what was loaded in the browser (after an admin change), so the next use asks again. */
export function resetStoreSupportCache(): void {
  cache = null;
}

/**
 * Store support WhatsApp number and enabled payment methods, as configured on the backend.
 * Comes with the page when the server fetched it (always the newest copy the page has: a
 * refreshed page brings a new one); otherwise loaded once per page session and shared by every
 * component that needs it.
 */
export function useStoreSupport(): StoreSupport & { retry: () => void } {
  const seeded = useStoreSeed()?.payments;
  const fromSeed = useMemo(() => (seeded ? fromResponse(seeded) : null), [seeded]);
  const [state, setState] = useState<StoreSupport>({ supportPhone: null, paymentMethods: [], paymentWindows: DEFAULT_PAYMENT_WINDOWS, livePaymentStatus: false, payNowGateway: null, whatsappEnabled: false, loaded: false });
  const [attempt, setAttempt] = useState(0);
  const useSeed = fromSeed !== null && attempt === 0;

  useEffect(() => {
    if (useSeed) {
      cache = Promise.resolve(fromSeed);
      return;
    }
    let active = true;
    load().then((value) => {
      if (active) setState(value);
    });
    return () => {
      active = false;
    };
  }, [useSeed, fromSeed, attempt]);

  /** Ask the server again (after a failed load, or to pick up methods switched back on). */
  const retry = useCallback(() => {
    cache = null;
    setState((s) => ({ ...s, loaded: false }));
    setAttempt((a) => a + 1);
  }, []);

  return { ...(useSeed ? fromSeed : state), retry };
}
