'use client';

import React, { createContext, useContext, useEffect, useRef, useState } from 'react';
import { usePathname } from 'next/navigation';
import { Product } from '@/types';
import { useAuth } from '@/context/AuthContext';
import { ApiError, apiGetSavedWishlist, apiSaveWishlist, getProductDetail, mapApiProductToStorefront } from '@/lib/api-client';

interface WishlistContextType {
  wishlist: Product[];
  toggleWishlist: (product: Product) => void;
  isInWishlist: (productId: string) => boolean;
  removeFromWishlist: (productId: string) => void;
  clearWishlist: () => void;
  wishlistCount: number;
  isWishlistOpen: boolean;
  openWishlist: () => void;
  closeWishlist: () => void;
}

const WishlistContext = createContext<WishlistContextType | undefined>(undefined);

function readLocal(key: string): Product[] {
  try {
    const parsed = JSON.parse(localStorage.getItem(key) || '[]');
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function writeLocal(key: string, value: Product[] | null): void {
  try {
    if (value === null) localStorage.removeItem(key);
    else localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage unavailable: the wishlist still works for this visit.
  }
}

function readLocalFlag(key: string): boolean {
  try {
    return localStorage.getItem(key) === 'true';
  } catch {
    return false;
  }
}

function writeLocalFlag(key: string, on: boolean): void {
  try {
    if (on) localStorage.setItem(key, 'true');
    else localStorage.removeItem(key);
  } catch {
    // Storage unavailable: the wishlist still works for this visit.
  }
}

export const WishlistProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, isLoading: authLoading } = useAuth();
  const isCustomer = !!user && user.role === 'customer';
  const [wishlist, setWishlist] = useState<Product[]>([]);
  const wishlistRef = useRef<Product[]>([]);
  useEffect(() => {
    wishlistRef.current = wishlist;
  }, [wishlist]);
  const [isWishlistOpen, setIsWishlistOpen] = useState(false);
  // Going to another page puts the drawer away ("Buy now" or "View full details" from the quick
  // add opened over it would otherwise leave it covering the new page).
  const pathname = usePathname();
  const [openedOn, setOpenedOn] = useState(pathname);
  if (pathname !== openedOn) {
    setOpenedOn(pathname);
    setIsWishlistOpen(false);
  }
  // Which identity's wishlist is loaded (guest or a customer key); stays null until the first load.
  const [loadedKey, setLoadedKey] = useState<string | null>(null);
  // Hearts tapped before the list has loaded: applied again on top of it, or the load would undo them.
  const tappedWhileLoading = useRef<Product[]>([]);
  const serverReady = useRef(false);
  /** Counts wishlist changes, so a finished save knows whether a newer change is still unsaved. */
  const wishlistChange = useRef(0);

  const wishlistStorageKey = isCustomer ? `shopco_wishlist_user_${user!.id}` : 'shopco_wishlist_guest';
  const isHydrated = !authLoading && loadedKey === wishlistStorageKey;

  // Load: guests use this device; customers get their saved account wishlist merged with
  // anything saved as a guest here. Saved products come back with current prices. The account's
  // list is the truth: this device's copy only adds changes it had not managed to save yet,
  // otherwise it would bring back items removed on another device.
  useEffect(() => {
    if (authLoading) return;
    let cancelled = false;
    serverReady.current = false;

    (async () => {
      writeLocal('shop_co_wishlist', null); // legacy key from the mock build
      let next = readLocal(wishlistStorageKey);

      if (isCustomer) {
        const guest = readLocal('shopco_wishlist_guest');
        try {
          const saved = await apiGetSavedWishlist();
          const fresh = saved.products.map(mapApiProductToStorefront);
          const unsaved = readLocalFlag(`${wishlistStorageKey}_unsaved`);
          const byId = new Map<string, Product>();
          for (const p of [...(unsaved ? next : []), ...guest]) byId.set(p.id, p);
          for (const p of fresh) byId.set(p.id, p); // server copy has live prices
          next = Array.from(byId.values());
          writeLocal('shopco_wishlist_guest', null);
          serverReady.current = true;
        } catch {
          next = [...next, ...guest.filter((g) => !next.some((p) => p.id === g.id))];
        }
      }

      if (!cancelled) {
        for (const product of tappedWhileLoading.current) {
          next = next.some((p) => p.id === product.id) ? next.filter((p) => p.id !== product.id) : [...next, product];
        }
        tappedWhileLoading.current = [];
        setWishlist(next);
        setLoadedKey(wishlistStorageKey);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [authLoading, isCustomer, wishlistStorageKey]);

  useEffect(() => {
    if (!isHydrated) return;
    writeLocal(wishlistStorageKey, wishlist);

    if (!isCustomer) return;
    const unsavedKey = `${wishlistStorageKey}_unsaved`;
    writeLocalFlag(unsavedKey, true);
    const change = ++wishlistChange.current;
    if (!serverReady.current) return;
    const timer = setTimeout(() => {
      apiSaveWishlist(wishlist.map((p) => Number(p.id)).filter((id) => id > 0))
        .then(() => {
          // Only when nothing changed while this save was on its way.
          if (change === wishlistChange.current) writeLocalFlag(unsavedKey, false);
        })
        .catch(() => {
          // Saved locally; the next change retries.
        });
    }, 800);
    return () => clearTimeout(timer);
  }, [wishlist, isHydrated, isCustomer, wishlistStorageKey]);

  // A guest's list only exists on this device, so its prices are as old as the visit that saved
  // them. Opening the list brings them up to date, once per visit; what can't be fetched stays
  // as it is, and a product that no longer exists leaves the list.
  const refreshedGuestList = useRef(false);
  useEffect(() => {
    if (!isWishlistOpen || !isHydrated || isCustomer || refreshedGuestList.current) return;
    refreshedGuestList.current = true;
    wishlistRef.current.forEach((saved) => {
      getProductDetail(saved.slug || saved.id).then(
        (detail) => {
          const live = mapApiProductToStorefront(detail);
          setWishlist((prev) =>
            prev.map((p) =>
              p.id === saved.id
                ? { ...p, price: live.price, originalPrice: live.originalPrice, discountPercent: live.discountPercent, inStock: live.inStock, totalStock: live.totalStock }
                : p
            )
          );
        },
        (err) => {
          if (err instanceof ApiError && err.statusCode === 404) setWishlist((prev) => prev.filter((p) => p.id !== saved.id));
        }
      );
    });
  }, [isWishlistOpen, isHydrated, isCustomer]);

  const toggleWishlist = (product: Product) => {
    if (loadedKey === null) tappedWhileLoading.current.push(product);
    setWishlist((prev) =>
      prev.some((p) => p.id === product.id) ? prev.filter((p) => p.id !== product.id) : [...prev, product]
    );
  };

  const isInWishlist = (productId: string) => wishlist.some((p) => p.id === productId);

  const removeFromWishlist = (productId: string) => {
    setWishlist((prev) => prev.filter((p) => p.id !== productId));
  };

  const clearWishlist = () => setWishlist([]);

  return (
    <WishlistContext.Provider
      value={{
        wishlist,
        toggleWishlist,
        isInWishlist,
        removeFromWishlist,
        clearWishlist,
        wishlistCount: wishlist.length,
        isWishlistOpen,
        openWishlist: () => setIsWishlistOpen(true),
        closeWishlist: () => setIsWishlistOpen(false),
      }}
    >
      {children}
    </WishlistContext.Provider>
  );
};

export const useWishlist = (): WishlistContextType => {
  const context = useContext(WishlistContext);
  if (!context) {
    throw new Error('useWishlist must be used within a WishlistProvider');
  }
  return context;
};
