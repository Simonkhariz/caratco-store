import { SHOP } from '@/lib/shop';

/**
 * Artwork for the homepage "Browse by ..." tiles (the styles themselves come from the live
 * catalogue). The pictures are set in backend/config/shop.json under style.images, keyed by the
 * style's name in lower case; a style with no picture keeps its dark tile with the name.
 * When a picture is replaced, give the file a new name: browsers and the image optimiser keep
 * pictures by address, so a new file under an old name can keep showing the old one.
 */
export const DRESS_STYLE_IMAGES: Record<string, string> = Object.fromEntries(
  Object.entries(SHOP.style.images ?? {}).map(([name, src]) => [name.trim().toLowerCase(), src])
);
