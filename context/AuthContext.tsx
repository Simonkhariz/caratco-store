'use client';

import React, { createContext, useContext, useState, useEffect, useCallback, useRef } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { forgetOrdersOnThisDevice } from '@/lib/pending-order';
import {
  ApiError,
  ApiUser,
  ApiAddress,
  onCustomerSessionEvent,
  apiPeekSession,
  apiLogin,
  apiRegister,
  apiLogout,
  apiRequestMagicLink,
  apiVerifyMagicLink,
  apiGetAddresses,
  apiCreateAddress,
  apiDeleteAddress,
  apiSetDefaultAddress,
  apiUpdateProfile,
} from '@/lib/api-client';

interface AuthContextType {
  user: ApiUser | null;
  isLoading: boolean;
  isAdmin: boolean;
  addresses: ApiAddress[];
  isAuthModalOpen: boolean;
  authModalTab: 'login' | 'register' | 'magic';
  /** Why the sign-in window opened by itself (the session ended). */
  authNotice: string | null;
  openAuthModal: (tab?: 'login' | 'register' | 'magic') => void;
  closeAuthModal: () => void;
  login: (email: string, password: string) => Promise<void>;
  register: (data: { full_name: string; email: string; password: string; phone?: string }) => Promise<void>;
  requestMagicLink: (email: string) => Promise<{ message: string }>;
  /** Resolves true when a password set before the email was confirmed was removed. */
  verifyMagicLink: (token: string) => Promise<boolean>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
  loadAddresses: () => Promise<void>;
  addAddress: (data: Partial<ApiAddress>) => Promise<ApiAddress>;
  deleteAddress: (id: number) => Promise<void>;
  setDefaultAddress: (id: number) => Promise<void>;
  updateProfile: (data: { full_name?: string; phone?: string }) => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

/** Tells this browser's other tabs that someone signed in or out, so they don't look stale. */
const SESSION_SIGNAL_KEY = 'shopco_session_changed';
function signalOtherTabs(kind: 'in' | 'out'): void {
  try {
    localStorage.setItem(SESSION_SIGNAL_KEY, `${Date.now()}:${kind}`);
  } catch {
    // Storage unavailable: the other tabs catch up when next focused.
  }
}

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const router = useRouter();
  // The admin area has its own sign-in check (components/admin/AdminAuthGate.tsx). The shopper's is
  // not asked there, which leaves isLoading true, so the cart and wishlist don't load there either.
  const inAdminArea = usePathname()?.startsWith('/admin') ?? false;
  const [user, setUser] = useState<ApiUser | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [addresses, setAddresses] = useState<ApiAddress[]>([]);
  const [isAuthModalOpen, setIsAuthModalOpen] = useState<boolean>(false);
  const [authModalTab, setAuthModalTab] = useState<'login' | 'register' | 'magic'>('login');
  const [authNotice, setAuthNotice] = useState<string | null>(null);
  // Counts sign-ins and sign-outs, so a /auth/me answer from before one is ignored.
  const sessionChanges = useRef(0);
  const signedInRef = useRef(false);
  useEffect(() => {
    signedInRef.current = user !== null;
  }, [user]);

  const loadAddresses = useCallback(async () => {
    try {
      const data = await apiGetAddresses();
      setAddresses(data);
    } catch {
      setAddresses([]);
    }
  }, []);

  // State changes only once the server has answered (never synchronously).
  const sessionEnded = useCallback(() => {
    sessionChanges.current += 1;
    setUser(null);
    setAddresses([]);
    setAuthNotice('Your session has ended. Please sign in again.');
    setAuthModalTab('login');
    setIsAuthModalOpen(true);
  }, []);

  const refreshUser = useCallback(() => {
    const startedAt = sessionChanges.current;
    return apiPeekSession()
      .then(
        (res) => {
          if (startedAt !== sessionChanges.current) return;
          // Nobody is signed in: normal for a guest; for someone who was, the session ended.
          if (!res.user && signedInRef.current) {
            sessionEnded();
            return;
          }
          // The same answer keeps the same object, so nothing that follows the user reloads.
          setUser((prev) => (JSON.stringify(prev) === JSON.stringify(res.user) ? prev : res.user));
          if (res.user) loadAddresses();
        },
        (err) => {
          if (startedAt !== sessionChanges.current) return;
          if (signedInRef.current && err instanceof ApiError && err.statusCode === 401) {
            sessionEnded();
            return;
          }
          // A network blip while signed in isn't a sign-out.
          if (signedInRef.current) return;
          setUser(null);
          setAddresses([]);
        }
      )
      .finally(() => setIsLoading(false));
  }, [loadAddresses, sessionEnded]);

