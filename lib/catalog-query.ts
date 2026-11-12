/**
 * Catalogue URL <-> filter state. The URL is the single source of truth, so Back/Forward,
 * shared links and header links always show exactly the filters in the address bar.
 * Older parameter names (category, colors, sizes, newArrivals, topSelling...) are still read.
 */
import type { FilterState } from '@/types';
import type { ApiProductSummary, PaginationMeta, ProductListResponse, ProductQueryParams } from '@/lib/api-client';

export const PAGE_SIZE = 12;

const SORTS: FilterState['sortBy'][] = ['most-popular', 'price-low-high', 'price-high-low', 'newest', 'rating'];

function list(value: string | null): string[] {
  return value ? value.split(',').map((v) => v.trim()).filter(Boolean) : [];
}

function num(value: string | null): number | null {
  if (value === null || value.trim() === '') return null;
  const n = Number(value);
  return Number.isFinite(n) && n >= 0 ? n : null;
}

export function parseCatalogParams(params: URLSearchParams): FilterState {
  const sortParam = params.get('sort') as FilterState['sortBy'] | null;
  const sortBy: FilterState['sortBy'] = sortParam && SORTS.includes(sortParam)
    ? sortParam
    : params.get('newArrivals') === 'true'
      ? 'newest'
      : 'most-popular';

  return {
    category: params.get('cat') || params.get('category') || null,
    dressStyle: params.get('style') || params.get('dressStyle') || null,
    searchQuery: params.get('q') || params.get('search') || '',
    selectedColors: list(params.get('color') || params.get('colors')),
    selectedSizes: list(params.get('size') || params.get('sizes')),
    minPrice: num(params.get('min_price')),
    maxPrice: num(params.get('max_price')),
    sortBy,
    onSale: params.get('onSale') === 'true' || params.get('sale') === 'true',
    flashDeals: params.get('flash') === 'true' || params.get('flashDeals') === 'true',
    featured: params.get('featured') === 'true',
    page: Math.max(1, Math.floor(num(params.get('page')) ?? 1)),
  };
}

/** A Next.js searchParams object as URLSearchParams (first value of a repeated key, like `get`). */
export function urlParamsFrom(raw: Record<string, string | string[] | undefined>): URLSearchParams {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(raw)) {
    const first = Array.isArray(value) ? value[0] : value;
    if (first !== undefined) params.set(key, first);
  }
  return params;
}

export function buildCatalogUrl(f: FilterState): string {
  const params = new URLSearchParams();
  if (f.category) params.set('cat', f.category);
  if (f.dressStyle) params.set('style', f.dressStyle);
  if (f.searchQuery.trim()) params.set('q', f.searchQuery.trim());
  if (f.selectedColors.length) params.set('color', f.selectedColors.join(','));
  if (f.selectedSizes.length) params.set('size', f.selectedSizes.join(','));
  if (f.minPrice !== null) params.set('min_price', String(f.minPrice));
  if (f.maxPrice !== null) params.set('max_price', String(f.maxPrice));
  if (f.sortBy !== 'most-popular') params.set('sort', f.sortBy);
  if (f.onSale) params.set('onSale', 'true');
  if (f.flashDeals) params.set('flash', 'true');
  if (f.featured) params.set('featured', 'true');
  if (f.page > 1) params.set('page', String(f.page));
  const qs = params.toString();
  return qs ? `/category?${qs}` : '/category';
}

export const EMPTY_FILTERS: FilterState = {
  category: null,
  dressStyle: null,
  minPrice: null,
  maxPrice: null,
  selectedColors: [],
  selectedSizes: [],
  sortBy: 'most-popular',
  searchQuery: '',
  onSale: false,
  flashDeals: false,
  featured: false,
  page: 1,
};

/** API sort value for a UI sort option. */
export function apiSortFor(sortBy: FilterState['sortBy']): string {
  switch (sortBy) {
    case 'price-low-high':
      return 'price_asc';
    case 'price-high-low':
      return 'price_desc';
    case 'newest':
      return 'newest';
    case 'rating':
      return 'rating_desc';
    default:
      return 'best_selling';
  }
}

