/**
 * Admin API client. Every call goes through the same-origin proxy with the admin session
 * cookie and admin CSRF token (see api-client.ts). Lists are paginated: {items, pagination}.
 */

import { API_BASE_URL, ApiError, PaginationMeta, apiRequest, apiRequestFull } from '@/lib/api-client';
import type { AdminProduct, AdminProductPayload } from '@/lib/api-client';
import { refreshAfter, refreshBellCounts, refreshDevMode, refreshStorageAlert } from '@/lib/admin-query-keys';
import { queryString as qs } from '@/lib/admin-logic';

export type { PaginationMeta };
export { errorMessage } from '@/lib/api-client';

export interface Paginated<T> {
  items: T[];
  pagination: PaginationMeta;
}

/**
 * For actions that can run long on a slow host. Matches the proxy's limit (next.config.mjs), so
 * the browser doesn't give up while the server carries on.
 */
const SLOW_ACTION_TIMEOUT_MS = 120_000;

function send<T>(endpoint: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown, options?: { timeoutMs?: number }): Promise<T> {
  return apiRequest<T>(endpoint, {
    ...options,
    method,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
}

/** Field errors from a 422 response, keyed by field name. */
export function fieldErrors(err: unknown): Record<string, string> {
  if (err instanceof ApiError && err.details && typeof err.details === 'object') {
    const out: Record<string, string> = {};
    Object.entries(err.details).forEach(([k, v]) => {
      if (typeof v === 'string' && v) out[k] = v;
    });
    return out;
  }
  return {};
}

// =============================================================================
// Orders
// =============================================================================

export type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'processing'
  | 'shipped'
  | 'delivered'
  | 'completed'
  | 'cancelled'
  | 'returned'
  | 'refund_pending'
  | 'refunded';

export interface AddressSnapshot {
  full_name?: string;
  phone?: string;
  email?: string;
  address_line1?: string;
  address_line2?: string | null;
  city?: string;
  state?: string;
  country?: string;
  postal_code?: string | null;
  /** Optional pin the customer shared at checkout ("Use my current location"). */
  location?: { latitude: number; longitude: number; accuracy_m: number | null };
}

export interface AdminOrderListItem {
  id: number;
  order_number: string;
  user_id: number | null;
  guest_email: string | null;
  /** Account email for signed-in customers, else the checkout email. */
  customer_email?: string | null;
  order_status: OrderStatus;
  payment_status: string;
  currency: string;
  subtotal: number;
  shipping_fee: number;
  discount_amount: number;
  grand_total: number;
  /** `heavy_items`: how many large or heavy items the delivery fee counted, at `heavy_item_fee` each. */
  shipping_zone_snapshot: { name?: string; fee?: number; estimated_days?: string; heavy_item_fee?: number | string; heavy_items?: number | string } | null;
  shipping_address_snapshot: AddressSnapshot | null;
  created_at: string;
  updated_at: string;
  expires_at: string | null;
  requires_attention: boolean;
  attention_reason: string | null;
  /** The customer tapped "Mark as paid" for a WhatsApp transfer (not a confirmation). */
  customer_marked_paid_at?: string | null;
  /** The customer's name, contact details and street address were removed (Data & storage). */
  personal_data_removed_at?: string | null;
  payment_provider: string | null;
  payment_reference: string | null;
  payment_record_status: string | null;
  carrier: string | null;
  tracking_number: string | null;
  tracking_url: string | null;
  shipment_status: string | null;
  shipped_at: string | null;
  delivered_at: string | null;
  items_count: number;
  total_quantity: number;
}

export interface AdminOrderPayment {
  id: number;
  provider: string;
  provider_reference: string;
  amount: number | string;
  amount_received: number | string | null;
  currency: string;
  payment_method: string | null;
  status: string;
  verified_at: string | null;
  created_at: string;
}

export interface AdminOrderDetail extends Omit<AdminOrderListItem, 'payment_provider' | 'payment_reference' | 'payment_record_status' | 'carrier' | 'tracking_number' | 'tracking_url' | 'shipment_status' | 'shipped_at' | 'delivered_at' | 'items_count' | 'total_quantity'> {
  notes: string | null;
  applied_promo_code: string | null;
  allowed_transitions: OrderStatus[];
  shipment: {
    id: number;
    carrier: string;
    tracking_number: string | null;
    tracking_url: string | null;
    status: string;
    shipped_at: string | null;
    delivered_at: string | null;
  } | null;
  items: Array<{
    id: number;
    product_id: number | null;
    variant_id: number | null;
    item_name: string;
    sku: string | null;
    variant_options: { size?: string; color?: string; color_name?: string; warranty?: string } | null;
    unit_price: number;
    quantity: number;
    line_total: number;
  }>;
  history: Array<{ previous_status: string | null; new_status: string; comment: string | null; changed_by_user_id: number | null; created_at: string }>;
  payments: AdminOrderPayment[];
  payment: AdminOrderPayment | null;
  refunds: Array<{ id: number; amount: number | string; currency: string; reason: string; status: string; restocked: number | boolean; created_at: string; updated_at: string }>;
  audit_logs: Array<{ id: number; action: string; admin_name: string | null; created_at: string }>;
}

export function adminGetOrders(params: { status?: string; search?: string; page?: number; per_page?: number }, signal?: AbortSignal) {
  return apiRequest<Paginated<AdminOrderListItem>>(`/admin/orders${qs(params)}`, { signal });
}

export function adminGetOrder(orderNumber: string) {
  return apiRequest<AdminOrderDetail>(`/admin/orders/${encodeURIComponent(orderNumber)}`);
}

export function adminUpdateOrderStatus(orderNumber: string, status: OrderStatus, comment?: string) {
  return refreshAfter(send<AdminOrderDetail>(`/admin/orders/${encodeURIComponent(orderNumber)}/status`, 'PATCH', { status, comment }), refreshBellCounts);
}

export function adminAssignShipment(orderNumber: string, data: { carrier: string; tracking_number: string; tracking_url?: string; comment?: string }) {
  return send<AdminOrderDetail>(`/admin/orders/${encodeURIComponent(orderNumber)}/shipment`, 'POST', { ...data, status: 'in_transit' });
}

export function adminConfirmDelivery(orderNumber: string, comment?: string) {
  return send<AdminOrderDetail>(`/admin/orders/${encodeURIComponent(orderNumber)}/delivered`, 'POST', { comment });
}

/** Record a manual (bank transfer / WhatsApp) payment. The bank reference is required. */
export function adminConfirmPayment(orderNumber: string, data: { reference: string; note?: string }) {
  return refreshAfter(send<AdminOrderDetail>(`/admin/orders/${encodeURIComponent(orderNumber)}/confirm-payment`, 'POST', data), refreshBellCounts);
}

/** Clear the "needs attention" flag with a note saying what was done (3-500 characters). */
export function adminResolveAttention(orderNumber: string, note: string) {
  return refreshAfter(send<AdminOrderDetail>(`/admin/orders/${encodeURIComponent(orderNumber)}/resolve-attention`, 'POST', { note }), refreshBellCounts);
}

// =============================================================================
// Dashboard & analytics
// =============================================================================

export interface DashboardOverview {
  period: { days: number; start: string; end: string; previous_start: string };
  kpis: {
    currency: string;
    gross_sales: number;
    net_sales: number;
    total_refunded: number;
    /** Paid in the period, then cancelled, not yet refunded: already taken off net sales. */
    owed_refunds: number;
    /** Recorded but not yet completed: no money has gone back yet. */
    pending_refunds?: number;
    pending_refund_count?: number;
    paid_orders: number;
    aov: number;
    total_orders: number;
    new_customers: number;
    pending_fulfillment: number;
    awaiting_payment: number;
    needs_attention: number;
    low_stock_count: number;
    out_of_stock_count: number;
    total_customers: number;
  };
  /** Percentage change vs the previous period; null when there is no baseline. */
  deltas: Record<'gross_sales' | 'net_sales' | 'paid_orders' | 'aov' | 'total_orders' | 'new_customers', number | null>;
  /** One entry per day: money received, and money refunded on that day (completed refunds). */
  trend: Array<{ date: string; sales: number; refunds: number; paid_orders: number; total_orders: number }>;
  recent_orders: Array<{
    id: number;
    order_number: string;
    customer_name: string | null;
    customer_email: string | null;
    total_amount: number | string;
    currency: string;
    order_status: OrderStatus;
    payment_status: string;
    requires_attention: number | boolean;
    payment_method: string | null;
    created_at: string;
    items_count: number | string;
  }>;
  attention_orders: Array<{ order_number: string; order_status: OrderStatus; payment_status: string; attention_reason: string | null; grand_total: number | string; updated_at: string }>;
  critical_stock: Array<{ id: number; sku: string; size: string; color_name: string; stock_quantity: number | string; low_stock_threshold: number | string; product_id: number; product_name: string }>;
  top_products: Array<{ id: number; name: string; slug: string; total_units_sold: number | string; total_revenue: number | string }>;
  payment_alerts: Array<{ id: number; payment_reference: string; order_number: string; gateway: string; amount: number | string; status: string; created_at: string }>;
}

export function adminGetOverview(period: 7 | 30 | 90) {
  return apiRequest<DashboardOverview>(`/admin/dashboard/overview?period=${period}`);
}

export interface AnalyticsReport {
  filters: { range: string; start_date: string; end_date: string };
  summary: {
    currency: string;
    gross_sales: number;
    total_refunded: number;
    refund_count: number;
    /** Paid then cancelled, not yet refunded: money the store owes back. */
    owed_refunds: number;
    /** Recorded but not yet completed: no money has gone back yet. */
    pending_refunds?: number;
    pending_refund_count?: number;
    owed_refund_count: number;
    net_sales: number;
    paid_orders: number;
    aov: number;
    total_orders: number;
    conversion_rate: number;
    repeat_customer_rate: number;
    paying_customers: number;
    total_units_sold: number;
    pending_fulfillment: number;
  };
  daily_breakdown: Array<{ date: string; orders_count: number; paid_count: number; sales: number; refunds: number }>;
  category_breakdown: Array<{ category_name: string; order_count: number | string; units_sold: number | string; revenue: number | string }>;
  top_products: Array<{ product_id: number | null; name: string; units_sold: number | string; revenue: number | string }>;
  gateway_breakdown: Array<{ provider_name: string; total_attempts: number; successful_count: number; failed_count: number; total_volume: number }>;
  owed_refunds: Array<{ order_number: string; cancelled_at: string; payment_status: string; paid: number | string; refunded: number | string; owed: number | string }>;
  formulas: Record<string, string>;
}

/** Sales are counted when money is verified; refunds when completed. */
export function adminGetAnalyticsReport(params: { range?: string; start_date?: string; end_date?: string }) {
  return apiRequest<AnalyticsReport>(`/admin/analytics/report${qs(params)}`);
}

/** Download a CSV export with the admin session. */
export async function adminDownloadExport(endpoint: string, filename: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    method: 'GET',
    headers: { Accept: 'text/csv' },
    credentials: 'include',
    cache: 'no-store',
  });
  if (!response.ok) {
    throw new ApiError(response.status === 401 ? 'Your admin session has ended. Sign in again.' : 'The export could not be generated.', response.status);
  }
  const blob = await response.blob();
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  window.URL.revokeObjectURL(url);
}

