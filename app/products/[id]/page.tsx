import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import { cache } from 'react';
import { notFound } from 'next/navigation';
import { ProductDetailClient } from '@/components/product/ProductDetailClient';
import type { ApiProductDetail } from '@/lib/api-client';
import { SHARE_HEIGHT, SHARE_WIDTH, pictureVersion } from '@/lib/share-image';

const BACKEND_ORIGIN = (process.env.BACKEND_ORIGIN || 'http://127.0.0.1:8000').replace(/\/$/, '');

type ProductMeta = ApiProductDetail & {
  images?: Array<{ image_url: string; is_primary?: number | boolean }>;
};

/**
 * Server-side product lookup, shared by the metadata and the page (Next.js runs the fetch once),
 * so the page is sent with the product already in it; the client then refreshes stock.
 * Returns 'missing' only when the API says the product does not exist; network errors and a slow
 * API (the same 4 s as lib/server-api.ts) return null, and the browser then loads the product.
 * A fetch with a signal isn't shared by Next.js, hence React's cache() around it.
 */
const lookupProduct = cache(async (idOrSlug: string): Promise<ProductMeta | 'missing' | null> => {
  try {
    const res = await fetch(`${BACKEND_ORIGIN}/api/v1/products/${encodeURIComponent(idOrSlug)}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });
    if (res.status === 404) return 'missing';
    if (!res.ok) return null;
    const json = (await res.json()) as { success?: boolean; data?: ProductMeta };
    return json.success && json.data ? json.data : null;
  } catch {
    return null;
  }
});

function summarise(text: string | null | undefined, max = 155): string | undefined {
  const clean = (text ?? '').replace(/\s+/g, ' ').trim();
  if (!clean) return undefined;
  return clean.length <= max ? clean : `${clean.slice(0, max - 1).replace(/\s+\S*$/, '')}…`;
}

export async function generateMetadata({ params }: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const { id } = await params;
  const product = await lookupProduct(id);
  if (product === 'missing') {
    return { title: `Product not found | ${SHOP.name}`, robots: { index: false, follow: false } };
  }
  if (!product) {
    return { title: `Shop | ${SHOP.name}` };
  }

  const description = summarise(product.description);
  const primary =
    product.images?.find((img) => img.is_primary === true || img.is_primary === 1)?.image_url ??
    product.images?.[0]?.image_url ??
    product.primary_image ??
    null;
  // A shared product link shows the product's own picture: /share/product/{id} sends it as a
  // JPEG of the size chat apps expect (lib/share-image.ts). The tag on the end changes with the
  // picture, so a new main picture replaces the one an app has kept. With no picture at all,
  // the link shows the shop's hero picture like any other page.
  const sharePicture = primary
    ? `/share/product/${encodeURIComponent(String(product.id))}?v=${pictureVersion(primary)}`
    : '/share/home';
  const title = `${product.name} | ${SHOP.name}`;

  return {
    title,
    description,
    openGraph: {
      title: product.name,
      description,
      type: 'website',
      siteName: SHOP.name,
      url: `/products/${encodeURIComponent(id)}`,
      images: [{ url: sharePicture, width: SHARE_WIDTH, height: SHARE_HEIGHT, type: 'image/jpeg', alt: product.name }],
    },
    twitter: { card: 'summary_large_image', title: product.name, description, images: [sharePicture] },
  };
}

export default async function ProductDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const product = await lookupProduct(id);
  if (product === 'missing') notFound();
  return <ProductDetailClient id={decodeURIComponent(id)} initial={product} />;
}
