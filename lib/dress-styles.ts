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

/** Tile widths out of 12 columns, two tiles to a row: narrow and wide, then wide and narrow. */
const TILE_SPANS = [4, 8, 8, 4] as const;

/**
 * How wide the tile at `index` is when there are `count` tiles. With an odd number the last
 * tile would sit alone beside an empty space, so it takes the whole row.
 */
export function styleTileSpan(index: number, count: number): 4 | 8 | 12 {
  if (count % 2 === 1 && index === count - 1) return 12;
  return TILE_SPANS[index % TILE_SPANS.length];
}