// =============================================================================
// Products: specifications, warranty and heavy items
// (the product calls themselves are in api-client.ts; these are the fields the editor adds)
// =============================================================================

/** One row of a product's specifications ("Top notes" / "Bergamot, pink pepper"). */
export interface ProductSpecification {
  name: string;
  value: string;
}

export const MAX_SPECIFICATIONS = 30;
export const MAX_SPECIFICATION_NAME = 60;
export const MAX_SPECIFICATION_VALUE = 300;
export const MAX_WARRANTY = 160;

/** What the admin product read carries besides AdminProduct. Optional: a list cached before an update lacks them. */
export interface AdminProductExtras {
  specifications?: ProductSpecification[] | null;
  warranty?: string | null;
  is_heavy?: boolean | number;
  /** Worked out by the server from the product's category; read only. */
  age_restricted?: boolean;
}

/** The same fields when saving. A key that is left out keeps what the server has stored. */
export interface AdminProductExtrasPayload {
  /** An empty list clears them. */
  specifications?: ProductSpecification[];
  /** '' clears it. */
  warranty?: string;
  is_heavy?: boolean;
}

export type AdminProductSave = AdminProductPayload & AdminProductExtrasPayload;

/** The extra fields of a product as the admin list returned it. */
export function productExtras(product: AdminProduct): AdminProductExtras {
  return product as AdminProduct & AdminProductExtras;
}

