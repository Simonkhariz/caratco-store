'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import Image from 'next/image';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { ApiBanner, ApiPromotion, HomepageData } from '@/lib/api-client';
import { PromoTag } from '@/components/home/PromoCode';
import { canOptimizeImage } from '@/lib/image';
import { SHOP } from '@/lib/shop';

interface HeroBannerProps {
  banners?: ApiBanner[];
  promotions?: ApiPromotion[];
  /** Real catalogue counts from the API; a stat is hidden when it is missing or zero. */
  stats?: HomepageData['stats'];
}

const ALL_STATES_AND_FCT = 37;
/** How long each slide stays (the progress bar runs for this long). */
const SLIDE_MS = 6000;

/** The shop's own slide (backend/config/shop.json), shown whole when the owner has set no banner and filling the gaps of one that has no text. */
const DEFAULT_SLIDE = {
  title: SHOP.hero.title,
  subtitle: SHOP.hero.subtitle,
  ctaText: SHOP.hero.cta,
  ctaUrl: '/category',
  imageUrl: SHOP.hero.image,
};

/**
 * The shop's own hero art, when it is a cut-out (hero.cutout), stands on the brand strip; any
 * other picture is a photo card.
 */
const isCutout = (src: string) => SHOP.hero.cutout && src === SHOP.hero.image;
const twoDigits = (n: number) => String(n).padStart(2, '0');

/**
 * Home page banner. With the brand strip under it, it fills the first screen (see .first-screen
 * in globals.css). Every slide's text shares one grid cell, so the banner keeps the height of
 * its longest slide and nothing moves when the slide changes.
 */
