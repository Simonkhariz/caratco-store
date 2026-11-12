/**
 * What kind of shop this copy is: its name, colours, the names of the two product options and the
 * wording that changes from one business to the next. Everything comes from ONE file,
 * backend/config/shop.json, which the API reads too (App\Config\Shop), so the storefront, the
 * admin, the emails and the server messages always agree.
 *
 * To turn this project into another shop, edit that file. Nothing here needs to change.
 * This module has no other imports, so the plain Node tests can load it.
 */
import raw from '../backend/config/shop.json' with { type: 'json' };

export interface ShopOption {
  /** Shown beside the choice: "Size", "Volume", "Length". */
  label: string;
  plural: string;
  /** The value that means "this product does not use this option". Never shown to anyone. */
  none: string;
}

export interface ShopConfig {
  key: string;
  name: string;
  /** "online clothing store", "online perfume store": used in a sentence about the shop. */
  kind: string;
  title: string;
  description: string;
  tagline: string;
  hero: { title: string; subtitle: string; cta: string; image: string; cutout: boolean };
  theme: { accent: string; accentHover: string; accentInk: string; surface: string; card: string; hero: string };
  option1: ShopOption & {
    choices: string[];
    /** One quiet line under the option-1 picker on the product page ('' for none). */
    help: string;
    /** The same line for the products of one category, by category slug: a ring size tip belongs under rings, not necklaces. */
    helpByCategory: Record<string, string>;
  };
  option2: ShopOption & { swatch: boolean; placeholder: string };
  style: {
    enabled: boolean;
    /** The filter's full name: "Dress style", "Scent family", "Brand". */
    label: string;
    plural: string;
    /** The short name beside a value ("Style: Casual"). The label when left out. */
    short: string;
    /** The catalogue heading for one style, with {value} where its name goes ("{value} style"). The value alone when left out. */
    heading: string;
    browseTitle: string;
    values: string[];
    /** Home page tile pictures, keyed by the style's name in lower case. */
    images: Record<string, string>;
  };
  specs: { label: string; suggested: string[] };
  features: { warranty: boolean; heavyDelivery: boolean; ageCheck: boolean };
  ageCheck: { minAge: number; categories: string[] };
  wording: {
    featured: string;
    newArrivals: string;
    topSelling: string;
    categoriesEyebrow: string;
    categoriesTitle: string;
    categoriesCta: string;
    emptyCart: string;
    contactIntro: string;
    /** The tip under a search that found nothing: try "shirt" instead of "blue cotton shirt". Empty leaves the example out. */
    searchExampleShort: string;
    searchExampleLong: string;
    descriptionHint: string;
    categoryHint: string;
  };
}

const given = raw as ShopConfig;

// The keys added after the first shop.json was written have a fallback, so a shop file that
// lacks them still reads well.
export const SHOP: ShopConfig = {
  ...given,
  option1: { ...given.option1, helpByCategory: given.option1.helpByCategory ?? {} },
  style: {
    ...given.style,
    short: given.style.short || given.style.label,
    // A pattern without {value} would name no style at all: the value alone is shown instead.
    heading: given.style.heading?.includes('{value}') ? given.style.heading : '{value}',
  },
  wording: {
    ...given.wording,
    searchExampleShort: given.wording.searchExampleShort ?? '',
    searchExampleLong: given.wording.searchExampleLong ?? '',
  },
};

const same = (a: string | null | undefined, b: string) => (a ?? '').trim().toLowerCase() === b.trim().toLowerCase();

/** True when a product really uses the first option (its value is not the "none" word). */
export function hasOption1(value: string | null | undefined): value is string {
  return Boolean(value && value.trim()) && !same(value, SHOP.option1.none);
}

/** True when a product really uses the second option. */
export function hasOption2(value: string | null | undefined): value is string {
  return Boolean(value && value.trim()) && !same(value, SHOP.option2.none);
}

