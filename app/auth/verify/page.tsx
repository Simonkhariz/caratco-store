'use client';

import { Suspense, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { AlertCircle, CheckCircle2 } from 'lucide-react';
import { useAuth } from '@/context/AuthContext';

type VerifyState = 'verifying' | 'success' | 'error';

function VerifyMagicLink() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const { verifyMagicLink, openAuthModal } = useAuth();
  // The token is read once, on arrival (it is then removed from the address bar).
  const [token] = useState(() => searchParams.get('token') || '');
  const [verifyState, setState] = useState<VerifyState>('verifying');
  const [verifyMessage, setMessage] = useState<string>('');
  const [passwordRemoved, setPasswordRemoved] = useState(false);
  const state: VerifyState = token ? verifyState : 'error';
  const message = token ? verifyMessage : 'This sign-in link is missing its security token. Please request a new link.';
  // Sign-in tokens are single use; guard against React StrictMode running the effect twice.
  const attempted = useRef(false);

  useEffect(() => {
    if (attempted.current) return;
    attempted.current = true;

    // Drop the token from the address bar and history as soon as it has been read.
    window.history.replaceState(null, '', '/auth/verify');
    if (!token) return;

    verifyMagicLink(token)
      .then((removed) => {
        setState('success');
        setPasswordRemoved(removed);
        // Leave time to read the note about the removed password.
        setTimeout(() => router.replace('/orders'), removed ? 6000 : 1200);
      })
      .catch((err: Error) => {
        setState('error');
        setMessage(err.message || 'This sign-in link is invalid, expired, or has already been used.');
      });
  }, [token, verifyMagicLink, router]);

  return (
    <div className="bg-white rounded-3xl p-8 sm:p-12 text-center border border-neutral-100 shadow-sm space-y-4" role="status" aria-live="polite">
      {state === 'verifying' && (
        <>
          <div className="w-10 h-10 border-4 border-black/10 border-t-black rounded-full animate-spin mx-auto" aria-hidden="true" />
          <p className="text-sm text-neutral-600 font-medium">Signing you in…</p>
        </>
      )}

      {state === 'success' && (
        <>
          <CheckCircle2 className="w-10 h-10 text-green-600 mx-auto" aria-hidden="true" />
          <h1 className="text-lg font-bold text-black">You&apos;re signed in</h1>
          {passwordRemoved && (
            <p className="text-sm text-neutral-700 max-w-md mx-auto">
              For your safety, the password set on this account before your email was confirmed has been removed
              and other devices were signed out. Use &quot;Email me a sign-in link&quot; to sign in next time.
            </p>
          )}
          <p className="text-sm text-neutral-600">Taking you to your orders…</p>
        </>
      )}

      {state === 'error' && (
        <>
          <AlertCircle className="w-10 h-10 text-red-500 mx-auto" aria-hidden="true" />
          <h1 className="text-lg font-bold text-black">We couldn&apos;t sign you in</h1>
          <p className="text-sm text-neutral-600 max-w-md mx-auto">{message}</p>
          <div className="pt-2 flex flex-wrap items-center justify-center gap-3">
            <button
              type="button"
              onClick={() => openAuthModal('magic')}
              className="bg-accent text-white hover:bg-accent-hover font-semibold px-6 py-3 rounded-full text-xs transition-colors"
            >
              Send a new sign-in link
            </button>
            <Link
              href="/"
              className="bg-neutral-100 hover:bg-neutral-200 text-black font-semibold px-6 py-3 rounded-full text-xs transition-colors"
            >
              Back to shop
            </Link>
          </div>
        </>
      )}
    </div>
  );
}

export default function VerifyMagicLinkPage() {
  return (
    <main className="max-w-4xl mx-auto px-4 py-16 sm:py-24">
      <div className="max-w-lg mx-auto">
        <Suspense
          fallback={
            <div className="bg-white rounded-3xl p-12 text-center border border-neutral-100 shadow-sm">
              <div className="w-10 h-10 border-4 border-black/10 border-t-black rounded-full animate-spin mx-auto" aria-hidden="true" />
            </div>
          }
        >
          <VerifyMagicLink />
        </Suspense>
      </div>
    </main>
  );
}
