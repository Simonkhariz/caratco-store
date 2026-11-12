// Plain text: the categories, styles and option values are whatever this shop sells
// (backend/config/shop.json names the options; the catalogue holds the values).
export type CategoryType = string;

export type DressStyleType = string;

export type SizeType = string;

export interface ProductColor {
  name: string;
  hex: string;
}

/** One row of a product's specifications ("Battery" / "5000 mAh"). */
export interface ProductSpec {
  name: string;
  value: string;
}

export interface Review {
  id: string;
  author: string;
  rating: number;
  verified: boolean;
  title?: string;
  comment: string;
  date: string;
}

/**
 * One purchasable combination of the two options, with its own price and stock. `size` is
 * option 1 and `color` option 2, whatever the shop calls them (lib/shop.ts).
 */
export interface ProductVariant {
  id: number;
  sku: string;
  size: string;
  color: ProductColor;
  price: number;
  stock: number;
}

export interface Product {
  id: string;
  name: string;
  slug: string;
  description: string;
  price: number;
  originalPrice?: number;
  discountPercent?: number;
  /** Average of approved reviews; 0 when there are none. */
  rating: number;
  reviewCount: number;
  category: CategoryType;
  categoryName?: string;
  dressStyle: DressStyleType | DressStyleType[];
  gender: 'men' | 'women' | 'unisex';
  images: string[];
  colors: ProductColor[];
  sizes: SizeType[];
  /** Present on product detail; summaries only carry colours/sizes. */
  variants?: ProductVariant[];
  totalStock?: number;
  inStock?: boolean;
  sku?: string;
  isNewArrival?: boolean;
  isTopSelling?: boolean;
  /** The owner's own rows for the product page (product detail only; empty in lists). */
  specifications?: ProductSpec[];
  /** Shown only when the shop has warranties switched on and the product has one. */
  warranty?: string;
  /** Counts towards a zone's large-item delivery fee. */
  isHeavy?: boolean;
  /** Its category is for adults only: checkout asks the buyer to confirm their age. */
  ageRestricted?: boolean;
  details: string[];
  faqs: { question: string; answer: string }[];
  reviews: Review[];
}

export interface CartItem {
  /** Unique line key: the variant ID as a string. */
  id: string;
  productId: string;
  /** Exact size + colour combination being bought (required at checkout). */
  variantId?: number;
  sku?: string;
  name: string;
  slug: string;
  /** Last known unit price (display only; the server always recalculates). */
  price: number;
  originalPrice?: number;
  image: string;
  size: SizeType;
  color: ProductColor;
  quantity: number;
  /** Last known stock for this variant, used to cap the quantity stepper. */
  maxStock?: number;
}

/** Catalogue filters. The page URL is the source of truth; this is its parsed form. */
export interface FilterState {
  category: string | null;
  dressStyle: string | null;
  /** null = no lower/upper price bound. */
  minPrice: number | null;
  maxPrice: number | null;
  selectedColors: string[];
  selectedSizes: string[];
  sortBy: 'most-popular' | 'price-low-high' | 'price-high-low' | 'newest' | 'rating';
  searchQuery: string;
  onSale: boolean;
  flashDeals: boolean;
  featured: boolean;
  page: number;
}

export interface PromoCode {
  code: string;
  description: string;
  /** Short wording from the server, e.g. "10% off (up to ₦10,000) on orders of ₦20,000+". */
  label: string;
  /** The code's rules, to preview the discount in the cart (the server decides at checkout). */
  rule: import('@/lib/coupon').CouponRule;
}
