'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import Image from 'next/image';
import { ApiCategory } from '@/lib/api-client';
import { canOptimizeImage } from '@/lib/image';
import { SHOP } from '@/lib/shop';
import { HugeiconsIcon } from '@hugeicons/react';
import { ArrowUpRight01Icon } from '@hugeicons/core-free-icons';

interface CategoryGridProps {
  categories?: ApiCategory[];
}

export const CategoryGrid: React.FC<CategoryGridProps> = ({ categories }) => {
  // A picture that fails to load is dropped, leaving the plain dark card instead of a broken icon.
  const [failed, setFailed] = useState<Record<string, true>>({});

  // Only the store's real categories, with their real published-product counts.
  const items = (categories ?? []).map((c) => ({
    name: c.name,
    slug: c.slug,
    count: c.product_count > 0 ? `${c.product_count} ${c.product_count === 1 ? 'product' : 'products'}` : null,
    image: c.image_url || null,
  }));

  if (items.length === 0) {
    return null;
  }

  return (
    <section className="py-12 sm:py-16">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex flex-col sm:flex-row sm:items-end justify-between mb-8 sm:mb-12">
          <div>
            <span className="text-xs font-bold uppercase tracking-widest text-neutral-600 block mb-1">
              {SHOP.wording.categoriesEyebrow}
            </span>
            <h2 className="font-integral text-2xl sm:text-4xl lg:text-5xl text-black tracking-tight">
              {SHOP.wording.categoriesTitle}
            </h2>
          </div>
          <Link
            href="/category"
            className="text-xs sm:text-sm font-semibold text-black hover:underline mt-2 sm:mt-0 flex items-center gap-1"
          >
            <span>{SHOP.wording.categoriesCta}</span>
            <HugeiconsIcon icon={ArrowUpRight01Icon} size={16} aria-hidden="true" />
          </Link>
        </div>

        {/* 6-Grid Responsive Cards */}
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 sm:gap-6">
          {items.map((cat) => (
            <Link
              key={cat.slug}
              href={`/category?cat=${encodeURIComponent(cat.slug)}`}
              className="group relative bg-card rounded-3xl overflow-hidden aspect-[4/5] flex flex-col justify-between p-4 sm:p-5 shadow-sm hover:shadow-md transition-all duration-300"
            >
              {/* Background Image with Zoom (only when the category has its own image) */}
              <div className="absolute inset-0 z-0 bg-neutral-800">
                {cat.image && !failed[cat.slug] && (
                  <Image
                    src={cat.image}
                    alt=""
                    fill
                    unoptimized={!canOptimizeImage(cat.image)}
                    onError={() => setFailed((f) => ({ ...f, [cat.slug]: true }))}
                    sizes="(max-width: 767px) 50vw, (max-width: 1023px) 33vw, (max-width: 1279px) 16vw, 190px"
                    className="object-cover object-center group-hover:scale-110 transition-transform duration-500 opacity-90 group-hover:opacity-100"
                  />
                )}
                <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent" />
              </div>

              {/* Top Floating Badge */}
              <div className="relative z-10 self-end" aria-hidden="true">
                <div className="w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center text-black group-hover:bg-accent group-hover:text-accent-ink transition-colors shadow-sm">
                  <HugeiconsIcon icon={ArrowUpRight01Icon} size={16} />
                </div>
              </div>

              {/* Bottom Details */}
              <div className="relative z-10 text-white">
                <h3 className="font-bold text-base sm:text-lg leading-tight group-hover:translate-x-1 transition-transform">
                  {cat.name}
                </h3>
                {cat.count && <p className="text-xs text-neutral-100 mt-0.5 font-medium">{cat.count}</p>}
              </div>
            </Link>
          ))}
        </div>
      </div>
    </section>
  );
};