// =============================================================================
// Inventory
// =============================================================================

export interface InventoryRow {
  id: number;
  product_id: number;
  product_name: string;
  product_slug: string;
  product_status: string;
  sku: string;
  size: string;
  color_name: string;
  color_hex: string;
  stock_quantity: number | string;
  low_stock_threshold: number | string;
  price_override: number | string | null;
  base_price: number | string;
  updated_at: string;
}

export interface InventoryLog {
  id: number;
  variant_id: number;
  product_id: number;
  product_name: string;
  variant_sku: string;
  size: string;
  color_name: string;
  previous_stock: number;
  new_stock: number;
  change_amount: number;
  reason: string;
  adjusted_by_name: string | null;
  created_at: string;
}

export function adminGetInventory(params: { filter?: 'all' | 'low_stock' | 'out_of_stock' | 'low_or_out'; search?: string; page?: number; per_page?: number }) {
  return apiRequest<Paginated<InventoryRow>>(`/admin/inventory${qs(params)}`);
}

export function adminGetInventoryLogs(params: { variant_id?: number; page?: number; per_page?: number }) {
  return apiRequest<Paginated<InventoryLog>>(`/admin/inventory/logs${qs(params)}`);
}

/** The answer carries the stock before and after, as the server applied it (the screen's figure can be old). */
export function adminAdjustStock(data: { variant_id: number; change_amount: number; reason: string }) {
  return send<{ previous_stock: number; new_stock: number }>('/admin/inventory/adjust', 'POST', data);
}

// =============================================================================
// Customers
// =============================================================================

export interface CustomerRow {
  id: number;
  uuid: string;
  full_name: string;
  email: string;
  phone: string | null;
  status: 'active' | 'unverified' | 'suspended';
  email_verified_at: string | null;
  created_at: string;
  total_orders: number | string;
  lifetime_spend: number | string;
}

