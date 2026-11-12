/**
 * Storefront API Client for Shop.co
 * Communicates with the PHP 8+ REST backend API running on XAMPP / local server.
 */

import type { Product, ProductColor, ProductSpec, ProductVariant, Review } from '@/types';
import { SHOP } from '@/lib/shop';
import { optimizeImageForUpload } from '@/lib/image-optimize';
import { productListFrom, productListPath } from '@/lib/catalog-query';
import { clearQueryCache } from '@/lib/query-client';

// Same-origin by default: next.config.mjs proxies /api/v1/* to the PHP backend so the
// HTTP-only session cookies work without exposing session tokens to JavaScript.
/** Neutral stand-in shown when a product has no photos yet. */
export const PRODUCT_IMAGE_PLACEHOLDER = '/images/placeholder-product.svg';

export const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || '/api/v1';

export interface ApiProductSummary {
  id: number;
  uuid: string;
  name: string;
  slug: string;
  description: string;
  product_type: 'physical';
  base_price: number;
  original_price: number | null;
  currency: string;
  sku: string;
  rating_avg: number;
  rating_count: number;
  is_featured: boolean;
  is_flash_deal: boolean;
  /** When the flash deal ends (null = no end date set). */
  flash_deal_ends_at?: string | null;
  dress_style: string | null;
  created_at: string;
  category_id: number;
  category_name: string;
  category_slug: string;
  primary_image: string | null;
  total_stock: number;
  in_stock: boolean;
  discount_percent: number;
  available_colors?: Array<{ name: string; hex: string }>;
  available_sizes?: string[];
  /** Worked out by the server from the product's category (the shop's age check). */
  age_restricted?: boolean;
}

export interface ApiProductImage {
  id: number;
  image_url: string;
  alt_text?: string;
  is_primary: number | boolean;
  display_order: number;
}

export interface ApiProductVariant {
  id: number;
  sku: string;
  size: string;
  color_name: string;
  color_hex: string;
  price_override: number | null;
  stock_quantity: number;
  low_stock_threshold?: number;
  in_stock: boolean;
}

export interface ApiProductReview {
  id: number;
  author: string;
  rating: number;
  title?: string | null;
  comment: string;
  verified: boolean;
  created_at: string;
}

export interface ApiProductDetail extends ApiProductSummary {
  status: string;
  updated_at: string;
  images: ApiProductImage[];
  variants: ApiProductVariant[];
  available_colors: Array<{ name: string; hex: string }>;
  available_sizes: string[];
  reviews?: ApiProductReview[];
  /** The owner's rows for the product page ([] when none). */
  specifications?: Array<{ name: string; value: string }> | null;
  warranty?: string | null;
  /** Counts towards a delivery zone's `heavy_item_fee`. */
  is_heavy?: boolean;
}

export interface CatalogFacets {
  categories: ApiCategory[];
  colors: Array<{ name: string; hex: string; product_count: number }>;
  sizes: string[];
  dress_styles: string[];
  price: { min: number; max: number };
}

export interface ApiCategory {
  id: number;
  name: string;
  slug: string;
  description: string;
  image_url: string;
  display_order?: number;
  product_count: number;
}

export interface ApiPromotion {
  code: string;
  description: string;
  discount_percent: number;
  discount_type?: 'percent' | 'fixed';
  discount_value?: number;
  min_order_amount?: number | null;
  max_discount_amount?: number | null;
  label?: string;
}

export interface ApiBanner {
  id: number;
  title: string;
  subtitle: string | null;
  cta_text: string | null;
  cta_url: string | null;
  image_url: string;
  display_order: number;
  is_active?: number | boolean;
}

export interface ApiReview {
  id: number;
  product_id: number;
  product_name?: string;
  author: string;
  rating: number;
  title?: string;
  comment: string;
  verified?: boolean | number;
  created_at: string;
}

export interface HomepageData {
  /** Real counts for the hero (no marketing numbers). */
  stats?: { published_products: number; categories: number; states_served: number };
  /** Soonest real end time among the flash deals shown; null = no countdown. */
  flash_deals_end_at?: string | null;
  featured_products: ApiProductSummary[];
  flash_deals: ApiProductSummary[];
  new_arrivals: ApiProductSummary[];
  top_selling: ApiProductSummary[];
  categories: ApiCategory[];
  promotions: ApiPromotion[];
  banners: ApiBanner[];
  reviews?: ApiReview[];
  /** Brands for the logo strip (admin Settings); empty hides it. */
  brands?: string[];
}

