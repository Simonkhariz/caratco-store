'use client';

import { useEffect, useRef, useState } from 'react';
import { Check, Copy, Facebook, Mail, Share2 } from 'lucide-react';
import { formatPrice } from '@/lib/currency';
import { WhatsAppIcon } from '@/components/ui/WhatsAppIcon';

/**
 * Lets a shopper send this product to someone: the phone's own share sheet where there is one,
 * otherwise a small menu (copy link, WhatsApp, Facebook, X, email).
 */
export function ShareProduct({ name, slug, price }: { name: string; slug: string; price: number }) {
  const [open, setOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (!wrapRef.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const url = () => `${window.location.origin}/products/${encodeURIComponent(slug)}`;
  const text = `${name} – ${formatPrice(price)}`;

  const share = async () => {
    if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
      try {
        await navigator.share({ title: name, text, url: url() });
        return;
      } catch (err) {
        // Cancelled by the shopper: nothing to do. Any other failure falls back to the menu.
        if (err instanceof DOMException && err.name === 'AbortError') return;
      }
    }
    setOpen((v) => !v);
  };

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(url());
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      window.prompt('Copy this link:', url());
    }
  };

  const links = () => {
    const u = encodeURIComponent(url());
    const t = encodeURIComponent(text);
    return [
      { label: 'WhatsApp', href: `https://wa.me/?text=${encodeURIComponent(`${text}\n${url()}`)}`, icon: <WhatsAppIcon className="size-4" /> },
      { label: 'Facebook', href: `https://www.facebook.com/sharer/sharer.php?u=${u}`, icon: <Facebook className="size-4" aria-hidden="true" /> },
      { label: 'X (Twitter)', href: `https://twitter.com/intent/tweet?text=${t}&url=${u}`, icon: <span className="w-4 text-center text-sm font-bold leading-none" aria-hidden="true">𝕏</span> },
      { label: 'Email', href: `mailto:?subject=${encodeURIComponent(name)}&body=${encodeURIComponent(`${text}\n${url()}`)}`, icon: <Mail className="size-4" aria-hidden="true" /> },
    ];
  };

  return (
    <div ref={wrapRef} className="relative shrink-0">
      <button
        type="button"
        onClick={share}
        aria-haspopup="menu"
        aria-expanded={open}
        className="inline-flex items-center gap-1.5 rounded-full border border-neutral-300 px-3.5 py-2.5 text-sm font-semibold text-black transition-colors hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
      >
        <Share2 className="size-4" aria-hidden="true" />
        Share
      </button>

      {open && (
        <div role="menu" aria-label={`Share ${name}`} className="absolute right-0 top-full z-[35] mt-2 w-56 rounded-2xl border border-neutral-200 bg-white p-1.5 shadow-xl">
          <button
            type="button"
            role="menuitem"
            onClick={copy}
            className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-black hover:bg-neutral-100"
          >
            {copied ? <Check className="size-4 text-emerald-700" aria-hidden="true" /> : <Copy className="size-4" aria-hidden="true" />}
            {copied ? 'Link copied' : 'Copy link'}
          </button>
          {links().map((l) => (
            <a
              key={l.label}
              role="menuitem"
              href={l.href}
              target={l.href.startsWith('mailto:') ? undefined : '_blank'}
              rel="noopener noreferrer"
              onClick={() => setOpen(false)}
              className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-black hover:bg-neutral-100"
            >
              {l.icon}
              {l.label}
            </a>
          ))}
          <span className="sr-only" role="status" aria-live="polite">
            {copied ? 'Link copied' : ''}
          </span>
        </div>
      )}
    </div>
  );
}
