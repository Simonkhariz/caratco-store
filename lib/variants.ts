import type { Product, ProductColor, ProductVariant } from '@/types';
import { hasOption1, hasOption2, option1Word, option2Word, optionsText, withArticle } from '@/lib/shop';

// In the code a variant's `size` is option 1 and its `color` option 2, whatever the shop calls
// them ("Volume", "Scent"...). The words people read come from lib/shop.ts.

/** 'available' = in stock, 'sold-out' = offered but no stock, 'unavailable' = not offered in this colour. */
export type OptionState = 'available' | 'sold-out' | 'unavailable';

/** Start on the first option that is actually in stock (else the first option). */
export function firstChoice(product: Product | null | undefined): ProductVariant | undefined {
  const variants = product?.variants ?? [];
  return variants.find((v) => v.stock > 0) ?? variants[0];
}

/** The variant for a colour and size, if the product is made that way. */
export function findVariant(variants: ProductVariant[], color: ProductColor | null, size: string | null): ProductVariant | null {
  return variants.find((v) => v.color.name === color?.name && v.size === size) ?? null;
}

/** Availability of each size in the chosen colour. */
export function sizeStatesFor(sizes: string[], variants: ProductVariant[], color: ProductColor | null): Record<string, OptionState> {
  const states: Record<string, OptionState> = {};
  for (const size of sizes) {
    const v = variants.find((x) => x.size === size && x.color.name === color?.name);
    states[size] = !v ? 'unavailable' : v.stock > 0 ? 'available' : 'sold-out';
  }
  return states;
}

/** The sizes to offer: only those in stock in the chosen colour (sold-out and not-made sizes are left out). */
export function visibleSizes(sizes: string[], states: Record<string, OptionState>): string[] {
  return sizes.filter((size) => states[size] === 'available');
}

/** Whether each colour has any size in stock. */
export function colorStockFor(variants: ProductVariant[]): Record<string, boolean> {
  const map: Record<string, boolean> = {};
  for (const v of variants) map[v.color.name] = (map[v.color.name] ?? false) || v.stock > 0;
  return map;
}

/**
 * Which of the two options a product really uses. One that only ever holds the "none" value
 * ("Standard", "Default") gets no picker and is never named.
 */
export function usedOptions(product: Pick<Product, 'sizes' | 'colors'>): { option1: boolean; option2: boolean } {
  return {
    option1: product.sizes.some((size) => hasOption1(size)),
    option2: product.colors.some((color) => hasOption2(color.name)),
  };
}

/**
 * When the colour changes: keep the chosen size if that colour has it in stock. If it does not,
 * no size is chosen (the shopper picks again): swapping M for S behind their back would put the
 * wrong size in the cart. With no size chosen yet, a colour that comes in a single size takes it.
 */
export function sizeAfterColorChange(variants: ProductVariant[], color: ProductColor, size: string | null): string | null {
  const ofColor = variants.filter((v) => v.color.name === color.name);
  // A product that does not use option 1 has nothing to pick again: its one value stays chosen,
  // so a sold-out colour says "sold out" instead of asking for a choice that is not on the page.
  if (ofColor.length > 0 && ofColor.every((v) => !hasOption1(v.size))) return ofColor[0].size;
  const inStock = ofColor.filter((v) => v.stock > 0);
  if (inStock.some((v) => v.size === size)) return size;
  return size === null && inStock.length === 1 ? inStock[0].size : null;
}

/**
 * The chosen colour's name when nothing at all is in stock in it, else null. In that colour there
 * is no size left to choose, so the stock line says "sold out" instead of asking for a choice.
 */
export function soldOutColorName(variants: ProductVariant[], color: ProductColor | null): string | null {
  if (!color) return null;
  const ofColor = variants.filter((v) => v.color.name === color.name);
  return ofColor.length > 0 && ofColor.every((v) => v.stock <= 0) ? color.name : null;
}

/**
 * Short stock line under the options. It names only the options the product uses.
 * `soldOutColor` is soldOutColorName() for the chosen colour.
 */
export function stockMessageFor(variant: ProductVariant | null, variantCount: number, soldOutColor: string | null = null): string | null {
  if (!variant) {
    if (variantCount === 0) return 'This product is not available to order right now.';
    // Nothing is chosen because nothing can be: every size of the chosen colour is sold out.
    if (soldOutColor !== null) return hasOption2(soldOutColor) ? `Sold out in this ${option2Word}.` : 'Sold out.';
    return `Choose ${withArticle(option1Word)} to continue.`;
  }
  const uses1 = hasOption1(variant.size);
  const uses2 = hasOption2(variant.color.name);
  if (variant.stock === 0) {
    const which = [uses2 ? option2Word : '', uses1 ? option1Word : ''].filter(Boolean).join(' and ');
    return which ? `Sold out in this ${which}.` : 'Sold out.';
  }
  if (variant.stock <= 5) {
    const which = uses1 ? option1Word : uses2 ? option2Word : '';
    return which ? `Only ${variant.stock} left in this ${which}.` : `Only ${variant.stock} left.`;
  }
  return null;
}

/**
 * The line above the price on the phone's purchase bar: the chosen options ("M • Navy"), or what
 * is still to choose ("Choose colour & size"). '' for a product with no options.
 */
export function selectionLabelFor(variant: ProductVariant | null, product: Pick<Product, 'sizes' | 'colors'>): string {
  if (variant) return optionsText(variant.size, variant.color.name, ' • ');
  const uses = usedOptions(product);
  const which = [uses.option2 ? option2Word : '', uses.option1 ? option1Word : ''].filter(Boolean).join(' & ');
  return which ? `Choose ${which}` : '';
}
