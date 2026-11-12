import { createHash, timingSafeEqual } from 'node:crypto';
import { revalidatePath } from 'next/cache';

/**
 * POST /api/revalidate, called by the PHP API after an admin change (StorefrontRefresh.php).
 * Marks every cached page and server fetch under the root layout as stale, so the next visit
 * to any page is rebuilt with current catalogue, banners and settings.
 *
 * Needs REVALIDATE_SECRET (server-only, at least 32 characters, the same value as in
 * backend/.env) sent in the X-Revalidate-Secret header. Without it the endpoint is off (404)
 * and pages still refresh on their own 60-second timer.
 */
const MIN_SECRET_LENGTH = 32;
const NO_STORE = { 'Cache-Control': 'no-store' };

const digest = (value: string) => createHash('sha256').update(value).digest();

export async function POST(request: Request) {
  const secret = process.env.REVALIDATE_SECRET?.trim() ?? '';
  if (secret.length < MIN_SECRET_LENGTH) {
    return Response.json({ ok: false, error: 'Not found' }, { status: 404, headers: NO_STORE });
  }

  const given = request.headers.get('x-revalidate-secret') ?? '';
  if (!timingSafeEqual(digest(given), digest(secret))) {
    return Response.json({ ok: false, error: 'Unauthorized' }, { status: 401, headers: NO_STORE });
  }

  revalidatePath('/', 'layout');
  return Response.json({ ok: true, revalidated: 'all pages' }, { headers: NO_STORE });
}