/** "M / Navy", "50ml", "" : the chosen options of one item, leaving out any the product does not use. */
export function optionsText(option1: string | null | undefined, option2: string | null | undefined, separator = ' / '): string {
  return [hasOption1(option1) ? option1.trim() : '', hasOption2(option2) ? option2.trim() : ''].filter(Boolean).join(separator);
}

/**
 * The one option that tells two lines of the same product apart where there is room for one:
 * option 1 ("M"), or option 2 for a product that only uses that ("Vanilla"). '' with neither.
 */
export function mainOption(option1: string | null | undefined, option2: string | null | undefined): string {
  return hasOption1(option1) ? option1.trim() : hasOption2(option2) ? option2.trim() : '';
}

/** "Classic Tee (M / Navy)", or just the name when there are no options to add. */
export function nameWithOptions(name: string, options: string): string {
  return options ? `${name} (${options})` : name;
}

/**
 * Catalogue filters as this shop offers them: no style filter when it is switched off, and never
 * the "none" values (an old or hand-typed link must not filter by something no screen shows).
 */
export function shopFilters<T extends { dressStyle: string | null; selectedSizes: string[]; selectedColors: string[] }>(filters: T): T {
  return {
    ...filters,
    dressStyle: SHOP.style.enabled ? filters.dressStyle : null,
    selectedSizes: filters.selectedSizes.filter((size) => hasOption1(size)),
    selectedColors: filters.selectedColors.filter((color) => hasOption2(color)),
  };
}

/**
 * The tip under the option-1 picker on a product's page: the one for its category when the shop
 * wrote one (option1.helpByCategory, by category slug), otherwise the shop's general tip, otherwise
 * ''. A category given an empty text shows no tip at all.
 */
export function option1HelpFor(
  categorySlug: string | null | undefined,
  option1: Pick<ShopConfig['option1'], 'help' | 'helpByCategory'> = SHOP.option1
): string {
  const slug = (categorySlug ?? '').trim().toLowerCase();
  const own = slug ? Object.entries(option1.helpByCategory ?? {}).find(([key]) => key.trim().toLowerCase() === slug) : undefined;
  return (own ? own[1] : option1.help) ?? '';
}

/**
 * A style as the shop wrote it ("LG", "Eau de Parfum"). A link carries the style in lower case;
 * people are shown the stored spelling: the one in `known` (the catalogue's own list) or in the
 * shop's settings, and the text as it came when neither has it.
 */
export function styleName(value: string | null | undefined, known: readonly string[] = []): string {
  const wanted = (value ?? '').trim();
  const match = [...known, ...SHOP.style.values].find((name) => name.trim().toLowerCase() === wanted.toLowerCase());
  return match?.trim() || wanted;
}

/** The words of the catalogue heading for a style, before and after the style's name: ['', ' style']. */
export function styleHeadingParts(): [string, string] {
  const [before, ...after] = SHOP.style.heading.split('{value}');
  return [before, after.join('')];
}

/** The catalogue heading for one style, from the shop's pattern: "Casual style", "Gifts for Her", or the value alone. */
export function styleHeading(value: string): string {
  const [before, after] = styleHeadingParts();
  return `${before}${value}${after}`;
}

/** Lower case for the middle of a sentence: "Choose a size", "in this colour". */
export const option1Word = SHOP.option1.label.toLowerCase();
export const option2Word = SHOP.option2.label.toLowerCase();
/** "filters for category, colour, size, style and price". */
export const styleWord = SHOP.style.short.toLowerCase();

/** "a size", "an option": the label with its article. */
export function withArticle(word: string): string {
  return `${/^[aeiou]/i.test(word) ? 'an' : 'a'} ${word}`;
}

/** File-name friendly store name: "SHOP.CO" becomes "SHOPCO". */
export const SHOP_FILE_NAME = SHOP.name.replace(/[^A-Za-z0-9]+/g, '').toUpperCase() || 'STORE';
