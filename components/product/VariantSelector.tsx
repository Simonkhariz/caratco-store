'use client';

import React, { useId } from 'react';
import { ProductColor } from '@/types';
import { visibleSizes, type OptionState } from '@/lib/variants';
import { SHOP, hasOption1, hasOption2, option2Word } from '@/lib/shop';
import { useHydrated } from '@/lib/use-hydrated';
import { HugeiconsIcon } from '@hugeicons/react';
import {
  Tick02Icon,
  MinusSignIcon,
  PlusSignIcon,
  ShoppingBag01Icon,
  FireIcon,
  FavouriteIcon,
} from '@hugeicons/core-free-icons';

export type { OptionState };

interface VariantSelectorProps {
  colors: ProductColor[];
  sizes: string[];
  selectedColor: ProductColor | null;
  onSelectColor: (color: ProductColor) => void;
  selectedSize: string | null;
  onSelectSize: (size: string) => void;
  /** Availability of each size for the selected colour. */
  sizeStates: Record<string, OptionState>;
  /** Whether each colour has any size in stock. */
  colorInStock: Record<string, boolean>;
  quantity: number;
  maxQuantity: number;
  onQuantityChange: (q: number) => void;
  canPurchase: boolean;
  /** Short stock line under the options, e.g. "Only 3 left" or "Sold out in this size". */
  stockMessage?: string | null;
  /** One quiet line under the option-1 picker (a ring size tip, say); the product page passes the shop's. */
  option1Help?: string;
  onAddToCart: () => void;
  onBuyNow: () => void;
  isWishlisted?: boolean;
  onToggleWishlist?: () => void;
}


/** Next radio index for arrow / Home / End keys, skipping options that cannot be chosen. */
function nextIndex(e: React.KeyboardEvent, index: number, length: number, selectable: (i: number) => boolean): number | null {
  const step = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0;
  if (e.key === 'Home' || e.key === 'End') {
    const order = Array.from({ length }, (_, i) => (e.key === 'Home' ? i : length - 1 - i));
    return order.find(selectable) ?? null;
  }
  if (step === 0) return null;
  for (let n = 1; n <= length; n++) {
    const i = (index + step * n + length * n) % length;
    if (selectable(i)) return i;
  }
  return null;
}

/** Move focus to the radio at position `index` within the same group. */
function focusSibling(current: HTMLElement, index: number) {
  const radios = current.parentElement?.querySelectorAll<HTMLElement>('[role="radio"]');
  radios?.[index]?.focus();
}

