'use client';

import { useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { useQueryClient } from '@tanstack/react-query';
import { apiGetStorefrontVersion, clearApiCache } from '@/lib/api-client';
import { useStoreSeed } from '@/components/layout/StoreDataProvider';
import { clearProductDetailCache } from '@/lib/product-detail-cache';
import { resetStoreInfoCaches } from '@/lib/use-store-info';
import { resetStoreSupportCache } from '@/lib/use-store-support';

/**
 * How often an open, visible page asks whether the owner changed anything. Each ask is a tiny,
 * database-free answer, so 50 shoppers with the shop open cost about 10 small requests a second.
 */
const CHECK_EVERY_MS = 5_000;

/**
 * Keeps an open storefront page up to date with the owner's changes, without the shopper
 * refreshing. Every 5 seconds while the page is visible, and at once when the shopper comes back
 * to the tab, goes online again or returns with the Back button, it asks the API for the
 * storefront version (a tiny, uncached answer that changes after every admin change). When it
 * has moved, the page's server data is fetched again in place (router.refresh: what the shopper
 * typed, scrolled to or has in the cart stays) and every browser-side copy is dropped.
 *
 * Why not a live stream (SSE): on shared hosting each open stream holds one of the account's few
 * PHP processes (20 on this plan) for as long as the page is open, so a handful of shoppers would
 * use them up. A check every 5 seconds costs one small request and nothing in between.
 */
export function StoreLiveUpdates() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const seedVersion = useStoreSeed()?.version ?? null;
  // The version the page on screen was built with; a refresh brings the newer one in the seed.
  const known = useRef<string | null>(seedVersion);
  useEffect(() => {
    if (seedVersion) known.current = seedVersion;
  }, [seedVersion]);

  useEffect(() => {
    let checking = false;
    let controller: AbortController | null = null;

    const check = async () => {
      if (checking || document.visibilityState !== 'visible' || !navigator.onLine) return;
      checking = true;
      controller = new AbortController();
      try {
        const latest = await apiGetStorefrontVersion(controller.signal);
        if (!latest) return;
        // A page built without a version (the API was busy then) can't tell whether it is current,
        // and may be missing data too: refresh it once to be sure.
        if (latest !== known.current) {
          known.current = latest;
          clearApiCache();
          clearProductDetailCache();
          resetStoreInfoCaches();
          resetStoreSupportCache();
          void queryClient.invalidateQueries();
          router.refresh();
        }
      } catch {
        // Offline or the API is busy: the next check tries again.
      } finally {
        checking = false;
      }
    };

    const onReturn = () => {
      if (document.visibilityState === 'visible') void check();
    };
    // A page restored by Back/Forward is a snapshot from earlier: check it straight away.
    const onPageShow = (e: PageTransitionEvent) => {
      if (e.persisted) void check();
    };

    const timer = window.setInterval(() => void check(), CHECK_EVERY_MS);
    document.addEventListener('visibilitychange', onReturn);
    window.addEventListener('focus', onReturn);
    window.addEventListener('online', onReturn);
    window.addEventListener('pageshow', onPageShow);
    return () => {
      window.clearInterval(timer);
      controller?.abort();
      document.removeEventListener('visibilitychange', onReturn);
      window.removeEventListener('focus', onReturn);
      window.removeEventListener('online', onReturn);
      window.removeEventListener('pageshow', onPageShow);
    };
  }, [router, queryClient]);

  return null;
}