export interface PaginationMeta {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export interface ProductListResponse {
  items: ApiProductSummary[];
  pagination: PaginationMeta;
}

export interface ProductQueryParams {
  category?: string;
  search?: string;
  min_price?: number;
  max_price?: number;
  dress_style?: string;
  colors?: string[];
  sizes?: string[];
  on_sale?: boolean;
  is_featured?: boolean;
  flash_deals?: boolean;
  sort?: 'popularity' | 'price_asc' | 'price_desc' | 'latest' | 'rating_desc' | string;
  page?: number;
  per_page?: number;
}

export interface ApiResponse<T> {
  success: boolean;
  data?: T;
  meta?: {
    total: number;
    page: number;
    per_page: number;
    total_pages: number;
  };
  message?: string;
  error?: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
}

// Session tokens live only in HTTP-only cookies. JavaScript holds just the per-session
// CSRF tokens (in memory), one for the customer session and one for the admin session.
let memoryCsrfToken: string | null = null;
let memoryAdminCsrfToken: string | null = null;

export function setMemoryCsrfToken(token: string | null): void {
  memoryCsrfToken = token;
}

export function getMemoryCsrfToken(): string | null {
  return memoryCsrfToken;
}

export function setAdminCsrfToken(token: string | null): void {
  memoryAdminCsrfToken = token;
}

function isAdminEndpoint(endpoint: string): boolean {
  return endpoint.startsWith('/admin') || endpoint.startsWith('/setup') || endpoint.includes('scope=admin');
}

function rememberCsrf(user: ApiUser | undefined, token: string | undefined): void {
  if (!token) return;
  if (user && (user.role === 'admin' || user.role === 'super_admin')) {
    setAdminCsrfToken(token);
  } else {
    setMemoryCsrfToken(token);
    knownCustomerId = user?.id ?? null;
    customerSessionEpoch += 1;
  }
}

// What this tab believes about the customer session. The cookie is shared with other tabs, so
// the session behind a request can change without this tab having been told.
let knownCustomerId: number | null = null;
/** Changes at every sign-in and sign-out here: a 401 from before one says nothing about the session now. */
let customerSessionEpoch = 0;

export interface CustomerSessionEvents {
  /** A signed-in request was refused: the session has ended. Also fires for guests; ignore it then. */
  expired: () => void;
  /** Another tab of this browser signed in, so this is now the tab's session. */
  signedInElsewhere: (user: ApiUser) => void;
}

let customerSessionEvents: CustomerSessionEvents | null = null;

/** For the auth state (context/AuthContext.tsx): returns the function that stops listening. */
export function onCustomerSessionEvent(events: CustomerSessionEvents): () => void {
  customerSessionEvents = events;
  return () => {
    if (customerSessionEvents === events) customerSessionEvents = null;
  };
}

let csrfRefresh: Promise<boolean> | null = null;

/**
 * The session's CSRF token was refused: this tab's copy is from before a sign-in on another tab.
 * Asks the server for the current one and tells the auth state. True when the request can be sent
 * again; never when the session is now a different customer's (a saved cart, say, would go to the
 * wrong account).
 */
function refreshCustomerCsrf(): Promise<boolean> {
  csrfRefresh ??= (async () => {
    const before = knownCustomerId;
    try {
      const me = await apiGetMe();
      customerSessionEvents?.signedInElsewhere(me.user);
      return before === null || before === me.user.id;
    } catch {
      return false;
    }
  })().finally(() => {
    csrfRefresh = null;
  });
  return csrfRefresh;
}

/** The message to show for a failed request (or anything thrown), else the fallback. */
export function errorMessage(err: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (err instanceof Error && err.message) return err.message;
  return fallback;
}

export class ApiError extends Error {
  statusCode: number;
  errorCode?: string;
  details?: Record<string, unknown>;

  constructor(message: string, statusCode: number, errorCode?: string, details?: Record<string, unknown>) {
    super(message);
    this.name = 'ApiError';
    this.statusCode = statusCode;
    this.errorCode = errorCode;
    this.details = details;
  }
}

// Only public catalogue reads are cached briefly. Orders, payment status, customer and
// admin data are always fetched fresh so status changes show up immediately.
const CACHEABLE_PREFIXES = ['/products', '/categories', '/storefront/homepage'];
const apiCache = new Map<string, { data: ApiResponse<unknown>; timestamp: number }>();
const CACHE_TTL_MS = 30000;

export function clearApiCache(): void {
  apiCache.clear();
}

/**
 * Standard HTTP fetcher returning full ApiResponse envelope.
 */
async function fetchFullFromApi<T>(
  endpoint: string,
  options?: RequestInit & { timeoutMs?: number },
  isCsrfRetry = false
): Promise<ApiResponse<T>> {
  const path = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
  const url = `${API_BASE_URL}${path}`;
  const method = (options?.method || 'GET').toUpperCase();
  const isCacheable = method === 'GET' && CACHEABLE_PREFIXES.some((prefix) => path.startsWith(prefix));

  if (isCacheable) {
    const cached = apiCache.get(url);
    if (cached && Date.now() - cached.timestamp < CACHE_TTL_MS) {
      return cached.data as ApiResponse<T>;
    }
  }

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), options?.timeoutMs ?? 30000); // 30s unless the call says otherwise
  // A caller's signal (e.g. TanStack Query cancelling a request nobody needs any more) also
  // stops the request; that is reported as a cancellation, not as a timeout.
  const callerSignal = options?.signal ?? null;
  if (callerSignal?.aborted) controller.abort();
  else callerSignal?.addEventListener('abort', () => controller.abort(), { once: true });

  const headers: Record<string, string> = {
    'Accept': 'application/json',
    ...(options?.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
    ...(options?.headers as Record<string, string> || {}),
  };

  const csrf = isAdminEndpoint(path) ? memoryAdminCsrfToken : memoryCsrfToken;
  if (csrf && method !== 'GET') {
    headers['X-CSRF-Token'] = csrf;
  }
  const epochAtStart = customerSessionEpoch;

  try {
    const res = await fetch(url, {
      ...options,
      credentials: 'include',
      signal: controller.signal,
      headers,
      cache: 'no-store',
    });

    clearTimeout(timeoutId);

    let json: ApiResponse<T>;
    try {
      json = await res.json();
    } catch {
      throw new ApiError(
        res.status >= 500 || res.status === 0
          ? 'The store server is not responding. Please try again in a moment.'
          : `Unexpected response from the server (HTTP ${res.status}).`,
        res.status
      );
    }

    if (!res.ok || !json.success) {
      if (!isCsrfRetry && json.error?.code === 'CSRF_MISMATCH' && !isAdminEndpoint(path) && (await refreshCustomerCsrf())) {
        return await fetchFullFromApi<T>(endpoint, options, true);
      }
      // /auth/* answers 401 for a guest or a wrong password, which says nothing about a session.
      if (res.status === 401 && !isAdminEndpoint(path) && !path.startsWith('/auth/') && epochAtStart === customerSessionEpoch) {
        setMemoryCsrfToken(null);
        knownCustomerId = null;
        customerSessionEpoch += 1;
        customerSessionEvents?.expired();
      }
      const errMsg =
        json.error?.message || json.message || `Request failed with status ${res.status}`;
      throw new ApiError(errMsg, res.status, json.error?.code, json.error?.details);
    }

    if (isCacheable) {
      apiCache.set(url, { data: json, timestamp: Date.now() });
    } else if (method !== 'GET') {
      apiCache.clear();
    }

    return json;
  } catch (err) {
    clearTimeout(timeoutId);
    if (callerSignal?.aborted) {
      throw err;
    }
    if (err instanceof Error && err.name === 'AbortError') {
      // An admin change the server may already be carrying out: trying again blindly could repeat it.
      if (method !== 'GET' && isAdminEndpoint(path)) {
        throw new ApiError('The server did not answer in time, so this may or may not have gone through. Check before trying again.', 0, 'TIMEOUT');
      }
      throw new ApiError('The request timed out. Please check your connection and try again.', 0, 'TIMEOUT');
    }
    if (err instanceof TypeError) {
      throw new ApiError('Could not reach the store server. Please check your connection and try again.', 0, 'NETWORK_ERROR');
    }
    throw err;
  }
}

