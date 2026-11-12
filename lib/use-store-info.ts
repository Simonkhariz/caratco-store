'use client';

import { useEffect, useMemo, useState } from 'react';
import { ApiCategory, apiGetShippingZones, CatalogFacets, getCatalogFacets, ShippingZone, StoreOrigin } from '@/lib/api-client';
import { useStoreSeed } from '@/components/layout/StoreDataProvider';
import { normalizePhoneDigits } from '@/lib/whatsapp';
import { SHOP } from '@/lib/shop';

export type { StoreOrigin };

interface StoreInfo {
  zones: ShippingZone[];
  origin: StoreOrigin | null;
  loaded: boolean;
  error: string | null;
}

interface StoreNav {
  categories: ApiCategory[];
  dressStyles: string[];
  loaded: boolean;
}

let infoCache: Promise<StoreInfo> | null = null;
let navCache: Promise<StoreNav> | null = null;

function infoFrom(res: { zones?: ShippingZone[]; origin?: StoreOrigin | null } | null | undefined): StoreInfo {
  return { zones: res?.zones ?? [], origin: res?.origin ?? null, loaded: true, error: null };
}

function navFrom(f: CatalogFacets | null | undefined): StoreNav {
  return {
    categories: (f?.categories ?? []).filter((c) => (c.product_count ?? 0) > 0),
    // A shop with the style filter switched off has no styles to show anywhere (header menu,
    // phone menu, home page tiles), whatever its products still hold.
    dressStyles: SHOP.style.enabled ? (f?.dress_styles ?? []) : [],
    loaded: true,
  };
}

function loadInfo(): Promise<StoreInfo> {
  if (!infoCache) {
    infoCache = apiGetShippingZones()
      .then(infoFrom)
      .catch((err: Error) => {
        infoCache = null; // retry on the next mount
        return { zones: [], origin: null, loaded: true, error: err?.message || 'Could not load store details.' };
      });
  }
  return infoCache;
}

function loadNav(): Promise<StoreNav> {
  if (!navCache) {
    navCache = getCatalogFacets()
      .then(navFrom)
      .catch(() => {
        navCache = null;
        return { categories: [], dressStyles: [], loaded: true };
      });
  }
  return navCache;
}

/** Forget what was loaded in the browser (after an admin change), so the next use asks again. */
export function resetStoreInfoCaches(): void {
  infoCache = null;
  navCache = null;
}

/**
 * Live delivery zones and store contact details: with the page when the server fetched them
 * (always the newest copy the page has: a refreshed page brings a new one), otherwise loaded
 * once per page session and shared.
 */
export function useStoreInfo(): StoreInfo & { reload: () => void } {
  const seeded = useStoreSeed()?.shipping;
  const fromSeed = useMemo(() => (seeded ? infoFrom(seeded) : null), [seeded]);
  const [state, setState] = useState<StoreInfo>({ zones: [], origin: null, loaded: false, error: null });
  const [attempt, setAttempt] = useState(0);
  const useSeed = fromSeed !== null && attempt === 0;

  useEffect(() => {
    if (useSeed) {
      infoCache = Promise.resolve(fromSeed);
      return;
    }
    let active = true;
    loadInfo().then((value) => active && setState(value));
    return () => {
      active = false;
    };
  }, [useSeed, fromSeed, attempt]);

  return {
    ...(useSeed ? fromSeed : state),
    reload: () => {
      infoCache = null;
      setState((s) => ({ ...s, loaded: false, error: null }));
      setAttempt((n) => n + 1);
    },
  };
}

/** Categories that have published products, and the styles that exist in the catalogue (none when the shop has the style filter off). */
export function useStoreNav(): StoreNav {
  const seeded = useStoreSeed()?.facets;
  const fromSeed = useMemo(() => (seeded ? navFrom(seeded) : null), [seeded]);
  const [state, setState] = useState<StoreNav>({ categories: [], dressStyles: [], loaded: false });

  useEffect(() => {
    if (fromSeed) {
      navCache = Promise.resolve(fromSeed);
      return;
    }
    let active = true;
    loadNav().then((value) => active && setState(value));
    return () => {
      active = false;
    };
  }, [fromSeed]);

  return fromSeed ?? state;
}

/** wa.me link for a configured phone number, or null when none is set. */
export function whatsAppLink(phone: string | null | undefined, message?: string): string | null {
  const intl = normalizePhoneDigits(phone);
  if (!intl) return null;
  return `https://wa.me/${intl}${message ? `?text=${encodeURIComponent(message)}` : ''}`;
}
