'use client';

import React, { useRef } from 'react';
import { ApiReview } from '@/lib/api-client';
import { HugeiconsIcon } from '@hugeicons/react';
import { StarIcon, CheckmarkCircle01Icon, ArrowLeft01Icon, ArrowRight01Icon } from '@hugeicons/core-free-icons';

interface CustomerReviewsProps {
  reviews?: ApiReview[];
}

/**
 * Real, approved customer reviews only. The section is hidden when there are none;
 * "Verified purchase" appears only when the server confirmed a paid order.
 */
export const CustomerReviews: React.FC<CustomerReviewsProps> = ({ reviews = [] }) => {
  const scrollRef = useRef<HTMLUListElement>(null);

  const displayReviews = (reviews ?? [])
    .filter((r) => r.comment && r.comment.trim() !== '')
    .map((r) => ({
      id: r.id,
      author: r.author?.trim() || 'Customer',
      rating: Math.min(5, Math.max(0, Math.round(Number(r.rating) || 0))),
      title: r.title,
      comment: r.comment,
      verified: Boolean(Number(r.verified)),
      productName: r.product_name,
    }));

  if (displayReviews.length === 0) {
    return null;
  }

  const handleScroll = (direction: 'left' | 'right') => {
    scrollRef.current?.scrollBy({ left: direction === 'left' ? -360 : 360, behavior: 'smooth' });
  };

  return (
    <section className="py-12 sm:py-16 overflow-hidden" aria-labelledby="customer-reviews-heading">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between mb-8 sm:mb-10">
          <h2 id="customer-reviews-heading" className="font-integral text-2xl sm:text-4xl lg:text-5xl text-black tracking-tight">
            WHAT CUSTOMERS SAY
          </h2>

          {displayReviews.length > 1 && (
            <div className="flex items-center gap-3">
              <button
                type="button"
                onClick={() => handleScroll('left')}
                className="p-2.5 rounded-full border border-neutral-200 hover:bg-neutral-100 text-black transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
                aria-label="Scroll to previous reviews"
              >
                <HugeiconsIcon icon={ArrowLeft01Icon} size={20} aria-hidden="true" />
              </button>
              <button
                type="button"
                onClick={() => handleScroll('right')}
                className="p-2.5 rounded-full border border-neutral-200 hover:bg-neutral-100 text-black transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
                aria-label="Scroll to next reviews"
              >
                <HugeiconsIcon icon={ArrowRight01Icon} size={20} aria-hidden="true" />
              </button>
            </div>
          )}
        </div>

        <ul
          ref={scrollRef}
          className="flex gap-4 sm:gap-6 overflow-x-auto no-scrollbar pb-4 -mx-4 px-4 scroll-px-4 sm:-mx-6 sm:px-6 sm:scroll-px-6 lg:-mx-8 lg:px-8 lg:scroll-px-8 snap-x snap-mandatory"
          aria-label="Customer reviews"
        >
          {displayReviews.map((review) => (
            <li
              key={review.id}
              className="w-[85%] min-w-[260px] sm:w-auto sm:min-w-[380px] max-w-[400px] bg-white border border-neutral-200 rounded-3xl p-6 sm:p-8 flex flex-col justify-between snap-start shadow-sm shrink-0"
            >
              <div>
                <div className="flex items-center gap-1 mb-3" role="img" aria-label={`${review.rating} out of 5 stars`}>
                  {Array.from({ length: 5 }).map((_, i) => (
                    <span key={i} className={i < review.rating ? 'text-[#FFC633]' : 'text-neutral-300'} aria-hidden="true">
                      <HugeiconsIcon icon={StarIcon} size={16} className={i < review.rating ? 'fill-current' : ''} />
                    </span>
                  ))}
                </div>

                <div className="flex flex-wrap items-center gap-1.5 mb-2">
                  <span className="font-bold text-base sm:text-lg text-black">{review.author}</span>
                  {review.verified && (
                    <span className="inline-flex items-center gap-1 text-xs font-semibold text-[#017a23]">
                      <HugeiconsIcon icon={CheckmarkCircle01Icon} size={16} className="fill-current" aria-hidden="true" />
                      Verified purchase
                    </span>
                  )}
                </div>

                {review.title && <p className="font-semibold text-sm text-black mb-1">{review.title}</p>}
                <p className="text-neutral-700 text-sm leading-relaxed mb-3">&ldquo;{review.comment}&rdquo;</p>

                {review.productName && (
                  <div className="inline-block text-xs font-medium bg-neutral-100 text-neutral-700 px-2.5 py-1 rounded-md">
                    Item: {review.productName}
                  </div>
                )}
              </div>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
};
