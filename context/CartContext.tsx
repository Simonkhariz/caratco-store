'use client';

import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { CartItem, Product, ProductVariant, PromoCode } from '@/types';
import { couponDiscount, couponRuleFrom } from '@/lib/coupon';
import {
  ApiSavedCartItem,
  PRODUCT_IMAGE_PLACEHOLDER,
  apiCheckCartLines,
  apiGetSavedCart,
  apiSaveCart,
  apiValidatePromotion,
  apiVerifyPayment,
  ApiError,
  errorMessage,
  getProductDetail,
  type ValidatePromoResponse,
} from '@/lib/api-client';
import { useAuth } from '@/context/AuthContext';
import { clearCheckoutDraft, clearPendingOrder, isOrderSettled, markOrderSettled, readGuestToken, readPendingOrder, signatureQuantities } from '@/lib/pending-order';
import { withAddedLines, type AddedLine } from '@/lib/cart-lines';
import { mainOption, nameWithOptions, optionsText } from '@/lib/shop';

interface ToastMessage {
  id: string;
  title: string;
  message: string;
  image?: string;
  /** Show the "View cart" action (only for cart additions). */
  showCartAction?: boolean;
}

export type PendingOrderOutcome = 'none' | 'paid' | 'cancelled' | 'pending' | 'unknown';

/** A line of a paid order, as the order API returns it. */
export interface PaidOrderLine {
  variant_id: number | null;
  quantity: number;
}

/** Per-line maximum, matching the server's checkout limit. */
export const MAX_QUANTITY_PER_LINE = 20;

interface CartContextType {
  items: CartItem[];
  /** False while the saved cart is loading: `items` is not the shopper's cart yet. */
  isLoaded: boolean;
  addToCart: (product: Product, variant: ProductVariant, quantity?: number) => void;
  removeFromCart: (cartItemId: string) => void;
  updateQuantity: (cartItemId: string, quantity: number) => void;
  clearCart: () => void;
  restoreItems: (newItems: CartItem[]) => void;
  appliedPromo: PromoCode | null;
  applyPromoCode: (code: string) => Promise<{ success: boolean; message: string }>;
  removePromoCode: () => void;
  /** Re-check every line against live price, stock and availability. */
  /** Re-checks prices and stock. True when something in the cart was changed (a notice says what). */
  revalidateCart: () => Promise<boolean>;
  isRevalidating: boolean;
  /** Human-readable changes found during the last re-check (price changes, stock limits...). */
  cartNotices: string[];
  dismissCartNotices: () => void;
  subtotal: number;
  discountAmount: number;
  /** Subtotal minus discount. Delivery is calculated at checkout from the delivery address. */
  total: number;
  totalQuantity: number;
  /**
   * Ask the server now about the unpaid order placed from this cart; when it has been paid,
   * its items leave the cart.
   */
  settlePendingOrder: () => Promise<PendingOrderOutcome>;
  /**
   * The payment for this order is verified (receipt page): take its items out of the cart,
   * once per order. `lines` are the order's own items when the page could load them.
   */
  completePaidOrder: (orderNumber: string, lines?: PaidOrderLine[]) => void;
  toast: ToastMessage | null;
  hideToast: () => void;
}

const CartContext = createContext<CartContextType | undefined>(undefined);

const PROMO_STORAGE_KEY = 'shopco_promo_code';

/** The applied code as the cart keeps it, from the server's promo check. */
function promoFrom(res: ValidatePromoResponse): PromoCode {
  const rule = couponRuleFrom(res);
  const fallback = rule.discountType === 'fixed' ? `₦${rule.discountValue.toLocaleString('en-NG')} off` : `${rule.discountPercent}% off`;
  return { code: res.code, description: res.description || '', label: res.label || fallback, rule };
}

function readJson<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    return fallback;
  }
}

