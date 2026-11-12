import type { HomepageData } from '@/lib/api-client';
import { serverApiGet } from '@/lib/server-api';
import { DEFAULT_HERO, sharePictureResponse } from '@/lib/share-image';

export const dynamic = 'force-dynamic';

/**
 * GET /share/home: the picture shown when the shop's link is shared (see lib/share-image.ts).
 * The first banner the owner set, or the shop's own hero art when there is none.
 */
export async function GET(): Promise<Response> {
  const home = await serverApiGet<HomepageData>('/storefront/homepage');
  const banner = home?.banners?.find((b) => Boolean(b.image_url))?.image_url ?? null;
  const src = banner || DEFAULT_HERO;
  return sharePictureResponse(src, src === DEFAULT_HERO ? 'hero' : 'banner');
}
