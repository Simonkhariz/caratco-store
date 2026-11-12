'use client';

import { SHOP } from '@/lib/shop';
import React, { useId, useState } from 'react';
import Link from 'next/link';
import { Mail, CheckCircle2, Heart, AlertTriangle } from 'lucide-react';
import { apiSubscribeNewsletter } from '@/lib/api-client';
import { useStoreInfo, useStoreNav, whatsAppLink } from '@/lib/use-store-info';
import { useStoreSupport } from '@/lib/use-store-support';
import { PaymentBadges } from '@/components/layout/PaymentBadges';
import { DEVELOPER } from '@/lib/developer';

const linkClass = 'inline-block py-1 transition-colors hover:text-black hover:underline';

function NewsletterForm() {
  const inputId = useId();
  const statusId = useId();
  const [email, setEmail] = useState('');
  const [state, setState] = useState<'idle' | 'sending' | 'done' | 'error'>('idle');
  const [message, setMessage] = useState('');

  const handleSubscribe = async (e: React.FormEvent) => {
    e.preventDefault();
    const value = email.trim();
    if (!value) {
      setState('error');
      setMessage('Enter your email address first.');
      return;
    }
    setState('sending');
    setMessage('');
    try {
      await apiSubscribeNewsletter(value);
      setState('done');
      setMessage(`Subscribed. We'll send offers to ${value}; every email has an unsubscribe link.`);
      setEmail('');
    } catch (err) {
      setState('error');
      setMessage(err instanceof Error && err.message ? err.message : 'We could not subscribe you right now. Please try again.');
    }
  };

  // This form sits on the newsletter card, which is the shop's accent colour (black, orange,
  // blue...). Its messages use the accent's own text colour, never a fixed red, green or grey
  // that only reads on black.
  if (state === 'done') {
    return (
      <div role="status" className="flex items-start gap-2 rounded-2xl bg-white/15 p-4 text-sm font-medium text-accent-ink">
        <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        <span>{message}</span>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubscribe} className="flex flex-col gap-3 sm:flex-row sm:flex-wrap lg:flex-col lg:flex-nowrap" noValidate>
      <label htmlFor={inputId} className="sr-only">
        Email address
      </label>
      <div className="relative sm:flex-1">
        <Mail className="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-neutral-500" aria-hidden="true" />
        <input
          id={inputId}
          type="email"
          required
          autoComplete="email"
          value={email}
          onChange={(e) => {
            setEmail(e.target.value);
            if (state === 'error') setState('idle');
          }}
          aria-invalid={state === 'error' || undefined}
          aria-describedby={state === 'error' ? statusId : undefined}
          placeholder="Enter your email address"
          className="w-full rounded-full bg-white py-3 pl-12 pr-4 text-sm text-black placeholder:text-neutral-500 focus:outline-none focus:ring-2 focus:ring-neutral-400 sm:py-3.5"
        />
      </div>
      <button
        type="submit"
        disabled={state === 'sending'}
        className="w-full rounded-full bg-white py-3 text-sm font-semibold text-black shadow-md sm:w-auto sm:px-6 lg:w-full transition-colors hover:bg-neutral-100 disabled:opacity-60 sm:py-3.5"
      >
        {state === 'sending' ? 'Subscribing…' : 'Subscribe to newsletter'}
      </button>
      {state === 'error' && (
        <p id={statusId} role="alert" className="flex w-full items-start gap-1.5 text-sm font-medium text-accent-ink">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{message}</span>
        </p>
      )}
    </form>
  );
}