export interface CustomerDetail {
  customer: Omit<CustomerRow, 'total_orders' | 'lifetime_spend'> & { updated_at: string };
  summary: { total_orders: number | string; total_spend: number | string };
  addresses: Array<{ id: number; label: string | null; recipient_name: string; phone: string; street_address: string; city: string; state: string; country: string; postal_code: string | null; is_default: number | boolean }>;
  orders: Array<{ id: number; order_number: string; grand_total: number | string; currency: string; order_status: OrderStatus; payment_status: string; created_at: string }>;
}

export function adminGetCustomers(params: { search?: string; status?: string; page?: number; per_page?: number }) {
  return apiRequest<Paginated<CustomerRow>>(`/admin/customers${qs(params)}`);
}

export function adminGetCustomer(id: number) {
  return apiRequest<CustomerDetail>(`/admin/customers/${id}`);
}

export function adminUpdateCustomerStatus(id: number, status: 'active' | 'suspended') {
  return send<{ id: number; status: string }>(`/admin/customers/${id}/status`, 'PATCH', { status });
}

// =============================================================================
// Payments & refunds
// =============================================================================

export interface PaymentRow {
  id: number;
  payment_reference: string;
  gateway: string;
  amount: number | string;
  amount_received: number | string | null;
  currency: string;
  payment_method: string | null;
  status: string;
  verified_at: string | null;
  created_at: string;
  updated_at: string;
  order_number: string | null;
  order_status: OrderStatus | null;
  order_payment_status: string | null;
  requires_attention: number | boolean | null;
  customer_name: string | null;
  customer_email: string | null;
}

export function adminGetPayments(params: { gateway?: string; status?: string; search?: string; page?: number; per_page?: number }) {
  return apiRequest<Paginated<PaymentRow>>(`/admin/payments${qs(params)}`);
}

export interface RefundRow {
  id: number;
  payment_id: number;
  order_id: number;
  order_number: string;
  amount: number | string;
  currency: string;
  reason: string;
  status: 'pending' | 'completed' | 'rejected';
  restocked: number | boolean;
  provider_refund_reference: string | null;
  customer_name: string | null;
  customer_email: string | null;
  payment_reference: string;
  gateway: string;
  processed_by_name: string | null;
  created_at: string;
  updated_at: string;
}

export interface RefundEligibility {
  order_number: string;
  order_status: OrderStatus;
  payment_status: string;
  currency: string;
  order_total: number;
  paid_amount: number;
  refunded_amount: number;
  pending_refund_amount: number;
  /** The most the next refund can be: a refund takes one payment at a time. */
  refundable_amount: number;
  /** Everything still refundable across all the order's payments. */
  refundable_total: number;
  payment_reference: string | null;
  gateway: string | null;
  can_restock: boolean;
}

export function adminGetRefunds(params: { status?: string; page?: number; per_page?: number }) {
  return apiRequest<Paginated<RefundRow>>(`/admin/refunds${qs(params)}`);
}

export function adminGetRefundEligibility(orderNumber: string) {
  return apiRequest<RefundEligibility>(`/admin/refunds/eligibility${qs({ order_number: orderNumber })}`);
}

export function adminCreateRefund(data: {
  order_number: string;
  amount: number;
  reason: string;
  status: 'completed' | 'pending';
  restock: boolean;
  provider_refund_reference?: string;
}) {
  return refreshAfter(send<RefundRow>('/admin/refunds', 'POST', data), refreshBellCounts);
}

export function adminResolveRefund(id: number, status: 'completed' | 'rejected') {
  return refreshAfter(send<RefundRow>(`/admin/refunds/${id}`, 'PATCH', { status }), refreshBellCounts);
}

// =============================================================================
// Promotions
// =============================================================================

export interface Promotion {
  id: number;
  code: string;
  description: string;
  discount_type: 'percent' | 'fixed';
  discount_percent: number;
  /** Fixed codes: the amount taken off. */
  discount_amount: number | null;
  /** The items subtotal must be at least this (null: no minimum). */
  min_order_amount: number | null;
  /** Percentage codes: never take off more than this (null: no cap). */
  max_discount_amount: number | null;
  /** e.g. "10% off (up to ₦10,000) on orders of ₦20,000+" */
  label: string;
  max_uses: number;
  used_count: number;
  is_active: boolean;
  /** The owner chose to advertise this code on the homepage. */
  show_on_homepage: boolean;
  /** This is the code the homepage shows right now. */
  on_homepage_now: boolean;
  starts_at: string | null;
  expires_at: string | null;
  created_at: string;
}