export const HeroBanner: React.FC<HeroBannerProps> = ({ banners = [], promotions = [], stats }) => {
  const slides = (banners.length > 0 ? banners : [null]).map((b) => ({
    key: b ? String(b.id) : 'default',
    title: b?.title || DEFAULT_SLIDE.title,
    subtitle: b?.subtitle || DEFAULT_SLIDE.subtitle,
    ctaText: b?.cta_text || DEFAULT_SLIDE.ctaText,
    ctaUrl: b?.cta_url || DEFAULT_SLIDE.ctaUrl,
    imageUrl: b?.image_url || DEFAULT_SLIDE.imageUrl,
  }));
  const count = slides.length;
  const [currentIndex, setCurrentIndex] = useState(0);
  // Stays valid if the list of banners gets shorter.
  const active = Math.min(currentIndex, count - 1);
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  const paused = hovered || focused;

  const activePromo = promotions.length > 0 ? promotions[0] : null;
  const statItems = [
    stats && stats.published_products > 0
      ? { value: stats.published_products.toLocaleString(), label: stats.published_products === 1 ? 'Product in store' : 'Products in store' }
      : null,
    stats && stats.categories > 0
      ? { value: stats.categories.toLocaleString(), label: stats.categories === 1 ? 'Category' : 'Categories' }
      : null,
    stats && stats.states_served > 0
      ? stats.states_served >= ALL_STATES_AND_FCT
        ? { value: '36 + FCT', label: 'Delivery to every state' }
        : { value: stats.states_served.toLocaleString(), label: stats.states_served === 1 ? 'State we deliver to' : 'States we deliver to' }
      : null,
  ].filter((item): item is { value: string; label: string } => item !== null);

  const go = (delta: number) => setCurrentIndex((prev) => (Math.min(prev, count - 1) + delta + count) % count);

  const controls = count > 1 && (
    <div className="flex items-center gap-3" role="group" aria-label="Banner slides">
      <span className="text-xs font-bold tabular-nums text-black" aria-hidden="true">
        {twoDigits(active + 1)}
      </span>
      <div className="flex items-center">
        {slides.map((s, idx) => (
          <button
            key={s.key}
            type="button"
            onClick={() => setCurrentIndex(idx)}
            aria-label={`Show banner ${idx + 1} of ${count}`}
            aria-current={idx === active ? 'true' : undefined}
            className="group px-[3px] py-3 focus:outline-none"
          >
            <span className="relative block h-[3px] w-6 overflow-hidden rounded-full bg-black/15 transition-colors group-hover:bg-black/30 group-focus-visible:ring-2 group-focus-visible:ring-accent group-focus-visible:ring-offset-2 sm:w-9">
              {idx === active && (
                // A new key restarts the bar; when it is full the next slide comes.
                <span
                  key={`${s.key}-${active}`}
                  className="hero-progress absolute inset-0 rounded-full bg-accent"
                  style={{ animationPlayState: paused ? 'paused' : 'running', ['--hero-slide-ms' as string]: `${SLIDE_MS}ms` }}
                  onAnimationEnd={() => go(1)}
                />
              )}
            </span>
          </button>
        ))}
      </div>
      <span className="text-xs font-semibold tabular-nums text-neutral-500" aria-hidden="true">
        {twoDigits(count)}
      </span>
      <div className="ml-1 flex items-center">
        <button
          type="button"
          onClick={() => go(-1)}
          aria-label="Previous banner"
          className="rounded-full p-2 text-neutral-600 transition-colors hover:text-black focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
          <ChevronLeft className="h-[18px] w-[18px]" aria-hidden="true" />
        </button>
        <button
          type="button"
          onClick={() => go(1)}
          aria-label="Next banner"
          className="rounded-full p-2 text-neutral-600 transition-colors hover:text-black focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
          <ChevronRight className="h-[18px] w-[18px]" aria-hidden="true" />
        </button>
      </div>
    </div>
  );

  return (
    <section
      className="relative flex flex-1 overflow-hidden bg-hero"
      aria-roledescription={count > 1 ? 'carousel' : undefined}
      aria-label="Featured"
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      onFocus={() => setFocused(true)}
      onBlur={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setFocused(false);
      }}
    >
      {/* Phones: text, picture, controls, top to bottom. Desktop: text and controls on the
          left, the picture full height on the right standing on the brand strip. */}
      <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col px-4 sm:px-6 lg:grid lg:grid-cols-12 lg:grid-rows-[1fr_auto] lg:gap-x-10 lg:px-8">
        <div className="pt-[clamp(0.75rem,3svh,1.5rem)] sm:pt-[clamp(1.25rem,4svh,2.5rem)] lg:col-span-7 lg:row-start-1 lg:self-center lg:py-[clamp(1rem,4svh,2.5rem)]">
          <div className="grid">
            {slides.map((s, idx) => {
              const shown = idx === active;
              return (
                <div
                  key={s.key}
                  aria-hidden={!shown}
                  className={`[grid-area:1/1] flex flex-col items-start gap-[clamp(0.75rem,2.2svh,1rem)] transition-opacity duration-500 sm:gap-[clamp(1rem,2.8svh,1.5rem)] ${shown ? 'opacity-100' : 'invisible opacity-0'}`}
                >
                  {/* Only the first slide is the page's heading. Sizes follow the window's
                      width and height, so the banner fits short laptop screens too. */}
                  {React.createElement(
                    idx === 0 ? 'h1' : 'p',
                    {
                      className:
                        'font-integral uppercase text-black tracking-tight leading-[1.02] text-[clamp(1.6rem,min(8.6vw,5svh),2.75rem)] sm:text-[clamp(2.25rem,min(6.4vw,6.2svh),3.5rem)] lg:text-[clamp(2.5rem,min(4.7vw,7.4svh),4.5rem)] lg:leading-[0.98]',
                    },
                    s.title
                  )}
                  <p className="max-w-xl text-sm leading-relaxed text-neutral-600 line-clamp-3 sm:text-base">{s.subtitle}</p>
                  {/* The advertised code shares the button's row (PromoTag): no extra height. */}
                  <div className="flex w-full items-center gap-3 sm:w-auto sm:gap-5">
                    <Link
                      href={s.ctaUrl}
                      tabIndex={shown ? undefined : -1}
                      className={`${activePromo ? 'shrink-0 px-6 sm:px-12' : 'w-full px-12'} rounded-full bg-accent py-3.5 text-center text-sm font-semibold text-white shadow-lg transition-all hover:bg-accent-hover active:scale-95 sm:w-auto sm:py-4 sm:text-base`}
                    >
                      {s.ctaText}
                    </Link>
                    {activePromo && <PromoTag promo={activePromo} />}
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {/* Pictures, cross-fading. */}
        <div className="relative mt-[clamp(0.75rem,2.5svh,1.25rem)] min-h-[150px] flex-1 sm:mt-8 lg:col-span-5 lg:col-start-8 lg:row-start-1 lg:mt-[clamp(1rem,3svh,1.5rem)] lg:min-h-0">
          {slides.map((s, idx) => {
            const shown = idx === active;
            const cutout = isCutout(s.imageUrl);
            return (
              <div
                key={s.key}
                aria-hidden={!shown}
                className={`absolute transition-opacity duration-700 ${shown ? 'opacity-100' : 'opacity-0'} ${
                  cutout ? 'inset-0' : 'inset-x-0 bottom-1 top-0 overflow-hidden rounded-[1.75rem] shadow-lg sm:bottom-2 lg:bottom-10 lg:top-4'
                }`}
              >
                <Image
                  src={s.imageUrl}
                  alt={shown ? s.title : ''}
                  fill
                  // Every slide is on the first screen: the first picture loads first, the
                  // others with the page but after it, so each is ready when its turn comes.
                  preload={idx === 0}
                  loading={idx > 0 ? 'eager' : undefined}
                  fetchPriority={idx > 0 ? 'low' : undefined}
                  unoptimized={!canOptimizeImage(s.imageUrl)}
                  sizes="(min-width: 1024px) 40vw, 100vw"
                  className={cutout ? 'object-contain object-bottom drop-shadow-xl' : 'object-cover object-[center_25%]'}
                />
              </div>
            );
          })}
        </div>

        {/* Store numbers and slide controls. Phones: just the controls, centred under the
            picture. Tablets: numbers left, controls right. Desktop: numbers under the text,
            controls centred under the picture they change. */}
        {(controls || statItems.length > 0) && (
          <div className="flex items-center justify-center py-1 sm:justify-between sm:gap-6 sm:border-t sm:border-neutral-300/60 sm:py-[clamp(0.75rem,2svh,1.25rem)] lg:contents">
            {statItems.length > 0 && (
              <dl className="hidden items-center sm:flex lg:col-span-7 lg:row-start-2 lg:mb-[clamp(1rem,3.5svh,2rem)] lg:border-t lg:border-neutral-300/60 lg:pt-[clamp(0.75rem,2.4svh,1.5rem)]">
                {statItems.map((item, idx) => (
                  <div key={item.label} className={`pr-5 lg:pr-8 ${idx > 0 ? 'border-l border-neutral-300 pl-5 lg:pl-8' : ''}`}>
                    <dt className="sr-only">{item.label}</dt>
                    <dd className="whitespace-nowrap text-2xl font-bold text-black lg:text-[clamp(1.5rem,4.2svh,2.25rem)]">{item.value}</dd>
                    <dd className="whitespace-nowrap text-xs text-neutral-600 lg:text-sm" aria-hidden="true">
                      {item.label}
                    </dd>
                  </div>
                ))}
              </dl>
            )}
            {controls && (
              <div className="shrink-0 lg:col-span-5 lg:col-start-8 lg:row-start-2 lg:mb-[clamp(1rem,3.5svh,2rem)] lg:flex lg:justify-center lg:self-end">
                {controls}
              </div>
            )}
          </div>
        )}
      </div>
    </section>
  );
};
