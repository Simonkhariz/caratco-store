'use client';

import React from 'react';
import Link from 'next/link';
import { useStoreInfo, whatsAppLink } from '@/lib/use-store-info';
import { useStoreSupport } from '@/lib/use-store-support';
import { storeAddressLine } from '@/lib/store-address';

/** Real contact details from admin → Store settings; anything not set is simply not shown. */
export function ContactDetails() {
  const { origin, loaded, error, reload } = useStoreInfo();
  const { supportPhone } = useStoreSupport();

  if (!loaded) {
    return (
      <p role="status" className="mt-6 text-sm text-neutral-600">
        Loading contact details…
      </p>
    );
  }

  if (error || !origin) {
    return (
      <div role="alert" className="mt-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <p>We could not load our contact details right now.</p>
        <button type="button" onClick={reload} className="mt-2 font-semibold underline">
          Try again
        </button>
      </div>
    );
  }

  const whatsappNumber = origin.whatsapp_phone || supportPhone || '';
  const waLink = whatsAppLink(whatsappNumber, 'Hello, I have a question about my order.');
  const address = storeAddressLine(origin.origin_address, origin.origin_city, origin.origin_state);

  const items: Array<{ label: string; value: React.ReactNode }> = [];
  if (origin.contact_email) {
    items.push({
      label: 'Email',
      value: (
        <a href={`mailto:${origin.contact_email}`} className="font-semibold text-black underline">
          {origin.contact_email}
        </a>
      ),
    });
  }
  if (waLink) {
    items.push({
      label: 'WhatsApp',
      value: (
        <a href={waLink} target="_blank" rel="noopener noreferrer" className="font-semibold text-emerald-800 underline">
          {whatsappNumber}
          <span className="sr-only"> (opens WhatsApp)</span>
        </a>
      ),
    });
  }
  if (origin.contact_phone) {
    items.push({
      label: 'Phone',
      value: (
        <a href={`tel:${origin.contact_phone.replace(/[^\d+]/g, '')}`} className="font-semibold text-black underline">
          {origin.contact_phone}
        </a>
      ),
    });
  }
  if (address) {
    items.push({
      label: 'Our location',
      value: (
        <span>
          {address}
          <br />
          <a
            href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`}
            target="_blank"
            rel="noopener noreferrer"
            className="font-semibold text-black underline"
          >
            Open in Google Maps
            <span className="sr-only"> (opens in a new tab)</span>
          </a>
        </span>
      ),
    });
  }

  if (items.length === 0) {
    return <p className="mt-6 text-sm text-neutral-700">Contact details have not been added yet.</p>;
  }

  return (
    <div className="mt-6 space-y-6">
      <dl className="grid gap-4 rounded-2xl border border-neutral-200 p-5 sm:grid-cols-2">
        {items.map((item) => (
          <div key={item.label}>
            <dt className="text-xs font-bold uppercase tracking-wider text-neutral-600">{item.label}</dt>
            <dd className="mt-1 break-words text-sm text-neutral-800">{item.value}</dd>
          </div>
        ))}
      </dl>
      <p className="text-sm text-neutral-700">
        Asking about an order? Include your order number. You can also check its status yourself on the{' '}
        <Link href="/track-order" className="font-semibold text-black underline">
          Track order
        </Link>{' '}
        page.
      </p>
    </div>
  );
}