/**
 * Standard HTTP fetcher returning unwrapped payload.
 */
async function fetchFromApi<T>(
  endpoint: string,
  options?: RequestInit & { timeoutMs?: number }
): Promise<T> {
  const json = await fetchFullFromApi<T>(endpoint, options);
  return json.data as T;
}

/** Request helpers shared with lib/admin-api.ts. */
export const apiRequest = fetchFromApi;
export const apiRequestFull = fetchFullFromApi;

/** A product's specification rows as the page shows them: trimmed, and no row without a name or a value. */
export function specificationRowsFrom(value: unknown): ProductSpec[] {
  if (!Array.isArray(value)) return [];
  const rows: ProductSpec[] = [];
  for (const row of value) {
    if (!row || typeof row !== 'object') continue;
    const { name, value: text } = row as { name?: unknown; value?: unknown };
    const cleanName = typeof name === 'string' ? name.trim() : '';
    const cleanValue = typeof text === 'string' || typeof text === 'number' ? String(text).trim() : '';
    if (cleanName && cleanValue) rows.push({ name: cleanName, value: cleanValue });
  }
  return rows;
}

/**
 * Maps an API product summary or detail into the storefront Product shape.
 * Only data from the API is used: no invented reviews, specifications, FAQs, colours or sizes.
 * The rating is the server's (the owner's starting rating blended with approved reviews), and
 * the review count is real reviews only. Missing information stays empty and the UI shows nothing.
 */
export function mapApiProductToStorefront(item: ApiProductSummary | ApiProductDetail): Product {
  const images =
    'images' in item && Array.isArray(item.images) && item.images.length > 0
      ? item.images.map((img) => img.image_url)
      : item.primary_image
        ? [item.primary_image]
        : [PRODUCT_IMAGE_PLACEHOLDER];

  const colors: ProductColor[] = Array.isArray(item.available_colors)
    ? item.available_colors.map((c) => ({ name: c.name, hex: c.hex || '#000000' }))
    : [];
  const sizes: string[] = Array.isArray(item.available_sizes) ? item.available_sizes : [];

  const basePrice = Number(item.base_price);
  const variants: ProductVariant[] | undefined =
    'variants' in item && Array.isArray(item.variants)
      ? item.variants.map((v) => ({
          id: Number(v.id),
          sku: v.sku,
          size: v.size,
          color: { name: v.color_name, hex: v.color_hex || '#000000' },
          price: v.price_override !== null && Number(v.price_override) > 0 ? Number(v.price_override) : basePrice,
          stock: Math.max(0, Number(v.stock_quantity) || 0),
        }))
      : undefined;

  // Lists send total_stock / in_stock; a product's own page sends the stock per option instead.
  // Without this, a refreshed wishlist item (read from the product page answer) looked sold out.
  const variantStock = variants ? variants.reduce((sum, v) => sum + v.stock, 0) : null;
  const listed = item as { total_stock?: number | string | null; in_stock?: boolean | null };
  const totalStock = listed.total_stock !== undefined && listed.total_stock !== null ? Number(listed.total_stock) || 0 : (variantStock ?? 0);
  const inStock = listed.in_stock !== undefined && listed.in_stock !== null ? Boolean(listed.in_stock) : variantStock !== null ? variantStock > 0 : totalStock > 0;

  const reviews: Review[] =
    'reviews' in item && Array.isArray(item.reviews)
      ? item.reviews.map((r) => ({
          id: String(r.id),
          author: r.author,
          rating: Number(r.rating),
          verified: Boolean(r.verified),
          title: r.title || undefined,
          comment: r.comment,
          date: new Date(r.created_at.replace(' ', 'T')).toLocaleDateString('en-NG', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
          }),
        }))
      : [];

  const details = [item.sku ? `SKU: ${item.sku}` : '', item.category_name ? `Category: ${item.category_name}` : ''].filter(Boolean);

  // Only a product's own page sends these; a list answer leaves them empty.
  const detail = item as Partial<ApiProductDetail>;
  const warranty = typeof detail.warranty === 'string' ? detail.warranty.trim() : '';

  return {
    id: String(item.id),
    name: item.name,
    slug: item.slug,
    description: item.description || '',
    price: basePrice,
    originalPrice: item.original_price ? Number(item.original_price) : undefined,
    discountPercent: item.discount_percent > 0 ? item.discount_percent : undefined,
    rating: Number(item.rating_avg) || 0,
    reviewCount: Number(item.rating_count) || 0,
    // A product can have no category (the API then sends null).
    category: item.category_slug ?? '',
    categoryName: item.category_name,
    dressStyle: item.dress_style ? [item.dress_style.toLowerCase()] : [],
    gender: 'unisex',
    images,
    colors,
    sizes,
    variants,
    totalStock,
    inStock,
    sku: item.sku,
    specifications: specificationRowsFrom(detail.specifications),
    // A shop that has warranties switched off never shows one, even if a product still holds it.
    warranty: SHOP.features.warranty && warranty ? warranty : undefined,
    isHeavy: detail.is_heavy === true || Number(detail.is_heavy) === 1,
    ageRestricted: item.age_restricted === true,
    details,
    faqs: [],
    reviews,
  };
}

/**
 * Fetch Homepage sections (featured, flash deals, new arrivals, top selling, categories, promotions).
 */
export async function getHomepageData(): Promise<HomepageData> {
  return fetchFromApi<HomepageData>('/storefront/homepage');
}

/**
 * Fetch filtered, searched, and paginated public product listing.
 */
export async function getProducts(params: ProductQueryParams = {}): Promise<ProductListResponse> {
  const json = await fetchFullFromApi<ApiProductSummary[]>(productListPath(params));
  return productListFrom(json.data, json.meta);
}

/**
 * Fetch single product detail by ID or Slug.
 */