function writeStorage(key: string, value: string | null): void {
  try {
    if (value === null) {
      localStorage.removeItem(key);
    } else {
      localStorage.setItem(key, value);
    }
  } catch {
    // Storage unavailable (private mode / quota): the cart still works for this visit.
  }
}

function lineKey(variantId: number): string {
  return String(variantId);
}

// How a toast or notice names a line. Only the options the product uses are named: the "none"
// value of an unused option ("Standard", "Default") is never shown.
/** "Classic Tee (M / Navy)". */
function fullName(name: string, size: string | null | undefined, color: string | null | undefined): string {
  return nameWithOptions(name, optionsText(size, color));
}
/** "Classic Tee (M)": the short form, with the one option that tells the lines apart. */
function shortName(name: string, size: string | null | undefined, color: string | null | undefined): string {
  return nameWithOptions(name, mainOption(size, color));
}

function fromSavedLine(line: ApiSavedCartItem): CartItem {
  return {
    id: lineKey(line.variant_id),
    productId: String(line.product_id),
    variantId: line.variant_id,
    sku: line.sku,
    name: line.product_name,
    slug: line.product_slug,
    price: line.unit_price,
    originalPrice: line.original_price ?? undefined,
    image: line.image_url || PRODUCT_IMAGE_PLACEHOLDER,
    size: line.size,
    color: { name: line.color_name, hex: line.color_hex || '#000000' },
    quantity: Math.min(line.quantity, MAX_QUANTITY_PER_LINE),
    maxStock: line.stock_quantity,
  };
}

/** Union of two carts by variant; the larger quantity wins so logging in never doubles a line. */
function mergeCarts(a: CartItem[], b: CartItem[]): CartItem[] {
  const map = new Map<string, CartItem>();
  for (const item of [...a, ...b]) {
    const existing = map.get(item.id);
    map.set(item.id, existing ? { ...existing, quantity: Math.max(existing.quantity, item.quantity) } : item);
  }
  return Array.from(map.values());
}

/** Take the lines of a paid order (its "variant:quantity" cart signature) out of the cart. */
function removeOrderedLines(items: CartItem[], signature: string): CartItem[] {
  const ordered = signatureQuantities(signature);
  return items
    .map((item) => {
      const key = String(item.variantId ?? item.id);
      const paid = ordered.get(key) ?? 0;
      return paid > 0 ? { ...item, quantity: item.quantity - paid } : item;
    })
    .filter((item) => item.quantity > 0);
}

/** Don't ask the server about the same unpaid order more than once a minute. */
const PENDING_CHECK_INTERVAL_MS = 60_000;

