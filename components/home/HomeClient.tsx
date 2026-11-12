'use client';

import { useEffect, useState, useCallback } from 'react';
import { HeroBanner } from '@/components/home/HeroBanner';
import { BrandMarquee } from '@/components/home/BrandMarquee';
import { FlashDeals } from '@/components/home/FlashDeals';
import { ProductSection } from '@/components/home/ProductSection';
import { CategoryGrid } from '@/components/home/CategoryGrid';
import { BrowseByDressStyle } from '@/components/home/BrowseByDressStyle';
import { CustomerReviews } from '@/components/home/CustomerReviews';
import { getHomepageData, mapApiProductToStorefront, HomepageData } from '@/lib/api-client';
import { Product } from '@/types';
import { SHOP } from '@/lib/shop';
import { HugeiconsIcon } from '@hugeicons/react';
import { Alert02Icon, RefreshIcon } from '@hugeicons/core-free-icons';

/**
 * Home page body. app/page.tsx fetches the data on the server for each visit, so the page
 * arrives with its products; without it (API down at that moment) the browser loads it.
 */
export function HomeClient({ initial = null }: { initial?: HomepageData | null }) {
  const [loading, setLoading] = useState(!initial);
  const [error, setError] = useState(false);
  const [homepageData, setHomepageData] = useState<HomepageData | null>(initial);
  const [flashExpired, setFlashExpired] = useState(false);
  // New server data (StoreLiveUpdates refreshed the page after an admin change) replaces what is shown.
  const [shownInitial, setShownInitial] = useState(initial);
  if (initial !== shownInitial) {
    setShownInitial(initial);
    if (initial) {
      setHomepageData(initial);
      setFlashExpired(false);
      setError(false);
      setLoading(false);
    }
  }

  const loadHomepage = useCallback(async () => {
    setLoading(true);
    setError(false);
    try {
      const data = await getHomepageData();
      setHomepageData(data);
      setFlashExpired(false);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, []);

  // No server copy (the API was down when the page was built): load it in the browser.
  useEffect(() => {
    if (initial) return;
    let current = true;
    getHomepageData()
      .then(
        (data) => current && setHomepageData(data),
        () => current && setError(true)
      )
      .finally(() => current && setLoading(false));
    return () => {
      current = false;
    };
  }, [initial]);

  const featured: Product[] = homepageData ? homepageData.featured_products.map(mapApiProductToStorefront) : [];
  const newArrivals: Product[] = homepageData ? homepageData.new_arrivals.map(mapApiProductToStorefront) : [];
  const topSelling: Product[] = homepageData ? homepageData.top_selling.map(mapApiProductToStorefront) : [];
  const flashProducts: Product[] = homepageData ? homepageData.flash_deals.map(mapApiProductToStorefront) : [];

  return (
    <div className="space-y-4">
      {/* 1. The first screen: hero (CMS banners, active promotion, real store numbers) with the
          brands the store carries (admin Settings) moving along its bottom edge. */}
      <div className="first-screen flex flex-col">
        <HeroBanner banners={homepageData?.banners} promotions={homepageData?.promotions} stats={homepageData?.stats} />
        <BrandMarquee brands={homepageData?.brands} />
      </div>

      {/* Couldn't load the shop: say so plainly (never fall back to made-up products or prices) */}
      {error && (
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
          <div
            role="alert"
            className="bg-red-50 border border-red-200 rounded-2xl p-6 text-center sm:text-left flex flex-col sm:flex-row items-center justify-between gap-4"
          >
            <div className="flex items-center gap-3">
              <div className="p-3 bg-red-100 text-red-700 rounded-full shrink-0" aria-hidden="true">
                <HugeiconsIcon icon={Alert02Icon} size={24} />
              </div>
              <div>
                <h2 className="font-bold text-red-900 text-base">We couldn&apos;t load the shop right now</h2>
                <p className="text-red-800 text-sm mt-0.5">Please check your connection and try again in a moment.</p>
              </div>
            </div>
            <button
              type="button"
              onClick={loadHomepage}
              disabled={loading}
              className="inline-flex items-center gap-2 bg-red-700 hover:bg-red-800 disabled:opacity-60 text-white font-semibold text-sm px-5 py-2.5 rounded-full transition-all shrink-0 shadow-sm"
            >
              <HugeiconsIcon icon={RefreshIcon} size={16} aria-hidden="true" />
              <span>{loading ? 'Trying again…' : 'Try again'}</span>
            </button>
          </div>
        </div>
      )}

      {/* Loading Skeletons */}
      {loading && !homepageData && (
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 space-y-12" role="status" aria-live="polite">
          <span className="sr-only">Loading products…</span>
          <div className="space-y-6" aria-hidden="true">
            <div className="h-10 bg-neutral-200 rounded-xl w-64 mx-auto animate-pulse" />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6">
              {[1, 2, 3, 4].map((i) => (
                <div key={i} className="space-y-3 animate-pulse">
                  <div className="aspect-square bg-neutral-200 rounded-2xl" />
                  <div className="h-4 bg-neutral-200 rounded w-3/4" />
                  <div className="h-3 bg-neutral-200 rounded w-1/2" />
                  <div className="h-5 bg-neutral-200 rounded w-1/3" />
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Below the banner: the browser lays these out only as the shopper scrolls near them
          (see .below-fold in globals.css), so the first screen appears sooner on phones. */}
      <div className="below-fold space-y-4">
        {/* 2. Flash deals: real countdown only when the store set an end time */}
        {homepageData && flashProducts.length > 0 && !flashExpired && (
          <FlashDeals products={flashProducts} endsAt={homepageData.flash_deals_end_at} onExpired={() => setFlashExpired(true)} />
        )}

        {/* 3. Featured: the products the admin ticked (hidden when there are none) */}
        {homepageData && <ProductSection title={SHOP.wording.featured} products={featured} viewAllHref="/category?featured=true" />}

        {/* 4. New Arrivals */}
        {homepageData && (
          <ProductSection title={SHOP.wording.newArrivals} products={newArrivals} viewAllHref="/category?sort=newest" hasDivider={true} />
        )}

        {/* 5. Top Selling: only products that have really sold (hidden until something has) */}
        {homepageData && <ProductSection title={SHOP.wording.topSelling} products={topSelling} viewAllHref="/category" hasDivider={false} />}

        {/* 6. Categories (real counts) */}
        <CategoryGrid categories={homepageData?.categories} />

        {/* 7. The styles that exist in the catalogue (nothing when the shop has the style filter off) */}
        <BrowseByDressStyle />

        {/* 8. Approved customer reviews (hidden when there are none) */}
        <CustomerReviews reviews={homepageData?.reviews} />
      </div>
    </div>
  );
}
