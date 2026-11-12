import NextImage, { type ImageProps } from 'next/image';
import { canOptimizeImage } from '@/lib/image';

/**
 * next/image for store pictures. The shop's own images (/images/..., /uploads/...) are resized for
 * each screen; a link to another site is shown as it is, because the image optimiser only accepts
 * the shop's own images (it cannot be used to fetch pictures from elsewhere).
 */
export default function StoreImage(props: ImageProps) {
  const src = typeof props.src === 'string' ? props.src : null;
  return <NextImage {...props} unoptimized={props.unoptimized ?? (src !== null && !canOptimizeImage(src))} />;
}
