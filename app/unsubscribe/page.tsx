'use client';

import { Suspense, useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { AlertCircle, CheckCircle2 } from 'lucide-react';
import { ApiError, apiUnsubscribeNewsletter } from '@/lib/api-client';

type UnsubscribeState =
  | { kind: 'working' }
  | { kind: 'done'; email: string }
  | { kind: 'invalid' }
  | { kind: 'failed'; message: string };

function Unsubscribe() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token') || '';
  const [state, setState] = useState<UnsubscribeState>({ kind: 'working' });
  // React StrictMode runs effects twice in development; one request is enough.
  const started = useRef(false);

  const run = useCallback(async () => {
    if (!/^[a-f0-9]{32}$/.test(token)) {
      setState({ kind: 'invalid' });
      return;
    }
    setState({ kind: 'working' });
    try {
      const res = await apiUnsubscribeNewsletter(token);
      setState({ kind: 'done', email: res.email });
    } catch (err) {
      if (err instanceof ApiError && (err.statusCode === 404 || err.statusCode === 422)) {
        setState({ kind: 'invalid' });
      } else {
        setState({
          kind: 'failed',
          message: err instanceof Error && err.message ? err.message : 'We could not reach the store. Please try again.',
        });
      }
    }
  }, [token]);

  useEffect(() => {
    if (started.current) return;
    started.current = true;
    run();
  }, [run]);

  return (
    <div className="bg-white rounded-3xl p-8 sm:p-12 text-center border border-neutral-100 shadow-sm space-y-4" role="status" aria-live="polite">
      {state.kind === 'working' && (
        <>
          <div className="w-10 h-10 border-4 border-black/10 border-t-black rounded-full animate-spin mx-auto" />
          <p className="text-sm text-neutral-600 font-medium">Unsubscribing you…</p>
        </>
      )}

      {state.kind === 'done' && (
        <>
          <CheckCircle2 className="w-10 h-10 text-green-600 mx-auto" aria-hidden="true" />
          <h1 className="text-lg font-bold text-black">You&apos;re unsubscribed</h1>
          <p className="text-sm text-neutral-600 max-w-md mx-auto">
            <span className="font-semibold text-black">{state.email}</span> has been unsubscribed. You won&apos;t receive newsletters from us
            any more. Order and delivery emails are not affected.
          </p>
        </>
      )}

      {state.kind === 'invalid' && (
        <>
          <AlertCircle className="w-10 h-10 text-red-500 mx-auto" aria-hidden="true" />
          <h1 className="text-lg font-bold text-black">This unsubscribe link is not valid</h1>
          <p className="text-sm text-neutral-600 max-w-md mx-auto">
            Please use the unsubscribe link from the most recent newsletter email you received.
          </p>
        </>
      )}

      {state.kind === 'failed' && (
        <>
          <AlertCircle className="w-10 h-10 text-red-500 mx-auto" aria-hidden="true" />
          <h1 className="text-lg font-bold text-black">We couldn&apos;t unsubscribe you yet</h1>
          <p className="text-sm text-neutral-600 max-w-md mx-auto">{state.message}</p>
          <button
            type="button"
            onClick={run}
            className="bg-accent text-white hover:bg-accent-hover font-semibold px-6 py-3 rounded-full text-xs transition-colors"
          >
            Try again
          </button>
        </>
      )}

      {state.kind !== 'working' && (
        <div className="pt-2">
          <Link
            href="/"
            className="inline-block bg-neutral-100 hover:bg-neutral-200 text-black font-semibold px-6 py-3 rounded-full text-xs transition-colors"
          >
            Back to shop
          </Link>
        </div>
      )}
    </div>
  );
}

export default function UnsubscribePage() {
  return (
    <main className="max-w-4xl mx-auto px-4 py-16 sm:py-24">
      <div className="max-w-lg mx-auto">
        <Suspense
          fallback={
            <div className="bg-white rounded-3xl p-12 text-center border border-neutral-100 shadow-sm">
              <div className="w-10 h-10 border-4 border-black/10 border-t-black rounded-full animate-spin mx-auto" />
            </div>
          }
        >
          <Unsubscribe />
        </Suspense>
      </div>
    </main>
  );
}