export interface PromotionInput {
  code: string;
  description: string;
  discount_type: 'percent' | 'fixed';
  discount_percent: number;
  discount_amount: number | null;
  min_order_amount: number | null;
  max_discount_amount: number | null;
  /** 0 = unlimited */
  max_uses: number;
  is_active: boolean;
  show_on_homepage: boolean;
  /** YYYY-MM-DD or null */
  starts_at: string | null;
  /** YYYY-MM-DD or null (the code works until the end of that day) */
  expires_at: string | null;
}

export function adminGetPromotions(params: { page?: number; per_page?: number } = {}) {
  return apiRequest<Paginated<Promotion>>(`/admin/promotions${qs(params)}`);
}

export function adminSavePromotion(id: number | null, data: PromotionInput) {
  return id ? send<{ id: number }>(`/admin/promotions/${id}`, 'PUT', data) : send<{ id: number }>('/admin/promotions', 'POST', data);
}

export function adminDeletePromotion(id: number) {
  return apiRequestFull<{ id: number; deleted: boolean }>(`/admin/promotions/${id}`, { method: 'DELETE' });
}

// =============================================================================
// Storefront: banners & reviews
// =============================================================================

export interface Banner {
  id: number;
  title: string;
  subtitle: string | null;
  cta_text: string | null;
  cta_url: string | null;
  image_url: string;
  is_active: number | boolean;
  display_order: number;
  created_at: string;
}

export type BannerInput = Pick<Banner, 'title' | 'image_url'> & {
  subtitle: string;
  cta_text: string;
  cta_url: string;
  is_active: boolean;
  display_order: number;
};

export interface ReviewRow {
  id: number;
  product_id: number;
  product_name: string;
  product_slug: string;
  author_name: string;
  author_email: string | null;
  rating: number;
  title: string | null;
  comment: string;
  status: 'pending' | 'approved' | 'rejected';
  is_verified_purchase: number | boolean;
  created_at: string;
}

export function adminGetBanners() {
  return apiRequest<Banner[]>('/admin/storefront/banners');
}

export function adminSaveBanner(id: number | null, data: BannerInput) {
  return id ? send<{ id: number }>(`/admin/storefront/banners/${id}`, 'PUT', data) : send<{ id: number }>('/admin/storefront/banners', 'POST', data);
}

export function adminDeleteBanner(id: number) {
  return send<{ id: number }>(`/admin/storefront/banners/${id}`, 'DELETE');
}

export function adminGetReviews(params: { status?: string; page?: number; per_page?: number }) {
  return apiRequest<Paginated<ReviewRow>>(`/admin/storefront/reviews${qs(params)}`);
}

export function adminUpdateReviewStatus(id: number, status: 'approved' | 'rejected' | 'pending') {
  return send<{ id: number; status: string }>(`/admin/storefront/reviews/${id}/status`, 'PATCH', { status });
}

// =============================================================================
// Categories
// =============================================================================

export interface AdminCategory {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  image_url: string | null;
  display_order: number;
  is_active: boolean;
  product_count: number;
  published_count: number;
}

export function adminGetCategories() {
  return apiRequest<AdminCategory[]>('/admin/categories');
}

export function adminSaveCategory(
  id: number | null,
  data: Partial<{ name: string; slug: string; description: string; image_url: string; display_order: number; is_active: boolean }>
) {
  return id ? send<AdminCategory>(`/admin/categories/${id}`, 'PUT', data) : send<AdminCategory>('/admin/categories', 'POST', data);
}

/** Hides the category from the store (it can be restored). */
export function adminDeactivateCategory(id: number) {
  return send<{ id: number }>(`/admin/categories/${id}`, 'DELETE');
}

// =============================================================================
// Delivery zones
// =============================================================================

export interface ShippingZone {
  id: number;
  name: string;
  code: string;
  description: string | null;
  states_covered: string[];
  /** Covers the first `items_included` items of an order. */
  fee: number;
  items_included: number;
  /** Added for each item after `items_included`. */
  extra_item_fee: number;
  /** Added once for each large or heavy item in the order (only when the shop charges for them). */
  heavy_item_fee?: number;
  estimated_days: string;
  is_active: boolean;
}

export type ShippingZoneInput = Omit<ShippingZone, 'id'>;

export function adminGetShippingZones() {
  return apiRequest<{ zones: ShippingZone[]; states: string[] }>('/admin/shipping-zones');
}

export function adminSaveShippingZone(id: number | null, data: ShippingZoneInput) {
  return id ? send<ShippingZone>(`/admin/shipping-zones/${id}`, 'PUT', data) : send<ShippingZone>('/admin/shipping-zones', 'POST', data);
}

export function adminDeleteShippingZone(id: number) {
  return send<{ id: number }>(`/admin/shipping-zones/${id}`, 'DELETE');
}

// =============================================================================
// Notifications (email outbox) & audit logs
// =============================================================================

