'use client';

import { useEffect, useSyncExternalStore } from 'react';
import { ADMIN_THEME_KEY } from '@/lib/admin-theme-boot';

/*
 * Admin light/dark theme: the admin's choice on this browser, else the device setting. Dark sets
 * html.admin-dark (app/admin/admin-dark.css) only while an admin screen is open, so the
 * storefront is never affected. Applied by the admin layout (sign-in, session check and every
 * screen); the switch itself is in the admin header.
 */
const THEME_EVENT = 'shopco-admin-theme';
// The choice for this visit when browser storage is blocked (private mode, strict settings).
let visitTheme: 'light' | 'dark' | null = null;

function readTheme(): 'light' | 'dark' {
  if (visitTheme) return visitTheme;
  try {
    const saved = localStorage.getItem(ADMIN_THEME_KEY);
    if (saved === 'light' || saved === 'dark') return saved;
  } catch {
    // storage blocked: follow the device
  }
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function subscribeTheme(onChange: () => void): () => void {
  const media = window.matchMedia?.('(prefers-color-scheme: dark)');
  window.addEventListener(THEME_EVENT, onChange);
  window.addEventListener('storage', onChange);
  media?.addEventListener?.('change', onChange);
  return () => {
    window.removeEventListener(THEME_EVENT, onChange);
    window.removeEventListener('storage', onChange);
    media?.removeEventListener?.('change', onChange);
  };
}

/** The current admin theme, following changes from the switch, other tabs and the device. */
export function useAdminThemeValue(): 'light' | 'dark' {
  return useSyncExternalStore(subscribeTheme, readTheme, () => 'light' as const);
}

/** Keeps html.admin-dark in step with the theme while mounted (removed on leaving the admin). */
export function AdminThemeClass() {
  const theme = useAdminThemeValue();
  useEffect(() => {
    document.documentElement.classList.toggle('admin-dark', theme === 'dark');
  }, [theme]);
  useEffect(() => () => document.documentElement.classList.remove('admin-dark'), []);
  return null;
}

/** The theme and a toggle for the header switch. */
export function useAdminTheme(): ['light' | 'dark', () => void] {
  const theme = useAdminThemeValue();
  const toggle = () => {
    const next = theme === 'dark' ? 'light' : 'dark';
    try {
      localStorage.setItem(ADMIN_THEME_KEY, next);
      visitTheme = null;
    } catch {
      // Not saved: still switches for this visit.
      visitTheme = next;
    }
    window.dispatchEvent(new Event(THEME_EVENT));
  };
  return [theme, toggle];
}
