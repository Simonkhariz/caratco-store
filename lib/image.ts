/**
 * Whether next/image may resize this picture. Only the shop's own images (/images/...,
 * /uploads/...) are resized for the visitor's screen; next.config.mjs allows no outside hosts,
 * so the image optimiser cannot be used to fetch pictures from other sites. A link to another
 * site is shown as it is (see components/ui/StoreImage.tsx).
 */
export function canOptimizeImage(src: string | null | undefined): boolean {
  if (!src) return false;
  return src.startsWith('/') && !src.startsWith('//') && !src.toLowerCase().endsWith('.svg');
}
