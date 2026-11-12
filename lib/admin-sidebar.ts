'use client';

import { useCallback, useSyncExternalStore } from 'react';

/*
 * Admin sidebar on wide screens: full (icons and names) or mini (a narrow strip of icons). The
 * choice is remembered on this browser; without storage it lasts for the visit.
 */
const SIDEBAR_KEY = 'shopco_admin_sidebar';
const SIDEBAR_EVENT = 'shopco-admin-sidebar';
let visitChoice: boolean | null = null;

function readMini(): boolean {
  if (visitChoice !== null) return visitChoice;
  try {
    return localStorage.getItem(SIDEBAR_KEY) === 'mini';
  } catch {
    return false; // storage blocked: start with the full sidebar
  }
}

function subscribe(onChange: () => void): () => void {
  window.addEventListener(SIDEBAR_EVENT, onChange);
  window.addEventListener('storage', onChange);
  return () => {
    window.removeEventListener(SIDEBAR_EVENT, onChange);
    window.removeEventListener('storage', onChange);
  };
}

/** [mini, setMini]: whether the desktop sidebar shows only icons. */
export function useAdminSidebarMini(): [boolean, (mini: boolean) => void] {
  const mini = useSyncExternalStore(subscribe, readMini, () => false);
  const setMini = useCallback((next: boolean) => {
    try {
      localStorage.setItem(SIDEBAR_KEY, next ? 'mini' : 'full');
      visitChoice = null;
    } catch {
      visitChoice = next; // storage blocked: the choice still holds for this visit
    }
    window.dispatchEvent(new Event(SIDEBAR_EVENT));
  }, []);
  return [mini, setMini];
}