/** The /products request for a catalogue page. Used by the server render and the browser alike. */
export function catalogApiParams(f: FilterState): ProductQueryParams {
  return {
    category: f.category || undefined,
    search: f.searchQuery.trim() || undefined,
    min_price: f.minPrice ?? undefined,
    max_price: f.maxPrice ?? undefined,
    dress_style: f.dressStyle || undefined,
    colors: f.selectedColors.length ? f.selectedColors : undefined,
    sizes: f.selectedSizes.length ? f.selectedSizes : undefined,
    on_sale: f.onSale || undefined,
    flash_deals: f.flashDeals || undefined,
    is_featured: f.featured || undefined,
    sort: apiSortFor(f.sortBy),
    page: f.page,
    per_page: PAGE_SIZE,
  };
}

/** API path for a product listing, e.g. "/products?category=hoodies&page=2". */
export function productListPath(params: ProductQueryParams): string {
  const query = new URLSearchParams();
  if (params.category) query.set('category', params.category);
  if (params.search) query.set('search', params.search);
  if (params.min_price !== undefined) query.set('min_price', String(params.min_price));
  if (params.max_price !== undefined) query.set('max_price', String(params.max_price));
  if (params.dress_style) query.set('dress_style', params.dress_style);
  if (params.colors && params.colors.length > 0) query.set('colors', params.colors.join(','));
  if (params.sizes && params.sizes.length > 0) query.set('sizes', params.sizes.join(','));
  if (params.on_sale) query.set('on_sale', '1');
  if (params.is_featured) query.set('is_featured', '1');
  if (params.flash_deals) query.set('flash_deals', '1');
  if (params.sort) query.set('sort', params.sort);
  if (params.page !== undefined) query.set('page', String(params.page));
  if (params.per_page !== undefined) query.set('per_page', String(params.per_page));

  const queryString = query.toString();
  return queryString ? `/products?${queryString}` : '/products';
}

/** A /products answer (data + meta) as items plus pagination. */
export function productListFrom(data: unknown, meta?: Partial<PaginationMeta> | null): ProductListResponse {
  const items = Array.isArray(data) ? (data as ApiProductSummary[]) : [];
  const pagination: PaginationMeta = meta
    ? {
        total: Number(meta.total ?? items.length),
        page: Number(meta.page ?? 1),
        per_page: Number(meta.per_page ?? 12),
        total_pages: Number(meta.total_pages ?? 1),
      }
    : { total: items.length, page: 1, per_page: 12, total_pages: 1 };
  return { items, pagination };
}

/** "Showing 13–24 of 43 products", for a page that has products. */
export function catalogShowingLabel(page: number, shown: number, total: number): string {
  const first = (page - 1) * PAGE_SIZE + 1;
  const last = first + shown - 1;
  const range = first === last ? `${first}` : `${first}–${last}`;
  return `Showing ${range} of ${total} ${total === 1 ? 'product' : 'products'}`;
}

/**
 * The price filter from what is typed in its two boxes (the higher number is taken as the maximum).
 * Null when nothing changes, so leaving a box untouched keeps the shopper's page, or when a box
 * doesn't hold a number.
 */
export function priceFilterFrom(
  minInput: string,
  maxInput: string,
  current: Pick<FilterState, 'minPrice' | 'maxPrice'>
): Pick<FilterState, 'minPrice' | 'maxPrice'> | null {
  const read = (text: string) => (text.trim() === '' ? null : Math.max(0, Number(text)));
  let minPrice = read(minInput);
  let maxPrice = read(maxInput);
  if ((minPrice !== null && Number.isNaN(minPrice)) || (maxPrice !== null && Number.isNaN(maxPrice))) return null;
  if (minPrice !== null && maxPrice !== null && minPrice > maxPrice) [minPrice, maxPrice] = [maxPrice, minPrice];
  if (minPrice === current.minPrice && maxPrice === current.maxPrice) return null;
  return { minPrice, maxPrice };
}

export function hasActiveFilters(f: FilterState): boolean {
  return Boolean(
    f.category || f.dressStyle || f.searchQuery.trim() || f.selectedColors.length || f.selectedSizes.length ||
      f.minPrice !== null || f.maxPrice !== null || f.onSale || f.flashDeals || f.featured
  );
}
