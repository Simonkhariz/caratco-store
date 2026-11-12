'use client';

import React from 'react';
import Link from 'next/link';
import { Product } from '@/types';
import { ProductCard } from '@/components/ui/ProductCard';

interface ProductSectionProps {
  title: string;
  products: Product[];
  viewAllHref: string;
  hasDivider?: boolean;
}

export const ProductSection: React.FC<ProductSectionProps> = ({
  title,
  products,
  viewAllHref,
  hasDivider = true,
}) => {
  // Nothing to show: hide the section rather than render an empty heading.
  if (products.length === 0) {
    return null;
  }

  return (
    <section className="py-12 sm:py-16">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h2 className="font-integral text-2xl sm:text-4xl lg:text-5xl text-center text-black mb-8 sm:mb-12 tracking-tight">
          {title}
        </h2>

        {/* Responsive Grid: 2 columns on phones, 4 columns from tablets up */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6">
          {products.slice(0, 4).map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </div>

        {/* View All Button */}
        <div className="mt-8 sm:mt-10 text-center">
          <Link
            href={viewAllHref}
            className="inline-block w-full sm:w-auto text-center border border-neutral-200 hover:bg-neutral-50 text-black font-semibold text-sm sm:text-base px-16 py-3.5 rounded-full transition-colors"
          >
            View All
          </Link>
        </div>

        {hasDivider && <hr className="mt-16 border-neutral-200" />}
      </div>
    </section>
  );
};