export interface OutboxRow {
  id: number;
  recipient_email: string;
  subject: string;
  template: string;
  status: 'pending' | 'sent' | 'failed';
  /** How it went out: 'smtp' / 'resend' = handed to the mail service, 'log' = written to the mail log only. */
  transport: 'smtp' | 'resend' | 'log' | null;
  attempt_count: number;
  last_error: string | null;
  /** For pending emails: when the next delivery attempt is due. */
  next_attempt_at: string | null;
  created_at: string;
  sent_at: string | null;
}

export function adminGetOutbox(params: { status?: string; page?: number; per_page?: number }) {
  return apiRequest<Paginated<OutboxRow>>(`/admin/notifications/outbox${qs(params)}`);
}

/** Re-send one email; the server message says whether it was delivered or only logged. */
export function adminRetryOutbox(id: number) {
  return apiRequestFull<{ id: number }>(`/admin/notifications/outbox/${id}/retry`, { method: 'POST' });
}

/** Deliver one batch of queued emails now (same as scripts/process_outbox.php). */
export function adminProcessOutbox() {
  return apiRequestFull<{ processed: number; sent: number; retrying: number; failed: number; still_pending: number }>(
    '/admin/notifications/outbox/process',
    { method: 'POST', timeoutMs: SLOW_ACTION_TIMEOUT_MS }
  );
}

export interface AuditLogRow {
  id: number;
  admin_id: number | null;
  admin_name: string | null;
  admin_email: string | null;
  action: string;
  entity_type: string;
  entity_id: string | null;
  before_state: string | null;
  after_state: string | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
}

export function adminGetAuditLogs(params: { action?: string; entity_type?: string; page?: number; per_page?: number }) {
  return apiRequest<Paginated<AuditLogRow> & { filters: { actions: string[]; entity_types: string[] } }>(`/admin/audit-logs${qs(params)}`);
}

// =============================================================================
// Settings, system status, account
// =============================================================================

export type PaymentMethodId = 'monnify' | 'kora' | 'whatsapp';

/** enabled = offered at checkout: the admin switch is on and backend/.env allows it. */
export interface PaymentMethodStatus {
  enabled: boolean;
  admin_on: boolean;
  server_allows: boolean;
}

export interface StoreSettings {
  editable: Array<{ key: string; value: string; help: string }>;
  readonly: {
    currency: string;
    timezone: string;
    kora_mode: 'live' | 'sandbox' | 'not_configured';
    monnify_mode: 'live' | 'sandbox' | 'not_configured';
    mail_mode: 'smtp' | 'resend' | 'log_only';
    payment_methods: Record<PaymentMethodId, PaymentMethodStatus>;
    /** The gateway the admin chose for the storefront's single "Pay now" button. */
    primary_gateway: 'monnify' | 'kora';
    /** The gateway "Pay now" actually uses now (the other one if the chosen one is off). */
    pay_now_gateway: 'monnify' | 'kora' | null;
    order_payment_window_minutes: number;
    manual_payment_window_hours: number;
    /** Where new-order, payment and storage alerts go right now ("Send admin alerts to", else every active admin). */
    admin_alert_recipients: string[];
  };
}

export function adminGetSettings() {
  return apiRequest<StoreSettings>('/admin/settings');
}

export function adminUpdateSettings(changes: Record<string, string>) {
  return send<Record<string, string>>('/admin/settings', 'PUT', changes);
}

/** Switch checkout payment methods on or off (at least one must stay available). */
export function adminUpdatePaymentMethods(changes: Partial<Record<PaymentMethodId, boolean>> & { primary_gateway?: 'monnify' | 'kora' }) {
  return send<Record<PaymentMethodId, PaymentMethodStatus>>('/admin/settings/payment-methods', 'PUT', changes);
}

export interface DevSwitch {
  key: string;
  label: string;
  what: string;
  when_off: string;
  on: boolean;
  recommended: boolean;
  /** False when the technology is not set up on this server, so the switch would do nothing. */
  available: boolean;
  note: string | null;
}

export interface DevMode {
  on: boolean;
  /** Only sent while dev mode is on. */
  switches?: DevSwitch[];
  fixed?: Array<{ name: string; state: string; why: string }>;
  warnings?: string[];
}

export function adminGetDevMode(signal?: AbortSignal) {
  return apiRequest<DevMode>('/admin/dev-mode', { signal });
}

/** Turning on needs the admin's password. */
export function adminSetDevMode(on: boolean, password?: string) {
  return refreshAfter(send<DevMode>('/admin/dev-mode', 'POST', on ? { on, password } : { on }), refreshDevMode);
}

export function adminUpdateDevSwitch(key: string, on: boolean) {
  return refreshAfter(send<DevMode>('/admin/dev-mode/switches', 'PUT', { key, on }), refreshDevMode);
}

export function adminResetDevSwitches() {
  return refreshAfter(send<DevMode>('/admin/dev-mode/switches', 'PUT', { reset: true }), refreshDevMode);
}

