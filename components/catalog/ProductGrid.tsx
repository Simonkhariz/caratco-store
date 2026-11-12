import React from 'react';
import { Product, FilterState } from '@/types';
import { ProductCard } from '@/components/ui/ProductCard';
import { formatPrice } from '@/lib/currency';
import { catalogShowingLabel, EMPTY_FILTERS, hasActiveFilters } from '@/lib/catalog-query';
import { SHOP } from '@/lib/shop';
import { HugeiconsIcon } from '@hugeicons/react';
import {
  SlidersHorizontalIcon,
  ArrowDown01Icon,
  ArrowLeft01Icon,
  ArrowRight01Icon,
  Cancel01Icon,
  Alert02Icon,
  RefreshIcon,
} from '@hugeicons/core-free-icons';

interface ProductGridProps {
  products: Product[];
  totalCount: number;
  categoryTitle: React.ReactNode;
  /** The chosen style as the shop wrote it (the filters hold the address form, in lower case). */
  styleLabel?: string | null;
  filters: FilterState;
  onFilterChange: (newFilters: Partial<FilterState>) => void;
  onReset: () => void;
  onOpenMobileFilters: () => void;
  loading: boolean;
  error: string | null;
  onRetry: () => void;
  onPageChange: (page: number) => void;
  totalPages: number;
}

/** Page numbers with gaps, e.g. 1 … 4 5 6 … 12. */
function pageItems(current: number, total: number): Array<number | 'gap'> {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
  const pages = new Set([1, total, current - 1, current, current + 1].filter((p) => p >= 1 && p <= total));
  const sorted = Array.from(pages).sort((a, b) => a - b);
  const out: Array<number | 'gap'> = [];
  sorted.forEach((p, i) => {
    if (i > 0 && p - sorted[i - 1] > 1) out.push('gap');
    out.push(p);
  });
  return out;
}

function Chip({ label, onRemove }: { label: string; onRemove: () => void }) {
  return (
    <button
      type="button"
      onClick={onRemove}
      className="inline-flex items-center gap-1.5 px-3 py-1 bg-neutral-100 text-neutral-900 text-xs rounded-full font-medium hover:bg-neutral-200 transition-colors"
      aria-label={`Remove filter: ${label}`}
    >
      <span>{label}</span>
      <HugeiconsIcon icon={Cancel01Icon} size={12} aria-hidden="true" />
    </button>
  );
}

