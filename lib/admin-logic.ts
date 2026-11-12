/**
 * Small rules behind the admin screens, kept free of React and of '@/' imports so
 * tests/admin-logic.test.mjs can run them without a browser.
 */

type Query = Record<string, string | number | boolean | null | undefined>;

/**
 * Query string for an admin API call. Empty values and the filter value 'all' ("no filter") are
 * left out; a date `range` of 'all' is kept, because the API reads a missing range as 30 days.
 */
export function queryString(params?: Query): string {
  if (!params) return '';
  const q = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return;
    if (value === 'all' && key !== 'range') return;
    q.set(key, String(value));
  });
  const s = q.toString();
  return s ? `?${s}` : '';
}

/** The text as a whole number, or null: 12.5, 1e3 and empty are refused instead of being cut down. */
export function wholeNumber(text: string): number | null {
  const t = text.trim();
  if (!/^[+-]?\d+$/.test(t)) return null;
  const n = Number(t);
  return Number.isSafeInteger(n) ? n : null;
}

/**
 * The page to move to when a list came back empty because its page is past the end (the last
 * row of page 2 was shipped, or an old link says ?page=9999); null for any other answer.
 */
export function pastEndPage(data: unknown): number | null {
  if (!data || typeof data !== 'object') return null;
  const { items, pagination } = data as { items?: unknown; pagination?: { page?: number; per_page?: number; total?: number; total_pages?: number } };
  if (!Array.isArray(items) || items.length > 0 || !pagination) return null;
  const page = Number(pagination.page);
  const total = Number(pagination.total);
  if (!(total > 0) || !(page > 1)) return null;
  const last = Number(pagination.total_pages) || Math.ceil(total / (Number(pagination.per_page) || total));
  return page > last ? Math.max(1, last) : null;
}

/**
 * The search box when the URL's search value changes. `pending` lists what this box wrote that
 * has not reached the URL yet: when one of those arrives it is ours, and the box keeps whatever
 * has been typed since. Anything else came from outside (Back, Forward, a link), and the box follows.
 */
export function searchBoxOnUrlChange(search: string, text: string, pending: string[]): { text: string; pending: string[] } {
  const ours = pending.indexOf(search);
  if (ours !== -1) return { text, pending: pending.slice(ours + 1) };
  return { text: search === text.trim() ? text : search, pending: [] };
}

/**
 * Dialogs open on top of each other. Only the top one answers keys, and the page is locked from
 * the first opening until the last closing, whichever order React closes them in.
 */
export function createModalStack(lockPage: () => () => void) {
  const open: object[] = [];
  let unlockPage: (() => void) | null = null;
  return {
    enter(): object {
      if (open.length === 0) unlockPage = lockPage();
      const modal = {};
      open.push(modal);
      return modal;
    },
    leave(modal: object): void {
      const index = open.indexOf(modal);
      if (index !== -1) open.splice(index, 1);
      if (open.length === 0 && unlockPage) {
        unlockPage();
        unlockPage = null;
      }
    },
    isTop(modal: object): boolean {
      return open[open.length - 1] === modal;
    },
  };
}

/** A slug the API refuses because it would be read as an ID: 501, 2e3 (PHP's is_numeric). */
export function isNumericSlug(slug: string): boolean {
  return /^-?\d+(e-?\d+)?$/.test(slug);
}

/** A web address from a name, with the prefix when the name alone would be only a number. */
export function slugify(value: string, prefix: 'product' | 'category'): string {
  const slug = value
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return isNumericSlug(slug) ? `${prefix}-${slug}` : slug;
}

/**
 * The customer said they sent a transfer but the order was cancelled before anyone checked. The
 * money can still be recorded (the order stays cancelled); same rule as PaymentFinalizer.
 */
export function canRecordLatePayment(order: { order_status: string; payment_status: string; customer_marked_paid_at?: string | null }): boolean {
  return order.order_status === 'cancelled' && !!order.customer_marked_paid_at && ['pending', 'failed', 'cancelled'].includes(order.payment_status);
}

/**
 * The amount a refund form starts with: all that can go back, or for a customer who overpaid an
 * order that still stands, just the extra (they keep what they bought).
 */
export function refundStartAmount(e: {
  order_status: string;
  order_total: number;
  paid_amount: number;
  refunded_amount: number;
  pending_refund_amount: number;
  refundable_amount: number;
}): string {
  if (e.refundable_amount <= 0) return '';
  const extra = Math.round((e.paid_amount - e.refunded_amount - e.pending_refund_amount - e.order_total) * 100) / 100;
  const orderStands = !['cancelled', 'refunded'].includes(e.order_status);
  return String(orderStands && extra > 0 ? Math.min(extra, e.refundable_amount) : e.refundable_amount);
}

/** States listed by more than one active zone (only older data: the API now refuses a new overlap). */
export function zoneOverlaps(zones: Array<{ name: string; is_active: boolean; states_covered: string[] }>): Array<{ state: string; zones: string[] }> {
  const byState = new Map<string, string[]>();
  zones
    .filter((z) => z.is_active)
    .forEach((z) => {
      new Set(z.states_covered).forEach((state) => byState.set(state, [...(byState.get(state) ?? []), z.name]));
    });
  return Array.from(byState, ([state, names]) => ({ state, zones: names })).filter((o) => o.zones.length > 1);
}

/**
 * What is wrong with each variant row's pair of options (already trimmed, '' = the product does
 * not use that option). A row with neither option is fine only when it is the product's one row,
 * and a pair may be listed once (the first row keeps it, later rows are the duplicates).
 */
export function optionPairProblems(pairs: Array<[string, string]>): Array<'empty' | 'duplicate' | null> {
  const seen = new Set<string>();
  return pairs.map(([first, second]) => {
    if (!first && !second) return pairs.length > 1 ? 'empty' : null;
    const key = `${first.toLowerCase()}|${second.toLowerCase()}`;
    if (seen.has(key)) return 'duplicate';
    seen.add(key);
    return null;
  });
}

/**
 * The specification rows worth saving: trimmed, without the rows that have no value (a suggested
 * name nobody filled in). `unnamed` counts the rows with a value but no name, which the form refuses.
 */
export function specificationRows(rows: Array<{ name: string; value: string }>): { rows: Array<{ name: string; value: string }>; unnamed: number } {
  const filled = rows.map((r) => ({ name: r.name.trim(), value: r.value.trim() })).filter((r) => r.value !== '');
  return { rows: filled.filter((r) => r.name !== ''), unnamed: filled.filter((r) => r.name === '').length };
}

/** Sort the results of uploading several files at once: what worked, and what failed by name. */
export function splitUploads<F extends { name: string }, R>(
  files: F[],
  settled: Array<PromiseSettledResult<R>>
): { uploaded: R[]; failed: Array<{ name: string; reason: unknown }> } {
  const uploaded: R[] = [];
  const failed: Array<{ name: string; reason: unknown }> = [];
  settled.forEach((result, i) => {
    if (result.status === 'fulfilled') uploaded.push(result.value);
    else failed.push({ name: files[i].name, reason: result.reason });
  });
  return { uploaded, failed };
}

/** The message naming the files that could not be uploaded, with the first file's reason. */
export function uploadFailureMessage(failed: Array<{ name: string; reason: unknown }>): string {
  const first = failed[0]?.reason;
  const why = first instanceof Error && first.message ? ` ${first.message}` : '';
  return failed.length === 1 ? `"${failed[0].name}" could not be uploaded.${why}` : `Not uploaded: ${failed.map((f) => f.name).join(', ')}.${why}`;
}
