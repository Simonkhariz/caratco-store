'use client';

import { SHOP } from '@/lib/shop';
import React, { useEffect, useId, useRef, useState } from 'react';
import Link from 'next/link';
import Image from '@/components/ui/StoreImage';
import { usePathname, useRouter } from 'next/navigation';
import { useCart } from '@/context/CartContext';
import { useWishlist } from '@/context/WishlistContext';
import { useAuth } from '@/context/AuthContext';
import { Product } from '@/types';
import { getProducts, mapApiProductToStorefront } from '@/lib/api-client';
import { formatPrice } from '@/lib/currency';
import { useStoreNav } from '@/lib/use-store-info';
import { Dialog } from '@/components/ui/Dialog';
import { HugeiconsIcon } from '@hugeicons/react';
import {
  Search01Icon,
  ShoppingCart01Icon,
  UserCircleIcon,
  Menu01Icon,
  Cancel01Icon,
  ArrowDown01Icon,
  ArrowRight01Icon,
  FavouriteIcon,
} from '@hugeicons/core-free-icons';

/** Catalogue search URL (the catalogue reads ?q=). */
function searchUrl(query: string): string {
  return `/category?q=${encodeURIComponent(query.trim())}`;
}

function initials(name: string): string {
  return name
    .split(' ')
    .filter(Boolean)
    .map((n) => n[0])
    .slice(0, 2)
    .join('');
}

// =============================================================================
// Search with autocomplete (ARIA combobox)
// =============================================================================

interface SearchBoxProps {
  /** Called after navigating (e.g. to close the mobile search panel). */
  onNavigate?: () => void;
  autoFocus?: boolean;
  inputClassName?: string;
}

function SearchBox({ onNavigate, autoFocus = false, inputClassName = '' }: SearchBoxProps) {
  const router = useRouter();
  const listboxId = useId();
  const inputId = useId();
  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const term = query.trim();
  // The highlighted suggestion starts over whenever the text changes.
  const [active, setActive] = useState(-1);
  const [activeFor, setActiveFor] = useState(query);
  if (query !== activeFor) {
    setActiveFor(query);
    setActive(-1);
  }

  useEffect(() => {
    if (autoFocus) inputRef.current?.focus();
  }, [autoFocus]);

  // Debounced lookup; only the answer for the current text is used. While it loads, the
  // previous suggestions stay visible.
  const [answer, setAnswer] = useState<{ term: string; results: Product[]; failed: boolean } | null>(null);
  useEffect(() => {
    if (!term) return;
    let current = true;
    const timer = window.setTimeout(() => {
      getProducts({ search: term, per_page: 5 }).then(
        (res) => current && setAnswer({ term, results: res.items.map(mapApiProductToStorefront), failed: false }),
        () => current && setAnswer({ term, results: [], failed: true })
      );
    }, 250);
    return () => {
      current = false;
      window.clearTimeout(timer);
    };
  }, [term]);
  const loading = term !== '' && answer?.term !== term;
  const results = term ? (answer?.results ?? []) : [];
  const failed = term !== '' && !loading && Boolean(answer?.failed);

  useEffect(() => {
    const onDown = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

  const showPanel = open && term !== '';
  // Options: each product, then "view all results".
  const optionCount = results.length + (term ? 1 : 0);

  const finish = () => {
    setOpen(false);
    setQuery('');
    onNavigate?.();
  };

  const goToAll = () => {
    if (!term) return;
    router.push(searchUrl(term));
    finish();
  };

  const choose = (index: number) => {
    if (index >= 0 && index < results.length) {
      router.push(`/products/${results[index].slug}`);
      finish();
    } else {
      goToAll();
    }
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setOpen(true);
      if (optionCount > 0) setActive((i) => (i + 1) % optionCount);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setOpen(true);
      if (optionCount > 0) setActive((i) => (i <= 0 ? optionCount - 1 : i - 1));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (showPanel && active >= 0) choose(active);
      else goToAll();
    } else if (e.key === 'Escape') {
      if (showPanel) {
        e.preventDefault();
        e.stopPropagation();
        setOpen(false);
        setActive(-1);
      }
    }
  };

  const optionId = (i: number) => `${listboxId}-opt-${i}`;

  return (
    <div ref={containerRef} className="relative w-full">
      <form
        role="search"
        onSubmit={(e) => {
          e.preventDefault();
          goToAll();
        }}
        className="relative"
      >
        <label htmlFor={inputId} className="sr-only">
          Search products
        </label>
        <div className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-neutral-500">
          <HugeiconsIcon icon={Search01Icon} size={20} />
        </div>
        <input
          ref={inputRef}
          id={inputId}
          type="search"
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={showPanel}
          aria-controls={listboxId}
          aria-activedescendant={showPanel && active >= 0 ? optionId(active) : undefined}
          autoComplete="off"
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
          placeholder="Search for products..."
          className={`w-full rounded-full bg-surface py-3 pl-12 pr-4 text-sm text-black placeholder:text-neutral-500 transition-all focus:outline-none focus:ring-2 focus:ring-black/20 [&::-webkit-search-cancel-button]:hidden ${inputClassName}`}
        />
      </form>

      {showPanel && (
        <div className="animate-in fade-in absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-2xl border border-neutral-100 bg-white shadow-2xl">
          {loading && results.length === 0 ? (
            <div role="status" className="flex items-center justify-center gap-2 p-4 text-center text-xs text-neutral-600">
              <span className="h-3 w-3 animate-spin rounded-full border-2 border-accent border-t-transparent" aria-hidden="true" />
              Searching…
            </div>
          ) : failed ? (
            <p role="status" className="p-4 text-center text-sm text-neutral-600">
              Search is unavailable right now. Press Enter to search the catalogue.
            </p>
          ) : null}

          <ul id={listboxId} role="listbox" aria-label="Search suggestions" className="divide-y divide-neutral-100">
            {results.map((product, i) => (
              <li
                key={product.id}
                id={optionId(i)}
                role="option"
                aria-selected={active === i}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => choose(i)}
                onMouseEnter={() => setActive(i)}
                className={`flex cursor-pointer items-center gap-3 p-3 transition-colors ${active === i ? 'bg-neutral-100' : 'hover:bg-neutral-50'}`}
              >
                <div className="relative h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-neutral-100">
                  {product.images[0] && <Image src={product.images[0]} alt="" fill className="object-cover" sizes="48px" />}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-neutral-900">{product.name}</p>
                  <p className="text-xs capitalize text-neutral-600">
                    {product.categoryName || product.category} · {formatPrice(product.price)}
                  </p>
                </div>
                <HugeiconsIcon icon={ArrowRight01Icon} size={16} className="text-neutral-500" />
              </li>
            ))}
            {!loading && !failed && results.length === 0 && (
              <li role="presentation" className="p-4 text-center text-sm text-neutral-600">
                No products match &ldquo;{term}&rdquo;
              </li>
            )}
            <li
              id={optionId(results.length)}
              role="option"
              aria-selected={active === results.length}
              onMouseDown={(e) => e.preventDefault()}
              onClick={goToAll}
              onMouseEnter={() => setActive(results.length)}
              className={`cursor-pointer py-2.5 text-center text-xs font-semibold text-black transition-colors ${
                active === results.length ? 'bg-neutral-200' : 'bg-neutral-50 hover:bg-neutral-100'
              }`}
            >
              View all results for &ldquo;{term}&rdquo;
            </li>
          </ul>
        </div>
      )}
    </div>
  );
}

