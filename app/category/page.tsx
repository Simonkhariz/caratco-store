import { SHOP, option1Word, option2Word, shopFilters, styleWord } from '@/lib/shop';
import type { Metadata } from 'next';
import { Suspense } from 'react';
import { CatalogClient, type CatalogSeed } from '@/components/catalog/CatalogClient';
import type { ApiProductSummary, CatalogFacets, PaginationMeta } from '@/lib/api-client';
import { buildCatalogUrl, catalogApiParams, parseCatalogParams, productListFrom, productListPath, urlParamsFrom } from '@/lib/catalog-query';
import { serverApiGet, serverApiGetFull } from '@/lib/server-api';

type SearchParams = Record<string, string | string[] | undefined>;

/** The tab says what the page shows: a search, a category, the deals, or everything. */
export async function generateMetadata({ searchParams }: { searchParams: Promise<SearchParams> }): Promise<Metadata> {
  const filters = shopFilters(parseCatalogParams(urlParamsFrom(await searchParams)));
  const words = (slug: string) => slug.replace(/[-_]+/g, ' ').replace(/\b\p{L}/gu, (c) => c.toUpperCase());
  // The filters this shop has, in its own words ("colour, size, style" in the template).
  const filterWords = ['category', option2Word, option1Word, ...(SHOP.style.enabled ? [styleWord] : [])].join(', ');
  const heading = filters.searchQuery
    ? `Search: ${filters.searchQuery}`
    : filters.category
      ? words(filters.category)
      : filters.onSale || filters.flashDeals
        ? 'Deals'
        : filters.featured
          ? 'Featured products'
          : 'Shop all products';
  return {
    title: `${heading} | ${SHOP.name}`,
    description: `Browse every product in the ${SHOP.name} catalogue, with filters for ${filterWords} and price.`,
  };
}

/**
 * The catalogue is sent with its first results and filter options already in the page, like
 * the home and product pages, instead of loading them in the browser after the scripts run.
 * If the API can't be reached the page still renders and the browser loads them itself.
 */
export default async function CategoryPage({ searchParams }: { searchParams: Promise<SearchParams> }) {
  // shopFilters: the same reading as components/catalog/CatalogClient.tsx, so the two agree on the address.
  const filters = shopFilters(parseCatalogParams(urlParamsFrom(await searchParams)));
  const category = filters.category?.toLowerCase() ?? '';
  const [list, facets] = await Promise.all([
    serverApiGetFull<ApiProductSummary[], Partial<PaginationMeta>>(productListPath(catalogApiParams(filters))),
    serverApiGet<CatalogFacets>(category ? `/catalog/facets?category=${encodeURIComponent(category)}` : '/catalog/facets'),
  ]);

  const seed: CatalogSeed = {
    key: buildCatalogUrl(filters),
    products: list ? productListFrom(list.data, list.meta) : null,
    facets: facets ? { category, facets } : null,
  };

  return (
    <Suspense fallback={<div className="p-12 text-center text-neutral-700">Loading catalogue…</div>}>
      <CatalogClient seed={seed} />
    </Suspense>
  );
}