export const Footer: React.FC = () => {
  const { categories } = useStoreNav();
  const { origin } = useStoreInfo();
  const { supportPhone } = useStoreSupport();

  const whatsappNumber = origin?.whatsapp_phone || supportPhone || '';
  const waLink = whatsAppLink(whatsappNumber, 'Hello, I have a question about my order.');

  return (
    <footer className="relative mt-36 bg-surface pb-12 pt-48 lg:pt-36">
      {/* Newsletter card (overhangs the top of the footer) */}
      <div className="absolute -top-24 left-0 right-0 z-20 mx-auto max-w-7xl px-4 sm:-top-28 sm:px-6 lg:px-8">
        <div className="flex flex-col items-center justify-between gap-6 rounded-3xl bg-accent p-6 text-accent-ink shadow-2xl sm:p-10 lg:flex-row lg:gap-10 md:p-12">
          <div className="w-full lg:max-w-md">
            <h2 className="font-integral text-2xl uppercase leading-tight tracking-tight text-accent-ink sm:text-3xl sm:leading-[1.05] lg:text-[42px]">
              Stay up to date about our latest offers
            </h2>
          </div>
          <div className="w-full lg:max-w-sm lg:flex-1">
            <NewsletterForm />
          </div>
        </div>
      </div>

      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid grid-cols-2 gap-x-6 gap-y-10 border-b border-neutral-300 pb-12 md:grid-cols-3 lg:grid-cols-5">
          {/* Brand & contact */}
          <div className="col-span-2 space-y-4 md:col-span-3 lg:col-span-2">
            <Link href="/" className="block font-integral text-2xl tracking-tighter text-black sm:text-3xl lg:text-4xl">
              {SHOP.name}
            </Link>
            <p className="max-w-sm text-sm leading-relaxed text-neutral-700">
              {SHOP.tagline}
            </p>
            <ul className="space-y-0.5 text-sm text-neutral-700">
              {origin?.contact_email && (
                <li>
                  <a href={`mailto:${origin.contact_email}`} className={linkClass}>
                    {origin.contact_email}
                  </a>
                </li>
              )}
              {waLink && (
                <li>
                  <a href={waLink} target="_blank" rel="noopener noreferrer" className={`font-medium text-emerald-800 ${linkClass}`}>
                    WhatsApp {whatsappNumber}
                    <span className="sr-only"> (opens WhatsApp)</span>
                  </a>
                </li>
              )}
              {origin?.contact_phone && (
                <li>
                  <a href={`tel:${origin.contact_phone.replace(/[^\d+]/g, '')}`} className={linkClass}>
                    {origin.contact_phone}
                  </a>
                </li>
              )}
            </ul>
          </div>

          {/* Shop */}
          <nav aria-label="Shop">
            <h3 className="mb-4 text-xs font-bold uppercase tracking-widest text-black">Shop</h3>
            <ul className="space-y-1 text-sm text-neutral-700">
              <li>
                <Link href="/category" className={linkClass}>
                  All products
                </Link>
              </li>
              {categories.map((c) => (
                <li key={c.slug}>
                  <Link href={`/category?cat=${encodeURIComponent(c.slug)}`} className={linkClass}>
                    {c.name}
                  </Link>
                </li>
              ))}
              <li>
                <Link href="/category?onSale=true" className={linkClass}>
                  On sale
                </Link>
              </li>
            </ul>
          </nav>

          {/* Help */}
          <nav aria-label="Help">
            <h3 className="mb-4 text-xs font-bold uppercase tracking-widest text-black">Help</h3>
            <ul className="space-y-1 text-sm text-neutral-700">
              <li>
                <Link href="/track-order" className={`font-semibold text-emerald-800 ${linkClass}`}>
                  Track an order
                </Link>
              </li>
              <li>
                <Link href="/orders" className={linkClass}>
                  My orders
                </Link>
              </li>
              <li>
                <Link href="/shipping" className={linkClass}>
                  Shipping &amp; delivery
                </Link>
              </li>
              <li>
                <Link href="/returns" className={linkClass}>
                  Returns &amp; refunds
                </Link>
              </li>
              <li>
                <Link href="/faq" className={linkClass}>
                  FAQ
                </Link>
              </li>
              <li>
                <Link href="/contact" className={linkClass}>
                  Contact us
                </Link>
              </li>
            </ul>
          </nav>

          {/* Company */}
          <nav aria-label="Company">
            <h3 className="mb-4 text-xs font-bold uppercase tracking-widest text-black">Company</h3>
            <ul className="space-y-1 text-sm text-neutral-700">
              <li>
                <Link href="/about" className={linkClass}>
                  About us
                </Link>
              </li>
              <li>
                <Link href="/terms" className={linkClass}>
                  Terms &amp; conditions
                </Link>
              </li>
              <li>
                <Link href="/privacy" className={linkClass}>
                  Privacy policy
                </Link>
              </li>
            </ul>
          </nav>
        </div>

        {/* Bottom bar */}
        <div className="flex flex-col items-center justify-between gap-4 pt-8 text-xs text-neutral-700 sm:flex-row">
          <div className="flex flex-col items-center gap-1 sm:items-start">
            <p>
              © {new Date().getFullYear()} {origin?.store_name || SHOP.name}. All rights reserved.
            </p>
            <p>
              Developed with <Heart className="inline h-3.5 w-3.5 -mt-0.5 fill-[#FF3333] text-[#FF3333]" aria-hidden="true" />
              <span className="sr-only">love</span> by{' '}
              <a href={DEVELOPER.url} target="_blank" rel="noopener noreferrer" className="font-semibold text-black underline-offset-2 hover:underline">
                {DEVELOPER.name}
                <span className="sr-only"> (opens in a new tab)</span>
              </a>
            </p>
          </div>
          <PaymentBadges />
        </div>
      </div>
    </footer>
  );
};
