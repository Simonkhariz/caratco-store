import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import Link from 'next/link';
import { ContentPage } from '@/components/content/ContentPage';
import type { PaymentMethodsResponse } from '@/lib/api-client';
import { serverApiGet } from '@/lib/server-api';
import { faqContent, paymentWindowsFrom } from '@/lib/site-content';

export const metadata: Metadata = {
  title: `FAQ | ${SHOP.name}`,
  description: 'Answers about payment, payment windows, delivery fees, order tracking, accounts and returns.',
};

export default async function FaqPage() {
  // The payment windows in force (the same cached answer the layout loads); defaults if unreachable.
  const FAQ = faqContent(paymentWindowsFrom(await serverApiGet<PaymentMethodsResponse>('/checkout/payment-methods')));
  return (
    <ContentPage title="Frequently asked questions">
      <div className="mt-6 divide-y divide-neutral-200 rounded-2xl border border-neutral-200">
        {FAQ.map((item) => (
          <details key={item.question} className="group px-5 py-4">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-black marker:hidden [&::-webkit-details-marker]:hidden">
              {item.question}
              <span aria-hidden="true" className="text-xl leading-none text-neutral-600 transition-transform group-open:rotate-45">
                +
              </span>
            </summary>
            <p className="mt-3 text-sm leading-relaxed text-neutral-700">{item.answer}</p>
          </details>
        ))}
      </div>
      <p className="mt-6 text-sm text-neutral-700">
        Still need help?{' '}
        <Link href="/contact" className="font-semibold text-black underline">
          Contact us
        </Link>
        .
      </p>
    </ContentPage>
  );
}
