'use client';

/**
 * Shown where the payment methods go while there are none to list: "Loading…" only while they
 * are actually loading; otherwise (the request failed, or the store switched every method off)
 * a clear message with a retry, instead of "Loading…" forever and a Pay button that stays off.
 */
export function PaymentMethodsNotice({
  loaded,
  supportPhone,
  onRetry,
}: {
  loaded: boolean;
  supportPhone: string | null;
  onRetry: () => void;
}) {
  if (!loaded) {
    return (
      <p className="text-sm text-neutral-700" role="status">
        Loading payment methods…
      </p>
    );
  }
  return (
    <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
      <p>
        No payment option is available right now. Your order is saved; please try again in a moment
        {supportPhone ? `, or message the store on WhatsApp (${supportPhone})` : ''}.
      </p>
      <button type="button" onClick={onRetry} className="mt-2 font-semibold underline underline-offset-2">
        Try again
      </button>
    </div>
  );
}
