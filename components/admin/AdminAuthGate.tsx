'use client';

import React, { createContext, useCallback, useContext, useEffect, useEffectEvent, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { ApiUser, apiPeekSession, apiGetSetupStatus, apiLogout } from '@/lib/api-client';
import { LoadingState, Skeleton } from '@/components/admin/ui';

interface AdminSession {
  admin: ApiUser;
  /** After the admin edits their profile, so the sidebar and greeting show it without a reload. */
  updateAdmin: (changes: Partial<ApiUser>) => void;
  signOut: () => Promise<void>;
}

const AdminSessionContext = createContext<AdminSession | null>(null);

export function useAdminSession(): AdminSession {
  const ctx = useContext(AdminSessionContext);
  if (!ctx) throw new Error('useAdminSession must be used inside <AdminAuthGate>');
  return ctx;
}

export function isAdminRole(role: string | undefined): boolean {
  return role === 'admin' || role === 'super_admin';
}

/** Only same-app admin paths are accepted as a post-login destination. */
export function safeAdminNext(next: string | null | undefined): string {
  return next && next.startsWith('/admin') && !next.startsWith('//') && !next.startsWith('/admin/login') && !next.startsWith('/admin/setup')
    ? next
    : '/admin';
}

/**
 * Guards the admin panel behind a real admin session (HTTP-only `shopco_admin` cookie).
 * Signed-out visitors go to /admin/login (or /admin/setup on a brand-new store).
 * The API enforces the same rule server-side on every request.
 */
export function AdminAuthGate({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [admin, setAdmin] = useState<ApiUser | null>(null);

  // Sends a signed-out visitor to sign in (or to setup), coming back to the page they asked for.
  const leaveForSignIn = useEffectEvent((needsSetup: boolean) => {
    router.replace(needsSetup ? '/admin/setup' : `/admin/login?next=${encodeURIComponent(pathname + window.location.search)}`);
  });

  // Only on first load: later 401s are handled by redirectIfSignedOut().
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const me = await apiPeekSession('admin');
        if (cancelled) return;
        if (!me.user || !isAdminRole(me.user.role)) throw new Error('not admin');
        setAdmin(me.user);
      } catch {
        if (cancelled) return;
        let needsSetup = false;
        try {
          needsSetup = !(await apiGetSetupStatus()).is_setup_completed;
        } catch {
          // If the status check fails, the login page explains the problem.
        }
        if (!cancelled) leaveForSignIn(needsSetup);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const signOut = useCallback(async () => {
    try {
      await apiLogout('admin');
    } finally {
      setAdmin(null);
      router.replace('/admin/login');
    }
  }, [router]);

  const updateAdmin = useCallback((changes: Partial<ApiUser>) => {
    setAdmin((current) => (current ? { ...current, ...changes } : current));
  }, []);

  if (!admin) {
    return (
      // Shaped like the admin (sidebar, top bar, page) so nothing jumps when it appears.
      <div className="flex min-h-dvh w-full bg-[#f8f8f8]">
        <div className="hidden w-[250px] shrink-0 flex-col gap-5 border-r border-[#e5e7eb] p-4 lg:flex" aria-hidden="true">
          <Skeleton className="h-7 w-32" />
          {Array.from({ length: 9 }, (_, i) => (
            <Skeleton key={i} className={`h-4 ${i % 3 === 0 ? 'w-20' : 'w-36'}`} />
          ))}
        </div>
        <div className="flex min-w-0 flex-1 flex-col bg-white">
          <div className="flex h-[52px] items-center justify-between border-b border-[#e5e7eb] px-4" aria-hidden="true">
            <Skeleton className="h-4 w-40" />
            <div className="flex gap-2">
              <Skeleton className="size-9 rounded-lg" />
              <Skeleton className="size-9 rounded-lg" />
              <Skeleton className="size-9 rounded-lg" />
            </div>
          </div>
          <main className="mx-auto w-full max-w-[1280px] flex-1 px-3 py-4 sm:px-5 sm:py-5">
            <LoadingState label="Checking your admin session…" variant="page" />
          </main>
        </div>
      </div>
    );
  }

  return <AdminSessionContext.Provider value={{ admin, updateAdmin, signOut }}>{children}</AdminSessionContext.Provider>;
}
