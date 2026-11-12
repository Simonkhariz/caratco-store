import { getProductDetail, type ApiProductDetail } from '@/lib/api-client';

/** Stock is read from this copy when adding to the cart, so it is only reused for a short while. */
export const DETAIL_TTL_MS = 30_000;

const cache = new Map<string, { at: number; promise: Promise<ApiProductDetail> }>();

/**
 * A product's details (options and live stock) for the quick add-to-cart button. Started early
 * (when a finger or pointer reaches the button) and shared, so the tap itself finds the answer
 * ready instead of waiting for a server round trip.
 */
export function loadProductDetail(slug: string, now: number = Date.now()): Promise<ApiProductDetail> {
  const hit = cache.get(slug);
  if (hit && now - hit.at < DETAIL_TTL_MS) return hit.promise;
  const promise = getProductDetail(slug);
  cache.set(slug, { at: now, promise });
  // A failed load is not kept: the next try asks again.
  promise.catch(() => {
    if (cache.get(slug)?.promise === promise) cache.delete(slug);
  });
  return promise;
}

/** Forget every copy (the shop changed something: the next tap asks the server again). */
export function clearProductDetailCache(): void {
  cache.clear();
}

/** Warm the cache; errors are ignored here (the tap asks again and handles them). */
export function prefetchProductDetail(slug: string): void {
  void loadProductDetail(slug).catch(() => {});
}