export const CartProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, isLoading: authLoading } = useAuth();
  const isCustomer = !!user && user.role === 'customer';
  const [items, setItems] = useState<CartItem[]>([]);
  const [isLoaded, setIsLoaded] = useState(false);
  const [appliedPromo, setAppliedPromo] = useState<PromoCode | null>(null);
  const [toast, setToast] = useState<ToastMessage | null>(null);
  const [cartNotices, setCartNotices] = useState<string[]>([]);
  const [isRevalidating, setIsRevalidating] = useState(false);
  const serverSyncReady = useRef(false);
  // The cart key whose stored cart has been loaded into `items` (null while loading).
  const loadedKey = useRef<string | null>(null);
  const loadedRef = useRef(false);
  loadedRef.current = isLoaded;
  // Added to the cart before it finished loading: put back on top of what the load brings.
  const addedWhileLoading = useRef<AddedLine[]>([]);
  /** Counts cart changes, so a finished save knows whether a newer change is still unsaved. */
  const cartChange = useRef(0);
  const itemsRef = useRef<CartItem[]>([]);
  itemsRef.current = items;

  const cartStorageKey = isCustomer ? `shopco_cart_user_${user!.id}` : 'shopco_cart_guest';

  const showToast = useCallback((title: string, message: string, image?: string, showCartAction = false) => {
    const id = Date.now().toString();
    setToast({ id, title, message, image, showCartAction });
    setTimeout(() => {
      setToast((current) => (current?.id === id ? null : current));
    }, 2800);
  }, []);

  const hideToast = () => setToast(null);

  // Load the cart for the current identity. Signed-in customers get their saved server cart,
  // merged with anything they added as a guest on this device.
  useEffect(() => {
    if (authLoading) return;
    let cancelled = false;
    serverSyncReady.current = false;
    loadedKey.current = null;
    setIsLoaded(false);

    (async () => {
      writeStorage('shopco_cart', null); // legacy key from the mock build
      const local = readJson<CartItem[]>(cartStorageKey, []);
      const localItems = Array.isArray(local) ? local : [];
      let next = localItems;

      if (isCustomer) {
        const guest = readJson<CartItem[]>('shopco_cart_guest', []);
        const guestItems = Array.isArray(guest) ? guest : [];
        next = mergeCarts(localItems, guestItems);
        try {
          const saved = await apiGetSavedCart();
          // The account's saved cart is the truth: paid orders are taken out of it on the
          // server. This device's copy only adds changes it had not managed to save yet,
          // otherwise it would put the items of a paid order back.
          const unsaved = readJson<boolean>(`${cartStorageKey}_unsaved`, false) === true;
          next = mergeCarts(mergeCarts(saved.map(fromSavedLine), unsaved ? localItems : []), guestItems);
          writeStorage('shopco_cart_guest', null);
          serverSyncReady.current = true;
        } catch {
          // Offline / API down: keep the local cart; it syncs on the next successful change.
        }
      }

      if (!cancelled) {
        loadedKey.current = cartStorageKey;
        const added = addedWhileLoading.current;
        addedWhileLoading.current = [];
        setItems(withAddedLines(next, added, MAX_QUANTITY_PER_LINE));
        setIsLoaded(true);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [authLoading, isCustomer, cartStorageKey]);

  // Other tabs of the shop: when one changes the cart (an item added, or a paid order taken
  // out on the receipt page), every other tab takes the same cart instead of keeping (and
  // later saving back) its own older copy.
  const fromOtherTab = useRef(false);
  useEffect(() => {
    if (!isLoaded) return;
    const onStorage = (e: StorageEvent) => {
      if (e.key === cartStorageKey) {
        const next = readJson<CartItem[]>(cartStorageKey, []);
        fromOtherTab.current = true;
        itemsRef.current = Array.isArray(next) ? next : [];
        setItems(itemsRef.current);
      } else if (e.key === PROMO_STORAGE_KEY && e.newValue === null) {
        setAppliedPromo(null);
      }
    };
    window.addEventListener('storage', onStorage);
    return () => window.removeEventListener('storage', onStorage);
  }, [isLoaded, cartStorageKey]);

  // Persist locally on every change; for customers also save to the account (debounced).
  useEffect(() => {
    if (!isLoaded) return;
    // Right after signing in or out this effect runs once more with the previous identity's
    // items (isLoaded is still true in that render). Writing them would store them under the new
    // account and mark that account's old local copy as "unsaved", which the load then merges
    // back in (bringing back items of an order that was already paid). Wait for the load.
    if (loadedKey.current !== cartStorageKey) return;
    if (fromOtherTab.current) {
      // Already stored (and saved to the account) by the tab that made the change.
      fromOtherTab.current = false;
      return;
    }
    writeStorage(cartStorageKey, JSON.stringify(items));

    // Only write to the account once its saved cart was read, so an offline device never
    // overwrites a fuller cart saved from another device.
    if (!isCustomer) return;
    const unsavedKey = `${cartStorageKey}_unsaved`;
    writeStorage(unsavedKey, 'true');
    const change = ++cartChange.current;
    if (!serverSyncReady.current) return;
    const timer = setTimeout(() => {
      const lines = items
        .filter((i) => typeof i.variantId === 'number')
        .map((i) => ({ variant_id: i.variantId as number, quantity: i.quantity }));
      apiSaveCart(lines)
        .then(() => {
          serverSyncReady.current = true;
          // Only when nothing changed while this save was on its way.
          if (change === cartChange.current) writeStorage(unsavedKey, null);
        })
        .catch(() => {
          // Saved locally; the next change retries.
        });
    }, 800);
    return () => clearTimeout(timer);
  }, [items, isLoaded, isCustomer, cartStorageKey]);

  // Restore a previously applied promo code (validated again before use).
  useEffect(() => {
    const code = readJson<string | null>(PROMO_STORAGE_KEY, null);
    if (code) {
      // Rules unknown until the server check below: no discount is previewed meanwhile.
      setAppliedPromo({ code, description: '', label: '', rule: couponRuleFrom({ discount_type: 'percent', discount_percent: 0 }) });
    }
  }, []);

  const subtotal = useMemo(() => items.reduce((sum, item) => sum + item.price * item.quantity, 0), [items]);

  // Re-validate the promo whenever the cart total changes; drop it with a notice if it no longer applies.
  const promoCode = appliedPromo?.code ?? null;
  useEffect(() => {
    if (!promoCode || !isLoaded) return;
    if (items.length === 0) return;
    // The shopper may remove or replace the code while this check is on its way: its answer
    // must not bring the old code back.
    let current = true;
    const timer = setTimeout(async () => {
      try {
        const res = await apiValidatePromotion(promoCode, subtotal);
        if (!current) return;
        if (res?.valid) {
          setAppliedPromo(promoFrom(res));
        } else {
          throw new ApiError('Coupon is no longer valid.', 422, 'INVALID_PROMOTION');
        }
      } catch (err) {
        // Only the server saying the code is invalid removes it. A network blip, a busy server
        // or the per-minute limit keeps it: the checkout quote checks the code again anyway.
        if (!current || !(err instanceof ApiError && err.errorCode === 'INVALID_PROMOTION')) return;
        setAppliedPromo(null);
        writeStorage(PROMO_STORAGE_KEY, null);
        setCartNotices((prev) => [...prev, `Promo code ${promoCode} was removed: ${errorMessage(err, 'it is no longer valid.')}`]);
      }
    }, 500);
    return () => {
      current = false;
      clearTimeout(timer);
    };
  }, [promoCode, subtotal, isLoaded, items.length]);

  const addToCart = (product: Product, variant: ProductVariant, quantity: number = 1) => {
    const id = lineKey(variant.id);
    const cap = Math.min(MAX_QUANTITY_PER_LINE, variant.stock);
    if (cap <= 0) {
      showToast('Out of stock', `${fullName(product.name, variant.size, variant.color.name)} is sold out.`);
      return;
    }

    const existing = itemsRef.current.find((i) => i.id === id);
    const newQuantity = Math.min(cap, (existing?.quantity ?? 0) + quantity);
    const line: CartItem = {
      id,
      productId: product.id,
      variantId: variant.id,
      sku: variant.sku,
      name: product.name,
      slug: product.slug,
      price: variant.price,
      originalPrice: product.originalPrice,
      image: product.images[0] || PRODUCT_IMAGE_PLACEHOLDER,
      size: variant.size,
      color: variant.color,
      quantity: newQuantity,
      maxStock: variant.stock,
    };
    if (!loadedRef.current) addedWhileLoading.current.push({ line, quantity });

    setItems((prev) => {
      const index = prev.findIndex((i) => i.id === id);
      if (index > -1) {
        const updated = [...prev];
        updated[index] = { ...prev[index], ...line };
        return updated;
      }
      return [...prev, line];
    });

    const capped = (existing?.quantity ?? 0) + quantity > cap;
    showToast(
      capped ? 'Quantity limited' : 'Added to cart',
      capped
        ? `Only ${cap} of ${shortName(product.name, variant.size, variant.color.name)} can be ordered.`
        : fullName(product.name, variant.size, variant.color.name),
      product.images[0],
      true
    );
  };

  const removeFromCart = (cartItemId: string) => {
    setItems((prev) => prev.filter((item) => item.id !== cartItemId));
  };

  const updateQuantity = (cartItemId: string, quantity: number) => {
    if (quantity <= 0) {
      removeFromCart(cartItemId);
      return;
    }
    setItems((prev) =>
      prev.map((item) => {
        if (item.id !== cartItemId) return item;
        const cap = Math.min(MAX_QUANTITY_PER_LINE, item.maxStock ?? MAX_QUANTITY_PER_LINE);
        return { ...item, quantity: Math.max(1, Math.min(quantity, cap)) };
      })
    );
  };

  const clearCart = () => {
    setItems([]);
    setAppliedPromo(null);
    writeStorage(PROMO_STORAGE_KEY, null);
  };

  // A paid order's items leave the cart exactly once per order on this device (anything added
  // since stays). Before the cart has loaded, the order waits and is applied right after
  // loading, so the freshly loaded cart can't bring the paid items back.
  const paidWhileLoading = useRef<Array<{ orderNumber: string; lines?: PaidOrderLine[] }>>([]);
  const applyPaidOrder = useCallback((orderNumber: string, lines?: PaidOrderLine[]) => {
    if (isOrderSettled(orderNumber)) {
      clearPendingOrder(orderNumber);
      return;
    }
    const pending = readPendingOrder();
    // The order's own items when known (also covers paying again from the orders page);
    // otherwise the cart this order was placed from.
    const signature = lines?.length
      ? lines.filter((l) => l.variant_id).map((l) => `${l.variant_id}:${l.quantity}`).join('|')
      : pending?.orderNumber === orderNumber
        ? pending.cartSignature
        : '';
    if (!signature) return;
    markOrderSettled(orderNumber);
    clearPendingOrder(orderNumber);
    clearCheckoutDraft(); // kept until now so the details could still be changed before paying
    const remaining = removeOrderedLines(itemsRef.current, signature);
    itemsRef.current = remaining;
    setItems(remaining);
    if (remaining.length === 0) {
      setAppliedPromo(null);
      writeStorage(PROMO_STORAGE_KEY, null);
    }
  }, []);

  const completePaidOrder = useCallback(
    (orderNumber: string, lines?: PaidOrderLine[]) => {
      if (!loadedRef.current) {
        paidWhileLoading.current.push({ orderNumber, lines });
        return;
      }
      applyPaidOrder(orderNumber, lines);
    },
    [applyPaidOrder]
  );

  useEffect(() => {
    if (!isLoaded || paidWhileLoading.current.length === 0) return;
    const queued = paidWhileLoading.current;
    paidWhileLoading.current = [];
    queued.forEach((o) => applyPaidOrder(o.orderNumber, o.lines));
  }, [isLoaded, applyPaidOrder]);

  // An order placed from this cart can be paid away from the payment return page: a WhatsApp
  // transfer the store confirms later, or a gateway tab closed before it redirected back.
  const lastPendingCheck = useRef(0);
  const revalidateRef = useRef<() => Promise<boolean>>(async () => false);
  const checkPendingOrder = useCallback(async (force = false): Promise<PendingOrderOutcome> => {
    const pending = readPendingOrder();
    if (!pending) return 'none';
    // The payment return page checks its order itself (lib/payment-queries.ts) and settles the
    // cart when it is paid; asking the server for the same payment again there is a duplicate.
    if (!force && window.location.pathname === '/checkout/callback') return 'pending';
    if (!force && Date.now() - lastPendingCheck.current < PENDING_CHECK_INTERVAL_MS) return 'pending';
    lastPendingCheck.current = Date.now();
    try {
      const res = await apiVerifyPayment(pending.orderNumber, readGuestToken(pending.orderNumber));
      const orderStatus = String(res.order_status || '');
      if (res.status === 'successful' && orderStatus !== 'cancelled') {
        completePaidOrder(pending.orderNumber);
        // Found on returning to the tab (a transfer the store confirmed, a payment tab closed
        // early): say why the items left the cart. Callers that ask on purpose show their own page.
        if (!force) showToast('Payment received', `Order ${pending.orderNumber} is paid. Follow it under Track order.`);
        return 'paid';
      }
      if (orderStatus === 'cancelled') {
        clearPendingOrder(pending.orderNumber);
        // A check running alongside this one may have counted the order's reservation as stock.
        void revalidateRef.current();
        return 'cancelled';
      }
      return 'pending';
    } catch {
      // Offline or no access from this device: try again on the next visit.
      return 'unknown';
    }
  }, [completePaidOrder, showToast]);

  useEffect(() => {
    if (!isLoaded) return;
    checkPendingOrder();
    const onVisible = () => {
      if (document.visibilityState === 'visible') void checkPendingOrder();
    };
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);
    return () => {
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('focus', onVisible);
    };
  }, [isLoaded, checkPendingOrder]);

  const restoreItems = (newItems: CartItem[]) => {
    if (!Array.isArray(newItems) || newItems.length === 0) return;
    setItems((prev) => mergeCarts(prev, newItems));
  };

  const revalidateCart = useCallback(async () => {
    const current = itemsRef.current;
    if (current.length === 0) return false;
    setIsRevalidating(true);
    const notices: string[] = [];

    try {
      // Legacy lines saved before variants were tracked: resolve the exact variant or drop them.
      const resolved: CartItem[] = [];
      /** Checked line id -> the id it had when the check started. */
      const startedAs = new Map<string, string>();
      for (const item of current) {
        startedAs.set(item.id, item.id);
        if (typeof item.variantId === 'number') {
          resolved.push(item);
          continue;
        }
        try {
          const detail = await getProductDetail(item.productId);
          const match = detail.variants.find(
            (v) =>
              v.size.toLowerCase() === String(item.size).toLowerCase() &&
              v.color_name.toLowerCase() === item.color.name.toLowerCase()
          );
          if (match) {
            resolved.push({ ...item, id: lineKey(match.id), variantId: match.id, sku: match.sku });
            startedAs.set(lineKey(match.id), item.id);
          } else {
            notices.push(`${fullName(item.name, item.size, item.color.name)} is no longer offered and was removed. Please choose another option.`);
          }
        } catch {
          notices.push(`${item.name} is no longer available and was removed from your cart.`);
        }
      }

      const { lines } = await apiCheckCartLines(resolved.map((i) => i.variantId as number));
      const byVariant = new Map(lines.map((l) => [l.variant_id, l]));
      // Placing an order takes its items out of stock while it waits for payment, but they are
      // this shopper's own: they count as available, or the check would remove them from the
      // cart the order was placed from (and the order could no longer be resumed).
      const pendingOrder = readPendingOrder();
      const reservedForMe = pendingOrder ? signatureQuantities(pendingOrder.cartSignature) : new Map<string, number>();

      const next: CartItem[] = [];
      for (const item of resolved) {
        const live = byVariant.get(item.variantId as number);
        if (!live || !live.available) {
          notices.push(`${shortName(item.name, item.size, item.color?.name)} is no longer available and was removed.`);
          continue;
        }
        const available = live.stock_quantity + (reservedForMe.get(String(item.variantId)) ?? 0);
        if (available <= 0) {
          notices.push(`${fullName(item.name, item.size, item.color?.name)} sold out and was removed.`);
          continue;
        }
        let quantity = item.quantity;
        if (quantity > available) {
          quantity = available;
          notices.push(`Only ${available} left of ${shortName(item.name, item.size, item.color?.name)}; quantity updated.`);
        }
        if (Math.abs(live.unit_price - item.price) >= 0.01) {
          notices.push(
            `${item.name} price ${live.unit_price > item.price ? 'increased' : 'dropped'} to ₦${live.unit_price.toLocaleString('en-NG')}.`
          );
        }
        next.push({
          ...item,
          name: live.product_name,
          slug: live.product_slug,
          price: live.unit_price,
          originalPrice: live.original_price ?? undefined,
          quantity: Math.min(quantity, MAX_QUANTITY_PER_LINE),
          maxStock: available,
        });
      }

      // Apply the results to the cart as it is now, not as it was when the check started:
      // a paid order may have left the cart meanwhile, or the shopper changed something.
      const checked = new Map(next.map((item) => [startedAs.get(item.id) ?? item.id, item]));
      const checkedIds = new Set(current.map((item) => item.id));
      setItems((prev) =>
        mergeCarts(
          [],
          prev.flatMap((item) => {
            if (!checkedIds.has(item.id)) return [item]; // added during the check
            const live = checked.get(item.id);
            if (!live) return []; // no longer available
            return [{ ...live, quantity: Math.min(item.quantity, live.maxStock ?? item.quantity, MAX_QUANTITY_PER_LINE) }];
          })
        )
      );
      if (notices.length > 0) {
        setCartNotices(notices);
      }
      return notices.length > 0;
    } catch {
      // Network problem: keep the cart as-is; checkout still re-verifies everything server-side.
      return false;
    } finally {
      setIsRevalidating(false);
    }
  }, []);
  useEffect(() => {
    revalidateRef.current = revalidateCart;
  }, [revalidateCart]);

  // Check prices and stock once the cart has loaded.
  const revalidatedFor = useRef<string | null>(null);
  useEffect(() => {
    if (!isLoaded || revalidatedFor.current === cartStorageKey) return;
    revalidatedFor.current = cartStorageKey;
    revalidateCart();
  }, [isLoaded, cartStorageKey, revalidateCart]);

  const applyPromoCode = async (code: string): Promise<{ success: boolean; message: string }> => {
    const cleanCode = code.trim().toUpperCase();
    if (!cleanCode) {
      return { success: false, message: 'Please enter a coupon code.' };
    }

    try {
      const res = await apiValidatePromotion(cleanCode, subtotal);
      if (res && res.valid) {
        const promo = promoFrom(res);
        setAppliedPromo(promo);
        writeStorage(PROMO_STORAGE_KEY, JSON.stringify(res.code));
        showToast('Coupon applied', `${res.code} · ${promo.label}`);
        return { success: true, message: `Promo code ${res.code} applied! (${promo.label})` };
      }
      return { success: false, message: 'Coupon code is invalid or deactivated.' };
    } catch (err) {
      return { success: false, message: errorMessage(err, 'Invalid or expired coupon code.') };
    }
  };

  const removePromoCode = () => {
    setAppliedPromo(null);
    writeStorage(PROMO_STORAGE_KEY, null);
  };

  const discountAmount = appliedPromo ? couponDiscount(appliedPromo.rule, subtotal) : 0;
  const total = Math.max(0, subtotal - discountAmount);
  const totalQuantity = items.reduce((sum, item) => sum + item.quantity, 0);

  return (
    <CartContext.Provider
      value={{
        items,
        isLoaded,
        addToCart,
        removeFromCart,
        updateQuantity,
        clearCart,
        restoreItems,
        appliedPromo,
        applyPromoCode,
        removePromoCode,
        revalidateCart,
        isRevalidating,
        cartNotices,
        dismissCartNotices: () => setCartNotices([]),
        subtotal,
        discountAmount,
        total,
        totalQuantity,
        settlePendingOrder: () => checkPendingOrder(true),
        completePaidOrder,
        toast,
        hideToast,
      }}
    >
      {children}
    </CartContext.Provider>
  );
};

export const useCart = () => {
  const context = useContext(CartContext);
  if (!context) {
    throw new Error('useCart must be used within a CartProvider');
  }
  return context;
};
