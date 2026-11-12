'use client';

import React, { Suspense, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { Lock } from 'lucide-react';
import { apiPeekSession, apiGetSetupStatus, apiLogin, apiLogout } from '@/lib/api-client';
import { clearQueryCache } from '@/lib/query-client';
import { isAdminRole, safeAdminNext } from '@/components/admin/AdminAuthGate';
import { Button, Field, LoadingState, Notice, TextInput } from '@/components/admin/ui';

function AdminLoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const next = safeAdminNext(searchParams?.get('next'));
  const expired = searchParams?.get('expired') === '1';

  const [checking, setChecking] = useState(true);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Already signed in → straight to the destination. Brand-new store → setup.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const me = await apiPeekSession('admin');
        if (!cancelled && me.user && isAdminRole(me.user.role)) {
          router.replace(next);
          return;
        }
      } catch {
        // Not signed in.
      }
      try {
        const setup = await apiGetSetupStatus();
        if (!cancelled && !setup.is_setup_completed) {
          router.replace('/admin/setup');
          return;
        }
      } catch {
        if (!cancelled) setError('The store server is not responding. Check that the backend is running, then reload.');
      }
      if (!cancelled) setChecking(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [router, next]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const res = await apiLogin({ email: email.trim(), password });
      if (!isAdminRole(res.user.role)) {
        await apiLogout('customer');
        setError('This account does not have administrator access.');
        return;
      }
      setPassword('');
      // A new sign-in starts clean: no screens cached for whoever used this tab before.
      clearQueryCache();
      router.replace(next);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign in failed. Please check your details and try again.');
    } finally {
      setSubmitting(false);
    }
  };

  if (checking) {
    return <LoadingState label="Checking your admin session…" />;
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4" noValidate>
      <div className="flex flex-col items-center gap-2 text-center">
        <div className="flex size-12 items-center justify-center rounded-full bg-[#f3f4f6] text-[#111827]">
          <Lock className="size-5" aria-hidden="true" />
        </div>
        <h1 className="text-xl font-bold text-[#111827]">Admin sign in</h1>
        <p className="text-sm text-[#6b7280]">Sign in with your store administrator account.</p>
      </div>

      {expired && !error && <Notice tone="warning">Your session ended. Sign in again to continue.</Notice>}
      {error && (
        <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </p>
      )}

      <Field label="Email">
        {(p) => <TextInput {...p} type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />}
      </Field>
      <Field label="Password">
        {(p) => (
          <TextInput {...p} type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        )}
      </Field>

      <Button type="submit" variant="primary" className="w-full" loading={submitting} disabled={!email || !password}>
        {submitting ? 'Signing in…' : 'Sign in'}
      </Button>

      <p className="text-center text-xs text-[#6b7280]">
        <Link href="/" className="hover:text-black hover:underline">
          Back to the store
        </Link>
      </p>
    </form>
  );
}

export default function AdminLoginPage() {
  return (
    <main className="flex min-h-dvh items-center justify-center bg-[#f8f8f8] px-4 py-12">
      <div className="w-full max-w-sm rounded-2xl border border-[#e5e7eb] bg-white p-8 shadow-sm">
        <Suspense fallback={<LoadingState />}>
          <AdminLoginForm />
        </Suspense>
      </div>
    </main>
  );
}
