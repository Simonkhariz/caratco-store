import { serverApiGet } from '@/lib/server-api';
import { sharePictureResponse } from '@/lib/share-image';

export const dynamic = 'force-dynamic';

type ProductPictures = {
  primary_image?: string | null;
  images?: Array<{ image_url: string; is_primary?: number | boolean }>;
};

/**
 * GET /share/product/{id or slug}: the picture shown when that product's link is shared
 * (see lib/share-image.ts). The product's main picture, whole.
 */
export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }): Promise<Response> {
  const { id } = await params;
  const product = await serverApiGet<ProductPictures>(`/products/${encodeURIComponent(id)}`);
  const src =
    product?.images?.find((img) => img.is_primary === true || img.is_primary === 1)?.image_url ??
    product?.images?.[0]?.image_url ??
    product?.primary_image ??
    null;
  return sharePictureResponse(src, 'product');
}
