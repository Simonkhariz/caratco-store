'use client';

import React, { createContext, useContext } from 'react';
import type { CatalogFacets, PaymentMethodsResponse, ShippingZone, StoreOrigin } from '@/lib/api-client';

/**
 * Store-wide data the server already fetched for this page (categories and dress styles,
 * delivery zones and contact details, payment options). The store hooks in
 * lib/use-store-info.ts and lib/use-store-support.ts start from it instead of each making
 * their own request after the page has loaded. Any part can be null (API unavailable when
 * the page was built); the hooks then load it in the browser as before.
 */
export interface StoreSeed {
  facets: CatalogFacets | null;
  shipping: { zones: ShippingZone[]; origin: StoreOrigin | null } | null;
  payments: PaymentMethodsResponse | null;
  /** The storefront version when the page was built (GET /storefront/version). */
  version: string | null;
}

const StoreSeedContext = createContext<StoreSeed | null>(null);

export function StoreDataProvider({ seed, children }: { seed: StoreSeed; children: React.ReactNode }) {
  return <StoreSeedContext.Provider value={seed}>{children}</StoreSeedContext.Provider>;
}

export function useStoreSeed(): StoreSeed | null {
  return useContext(StoreSeedContext);
}