  // Initial session hydration from HttpOnly cookie
  useEffect(() => {
    if (inAdminArea) return;
    refreshUser();
  }, [inAdminArea, refreshUser]);

  // Another tab signed in or out, or this tab comes back after a while: catch up.
  useEffect(() => {
    if (inAdminArea) return;
    let lastCheck = Date.now();
    const onStorage = (event: StorageEvent) => {
      if (event.key !== SESSION_SIGNAL_KEY || !event.newValue) return;
      lastCheck = Date.now();
      if (event.newValue.endsWith(':out')) {
        sessionChanges.current += 1;
        forgetOrdersOnThisDevice();
        setUser(null);
        setAddresses([]);
        return;
      }
      void refreshUser();
    };
    const onVisible = () => {
      if (document.visibilityState !== 'visible' || Date.now() - lastCheck < 30000) return;
      lastCheck = Date.now();
      void refreshUser();
    };
    window.addEventListener('storage', onStorage);
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);
    return () => {
      window.removeEventListener('storage', onStorage);
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('focus', onVisible);
    };
  }, [inAdminArea, refreshUser]);

  // What other requests find out about the session: it ended, or another tab of this browser
  // signed in (the session here is then that one).
  useEffect(
    () =>
      onCustomerSessionEvent({
        expired: () => {
          if (signedInRef.current) sessionEnded();
        },
        signedInElsewhere: (nextUser) => {
          sessionChanges.current += 1;
          setUser(nextUser);
          loadAddresses();
        },
      }),
    [sessionEnded, loadAddresses]
  );

  const openAuthModal = (tab: 'login' | 'register' | 'magic' = 'login') => {
    setAuthNotice(null);
    setAuthModalTab(tab);
    setIsAuthModalOpen(true);
  };

  const closeAuthModal = () => {
    setAuthNotice(null);
    setIsAuthModalOpen(false);
  };

  const login = async (email: string, password: string) => {
    const res = await apiLogin({ email, password });
    sessionChanges.current += 1;
    setAuthNotice(null);
    setIsAuthModalOpen(false);

    // Admin accounts get an admin-scoped session cookie and belong in the admin dashboard,
    // not the storefront customer session.
    if (res.user?.role === 'admin' || res.user?.role === 'super_admin') {
      router.push('/admin');
      return;
    }

    // The cart and wishlist providers merge this device's guest cart/wishlist into the
    // account automatically when the signed-in user changes.
    setUser(res.user);
    loadAddresses();
    signalOtherTabs('in');
  };

  const register = async (data: { full_name: string; email: string; password: string; phone?: string }) => {
    const res = await apiRegister(data);
    sessionChanges.current += 1;
    setUser(res.user);
    setIsAuthModalOpen(false);
    loadAddresses();
    signalOtherTabs('in');
  };

  const requestMagicLink = async (email: string) => {
    return apiRequestMagicLink(email);
  };

  const verifyMagicLink = async (token: string) => {
    const res = await apiVerifyMagicLink(token);
    sessionChanges.current += 1;
    setUser(res.user);
    setIsAuthModalOpen(false);
    loadAddresses();
    signalOtherTabs('in');
    return res.password_removed === true;
  };

  const logout = async () => {
    try {
      await apiLogout();
    } finally {
      sessionChanges.current += 1;
      forgetOrdersOnThisDevice();
      setUser(null);
      setAddresses([]);
      signalOtherTabs('out');
    }
  };

  const addAddress = async (data: Partial<ApiAddress>) => {
    const created = await apiCreateAddress(data);
    await loadAddresses();
    return created;
  };

  const deleteAddress = async (id: number) => {
    await apiDeleteAddress(id);
    await loadAddresses();
  };

  const setDefaultAddress = async (id: number) => {
    await apiSetDefaultAddress(id);
    await loadAddresses();
  };

  const updateProfile = async (data: { full_name?: string; phone?: string }) => {
    const updated = await apiUpdateProfile(data);
    setUser(updated);
  };

  const isAdmin = user?.role === 'admin' || user?.role === 'super_admin';

  return (
    <AuthContext.Provider
      value={{
        user,
        isLoading,
        isAdmin,
        addresses,
        isAuthModalOpen,
        authModalTab,
        authNotice,
        openAuthModal,
        closeAuthModal,
        login,
        register,
        requestMagicLink,
        verifyMagicLink,
        logout,
        refreshUser,
        loadAddresses,
        addAddress,
        deleteAddress,
        setDefaultAddress,
        updateProfile,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};
