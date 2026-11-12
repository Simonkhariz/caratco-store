'use client';

import React, { useEffect, useId, useRef, useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { Search } from 'lucide-react';
import { adminGetCustomers, adminGetOrders } from '@/lib/admin-api';
import { apiGetAdminProducts } from '@/lib/api-client';
import { formatPrice } from '@/lib/currency';
import { orderStatus } from '@/lib/admin-format';

/** One place a search result leads to. */
interface Hit {
  group: 'Orders' | 'Customers' | 'Products';
  href: string;
  title: string;
  detail: string;
}

const PER_GROUP = 4;
const MIN_LETTERS = 2;

/**
 * Search from anywhere in the admin: an order number, a customer's name or email, a product name
 * or SKU. Results are grouped; choosing one opens that order, or the customers / products list
 * already narrowed to it. Enter with nothing highlighted searches the orders list.
 */
export function AdminHeaderSearch() {
  const router = useRouter();
  const pathname = usePathname();
  const listId = useId();
  const boxRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const [text, setText] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  // The answer for one question; shown only while it still matches what is typed.
  const [answer, setAnswer] = useState<{ query: string; hits: Hit[]; failed: boolean } | null>(null);

  const query = text.trim();
  const ready = query.length >= MIN_LETTERS;
  const current = ready && answer?.query === query ? answer : null;
  const hits = current?.hits ?? [];

  // Moving to another screen puts the results away.
  const [shownOn, setShownOn] = useState(pathname);
  if (pathname !== shownOn) {
    setShownOn(pathname);
    setOpen(false);
    setText('');
    setActive(-1);
  }

  useEffect(() => {
    if (!ready) return;
    let stale = false;
    const timer = setTimeout(async () => {
      const [orders, customers, products] = await Promise.allSettled([
        adminGetOrders({ search: query, per_page: PER_GROUP }),
        adminGetCustomers({ search: query, per_page: PER_GROUP }),
        apiGetAdminProducts({ search: query, per_page: PER_GROUP }),
      ]);
      if (stale) return;
      const found: Hit[] = [];
      if (orders.status === 'fulfilled') {
        for (const o of orders.value.items.slice(0, PER_GROUP)) {
          found.push({
            group: 'Orders',
            href: `/admin/orders/${encodeURIComponent(o.order_number)}`,
            title: o.order_number,
            detail: `${orderStatus(o.order_status).label} · ${formatPrice(Number(o.grand_total))}${o.customer_email || o.guest_email ? ` · ${o.customer_email || o.guest_email}` : ''}`,
          });
        }
      }
      if (customers.status === 'fulfilled') {
        for (const c of customers.value.items.slice(0, PER_GROUP)) {
          found.push({ group: 'Customers', href: `/admin/customers?search=${encodeURIComponent(c.email)}`, title: c.full_name || c.email, detail: c.email });
        }
      }
      if (products.status === 'fulfilled') {
        for (const p of products.value.items.slice(0, PER_GROUP)) {
          found.push({ group: 'Products', href: `/admin/products?search=${encodeURIComponent(p.sku)}`, title: p.name, detail: `${p.sku} · ${formatPrice(Number(p.base_price))}` });
        }
      }
      const failed = orders.status === 'rejected' && customers.status === 'rejected' && products.status === 'rejected';
      setAnswer({ query, hits: found, failed });
      setActive(-1);
    }, 250);
    return () => {
      stale = true;
      clearTimeout(timer);
    };
  }, [query, ready]);

  // A click anywhere else closes the results; "/" or Ctrl+K puts the cursor in the box.
  useEffect(() => {
    const onDown = (e: PointerEvent) => {
      if (!boxRef.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      const typing = (e.target as HTMLElement | null)?.closest('input, textarea, select, [contenteditable="true"]');
      if ((e.key === '/' && !typing) || (e.key.toLowerCase() === 'k' && (e.ctrlKey || e.metaKey))) {
        e.preventDefault();
        inputRef.current?.focus();
        inputRef.current?.select();
      }
    };
    document.addEventListener('pointerdown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, []);

  const onInputKey = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Escape') {
      setOpen(false);
      inputRef.current?.blur();
    } else if (e.key === 'ArrowDown' && hits.length > 0) {
      e.preventDefault();
      setOpen(true);
      setActive((i) => (i + 1) % hits.length);
    } else if (e.key === 'ArrowUp' && hits.length > 0) {
      e.preventDefault();
      setActive((i) => (i <= 0 ? hits.length - 1 : i - 1));
    } else if (e.key === 'Enter' && ready) {
      e.preventDefault();
      router.push(active >= 0 && hits[active] ? hits[active].href : `/admin/orders?search=${encodeURIComponent(query)}`);
    }
  };

  const showPanel = open && ready;
  let lastGroup = '';

  return (
    <div ref={boxRef} className="relative hidden w-56 md:block lg:w-72">
      <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" aria-hidden="true" />
      <input
        ref={inputRef}
        type="search"
        role="combobox"
        aria-expanded={showPanel}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={active >= 0 ? `${listId}-${active}` : undefined}
        aria-label="Search orders, customers and products"
        placeholder="Search orders, customers, products"
        value={text}
        onChange={(e) => {
          setText(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={onInputKey}
        className="h-9 w-full rounded-lg border border-[#e5e7eb] bg-white pl-8 pr-2.5 text-[13px] text-[#111827] outline-none transition-[border-color,box-shadow] placeholder:text-[#9ca3af] focus:border-[#6b7280] focus:shadow-[0_0_0_3px_rgba(156,163,175,0.25)] [&::-webkit-search-cancel-button]:hidden"
      />

      {showPanel && (
        <div
          id={listId}
          role="listbox"
          aria-label="Search results"
          className="absolute left-0 top-full z-[60] mt-2 max-h-[70vh] w-[min(24rem,calc(100vw-1.5rem))] overflow-y-auto rounded-xl border border-[#e5e7eb] bg-white p-1 text-[13px] shadow-lg"
        >
          {!current ? (
            <p className="px-3 py-3 text-[#6b7280]" role="status">
              Searching…
            </p>
          ) : current.failed ? (
            <p className="px-3 py-3 text-[#6b7280]" role="status">
              The search could not be run just now. Try again in a moment.
            </p>
          ) : hits.length === 0 ? (
            <p className="px-3 py-3 text-[#6b7280]" role="status">
              Nothing found for &ldquo;{query}&rdquo;. Try an order number, an email, a product name or a SKU.
            </p>
          ) : (
            hits.map((hit, index) => {
              const heading = hit.group !== lastGroup ? hit.group : null;
              lastGroup = hit.group;
              return (
                <React.Fragment key={hit.href}>
                  {heading && <p className="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wider text-[#6b7280]">{heading}</p>}
                  <Link
                    id={`${listId}-${index}`}
                    role="option"
                    aria-selected={index === active}
                    href={hit.href}
                    onMouseEnter={() => setActive(index)}
                    className={`block rounded-lg px-3 py-2 outline-none ${index === active ? 'bg-[#f3f4f6]' : 'hover:bg-[#f3f4f6]'}`}
                  >
                    <span className="block truncate font-semibold text-[#111827]">{hit.title}</span>
                    <span className="block truncate text-xs text-[#6b7280]">{hit.detail}</span>
                  </Link>
                </React.Fragment>
              );
            })
          )}
        </div>
      )}
    </div>
  );
}
