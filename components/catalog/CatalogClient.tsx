'use client';

import { useEffect, useMemo, useState, useTransition } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { FilterState, Product } from '@/types';
import { CatalogFacets, errorMessage, getCatalogFacets, getProducts, mapApiProductToStorefront, ProductListResponse } from '@/lib/api-client';
import { buildCatalogUrl, catalogApiParams, EMPTY_FILTERS, parseCatalogParams } from '@/lib/catalog-query';
import { shopFilters, styleWord } from '@/lib/shop';
import { FilterSidebar } from '@/components/catalog/FilterSidebar';
import { FilterDrawer } from '@/components/catalog/FilterDrawer';
import { ProductGrid } from '@/components/catalog/ProductGrid';
import { HugeiconsIcon } from '@hugeicons/react';
import { ArrowRight01Icon } from '@hugeicons/core-free-icons';

/**
 * What the server already fetched for this address (app/category/page.tsx). `key` is the
 * canonical catalogue URL it was fetched for; null parts mean that fetch failed, and the
 * browser loads them instead.
 */
export interface CatalogSeed {
  key: string;
  products: ProductListResponse | null;
  facets: { category: string; facets: CatalogFacets } | null;
}

interface CatalogAnswer {
  key: string;
  products: Product[];
  totalCount: number;
  totalPages: number;
  error: string | null;
}

