'use client';

import React from 'react';
import Link from 'next/link';
import Image from '@/components/ui/StoreImage';
import { DRESS_STYLE_IMAGES } from '@/lib/dress-styles';
import { useStoreNav } from '@/lib/use-store-info';
import { SHOP } from '@/lib/shop';

/** Alternating tile widths (out of 12 columns) for the mosaic layout. */
const SPANS = ['md:col-span-4', 'md:col-span-8', 'md:col-span-8', 'md:col-span-4'];

/**
 * Style tiles ("dress style" in the template; the shop's own name for it elsewhere), only for
 * styles that actually exist in the published catalogue. Each tile links to the catalogue
 * filtered by that style (?style=…). A shop with the style filter off has no styles, so no section.
 */
export const BrowseByDressStyle: React.FC = () => {
  // Shared with the header and footer (sent with the page, or loaded once).
  const { dressStyles: styles } = useStoreNav();

  if (styles.length === 0) {
    return null;
  }

  return (
    <section className="py-8 sm:py-12">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="bg-surface rounded-3xl p-6 sm:p-12 lg:p-16">
          <h2 className="font-integral text-2xl sm:text-4xl lg:text-5xl text-center text-black mb-8 sm:mb-14 tracking-tight">
            {SHOP.style.browseTitle}
          </h2>

          <div className="grid grid-cols-1 md:grid-cols-12 gap-4 sm:gap-6">
            {styles.map((style, idx) => {
              const slug = style.toLowerCase();
              const image = DRESS_STYLE_IMAGES[slug];
              const span = styles.length === 1 ? 'md:col-span-12' : SPANS[idx % SPANS.length];
              return (
                <Link
                  key={style}
                  href={`/category?style=${encodeURIComponent(slug)}`}
                  className={`${span} relative h-52 sm:h-72 bg-neutral-900 rounded-2xl overflow-hidden group shadow-sm hover:shadow-md transition-shadow`}
                >
                  {image && (
                    <div className="absolute inset-0 w-full h-full">
                      <Image
                        src={image}
                        alt=""
                        fill
                        sizes={span === 'md:col-span-4' ? '(max-width: 768px) 100vw, 33vw' : '(max-width: 768px) 100vw, 66vw'}
                        className="object-cover object-top group-hover:scale-105 transition-transform duration-500"
                      />
                      <div className="absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-black/30 pointer-events-none" />
                    </div>
                  )}
                  <span className="absolute top-4 left-6 sm:top-6 sm:left-8 font-integral text-xl sm:text-3xl lg:text-4xl text-white drop-shadow-lg z-10 tracking-tight">
                    {style}
                  </span>
                </Link>
              );
            })}
          </div>
        </div>
      </div>
    </section>
  );
};
