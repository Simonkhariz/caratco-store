/**
 * The shop's clock. The API sends times as MySQL "YYYY-MM-DD HH:MM:SS" in Lagos time (no offset),
 * so reading them as the viewer's local time puts them hours off for anyone in another timezone.
 */
export const SHOP_TIME_ZONE = 'Africa/Lagos';

/** Lagos is UTC+1 all year (no daylight saving). */
const SHOP_OFFSET = '+01:00';

/** A time from the API as an exact moment. Invalid or empty values give an Invalid Date. */
export function parseShopTime(value: string | null | undefined): Date {
  const text = String(value ?? '').trim().replace(' ', 'T');
  if (!text) return new Date(NaN);
  if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return new Date(`${text}T00:00:00${SHOP_OFFSET}`);
  return new Date(/T.*(?:Z|[+-]\d{2}(?::?\d{2})?)$/i.test(text) ? text : `${text}${SHOP_OFFSET}`);
}

/**
 * A deadline as the shopper reads it, in the shop's timezone: the time alone when it is today,
 * otherwise with the date ("7 Nov, 04:10"), so a next-day deadline never looks already past.
 */
export function formatShopDeadline(value: string | null | undefined, now: Date = new Date()): string | null {
  const when = parseShopTime(value);
  if (!Number.isFinite(when.getTime())) return null;
  const dayOf = (d: Date) => d.toLocaleDateString('en-CA', { timeZone: SHOP_TIME_ZONE });
  const time = when.toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit', timeZone: SHOP_TIME_ZONE });
  if (dayOf(when) === dayOf(now)) return time;
  const date = when.toLocaleDateString('en-NG', { day: 'numeric', month: 'short', timeZone: SHOP_TIME_ZONE });
  return `${date}, ${time}`;
}