export interface SystemStatus {
  system_health: 'healthy' | 'degraded';
  server_time: string;
  environment: 'production' | 'development';
  /** PHP version, required extensions that are missing (the API needs them), and whether OPcache is on. */
  /** upload_limit_bytes: the largest upload PHP accepts (0 = no limit); pictures need 5 MB. */
  runtime: { php: string; missing_extensions: string[]; missing_backup_extensions: string[]; opcache: boolean; upload_limit_bytes: number };
  /** The admin's address as the API sees it; a private or local address on a live server means the proxy is not set up. */
  network: { client_ip: string; public_address: boolean };
  /** Optional Redis for the catalogue cache: set up or not, answering now, and the last outage marker. */
  redis: { configured: boolean; switched_off: boolean; ok: boolean | null; ms: number | null; error: string | null; down_marker: boolean };
  /** Live-server settings that would break the shop (empty outside production). */
  config_warnings: string[];
  database: { connected: boolean; latency_ms: number | null };
  migrations: { applied: number; latest: string | null };
  /** driver: where pictures are kept ('local' = the server's disk). */
  storage: { uploads_writable: boolean; driver: string };
  payments: { kora: string; monnify: string };
  /** oldest_due_minutes: age of the oldest email waiting to be sent (null when none); large values mean the outbox worker is not running. */
  mail: { mode: 'smtp' | 'resend' | 'log_only'; outbox_pending: number; outbox_failed: number; oldest_due_minutes: number | null };
  /** Whether admin changes refresh the storefront's cached pages at once, and the last attempt's outcome. */
  storefront_refresh: { configured: boolean; last: { at: string; ok: boolean; error: string | null; ms: number } | null };
  orders: { needs_attention: number; unpaid_overdue: number; awaiting_fulfilment: number };
}

/** Open without dev mode: the Email outbox page is for everyday use. */
export function adminGetOutboxStatus() {
  return apiRequest<SystemStatus['mail']>('/admin/notifications/outbox/status');
}

export function adminGetSystemStatus() {
  return apiRequest<SystemStatus>('/admin/system-status');
}

// =============================================================================
// Data & storage (retention and database health)
// =============================================================================

export type RetentionKey = 'retention_customer_days' | 'retention_unpaid_days' | 'retention_message_days' | 'retention_activity_days';
export type DataSettingKey = RetentionKey | 'storage_alert_mb';
export type DataSettings = Record<DataSettingKey, number>;
export type HealthLevel = 'ok' | 'warning' | 'critical';

export interface HealthIssue {
  level: Exclude<HealthLevel, 'ok'>;
  code: 'size' | 'slow' | 'queries' | 'due' | 'backlog' | 'pictures_not_checked';
  title: string;
  detail: string;
}

export interface CleanupRun {
  at: string;
  /** The admin who confirmed it. */
  by: string | null;
  removed: Record<string, number>;
}

export interface StorageHealthReport {
  level: HealthLevel;
  issues: HealthIssue[];
  checked_at: string;
  /** The moment every list on the page is computed for (sent back when reviewing and confirming). */
  as_of: string;
  database: {
    size_bytes: number;
    alert_mb: number;
    used_percent: number;
    tables: Array<{ name: string; rows: number; bytes: number; free_bytes: number }>;
  };
  slow_requests: {
    threshold_ms: number;
    last_24h: number;
    slowest_ms: number;
    routes: Array<{ method: string; route: string; hits: number; avg_ms: number; max_ms: number }>;
  };
  queries?: { typical_ms: number };
  /**
   * due: records past the keep periods per rule (nothing is removed until an admin confirms).
   * note: why unused pictures are missing from `due` (their storage can't be reached), else null.
   */
  cleanup: { due: Record<string, number>; pictures_checked?: boolean; note?: string | null; last_run: CleanupRun | null; days_since: number | null };
}

export interface DataOverview {
  settings: DataSettings;
  options: Record<DataSettingKey, number[]>;
  labels: Record<string, string>;
  /** Rules for technical rows with no customer or business data. */
  housekeeping: string[];
  health: StorageHealthReport;
  mail_mode: 'smtp' | 'resend' | 'log_only';
}

export interface DataRecordColumn {
  key: string;
  label: string;
  type: 'text' | 'date' | 'money' | 'number' | 'code';
}

export interface DataRecords {
  rule: string;
  label: string;
  columns: DataRecordColumn[];
  items: Array<Record<string, string | number | null>>;
  pagination: PaginationMeta;
}

export interface CleanupChallenge {
  confirmation_id: string;
  sent_to: string;
  expires_at: string;
  counts: Record<string, number>;
  mail_mode: 'smtp' | 'resend' | 'log_only';
}

export function adminGetDataOverview() {
  return apiRequest<DataOverview>('/admin/data');
}

export function adminGetStorageAlert(signal?: AbortSignal) {
  return apiRequest<{ level: HealthLevel; issues: HealthIssue[] }>('/admin/data/alert', { signal });
}