export async function getProductDetail(idOrSlug: string | number): Promise<ApiProductDetail> {
  return fetchFromApi<ApiProductDetail>(`/products/${encodeURIComponent(idOrSlug)}`);
}

/**
 * Filter options that exist in the published catalogue.
 */
export async function getCatalogFacets(category?: string | null): Promise<CatalogFacets> {
  return fetchFromApi<CatalogFacets>(category ? `/catalog/facets?category=${encodeURIComponent(category)}` : '/catalog/facets');
}

/**
 * Fetch public active categories.
 */
export async function getCategories(): Promise<ApiCategory[]> {
  return fetchFromApi<ApiCategory[]>('/categories');
}

export const apiGetCategories = getCategories;

// =============================================================================
// AUTHENTICATION & CUSTOMER ACCOUNT API CLIENT METHODS
// =============================================================================

export interface ApiUser {
  id: number;
  uuid: string;
  email: string;
  full_name: string;
  phone: string | null;
  role: 'customer' | 'admin' | 'super_admin';
  status: 'active' | 'unverified' | 'suspended';
  email_verified_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface ApiAddress {
  id: number;
  user_id: number;
  label: string;
  recipient_name: string;
  phone: string;
  street_address: string;
  city: string;
  state: string;
  country: string;
  postal_code: string | null;
  is_default: number | boolean;
  created_at: string;
  updated_at: string;
}

export interface ApiAuthResponse {
  user: ApiUser;
  csrf_token: string;
  expires_at: string;
  /** Sign-in link only: a password set before the email was confirmed was removed. */
  password_removed?: boolean;
}

export interface ApiShippingZone {
  id: number;
  name: string;
  code: string;
  description: string;
  states_covered: string[];
  fee: number;
  heavy_item_fee?: number;
  estimated_days: string;
  is_active: boolean;
}

/**
 * Register a new customer account.
 */
export async function apiRegister(payload: {
  full_name: string;
  email: string;
  password: string;
  phone?: string;
}): Promise<ApiAuthResponse> {
  const res = await fetchFullFromApi<ApiAuthResponse>('/auth/register', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
  rememberCsrf(res.data?.user, res.data?.csrf_token);
  return res.data!;
}

/**
 * Sign in using email and password. The server sets the HTTP-only session cookie
 * (admin or customer scope, depending on the account role).
 */
export async function apiLogin(credentials: {
  email: string;
  password: string;
}): Promise<ApiAuthResponse> {
  const res = await fetchFullFromApi<ApiAuthResponse>('/auth/login', {
    method: 'POST',
    body: JSON.stringify(credentials),
  });
  rememberCsrf(res.data?.user, res.data?.csrf_token);
  return res.data!;
}

/**
 * Sign out of the customer session (or the admin session with scope 'admin').
 * Local state is cleared even if the network call fails.
 */
export async function apiLogout(scope: 'customer' | 'admin' = 'customer'): Promise<void> {
  try {
    await fetchFullFromApi<null>(`/auth/logout${scope === 'admin' ? '?scope=admin' : ''}`, {
      method: 'POST',
    });
  } finally {
    if (scope === 'admin') {
      setAdminCsrfToken(null);
    } else {
      setMemoryCsrfToken(null);
      knownCustomerId = null;
      customerSessionEpoch += 1;
    }
    clearApiCache();
    clearQueryCache();
  }
}

/**
 * Fetch the current signed-in customer (or admin with scope 'admin').
 */
export async function apiGetMe(scope: 'customer' | 'admin' = 'customer'): Promise<{ user: ApiUser; csrf_token: string }> {
  const res = await fetchFullFromApi<{ user: ApiUser; csrf_token: string }>(
    `/auth/me${scope === 'admin' ? '?scope=admin' : ''}`
  );
  if (res.data?.csrf_token) {
    if (scope === 'admin') {
      setAdminCsrfToken(res.data.csrf_token);
    } else {
      setMemoryCsrfToken(res.data.csrf_token);
      knownCustomerId = res.data.user?.id ?? null;
    }
  }
  return res.data!;
}

/**
 * Is anyone signed in? Unlike apiGetMe, "no" is a normal answer (user: null), not a 401 in the
 * browser's console on every page a visitor opens.
 */
export async function apiPeekSession(scope: 'customer' | 'admin' = 'customer'): Promise<{ user: ApiUser | null }> {
  const res = await fetchFullFromApi<{ user: ApiUser | null; csrf_token: string | null }>(
    `/auth/me?optional=1${scope === 'admin' ? '&scope=admin' : ''}`
  );
  const user = res.data?.user ?? null;
  if (res.data?.csrf_token) {
    if (scope === 'admin') {
      setAdminCsrfToken(res.data.csrf_token);
    } else {
      setMemoryCsrfToken(res.data.csrf_token);
      knownCustomerId = user?.id ?? null;
    }
  }
  return { user };
}

/**
 * Request a passwordless magic sign-in link. The link is delivered by email only.
 */
export async function apiRequestMagicLink(email: string): Promise<{ message: string }> {
  const res = await fetchFullFromApi<{ email: string; expires_in_minutes: number }>('/auth/customer/magic-link', {
    method: 'POST',
    body: JSON.stringify({ email }),
  });
  return { message: res.message || 'Check your inbox for a sign-in link.' };
}

/**
 * Verify passwordless magic sign-in token.
 */
export async function apiVerifyMagicLink(token: string): Promise<ApiAuthResponse> {
  const res = await fetchFullFromApi<ApiAuthResponse>('/auth/customer/verify', {
    method: 'POST',
    body: JSON.stringify({ token }),
  });
  rememberCsrf(res.data?.user, res.data?.csrf_token);
  return res.data!;
}

/**
 * Update authenticated customer profile.
 */
export async function apiUpdateProfile(data: { full_name?: string; phone?: string }): Promise<ApiUser> {
  return fetchFromApi<ApiUser>('/customer/profile', {
    method: 'PUT',
    body: JSON.stringify(data),
  });
}

/**
 * List customer saved addresses.
 */
export async function apiGetAddresses(): Promise<ApiAddress[]> {
  return fetchFromApi<ApiAddress[]>('/customer/addresses');
}

/**
 * Create a new customer delivery address.
 */
export async function apiCreateAddress(address: Partial<ApiAddress>): Promise<ApiAddress> {
  return fetchFromApi<ApiAddress>('/customer/addresses', {
    method: 'POST',
    body: JSON.stringify(address),
  });
}

/**
 * Delete a customer delivery address.
 */
export async function apiDeleteAddress(id: number): Promise<void> {
  await fetchFromApi<null>(`/customer/addresses/${id}`, {
    method: 'DELETE',
  });
}

/**
 * Set an address as default.
 */
export async function apiSetDefaultAddress(id: number): Promise<void> {
  await fetchFromApi<null>(`/customer/addresses/${id}/default`, {
    method: 'POST',
  });
}

export interface ApiSavedCartItem {
  variant_id: number;
  product_id: number;
  quantity: number;
  size: string;
  color_name: string;
  color_hex: string;
  sku: string;
  unit_price: number;
  original_price: number | null;
  stock_quantity: number;
  product_name: string;
  product_slug: string;
  available: boolean;
  image_url: string | null;
}

/** A line that can't be bought says only that: nothing about an unpublished product is sent. */
export type CartLineCheck =
  | { variant_id: number; available: false }
  | {
      variant_id: number;
      product_id: number;
      sku: string;
      size: string;
      color_name: string;
      color_hex: string;
      unit_price: number;
      original_price: number | null;
      stock_quantity: number;
      product_name: string;
      product_slug: string;
      available: true;
    };

/**
 * Live price, stock and availability for cart lines (no sign-in needed).
 */
export async function apiCheckCartLines(variantIds: number[]): Promise<{ lines: CartLineCheck[] }> {
  if (variantIds.length === 0) return { lines: [] };
  return fetchFromApi<{ lines: CartLineCheck[] }>('/checkout/cart-check', {
    method: 'POST',
    body: JSON.stringify({ variant_ids: variantIds }),
  });
}

/**
 * The signed-in customer's saved cart (current prices and stock).
 */
export async function apiGetSavedCart(): Promise<ApiSavedCartItem[]> {
  const res = await fetchFromApi<{ items: ApiSavedCartItem[] }>('/customer/cart');
  return res.items || [];
}

/**
 * Replace the signed-in customer's saved cart.
 */
export async function apiSaveCart(items: Array<{ variant_id: number; quantity: number }>): Promise<ApiSavedCartItem[]> {
  const res = await fetchFromApi<{ items: ApiSavedCartItem[] }>('/customer/cart', {
    method: 'PUT',
    body: JSON.stringify({ items }),
  });
  return res.items || [];
}

/**
 * The signed-in customer's saved wishlist.
 */
export async function apiGetSavedWishlist(): Promise<{ product_ids: number[]; products: ApiProductSummary[] }> {
  return fetchFromApi<{ product_ids: number[]; products: ApiProductSummary[] }>('/customer/wishlist');
}

/**
 * Replace the signed-in customer's saved wishlist.
 */
export async function apiSaveWishlist(productIds: number[]): Promise<{ product_ids: number[]; products: ApiProductSummary[] }> {
  return fetchFromApi<{ product_ids: number[]; products: ApiProductSummary[] }>('/customer/wishlist', {
    method: 'PUT',
    body: JSON.stringify({ product_ids: productIds }),
  });
}

/**
 * Check admin onboarding setup status.
 */
export async function apiGetSetupStatus(): Promise<{ is_setup_completed: boolean; store_name: string }> {
  return fetchFromApi<{ is_setup_completed: boolean; store_name: string }>('/setup/status');
}

export interface SetupStorePayload {
  name: string;
  email: string;
  password: string;
  store_name: string;
  contact_email: string;
  contact_phone: string;
  origin_address: string;
  origin_city: string;
  origin_state: string;
}

/**
 * Complete first-run admin onboarding.
 */
export async function apiSetupStore(payload: SetupStorePayload): Promise<ApiAuthResponse> {
  const res = await fetchFullFromApi<ApiAuthResponse>('/setup', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
  rememberCsrf(res.data?.user, res.data?.csrf_token);
  return res.data!;
}

export interface AdminProduct {
  id: number;
  uuid: string;
  name: string;
  slug: string;
  description: string;
  product_type: 'physical';
  status: 'draft' | 'published' | 'archived';
  base_price: number;
  original_price: number | null;
  currency: string;
  sku: string;
  /** The rating the shop shows: the starting rating blended with approved reviews. */
  rating_avg: number;
  /** Approved reviews only. */
  rating_count: number;
  /** The product's own starting rating; null = the store default. */
  rating_start?: number | null;
  /** The customers' own average (approved reviews only). */
  review_avg?: number;
  is_featured: boolean;
  is_flash_deal: boolean;
  flash_deal_ends_at?: string | null;
  dress_style: string | null;
  created_at: string;
  updated_at: string;
  category_id: number | null;
  category_name?: string;
  category_slug?: string;
  primary_image?: string;
  total_stock: number;
  is_low_stock: boolean;
  variants_count: number;
  variants: ApiProductVariant[];
  images: ApiProductImage[];
}

export interface AdminProductQuery {
  page?: number;
  per_page?: number;
  search?: string;
  category?: string;
  status?: string;
  inventory?: string;
}

/** What the product editor saves. A new variant carries its starting stock; later changes go through inventory. */
export interface AdminProductPayload {
  name: string;
  slug: string;
  category_id: number | null;
  product_type: 'physical';
  status: AdminProduct['status'];
  dress_style: string | null;
  base_price: number;
  original_price: number | null;
  sku: string;
  description: string;
  is_featured: 0 | 1;
  is_flash_deal: 0 | 1;
  flash_deal_ends_at: string;
  /** 1 to 5; null = use the store default starting rating. */
  rating_start: number | null;
  images: Array<{ image_url: string; alt_text: string; is_primary: 0 | 1; display_order: number }>;
  variants: Array<{
    id?: number;
    stock_quantity?: number;
    sku: string;
    size: string;
    color_name: string;
    color_hex: string;
    price_override: number | null;
    low_stock_threshold: number;
  }>;
}

/**
 * Fetch admin products with filtering and pagination.
 */
export async function apiGetAdminProducts(params: AdminProductQuery = {}): Promise<{
  items: AdminProduct[];
  pagination: PaginationMeta;
}> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') {
      query.append(k, String(v));
    }
  });
  const qs = query.toString() ? `?${query.toString()}` : '';
  const res = await fetchFullFromApi<AdminProduct[]>(`/admin/products${qs}`);
  return {
    items: res.data || [],
    pagination: res.meta || {
      page: 1,
      per_page: 15,
      total: 0,
      total_pages: 1,
    },
  };
}

