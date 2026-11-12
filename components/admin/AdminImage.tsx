'use client';

import { useState } from 'react';
import Image from 'next/image';
import { canOptimizeImage } from '@/lib/image';

type AdminImageProps = {
  src: string | null | undefined;
  alt: string;
  className?: string;
  /** Shown instead when there is no image or it can't be loaded. */
  fallback?: string;
  sizes?: string;
} & ({ fill: true; width?: never; height?: never } | { fill?: false; width: number; height: number });

/**
 * Admin image (product photos, thumbnails). Store and known hosts are resized by Next.js;
 * any other address an admin typed is shown as it is. Falls back when the image fails.
 */
export function AdminImage({ src, alt, className, fallback, sizes, ...size }: AdminImageProps) {
  const [failedSrc, setFailedSrc] = useState<string | null>(null);
  const shown = src && src !== failedSrc ? src : fallback;
  if (!shown) return null;
  return (
    <Image
      src={shown}
      alt={alt}
      className={className}
      sizes={sizes}
      unoptimized={!canOptimizeImage(shown)}
      onError={() => {
        if (shown === src) setFailedSrc(src);
      }}
      {...(size.fill ? { fill: true } : { width: size.width, height: size.height })}
    />
  );
}
