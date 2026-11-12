import { SHOP } from '@/lib/shop';
import type { Metadata } from 'next';
import { ContentPage } from '@/components/content/ContentPage';
import type { PaymentMethodsResponse } from '@/lib/api-client';
import { serverApiGet } from '@/lib/server-api';
import { paymentWindowsFrom, termsContent } from '@/lib/site-content';

export const metadata: Metadata = {
  title: `Terms & conditions | ${SHOP.name}`,
  description: `How ordering, payment, stock, promo codes and refunds work at ${SHOP.name}.`,
};

export default async function TermsPage() {
  // The payment windows in force (the same cached answer the layout loads); defaults if unreachable.
  const terms = termsContent(paymentWindowsFrom(await serverApiGet<PaymentMethodsResponse>('/checkout/payment-methods')));
  return <ContentPage title="Terms & conditions" intro={terms.intro} sections={terms.sections} />;
}
