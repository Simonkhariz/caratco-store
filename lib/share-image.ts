import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { headers } from 'next/headers';
import { SHOP } from '@/lib/shop';

/**
 * Pictures for link previews (WhatsApp, Facebook, X, iMessage and the rest).
 *
 * The shop keeps its pictures as WebP, which some of those apps still refuse in a preview, and
 * they want one wide picture of a sensible weight. So a shared link points at /share/..., which
 * sends a 1200 x 630 JPEG made from the shop's own picture:
 *   /share/home            the hero picture (the first banner, or the shop's own hero art)
 *   /share/product/{id}    that product's main picture, whole, on the product card colour
 * Server only: used by the two route handlers and by the pages' metadata.
 */

const BACKEND_ORIGIN = (process.env.BACKEND_ORIGIN || 'http://127.0.0.1:8000').replace(/\/$/, '');

export const SHARE_WIDTH = 1200;
export const SHARE_HEIGHT = 630;
/** The shop's own hero art (backend/config/shop.json), shown when the owner has set no banner. */
export const DEFAULT_HERO = SHOP.hero.image;
/** Behind the hero art on the home page, and behind a product on its card (the shop's own colours). */
const HERO_BACKGROUND = SHOP.theme.hero;
const PRODUCT_BACKGROUND = SHOP.theme.card;
const MAX_SOURCE_BYTES = 20 * 1024 * 1024;

export type ShareLook = 'hero' | 'banner' | 'product';

/**
 * The shop's public address, for the absolute links a preview needs. SITE_URL when the host
 * sets it; otherwise the address this request came to (pages are built for each visit).
 */
export async function siteOrigin(): Promise<string> {
  const fixed = (process.env.SITE_URL || '').trim().replace(/\/+$/, '');
  if (/^https?:\/\/[^\s/]+$/i.test(fixed)) return fixed;
  const h = await headers();
  const first = (value: string | null) => (value ?? '').split(',')[0].trim();
  const host = first(h.get('x-forwarded-host')) || first(h.get('host'));
  if (!/^[a-z0-9.-]+(:\d{1,5})?$/i.test(host)) return 'http://localhost:3000';
  const local = /^(localhost|127\.|10\.|192\.168\.)/i.test(host);
  const asked = first(h.get('x-forwarded-proto')).toLowerCase();
  const proto = asked === 'http' || asked === 'https' ? asked : local ? 'http' : 'https';
  return `${proto}://${host}`;
}

/** A short tag that changes when the picture does, so apps fetch the new one. */
export function pictureVersion(src: string): string {
  let hash = 5381;
  for (let i = 0; i < src.length; i += 1) hash = ((hash * 33) ^ src.charCodeAt(i)) >>> 0;
  return hash.toString(36);
}

/** True for a picture the shop holds itself (the site's own files, or an upload). */
function isOwnPicture(src: string): boolean {
  return (src.startsWith('/images/') || src.startsWith('/uploads/')) && !src.includes('..') && !src.includes('\\');
}

async function loadOwnPicture(src: string): Promise<Buffer | null> {
  try {
    if (src.startsWith('/images/')) {
      return await readFile(path.join(process.cwd(), 'public', ...src.split('/').filter(Boolean).map(decodeURIComponent)));
    }
    const res = await fetch(`${BACKEND_ORIGIN}${src}`, { cache: 'no-store', signal: AbortSignal.timeout(8000) });
    if (!res.ok) return null;
    const bytes = Buffer.from(await res.arrayBuffer());
    return bytes.length > 0 && bytes.length <= MAX_SOURCE_BYTES ? bytes : null;
  } catch {
    return null;
  }
}

async function toShareJpeg(source: Buffer, look: ShareLook): Promise<Buffer> {
  const sharp = (await import('sharp')).default;
  const image = sharp(source, { limitInputPixels: 60_000_000 }).rotate();
  if (look === 'product') {
    // The whole product, never cropped: a tall photo sits in the middle of the card colour.
    return image
      .resize(SHARE_WIDTH, SHARE_HEIGHT, { fit: 'contain', background: PRODUCT_BACKGROUND })
      .flatten({ background: PRODUCT_BACKGROUND })
      .jpeg({ quality: 84, mozjpeg: true })
      .toBuffer();
  }
  // The hero picture fills the frame. Hero art and banners are usually taller than the frame,
  // so it is filled from the top: faces stay in the picture.
  return image
    .flatten({ background: HERO_BACKGROUND })
    .resize(SHARE_WIDTH, SHARE_HEIGHT, { fit: 'cover', position: 'top' })
    .jpeg({ quality: 84, mozjpeg: true })
    .toBuffer();
}

// The same few pictures are asked for again and again when a link is passed around.
const KEPT = 40;
const made = new Map<string, Buffer>();

function remember(key: string, jpeg: Buffer): void {
  made.delete(key);
  made.set(key, jpeg);
  while (made.size > KEPT) {
    const oldest = made.keys().next().value;
    if (oldest === undefined) break;
    made.delete(oldest);
  }
}

/**
 * The answer for a share picture: the JPEG, or the picture as it is when it lives on another
 * site or cannot be converted on this host, or 404 when there is nothing to show.
 */
export async function sharePictureResponse(src: string | null | undefined, look: ShareLook): Promise<Response> {
  if (!src) return new Response('Not found', { status: 404 });
  if (/^https:\/\//i.test(src)) return Response.redirect(src, 307);
  if (!isOwnPicture(src)) return new Response('Not found', { status: 404 });

  const key = `${look}:${src}`;
  let jpeg = made.get(key) ?? null;
  if (!jpeg) {
    const source = await loadOwnPicture(src);
    if (!source) return new Response('Not found', { status: 404 });
    try {
      jpeg = await toShareJpeg(source, look);
      remember(key, jpeg);
    } catch {
      // No picture library on this host: the original picture is better than no picture.
      return Response.redirect(`${await siteOrigin()}${src}`, 307);
    }
  }
  return new Response(new Uint8Array(jpeg), {
    status: 200,
    headers: {
      'Content-Type': 'image/jpeg',
      'Content-Length': String(jpeg.length),
      'Cache-Control': 'public, max-age=86400',
    },
  });
}