export function CatalogClient({ seed }: { seed: CatalogSeed }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const queryKey = searchParams.toString();
  // The URL is the single source of truth for every filter, the sort and the page (read the
  // same way as the server does in app/category/page.tsx, or its results would not be used).
  const filters = useMemo(() => shopFilters(parseCatalogParams(new URLSearchParams(queryKey))), [queryKey]);
  const canonicalKey = buildCatalogUrl(filters);
  // Filter and page changes are server navigations (the server sends the next results);
  // while one is on its way the grid shows its loading state.
  const [navigating, startNavigation] = useTransition();

  const [isMobileDrawerOpen, setIsMobileDrawerOpen] = useState(false);
  // Filter options for the category being shown (all products when none is chosen). The last
  // set stays on screen while the next category's loads, so the sidebar does not flash empty.
  const facetCategory = filters.category?.toLowerCase() ?? '';
  const seededFacets = seed.key === canonicalKey && seed.facets?.category === facetCategory ? seed.facets.facets : null;
  const [facetAnswer, setFacetAnswer] = useState<{ category: string; facets: CatalogFacets } | null>(null);
  const facets = seededFacets ?? facetAnswer?.facets ?? null;
  const hasSeededFacets = seededFacets !== null;

  // "Try again" counts per address, so a retry on one page doesn't stop later pages using the
  // server's results. A retry always asks the API again.
  const [retry, setRetry] = useState({ key: '', count: 0 });
  const retries = retry.key === queryKey ? retry.count : 0;
  const requestKey = `${queryKey}#${retries}`;
  const seededProducts = retries === 0 && seed.key === canonicalKey ? seed.products : null;
  const seededAnswer = useMemo<CatalogAnswer | null>(
    () =>
      seededProducts
        ? {
            key: requestKey,
            products: seededProducts.items.map(mapApiProductToStorefront),
            totalCount: seededProducts.pagination.total,
            totalPages: Math.max(1, seededProducts.pagination.total_pages),
            error: null,
          }
        : null,
    [seededProducts, requestKey]
  );
  const hasSeededProducts = seededAnswer !== null;
  // One browser request per address (filters, page) and per "Try again"; only its answer is shown.
  const [fetched, setFetched] = useState<CatalogAnswer | null>(null);
  const answer = seededAnswer ?? fetched;
  // While the next page loads, the previous results stay on screen.
  const loading = navigating || answer?.key !== requestKey;
  const products = answer?.products ?? [];
  const totalCount = answer?.totalCount ?? 0;
  const totalPages = answer?.totalPages ?? 1;
  const error = loading ? null : (answer?.error ?? null);

  useEffect(() => {
    if (hasSeededFacets) return;
    let current = true;
    getCatalogFacets(facetCategory || null)
      .catch((): CatalogFacets => ({ categories: [], colors: [], sizes: [], dress_styles: [], price: { min: 0, max: 0 } }))
      .then((value) => current && setFacetAnswer({ category: facetCategory, facets: value }));
    return () => {
      current = false;
    };
  }, [facetCategory, hasSeededFacets]);

  useEffect(() => {
    if (hasSeededProducts) return;
    let current = true; // a newer request makes this answer irrelevant
    getProducts(catalogApiParams(filters)).then(
      (response) => {
        if (!current) return;
        setFetched({
          key: requestKey,
          products: response.items.map(mapApiProductToStorefront),
          totalCount: response.pagination.total,
          totalPages: Math.max(1, response.pagination.total_pages),
          error: null,
        });
      },
      (err) => {
        if (!current) return;
        setFetched({ key: requestKey, products: [], totalCount: 0, totalPages: 1, error: errorMessage(err, 'The catalogue could not be loaded.') });
      }
    );
    return () => {
      current = false;
    };
  }, [filters, requestKey, hasSeededProducts]);

  const navigate = (next: FilterState, scrollTop = false) => {
    startNavigation(() => router.push(buildCatalogUrl(next), { scroll: false }));
    if (scrollTop) window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // Any filter change starts again from page 1. Colours and sizes belong to a category, so
  // choosing another category clears them.
  const handleFilterChange = (patch: Partial<FilterState>) => {
    const categoryChanged = 'category' in patch && (patch.category ?? null) !== (filters.category ?? null);
    navigate({ ...filters, ...(categoryChanged ? { selectedColors: [], selectedSizes: [] } : {}), ...patch, page: 1 });
  };
  const handlePageChange = (page: number) => navigate({ ...filters, page }, true);
  const handleReset = () => navigate(EMPTY_FILTERS);
  const handleRetry = () => setRetry((r) => ({ key: queryKey, count: (r.key === queryKey ? r.count : 0) + 1 }));

  const activeCategory = facets?.categories.find((c) => c.slug.toLowerCase() === filters.category?.toLowerCase());
  const activeTitle = activeCategory
    ? activeCategory.name
    : filters.category
      ? filters.category.replace(/-/g, ' ')
      : filters.searchQuery.trim()
        ? `Results for “${filters.searchQuery.trim()}”`
        : filters.dressStyle
          ? `${filters.dressStyle} ${styleWord}`
          : filters.flashDeals
            ? 'Flash deals'
            : filters.featured
              ? 'Featured'
            : filters.onSale
              ? 'On sale'
              : filters.sortBy === 'newest'
                ? 'New arrivals'
                : 'All products';

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
      <nav aria-label="Breadcrumb" className="flex items-center gap-2 text-sm text-neutral-700 mb-6">
        <Link href="/" className="hover:text-black transition-colors">
          Home
        </Link>
        <HugeiconsIcon icon={ArrowRight01Icon} size={14} className="text-neutral-500" aria-hidden="true" />
        <span className="text-black font-medium capitalize" aria-current="page">{activeTitle}</span>
      </nav>

      <div className="flex flex-col lg:flex-row gap-8 items-start">
        <FilterSidebar filters={filters} onFilterChange={handleFilterChange} onReset={handleReset} facets={facets} />

        <FilterDrawer
          isOpen={isMobileDrawerOpen}
          onClose={() => setIsMobileDrawerOpen(false)}
          filters={filters}
          onFilterChange={handleFilterChange}
          onReset={handleReset}
          facets={facets}
          resultCount={totalCount}
        />

        <ProductGrid
          products={products}
          totalCount={totalCount}
          categoryTitle={activeTitle}
          filters={filters}
          onFilterChange={handleFilterChange}
          onReset={handleReset}
          onOpenMobileFilters={() => setIsMobileDrawerOpen(true)}
          loading={loading}
          error={error}
          onRetry={handleRetry}
          onPageChange={handlePageChange}
          totalPages={totalPages}
        />
      </div>
    </div>
  );
}
