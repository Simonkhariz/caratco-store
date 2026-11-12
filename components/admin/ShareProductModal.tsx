'use client';

import React, { useState } from 'react';
import { Copy, Mail, Share2 } from 'lucide-react';
import { formatPrice } from '@/lib/admin-format';
import { normalizePhoneDigits } from '@/lib/whatsapp';
import { Button, Field, Modal, Notice, TextInput, useToast } from '@/components/admin/ui';

export interface ShareableProduct {
  name: string;
  slug: string;
  status: string;
  price: number;
}

/**
 * Send a product's store link to a customer: copy it, open WhatsApp (to a chosen contact or a
 * typed number), email it, or use the phone's share sheet.
 */
export function ShareProductModal({ product, onClose }: { product: ShareableProduct | null; onClose: () => void }) {
  const toast = useToast();
  const [phone, setPhone] = useState('');
  const [phoneError, setPhoneError] = useState<string | undefined>();
  // A fresh number field for each product shared.
  const [formFor, setFormFor] = useState(product?.slug);
  if (product?.slug !== formFor) {
    setFormFor(product?.slug);
    setPhone('');
    setPhoneError(undefined);
  }

  if (!product) return null;
  // Only rendered in the browser once a product is chosen, so navigator is available here.
  const canNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

  const url = `${window.location.origin}/products/${encodeURIComponent(product.slug)}`;
  const message = `${product.name} – ${formatPrice(product.price)}\n${url}`;
  const published = product.status === 'published';

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(url);
      toast.success('Link copied.');
    } catch {
      toast.error('Could not copy. Select the link and copy it manually.');
    }
  };

  const openWhatsApp = (digits: string) => {
    window.open(`https://wa.me/${digits}?text=${encodeURIComponent(message)}`, '_blank', 'noopener');
  };

  const sendToNumber = (e: React.FormEvent) => {
    e.preventDefault();
    const digits = normalizePhoneDigits(phone);
    if (digits.length < 8 || digits.length > 15 || (digits.startsWith('234') && digits.length !== 13)) {
      setPhoneError('Enter the customer’s WhatsApp number, e.g. 0803 123 4567.');
      return;
    }
    setPhoneError(undefined);
    openWhatsApp(digits);
  };

  const nativeShare = async () => {
    try {
      await navigator.share({ title: product.name, text: `${product.name} – ${formatPrice(product.price)}`, url });
    } catch {
      // Cancelled by the user.
    }
  };

  return (
    <Modal open onClose={onClose} title={`Share ${product.name}`} description="Send customers straight to this product in the store.">
      <div className="grid gap-4">
        {!published && (
          <Notice tone="warning">
            This product is not published, so customers who open the link will not find it. Publish it first.
          </Notice>
        )}

        <Field label="Product link">
          {(p) => (
            <div className="flex items-stretch gap-2 [&>button]:!h-auto [&>button]:self-stretch">
              <TextInput {...p} readOnly value={url} onFocus={(e) => e.currentTarget.select()} />
              <Button onClick={copy}>
                <Copy className="size-3.5" aria-hidden="true" />
                Copy
              </Button>
            </div>
          )}
        </Field>

        <form onSubmit={sendToNumber} noValidate>
          <Field label="Send on WhatsApp to" hint="Type the customer's number (080…, 070…, 090… or +234…)." error={phoneError}>
            {(p) => (
              <div className="flex items-stretch gap-2 [&>button]:!h-auto [&>button]:self-stretch">
                <TextInput {...p} type="tel" inputMode="tel" placeholder="0803 123 4567" value={phone} onChange={(e) => setPhone(e.target.value)} />
                <Button type="submit" variant="primary">
                  Send
                </Button>
              </div>
            )}
          </Field>
        </form>

        <div className="flex flex-wrap gap-2">
          <Button onClick={() => openWhatsApp('')}>Choose a WhatsApp chat</Button>
          <a
            href={`mailto:?subject=${encodeURIComponent(product.name)}&body=${encodeURIComponent(message)}`}
            className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#d1d5db] bg-white px-3.5 text-[13px] font-semibold text-[#1f2937] outline-none transition-colors hover:bg-[#f9fafb] focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/50"
          >
            <Mail className="size-3.5" aria-hidden="true" />
            Email
          </a>
          {canNativeShare && (
            <Button onClick={nativeShare}>
              <Share2 className="size-3.5" aria-hidden="true" />
              More apps
            </Button>
          )}
        </div>
      </div>
    </Modal>
  );
}
