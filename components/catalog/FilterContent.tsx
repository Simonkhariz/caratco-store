'use client';

import React, { useId, useState } from 'react';
import { FilterState } from '@/types';
import { CatalogFacets } from '@/lib/api-client';
import { priceFilterFrom } from '@/lib/catalog-query';
import { formatPrice } from '@/lib/currency';
import { SHOP, hasOption1, hasOption2 } from '@/lib/shop';
import { HugeiconsIcon } from '@hugeicons/react';
import { ArrowRight01Icon, ArrowUp01Icon, Tick02Icon, SlidersHorizontalIcon } from '@hugeicons/core-free-icons';

interface FilterContentProps {
  filters: FilterState;
  onFilterChange: (newFilters: Partial<FilterState>) => void;
  onReset?: () => void;
  /** Mobile drawer: show a "Show results" button that closes it. */
  onDone?: () => void;
  isMobile?: boolean;
  facets: CatalogFacets | null;
}

function Section({ title, children, defaultOpen = true }: { title: string; children: React.ReactNode; defaultOpen?: boolean }) {
  const [open, setOpen] = useState(defaultOpen);
  // Unique per copy: the desktop sidebar and the phone drawer can both be on the page.
  const id = `filter-${useId()}`;
  return (
    <div className="pb-6 border-b border-neutral-200">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        aria-controls={id}
        className="w-full flex items-center justify-between font-bold text-base mb-4"
      >
        <span>{title}</span>
        <HugeiconsIcon icon={ArrowUp01Icon} size={16} aria-hidden="true" className={`transition-transform duration-200 ${open ? '' : 'rotate-180'}`} />
      </button>
      {open && <div id={id}>{children}</div>}
    </div>
  );
}

const isLight = (hex: string) => ['#ffffff', '#fff', '#fafafa', '#f5f5f5', '#fffdd0'].includes(hex.toLowerCase());

/**
 * Catalogue filters. Every option comes from /catalog/facets, so only categories, colours,
 * sizes and styles that exist in the published catalogue are offered. The names of the two
 * options and of the style filter are the shop's own (lib/shop.ts); in the code they stay
 * `colors`, `sizes` and `dress_styles`.
 */
