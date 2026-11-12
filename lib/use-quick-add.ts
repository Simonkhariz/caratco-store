'use client';

import { useCallback, useState } from 'react';
import { useRouter } from 'next/navigation';
import type { Product } from '@/types';
import { useCart } from '@/context/CartContext';
import { mapApiProductToStorefront } from '@/lib/api-client';
import { loadProductDetail, prefetchProductDetail } from '@/lib/product-detail-cache';

/**
 * The quick "add to cart" of a product shown in a list (catalogue card, wishlist). A product with
 * one option in stock goes straight into the cart; with several, `choose` is set and the caller
 * shows the quick-add dialog; sold out or failed loads open the product page.
 * `warm` starts loading the options before the tap, so the tap does not wait for the server.
 */
export function useQuickAdd(product: Pick<Product, 'slug'>, onNavigate?: () => void) {
  const router = useRouter();
  const { addToCart } = useCart();
  const [busy, setBusy] = useState(false);
  const [choose, setChoose] = useState<Product | null>(null);
  const slug = product.slug;

  const warm = useCallback(() => prefetchProductDetail(slug), [slug]);

  const add = useCallback(async () => {
    setBusy(true);
    try {
      const detail = mapApiProductToStorefront(await loadProductDetail(slug));
      const inStock = (detail.variants ?? []).filter((v) => v.stock > 0);
      if (inStock.length === 1) addToCart(detail, inStock[0], 1);
      else if (inStock.length > 1) setChoose(detail);
      else {
        onNavigate?.();
        router.push(`/products/${slug}`); // sold out since the list loaded: the product page says so
      }
    } catch {
      onNavigate?.();
      router.push(`/products/${slug}`);
    } finally {
      setBusy(false);
    }
  }, [slug, addToCart, router, onNavigate]);

  return { add, warm, busy, choose, closeChoose: () => setChoose(null) };
}