/**
 * Create a new product as admin.
 */
export async function apiCreateAdminProduct(payload: AdminProductPayload): Promise<ApiProductDetail> {
  return fetchFromApi<ApiProductDetail>('/admin/products', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

/**
 * Update an existing product as admin.
 */
export async function apiUpdateAdminProduct(id: number, payload: AdminProductPayload): Promise<ApiProductDetail> {
  return fetchFromApi<ApiProductDetail>(`/admin/products/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  });
}

/**
 * Quick status update for product.
 */
export async function apiUpdateAdminProductStatus(
  id: number,
  status: 'draft' | 'published' | 'archived'
): Promise<void> {
  await fetchFromApi<unknown>(`/admin/products/${id}/status`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  });
}

/**
 * Archive a product.
 */
export async function apiDeleteAdminProduct(id: number): Promise<void> {
  await fetchFromApi<unknown>(`/admin/products/${id}`, {
    method: 'DELETE',
  });
}

/**
 * Upload an image (products, categories, banners). The photo is resized and converted to
 * WebP in the browser first, so customers download small files.
 */
export async function apiUploadProductImage(
  file: File
): Promise<{ image_url: string; file_name: string; size_bytes: number }> {
  const formData = new FormData();
  formData.append('image', await optimizeImageForUpload(file));

  return fetchFromApi<{ image_url: string; file_name: string; size_bytes: number }>('/admin/upload-image', {
    method: 'POST',
    body: formData,
    timeoutMs: 120000, // slow connections; matches the proxy's limit (next.config.mjs)
  });
}

// =============================================================================
// Checkout & Order Creation APIs
// =============================================================================

export interface CheckoutItemInput {
  product_id: number;
  variant_id?: number;
  quantity: number;
  size?: string;
  color?: string;
}

export interface ShippingAddressInput {
  full_name: string;
  phone: string;
  address_line1: string;
  address_line2?: string;
  city: string;
  state: string;
  postal_code?: string;
  country: string;
  /** Optional pin the customer shared at checkout ("Use my current location"). */
  location?: { latitude: number; longitude: number; accuracy_m: number | null };
}

export interface ShippingZone {
  id: number;
  name: string;
  code: string;
  description?: string;
  /** In a checkout quote: the delivery fee for this order's number of items. */
  fee: number;
  /** The zone's own fee, covering the first `items_included` items. */
  base_fee?: number;
  items_included?: number;
  /** Added for each item after `items_included`. */
  extra_item_fee?: number;
  /** Added for each large or heavy item (only charged when the shop has heavy delivery on). */
  heavy_item_fee?: number;
  estimated_days: string;
  states_covered: string[];
  is_active: boolean;
}

/**
 * The two options as saved on an order line (`size` is option 1, `color` option 2; a product
 * that does not use one holds the shop's "none" value there), with the product's warranty when
 * it had one.
 */
export interface VariantOptions {
  size?: string;
  color?: string;
  color_hex?: string;
  warranty?: string;
}

/** Store contact and dispatch details as configured in admin Settings ('' means not set). */
export interface StoreOrigin {
  store_name: string;
  origin_city: string;
  origin_state: string;
  origin_address: string;
  contact_phone: string;
  contact_email: string;
  whatsapp_phone?: string;
}

/** A priced cart line as the server checked it (quote and newly placed order). */
export interface CheckoutLineItem {
  product_id: number;
  variant_id: number;
  name: string;
  slug?: string;
  sku: string;
  variant_options: VariantOptions | null;
  unit_price: number;
  quantity: number;
  line_total: number;
  stock_available?: number;
}

export interface CheckoutQuoteResponse {
  currency: string;
  items: CheckoutLineItem[];
  items_count: number;
  total_quantity: number;
  subtotal: number;
  discount_amount: number;
  shipping_fee: number;
  grand_total: number;
  applied_coupon: { id: number; code: string; description: string; discount_percent: number; label?: string } | null;
  shipping_zone: ShippingZone | null;
  eligible_zones: ShippingZone[];
  origin: StoreOrigin;
  /** How many large or heavy items the delivery fee counted (0 when the shop does not charge for them). */
  heavy_items?: number;
  /** An item in the cart is for adults only: the order needs `age_confirmed`. */
  age_check_required?: boolean;
  minimum_age?: number;
}

/** The delivery zone as it was when the order was placed. */
export interface OrderZoneSnapshot {
  id: number;
  name: string;
  code: string;
  fee: number;
  estimated_days: string;
  origin?: StoreOrigin;
  heavy_item_fee?: number;
  heavy_items?: number;
}

export interface CheckoutOrderResponse {
  order_id: number;
  order_number: string;
  guest_access_token: string;
  guest_email: string;
  user_id: number | null;
  status: string;
  payment_status: string;
  expires_at?: string | null;
  currency: string;
  subtotal: number;
  discount_amount: number;
  shipping_fee: number;
  grand_total: number;
  shipping_zone: OrderZoneSnapshot | null;
  shipping_address: ShippingAddressInput;
  items_count: number;
  items: CheckoutLineItem[];
  created_at: string;
  /** True when a repeated submit returned the order already placed. */
  replayed?: boolean;
}

/** One line of a placed order. */
export interface ApiOrderItem {
  id: number;
  product_id: number | null;
  variant_id: number | null;
  item_name: string;
  sku: string;
  variant_options: VariantOptions | null;
  unit_price: number;
  quantity: number;
  line_total: number;
}

export interface ApiOrderShipment {
  carrier: string | null;
  tracking_number: string | null;
  tracking_url: string | null;
  status: string;
  shipped_at: string | null;
  delivered_at: string | null;
}

/** A customer's order as listed in "My orders". */
export interface ApiCustomerOrder {
  id: number;
  order_number: string;
  order_status: string;
  payment_status: string;
  currency: string;
  subtotal: number;
  discount_amount: number;
  shipping_fee: number;
  grand_total: number;
  shipping_address_snapshot: ShippingAddressInput | null;
  expires_at: string | null;
  /** When the customer reported their WhatsApp transfer. */
  customer_marked_paid_at?: string | null;
  created_at: string;
  updated_at: string;
  items: ApiOrderItem[];
  items_count: number;
  shipment: ApiOrderShipment | null;
}

/** One order as the order lookup returns it (internal fields are removed by the server). */
export interface ApiOrder extends Omit<ApiCustomerOrder, 'items_count'> {
  /** Same as order_status. */
  status: string;
  guest_email: string | null;
  shipping_zone_id: number | null;
  shipping_zone_snapshot: OrderZoneSnapshot | null;
  applied_promo_code: string | null;
  notes: string | null;
  customer_marked_paid_at: string | null;
  history: Array<{ previous_status: string | null; new_status: string; created_at: string }>;
  /** The successful payment, else the latest attempt. */
  payment: {
    provider: string;
    provider_reference: string | null;
    amount: number;
    currency: string;
    status: string;
    verified_at: string | null;
  } | null;
}

/**
 * Fetch available shipping destination zones from the store backend.
 */
export async function apiGetShippingZones(): Promise<{ zones: ShippingZone[]; origin: StoreOrigin }> {
  return fetchFromApi<{ zones: ShippingZone[]; origin: StoreOrigin }>('/checkout/shipping-zones');
}

export interface ValidatePromoResponse {
  valid: boolean;
  code: string;
  discount_percent: number;
  discount_amount: number;
  description: string;
  discount_type?: 'percent' | 'fixed';
  discount_value?: number;
  min_order_amount?: number | null;
  max_discount_amount?: number | null;
  label?: string;
}

/**
 * Validate a promotional coupon code server-side against MySQL active promotions, dates and usage limits.
 */
export async function apiValidatePromotion(
  code: string,
  subtotal: number = 0
): Promise<ValidatePromoResponse> {
  return fetchFromApi<ValidatePromoResponse>('/checkout/validate-promo', {
    method: 'POST',
    body: JSON.stringify({ code, subtotal }),
  });
}

/**
 * Fetch real-time checkout quote with server-side pricing and shipping calculation.
 */
export async function apiGetCheckoutQuote(data: {
  items: CheckoutItemInput[];
  shipping_address?: Partial<ShippingAddressInput>;
  shipping_zone_id?: number;
  coupon_code?: string;
}): Promise<CheckoutQuoteResponse> {
  return fetchFromApi<CheckoutQuoteResponse>('/checkout/quote', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

/**
 * Submit and create a real order with atomic inventory reservation.
 */
export async function apiCreateCheckoutOrder(data: {
  email?: string;
  items: CheckoutItemInput[];
  shipping_address: ShippingAddressInput;
  shipping_zone_id?: number;
  coupon_code?: string;
  notes?: string;
  /** Sent as true when the quote asked for the age check and the buyer ticked the box. */
  age_confirmed?: boolean;
}, idempotencyKey?: string): Promise<CheckoutOrderResponse> {
  const headers: Record<string, string> = {};
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
  // Lets the shop count this browser's unpaid orders (it keeps only a hash of the id).
  const device = deviceId();
  if (device) headers['X-Device-Id'] = device;
  return fetchFromApi<CheckoutOrderResponse>('/checkout/orders', {
    method: 'POST',
    headers,
    body: JSON.stringify(data),
  });
}

const DEVICE_ID_KEY = 'shopco_device';

/**
 * A random id for this browser, made once and kept in its storage. Sent only when placing an
 * order, so the shop can limit how many unpaid orders one device holds. Empty when storage is
 * blocked (the order is then counted by email and phone alone).
 */
function deviceId(): string {
  if (typeof window === 'undefined') return '';
  try {
    const saved = window.localStorage.getItem(DEVICE_ID_KEY);
    if (saved && /^[A-Za-z0-9_-]{16,64}$/.test(saved)) return saved;
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    const fresh = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    window.localStorage.setItem(DEVICE_ID_KEY, fresh);
    return fresh;
  } catch {
    return '';
  }
}

/**
 * Retrieve order details. Access requires the signed-in owner's session, the guest access
 * token (sent as a header, never in the URL), or the full email used at checkout.
 */
export async function apiGetOrder(
  orderNumber: string,
  verification?: string | { token?: string; email?: string; reference?: string },
  signal?: AbortSignal
): Promise<ApiOrder> {
  const headers: Record<string, string> = {};
  const token = typeof verification === 'string' ? verification : verification?.token;
  const email = typeof verification === 'string' ? undefined : verification?.email;

  if (token) headers['X-Guest-Access-Token'] = token;
  if (email) headers['X-Order-Email'] = email.trim();

  return fetchFromApi<ApiOrder>(`/checkout/orders/${encodeURIComponent(orderNumber)}`, { headers, signal });
}

/**
 * Retrieve all past orders for the authenticated customer.
 */
export async function apiGetCustomerOrders(): Promise<ApiCustomerOrder[]> {
  return fetchFromApi<ApiCustomerOrder[]>('/customer/orders');
}

export interface PaymentMethodItem {
  id: 'monnify' | 'kora' | 'whatsapp';
  name: string;
  description: string;
  badge: string;
  is_default: boolean;
}

export interface PaymentMethodsResponse {
  methods: PaymentMethodItem[];
  /** The gateway behind the single "Pay now" button (null: no card/transfer gateway is on). */
  pay_now_gateway?: 'monnify' | 'kora' | null;
  whatsapp_enabled?: boolean;
  support_phone: string;
  /** How long an unpaid order holds its items when paying online / by WhatsApp transfer. */
  payment_window_minutes?: number;
  manual_payment_window_hours?: number;
  /** The payment return page may open the live status stream. */
  live_payment_status?: boolean;
}

/**
 * Fetch enabled payment gateways and support contact.
 */
export async function apiGetPaymentMethods(): Promise<PaymentMethodsResponse> {
  return fetchFromApi<PaymentMethodsResponse>('/checkout/payment-methods');
}

/** Changes after every admin change: open pages compare it to reload their content (StoreLiveUpdates). */
export async function apiGetStorefrontVersion(signal?: AbortSignal): Promise<string> {
  const res = await fetchFromApi<{ version: string }>('/storefront/version', { timeoutMs: 10000, signal });
  return String(res?.version ?? '');
}

export interface PaymentInitializationResponse {
  order_number: string;
  provider: 'monnify' | 'kora' | string;
  provider_reference: string;
  checkout_url?: string;
  amount: number;
  currency: string;
  status: string;
  expires_in_minutes?: number;
  expires_at?: string | null;
  support_phone?: string;
}

/**
 * Initialize payment session (Monnify or Kora) for an order.
 */
export async function apiInitializePayment(
  orderNumber: string,
  guestAccessToken?: string,
  redirectUrl?: string,
  gateway?: 'monnify' | 'kora' | string,
  /** The checkout email, when the order was looked up with it (no access key on this device). */
  orderEmail?: string
): Promise<PaymentInitializationResponse> {
  const headers: Record<string, string> = {};
  if (guestAccessToken) {
    headers['X-Guest-Access-Token'] = guestAccessToken;
  }
  if (orderEmail) {
    headers['X-Order-Email'] = orderEmail;
  }

  return fetchFromApi<PaymentInitializationResponse>(
    `/checkout/orders/${encodeURIComponent(orderNumber)}/initialize-payment`,
    {
      method: 'POST',
      headers,
      body: JSON.stringify({
        guest_access_token: guestAccessToken,
        redirect_url: redirectUrl,
        gateway: gateway || 'monnify',
      }),
    }
  );
}

/**
 * "Mark as paid" for a WhatsApp transfer: tells the store to check and confirm it. This never
 * marks the order paid; the store does that once the money has arrived.
 */
/**
 * The customer left an unpaid order to change their delivery details. If no payment was
 * started for it, the server releases it so its items go back on sale at once; otherwise it
 * keeps its reservation until its deadline (`released: false`).
 */
export async function apiReleaseOrder(orderNumber: string, guestAccessToken?: string): Promise<{ order_number: string; released: boolean }> {
  const headers: Record<string, string> = {};
  if (guestAccessToken) {
    headers['X-Guest-Access-Token'] = guestAccessToken;
  }
  return fetchFromApi(`/checkout/orders/${encodeURIComponent(orderNumber)}/release`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ guest_access_token: guestAccessToken }),
  });
}

export async function apiMarkPaymentSent(
  orderNumber: string,
  guestAccessToken?: string,
  /** The checkout email, when the order was opened from "Track order" (no access key here). */
  orderEmail?: string
): Promise<{ order_number: string; recorded: boolean; order_status: string; customer_marked_paid_at?: string | null; expires_at?: string | null }> {
  const headers: Record<string, string> = {};
  if (guestAccessToken) {
    headers['X-Guest-Access-Token'] = guestAccessToken;
  } else if (orderEmail) {
    headers['X-Order-Email'] = orderEmail;
  }
  return fetchFromApi(`/checkout/orders/${encodeURIComponent(orderNumber)}/payment-sent`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ guest_access_token: guestAccessToken }),
  });
}

export interface PaymentVerificationResponse {
  /** WhatsApp transfers: the customer said they paid; the store has not confirmed yet. */
  customer_marked_paid?: boolean;
  order_number: string;
  provider_reference: string;
  provider?: string;
  status: 'successful' | 'failed' | 'pending' | 'not_started';
  order_status: string;
  amount: number;
  currency: string;
  verified_at?: string;
  failure_reason?: string;
  message?: string;
  expires_at?: string | null;
  source: string;
}


/**
 * Authoritatively query backend for verified payment status of an order.
 * Replaces trusting client query parameters with real server-side verification.
 */
export async function apiVerifyPayment(
  orderNumber: string,
  guestAccessToken?: string,
  reference?: string,
  signal?: AbortSignal,
  /** The checkout email, when the receipt was opened from "Track order" (no access key here). */
  orderEmail?: string
): Promise<PaymentVerificationResponse> {
  const params = new URLSearchParams();
  if (reference) {
    params.append('reference', reference);
  }
  const query = params.toString() ? `?${params.toString()}` : '';

  const headers: Record<string, string> = {};
  if (guestAccessToken) {
    headers['X-Guest-Access-Token'] = guestAccessToken;
  }
  if (reference) {
    headers['X-Payment-Reference'] = reference;
  }
  if (orderEmail && !guestAccessToken) {
    headers['X-Order-Email'] = orderEmail;
  }

  return fetchFromApi<PaymentVerificationResponse>(
    `/checkout/orders/${encodeURIComponent(orderNumber)}/verify-payment${query}`,
    {
      method: 'GET',
      headers,
      signal,
    }
  );
}

export async function apiSubscribeNewsletter(email: string): Promise<{ email: string }> {
  return fetchFromApi<{ email: string }>('/newsletter/subscribe', {
    method: 'POST',
    body: JSON.stringify({ email }),
  });
}

/** One-click unsubscribe from the link in every newsletter. */
export async function apiUnsubscribeNewsletter(token: string): Promise<{ email: string }> {
  return fetchFromApi<{ email: string }>('/newsletter/unsubscribe', {
    method: 'POST',
    body: JSON.stringify({ token }),
  });
}

/**
 * Submit a product review. It is held for moderation; "verified purchase" is decided by the
 * server from paid orders for the given email (or the signed-in account).
 */
export async function apiSubmitReview(
  productIdOrSlug: string | number,
  review: { author_name: string; email: string; rating: number; title?: string; comment: string }
): Promise<{ id: number; status: 'pending'; verified_purchase: boolean; message: string }> {
  const res = await fetchFullFromApi<{ id: number; status: 'pending'; verified_purchase: boolean }>(
    `/products/${encodeURIComponent(String(productIdOrSlug))}/reviews`,
    { method: 'POST', body: JSON.stringify(review) }
  );
  return { ...res.data!, message: res.message || 'Thanks! Your review will appear once it has been checked.' };
}