export const ProductGrid: React.FC<ProductGridProps> = ({
  products,
  totalCount,
  categoryTitle,
  styleLabel,
  filters,
  onFilterChange,
  onReset,
  onOpenMobileFilters,
  loading,
  error,
  onRetry,
  onPageChange,
  totalPages,
}) => {
  const currentPage = Math.min(filters.page, Math.max(1, totalPages));
  const active = hasActiveFilters(filters);
  // For a search that found nothing: the same search with every filter cleared.
  const searchOnlyFilters = { ...EMPTY_FILTERS, sortBy: filters.sortBy };
  const otherFiltersActive = hasActiveFilters({ ...filters, searchQuery: '' });
  // An address past the last page (?page=99): the products exist, just not on this page.
  const pastLastPage = !loading && !error && products.length === 0 && totalCount > 0;

  return (
    <div className="w-full flex-1 min-w-0">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="font-integral text-2xl sm:text-3xl text-black capitalize">{categoryTitle}</h1>
          <p className="text-sm text-neutral-700 mt-1" aria-live="polite">
            {loading
              ? 'Loading products…'
              : products.length > 0
                ? catalogShowingLabel(currentPage, products.length, totalCount)
                : pastLastPage
                  ? `Page ${filters.page} not found`
                  : 'No products'}
          </p>
        </div>

        <div className="flex items-center gap-3 self-end sm:self-auto">
          <button
            type="button"
            onClick={onOpenMobileFilters}
            className="lg:hidden p-2.5 bg-surface rounded-full text-black hover:bg-neutral-200 transition-colors flex items-center justify-center"
            aria-label="Open filters"
          >
            <HugeiconsIcon icon={SlidersHorizontalIcon} size={20} aria-hidden="true" />
          </button>

          <div className="relative flex items-center text-sm text-neutral-700">
            <label htmlFor="catalog-sort" className="sr-only sm:not-sr-only sm:mr-1">Sort by:</label>
            <div className="relative inline-block">
              <select
                id="catalog-sort"
                value={filters.sortBy}
                onChange={(e) => onFilterChange({ sortBy: e.target.value as FilterState['sortBy'] })}
                className="appearance-none bg-transparent font-semibold text-black pr-6 py-1 cursor-pointer focus:outline-none focus:ring-2 focus:ring-accent rounded"
              >
                <option value="most-popular">Best selling</option>
                <option value="newest">Newest</option>
                <option value="price-low-high">Price: Low to High</option>
                <option value="price-high-low">Price: High to Low</option>
                <option value="rating">Top Rated</option>
              </select>
              <div className="absolute right-0 top-1/2 -translate-y-1/2 pointer-events-none text-black">
                <HugeiconsIcon icon={ArrowDown01Icon} size={14} />
              </div>
            </div>
          </div>
        </div>
      </div>

      {active && (
        <div className="flex flex-wrap items-center gap-2 mb-6">
          <span className="text-xs font-semibold text-neutral-700 mr-1">Active:</span>
          {filters.category && <Chip label={filters.category.replace(/-/g, ' ')} onRemove={() => onFilterChange({ category: null })} />}
          {/* The shop's own names for the style filter and the two options. The filters arrive
              without a switched-off style or a "none" value (shopFilters in lib/shop.ts). */}
          {filters.dressStyle && <Chip label={`${SHOP.style.short}: ${styleLabel || filters.dressStyle}`} onRemove={() => onFilterChange({ dressStyle: null })} />}
          {filters.selectedColors.map((color) => (
            <Chip key={color} label={`${SHOP.option2.label}: ${color}`} onRemove={() => onFilterChange({ selectedColors: filters.selectedColors.filter((c) => c !== color) })} />
          ))}
          {filters.selectedSizes.map((size) => (
            <Chip key={size} label={`${SHOP.option1.label}: ${size}`} onRemove={() => onFilterChange({ selectedSizes: filters.selectedSizes.filter((s) => s !== size) })} />
          ))}
          {filters.minPrice !== null && <Chip label={`From ${formatPrice(filters.minPrice)}`} onRemove={() => onFilterChange({ minPrice: null })} />}
          {filters.maxPrice !== null && <Chip label={`Up to ${formatPrice(filters.maxPrice)}`} onRemove={() => onFilterChange({ maxPrice: null })} />}
          {filters.onSale && <Chip label="On sale" onRemove={() => onFilterChange({ onSale: false })} />}
          {filters.flashDeals && <Chip label="Flash deals" onRemove={() => onFilterChange({ flashDeals: false })} />}
          {filters.featured && <Chip label="Featured" onRemove={() => onFilterChange({ featured: false })} />}
          {filters.searchQuery.trim() && <Chip label={`“${filters.searchQuery}”`} onRemove={() => onFilterChange({ searchQuery: '' })} />}
          <button type="button" onClick={onReset} className="text-xs text-red-700 hover:text-red-800 font-bold underline ml-1">
            Clear all
          </button>
        </div>
      )}

      {error && (
        <div role="alert" className="bg-red-50 border border-red-200 rounded-3xl p-8 text-center my-6">
          <div className="w-12 h-12 bg-red-100 text-red-700 rounded-full flex items-center justify-center mx-auto mb-3" aria-hidden="true">
            <HugeiconsIcon icon={Alert02Icon} size={24} />
          </div>
          <h2 className="font-bold text-lg text-red-900 mb-1">We couldn&apos;t load products</h2>
          <p className="text-sm text-red-800 max-w-md mx-auto mb-5">{error}</p>
          <button
            type="button"
            onClick={onRetry}
            className="inline-flex items-center gap-2 bg-red-700 hover:bg-red-800 text-white px-6 py-2.5 rounded-full text-sm font-semibold"
          >
            <HugeiconsIcon icon={RefreshIcon} size={16} aria-hidden="true" />
            <span>Try again</span>
          </button>
        </div>
      )}

      {loading && (
        <div className="grid grid-cols-2 md:grid-cols-3 gap-4 sm:gap-6 my-2" aria-hidden="true">
          {[1, 2, 3, 4, 5, 6].map((i) => (
            <div key={i} className="space-y-3 animate-pulse bg-white p-2 rounded-2xl border border-neutral-100">
              <div className="aspect-square bg-neutral-200 rounded-2xl w-full" />
              <div className="h-4 bg-neutral-200 rounded w-3/4" />
              <div className="h-3 bg-neutral-200 rounded w-1/2" />
              <div className="h-5 bg-neutral-200 rounded w-1/3" />
            </div>
          ))}
        </div>
      )}

      {!loading && !error && products.length > 0 && (
        <div className="grid grid-cols-2 md:grid-cols-3 gap-4 sm:gap-6">
          {products.map((product, index) => (
            // The first row (two cards on phones) is what the shopper sees first.
            <ProductCard key={product.id} product={product} preload={index < 2} columns={3} />
          ))}
        </div>
      )}

      {pastLastPage && (
        <div className="bg-neutral-50 rounded-3xl p-12 text-center my-8">
          <h2 className="font-bold text-lg text-black mb-2">This page doesn&apos;t exist</h2>
          <p className="text-sm text-neutral-700 max-w-sm mx-auto mb-6">
            {totalPages === 1 ? 'These products fit on one page.' : `These products end on page ${totalPages}.`}
          </p>
          <button type="button" onClick={() => onPageChange(totalPages)} className="bg-accent text-accent-ink px-6 py-2.5 rounded-full text-sm font-semibold hover:bg-accent-hover">
            Go to the last page
          </button>
        </div>
      )}

      {!loading && !error && products.length === 0 && !pastLastPage && filters.searchQuery.trim() !== '' && (
        // A search that found nothing gets its own words: it is about the search, not the filters.
        <div className="bg-neutral-50 rounded-3xl p-8 sm:p-12 text-center my-8">
          <h2 className="font-bold text-lg text-black mb-2 break-words">No results for &ldquo;{filters.searchQuery.trim()}&rdquo;</h2>
          <p className="text-sm text-neutral-700 max-w-sm mx-auto mb-6">
            Check the spelling, or try a shorter or more general word
            {/* The example is the shop's own (a perfume shop does not suggest "shirt"); none set, none shown. */}
            {SHOP.wording.searchExampleShort && SHOP.wording.searchExampleLong && (
              <> (for example &ldquo;{SHOP.wording.searchExampleShort}&rdquo; instead of &ldquo;{SHOP.wording.searchExampleLong}&rdquo;)</>
            )}
            .{otherFiltersActive && ' Your filters are also narrowing the search.'}
          </p>
          <div className="flex flex-wrap items-center justify-center gap-3">
            {otherFiltersActive && (
              <button
                type="button"
                onClick={() => onFilterChange({ ...searchOnlyFilters, searchQuery: filters.searchQuery })}
                className="bg-accent text-accent-ink px-6 py-2.5 rounded-full text-sm font-semibold hover:bg-accent-hover"
              >
                Search without filters
              </button>
            )}
            <button
              type="button"
              onClick={onReset}
              className={
                otherFiltersActive
                  ? 'border border-neutral-300 text-black px-6 py-2.5 rounded-full text-sm font-semibold hover:bg-neutral-100'
                  : 'bg-accent text-accent-ink px-6 py-2.5 rounded-full text-sm font-semibold hover:bg-accent-hover'
              }
            >
              Browse all products
            </button>
          </div>
        </div>
      )}

      {!loading && !error && products.length === 0 && !pastLastPage && filters.searchQuery.trim() === '' && (
        <div className="bg-neutral-50 rounded-3xl p-12 text-center my-8">
          <h2 className="font-bold text-lg text-black mb-2">No matching products</h2>
          <p className="text-sm text-neutral-700 max-w-sm mx-auto mb-6">
            {active ? 'Try removing a filter or widening the price range.' : 'There are no products to show yet.'}
          </p>
          {active && (
            <button type="button" onClick={onReset} className="bg-accent text-accent-ink px-6 py-2.5 rounded-full text-sm font-semibold hover:bg-accent-hover">
              Clear all filters
            </button>
          )}
        </div>
      )}

      {totalPages > 1 && !error && !pastLastPage && (
        <nav aria-label="Pagination" className="mt-12 pt-6 border-t border-neutral-200 flex items-center justify-between gap-1 sm:gap-2 text-sm font-semibold text-neutral-800">
          <button
            type="button"
            disabled={currentPage === 1}
            onClick={() => onPageChange(currentPage - 1)}
            aria-label="Previous page"
            className="flex shrink-0 items-center gap-2 px-2 sm:px-4 py-2 border border-neutral-200 rounded-lg hover:bg-neutral-50 disabled:opacity-40"
          >
            <HugeiconsIcon icon={ArrowLeft01Icon} size={16} aria-hidden="true" />
            <span className="hidden sm:inline">Previous</span>
          </button>

          <ol className="flex min-w-0 flex-wrap items-center justify-center gap-0.5 sm:gap-1">
            {pageItems(currentPage, totalPages).map((item, idx) =>
              item === 'gap' ? (
                <li key={`gap-${idx}`} className="w-4 sm:w-6 text-center text-neutral-500" aria-hidden="true">…</li>
              ) : (
                <li key={item}>
                  <button
                    type="button"
                    onClick={() => onPageChange(item)}
                    aria-current={item === currentPage ? 'page' : undefined}
                    aria-label={`Page ${item}`}
                    className={`w-8 h-8 sm:w-9 sm:h-9 rounded-lg flex items-center justify-center ${
                      item === currentPage ? 'bg-accent text-accent-ink' : 'text-neutral-700 hover:bg-neutral-100'
                    }`}
                  >
                    {item}
                  </button>
                </li>
              )
            )}
          </ol>

          <button
            type="button"
            disabled={currentPage === totalPages}
            onClick={() => onPageChange(currentPage + 1)}
            aria-label="Next page"
            className="flex shrink-0 items-center gap-2 px-2 sm:px-4 py-2 border border-neutral-200 rounded-lg hover:bg-neutral-50 disabled:opacity-40"
          >
            <span className="hidden sm:inline">Next</span>
            <HugeiconsIcon icon={ArrowRight01Icon} size={16} aria-hidden="true" />
          </button>
        </nav>
      )}
    </div>
  );
};