export function adminUpdateDataSettings(data: Partial<DataSettings>) {
  return refreshAfter(send<DataSettings>('/admin/data/settings', 'PUT', data), refreshStorageAlert);
}

export function adminGetDataRecords(rule: string, asOf: string, page: number) {
  return apiRequest<DataRecords>(`/admin/data/records${qs({ rule, as_of: asOf, page })}`);
}

/** Step 1 of a clean-up: the admin's password; a one-time code is emailed to them. */
/** `skip`: the review lines the admin unticked; the code and the clean-up cover the rest. */
export function adminVerifyCleanup(password: string, asOf: string, skip: string[] = []) {
  return send<CleanupChallenge>('/admin/data/cleanup/verify', 'POST', { password, as_of: asOf, ...(skip.length > 0 ? { skip } : {}) });
}

/** Step 2: the emailed code; removes exactly the records reviewed as of the challenge's time. */
export function adminRunCleanup(confirmationId: string, code: string) {
  // Removing many pictures from the bucket can take a while: wait up to two minutes.
  return refreshAfter(
    send<{ removed: Record<string, number> }>('/admin/data/cleanup', 'POST', { confirmation_id: confirmationId, code }, { timeoutMs: SLOW_ACTION_TIMEOUT_MS }),
    refreshStorageAlert
  );
}

export interface AdminAccount {
  id: number;
  uuid: string;
  email: string;
  full_name: string;
  phone: string | null;
  role: string;
  created_at: string;
  updated_at: string;
}

export function adminGetAccount() {
  return apiRequest<AdminAccount>('/admin/account');
}

/** Changing `email` (the sign-in email) or setting `new_password` needs `current_password`. */
/** Fields left out are kept as they are on the server. */
export function adminUpdateAccount(data: { full_name?: string; phone?: string; email?: string; current_password?: string; new_password?: string }) {
  return apiRequestFull<{ id: number; full_name: string; phone: string; email: string }>('/admin/account', {
    method: 'PUT',
    body: JSON.stringify(data),
  });
}

// =============================================================================
// Admin team
// =============================================================================

export interface AdminTeamMember {
  id: number;
  email: string;
  full_name: string;
  role: 'admin' | 'super_admin';
  status: 'active' | 'unverified' | 'suspended';
  created_at: string;
  last_seen_at: string | null;
  is_you: boolean;
}

export function adminListAdmins() {
  return apiRequest<{ items: AdminTeamMember[]; min_password_length: number }>('/admin/admins');
}

/** Leave `password` empty to have one made up; it comes back once as generated_password. */
export function adminCreateAdmin(data: { full_name: string; email: string; password?: string; current_password: string }) {
  return apiRequestFull<AdminTeamMember & { generated_password: string | null }>('/admin/admins', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export function adminSetAdminStatus(id: number, status: 'active' | 'suspended', currentPassword: string) {
  return apiRequestFull<{ id: number; status: string }>(`/admin/admins/${id}/status`, {
    method: 'PATCH',
    body: JSON.stringify({ status, current_password: currentPassword }),
  });
}

/** Removes the admin account for good. The activity log keeps their name on what they did. */
export function adminDeleteAdmin(id: number, currentPassword: string) {
  return apiRequestFull<{ id: number }>(`/admin/admins/${id}/delete`, {
    method: 'POST',
    body: JSON.stringify({ current_password: currentPassword }),
  });
}

// =============================================================================
// Marketing (newsletter subscribers + registered customers)
// =============================================================================

export interface Subscriber {
  email: string;
  status: 'active' | 'unsubscribed';
  /** subscriber: joined from the store footer. customer: on the list because they have an account. */
  origin: 'subscriber' | 'customer';
  /** The email has an active customer account (also true for a subscriber who registered). */
  has_account: boolean;
  subscribed_at: string;
  unsubscribed_at: string | null;
}

export function adminGetSubscribers(params: { status?: string; origin?: string; page?: number; per_page?: number }) {
  return apiRequest<
    Paginated<Subscriber> & { counts: { active: number; unsubscribed: number; active_subscribers: number; active_customers: number } }
  >(
    // The server defaults to 'active'; 'any' (not one of its statuses) lists everyone.
    `/admin/marketing/subscribers${qs({
      status: params.status === 'all' ? 'any' : params.status ?? 'active',
      origin: params.origin && params.origin !== 'all' ? params.origin : undefined,
      page: params.page,
      per_page: params.per_page,
    })}`
  );
}

/** confirm_recipients must equal the active count (subscribers + customers) the admin saw and confirmed. */
export function adminSendBroadcast(data: { subject: string; content: string; confirm_recipients: number }) {
  // already_queued_today: subscribers who already had this exact newsletter queued today (not sent twice).
  return send<{ queued_count: number; already_queued_today: number; mail_mode: string }>('/admin/marketing/broadcast', 'POST', data, { timeoutMs: SLOW_ACTION_TIMEOUT_MS });
}