export const VariantSelector: React.FC<VariantSelectorProps> = ({
  colors,
  sizes,
  selectedColor,
  onSelectColor,
  selectedSize,
  onSelectSize,
  sizeStates,
  colorInStock,
  quantity,
  maxQuantity,
  onQuantityChange,
  canPurchase,
  stockMessage,
  option1Help,
  onAddToCart,
  onBuyNow,
  isWishlisted = false,
  onToggleWishlist,
}) => {
  // Unique per selector: the product page and a quick-add panel can both be on screen.
  const uid = useId();
  const ready = useHydrated();
  const shownSizes = visibleSizes(sizes, sizeStates);
  // An option the product does not use holds only the shop's "none" value: it gets no picker.
  const usesOption1 = sizes.some((size) => hasOption1(size));
  const usesOption2 = colors.some((color) => hasOption2(color.name));
  return (
    <div className="space-y-6">
      {/* 1. Option 2, a colour in the template (radio group: arrow keys move and select). Round
          colour dots when the shop's option is a colour, text chips like option 1 when it is not. */}
      {usesOption2 && (
        <div className="pb-6 border-b border-neutral-200">
          <p id={`${uid}-color`} className="text-xs font-bold text-neutral-600 uppercase tracking-wider block mb-3">
            Select {SHOP.option2.label}: <span className="text-black font-bold normal-case">{selectedColor?.name ?? '—'}</span>
          </p>
          <div
            role="radiogroup"
            aria-labelledby={`${uid}-color`}
            className={SHOP.option2.swatch ? 'flex flex-wrap items-center gap-3' : 'flex flex-wrap gap-2.5 sm:gap-3'}
          >
            {colors.map((color, index) => {
              const isSelected = selectedColor?.name === color.name;
              const isLight = ['#ffffff', '#fff', '#fafafa', '#f5f5f5'].includes(color.hex.toLowerCase());
              const inStock = colorInStock[color.name] !== false;
              const focusable = isSelected || (!selectedColor && index === 0);
              const radio = {
                type: 'button' as const,
                role: 'radio',
                'aria-checked': isSelected,
                tabIndex: focusable ? 0 : -1,
                onClick: () => onSelectColor(color),
                onKeyDown: (e: React.KeyboardEvent<HTMLButtonElement>) => {
                  const next = nextIndex(e, index, colors.length, () => true);
                  if (next === null) return;
                  e.preventDefault();
                  onSelectColor(colors[next]);
                  focusSibling(e.currentTarget, next);
                },
                title: inStock ? color.name : `${color.name} (sold out)`,
                'aria-label': `${color.name}${inStock ? '' : ', sold out'}`,
              };
              if (!SHOP.option2.swatch) {
                return (
                  <button
                    key={color.name}
                    {...radio}
                    className={`px-6 py-3 rounded-full text-xs sm:text-sm font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 ${
                      isSelected ? 'bg-accent text-white shadow-sm' : 'bg-surface text-neutral-700 hover:bg-neutral-200'
                    } ${inStock ? '' : 'line-through opacity-60'}`}
                  >
                    {color.name}
                  </button>
                );
              }
              return (
                <button
                  key={color.name}
                  {...radio}
                  style={{ backgroundColor: color.hex }}
                  className={`relative w-10 h-10 rounded-full flex items-center justify-center transition-transform hover:scale-105 shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 ${
                    isLight ? 'border border-neutral-300' : ''
                  } ${isSelected ? 'ring-2 ring-accent ring-offset-2' : ''} ${inStock ? '' : 'opacity-50'}`}
                >
                  {isSelected && (
                    <HugeiconsIcon icon={Tick02Icon} size={16} className={isLight ? 'text-black' : 'text-white'} aria-hidden="true" />
                  )}
                  {!inStock && (
                    <span aria-hidden="true" className="absolute inset-0 flex items-center justify-center">
                      <span className="block w-full h-0.5 bg-neutral-500 rotate-45" />
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        </div>
      )}

      {/* 2. Option 1, a size in the template (radio group; only the values in stock in the chosen
          option 2 are shown) */}
      {usesOption1 && (
        <div className="pb-6 border-b border-neutral-200">
          <p id={`${uid}-size`} className="text-xs font-bold text-neutral-600 uppercase tracking-wider block mb-3">
            Choose {SHOP.option1.label}: <span className="text-black font-bold normal-case">{selectedSize && shownSizes.includes(selectedSize) ? selectedSize : '—'}</span>
          </p>
          {shownSizes.length === 0 ? (
            <p role="status" className="text-sm font-semibold text-red-600">
              Out of stock{usesOption2 && colors.length > 1 ? ` in this ${option2Word}` : ''}
            </p>
          ) : (
            <div role="radiogroup" aria-labelledby={`${uid}-size`} className="flex flex-wrap gap-2.5 sm:gap-3">
              {shownSizes.map((size, index) => {
                const isSelected = selectedSize === size;
                const focusable = isSelected || (!shownSizes.some((x) => x === selectedSize) && index === 0);
                return (
                  <button
                    key={size}
                    type="button"
                    role="radio"
                    aria-checked={isSelected}
                    tabIndex={focusable ? 0 : -1}
                    onClick={() => onSelectSize(size)}
                    onKeyDown={(e) => {
                      const next = nextIndex(e, index, shownSizes.length, () => true);
                      if (next === null) return;
                      e.preventDefault();
                      onSelectSize(shownSizes[next]);
                      focusSibling(e.currentTarget, next);
                    }}
                    className={`px-6 py-3 rounded-full text-xs sm:text-sm font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 ${
                      isSelected ? 'bg-accent text-white shadow-sm' : 'bg-surface text-neutral-700 hover:bg-neutral-200'
                    }`}
                  >
                    {size}
                  </button>
                );
              })}
            </div>
          )}
          {option1Help && <p className="mt-3 text-xs text-neutral-600">{option1Help}</p>}
        </div>
      )}

      {stockMessage && (
        <p
          role="status"
          className={`text-sm font-medium ${canPurchase ? 'text-amber-700' : 'text-red-600'}`}
        >
          {stockMessage}
        </p>
      )}

      {/* 3. Quantity Stepper & Desktop Action CTAs (data-main-purchase: the phone bar hides while this shows) */}
      <div data-main-purchase className="space-y-3 pt-2">
        <div className="flex items-center gap-3 sm:gap-4">
          <div className="flex items-center bg-surface rounded-full p-1.5 gap-1 shrink-0">
            <button
              type="button"
              onClick={() => onQuantityChange(Math.max(1, quantity - 1))}
              disabled={!canPurchase || quantity <= 1}
              className="text-black hover:bg-black/5 rounded-full p-3 disabled:opacity-30"
              aria-label="Decrease quantity"
            >
              <HugeiconsIcon icon={MinusSignIcon} size={16} />
            </button>
            <span className="font-bold text-sm text-black min-w-[1.2rem] text-center" aria-live="polite">
              {quantity}
            </span>
            <button
              type="button"
              onClick={() => onQuantityChange(Math.min(maxQuantity, quantity + 1))}
              disabled={!canPurchase || quantity >= maxQuantity}
              className="text-black hover:bg-black/5 rounded-full p-3 disabled:opacity-30"
              aria-label="Increase quantity"
            >
              <HugeiconsIcon icon={PlusSignIcon} size={16} />
            </button>
          </div>

          <button
            type="button"
            onClick={onAddToCart}
            disabled={!canPurchase || !ready}
            className="flex-1 bg-accent text-white hover:bg-accent-hover font-semibold py-4 rounded-full transition-all shadow-lg flex items-center justify-center gap-2 text-sm sm:text-base disabled:bg-neutral-300 disabled:text-neutral-700 disabled:shadow-none disabled:cursor-not-allowed"
          >
            <HugeiconsIcon icon={ShoppingBag01Icon} size={20} />
            <span>{canPurchase ? 'Add to Cart' : 'Unavailable'}</span>
          </button>

          {onToggleWishlist && (
            <button
              type="button"
              onClick={onToggleWishlist}
              className={`p-4 rounded-full border transition-all shrink-0 ${
                isWishlisted
                  ? 'border-red-200 bg-red-50 text-red-500 shadow-sm'
                  : 'border-neutral-300 hover:bg-neutral-50 text-neutral-600 hover:text-black'
              }`}
              title={isWishlisted ? 'Remove from wishlist' : 'Save to wishlist'}
              aria-label={isWishlisted ? 'Remove from wishlist' : 'Save to wishlist'}
              aria-pressed={isWishlisted}
            >
              <HugeiconsIcon icon={FavouriteIcon} size={20} className={isWishlisted ? 'fill-current text-red-500' : ''} />
            </button>
          )}
        </div>

        <button
          type="button"
          onClick={onBuyNow}
          disabled={!canPurchase || !ready}
          className="w-full bg-surface hover:bg-neutral-200 text-black font-semibold py-3.5 rounded-full transition-colors flex items-center justify-center gap-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <HugeiconsIcon icon={FireIcon} size={16} className="text-red-500 fill-current" />
          <span>Buy Now</span>
        </button>
      </div>
    </div>
  );
};
