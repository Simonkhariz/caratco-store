'use client';

import React, { useRef, useState } from 'react';
import { Product } from '@/types';
import { ReviewForm } from '@/components/product/ReviewForm';
import { SHOP } from '@/lib/shop';
import { HugeiconsIcon } from '@hugeicons/react';
import { StarIcon, CheckmarkCircle01Icon, ArrowDown01Icon } from '@hugeicons/core-free-icons';

interface ProductTabsProps {
  product: Product;
}

type TabId = 'details' | 'reviews' | 'faqs';

/**
 * Product details, approved customer reviews and FAQs. Only real data is shown:
 * tabs without content explain that rather than displaying placeholder text.
 */
export const ProductTabs: React.FC<ProductTabsProps> = ({ product }) => {
  const reviews = product.reviews ?? [];
  const faqs = product.faqs ?? [];
  const [activeTab, setActiveTab] = useState<TabId>(reviews.length > 0 ? 'reviews' : 'details');
  const [sortBy, setSortBy] = useState<'latest' | 'highest'>('latest');
  const [reviewOpen, setReviewOpen] = useState(false);
  const tabRefs = useRef<Record<string, HTMLButtonElement | null>>({});

  const sortedReviews = sortBy === 'highest' ? [...reviews].sort((a, b) => b.rating - a.rating) : reviews;
  // A shop with the style filter switched off does not name a style here either.
  const dressStyles = SHOP.style.enabled ? (Array.isArray(product.dressStyle) ? product.dressStyle : [product.dressStyle]).filter(Boolean) : [];
  const specifications = product.specifications ?? [];
  const hasSpecifications = specifications.length > 0 || product.details.length > 0;

  const tabs: { id: TabId; label: string }[] = [
    { id: 'details', label: 'Product Details' },
    { id: 'reviews', label: `Rating & Reviews (${reviews.length})` },
    ...(faqs.length > 0 ? [{ id: 'faqs' as TabId, label: 'FAQs' }] : []),
  ];

  // Roving focus between tabs: arrows move and activate, Home/End jump to the ends.
  const onTabKey = (e: React.KeyboardEvent, index: number) => {
    let next = -1;
    if (e.key === 'ArrowRight') next = (index + 1) % tabs.length;
    else if (e.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = tabs.length - 1;
    if (next < 0) return;
    e.preventDefault();
    const id = tabs[next].id;
    setActiveTab(id);
    tabRefs.current[id]?.focus();
  };

  return (
    <div className="mt-16 sm:mt-20">
      <div role="tablist" aria-label="Product information" className="flex border-b border-neutral-200 text-center text-sm sm:text-base font-medium">
        {tabs.map((tab, index) => {
          const selected = activeTab === tab.id;
          return (
            <button
              key={tab.id}
              ref={(el) => {
                tabRefs.current[tab.id] = el;
              }}
              type="button"
              role="tab"
              id={`tab-${tab.id}`}
              aria-selected={selected}
              aria-controls={`panel-${tab.id}`}
              tabIndex={selected ? 0 : -1}
              onClick={() => setActiveTab(tab.id)}
              onKeyDown={(e) => onTabKey(e, index)}
              className={`flex-1 pb-4 relative transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 rounded-t-md ${
                selected ? 'text-black font-bold border-b-2 border-accent' : 'text-neutral-600 hover:text-black'
              }`}
            >
              {tab.label}
            </button>
          );
        })}
      </div>

      {activeTab === 'details' && (
        <div role="tabpanel" id="panel-details" aria-labelledby="tab-details" tabIndex={0} className="py-8 max-w-2xl mx-auto space-y-6 focus:outline-none">
          {product.description && (
            <div>
              <h3 className="font-bold text-lg text-black mb-3">About this item</h3>
              <p className="text-sm text-neutral-700 leading-relaxed whitespace-pre-line">{product.description}</p>
            </div>
          )}
          {hasSpecifications && (
            <div>
              <h3 className="font-bold text-lg text-black mb-3">{SHOP.specs.label}</h3>
              {/* The owner's own rows, name beside value. The table keeps its two columns on a
                  phone: long values wrap inside theirs. */}
              {specifications.length > 0 && (
                <table className={`w-full table-fixed text-left text-sm ${product.details.length > 0 ? 'mb-4' : ''}`}>
                  <caption className="sr-only">{SHOP.specs.label}</caption>
                  <tbody className="divide-y divide-neutral-200 border-y border-neutral-200">
                    {specifications.map((row, index) => (
                      <tr key={`${index}-${row.name}`}>
                        <th scope="row" className="w-2/5 py-2.5 pr-4 align-top font-medium text-neutral-600 break-words">
                          {row.name}
                        </th>
                        <td className="py-2.5 align-top text-black break-words whitespace-pre-line">{row.value}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              {product.details.length > 0 && (
                <ul className="space-y-2 text-neutral-700 text-sm list-disc pl-5">
                  {product.details.map((detail) => (
                    <li key={detail}>{detail}</li>
                  ))}
                </ul>
              )}
            </div>
          )}
          {product.warranty && (
            <div>
              <h3 className="font-bold text-lg text-black mb-2">Warranty</h3>
              <p className="text-sm text-neutral-700 leading-relaxed">{product.warranty}</p>
            </div>
          )}
          {dressStyles.length > 0 && (
            <div>
              <h3 className="font-bold text-lg text-black mb-2">{SHOP.style.short}</h3>
              <p className="text-sm text-neutral-700 capitalize">{dressStyles.join(', ')}</p>
            </div>
          )}
          {!product.description && !hasSpecifications && !product.warranty && dressStyles.length === 0 && (
            <p className="text-sm text-neutral-600">No further details have been added for this product yet.</p>
          )}
        </div>
      )}

      {activeTab === 'reviews' && (
        <div role="tabpanel" id="panel-reviews" aria-labelledby="tab-reviews" tabIndex={0} className="py-8 space-y-8 focus:outline-none">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-xl sm:text-2xl text-black">All Reviews</h3>
              <span className="text-sm text-neutral-600">({reviews.length})</span>
            </div>

            <div className="flex items-center gap-3">
              {reviews.length > 1 && (
                <div className="relative inline-block text-xs sm:text-sm">
                  <label htmlFor="review-sort" className="sr-only">
                    Sort reviews
                  </label>
                  <select
                    id="review-sort"
                    value={sortBy}
                    onChange={(e) => setSortBy(e.target.value as 'latest' | 'highest')}
                    className="bg-surface text-black font-semibold rounded-full pl-4 pr-9 py-2.5 appearance-none cursor-pointer focus:outline-none focus:ring-2 focus:ring-accent"
                  >
                    <option value="latest">Latest</option>
                    <option value="highest">Highest Rating</option>
                  </select>
                  <div className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-black" aria-hidden="true">
                    <HugeiconsIcon icon={ArrowDown01Icon} size={14} />
                  </div>
                </div>
              )}
              <button
                type="button"
                onClick={() => setReviewOpen(true)}
                className="bg-accent text-white hover:bg-accent-hover font-semibold px-5 py-2.5 rounded-full text-xs sm:text-sm"
              >
                Write a review
              </button>
            </div>
          </div>

          {reviews.length === 0 ? (
            <div className="text-center py-10 text-neutral-600 text-sm">No customer reviews yet. Bought this item? Be the first to review it.</div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
              {sortedReviews.map((rev) => (
                <article key={rev.id} className="border border-neutral-200 rounded-3xl p-6 space-y-3 bg-white">
                  <div className="flex items-center gap-1" role="img" aria-label={`${rev.rating} out of 5 stars`}>
                    {Array.from({ length: 5 }).map((_, i) => (
                      <span key={i} aria-hidden="true" className={i < rev.rating ? 'text-[#FFC633]' : 'text-neutral-300'}>
                        <HugeiconsIcon icon={StarIcon} size={14} className={i < rev.rating ? 'fill-current' : ''} />
                      </span>
                    ))}
                  </div>
                  <div className="flex items-center gap-1.5">
                    <span className="font-bold text-base text-black">{rev.author}</span>
                    {rev.verified && (
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-[#01803a]" title="Verified purchase">
                        <HugeiconsIcon icon={CheckmarkCircle01Icon} size={16} className="fill-current" aria-hidden="true" />
                        <span>
                          Verified<span className="sr-only sm:not-sr-only"> purchase</span>
                        </span>
                      </span>
                    )}
                  </div>
                  {rev.title && <h4 className="font-semibold text-sm text-black">{rev.title}</h4>}
                  <p className="text-neutral-700 text-sm leading-relaxed">&ldquo;{rev.comment}&rdquo;</p>
                  {rev.date && <p className="text-xs text-neutral-600 pt-1">Posted on {rev.date}</p>}
                </article>
              ))}
            </div>
          )}
        </div>
      )}

      {activeTab === 'faqs' && faqs.length > 0 && (
        <div role="tabpanel" id="panel-faqs" aria-labelledby="tab-faqs" tabIndex={0} className="py-8 max-w-2xl mx-auto space-y-4 focus:outline-none">
          {faqs.map((faq) => (
            <div key={faq.question} className="border border-neutral-200 rounded-2xl p-5 bg-white space-y-2">
              <h3 className="font-bold text-base text-black">{faq.question}</h3>
              <p className="text-sm text-neutral-700 leading-relaxed">{faq.answer}</p>
            </div>
          ))}
        </div>
      )}

      <ReviewForm open={reviewOpen} onClose={() => setReviewOpen(false)} productSlug={product.slug || product.id} productName={product.name} />
    </div>
  );
};
