'use client';

import React from 'react';
import Link from 'next/link';
import Image from '@/components/ui/StoreImage';
import { useRouter } from 'next/navigation';
import { Product } from '@/types';
import { formatPrice } from '@/lib/currency';
import { HugeiconsIcon } from '@hugeicons/react';
import { StarIcon, ShoppingBag01Icon, FavouriteIcon } from '@hugeicons/core-free-icons';
import { useWishlist } from '@/context/WishlistContext';
import { useQuickAdd } from '@/lib/use-quick-add';
import { QuickAddDialog } from '@/components/product/QuickAddDialog';

interface ProductCardProps {
  product: Product;
  /** Load the photo straight away (for cards in the first row of a page). */
  preload?: boolean;
  /** Cards per row from tablet width up (grid-cols-2 md:grid-cols-3 or md:grid-cols-4). */
  columns?: 3 | 4;
}

// How wide the photo is shown, so the browser downloads a file that size: two per row on
// phones, then three or four, and never wider than 300px once the page stops growing (1280px).
const PHOTO_SIZES = {
  3: '(max-width: 767px) 50vw, (max-width: 1023px) 33vw, (max-width: 1279px) 25vw, 300px',
  4: '(max-width: 767px) 50vw, (max-width: 1279px) 25vw, 300px',
} as const;

/**
 * Catalogue card. The cart button never guesses an option: a product with a single option in
 * stock goes straight into the cart; otherwise a quick-add panel asks for colour and size.
 */
export const ProductCard: React.FC<ProductCardProps> = ({ product, preload = false, columns = 4 }) => {
  const router = useRouter();
  const { toggleWishlist, isInWishlist } = useWishlist();
  const isFavorited = isInWishlist(product.id);
  const href = `/products/${product.slug}`;
  const soldOut = product.inStock === false;
  const { add, warm, busy: checking, choose: quickAdd, closeChoose } = useQuickAdd(product);

  const handleAdd = () => {
    if (soldOut) {
      router.push(href);
      return;
    }
    void add();
  };

  return (
    <article className="group relative flex flex-col h-full bg-white rounded-2xl overflow-hidden">
      <div className="relative aspect-square w-full bg-card rounded-2xl overflow-hidden mb-3 ring-1 ring-inset ring-black/[0.06]">
        <Link href={href} className="absolute inset-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded-2xl" tabIndex={-1} aria-hidden="true">
          <Image
            src={product.images[0]}
            alt=""
            fill
            sizes={PHOTO_SIZES[columns]}
            preload={preload}
            className={`object-cover group-hover:scale-105 transition-transform duration-300 ${soldOut ? 'opacity-60' : ''}`}
          />
        </Link>

        {soldOut && (
          <span className="absolute left-2.5 top-2.5 rounded-full bg-black/80 px-2.5 py-1 text-xs font-semibold text-white">
            Sold out
          </span>
        )}

        <button
          type="button"
          onClick={() => toggleWishlist(product)}
          className={`absolute top-2 right-2 p-2.5 rounded-full transition-all duration-200 z-10 ${
            isFavorited
              ? 'bg-white text-red-500 shadow-md scale-105'
              : 'bg-white/80 backdrop-blur-sm text-neutral-600 hover:text-red-500 hover:bg-white shadow-sm ring-1 ring-black/[0.06]'
          }`}
          aria-label={isFavorited ? `Remove ${product.name} from wishlist` : `Save ${product.name} to wishlist`}
          aria-pressed={isFavorited}
        >
          <HugeiconsIcon icon={FavouriteIcon} size={16} className={isFavorited ? 'fill-current text-red-500' : ''} aria-hidden="true" />
        </button>
      </div>

      <h3 className="min-h-10 font-bold text-sm leading-5 text-black line-clamp-2 sm:min-h-12 sm:text-base sm:leading-6">
        <Link href={href} className="hover:text-neutral-700 focus:outline-none focus-visible:underline">
          {product.name}
        </Link>
      </h3>

      {product.rating > 0 ? (
        <div
          className="flex items-center gap-1.5 my-1.5"
          role="img"
          aria-label={`Rated ${product.rating.toFixed(1)} out of 5${product.reviewCount > 0 ? ` from ${product.reviewCount} ${product.reviewCount === 1 ? 'review' : 'reviews'}` : ''}`}
        >
          <div className="flex items-center" aria-hidden="true">
            {Array.from({ length: 5 }).map((_, i) => {
              const isFilled = i + 1 <= Math.round(product.rating);
              return (
                <span key={i} className={isFilled ? 'text-[#FFC633]' : 'text-neutral-300'}>
                  <HugeiconsIcon icon={StarIcon} size={14} className={isFilled ? 'fill-current' : ''} />
                </span>
              );
            })}
          </div>
          <span className="text-xs text-neutral-700 font-medium" aria-hidden="true">
            {product.rating.toFixed(1)}/<span className="text-neutral-600">5</span>
          </span>
        </div>
      ) : (
        <div className="my-1.5 h-[18px]" />
      )}

      <div className="flex items-center justify-between gap-2 mt-auto pt-2">
        {/* Phones: the old price sits under the price in every card (room is kept for it), so prices
            and buttons line up across a row. Wider cards have room for both on one line. */}
        <div className="flex min-h-10 min-w-0 flex-col justify-center sm:min-h-0 sm:flex-row sm:flex-wrap sm:items-center sm:gap-2">
          <span className="font-bold text-base leading-5 sm:text-xl md:text-lg lg:text-xl text-black">{formatPrice(product.price)}</span>
          {product.originalPrice && product.originalPrice > product.price && (
            <span className="font-semibold text-xs sm:text-sm text-neutral-600 line-through">
              <span className="sr-only">Was </span>
              {formatPrice(product.originalPrice)}
            </span>
          )}
        </div>

        <button
          type="button"
          onClick={handleAdd}
          onPointerEnter={soldOut ? undefined : warm}
          onTouchStart={soldOut ? undefined : warm}
          onFocus={soldOut ? undefined : warm}
          disabled={checking}
          aria-busy={checking || undefined}
          className="bg-accent hover:bg-accent-hover text-white rounded-full p-2.5 sm:px-3.5 sm:py-2 md:p-2.5 lg:px-3.5 lg:py-2 flex items-center justify-center gap-1 text-xs font-semibold shadow-sm hover:shadow-md transition-all shrink-0 disabled:opacity-70"
          aria-label={soldOut ? `View ${product.name}` : `Add ${product.name} to cart`}
        >
          {checking ? (
            <span className="block h-[15px] w-[15px] animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true" />
          ) : (
            <HugeiconsIcon icon={ShoppingBag01Icon} size={15} aria-hidden="true" />
          )}
          <span className="hidden sm:inline md:hidden lg:inline">{soldOut ? 'View' : 'Add'}</span>
        </button>
      </div>

      {quickAdd && <QuickAddDialog product={quickAdd} onClose={closeChoose} />}
    </article>
  );
};