// =============================================================================
// Shop dropdown (click / keyboard, not hover-only)
// =============================================================================

function ShopMenu() {
  const { categories, dressStyles, loaded } = useStoreNav();
  const [open, setOpen] = useState(false);
  const panelId = useId();
  const wrapperRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);

  const focusFirstLink = () => window.requestAnimationFrame(() => panelRef.current?.querySelector<HTMLElement>('a')?.focus());

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false);
        buttonRef.current?.focus();
      }
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const onPanelKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    const links = Array.from(panelRef.current?.querySelectorAll<HTMLElement>('a') ?? []);
    const index = links.indexOf(document.activeElement as HTMLElement);
    if (links.length === 0) return;
    e.preventDefault();
    const next = e.key === 'ArrowDown' ? (index + 1) % links.length : index <= 0 ? links.length - 1 : index - 1;
    links[next].focus();
  };

  return (
    <div
      ref={wrapperRef}
      className="relative"
      onBlur={(e) => {
        if (!wrapperRef.current?.contains(e.relatedTarget as Node)) setOpen(false);
      }}
    >
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((o) => !o)}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            setOpen(true);
            focusFirstLink();
          }
        }}
        className="flex items-center gap-1 rounded-md py-2 transition-colors hover:text-black focus:outline-none focus-visible:ring-2 focus-visible:ring-black/30"
      >
        <span>Shop</span>
        <HugeiconsIcon icon={ArrowDown01Icon} size={16} className={`transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div
          ref={panelRef}
          id={panelId}
          onKeyDown={onPanelKeyDown}
          className="animate-in fade-in slide-in-from-top-2 absolute left-0 top-full z-50 grid w-80 grid-cols-2 gap-3 rounded-2xl border border-neutral-100 bg-white p-4 shadow-2xl"
        >
          <div>
            <p className="mb-2 px-2 text-xs font-bold uppercase tracking-wider text-neutral-600">Categories</p>
            <Link
              href="/category"
              onClick={() => setOpen(false)}
              className="block rounded-lg px-2 py-1.5 text-sm font-medium text-neutral-900 transition-colors hover:bg-neutral-50"
            >
              All products
            </Link>
            {!loaded && <p className="px-2 py-1.5 text-xs text-neutral-600">Loading…</p>}
            {categories.map((cat) => (
              <Link
                key={cat.slug}
                href={`/category?cat=${encodeURIComponent(cat.slug)}`}
                onClick={() => setOpen(false)}
                className="block rounded-lg px-2 py-1.5 text-sm text-neutral-800 transition-colors hover:bg-neutral-50"
              >
                {cat.name}
              </Link>
            ))}
          </div>

          {dressStyles.length > 0 && (
            <div>
              <p className="mb-2 px-2 text-xs font-bold uppercase tracking-wider text-neutral-600">{SHOP.style.plural}</p>
              {dressStyles.map((style) => (
                <Link
                  key={style}
                  href={`/category?style=${encodeURIComponent(style.toLowerCase())}`}
                  onClick={() => setOpen(false)}
                  className="block rounded-lg px-2 py-1.5 text-sm capitalize text-neutral-800 transition-colors hover:bg-neutral-50"
                >
                  {style}
                </Link>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// =============================================================================
// Header
// =============================================================================

export const Header: React.FC = () => {
  const router = useRouter();
  const pathname = usePathname();
  const { totalQuantity } = useCart();
  const { wishlistCount, openWishlist } = useWishlist();
  const { user, isAdmin, logout, openAuthModal } = useAuth();
  const { categories, dressStyles } = useStoreNav();

  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [isMobileSearchOpen, setIsMobileSearchOpen] = useState(false);
  const [isProfileMenuOpen, setIsProfileMenuOpen] = useState(false);
  const profileRef = useRef<HTMLDivElement>(null);
  const profileButtonRef = useRef<HTMLButtonElement>(null);
  const profileMenuId = useId();

  // Close transient menus on navigation.
  const [menusFor, setMenusFor] = useState(pathname);
  if (pathname !== menusFor) {
    setMenusFor(pathname);
    setIsMobileMenuOpen(false);
    setIsMobileSearchOpen(false);
    setIsProfileMenuOpen(false);
  }

  useEffect(() => {
    router.prefetch('/cart');
    router.prefetch('/category');
  }, [router]);

  useEffect(() => {
    if (!isProfileMenuOpen) return;
    const onDown = (e: MouseEvent) => {
      if (profileRef.current && !profileRef.current.contains(e.target as Node)) setIsProfileMenuOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setIsProfileMenuOpen(false);
        profileButtonRef.current?.focus();
      }
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [isProfileMenuOpen]);

  const closeMobile = () => setIsMobileMenuOpen(false);
  const mobileLink = 'block py-1.5 text-sm text-neutral-800 hover:text-black';

  return (
    <header className="sticky top-0 z-40 border-b border-neutral-100 bg-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="flex h-16 items-center justify-between gap-4 sm:h-20">
          {/* Left: mobile menu toggle & logo */}
          <div className="flex items-center gap-4">
            <button
              type="button"
              onClick={() => setIsMobileMenuOpen(true)}
              className="p-1 text-black hover:opacity-75 lg:hidden"
              aria-label="Open menu"
              aria-haspopup="dialog"
            >
              <HugeiconsIcon icon={Menu01Icon} size={24} />
            </button>

            <Link href="/" prefetch={true} className="font-integral text-2xl tracking-tighter text-black sm:text-3xl lg:text-4xl">
              {SHOP.name}
            </Link>
          </div>

          {/* Desktop navigation */}
          <nav aria-label="Main" className="hidden items-center gap-6 text-sm font-medium text-neutral-700 lg:flex">
            <ShopMenu />
            <Link href="/category?onSale=true" className="transition-colors hover:text-black">
              On Sale
            </Link>
            <Link href="/category?sort=newest" className="transition-colors hover:text-black">
              New Arrivals
            </Link>
            <Link href="/track-order" className="font-medium text-emerald-800 transition-colors hover:text-black">
              Track Order
            </Link>
          </nav>

          {/* Desktop search */}
          <div className="hidden max-w-md flex-1 md:block">
            <SearchBox />
          </div>

          {/* Right: icons */}
          <div className="flex items-center gap-2 sm:gap-4">
            <button
              type="button"
              onClick={() => setIsMobileSearchOpen((o) => !o)}
              className="p-2 text-black transition-opacity hover:opacity-75 md:hidden"
              aria-label={isMobileSearchOpen ? 'Close search' : 'Search products'}
              aria-expanded={isMobileSearchOpen}
              aria-controls="mobile-search"
            >
              <HugeiconsIcon icon={isMobileSearchOpen ? Cancel01Icon : Search01Icon} size={24} />
            </button>

            <button
              type="button"
              onClick={openWishlist}
              className="relative p-2 text-black transition-opacity hover:opacity-75"
              aria-label={wishlistCount > 0 ? `Wishlist, ${wishlistCount} ${wishlistCount === 1 ? 'item' : 'items'}` : 'Wishlist'}
              aria-haspopup="dialog"
            >
              <HugeiconsIcon icon={FavouriteIcon} size={24} />
              {wishlistCount > 0 && (
                <span
                  aria-hidden="true"
                  className="animate-in zoom-in absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full border-2 border-white bg-red-600 px-1 text-xs font-bold text-white"
                >
                  {wishlistCount}
                </span>
              )}
            </button>

            <Link
              href="/cart"
              prefetch={true}
              className="relative p-2 text-black transition-opacity hover:opacity-75"
              aria-label={totalQuantity > 0 ? `Cart, ${totalQuantity} ${totalQuantity === 1 ? 'item' : 'items'}` : 'Cart'}
            >
              <HugeiconsIcon icon={ShoppingCart01Icon} size={24} />
              {totalQuantity > 0 && (
                <span
                  aria-hidden="true"
                  className="animate-in zoom-in absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full border-2 border-white bg-accent px-1 text-xs font-bold text-white"
                >
                  {totalQuantity}
                </span>
              )}
            </Link>

            {/* Account */}
            <div ref={profileRef} className="relative">
              <button
                ref={profileButtonRef}
                type="button"
                onClick={() => (user ? setIsProfileMenuOpen((o) => !o) : openAuthModal('login'))}
                className="rounded-full p-2 text-black transition-opacity hover:opacity-75 focus:outline-none focus-visible:ring-2 focus-visible:ring-black/30"
                aria-label={user ? `Account menu for ${user.full_name}` : 'Sign in'}
                aria-expanded={user ? isProfileMenuOpen : undefined}
                aria-controls={user ? profileMenuId : undefined}
              >
                {user ? (
                  <span className="flex h-8 w-8 items-center justify-center rounded-full bg-accent text-xs font-bold uppercase text-white shadow-sm">
                    {initials(user.full_name)}
                  </span>
                ) : (
                  <HugeiconsIcon icon={UserCircleIcon} size={24} />
                )}
              </button>

              {isProfileMenuOpen && user && (
                <div
                  id={profileMenuId}
                  className="animate-in fade-in zoom-in-95 absolute right-0 z-50 mt-2 w-64 rounded-2xl border border-neutral-100 bg-white py-3 shadow-xl"
                >
                  <div className="border-b border-neutral-100 px-4 py-2">
                    <p className="truncate text-sm font-semibold text-neutral-900">{user.full_name}</p>
                    <p className="truncate text-xs text-neutral-600">{user.email}</p>
                  </div>
                  <div className="py-1">
                    {isAdmin && (
                      <Link
                        href="/admin"
                        prefetch={false}
                        onClick={() => setIsProfileMenuOpen(false)}
                        className="block px-4 py-2.5 text-sm font-medium text-neutral-800 transition-colors hover:bg-neutral-50"
                      >
                        Admin dashboard
                      </Link>
                    )}
                    <Link
                      href="/orders"
                      onClick={() => setIsProfileMenuOpen(false)}
                      className="block px-4 py-2.5 text-sm font-medium text-neutral-800 transition-colors hover:bg-neutral-50"
                    >
                      My orders
                    </Link>
                    <button
                      type="button"
                      onClick={() => {
                        setIsProfileMenuOpen(false);
                        openWishlist();
                      }}
                      className="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm text-neutral-800 transition-colors hover:bg-neutral-50"
                    >
                      <span>Wishlist</span>
                      {wishlistCount > 0 && <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{wishlistCount}</span>}
                    </button>
                    <button
                      type="button"
                      onClick={async () => {
                        setIsProfileMenuOpen(false);
                        await logout();
                      }}
                      className="w-full px-4 py-2.5 text-left text-sm font-medium text-red-700 transition-colors hover:bg-red-50"
                    >
                      Sign out
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Mobile search panel */}
      {isMobileSearchOpen && (
        <div id="mobile-search" className="animate-in fade-in slide-in-from-top-2 border-t border-neutral-100 px-4 py-3 md:hidden">
          <SearchBox autoFocus onNavigate={() => setIsMobileSearchOpen(false)} />
        </div>
      )}

      {/* Mobile navigation drawer */}
      <Dialog
        open={isMobileMenuOpen}
        onClose={closeMobile}
        title="Menu"
        placement="left"
        panelClassName="animate-in slide-in-from-left flex h-full w-full max-w-xs flex-col overflow-hidden bg-white shadow-2xl"
      >
        <div className="flex shrink-0 items-center justify-between border-b border-neutral-100 py-3 pl-6 pr-4">
          <Link href="/" onClick={closeMobile} className="font-integral text-2xl tracking-tighter text-black">
            {SHOP.name}
          </Link>
          <button type="button" onClick={closeMobile} className="rounded-full p-2 text-neutral-600 hover:text-black" aria-label="Close menu">
            <HugeiconsIcon icon={Cancel01Icon} size={20} />
          </button>
        </div>

        <div className="flex min-h-0 flex-1 flex-col overflow-y-auto p-6">
        <nav aria-label="Mobile" className="flex-1 space-y-5">
          <div className="space-y-1">
            <Link href="/category" onClick={closeMobile} className="block py-1.5 text-base font-semibold text-neutral-900">
              All products
            </Link>
            <Link href="/category?onSale=true" onClick={closeMobile} className="block py-1.5 text-sm font-semibold text-red-700">
              On sale
            </Link>
            <Link href="/category?sort=newest" onClick={closeMobile} className="block py-1.5 text-sm font-semibold text-neutral-900">
              New arrivals
            </Link>
          </div>

          {categories.length > 0 && (
            <div className="border-t border-neutral-100 pt-3">
              <p className="mb-1 text-xs font-bold uppercase tracking-wider text-neutral-600">Categories</p>
              {categories.map((c) => (
                <Link key={c.slug} href={`/category?cat=${encodeURIComponent(c.slug)}`} onClick={closeMobile} className={mobileLink}>
                  {c.name}
                </Link>
              ))}
            </div>
          )}

          {dressStyles.length > 0 && (
            <div className="border-t border-neutral-100 pt-3">
              <p className="mb-1 text-xs font-bold uppercase tracking-wider text-neutral-600">{SHOP.style.plural}</p>
              {dressStyles.map((s) => (
                <Link key={s} href={`/category?style=${encodeURIComponent(s.toLowerCase())}`} onClick={closeMobile} className={`${mobileLink} capitalize`}>
                  {s}
                </Link>
              ))}
            </div>
          )}

          <div className="border-t border-neutral-100 pt-3">
            <p className="mb-1 text-xs font-bold uppercase tracking-wider text-neutral-600">Your account</p>
            {user ? (
              <p className="mb-1 truncate text-xs text-neutral-600">Signed in as {user.email}</p>
            ) : (
              <button
                type="button"
                onClick={() => {
                  closeMobile();
                  openAuthModal('login');
                }}
                className="block py-1.5 text-left text-sm font-semibold text-neutral-900"
              >
                Sign in
              </button>
            )}
            <Link href="/orders" onClick={closeMobile} className={mobileLink}>
              My orders
            </Link>
            <Link href="/track-order" onClick={closeMobile} className={mobileLink}>
              Track an order
            </Link>
            <Link href="/cart" onClick={closeMobile} className={`${mobileLink} flex items-center justify-between`}>
              <span>Cart</span>
              {totalQuantity > 0 && <span className="rounded-full bg-accent px-2 py-0.5 text-xs font-bold text-white">{totalQuantity}</span>}
            </Link>
            <button
              type="button"
              onClick={() => {
                closeMobile();
                openWishlist();
              }}
              className={`${mobileLink} flex w-full items-center justify-between text-left`}
            >
              <span>Wishlist</span>
              {wishlistCount > 0 && <span className="rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">{wishlistCount}</span>}
            </button>
            {isAdmin && (
              <Link href="/admin" prefetch={false} onClick={closeMobile} className={`${mobileLink} font-semibold`}>
                Admin dashboard
              </Link>
            )}
            {user && (
              <button
                type="button"
                onClick={async () => {
                  closeMobile();
                  await logout();
                }}
                className="block py-1.5 text-left text-sm font-semibold text-red-700"
              >
                Sign out
              </button>
            )}
          </div>
        </nav>

        <p className="mt-6 border-t border-neutral-100 pt-4 text-xs text-neutral-600">© {new Date().getFullYear()} {SHOP.name}</p>
        </div>
      </Dialog>
    </header>
  );
};
