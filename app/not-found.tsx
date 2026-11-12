import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import Link from 'next/link';

export const metadata: Metadata = {
  title: `Page not found | ${SHOP.name}`,
  robots: { index: false, follow: false },
};

export default function NotFound() {
  return (
    <div className="min-h-[60vh] flex flex-col items-center justify-center text-center px-4 py-16">
      <p className="font-integral text-5xl sm:text-7xl font-black text-black mb-4" aria-hidden="true">
        404
      </p>
      <h1 className="text-xl sm:text-2xl font-bold text-black mb-3">Page not found</h1>
      <p className="text-sm sm:text-base text-neutral-600 max-w-md mb-8">
        This page doesn&apos;t exist or has moved. Try the shop, or look up an order you have placed.
      </p>
      <nav aria-label="Helpful links" className="flex flex-col sm:flex-row items-center gap-3">
        <Link
          href="/"
          className="bg-accent text-white hover:bg-accent-hover font-semibold px-8 py-3.5 rounded-full text-sm transition-colors shadow-md"
        >
          Go to the homepage
        </Link>
        <Link
          href="/category"
          className="border border-neutral-300 text-black hover:bg-neutral-50 font-semibold px-8 py-3.5 rounded-full text-sm transition-colors"
        >
          Browse the shop
        </Link>
        <Link
          href="/track-order"
          className="border border-neutral-300 text-black hover:bg-neutral-50 font-semibold px-8 py-3.5 rounded-full text-sm transition-colors"
        >
          Track an order
        </Link>
      </nav>
    </div>
  );
}