export const FilterContent: React.FC<FilterContentProps> = ({ filters, onFilterChange, onReset, onDone, isMobile = false, facets }) => {
  const [minInput, setMinInput] = useState(filters.minPrice?.toString() ?? '');
  const [maxInput, setMaxInput] = useState(filters.maxPrice?.toString() ?? '');
  const [focusedPrice, setFocusedPrice] = useState<'min' | 'max' | null>(null);

  // Keep the price inputs in step with the URL (Back/Forward, chip removal), except the box
  // being typed in: the address changing because the other box was left must not reset it.
  const appliedPrice = `${filters.minPrice ?? ''}|${filters.maxPrice ?? ''}`;
  const [inputsFor, setInputsFor] = useState(appliedPrice);
  if (appliedPrice !== inputsFor) {
    setInputsFor(appliedPrice);
    if (focusedPrice !== 'min') setMinInput(filters.minPrice?.toString() ?? '');
    if (focusedPrice !== 'max') setMaxInput(filters.maxPrice?.toString() ?? '');
  }

  const toggleIn = (list: string[], value: string) =>
    list.some((v) => v.toLowerCase() === value.toLowerCase())
      ? list.filter((v) => v.toLowerCase() !== value.toLowerCase())
      : [...list, value];

  const applyPrice = () => {
    const price = priceFilterFrom(minInput, maxInput, filters);
    if (!price) return;
    // The boxes show what was applied (a minimum above the maximum swaps them).
    setMinInput(price.minPrice?.toString() ?? '');
    setMaxInput(price.maxPrice?.toString() ?? '');
    onFilterChange(price);
  };

  // A form without a submit button doesn't submit on Enter when it has two boxes.
  const applyOnEnter = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    // Leaving the box applies the price (onBlur); a box left focused would ignore the Back button.
    e.currentTarget.blur();
  };

  const categories = facets?.categories ?? [];
  // The "none" value of an option a product does not use is never offered as a choice (the server
  // leaves it out too), and a shop with the style filter switched off has no style section.
  const colors = (facets?.colors ?? []).filter((col) => hasOption2(col.name));
  const sizes = (facets?.sizes ?? []).filter((size) => hasOption1(size));
  const styles = SHOP.style.enabled ? (facets?.dress_styles ?? []) : [];
  const chipClass = (isSelected: boolean) =>
    `px-4 py-2 rounded-full text-sm font-medium transition-colors ${
      isSelected ? 'bg-accent text-accent-ink' : 'bg-surface text-neutral-700 hover:bg-neutral-200'
    }`;

  return (
    <div className="space-y-6 text-black">
      {!isMobile && (
        <div className="flex items-center justify-between pb-4 border-b border-neutral-200">
          <div className="flex items-center gap-2">
            <HugeiconsIcon icon={SlidersHorizontalIcon} size={20} />
            <h2 className="font-bold text-lg text-black">Filters</h2>
          </div>
          {onReset && (
            <button type="button" onClick={onReset} className="text-sm text-neutral-700 hover:text-black underline">
              Reset all
            </button>
          )}
        </div>
      )}

      {!facets && <p className="text-sm text-neutral-600" role="status">Loading filters…</p>}

      {categories.length > 0 && (
        <fieldset className="space-y-1 pb-6 border-b border-neutral-200">
          <legend className="sr-only">Category</legend>
          {categories.map((cat) => {
            const isActive = filters.category?.toLowerCase() === cat.slug.toLowerCase();
            return (
              <button
                key={cat.slug}
                type="button"
                aria-pressed={isActive}
                onClick={() => onFilterChange({ category: isActive ? null : cat.slug })}
                className={`w-full flex items-center justify-between text-sm py-1.5 transition-colors ${
                  isActive ? 'font-bold text-black' : 'text-neutral-700 hover:text-black'
                }`}
              >
                <span>
                  {cat.name}
                  {typeof cat.product_count === 'number' && <span className="text-neutral-500 font-normal"> ({cat.product_count})</span>}
                </span>
                <HugeiconsIcon icon={ArrowRight01Icon} size={16} className={isActive ? 'text-black' : 'text-neutral-500'} aria-hidden="true" />
              </button>
            );
          })}
        </fieldset>
      )}

      <Section title="Price">
        <form onSubmit={(e) => e.preventDefault()} className="space-y-3" aria-label="Price range">
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label htmlFor={`min-price-${isMobile ? 'm' : 'd'}`} className="block text-xs font-semibold text-neutral-700 mb-1">Min (₦)</label>
              <input
                id={`min-price-${isMobile ? 'm' : 'd'}`}
                type="number"
                inputMode="numeric"
                min={0}
                step={500}
                placeholder={facets ? String(Math.floor(facets.price.min)) : '0'}
                value={minInput}
                onChange={(e) => setMinInput(e.target.value)}
                onKeyDown={applyOnEnter}
                onFocus={() => setFocusedPrice('min')}
                onBlur={() => {
                  setFocusedPrice(null);
                  applyPrice();
                }}
                className="w-full rounded-xl bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
              />
            </div>
            <div>
              <label htmlFor={`max-price-${isMobile ? 'm' : 'd'}`} className="block text-xs font-semibold text-neutral-700 mb-1">Max (₦)</label>
              <input
                id={`max-price-${isMobile ? 'm' : 'd'}`}
                type="number"
                inputMode="numeric"
                min={0}
                step={500}
                placeholder={facets ? String(Math.ceil(facets.price.max)) : 'Any'}
                value={maxInput}
                onChange={(e) => setMaxInput(e.target.value)}
                onKeyDown={applyOnEnter}
                onFocus={() => setFocusedPrice('max')}
                onBlur={() => {
                  setFocusedPrice(null);
                  applyPrice();
                }}
                className="w-full rounded-xl bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
              />
            </div>
          </div>
          {facets && facets.price.max > 0 && (
            <p className="text-xs text-neutral-600">
              Prices range from {formatPrice(facets.price.min)} to {formatPrice(facets.price.max)}.
            </p>
          )}
        </form>
      </Section>

      {colors.length > 0 && (
        <Section title={SHOP.option2.plural}>
          <fieldset className="flex flex-wrap gap-2">
            <legend className="sr-only">{SHOP.option2.plural}</legend>
            {colors.map((col) => {
              const isSelected = filters.selectedColors.some((c) => c.toLowerCase() === col.name.toLowerCase());
              const hex = col.hex || '#cccccc';
              // Not a colour in this shop (a scent, a flavour): a text chip like option 1, no dot.
              if (!SHOP.option2.swatch) {
                return (
                  <button
                    key={col.name}
                    type="button"
                    aria-pressed={isSelected}
                    onClick={() => onFilterChange({ selectedColors: toggleIn(filters.selectedColors, col.name) })}
                    className={chipClass(isSelected)}
                    aria-label={`${col.name} (${col.product_count})`}
                  >
                    {col.name}
                  </button>
                );
              }
              return (
                <button
                  key={col.name}
                  type="button"
                  aria-pressed={isSelected}
                  onClick={() => onFilterChange({ selectedColors: toggleIn(filters.selectedColors, col.name) })}
                  className={`inline-flex items-center gap-2 rounded-full border py-1.5 pl-1.5 pr-3 text-sm font-medium transition-colors ${
                    isSelected ? 'border-accent bg-accent text-accent-ink' : 'border-neutral-200 bg-white text-neutral-800 hover:border-neutral-400'
                  }`}
                  aria-label={`${col.name} (${col.product_count})`}
                >
                  <span
                    style={{ backgroundColor: hex }}
                    className={`flex size-6 shrink-0 items-center justify-center rounded-full ${isLight(hex) || isSelected ? 'ring-1 ring-inset ring-neutral-300' : ''}`}
                    aria-hidden="true"
                  >
                    {isSelected && <HugeiconsIcon icon={Tick02Icon} size={14} className={isLight(hex) ? 'text-black' : 'text-white'} />}
                  </span>
                  <span className="capitalize">{col.name}</span>
                </button>
              );
            })}
          </fieldset>
        </Section>
      )}

      {sizes.length > 0 && (
        <Section title={SHOP.option1.label}>
          <fieldset className="flex flex-wrap gap-2">
            <legend className="sr-only">{SHOP.option1.plural}</legend>
            {sizes.map((size) => {
              const isSelected = filters.selectedSizes.some((s) => s.toLowerCase() === size.toLowerCase());
              return (
                <button
                  key={size}
                  type="button"
                  aria-pressed={isSelected}
                  onClick={() => onFilterChange({ selectedSizes: toggleIn(filters.selectedSizes, size) })}
                  className={chipClass(isSelected)}
                >
                  {size}
                </button>
              );
            })}
          </fieldset>
        </Section>
      )}

      {styles.length > 0 && (
        <Section title={SHOP.style.label}>
          <fieldset className="space-y-1">
            <legend className="sr-only">{SHOP.style.label}</legend>
            {styles.map((style) => {
              const isActive = filters.dressStyle?.toLowerCase() === style.toLowerCase();
              return (
                <button
                  key={style}
                  type="button"
                  aria-pressed={isActive}
                  onClick={() => onFilterChange({ dressStyle: isActive ? null : style.toLowerCase() })}
                  className={`w-full flex items-center justify-between text-sm py-1.5 transition-colors ${
                    isActive ? 'font-bold text-black' : 'text-neutral-700 hover:text-black'
                  }`}
                >
                  <span>{style}</span>
                  <HugeiconsIcon icon={ArrowRight01Icon} size={16} className={isActive ? 'text-black' : 'text-neutral-500'} aria-hidden="true" />
                </button>
              );
            })}
          </fieldset>
        </Section>
      )}

      <fieldset className="space-y-2">
        <legend className="text-sm font-bold text-black mb-1">Offers</legend>
        <label className="flex items-center gap-2 text-sm text-neutral-800">
          <input type="checkbox" className="size-4 accent-accent" checked={filters.onSale} onChange={(e) => onFilterChange({ onSale: e.target.checked })} />
          On sale
        </label>
        <label className="flex items-center gap-2 text-sm text-neutral-800">
          <input type="checkbox" className="size-4 accent-accent" checked={filters.flashDeals} onChange={(e) => onFilterChange({ flashDeals: e.target.checked })} />
          Flash deals
        </label>
      </fieldset>

      {isMobile && onDone && (
        <button
          type="button"
          onClick={onDone}
          className="w-full bg-accent text-accent-ink hover:bg-accent-hover font-semibold py-3.5 rounded-full transition-colors shadow-md text-sm"
        >
          Show results
        </button>
      )}
    </div>
  );
};
